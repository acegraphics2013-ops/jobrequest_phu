@echo off
title PHU Workbench Native Desktop Chat
set URL=http://localhost/jobrequest_phu/chat_center.php

where msedge >nul 2>&1
if %errorlevel%==0 (
    start msedge --app="%URL%" --window-size=1120,740 --user-data-dir="%LOCALAPPDATA%\PHUChatProfile"
    exit
)

where chrome >nul 2>&1
if %errorlevel%==0 (
    start chrome --app="%URL%" --window-size=1120,740 --user-data-dir="%LOCALAPPDATA%\PHUChatProfile"
    exit
)

start "" "%URL%"
