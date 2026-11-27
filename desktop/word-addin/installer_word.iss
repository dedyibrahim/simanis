; Included by the SIMANIS desktop installer. Never changes Office security.
[Code]
var
  WordStartup: String;

function ExpandEnvironmentStrings(Source, Dest: String; Size: LongWord): LongWord;
  external 'ExpandEnvironmentStringsW@kernel32.dll stdcall';

function ExpandEnvironment(const Value: String): String;
var
  Size: LongWord;
begin
  SetLength(Result, 32768);
  Size := ExpandEnvironmentStrings(Value, Result, Length(Result));
  if (Size > 0) and (Size <= LongWord(Length(Result))) then
    SetLength(Result, Size - 1)
  else
    Result := Value;
end;

function GetWordStartup(Param: String): String;
var
  Custom: String;
begin
  if WordStartup = '' then begin
    WordStartup := ExpandConstant('{userappdata}\Microsoft\Word\STARTUP');
#ifdef IntegrationTest
    WordStartup := ExpandConstant('{param:WORDSTARTUPDIR|{tmp}\SIMANIS-Word-Test-Startup}');
#else
    if RegQueryStringValue(HKCU, 'Software\Microsoft\Office\16.0\Word\Options', 'STARTUP-PATH', Custom) or
       RegQueryStringValue(HKCU, 'Software\Microsoft\Office\15.0\Word\Options', 'STARTUP-PATH', Custom) or
       RegQueryStringValue(HKCU, 'Software\Microsoft\Office\14.0\Word\Options', 'STARTUP-PATH', Custom) then begin
      Custom := ExpandEnvironment(RemoveQuotes(Trim(Custom)));
      if (Length(Custom) > 2) and (Custom[2] = ':') then
        WordStartup := Custom;
    end;
#endif
    WordStartup := RemoveBackslashUnlessRoot(WordStartup);
    Log('SIMANIS Word Startup: ' + WordStartup);
  end;
  Result := WordStartup;
end;

function PrepareToInstall(var NeedsRestart: Boolean): String;
begin
  Result := '';
#ifndef IntegrationTest
  if FindWindowByClassName('OpusApp') <> 0 then
    Result := 'Microsoft Word masih terbuka.' + #13#10 +
      'Simpan dokumen Anda, tutup semua jendela Word, lalu klik Coba Lagi (Retry).' + #13#10 +
      'SIMANIS tidak akan menutup Word atau membuang perubahan dokumen Anda.';
#endif
end;

procedure BackupLegacyAddins;
var
  Find: TFindRec;
  Source, Backup, Folder: String;
begin
  Folder := AddBackslash(ExpandConstant('{app}\WordAddin\Backup')) +
    GetDateTimeString('yyyymmdd-hhnnss', '-', ':');
  if FindFirst(AddBackslash(GetWordStartup('')) + '*.dotm', Find) then begin
    try
      repeat
        if ((Find.Attributes and FILE_ATTRIBUTE_DIRECTORY) = 0) and
          ((CompareText(Find.Name, 'SIMANIS-Word.dotm') = 0) or (CompareText(Copy(Find.Name, 1, 12), 'SIGANDI-Word') = 0)) then begin
          Source := AddBackslash(GetWordStartup('')) + Find.Name;
          if not ForceDirectories(Folder) then
            RaiseException('Folder cadangan add-in Word tidak dapat dibuat.');
          Backup := AddBackslash(Folder) + Find.Name;
          if not CopyFile(Source, Backup, False) then
            RaiseException('Add-in Word lama tidak dapat dicadangkan: ' + Source);
          if GetSHA256OfFile(Source) <> GetSHA256OfFile(Backup) then
            RaiseException('Verifikasi cadangan add-in Word gagal.');
          if not DeleteFile(Source) then
            RaiseException('Add-in Word lama masih digunakan. Tutup Word lalu jalankan ulang installer.');
          Log('Backed up old SIMANIS Word add-in: ' + Source);
        end;
      until not FindNext(Find);
    finally
      FindClose(Find);
    end;
  end;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  Installed, Details: String;
begin
  if CurStep = ssInstall then
    BackupLegacyAddins;
  if CurStep = ssPostInstall then begin
    Installed := AddBackslash(GetWordStartup('')) + 'SIMANIS-Word.dotm';
    if not FileExists(Installed) then
      RaiseException('Add-in Word belum terpasang. Jalankan ulang installer.');
    if CompareText(GetSHA256OfFile(Installed), '{#WordAddinSHA}') <> 0 then
      RaiseException('Verifikasi add-in Word yang terpasang gagal.');
    Details := 'SIMANIS Word 0.5.3' + #13#10 +
      'Startup=' + GetWordStartup('') + #13#10 +
      'SHA256=' + GetSHA256OfFile(Installed) + #13#10 +
      'Office macro security unchanged' + #13#10;
    SaveStringToFile(ExpandConstant('{app}\WordAddin\installation.txt'), Details, False);
    Log('SIMANIS Word installed and verified: ' + Installed);
  end;
end;

function InitializeUninstall: Boolean;
begin
  Result := True;
#ifndef IntegrationTest
  if FindWindowByClassName('OpusApp') <> 0 then begin
    MsgBox('Simpan dokumen Anda dan tutup Microsoft Word sebelum menghapus SIMANIS.', mbInformation, MB_OK);
    Result := False;
  end;
#endif
end;

function UpdateReadyMemo(Space, NewLine, MemoUserInfoInfo, MemoDirInfo, MemoTypeInfo,
  MemoComponentsInfo, MemoGroupInfo, MemoTasksInfo: String): String;
begin
  Result := MemoDirInfo + NewLine + NewLine +
    'Add-in Microsoft Word (desktop Windows):' + NewLine + Space +
    'SIMANIS dipasang otomatis dan dimuat setiap Word dibuka.' + NewLine + Space +
    'Server data: http://192.168.0.12:8000/api (LAN kantor).' + NewLine + Space +
    'Login akun SIMANIS, pilih Pekerjaan Saya untuk mengisi draf.' + NewLine + Space +
    'Simpan dokumen dan tutup Word sebelum melanjutkan.' + NewLine + NewLine + MemoTasksInfo;
end;
