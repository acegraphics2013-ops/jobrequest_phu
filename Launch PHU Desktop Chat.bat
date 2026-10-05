@echo off
title PHU Workbench - Desktop Floating Chat Heads Companion
cd /d "%~dp0desktop-chat"
echo Starting PHU Desktop Floating Chat Heads Companion...

set MINGW_PATH=C:\Users\ADMIN\AppData\Local\Microsoft\WinGet\Packages\BrechtSanders.WinLibs.POSIX.UCRT_Microsoft.Winget.Source_8wekyb3d8bbwe\mingw64\bin
set PATH=%USERPROFILE%\.cargo\bin;%MINGW_PATH%;%PATH%

npm run tauri:dev
