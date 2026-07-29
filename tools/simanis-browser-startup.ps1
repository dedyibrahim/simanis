param(
    [string]$Url = "http://192.168.0.12",
    [int]$DelaySeconds = 8,
    [ValidateSet("Fullscreen", "Kiosk", "Normal")]
    [string]$Mode = "Fullscreen",
    [switch]$InstallStartup
)

$ErrorActionPreference = "Stop"

function Install-StartupLauncher {
    $startupFolder = [Environment]::GetFolderPath("Startup")
    $shortcutPath = Join-Path $startupFolder "SIMANIS Dashboard.lnk"
    $scriptPath = $PSCommandPath
    if ([string]::IsNullOrWhiteSpace($scriptPath)) {
        throw "Tidak bisa menentukan path script browser startup."
    }

    $shell = New-Object -ComObject WScript.Shell
    $shortcut = $shell.CreateShortcut($shortcutPath)
    $shortcut.TargetPath = "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe"
    $shortcut.Arguments = "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$scriptPath`" -Url `"$Url`" -DelaySeconds $DelaySeconds -Mode $Mode"
    $shortcut.WorkingDirectory = Split-Path -Parent $scriptPath
    $shortcut.Description = "Buka halaman SIMANIS fullscreen saat Windows login"
    $shortcut.Save()

    Write-Host "Startup browser launcher berhasil dibuat:"
    Write-Host $shortcutPath
    Write-Host "URL: $Url"
    Write-Host "Mode: $Mode"
}

function Get-BrowserExecutable {
    $candidates = @(
        "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe",
        "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
        "$env:LOCALAPPDATA\Microsoft\Edge\Application\msedge.exe",
        "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
        "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
        "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe"
    )

    foreach ($candidate in $candidates) {
        if (-not [string]::IsNullOrWhiteSpace($candidate) -and (Test-Path -LiteralPath $candidate)) {
            return $candidate
        }
    }

    return $null
}

function Open-SimanisBrowser {
    $browser = Get-BrowserExecutable
    $modeName = $Mode.ToLowerInvariant()

    if ($browser) {
        $arguments = New-Object System.Collections.Generic.List[string]
        if ($modeName -eq "kiosk") {
            $arguments.Add("--kiosk")
            $arguments.Add($Url)
            if ([System.IO.Path]::GetFileName($browser).ToLowerInvariant() -eq "msedge.exe") {
                $arguments.Add("--edge-kiosk-type=fullscreen")
            }
        }
        else {
            $arguments.Add("--new-window")
            if ($modeName -eq "fullscreen") {
                $arguments.Add("--start-fullscreen")
            }
            $arguments.Add($Url)
        }

        Start-Process -FilePath $browser -ArgumentList $arguments.ToArray() -WindowStyle Maximized
        return
    }

    Start-Process $Url

    if ($modeName -eq "fullscreen") {
        Start-Sleep -Seconds 2
        try {
            $shell = New-Object -ComObject WScript.Shell
            $shell.SendKeys("{F11}")
        }
        catch {
            Write-Host "Browser dibuka, tetapi gagal mengirim tombol F11: $($_.Exception.Message)"
        }
    }
}

if ($InstallStartup) {
    Install-StartupLauncher
    return
}

if ($DelaySeconds -gt 0) {
    Start-Sleep -Seconds $DelaySeconds
}

Open-SimanisBrowser
