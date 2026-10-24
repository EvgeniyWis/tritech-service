# -*- coding: utf-8 -*-
"""Сборка пакета для бесплатного хостинга (InfinityFree и аналоги).

    python -X utf8 deploy/build.py <site_url> [db_host db_name db_user db_pass]

Результат в deploy/out/:
    site/                — содержимое для каталога htdocs (загружается по FTP)
    tritech_demo.sql     — дамп БД для импорта через phpMyAdmin
    triggers.sql         — необязательные триггеры (импортировать с DELIMITER $$)
    admin_password.txt   — пароль администратора для демо-сайта
"""
import os
import re
import secrets
import shutil
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'deploy', 'out')
PHP = r'C:\xampp\php\php.exe'
MYSQL = r'C:\xampp\mysql\bin\mysql.exe'

site_url = (sys.argv[1] if len(sys.argv) > 1 else 'https://example.infinityfreeapp.com').rstrip('/')
db_host, db_name, db_user, db_pass = (sys.argv[2:6] + ['', '', '', ''])[:4] if len(sys.argv) > 2 else ('sqlXXX.infinityfree.com', 'if0_XXXX_tritech', 'if0_XXXX', 'PASSWORD')

shutil.rmtree(OUT, ignore_errors=True)
os.makedirs(OUT)

# ---------------------------------------------------------------- дамп БД
subprocess.run([PHP, 'database/install.php', '--force', '--demo'], cwd=ROOT, check=True, stdout=subprocess.DEVNULL)
admin_pass = secrets.token_urlsafe(9) + '7a'
hash_php = "echo password_hash('%s', PASSWORD_BCRYPT, ['cost' => 10]);" % admin_pass
h = subprocess.run([PHP, '-r', hash_php], capture_output=True, text=True, check=True).stdout.strip()
sql = "UPDATE users SET password_hash = '%s' WHERE email = 'admin@tritech-service.ru'; TRUNCATE TABLE activity_log;" % h
subprocess.run([MYSQL, '-u', 'root', 'tritech_service', '-e', sql], check=True)
name = subprocess.run([PHP, '-r', "require 'src/bootstrap.php'; echo App\\Services\\Backup::create();"], cwd=ROOT, capture_output=True, text=True, check=True).stdout.strip()
shutil.move(os.path.join(ROOT, 'storage', 'backups', name), os.path.join(OUT, 'tritech_demo.sql'))
# убрать из хранилища временную копию и вернуть локальный пароль администратора
subprocess.run([PHP, 'database/install.php', '--force', '--demo'], cwd=ROOT, check=True, stdout=subprocess.DEVNULL)

with open(os.path.join(ROOT, 'database', 'triggers.sql'), encoding='utf-8') as f:
    trg = f.read()
parts = [re.sub(r'^\s*--.*$', '', p, flags=re.M).strip() for p in re.split(r'^--#\s*$', trg, flags=re.M)]
parts = [p for p in parts if p]
with open(os.path.join(OUT, 'triggers.sql'), 'w', encoding='utf-8') as f:
    f.write('DELIMITER $$\n' + '\n'.join(p + '\n$$' for p in parts) + '\nDELIMITER ;\n')
with open(os.path.join(OUT, 'admin_password.txt'), 'w', encoding='utf-8') as f:
    f.write(f'admin@tritech-service.ru\n{admin_pass}\n')

# ---------------------------------------------------------------- файлы сайта
site = os.path.join(OUT, 'site')
skip_dirs = {'tests', 'docs', 'deploy', '_shots', '_design', '__pycache__', '.git'}
for cur, dirs, files in os.walk(ROOT):
    rel = os.path.relpath(cur, ROOT)
    top = rel.split(os.sep)[0]
    if top in skip_dirs:
        dirs[:] = []
        continue
    if top == 'storage':
        continue
    for fn in files:
        if fn in ('config.local.php', 'start.bat') or fn.endswith('.pyc'):
            continue
        dst = os.path.join(site, rel, fn) if rel != '.' else os.path.join(site, fn)
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        shutil.copy2(os.path.join(cur, fn), dst)
for d in ('uploads', 'mails', 'backups', 'logs'):
    os.makedirs(os.path.join(site, 'storage', d), exist_ok=True)
    open(os.path.join(site, 'storage', d, '.gitkeep'), 'w').close()
    # прямой доступ к storage закрыт на уровне корневого .htaccess; дублируем защиту
    with open(os.path.join(site, 'storage', d, '.htaccess'), 'w') as f:
        f.write('Require all denied\nDeny from all\n')
shutil.copy2(os.path.join(ROOT, 'deploy', 'htaccess-root.txt'), os.path.join(site, '.htaccess'))

cfg = f"""<?php
// Конфигурация демо-сайта (хостинг)
return [
    'app' => ['url' => '{site_url}', 'debug' => false, 'dev_links' => true, 'demo' => true, 'lazy_cron' => true],
    'db' => ['host' => '{db_host}', 'port' => 3306, 'name' => '{db_name}', 'user' => '{db_user}', 'pass' => '{db_pass}'],
    'yookassa' => ['return_url' => '{site_url}/#/my/appointments'],
];
"""
with open(os.path.join(site, 'config', 'config.local.php'), 'w', encoding='utf-8') as f:
    f.write(cfg)

n = sum(len(fs) for _, _, fs in os.walk(site))
print('Файлов для загрузки:', n)
print('Дамп:', os.path.getsize(os.path.join(OUT, 'tritech_demo.sql')) // 1024, 'КБ')
print('Каталог пакета:', OUT)
