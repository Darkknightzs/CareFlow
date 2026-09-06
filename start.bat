@echo off
cd /d "%~dp0"

echo ========================================================
echo   CareFlow Hospital Queue System - Smart Start
echo ========================================================
echo.

:: 1. Check for PHP Installation (PATH, WinGet, or XAMPP)
echo Checking for PHP Installation...
where php >nul 2>nul
if %errorlevel% equ 0 (
    set PHP_CMD=php
    echo [OK] PHP found in PATH.
) else if exist "%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.2_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe" (
    set PHP_CMD="%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.2_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
    echo [OK] PHP found in WinGet Packages.
) else if exist "C:\xampp\php\php.exe" (
    set PHP_CMD="C:\xampp\php\php.exe"
    echo [OK] PHP found in C:\xampp\php.
) else (
    echo.
    echo ========================================================
    echo  ERROR: PHP and MySQL are missing!
    echo ========================================================
    echo This project requires XAMPP - PHP and MySQL - to run.
    echo.
    echo Press any key to open the XAMPP download page.
    echo Please install it, then run setup.bat again.
    pause
    start https://www.apachefriends.org/download.html
    exit /b
)
echo.

:: 2. Try to start MySQL Service if present
echo Checking MySQL Database Service...
sc query MySQL80 >nul 2>nul
if %errorlevel% equ 0 net start MySQL80 >nul 2>nul

sc query mysql >nul 2>nul
if %errorlevel% equ 0 net start mysql >nul 2>nul

echo.
echo ========================================================
echo Starting Server on http://localhost:8000
echo ========================================================
echo Do not close this window while using the app.
echo.

start http://localhost:8000/login.php
%PHP_CMD% -d extension=pdo_mysql -S localhost:8000 -t public
pause