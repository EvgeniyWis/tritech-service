@echo off
rem ТрайтэкСервис: запуск для разработки/демонстрации (Windows + XAMPP)
chcp 65001 >nul
setlocal
set "ROOT=%~dp0"
set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" set "PHP=php"

rem 1. СУБД: MariaDB из XAMPP (если ещё не запущена)
tasklist /FI "IMAGENAME eq mysqld.exe" 2>nul | find /I "mysqld.exe" >nul
if errorlevel 1 (
  if exist "C:\xampp\mysql\bin\mysqld.exe" (
    echo Запуск MariaDB...
    start "" /B "C:\xampp\mysql\bin\mysqld.exe" --defaults-file="C:\xampp\mysql\bin\my.ini" --standalone
    timeout /t 6 >nul
  ) else (
    echo MySQL/MariaDB не найдена. Запустите сервер БД и повторите.
  )
)

cd /d "%ROOT%"

rem 2. Установка БД с демо-данными (если базы ещё нет; существующая база не затрагивается)
"%PHP%" database\install.php --demo 2>nul

rem 3. Веб-сервер
echo.
echo Приложение: http://127.0.0.1:8200
echo Демо-вход: kozlova@example.com / Demo12345  (другие роли — на странице входа)
echo Остановка: Ctrl+C
"%PHP%" -S 127.0.0.1:8200 -t public public\index.php
