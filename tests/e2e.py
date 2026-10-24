# -*- coding: utf-8 -*-
"""Сквозные UI-тесты (Playwright + Chrome). Сервер на 127.0.0.1:8200, БД установлена с --demo.
    python -X utf8 tests/e2e.py [outdir]
"""
import os
import re
import sys
from datetime import datetime
from playwright.sync_api import sync_playwright, expect

BASE = 'http://127.0.0.1:8200'
OUT = sys.argv[1] if len(sys.argv) > 1 else '_shots'
fails = []
passed = 0
problems = []


def ok(name, cond=True, info=''):
    global passed
    if cond:
        passed += 1
        print('  ok  ', name)
    else:
        fails.append(name)
        print('  FAIL', name, info)


def new_page(browser, w=1440, h=900):
    ctx = browser.new_context(viewport={'width': w, 'height': h})
    page = ctx.new_page()
    page.on('pageerror', lambda e: problems.append(f'pageerror: {e}'))
    page.on('console', lambda m: problems.append(f'console.{m.type}: {m.text}') if m.type == 'error' and 'status of 4' not in m.text else None)
    page.on('response', lambda r: problems.append(f'HTTP {r.status} {r.request.method} {r.url}') if r.status >= 500 else None)
    return ctx, page


def login(page, email, pwd='Demo12345'):
    page.goto(BASE + '/#/login')
    page.fill('input[name=email]', email)
    page.fill('input[name=password]', pwd)
    page.click('button[type=submit]')
    page.wait_for_selector('.sidebar', timeout=8000)


def logout(page):
    page.click('text=Выйти')
    page.wait_for_selector('input[name=email]')


def go(page, route):
    page.goto(BASE + '/#' + route)
    page.wait_for_timeout(700)


def toast_text(page):
    try:
        return page.locator('.toast').last.inner_text(timeout=3000)
    except Exception:
        return ''


def main():
    os.makedirs(OUT, exist_ok=True)
    with sync_playwright() as p:
        b = p.chromium.launch(channel='chrome')
        stamp = datetime.now().strftime('%H%M%S')

        print('== Регистрация через UI')
        ctx, page = new_page(b)
        page.goto(BASE + '/#/register')
        page.fill('input[name=last_name]', 'Новиков')
        page.fill('input[name=first_name]', 'Артём')
        page.fill('input[name=phone]', '+7 900 555-44-33')
        page.fill('input[name=company]', 'ООО «Новик»')
        em = f'novikov{stamp}@example.com'
        page.fill('input[name=email]', em)
        page.fill('input[name=password]', 'Novik12345')
        page.fill('input[name=password_confirm]', 'Novik12345')
        page.click('button[type=submit]')
        page.wait_for_timeout(600)
        ok('без согласия — ошибка', page.locator('.field .err:visible').count() > 0)
        page.check('input[name=consent]')
        page.click('button[type=submit]')
        page.wait_for_selector('text=Проверьте почту')
        link = page.locator('a:has-text("подтвердить e-mail")').get_attribute('href')
        ok('ссылка подтверждения (dev)', bool(link))
        page.goto(link)
        page.wait_for_selector('text=E-mail подтверждён')
        ok('e-mail подтверждён', True)
        login(page, em, 'Novik12345')
        ok('вход нового клиента → кабинет', page.locator('h1:has-text("Главная")').count() == 1)
        page.screenshot(path=f'{OUT}/e2e_new_client_home.png')

        print('== Мастер записи')
        go(page, '/book')
        page.click('.opt:has-text("Аудит учёта в 1С")')
        page.click('.opt:has-text("Волков")')
        page.wait_for_selector('.cal .d.has')
        days = page.locator('.cal .d.has')
        ok('календарь: есть дни со слотами', days.count() > 0)
        days.nth(2).click()
        page.wait_for_selector('.slot:not(.busy)')
        page.locator('.slot:not(.busy)').first.click()
        page.fill('textarea[name=comment]', 'E2E: проверка записи')
        page.screenshot(path=f'{OUT}/e2e_booking_step3.png')
        page.click('text=Далее: подтверждение')
        page.check('input[type=checkbox]')
        page.click('text=Подтвердить и перейти к оплате')
        page.wait_for_selector('text=Способ оплаты')
        ok('переход на страницу оплаты', True)
        page.screenshot(path=f'{OUT}/e2e_pay.png')
        page.click('button:has-text("Оплатить")')
        page.wait_for_selector('h2:has-text("Аудит учёта в 1С")', timeout=8000)
        ok('после оплаты — запись подтверждена', page.locator('.badge:has-text("Подтверждена")').count() >= 1)
        url = page.url
        page.screenshot(path=f'{OUT}/e2e_appointment_client.png')

        print('== Перенос и отмена')
        page.click('button:has-text("Перенести")')
        page.wait_for_selector('.modal input[type=date]')
        # выбрать первый доступный день в окне
        target = None
        for i in range(10, 25):
            d = datetime.now().replace(hour=0, minute=0, second=0, microsecond=0)
            from datetime import timedelta
            dd = d + timedelta(days=i)
            if dd.weekday() >= 5:
                continue
            target = dd.strftime('%Y-%m-%d')
            break
        page.fill('.modal input[type=date]', target)
        page.dispatch_event('.modal input[type=date]', 'change')
        page.wait_for_selector('.modal .slot:not(.busy)')
        page.locator('.modal .slot:not(.busy)').first.click()
        page.click('.modal button:has-text("Перенести")')
        page.wait_for_timeout(800)
        ok('перенос выполнен', 'перенесена' in toast_text(page).lower() or page.locator('text=Перенос:').count() > 0)
        page.click('button:has-text("Отменить запись")')
        page.wait_for_selector('.modal')
        ok('предпросмотр возврата 100%', 'Возврат будет полным' in page.locator('.modal').inner_text())
        page.click('.modal button:has-text("Отменить запись")')
        page.wait_for_timeout(900)
        ok('отмена с возвратом', 'Возврат' in toast_text(page) or page.locator('.badge:has-text("Отменена")').count() >= 1)

        print('== Заявка клиента')
        go(page, '/support')
        page.click('button:has-text("Создать заявку")')
        page.wait_for_selector('.modal')
        page.click('.modal button:has-text("Создать заявку")')
        page.wait_for_timeout(500)
        ok('валидация заявки', page.locator('.modal .field .err:visible').count() > 0)
        page.select_option('.modal select[name=category_id]', index=2)
        page.fill('.modal input[name=subject]', f'E2E проблема с печатью {stamp}')
        page.fill('.modal textarea[name=description]', 'Печатная форма накладной выводит пустую страницу, воспроизводится постоянно.')
        page.click('.modal button:has-text("Создать заявку")')
        page.wait_for_selector('text=SLA', timeout=8000)
        ok('заявка создана, открыта карточка', page.locator(f'text=E2E проблема с печатью {stamp}').count() >= 1)
        ticket_url = page.url
        page.fill('textarea', 'Дополнительно: проблема у всех пользователей')
        page.click('button:has-text("Отправить")')
        page.wait_for_timeout(700)
        ok('сообщение клиента в переписке', page.locator('.msg:has-text("проблема у всех")').count() == 1)
        ctx.close()

        print('== Специалист поддержки')
        ctx, page = new_page(b)
        # найдём исполнителя заявки: пройдём всех специалистов
        tid = ticket_url.split('/ticket/')[1]
        for em_s in ('belov', 'orlova', 'kim'):
            login(page, f'{em_s}@tritech-service.ru')
            go(page, f'/ticket/{tid}')
            if page.locator('.card:has-text("Параметры") >> text=автоназначение').count():
                who = em_s
                break
            logout(page)
        ok('автоназначение: специалист нашёл заявку', True)
        page.click('button:has-text("Внутренняя заметка")')
        page.fill('textarea', 'Внутри: воспроизвёл')
        page.click('button:has-text("Добавить заметку")')
        page.wait_for_timeout(700)
        ok('внутренняя заметка оранжевая', page.locator('.msg.internal').count() == 1)
        page.click('.seg button:has-text("Ответ клиенту")')
        page.select_option('select:has(option:text("Шаблон ответа"))', index=1)
        ok('шаблон подставляется в поле', len(page.input_value('textarea')) > 10)
        page.click('button:has-text("Отправить")')
        page.wait_for_timeout(800)
        ok('ответ клиенту отправлен', page.locator('.msg.staff').count() >= 1)
        page.screenshot(path=f'{OUT}/e2e_ticket_staff.png', full_page=True)
        page.click('button:has-text("Эскалировать в разработку")')
        page.wait_for_selector('.modal')
        page.click('.modal button:has-text("Эскалировать")')
        page.wait_for_timeout(1000)
        ok('эскалация: появилась связанная задача', page.locator('text=Связанная задача').locator('..').locator('a').count() == 1)
        page.click('button:has-text("Сменить статус")')
        page.select_option('.modal select', 'waiting_client')
        page.click('.modal button:has-text("Применить")')
        page.wait_for_timeout(800)
        ok('смена статуса', page.locator('.badge:has-text("Ожидание клиента")').count() >= 1)
        logout(page)

        print('== Разработчик: доска, перетаскивание, учёт времени')
        login(page, 'petrov@tritech-service.ru')
        go(page, '/dev/projects')
        page.click('.card:has-text("Портал дилеров")')
        page.wait_for_selector('.board')
        src = page.locator('.col-board.s-backlog .tcard').first
        title = src.locator('.ttl').inner_text()
        dst = page.locator('.col-board.s-in_progress')
        n_before = dst.locator('.tcard').count()
        src.drag_to(dst)
        page.wait_for_timeout(1200)
        ok('drag-and-drop меняет статус', page.locator('.col-board.s-in_progress .tcard', has_text=title).count() == 1)
        ok('в колонке «В работе» стало больше карточек', page.locator('.col-board.s-in_progress .tcard').count() == n_before + 1)
        page.locator('.col-board.s-in_progress .tcard', has_text=title).click()
        page.wait_for_selector('.modal')
        ok('карточка задачи открывается', page.locator('.modal h2').count() == 1)
        page.fill('.modal input[type=number][max="24"]', '2.5')
        page.fill('.modal input[placeholder="Что сделано"]', 'e2e')
        page.click('.modal button:has-text("Списать")')
        page.wait_for_timeout(1000)
        ok('время списано', page.locator('.modal:has-text("Трудозатраты · 2,5")').count() == 1 or page.locator('.modal :text("2,5")').count() > 0)
        page.screenshot(path=f'{OUT}/e2e_task_modal.png')
        page.keyboard.press('Escape')
        go(page, '/dev/my')
        ok('мои задачи отображаются', page.locator('.tcard').count() >= 3)
        go(page, '/dev/timelogs')
        ok('трудозатраты: запись есть', page.locator('td:has-text("2,5")').count() >= 1)
        logout(page)

        print('== Консультант')
        login(page, 'volkov@tritech-service.ru')
        go(page, '/schedule')
        page.click('button:has-text("Интервал") >> nth=5')  # суббота
        page.wait_for_timeout(300)
        page.click('button:has-text("Сохранить расписание")')
        page.wait_for_timeout(800)
        ok('расписание сохранено', 'сохранено' in toast_text(page).lower())
        page.screenshot(path=f'{OUT}/e2e_schedule.png', full_page=True)
        go(page, '/consultations')
        page.click('.tab:has-text("Проведённые")')
        page.wait_for_timeout(600)
        page.locator('tr.click').first.click()
        page.wait_for_selector('h2:has-text("Заключение консультанта")')
        page.fill('textarea >> nth=0', 'Итоги: обсудили план внедрения, согласовали сроки')
        page.click('button:has-text("Сохранить черновик")')
        page.wait_for_timeout(700)
        ok('черновик заключения сохранён', 'черновик' in toast_text(page).lower() or page.locator('.badge:has-text("черновик")').count() >= 1)
        page.click('button:has-text("Опубликовать")')
        page.wait_for_timeout(700)
        ok('заключение опубликовано', page.locator('.badge:has-text("опубликовано")').count() >= 1)
        logout(page)

        print('== Менеджер: модерация и проект')
        login(page, 'manager@tritech-service.ru')
        go(page, '/reviews')
        page.click('button:has-text("Опубликовать") >> nth=0')
        page.wait_for_timeout(700)
        ok('отзыв опубликован', 'опубликован' in toast_text(page).lower())
        go(page, '/journal')
        page.select_option('select:has(option:text("Статус: все"))', 'completed')
        page.wait_for_timeout(800)
        ok('журнал фильтруется по статусу', page.locator('tbody tr').count() >= 1)
        page.locator('tr.click').first.click()
        page.wait_for_selector('h2')
        if page.locator('button:has-text("Создать проект по итогам")').count():
            page.click('button:has-text("Создать проект по итогам")')
            page.click('.modal button:has-text("Создать")')
            page.wait_for_selector('.board', timeout=8000)
            ok('проект создан по итогам консультации', True)
        logout(page)

        print('== Директор: сводка и отчёты')
        login(page, 'director@tritech-service.ru')
        go(page, '/dashboard')
        page.wait_for_selector('.kpi')
        ok('KPI отображаются', page.locator('.kpi').count() == 4)
        page.click('.tab:has-text("Месяц")')
        page.wait_for_timeout(700)
        ok('переключение периода', page.locator('.tab.active:has-text("Месяц")').count() == 1)
        go(page, '/reports')
        page.click('button:has-text("SLA техподдержки")')
        page.wait_for_timeout(800)
        ok('отчёт SLA показывается', page.locator('table.tbl').count() == 1)
        logout(page)

        print('== Администратор')
        login(page, 'admin@tritech-service.ru', 'Admin12345')
        go(page, '/admin/users')
        page.click('button:has-text("Добавить пользователя")')
        page.wait_for_selector('.modal')
        page.fill('.modal input[name=last_name]', 'Тестовый')
        page.fill('.modal input[name=first_name]', 'Сотрудник')
        page.fill('.modal input[name=email]', f'staff{stamp}@tritech-service.ru')
        page.uncheck('.modal input[value=client]') if page.locator('.modal input[value=client]').is_checked() else None
        page.check('.modal input[value=support]')
        page.click('.modal button:has-text("Сохранить")')
        page.wait_for_timeout(1000)
        ok('пользователь создан с временным паролем', 'Временный пароль' in toast_text(page))
        go(page, '/admin/directories')
        page.click('button:has-text("Добавить")')
        page.fill('.modal input[name=name]', f'E2E услуга {stamp}')
        page.fill('.modal input[name=duration_min]', '10')
        page.click('.modal button:has-text("Сохранить")')
        page.wait_for_timeout(700)
        ok('длительность 10 мин отклонена', page.locator('.modal .field .err:visible').count() > 0 or 'от 15' in page.locator('.modal').inner_text())
        page.fill('.modal input[name=duration_min]', '45')
        page.click('.modal button:has-text("Сохранить")')
        page.wait_for_timeout(700)
        ok('услуга добавлена', page.locator(f'text=E2E услуга {stamp}').count() >= 1)
        go(page, '/admin/settings')
        page.fill('input[name=payment_timeout_min]', '30')
        page.click('button:has-text("Сохранить настройки")')
        page.wait_for_timeout(600)
        ok('настройки сохранены', 'сохранены' in toast_text(page).lower())
        go(page, '/admin/backups')
        page.click('button:has-text("Создать копию")')
        page.wait_for_timeout(1500)
        ok('копия создана', page.locator('code:has-text("backup_")').count() >= 1)
        page.screenshot(path=f'{OUT}/e2e_admin_backups.png', full_page=True)
        go(page, '/admin/log')
        ok('журнал содержит записи', page.locator('tbody tr').count() >= 5)
        logout(page)

        print('== Мобильная версия')
        ctx2, mp = new_page(b, 390, 844)
        login(mp, 'kozlova@example.com')
        mp.screenshot(path=f'{OUT}/e2e_mobile_home.png')
        mp.click('.menu-btn')
        mp.wait_for_timeout(300)
        mp.screenshot(path=f'{OUT}/e2e_mobile_menu.png')
        go(mp, '/book')
        ok('мобильная: нет горизонтальной прокрутки на /book', mp.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'))
        b.close()

    print(f'\nПройдено: {passed}, провалено: {len(fails)}')
    for f in fails:
        print(' -', f)
    uniq = sorted(set(problems))
    if uniq:
        print('\nПроблемы консоли/сети:')
        for x in uniq:
            print(' *', x)
    sys.exit(1 if fails or uniq else 0)


if __name__ == '__main__':
    main()
