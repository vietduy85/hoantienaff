@echo off
title Shopee Chrome CDP

echo =====================================
echo   Starting Chrome with CDP...
echo =====================================
echo.

taskkill /F /IM chrome.exe >nul 2>&1

timeout /t 2 >nul

start "" "C:\Program Files (x86)\Google\Chrome\Application\chrome.exe" ^
  --remote-debugging-port=9222 ^
  --user-data-dir=C:\Users\Administrator\shopee-chrome-profile

echo.
echo Chrome started.
echo.

timeout /t 5 >nul

start "" "https://affiliate.shopee.vn/offer/custom_link"

pause