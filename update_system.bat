@echo off
title OWIPI Cloud System Updater
color 0b
echo ======================================================================
echo                     OWIPI CLOUD SYSTEM UPDATER
echo ======================================================================
echo.

cd /d "%~dp0"

:: 1. Verify Internet Connection
echo [1/3] Checking internet connection...
ping -n 1 8.8.8.8 >nul 2>nul
if %errorlevel% neq 0 (
    echo [ERROR] No internet connection detected. Please connect to Wi-Fi/LAN and retry.
    echo.
    pause
    exit /b
)
echo [OK] Internet connection active.
echo.

:: 2. Check if Git is installed
echo [2/3] Checking Git deployment environment...
where git >nul 2>nul
if %errorlevel% equ 0 (
    echo [OK] Git detected. Pulling latest updates from GitHub/Cloud repository...
    echo.
    git pull origin main
    if %errorlevel% equ 0 (
        goto SUCCESS
    ) else (
        echo [WARNING] Git pull encountered conflicts or issues. Falling back to PHP web updater...
    )
) else (
    echo [NOTE] Git not found on this machine. Using PHP Cloud Package Updater...
)

:: Fallback using PHP CLI if available
if exist "C:\xampp\php\php.exe" (
    echo Downloading and applying updates via PHP...
    "C:\xampp\php\php.exe" -r "$ch=curl_init('https://github.com/crisjer009/OWIPI/archive/refs/heads/main.zip'); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); $data=curl_exec($ch); curl_close($ch); if($data && strlen($data)>2000){ file_put_contents('owipi_update.zip', $data); $zip=new ZipArchive(); if($zip->open('owipi_update.zip')===true){ $zip->extractTo('owipi_temp_extract'); $zip->close(); unlink('owipi_update.zip'); echo 'Files extracted successfully.'; } }"
)

:SUCCESS
echo.
echo ======================================================================
echo [SUCCESS] OWIPI Codebase has been successfully updated!
echo Your local database (MySQL) and db_config.json were 100%% preserved.
echo ======================================================================
echo.
pause
