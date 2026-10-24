# -*- coding: utf-8 -*-
"""Скриншоты экранов для визуальной проверки.
Использование: python -X utf8 tests/shots.py <outdir> <email|-> <route> [<route> ...]
"""
import sys
from playwright.sync_api import sync_playwright

BASE = 'http://127.0.0.1:8200'


def main():
    out, email, *routes = sys.argv[1:]
    pwd = 'Admin12345' if email.startswith('admin@') else 'Demo12345'
    with sync_playwright() as p:
        b = p.chromium.launch(channel='chrome')
        ctx = b.new_context(viewport={'width': 1440, 'height': 900})
        page = ctx.new_page()
        logs = []
        page.on('console', lambda m: logs.append(f'[{m.type}] {m.text}') if m.type in ('error', 'warning') else None)
        page.on('pageerror', lambda e: logs.append(f'[pageerror] {e}'))
        page.goto(BASE + '/#/login')
        page.wait_for_timeout(600)
        if email != '-':
            page.fill('input[name=email]', email)
            page.fill('input[name=password]', pwd)
            page.click('button[type=submit]')
            page.wait_for_timeout(1000)
        for i, r in enumerate(routes):
            full = False
            if r.endswith('!'):
                r = r[:-1]
                full = True
            page.goto(BASE + '/#' + r)
            page.wait_for_timeout(1500)
            name = f"{out}/{i:02d}_{r.strip('/').replace('/', '_').replace('?', '_').replace('=', '_') or 'root'}.png"
            page.screenshot(path=name, full_page=full)
            print(name)
        for l in logs:
            print(l)
        b.close()


if __name__ == '__main__':
    main()
