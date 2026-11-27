param([Parameter(Mandatory=$true)][string]$DllPath)
$ErrorActionPreference = 'Stop'
$DllPath = [IO.Path]::GetFullPath($DllPath)
if (-not (Test-Path -LiteralPath $DllPath)) { throw 'DLL panel Word tidak ditemukan.' }
$assembly = [Reflection.AssemblyName]::GetAssemblyName($DllPath).FullName
$classes = @(
 @('SIMANIS.Word','34FF62FC-8B12-4516-A0D5-9A27C5586925','Simanis.Word.Connect'),
 @('SIMANIS.Word.Pane','E55076D4-A710-4E71-B0C5-E0CAAB2CAC61','Simanis.Word.PaneControl')
)
foreach ($view in @([Microsoft.Win32.RegistryView]::Registry32,[Microsoft.Win32.RegistryView]::Registry64)) {
 $rootKey = [Microsoft.Win32.RegistryKey]::OpenBaseKey([Microsoft.Win32.RegistryHive]::CurrentUser,$view)
 try {
  foreach ($entry in $classes) {
   $progid=$entry[0]; $guid='{'+$entry[1]+'}'; $className=$entry[2]
   $loader=Join-Path $env:WINDIR 'System32\mscoree.dll'
   if ($view -eq [Microsoft.Win32.RegistryView]::Registry32 -and [Environment]::Is64BitOperatingSystem) { $loader=Join-Path $env:WINDIR 'SysWOW64\mscoree.dll' }
   $cls='Software\Classes\CLSID\'+$guid
   $key=$rootKey.CreateSubKey('Software\Classes\'+$progid+'\CLSID');try { $key.SetValue('',$guid) } finally { $key.Dispose() }
   $key=$rootKey.CreateSubKey($cls+'\ProgId');try { $key.SetValue('',$progid) } finally { $key.Dispose() }
   foreach ($suffix in @('\InprocServer32','\InprocServer32\1.0.0.0')) {
    $key=$rootKey.CreateSubKey($cls+$suffix)
    try {
     if ($suffix -eq '\InprocServer32') { $key.SetValue('',$loader);$key.SetValue('ThreadingModel','Both') }
     $key.SetValue('Class',$className);$key.SetValue('Assembly',$assembly);$key.SetValue('RuntimeVersion','v4.0.30319');$key.SetValue('CodeBase','file:///'+$DllPath.Replace('\','/'))
    } finally { $key.Dispose() }
   }
   $key=$rootKey.CreateSubKey($cls+'\Implemented Categories\{62C8FE65-4EBB-45E7-B440-6E39B2CDBF29}');$key.Dispose()
   if ($className.EndsWith('PaneControl')) { $key=$rootKey.CreateSubKey($cls+'\Control');$key.Dispose() }
  }
  $key=$rootKey.CreateSubKey('Software\Microsoft\Office\Word\Addins\SIMANIS.Word')
  try { $key.SetValue('FriendlyName','SIMANIS Word');$key.SetValue('Description','Panel pekerjaan SIMANIS');$key.SetValue('LoadBehavior',3,[Microsoft.Win32.RegistryValueKind]::DWord);$key.SetValue('CommandLineSafe',0,[Microsoft.Win32.RegistryValueKind]::DWord) } finally { $key.Dispose() }
 } finally { $rootKey.Dispose() }
}
