@echo off
REM Build the distributable plugin zip.
REM
REM Double-click this file, or run it from a terminal. No Composer needed —
REM it only requires PHP, which winget put on your PATH.

setlocal
cd /d "%~dp0\.."

where php >nul 2>nul
if errorlevel 1 (
    echo.
    echo ERROR: php was not found on your PATH.
    echo.
    echo If PHP was installed recently, close this window and open a NEW
    echo terminal - PATH changes only apply to newly opened windows.
    echo.
    echo Otherwise install it with:  winget install PHP.PHP.8.4
    echo.
    pause
    exit /b 1
)

php tools\build-zip.php
if errorlevel 1 (
    echo.
    echo Build FAILED.
    pause
    exit /b 1
)

echo.
echo Done. The zip is in the zips\ folder.
echo.
pause
