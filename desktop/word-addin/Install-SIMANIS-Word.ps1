# SIMANIS Word installer (current Windows account only).
# Does not change Office macro/security settings or certificate stores.
$ErrorActionPreference = 'Stop'
if (Get-Process WINWORD -ErrorAction SilentlyContinue) {
    throw 'Simpan dokumen dan tutup semua jendela Word sebelum memasang SIMANIS.'
}
$source = $PSScriptRoot
$appDir = Join-Path $env:LOCALAPPDATA 'Programs\SIMANIS'
$startup = Join-Path $env:APPDATA 'Microsoft\Word\STARTUP'
foreach ($version in @('16.0', '15.0', '14.0')) {
    $options = Get-ItemProperty -LiteralPath "HKCU:\Software\Microsoft\Office\$version\Word\Options" -ErrorAction SilentlyContinue
    if ($options -and $options.'STARTUP-PATH') {
        $startup = [Environment]::ExpandEnvironmentVariables($options.'STARTUP-PATH'.Trim('"'))
        break
    }
}
$startup = [IO.Path]::GetFullPath($startup)
if ($startup.StartsWith('\\')) { throw 'Gunakan Startup Word pada disk lokal.' }
foreach ($name in @('SIMANIS-Word.dotm', 'SIMANIS-WordData.exe', 'Simanis.Word.dll')) {
    if (-not (Test-Path -LiteralPath (Join-Path $source $name) -PathType Leaf)) { throw "File paket tidak lengkap: $name" }
}
New-Item -ItemType Directory -Path $appDir -Force | Out-Null
New-Item -ItemType Directory -Path $startup -Force | Out-Null
$backup = Join-Path $appDir ('Backup\' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Path $backup -Force | Out-Null
foreach ($file in Get-ChildItem -LiteralPath $startup -File | Where-Object { $_.Name -like 'SIGANDI-Word*.dotm' -or $_.Name -eq 'SIMANIS-Word.dotm' }) {
    $saved = Join-Path $backup $file.Name
    Copy-Item -LiteralPath $file.FullName -Destination $saved
    if ((Get-FileHash -LiteralPath $file.FullName).Hash -ne (Get-FileHash -LiteralPath $saved).Hash) { throw 'Verifikasi backup add-in gagal.' }
    Remove-Item -LiteralPath $file.FullName
}
foreach ($name in @('SIMANIS-Word.dotm', 'SIMANIS-WordData.exe', 'Simanis.Word.dll')) {
    Copy-Item -LiteralPath (Join-Path $source $name) -Destination (Join-Path $appDir $name) -Force
    if ((Get-FileHash -LiteralPath (Join-Path $source $name)).Hash -ne (Get-FileHash -LiteralPath (Join-Path $appDir $name)).Hash) { throw "Verifikasi instalasi gagal: $name" }
}
Copy-Item -LiteralPath (Join-Path $appDir 'SIMANIS-Word.dotm') -Destination (Join-Path $startup 'SIMANIS-Word.dotm') -Force
New-Item -Path 'HKCU:\Software\SIMANIS' -Force | Out-Null
New-ItemProperty -Path 'HKCU:\Software\SIMANIS' -Name 'WordDataExe' -Value (Join-Path $appDir 'SIMANIS-WordData.exe') -PropertyType String -Force | Out-Null
& (Join-Path $source 'Register-SIMANIS-WordPane.ps1') -DllPath (Join-Path $appDir 'Simanis.Word.dll')
Write-Host 'SIMANIS Word terpasang. Buka Word, lalu pilih tab SIMANIS > Pekerjaan / Data Draf.'
Write-Host 'Pengaturan keamanan macro Office tidak diubah. Ikuti kebijakan macro kantor yang berlaku.'
Write-Host "Cadangan add-in sebelumnya: $backup"
