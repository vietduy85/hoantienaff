@echo off

echo.
echo ===== CDP =====
curl http://127.0.0.1:3001/diagnostic/cdp

echo.
echo.

echo ===== Custom Link =====
curl http://127.0.0.1:3001/diagnostic/custom-link

echo.
pause