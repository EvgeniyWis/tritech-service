@echo off
rem Полный прогон тестов. Требуется: запущенная БД и сервер (start.bat), Python 3 + playwright для UI-тестов.
rem ВНИМАНИЕ: база tritech_service пересоздаётся с демо-данными!
chcp 65001 >nul
cd /d "%~dp0.."
set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" set "PHP=php"

"%PHP%" database\install.php --force --demo >nul || goto :err
echo === Модульные тесты SLA
"%PHP%" tests\sla_test.php || goto :err
echo === Интеграционные тесты API
python -X utf8 tests\api_test.py || goto :err
echo === Сквозные UI-тесты
"%PHP%" database\install.php --force --demo >nul
python -X utf8 tests\e2e.py _shots || goto :err
echo.
echo Все тесты пройдены
exit /b 0
:err
echo.
echo ТЕСТЫ НЕ ПРОЙДЕНЫ
exit /b 1
