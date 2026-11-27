@echo off
setlocal EnableExtensions

cd /d "%~dp0"

set "EXE_PATH=dist\SIMANIS-v1.5.3\SIMANIS.exe"
set "ISS_FILE=GarisAktaEditor.iss"

echo.
echo ===============================================
echo  Build Installer (Inno Setup)
echo ===============================================
echo.

if not exist "%EXE_PATH%" (
  echo [ERROR] EXE belum ada: %EXE_PATH%
  echo Jalankan build_garis_exe.bat terlebih dahulu.
  goto :fail
)

if not exist "%ISS_FILE%" (
  echo [ERROR] File Inno Setup tidak ditemukan: %ISS_FILE%
  goto :fail
)

where ISCC >nul 2>&1
if errorlevel 1 (
  if exist "C:\Program Files (x86)\Inno Setup 6\ISCC.exe" (
    set "ISCC_PATH=C:\Program Files (x86)\Inno Setup 6\ISCC.exe"
  ) else (
    echo [ERROR] ISCC tidak ditemukan.
    echo Install Inno Setup 6 atau tambahkan ISCC ke PATH.
    goto :fail
  )
) else (
  set "ISCC_PATH=ISCC"
)

echo [INFO] Compiling installer...
python -m PyInstaller --noconfirm --clean --onefile --windowed --name SIMANIS-WordData --distpath output\word-addin\installer --workpath tmp\data-build --specpath tmp simanis_data.py
if errorlevel 1 goto :fail
python word-addin\build_addin.py
if errorlevel 1 (
  echo [ERROR] Build add-in Word gagal.
  goto :fail
)
"%ISCC_PATH%" "%ISS_FILE%"
if errorlevel 1 (
  echo [ERROR] Build installer gagal.
  goto :fail
)

echo.
echo [OK] Installer berhasil dibuat.
echo Cek folder dist untuk file:
echo SIMANIS_Setup_v1.5.3.exe
echo.
pause
exit /b 0

:fail
echo.
echo Build installer gagal.
echo.
pause
exit /b 1
