@echo off
powershell.exe -NoProfile -File "%~dp0Install-SIMANIS-Word.ps1"
if errorlevel 1 pause
