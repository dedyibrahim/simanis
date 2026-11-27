@echo off
setlocal EnableExtensions EnableDelayedExpansion

cd /d "%~dp0"

set "APP_NAME=SIMANIS"
set "DIST_DIR=dist\SIMANIS-v1.5.2"
set "ENTRY=garis_editor.py"
set "ICON=assets\app_icon.ico"
set "SPLASH=assets\splash_small.png"
set "MODEL_A=scan_line_model_datatrain_v2.json"
set "MODEL_B=scan_line_model_2folder_allakta.json"
set "PNG_ICON=assets\app_icon.png"

echo.
echo ===============================================
echo  Build EXE - %APP_NAME%
echo  Folder: %CD%
echo ===============================================
echo.

if not exist "%ENTRY%" (
  echo [ERROR] File entry tidak ditemukan: %ENTRY%
  goto :fail
)

if not exist "%ICON%" (
  echo [ERROR] Icon tidak ditemukan: %ICON%
  goto :fail
)

if not exist "%SPLASH%" (
  echo [ERROR] Splash image tidak ditemukan: %SPLASH%
  goto :fail
)

if not exist "%MODEL_A%" (
  echo [ERROR] Model tidak ditemukan: %MODEL_A%
  goto :fail
)

if not exist "%MODEL_B%" (
  echo [ERROR] Model tidak ditemukan: %MODEL_B%
  goto :fail
)

where python >nul 2>&1
if errorlevel 1 (
  echo [ERROR] Python tidak ditemukan di PATH.
  goto :fail
)

python -m pip show pyinstaller >nul 2>&1
if errorlevel 1 (
  echo [INFO] PyInstaller belum terpasang, mencoba install...
  python -m pip install pyinstaller
  if errorlevel 1 (
    echo [ERROR] Gagal install PyInstaller.
    goto :fail
  )
)

echo [INFO] Menjalankan PyInstaller...
python -m PyInstaller ^
  --noconfirm ^
  --clean ^
  --onefile ^
  --windowed ^
  --name "%APP_NAME%" ^
  --distpath "%DIST_DIR%" ^
  --icon "%ICON%" ^
  --version-file "assets\windows_version.txt" ^
  --hidden-import tkinter ^
  --hidden-import pyi_splash ^
  --add-data "%PNG_ICON%;assets" ^
  --add-data "%MODEL_A%;." ^
  --add-data "%MODEL_B%;." ^
  "%ENTRY%"

if errorlevel 1 (
  echo.
  echo [ERROR] Build gagal.
  goto :fail
)

echo.
echo [OK] Build selesai.
echo EXE: "%CD%\%DIST_DIR%\%APP_NAME%.exe"
echo.
pause
exit /b 0

:fail
echo.
echo Build gagal.
echo.
pause
exit /b 1
