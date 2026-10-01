@echo off
cd /d D:\xampp\htdocs\tg-bet-alert
echo Starting TG Bet Alert...
start "TG Alert Web" /MIN D:\xampp\php\php.exe -S 127.0.0.1:8090 router.php
timeout /t 1 >nul
start "TG Alert Watcher" /MIN D:\xampp\php\php.exe watcher.php
start "TG Alert Tunnel" cmd /c "D:\xampp\cloudflared.exe tunnel --url http://127.0.0.1:8090 --no-autoupdate > data\tunnel.log 2>&1"
echo.
echo Laptop on rakho. Phone par HTTPS link app ke upar dikhegi.
echo PIN: 2565
pause
