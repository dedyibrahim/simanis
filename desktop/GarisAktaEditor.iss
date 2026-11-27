; Inno Setup script
; Build with: ISCC GarisAktaEditor.iss

#define AppName "SIMANIS"
#define AppVersion "1.6.0"
#ifndef AppSourceDir
  #define AppSourceDir "dist\SIMANIS-v1.6.0"
#endif
#define AppPublisher "SIMANIS"
#define AppExeName "SIMANIS.exe"
#define AppIcon "assets\app_icon.ico"
#ifdef IntegrationTest
  #define AppId "{{F2706A57-50E1-42D3-A009-B140891704A1}"
#else
  #define AppId "{{DCC9D65B-95A5-4C26-8B31-7A9D6B9A334D}"
#endif
#define WordAddinSource "output\word-addin\installer\SIMANIS-Word.dotm"
#define WordAddinSHA GetSHA256OfFile(WordAddinSource)

[Setup]
AppId={#AppId}
AppName={#AppName}
AppVersion={#AppVersion}
AppPublisher={#AppPublisher}
AppPublisherURL=http://192.168.0.12
AppSupportURL=http://192.168.0.12
AppUpdatesURL=http://192.168.0.12
DefaultDirName={localappdata}\Programs\SIMANIS
DefaultGroupName=SIMANIS
DisableProgramGroupPage=yes
AllowNoIcons=yes
PrivilegesRequired=lowest
#ifdef IntegrationTest
OutputBaseFilename=SIMANIS_Installer_Integration_Test
OutputDir=tmp\installer-word-test-1.6.0
Uninstallable=no
CreateUninstallRegKey=no
UsePreviousAppDir=no
UsePreviousTasks=no
#else
OutputDir=dist
OutputBaseFilename=SIMANIS_Setup_v{#AppVersion}
#endif
Compression=lzma
SolidCompression=yes
WizardStyle=modern
WizardImageFile=assets\installer_wizard.bmp
WizardSmallImageFile=assets\installer_wizard_small.bmp
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
SetupIconFile={#AppIcon}
UninstallDisplayIcon={app}\{#AppExeName}
UninstallDisplayName={#AppName}
VersionInfoVersion={#AppVersion}
VersionInfoCompany={#AppPublisher}
VersionInfoDescription={#AppName} Installer
VersionInfoProductName={#AppName}
VersionInfoProductVersion={#AppVersion}
CloseApplications=no
RestartApplications=no

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Create a desktop shortcut"; GroupDescription: "Additional icons:"; Flags: checkedonce

[Files]
Source: "{#AppSourceDir}\{#AppExeName}"; DestDir: "{app}"; Flags: ignoreversion
Source: "{#WordAddinSource}"; DestDir: "{app}\WordAddin"; DestName: "SIMANIS-Word.dotm"; Flags: ignoreversion
Source: "{#WordAddinSource}"; DestDir: "{code:GetWordStartup}"; DestName: "SIMANIS-Word.dotm"; Flags: ignoreversion
Source: "word-addin\PANDUAN.txt"; DestDir: "{app}\WordAddin"; Flags: ignoreversion
Source: "word-addin\PANDUAN.txt"; DestDir: "{app}"; DestName: "Panduan SIMANIS.txt"; Flags: ignoreversion

Source: "output\word-addin\installer\SIMANIS-WordData.exe"; DestDir: "{app}"; Flags: ignoreversion

Source: "output\word-addin\installer\Simanis.Word.dll"; DestDir: "{app}\WordAddin"; Flags: ignoreversion

#include "word-pane\registry.iss"

[Registry]
#ifndef IntegrationTest
Root: HKCU; Subkey: "Software\SIMANIS"; ValueType: string; ValueName: "WordDataExe"; ValueData: "{app}\SIMANIS-WordData.exe"; Flags: uninsdeletevalue
#endif

[Icons]
#ifndef IntegrationTest
Name: "{autoprograms}\SIMANIS\{#AppName}"; Filename: "{app}\{#AppExeName}"; IconFilename: "{app}\{#AppExeName}"
Name: "{autoprograms}\SIMANIS\Panduan SIMANIS"; Filename: "{app}\Panduan SIMANIS.txt"
Name: "{autodesktop}\{#AppName}"; Filename: "{app}\{#AppExeName}"; IconFilename: "{app}\{#AppExeName}"; Tasks: desktopicon
#endif

[Run]
#ifndef IntegrationTest
Filename: "{app}\{#AppExeName}"; Description: "Launch SIMANIS"; Flags: nowait postinstall skipifsilent
#endif

#include "word-addin\installer_word.iss"
