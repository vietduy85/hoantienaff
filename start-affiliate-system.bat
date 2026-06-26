@echo off
title Shopee Affiliate System V3
color 0A

echo ======================================================
echo        SHOPEE AFFILIATE SYSTEM V3
echo ======================================================
echo.

::---------------------------------------------------------
:: STEP 1 - Kill old Chrome
::---------------------------------------------------------

echo [1/5] Closing old Chrome...
taskkill /F /IM chrome.exe >nul 2>&1

::---------------------------------------------------------
:: STEP 2 - Start Chrome CDP
::---------------------------------------------------------

echo.
echo [2/5] Starting Chrome...

start "" "C:\Program Files (x86)\Google\Chrome\Application\chrome.exe" ^
--remote-debugging-port=9222 ^
--user-data-dir=C:\Users\Administrator\shopee-chrome-profile

echo Waiting Chrome...

:WAIT_CHROME

curl -s http://127.0.0.1:9222/json/version >nul 2>&1

if errorlevel 1 (
    timeout /t 1 >nul
    goto WAIT_CHROME
)

echo Chrome Ready.



::---------------------------------------------------------
:: STEP 3 - Start Worker
::---------------------------------------------------------

echo.
echo [3/5] Starting Affiliate Worker...

start "Affiliate Worker" cmd /k ^
"cd /d C:\xampp\htdocs\hoantienaff\affiliate-worker && npm start"

echo Waiting Worker...

:WAIT_WORKER

curl -s http://127.0.0.1:3001/health >nul 2>&1

if errorlevel 1 (
    timeout /t 1 >nul
    goto WAIT_WORKER
)

echo Worker Ready.

::---------------------------------------------------------
:: STEP 4 - Check CDP
::---------------------------------------------------------

echo.
echo [4/5] Checking Chrome CDP...

curl -s http://127.0.0.1:3001/diagnostic/cdp > "%TEMP%\cdp.json"

findstr /C:"\"connected\":true" "%TEMP%\cdp.json" >nul

if errorlevel 1 (

    echo.
    echo ============================================
    echo ERROR
    echo.
    echo Cannot connect to Chrome CDP.
    echo ============================================
    echo.

    type "%TEMP%\cdp.json"

    pause
    exit
)

echo CDP OK.

::---------------------------------------------------------
:: STEP 5 - Check Custom Link
::---------------------------------------------------------

echo.
echo [5/5] Checking Custom Link page...

curl -s http://127.0.0.1:3001/diagnostic/custom-link > "%TEMP%\custom.json"

findstr /C:"ALREADY_ON_CUSTOM_LINK" "%TEMP%\custom.json" >nul

if errorlevel 1 (

    echo.
    echo =====================================================
    echo ATTENTION
    echo.
    echo Chrome is running,
    echo Worker is running,
    echo.
    echo But you are NOT on:
    echo.
    echo https://affiliate.shopee.vn/offer/custom_link
    echo.
    echo Please:
    echo.
    echo 1. Login Shopee Affiliate
    echo 2. Open Custom Link page
    echo 3. Run this BAT again
    echo =====================================================
    echo.

    type "%TEMP%\custom.json"

    pause
    exit
)

echo.
echo ======================================================
echo.
echo              SYSTEM READY
echo.
echo Chrome      : OK
echo Worker      : OK
echo CDP         : OK
echo Custom Link : OK
echo.
echo Ready to create affiliate links.
echo.
echo ======================================================

pause