@echo off
REM Run the pre-release checks: PHP syntax lint, then PHPStan static analysis.
REM
REM Double-click this file, or run it from a terminal. Run it before tagging.

setlocal
cd /d "%~dp0\.."

where php >nul 2>nul
if errorlevel 1 (
    echo.
    echo ERROR: php was not found on your PATH.
    echo Close this window and open a NEW terminal, or install PHP with:
    echo     winget install PHP.PHP.8.4
    echo.
    pause
    exit /b 1
)

echo === Syntax check ===
php tools\lint.php
if errorlevel 1 goto failed

echo.
echo === View markup ===
php tools\check-views.php
if errorlevel 1 goto failed

echo.
echo === Format strings ===
php tools\check-formats.php
if errorlevel 1 goto failed

echo.
echo === Menu and tabs ===
php tools\check-tabs.php
if errorlevel 1 goto failed

echo.
echo === Static analysis ===
if not exist "vendor\phpstan\phpstan\phpstan.phar" (
    echo PHPStan is not installed. Installing dependencies...
    php composer.phar install --no-interaction --no-progress
    if errorlevel 1 goto failed
)
php vendor\phpstan\phpstan\phpstan.phar analyse --memory-limit=3G --no-progress
if errorlevel 1 goto failed

echo.
echo All checks passed.
echo.
pause
exit /b 0

:failed
echo.
echo CHECKS FAILED - do not tag a release until these are fixed.
echo.
pause
exit /b 1
