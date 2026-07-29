param(
    [string]$ApiBase = "http://192.168.0.12:8000/api",
    [string]$WatchFolder = "C:\SIMANIS_SCAN_INBOX",
    [string]$ArchiveFolder = "C:\SIMANIS_SCAN_ARCHIVE",
    [string]$FailedFolder = "C:\SIMANIS_SCAN_FAILED",
    [int]$Port = 8787,
    [int]$StableSeconds = 2,
    [string]$AgentName = $env:COMPUTERNAME,
    [switch]$InstallStartup
)

$ErrorActionPreference = "Stop"
$ScriptFullPath = if ($PSCommandPath) { [System.IO.Path]::GetFullPath($PSCommandPath) } else { "" }

if (-not (Test-Path -LiteralPath "D:\")) {
    if ($WatchFolder -like "D:\*") {
        $WatchFolder = $WatchFolder -replace '^D:', 'C:'
    }
    if ($ArchiveFolder -like "D:\*") {
        $ArchiveFolder = $ArchiveFolder -replace '^D:', 'C:'
    }
    if ($FailedFolder -like "D:\*") {
        $FailedFolder = $FailedFolder -replace '^D:', 'C:'
    }
}

function Ensure-Folder {
    param([string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) {
        New-Item -ItemType Directory -Path $Path | Out-Null
    }
}

function Install-StartupLauncher {
    $startupFolder = [Environment]::GetFolderPath("Startup")
    $shortcutPath = Join-Path $startupFolder "SIMANIS Scanner Agent.lnk"
    $scriptPath = $PSCommandPath
    if ([string]::IsNullOrWhiteSpace($scriptPath)) {
        throw "Tidak bisa menentukan path script agent."
    }

    $shell = New-Object -ComObject WScript.Shell
    $shortcut = $shell.CreateShortcut($shortcutPath)
    $shortcut.TargetPath = "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe"
    $shortcut.Arguments = "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$scriptPath`" -ApiBase `"$ApiBase`" -WatchFolder `"$WatchFolder`" -ArchiveFolder `"$ArchiveFolder`" -FailedFolder `"$FailedFolder`" -Port $Port"
    $shortcut.WorkingDirectory = Split-Path -Parent $scriptPath
    $shortcut.Description = "SIMANIS Scanner Agent - jalankan di session user aktif"
    $shortcut.Save()

    Write-Host "Startup launcher berhasil dibuat:"
    Write-Host $shortcutPath
    Write-Host "Penting: matikan/disable Scheduled Task lama 'SIMANIS Scanner Agent' agar tidak berjalan background."
}

function Stop-OtherScannerAgents {
    if ([string]::IsNullOrWhiteSpace($ScriptFullPath)) {
        return
    }

    $escapedScriptPath = [regex]::Escape($ScriptFullPath)
    $currentPid = $PID
    $otherAgents = Get-CimInstance Win32_Process -Filter "Name = 'powershell.exe' OR Name = 'pwsh.exe'" -ErrorAction SilentlyContinue |
        Where-Object {
            $_.ProcessId -ne $currentPid -and
            $_.CommandLine -match $escapedScriptPath
        }

    foreach ($agent in $otherAgents) {
        try {
            Stop-Process -Id $agent.ProcessId -Force -ErrorAction Stop
            Write-Host "Agent lama dimatikan: PID $($agent.ProcessId)"
        }
        catch {
            Write-Host "Gagal mematikan agent lama PID $($agent.ProcessId): $($_.Exception.Message)"
        }
    }
}

function Test-PortInUse {
    param([int]$PortNumber)

    try {
        $client = New-Object System.Net.Sockets.TcpClient
        $async = $client.BeginConnect("127.0.0.1", $PortNumber, $null, $null)
        $connected = $async.AsyncWaitHandle.WaitOne(400, $false)
        if ($connected) {
            $client.EndConnect($async)
        }
        $client.Close()
        return $connected
    }
    catch {
        return $false
    }
}

function Get-AgentRuntimeInfo {
    $process = Get-Process -Id $PID
    $activeSessionId = $null
    try {
        $activeLine = (query user 2>$null | Where-Object { $_ -match "\sActive\s" } | Select-Object -First 1)
        if ($activeLine -match "\s+(\d+)\s+Active\s") {
            $activeSessionId = [int]$matches[1]
        }
    }
    catch {
        $activeSessionId = $null
    }

    return [PSCustomObject]@{
        process_id = $PID
        session_id = $process.SessionId
        active_session_id = $activeSessionId
        user_interactive = [Environment]::UserInteractive
        likely_visible_desktop = ([Environment]::UserInteractive -and ($null -eq $activeSessionId -or $process.SessionId -eq $activeSessionId))
    }
}

function Write-JsonResponse {
    param(
        [System.Net.HttpListenerContext]$Context,
        [int]$StatusCode,
        [object]$Payload
    )

    $json = ConvertTo-Json -InputObject $Payload -Depth 10
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)
    $Context.Response.StatusCode = $StatusCode
    $Context.Response.ContentType = "application/json; charset=utf-8"
    $Context.Response.ContentLength64 = $bytes.Length
    $Context.Response.Headers.Add("Access-Control-Allow-Origin", "*")
    $Context.Response.Headers.Add("Access-Control-Allow-Methods", "GET,POST,OPTIONS")
    $Context.Response.Headers.Add("Access-Control-Allow-Headers", "Content-Type")
    $Context.Response.OutputStream.Write($bytes, 0, $bytes.Length)
    $Context.Response.OutputStream.Close()
}

function Write-FileResponse {
    param(
        [System.Net.HttpListenerContext]$Context,
        [System.IO.FileInfo]$File
    )

    $extension = $File.Extension.TrimStart(".").ToLowerInvariant()
    $contentType = switch ($extension) {
        "pdf" { "application/pdf" }
        "jpg" { "image/jpeg" }
        "jpeg" { "image/jpeg" }
        default { "application/octet-stream" }
    }

    $Context.Response.StatusCode = 200
    $Context.Response.ContentType = $contentType
    $Context.Response.ContentLength64 = $File.Length
    $Context.Response.Headers.Add("Access-Control-Allow-Origin", "*")
    $Context.Response.Headers.Add("Access-Control-Allow-Methods", "GET,POST,OPTIONS")
    $Context.Response.Headers.Add("Access-Control-Allow-Headers", "Content-Type")
    $Context.Response.Headers.Add("Content-Disposition", "inline; filename=""$($File.Name)""")

    $stream = [System.IO.File]::OpenRead($File.FullName)
    try {
        $stream.CopyTo($Context.Response.OutputStream)
    }
    finally {
        $stream.Close()
        $Context.Response.OutputStream.Close()
    }
}

function Read-JsonBody {
    param([System.Net.HttpListenerRequest]$Request)
    $reader = New-Object System.IO.StreamReader($Request.InputStream, $Request.ContentEncoding)
    $raw = $reader.ReadToEnd()
    $reader.Close()
    if ([string]::IsNullOrWhiteSpace($raw)) {
        return @{}
    }
    return $raw | ConvertFrom-Json
}

function Get-SafeUploadName {
    param(
        [string]$Name,
        [string]$SourceExtension
    )

    $safeName = [System.IO.Path]::GetFileName($Name)
    if ([string]::IsNullOrWhiteSpace($safeName)) {
        $safeName = "dokumen-scan$SourceExtension"
    }

    $extension = [System.IO.Path]::GetExtension($safeName)
    if ([string]::IsNullOrWhiteSpace($extension)) {
        $safeName = "$safeName$SourceExtension"
    }
    elseif ($extension.ToLowerInvariant() -ne $SourceExtension.ToLowerInvariant()) {
        $safeName = "{0}{1}" -f [System.IO.Path]::GetFileNameWithoutExtension($safeName), $SourceExtension
    }

    $invalid = [System.IO.Path]::GetInvalidFileNameChars()
    foreach ($char in $invalid) {
        $safeName = $safeName.Replace([string]$char, "-")
    }

    return $safeName.Trim()
}

function Test-StableFile {
    param([System.IO.FileInfo]$File)
    try {
        $firstLength = $File.Length
        Start-Sleep -Seconds $StableSeconds
        $fresh = Get-Item -LiteralPath $File.FullName -ErrorAction Stop
        return ($fresh.Length -eq $firstLength -and $fresh.Length -gt 0)
    }
    catch {
        return $false
    }
}

function Get-ScanFiles {
    $files = Get-ChildItem -LiteralPath $WatchFolder -File -ErrorAction SilentlyContinue |
        Where-Object { $_.Extension -match '^\.(pdf|jpg|jpeg)$' } |
        Sort-Object LastWriteTime -Descending

    return @($files | ForEach-Object {
        [PSCustomObject]@{
            name = $_.Name
            size_bytes = $_.Length
            extension = $_.Extension.TrimStart(".").ToLowerInvariant()
            last_write_time = $_.LastWriteTime.ToString("s")
            ready = (Test-StableFile -File $_)
        }
    })
}

function Resolve-ScanFile {
    param([string]$FileName)

    $safeName = [System.IO.Path]::GetFileName($FileName)
    if ([string]::IsNullOrWhiteSpace($safeName)) {
        throw "Nama file kosong."
    }

    $path = Join-Path $WatchFolder $safeName
    if (-not (Test-Path -LiteralPath $path)) {
        throw "File tidak ditemukan di folder scan: $safeName"
    }

    $file = Get-Item -LiteralPath $path
    if ($file.Extension -notmatch '^\.(pdf|jpg|jpeg)$') {
        throw "Tipe file tidak didukung: $($file.Extension)"
    }

    if (-not (Test-StableFile -File $file)) {
        throw "File masih ditulis scanner. Tunggu beberapa detik lalu refresh."
    }

    return $file
}

function Resolve-ScannerExecutable {
    param([string]$Path)

    $scannerPath = [string]$Path
    if ([string]::IsNullOrWhiteSpace($scannerPath)) {
        throw "Path aplikasi scanner kosong. Isi path Epson Scan dari halaman SIMANIS."
    }

    $scannerPath = $scannerPath.Trim().Trim('"')
    if (-not [System.IO.Path]::IsPathRooted($scannerPath)) {
        throw "Path aplikasi scanner harus berupa path lengkap, contoh C:\Program Files\Epson...\app.exe"
    }

    if ([System.IO.Path]::GetExtension($scannerPath).ToLowerInvariant() -ne ".exe") {
        throw "Path aplikasi scanner harus mengarah ke file .exe"
    }

    if (-not (Test-Path -LiteralPath $scannerPath -PathType Leaf)) {
        throw "File aplikasi scanner tidak ditemukan: $scannerPath"
    }

    return (Get-Item -LiteralPath $scannerPath -ErrorAction Stop)
}

function Open-ScannerExecutable {
    param([System.IO.FileInfo]$ScannerExe)

    $runtime = Get-AgentRuntimeInfo
    if (-not $runtime.likely_visible_desktop) {
        throw "Agent scanner berjalan di session background (session $($runtime.session_id), desktop aktif $($runtime.active_session_id)). Jalankan agent lewat Startup user/interaktif agar aplikasi Epson tampil di layar."
    }

    $processName = [System.IO.Path]::GetFileNameWithoutExtension($ScannerExe.Name)
    $process = Start-Process -FilePath $ScannerExe.FullName -WorkingDirectory $ScannerExe.DirectoryName -WindowStyle Normal -PassThru
    $visibleProcess = $null

    for ($attempt = 0; $attempt -lt 12; $attempt++) {
        Start-Sleep -Milliseconds 500
        $visibleProcess = Get-Process -Name $processName -ErrorAction SilentlyContinue |
            Where-Object { $_.MainWindowHandle -ne 0 -or -not [string]::IsNullOrWhiteSpace($_.MainWindowTitle) } |
            Select-Object -First 1

        if ($visibleProcess) {
            break
        }
    }

    if (-not $visibleProcess) {
        throw "Aplikasi scanner sudah dijalankan, tetapi window tidak tampil. Tutup proses Epson yang nyangkut atau jalankan agent di session user aktif."
    }

    return $visibleProcess
}

function Get-ScannerProcesses {
    param([System.IO.FileInfo]$ScannerExe)

    $processName = [System.IO.Path]::GetFileNameWithoutExtension($ScannerExe.Name)
    $expectedPath = $ScannerExe.FullName.ToLowerInvariant()

    return @(Get-Process -Name $processName -ErrorAction SilentlyContinue | Where-Object {
        $path = ""
        try {
            $path = [string]$_.Path
        }
        catch {
            $path = ""
        }

        [string]::IsNullOrWhiteSpace($path) -or $path.ToLowerInvariant() -eq $expectedPath
    })
}

function Get-ScannerStatusPayload {
    param([System.IO.FileInfo]$ScannerExe)

    $processes = Get-ScannerProcesses -ScannerExe $ScannerExe
    $visible = @($processes | Where-Object { $_.MainWindowHandle -ne 0 -or -not [string]::IsNullOrWhiteSpace($_.MainWindowTitle) })

    return [PSCustomObject]@{
        path = $ScannerExe.FullName
        running = ($processes.Count -gt 0)
        visible = ($visible.Count -gt 0)
        processes = @($processes | ForEach-Object {
            [PSCustomObject]@{
                process_id = $_.Id
                window_title = $_.MainWindowTitle
                visible = ($_.MainWindowHandle -ne 0 -or -not [string]::IsNullOrWhiteSpace($_.MainWindowTitle))
            }
        })
    }
}

function Close-ScannerExecutable {
    param([System.IO.FileInfo]$ScannerExe)

    $processes = Get-ScannerProcesses -ScannerExe $ScannerExe
    foreach ($process in $processes) {
        try {
            if ($process.MainWindowHandle -ne 0) {
                [void]$process.CloseMainWindow()
            }
        }
        catch {
        }
    }

    Start-Sleep -Seconds 2
    $remaining = Get-ScannerProcesses -ScannerExe $ScannerExe
    foreach ($process in $remaining) {
        try {
            Stop-Process -Id $process.Id -Force -ErrorAction Stop
        }
        catch {
        }
    }

    Start-Sleep -Milliseconds 600
    return Get-ScannerStatusPayload -ScannerExe $ScannerExe
}

function Upload-ScanFile {
    param(
        [System.IO.FileInfo]$File,
        [string]$Token,
        [string]$UploadName
    )

    $curl = Get-Command curl.exe -ErrorAction Stop
    $safeUploadName = Get-SafeUploadName -Name $UploadName -SourceExtension $File.Extension
    $arguments = @(
        "-sS",
        "-X", "POST",
        "-F", "token=$Token",
        "-F", "uploaded_by_agent=$AgentName",
        "-F", "original_name=$safeUploadName",
        "-F", "file=@$($File.FullName);filename=$safeUploadName",
        "$ApiBase/public/scan/upload"
    )

    $output = & $curl.Source @arguments
    if ($LASTEXITCODE -ne 0) {
        throw "curl.exe gagal upload dengan exit code $LASTEXITCODE"
    }

    try {
        $json = $output | ConvertFrom-Json
    }
    catch {
        throw "Server mengembalikan response tidak valid: $output"
    }

    if (-not $json.status) {
        throw (($json.message | Out-String).Trim())
    }

    return $json
}

function Move-WithUniqueName {
    param(
        [string]$Source,
        [string]$DestinationFolder
    )

    $name = [System.IO.Path]::GetFileName($Source)
    $target = Join-Path $DestinationFolder $name
    if (Test-Path -LiteralPath $target) {
        $target = Join-Path $DestinationFolder ("{0}-{1}" -f (Get-Date -Format "yyyyMMddHHmmss"), $name)
    }
    Move-Item -LiteralPath $Source -Destination $target
    return $target
}

if ($InstallStartup) {
    Install-StartupLauncher
    return
}

Stop-OtherScannerAgents
Start-Sleep -Milliseconds 500

Ensure-Folder $WatchFolder
Ensure-Folder $ArchiveFolder
Ensure-Folder $FailedFolder

$listener = New-Object System.Net.HttpListener
$prefix = "http://127.0.0.1:$Port/"
$listener.Prefixes.Add($prefix)

try {
    $listener.Start()
}
catch {
    Write-Host "Gagal menjalankan local scanner agent di $prefix"
    if (Test-PortInUse -PortNumber $Port) {
        Write-Host "Port $Port masih dipakai proses lain. Tutup agent lama atau restart Windows."
    }
    Write-Host "Coba jalankan PowerShell sebagai Administrator, atau jalankan:"
    Write-Host "netsh http add urlacl url=$prefix user=Everyone"
    throw
}

Write-Host "SIMANIS Scanner Agent berjalan."
Write-Host "Mode        : manual upload dari dashboard"
Write-Host "Local API   : $prefix"
Write-Host "SIMANIS API : $ApiBase"
Write-Host "Watch folder: $WatchFolder"
Write-Host "Archive     : $ArchiveFolder"
Write-Host "Failed      : $FailedFolder"
Write-Host "Arahkan Epson Document Capture Pro menyimpan PDF/JPG ke watch folder ini."
Write-Host "Setelah scan, kembali ke halaman SIMANIS dan klik Upload pada file yang dipilih."

while ($listener.IsListening) {
    try {
        $context = $listener.GetContext()
        $request = $context.Request
        $path = $request.Url.AbsolutePath.TrimEnd("/")

        if ($request.HttpMethod -eq "OPTIONS") {
            Write-JsonResponse -Context $context -StatusCode 204 -Payload @{}
            continue
        }

        if ($request.HttpMethod -eq "GET" -and ($path -eq "" -or $path -eq "/health")) {
            $runtime = Get-AgentRuntimeInfo
            Write-JsonResponse -Context $context -StatusCode 200 -Payload @{
                status = $true
                message = "SIMANIS scanner agent aktif."
                data = @{
                    api_base = $ApiBase
                    watch_folder = $WatchFolder
                    archive_folder = $ArchiveFolder
                    failed_folder = $FailedFolder
                    agent_name = $AgentName
                    runtime = $runtime
                }
            }
            continue
        }

        if ($request.HttpMethod -eq "GET" -and $path -eq "/files") {
            Write-JsonResponse -Context $context -StatusCode 200 -Payload @{
                status = $true
                message = "Daftar file scan berhasil dimuat."
                data = Get-ScanFiles
            }
            continue
        }

        if ($request.HttpMethod -eq "GET" -and $path -eq "/preview") {
            $fileName = [string]$request.QueryString["file_name"]
            $file = Resolve-ScanFile -FileName $fileName
            Write-FileResponse -Context $context -File $file
            continue
        }

        if ($request.HttpMethod -eq "GET" -and $path -eq "/scanner-status") {
            $scannerExe = Resolve-ScannerExecutable -Path ([string]$request.QueryString["path"])

            Write-JsonResponse -Context $context -StatusCode 200 -Payload @{
                status = $true
                message = "Status aplikasi scanner berhasil dimuat."
                data = Get-ScannerStatusPayload -ScannerExe $scannerExe
            }
            continue
        }

        if ($request.HttpMethod -eq "POST" -and $path -eq "/open-scanner") {
            $body = Read-JsonBody -Request $request
            $scannerExe = Resolve-ScannerExecutable -Path ([string]$body.path)
            $openedProcess = Open-ScannerExecutable -ScannerExe $scannerExe

            Write-JsonResponse -Context $context -StatusCode 200 -Payload @{
                status = $true
                message = "Aplikasi scanner berhasil dibuka."
                data = @{
                    path = $scannerExe.FullName
                    process_id = $openedProcess.Id
                    window_title = $openedProcess.MainWindowTitle
                }
            }
            continue
        }

        if ($request.HttpMethod -eq "POST" -and $path -eq "/close-scanner") {
            $body = Read-JsonBody -Request $request
            $scannerExe = Resolve-ScannerExecutable -Path ([string]$body.path)
            $scannerStatus = Close-ScannerExecutable -ScannerExe $scannerExe

            Write-JsonResponse -Context $context -StatusCode 200 -Payload @{
                status = $true
                message = "Aplikasi scanner berhasil ditutup."
                data = $scannerStatus
            }
            continue
        }

        if ($request.HttpMethod -eq "POST" -and $path -eq "/upload") {
            $body = Read-JsonBody -Request $request
            $token = [string]$body.token
            $fileName = [string]$body.file_name
            $targetName = [string]$body.target_name

            if ([string]::IsNullOrWhiteSpace($token)) {
                throw "Token sesi scan kosong. Buat sesi scan dari web terlebih dahulu."
            }

            $file = Resolve-ScanFile -FileName $fileName
            $result = Upload-ScanFile -File $file -Token $token -UploadName $targetName
            Move-WithUniqueName -Source $file.FullName -DestinationFolder $ArchiveFolder | Out-Null

            Write-JsonResponse -Context $context -StatusCode 200 -Payload @{
                status = $true
                message = "File berhasil di-upload ke SIMANIS."
                data = $result.data
            }
            continue
        }

        Write-JsonResponse -Context $context -StatusCode 404 -Payload @{
            status = $false
            message = "Endpoint agent tidak ditemukan."
            data = $null
        }
    }
    catch {
        try {
            Write-JsonResponse -Context $context -StatusCode 500 -Payload @{
                status = $false
                message = $_.Exception.Message
                data = $null
            }
        }
        catch {
            Write-Host "Agent error: $($_.Exception.Message)"
        }
    }
}
