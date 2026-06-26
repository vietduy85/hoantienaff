@echo off

set /p URL=Nhap link Shopee:

curl -X POST http://127.0.0.1:3001/shopee/create-link ^
-H "Content-Type: application/json" ^
-d "{\"url\":\"%URL%\"}"

echo.
pause