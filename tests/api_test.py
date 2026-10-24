# -*- coding: utf-8 -*-
"""Интеграционные тесты API ТрайтэкСервис.

Запуск (сервер должен работать на 127.0.0.1:8200, БД установлена с --demo):
    python tests/api_test.py
"""
import http.cookiejar
import json
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import date, datetime, timedelta

import os, shlex
BASE = os.environ.get('TT_BASE', 'http://127.0.0.1:8200')
PASS = 'Demo12345'


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = ''
        self.me_data = None
        self.refresh()

    def refresh(self):
        _, d = self.call('GET', '/api/auth/me')
        self.csrf = d['csrf']
        self.me_data = d['user']
        return d

    def call(self, method, path, body=None, raw=False, headers=None):
        data = None
        headers = {'X-CSRF-Token': self.csrf, **(headers or {})}
        if body is not None:
            data = json.dumps(body).encode()
            headers['Content-Type'] = 'application/json'
        req = urllib.request.Request(BASE + urllib.parse.quote(path, safe='/?&=:+%'), data=data, method=method, headers=headers)
        try:
            r = self.op.open(req)
            payload = r.read()
            code = r.status
        except urllib.error.HTTPError as e:
            payload = e.read()
            code = e.code
        if raw:
            return code, payload
        try:
            return code, json.loads(payload)
        except Exception:
            return code, payload.decode('utf-8', 'replace')

    def login(self, email, password=PASS):
        code, d = self.call('POST', '/api/auth/login', {'email': email, 'password': password})
        if code == 200:
            self.csrf = d['csrf']
            self.me_data = d['user']
        return code, d


fails = []
passed = 0
MYSQL = shlex.split(os.environ.get('TT_MYSQL', r'C:/xampp/mysql/bin/mysql.exe -u root'))
DBNAME = os.environ.get('TT_DB', 'tritech_service')
ADMIN_PW = os.environ.get('TT_ADMIN_PW', 'Admin12345')


def sql(q):
    r = subprocess.run([*MYSQL, DBNAME, '-N', '-B', '--default-character-set=utf8mb4', '-e', "SET time_zone = '+03:00'; " + q], capture_output=True, text=True, encoding='utf-8')
    if r.returncode:
        raise RuntimeError(r.stderr)
    return r.stdout.strip()


def check(name, cond, info=''):
    global passed
    if cond:
        passed += 1
        print('  ok  ', name)
    else:
        fails.append(name)
        print('  FAIL', name, info if info else '')


def as_user(email, password=PASS):
    c = Client()
    code, d = c.login(email, password)
    assert code == 200, (email, code, d)
    return c


def main():
    print('== Авторизация и регистрация')
    c = Client()
    check('me анонимно', c.me_data is None)
    code, d = c.login('nobody@example.com', 'x')
    check('неверный логин 401', code == 401, d)
    code, d = c.call('POST', '/api/auth/register', {'email': 'bad', 'password': '1', 'last_name': '', 'first_name': ''})
    check('регистрация: валидация 422', code == 422, d)
    stamp = datetime.now().strftime('%H%M%S%f')
    em = f'test{stamp}@example.com'
    reg = {'email': em, 'password': 'Test12345', 'password_confirm': 'Test12345', 'last_name': 'Тестов', 'first_name': 'Тест',
           'phone': '+7 900 111-22-33', 'company': 'ООО «Тест»', 'consent': True}
    code, d = c.call('POST', '/api/auth/register', {**reg, 'consent': False})
    check('регистрация без согласия 422', code == 422, d)
    code, d = c.call('POST', '/api/auth/register', reg)
    check('регистрация 200 + dev_link', code == 200 and d.get('dev_link'), d)
    code, d2 = c.call('POST', '/api/auth/register', reg)
    check('повторная регистрация 409', code == 409, d2)
    code, d3 = c.login(em, 'Test12345')
    check('вход до подтверждения e-mail 403', code == 403, d3)
    token = d['dev_link'].split('token=')[1]
    code, d4 = c.call('POST', '/api/auth/verify', {'token': token})
    check('подтверждение e-mail', code == 200, d4)
    code, d4 = c.call('POST', '/api/auth/verify', {'token': token})
    check('токен одноразовый', code == 422, d4)
    code, d5 = c.login(em, 'Test12345')
    check('вход после подтверждения', code == 200 and d5['user']['roles'] == ['client'], d5)
    code, d6 = c.call('POST', '/api/auth/logout')
    c.csrf = d6['csrf']
    code, d7 = c.call('GET', '/api/appointments')
    check('после выхода 401', code == 401, d7)

    # блокировка после 5 неверных попыток
    lc = Client()
    for i in range(4):
        lc.login(em, 'wrong')
    code, d = lc.login(em, 'wrong')
    check('5-я неудачная попытка — блокировка', code == 401 and 'заблокирован' in d['error'], d)
    code, d = lc.login(em, 'Test12345')
    check('верный пароль при блокировке отклонён 423', code == 423, d)

    # восстановление пароля
    rc = Client()
    code, d = rc.call('POST', '/api/auth/forgot', {'email': 'kozlova@example.com'})
    check('forgot dev_link', code == 200 and d.get('dev_link'), d)
    tk = d['dev_link'].split('token=')[1]
    code, d = rc.call('POST', '/api/auth/reset', {'token': tk, 'password': 'short'})
    check('reset: слабый пароль 422', code == 422, d)
    code, d = rc.call('POST', '/api/auth/reset', {'token': tk, 'password': 'NewPass123'})
    check('reset пароля', code == 200, d)
    code, _ = rc.login('kozlova@example.com', PASS)
    check('старый пароль не работает', code == 401)
    code, _ = rc.login('kozlova@example.com', 'NewPass123')
    check('новый пароль работает', code == 200)
    rc.call('POST', '/api/profile/password', {'current_password': 'NewPass123', 'new_password': PASS})

    print('== Каталог и слоты')
    pub = Client()
    code, specs = pub.call('GET', '/api/specializations')
    check('специализации', code == 200 and len(specs) >= 4)
    code, svcs = pub.call('GET', '/api/services')
    check('услуги', code == 200 and len(svcs) >= 7)
    code, cons = pub.call('GET', '/api/consultants')
    check('консультанты с рейтингом', code == 200 and len(cons) == 3 and all('rating' in x for x in cons))
    volkov = next(x for x in cons if x['name'].startswith('Волков'))
    svc = next(s for s in svcs if s['name'] == 'Аудит учёта в 1С')
    frm = date.today().isoformat()
    to = (date.today() + timedelta(days=14)).isoformat()
    code, sl = pub.call('GET', f"/api/slots?consultant_id={volkov['id']}&service_id={svc['id']}&from={frm}&to={to}")
    check('слоты возвращаются', code == 200 and any(v['free'] for v in sl['days'].values()), sl if code != 200 else '')
    # слоты не раньше чем через 2 часа и по сетке 30 мин
    now = datetime.now()
    bad = []
    for day, v in sl['days'].items():
        for s in v['slots']:
            st = datetime.fromisoformat(f"{day}T{s['start']}")
            if s['available'] and st < now + timedelta(hours=2):
                bad.append((day, s['start']))
            if int(s['start'][3:]) % 30:
                bad.append(('grid', s['start']))
    check('слоты: ≥2 ч и сетка 30 мин', not bad, bad[:3])
    wk = [d for d in sl['days'] if datetime.fromisoformat(d).weekday() >= 5]
    check('в выходные слотов нет', all(sl['days'][d]['total'] == 0 for d in wk))

    print('== Запись, оплата, отмена, возврат')
    cl = as_user('kozlova@example.com')
    free = [(d, s) for d, v in sl['days'].items() for s in v['slots'] if s['available'] and d >= (date.today() + timedelta(days=3)).isoformat()]
    d0, s0 = free[0]
    start = f"{d0} {s0['start']}:00"
    code, ap = cl.call('POST', '/api/appointments', {'consultant_id': volkov['id'], 'service_id': svc['id'], 'start_at': start, 'comment': 'тест'})
    check('создание записи', code == 200 and ap['status'] == 'pending_payment' and ap['payment_deadline'], ap)
    aid = ap['id']
    code, ap2 = cl.call('POST', '/api/appointments', {'consultant_id': volkov['id'], 'service_id': svc['id'], 'start_at': start})
    check('двойное бронирование слота 409', code == 409, ap2)
    # соседний слот нарушает перерыв 10 мин
    nxt = f"{d0} {(datetime.fromisoformat(start) + timedelta(minutes=30)).strftime('%H:%M')}:00"
    code, ap3 = cl.call('POST', '/api/appointments', {'consultant_id': volkov['id'], 'service_id': svc['id'], 'start_at': nxt})
    check('слот внутри длительности/буфера 409', code == 409, ap3)
    code, sl2 = pub.call('GET', f"/api/slots?consultant_id={volkov['id']}&service_id={svc['id']}&from={d0}&to={d0}")
    row = next(s for s in sl2['days'][d0]['slots'] if s['start'] == s0['start'])
    check('удерживаемый слот занят в сетке', not row['available'])
    pid = ap['payment']['id']
    code, pd = cl.call('GET', f'/api/payments/{pid}')
    check('платёжная страница (sandbox)', code == 200 and pd['payment']['mock'] is True, pd)
    other = as_user('zaharov@example.com')
    code, _ = other.call('GET', f'/api/appointments/{aid}')
    check('чужая запись недоступна 403', code == 403)
    code, r = cl.call('POST', f'/api/payments/{pid}/mock-pay', {'result': 'success'})
    check('оплата проходит', code == 200 and r['status'] == 'succeeded', r)
    code, ap = cl.call('GET', f'/api/appointments/{aid}')
    check('запись подтверждена после оплаты', ap['status'] == 'confirmed' and ap['payment_deadline'] is None, ap.get('status'))
    code, r = cl.call('POST', f'/api/payments/{pid}/mock-pay', {'result': 'success'})
    check('повторная оплата отклонена 409', code == 409)
    # webhook идемпотентен и защищён
    code, ext = cl.call('GET', f'/api/payments/{pid}')
    code, r = pub.call('POST', '/api/payments/webhook', {'event': 'payment.succeeded', 'object': {'id': 'x', 'status': 'succeeded'}})
    check('webhook без подписи 403', code == 403, r)
    # перенос
    free2 = [(d, s) for d, v in sl['days'].items() for s in v['slots'] if s['available'] and d >= (date.today() + timedelta(days=4)).isoformat() and (d, s) != (d0, s0)]
    d1, s1 = free2[-1]
    code, r = cl.call('POST', f'/api/appointments/{aid}/reschedule', {'start_at': f"{d1} {s1['start']}:00"})
    check('перенос записи', code == 200 and r['start_at'].startswith(d1) and r['reschedule_count'] == 1, r)
    code, r = cl.call('POST', f'/api/appointments/{aid}/reschedule', {'start_at': start})
    check('повторный перенос клиентом запрещён 409', code == 409, r)
    # отмена за ≥24 ч — возврат 100 %
    code, r = cl.call('POST', f'/api/appointments/{aid}/cancel', {'reason': 'тест'})
    check('отмена ≥24ч: возврат 100%', code == 200 and r['refund_percent'] == 100 and float(r['refund_amount']) == float(svc['price']), r)
    code, r = cl.call('POST', f'/api/appointments/{aid}/cancel', {})
    check('повторная отмена 409', code == 409)
    code, full = cl.call('GET', f'/api/appointments/{aid}')
    types = [p['type'] for p in full['payments']]
    check('есть платёж и возврат', types == ['payment', 'refund'], types)

    print('== Уведомления клиента')
    code, n = cl.call('GET', '/api/notifications')
    check('уведомления есть', code == 200 and n['unread'] > 0 and n['items'])
    code, _ = cl.call('POST', '/api/notifications/read-all')
    code, n = cl.call('GET', '/api/notifications')
    check('прочитано', n['unread'] == 0)

    print('== Консультант: расписание, календарь, заключение')
    vk = as_user('volkov@tritech-service.ru')
    code, sc = vk.call('GET', '/api/consultants/me/schedule')
    check('расписание', code == 200 and len(sc['weekly']) >= 5, sc)
    code, cal = vk.call('GET', f"/api/consultants/me/calendar?from={frm}&to={to}")
    check('календарь', code == 200 and isinstance(cal['items'], list))
    code, lst = vk.call('GET', '/api/appointments?status=completed')
    check('консультант видит только свои', code == 200 and all('Волков' in a['consultant_name'] for a in lst['items']), lst if code != 200 else '')
    code, lst = vk.call('GET', '/api/appointments?limit=100')
    nowstr = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    past = [a for a in lst['items'] if a['start_at'] < nowstr and a['status'] in ('confirmed', 'completed')]
    check('есть прошедшие консультации у консультанта', bool(past))
    a = past[0]
    code, r = vk.call('POST', f"/api/appointments/{a['id']}/note", {'summary': 'Итог', 'recommendations': 'x', 'status': 'draft'})
    check('заключение: слишком короткое 422', code == 422, r)
    code, r = vk.call('POST', f"/api/appointments/{a['id']}/note", {'summary': 'Итог консультации: всё ок', 'recommendations': '1. Сделать то-то', 'status': 'draft'})
    check('заключение: черновик', code == 200 and r['status'] == 'draft', r)
    cl_a = as_user(a['client_email'])
    code, r = cl_a.call('GET', f"/api/appointments/{a['id']}")
    check('клиент не видит черновик заключения', code == 200 and not any(n['status'] == 'draft' for n in r['notes']), r.get('notes'))
    code, r = vk.call('POST', f"/api/appointments/{a['id']}/note", {'summary': 'Итог консультации: финальная версия', 'recommendations': '1. Сделать то-то', 'status': 'final'})
    check('заключение: финальная версия', code == 200 and r['status'] == 'final', r)
    code, r = cl_a.call('GET', f"/api/appointments/{a['id']}")
    check('клиент видит финальное заключение', any(n['status'] == 'final' for n in r['notes']))
    code, r = vk.call('GET', f"/api/appointments/{a['id']}")
    check('запись → проведена после финального заключения', r['status'] == 'completed', r['status'])
    code, r = vk.call('POST', f"/api/appointments/{a['id']}/note", {'summary': 'Исправленная версия заключения', 'status': 'final'})
    check('новая версия заключения', code == 200)
    code, r = vk.call('GET', f"/api/appointments/{a['id']}")
    check('версии заключений хранятся', len(r['notes']) >= 2, len(r['notes']))
    code, r = vk.call('POST', f"/api/consultants/me/exceptions", {'date': (date.today() - timedelta(days=1)).isoformat(), 'is_working': False})
    check('исключение в прошлом отклонено', code == 422, r)
    code, r = vk.call('POST', '/api/consultants/me/exceptions', {'date': (date.today() + timedelta(days=50)).isoformat(), 'is_working': False, 'reason': 'Выходной'})
    check('добавить выходной', code == 200 and any(e['reason'] == 'Выходной' for e in r['exceptions']), r)
    for e in r['exceptions']:
        if e['reason'] == 'Выходной':
            vk.call('DELETE', f"/api/consultants/me/exceptions/{e['id']}")
    code, r = vk.call('GET', '/api/tickets')
    check('консультант не видит заявки 403', code == 403)

    print('== Отзывы')
    mg = as_user('manager@tritech-service.ru')
    code, rv = mg.call('GET', '/api/reviews?status=pending')
    check('отзывы на модерации', code == 200 and rv['pending'] >= 1 and rv['items'], rv)
    code, r = mg.call('POST', f"/api/reviews/{rv['items'][0]['id']}/moderate", {'status': 'published'})
    check('модерация отзыва', code == 200 and r['status'] == 'published')
    code, r = cl.call('GET', '/api/reviews')
    check('клиент не видит модерацию 403', code == 403)

    print('== Техподдержка')
    code, meta = cl.call('GET', '/api/ticket-meta')
    check('справочники заявки', code == 200 and len(meta['categories']) == 6 and len(meta['priorities']) == 4)
    prio = {p['code']: p['id'] for p in meta['priorities']}
    code, t = cl.call('POST', '/api/tickets', {'subject': 'Тест', 'description': 'коротко', 'category_id': 1, 'priority_id': prio['medium']})
    check('заявка: валидация темы 422', code == 422, t)
    code, t = cl.call('POST', '/api/tickets', {'subject': 'Критический без права', 'description': 'Описание проблемы достаточной длины', 'category_id': 1, 'priority_id': prio['critical']})
    check('клиент не может выбрать «Критический»', code == 422, t)
    code, t = cl.call('POST', '/api/tickets', {'subject': 'Не работает печать накладной', 'description': 'При печати накладной появляется пустая страница', 'category_id': meta['categories'][1]['id'], 'priority_id': prio['high']})
    check('создание заявки', code == 200 and t['status'] == 'new' and t['assignee_id'] and t['auto_assigned'] == 1, t)
    tid = t['id']
    created = datetime.fromisoformat(t['created_at'])
    due = datetime.fromisoformat(t['due_at'])
    check('SLA высокого: решение через 24 ч календарных', abs((due - created).total_seconds() - 24 * 3600) < 5, (created, due))
    rd = datetime.fromisoformat(t['first_response_due'])
    check('SLA высокого: реакция 1 ч', abs((rd - created).total_seconds() - 3600) < 5)
    # автоназначение — специалист с минимальной нагрузкой
    code, mt = mg.call('GET', '/api/ticket-meta')
    sp = {s['id']: s for s in mt['specialists']}
    check('назначен специалист поддержки', t['assignee_id'] in sp)
    sup = as_user('belov@tritech-service.ru')
    code, tl = sup.call('GET', '/api/tickets?tab=all&limit=100')
    check('список заявок + счётчики', code == 200 and tl['counts']['all'] >= 16 and tl['items'][0]['sla']['state'] in ('breached', 'warning', 'ok'), tl.get('error'))
    code, br = sup.call('GET', '/api/tickets?tab=breached')
    check('вкладка «Нарушен SLA»', code == 200 and all(x['sla']['state'] == 'breached' for x in br['items']) and br['total'] >= 1)
    code, det = cl.call('GET', f'/api/tickets/{tid}')
    check('карточка заявки клиента', code == 200 and det['staff_view'] is False and det['can_manage'] is False)
    code, r = cl.call('POST', f'/api/tickets/{tid}/comments', {'text': 'Дополнение от клиента'})
    check('комментарий клиента', code == 200)
    code, r = cl.call('POST', f'/api/tickets/{tid}/comments', {'text': 'Скрытая заметка', 'is_internal': True})
    check('клиент не создаёт внутренние заметки 403', code == 403)
    code, r = cl.call('POST', f'/api/tickets/{tid}/status', {'status': 'resolved'})
    check('клиент не может решить заявку 403', code == 403, r)
    # специалист работает
    assignee_email = {v['name']: k for k, v in {}.items()}
    owner = t['assignee_id']
    emails = {'belov': 'belov@tritech-service.ru', 'orlova': 'orlova@tritech-service.ru', 'kim': 'kim@tritech-service.ru'}
    worker = None
    for e in emails.values():
        w = as_user(e)
        if w.me_data['id'] == owner:
            worker = w
    check('исполнитель найден', worker is not None)
    code, r = worker.call('POST', f'/api/tickets/{tid}/comments', {'text': 'Приняли в работу, смотрим.'})
    check('публичный ответ специалиста', code == 200)
    code, r = worker.call('POST', f'/api/tickets/{tid}/comments', {'text': 'Внутренне: воспроизводится', 'is_internal': True})
    check('внутренняя заметка', code == 200)
    code, det = worker.call('GET', f'/api/tickets/{tid}')
    check('первая реакция зафиксирована, статус «Принята в работу»', det['first_response_at'] and det['status'] == 'accepted', (det['status'], det['first_response_at']))
    check('специалист видит внутреннюю заметку', any(c['is_internal'] for c in det['comments']))
    code, det = cl.call('GET', f'/api/tickets/{tid}')
    check('клиент не видит внутреннюю заметку', not any(c['is_internal'] for c in det['comments']))
    code, r = worker.call('POST', f'/api/tickets/{tid}/status', {'status': 'closed'})
    check('недопустимый переход 409', code == 409, r)
    code, r = worker.call('POST', f'/api/tickets/{tid}/status', {'status': 'waiting_client'})
    check('→ ожидание клиента', code == 200 and r['status'] == 'waiting_client')
    code, r = cl.call('POST', f'/api/tickets/{tid}/comments', {'text': 'Вот скриншот'})
    code, det = cl.call('GET', f'/api/tickets/{tid}')
    check('ответ клиента возвращает в работу', det['status'] == 'in_progress', det['status'])
    # эскалация
    code, tk = worker.call('POST', f'/api/tickets/{tid}/escalate', {'note': 'Нужна правка кода'})
    check('эскалация создаёт задачу-ошибку', code == 200 and tk['type'] == 'bug' and tk['ticket_id'] == tid, tk)
    code, r = worker.call('POST', f'/api/tickets/{tid}/escalate', {})
    check('повторная эскалация 409', code == 409, r)
    code, det = worker.call('GET', f'/api/tickets/{tid}')
    check('в заявке видна связанная задача', det['task'] and det['task']['id'] == tk['id'])
    # разработчик выполняет задачу → уведомление специалисту
    for e in ('petrov', 'sokolova', 'kuznetsov', 'sergeev', 'kovaleva'):
        dv = as_user(f'{e}@tritech-service.ru')
        if dv.me_data['id'] == tk['assignee_id']:
            break
    code, r = dv.call('POST', f"/api/tasks/{tk['id']}/move", {'status': 'done'})
    check('разработчик закрывает задачу', code == 200 and r['status'] == 'done' and r['completed_at'], r)
    code, nn = worker.call('GET', '/api/notifications')
    check('специалист уведомлён о выполнении', any(n['type'] == 'task_done' for n in nn['items']))
    code, r = worker.call('POST', f'/api/tickets/{tid}/status', {'status': 'resolved'})
    check('→ решена', code == 200 and r['status'] == 'resolved' and r['resolved_at'])
    code, r = cl.call('POST', f'/api/tickets/{tid}/status', {'status': 'closed'})
    check('клиент закрывает решённую заявку', code == 200 and r['status'] == 'closed', r)
    code, tpl = worker.call('GET', '/api/templates')
    check('шаблоны ответов', code == 200 and len(tpl) >= 6)
    code, r = worker.call('POST', '/api/templates', {'title': 'Тест шаблон', 'body': 'Текст тестового шаблона'})
    check('создание шаблона', code == 200 and r['id'])
    worker.call('DELETE', f"/api/templates/{r['id']}")
    code, r = cl.call('GET', '/api/templates')
    check('клиент не видит шаблоны 403', code == 403)
    code, r = worker.call('POST', f'/api/tickets/{tid}/priority', {'priority_id': prio['low']})
    check('смена приоритета пересчитывает SLA', code == 200 and r['priority_code'] == 'low' and r['due_at'] != t['due_at'], r)
    code, cs = worker.call('GET', '/api/clients?q=Козлова')
    check('список клиентов для ТП', code == 200 and cs['total'] >= 1)

    print('== Разработка')
    dir_ = as_user('director@tritech-service.ru')
    code, prs = dir_.call('GET', '/api/projects')
    check('проекты', code == 200 and len(prs) >= 4 and all('progress' in p for p in prs))
    p1 = next(p for p in prs if p['name'] == 'Портал дилеров')
    pr = p1['progress']
    exp = round(pr['est_done'] * 100 / pr['est_total'])
    check('прогресс = Σ оценок выполненных / Σ оценок всех', pr['percent'] == exp, pr)
    code, b = dir_.call('GET', f"/api/projects/{p1['id']}/board")
    check('доска: 4 колонки', code == 200 and [c['status'] for c in b['columns']] == ['backlog', 'in_progress', 'testing', 'done'])
    pt = as_user('petrov@tritech-service.ru')
    code, mine = pt.call('GET', '/api/tasks?assignee_id=me')
    check('мои задачи', code == 200 and mine['total'] >= 3 and all(t['assignee_id'] == pt.me_data['id'] for t in mine['items']))
    code, r = pt.call('POST', '/api/tasks', {'project_id': p1['id'], 'title': 'Тестовая задача', 'type': 'feature', 'estimate_hours': 4, 'assignee_id': pt.me_data['id'], 'due_date': (date.today() + timedelta(days=5)).isoformat()})
    check('создание задачи', code == 200 and r['status'] == 'backlog', r)
    tkid = r['id']
    code, r = pt.call('POST', f'/api/tasks/{tkid}/move', {'status': 'in_progress'})
    check('перемещение карточки', code == 200 and r['status'] == 'in_progress')
    code, r = pt.call('POST', f'/api/tasks/{tkid}/timelogs', {'work_date': date.today().isoformat(), 'hours': 0})
    check('часы 0 отклонены', code == 422)
    code, r = pt.call('POST', f'/api/tasks/{tkid}/timelogs', {'work_date': date.today().isoformat(), 'hours': 25})
    check('часы >24 отклонены', code == 422)
    code, r = pt.call('POST', f'/api/tasks/{tkid}/timelogs', {'work_date': (date.today() + timedelta(days=1)).isoformat(), 'hours': 2})
    check('часы в будущем отклонены', code == 422)
    code, r = pt.call('POST', f'/api/tasks/{tkid}/timelogs', {'work_date': date.today().isoformat(), 'hours': 1.5, 'comment': 'тест'})
    check('списание времени', code == 200)
    code, r = pt.call('GET', f'/api/tasks/{tkid}')
    check('карточка задачи: часы и история', float(r['logged_hours']) == 1.5 and len(r['history']) >= 2, r)
    code, r2 = pt.call('POST', f'/api/tasks/{tkid}/comments', {'text': 'Комментарий'})
    check('комментарий к задаче', code == 200)
    ex = as_user('kozlova@example.com')
    code, r = ex.call('POST', f'/api/tasks/{tkid}/move', {'status': 'done'})
    check('клиент не двигает задачи 403', code == 403)
    code, mine_c = ex.call('GET', '/api/projects')
    check('клиент видит только свои проекты', code == 200 and all('Козлова' in p['client_name'] for p in mine_c))
    kz = as_user('kuznetsov@tritech-service.ru')
    code, r = kz.call('GET', '/api/projects/' + str(next(p for p in prs if p['name'].startswith('Внедрение'))['id']))
    check('разработчик вне проекта не читает его 403', code == 403)
    code, r = kz.call('GET', '/api/timelogs')
    check('трудозатраты разработчика', code == 200 and 'total_hours' in r)
    code, r = dir_.call('POST', '/api/projects', {'name': 'Новый проект теста', 'client_id': ex.me_data['id'], 'start_date': date.today().isoformat()})
    check('создание проекта директором', code == 200 and r['status'] == 'planned', r)
    code, r2 = dir_.call('POST', f"/api/projects/{r['id']}/members", {'user_id': pt.me_data['id'], 'role_in_project': 'developer', 'allocation_percent': 50})
    check('добавление участника', code == 200 and len(r2) >= 1, r2)
    code, r3 = dir_.call('PUT', f"/api/projects/{r['id']}", {'status': 'in_progress'})
    check('смена статуса проекта', code == 200 and r3['status'] == 'in_progress')

    print('== Дашборд и отчёты')
    code, ds = dir_.call('GET', '/api/dashboard?period=month')
    check('дашборд', code == 200 and set(ds['kpi']) == {'consultations_today', 'tasks_in_work', 'open_tickets', 'sla_breaches'} and len(ds['load']) == 3, ds if code != 200 else '')
    check('дашборд: риски отсортированы', [x['remaining_min'] for x in ds['risks']] == sorted(x['remaining_min'] for x in ds['risks']))
    for typ in ('revenue', 'consultations', 'sla', 'support_load', 'dev_load', 'timelogs', 'projects'):
        code, rp = dir_.call('GET', f'/api/reports/{typ}?from={(date.today() - timedelta(days=90)).isoformat()}&to={date.today().isoformat()}')
        check(f'отчёт {typ}', code == 200 and rp['columns'] and isinstance(rp['rows'], list), rp if code != 200 else '')
    code, raw = dir_.call('GET', f'/api/reports/sla?format=xlsx', raw=True)
    check('XLSX — корректный zip', code == 200 and raw[:2] == b'PK' and b'xl/worksheet' in raw)
    code, raw = dir_.call('GET', f'/api/reports/revenue?format=csv', raw=True)
    check('CSV с BOM', code == 200 and raw[:3] == b'\xef\xbb\xbf')
    code, _ = pt.call('GET', '/api/dashboard')
    check('разработчик без доступа к дашборду 403', code == 403)

    print('== Администрирование')
    ad = as_user('admin@tritech-service.ru', ADMIN_PW)
    code, us = ad.call('GET', '/api/admin/users?limit=100')
    check('пользователи', code == 200 and us['total'] >= 18)
    code, r = ad.call('POST', '/api/admin/users', {'email': f'new{stamp}@tritech-service.ru', 'last_name': 'Новый', 'first_name': 'Сотрудник', 'roles': ['support', 'developer']})
    check('создание сотрудника с двумя ролями', code == 200 and set(r['roles']) == {'support', 'developer'} and r['temp_password'], r)
    uid = r['id']
    code, r = ad.call('PUT', f'/api/admin/users/{uid}', {'email': f'new{stamp}@tritech-service.ru', 'last_name': 'Новый', 'first_name': 'Сотрудник', 'roles': ['support'], 'is_active': False})
    check('блокировка пользователя', code == 200 and r['is_active'] == 0)
    code, r = ad.call('PUT', f"/api/admin/users/{ad.me_data['id']}", {'email': 'admin@tritech-service.ru', 'last_name': 'А', 'first_name': 'Б', 'roles': ['support']})
    check('нельзя снять с себя роль admin 409', code == 409, r)
    code, r = ad.call('GET', '/api/admin/directories/services')
    check('справочник услуг', code == 200 and len(r) >= 7)
    code, r = ad.call('POST', '/api/admin/directories/specializations', {'name': f'Тест {stamp}', 'description': 'x'})
    check('новая специализация', code == 200)
    code, r = ad.call('POST', '/api/admin/directories/services', {'specialization_id': 1, 'name': 'X', 'duration_min': 5, 'price': 100})
    check('услуга: длительность <15 отклонена', code == 422)
    code, st = ad.call('GET', '/api/admin/settings')
    check('настройки', code == 200 and any(s['name'] == 'payment_timeout_min' for s in st))
    code, r = ad.call('PUT', '/api/admin/settings', {'settings': {'partial_refund_percent': '150'}})
    check('настройка: процент > 100 отклонён', code == 422)
    code, lg = ad.call('GET', '/api/admin/log?limit=5')
    check('журнал действий', code == 200 and lg['total'] > 0)
    code, bk = ad.call('POST', '/api/admin/backups')
    check('резервная копия создаётся', code == 200 and bk['name'].endswith('.sql'))
    code, raw = ad.call('GET', f"/api/admin/backups/{bk['name']}", raw=True)
    check('копия скачивается', code == 200 and b'CREATE TABLE' in raw)
    code, r = ad.call('POST', '/api/admin/cron/check_sla')
    check('cron: check_sla', code == 200 and 'warnings' in r, r)
    code, r = ad.call('POST', '/api/admin/cron/cancel_unpaid')
    check('cron: cancel_unpaid', code == 200 and 'cancelled' in r)
    code, r = ad.call('POST', '/api/admin/cron/send_reminders')
    check('cron: send_reminders', code == 200)
    code, r = mg.call('GET', '/api/admin/users')
    check('менеджер не видит админку 403', code == 403)
    # CSRF
    csrf_c = as_user('kozlova@example.com')
    csrf_c.csrf = 'bad'
    code, r = csrf_c.call('POST', '/api/notifications/read-all')
    check('CSRF-токен обязателен 419', code == 419, r)


    print('== Платежи, webhook, сроки удержания, напоминания, возвраты')
    pub = Client()
    cl2 = as_user('severov@example.com')
    cons_ids = {c['name'].split()[0]: c['id'] for c in pub.call('GET', '/api/consultants')[1]}
    svc_by = {s['name']: s['id'] for s in pub.call('GET', '/api/services')[1]}

    def free_slot(cons, svc_id, skip=0, min_days=12):
        t = (date.today() + timedelta(days=min_days)).isoformat()
        e = (date.today() + timedelta(days=min_days + 20)).isoformat()
        c_, sl_ = pub.call('GET', f"/api/slots?consultant_id={cons}&service_id={svc_id}&from={t}&to={e}")
        lst = [(d, s['start']) for d, v in sl_['days'].items() for s in v['slots'] if s['available']]
        return lst[skip]

    def book(client, cons, svc_id, skip=0):
        d_, t_ = free_slot(cons, svc_id, skip)
        code_, a_ = client.call('POST', '/api/appointments', {'consultant_id': cons, 'service_id': svc_id, 'start_at': f'{d_} {t_}:00'})
        assert code_ == 200, a_
        return a_

    wh = {'X-Webhook-Secret': 'dev-webhook-secret'}
    a = book(cl2, cons_ids['Волков'], svc_by['Экспресс-консультация по 1С'])
    ext = sql(f"SELECT external_id FROM payments WHERE id = {a['payment']['id']}")
    code, r = pub.call('POST', '/api/payments/webhook', {'event': 'payment.succeeded', 'object': {'id': ext, 'status': 'succeeded'}}, headers=wh)
    check('webhook payment.succeeded принят', code == 200, r)
    code, ap = cl2.call('GET', f"/api/appointments/{a['id']}")
    check('webhook подтверждает запись', ap['status'] == 'confirmed')
    pub.call('POST', '/api/payments/webhook', {'event': 'payment.succeeded', 'object': {'id': ext, 'status': 'succeeded'}}, headers=wh)
    check('повторный webhook идемпотентен', sql(f"SELECT COUNT(*) FROM payments WHERE appointment_id = {a['id']}") == '1' and sql(f"SELECT COUNT(*) FROM status_history WHERE appointment_id = {a['id']} AND old_status_id IS NOT NULL") == '1')

    # напоминание за 24 ч
    sql(f"UPDATE appointments SET created_at = DATE_SUB(NOW(), INTERVAL 3 DAY), start_at = DATE_ADD(NOW(), INTERVAL 23 HOUR), end_at = DATE_ADD(NOW(), INTERVAL 1410 MINUTE) WHERE id = {a['id']}")
    ad = as_user('admin@tritech-service.ru', ADMIN_PW)
    before = int(sql(f"SELECT COUNT(*) FROM notifications WHERE user_id = {cl2.me_data['id']} AND type = 'reminder'"))
    code, r = ad.call('POST', '/api/admin/cron/send_reminders')
    after = int(sql(f"SELECT COUNT(*) FROM notifications WHERE user_id = {cl2.me_data['id']} AND type = 'reminder'"))
    check('cron: напоминание клиенту отправлено', after == before + 1, (before, after, r))
    ad.call('POST', '/api/admin/cron/send_reminders')
    check('cron: повторного напоминания нет', int(sql(f"SELECT COUNT(*) FROM notifications WHERE user_id = {cl2.me_data['id']} AND type = 'reminder'")) == after)

    # истечение срока оплаты → слот освобождается; поздняя оплата → автовозврат
    b = book(cl2, cons_ids['Орехова'], svc_by['Проектирование автоматизации'], skip=3)
    bd, bt = b['start_at'][:10], b['start_at'][11:16]
    sql(f"UPDATE appointments SET payment_deadline = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = {b['id']}")
    code, r = ad.call('POST', '/api/admin/cron/cancel_unpaid')
    check('cron: неоплаченная запись отменена', r.get('cancelled', 0) >= 1, r)
    code, bb = cl2.call('GET', f"/api/appointments/{b['id']}")
    check('отменена системой, причина «не оплачено в срок»', bb['status'] == 'cancelled' and bb['cancelled_by'] == 'system')
    code, sl3 = pub.call('GET', f"/api/slots?consultant_id={cons_ids['Орехова']}&service_id={svc_by['Проектирование автоматизации']}&from={bd}&to={bd}")
    check('слот снова свободен', next(s for s in sl3['days'][bd]['slots'] if s['start'] == bt)['available'])
    ext_b = sql(f"SELECT external_id FROM payments WHERE appointment_id = {b['id']} AND type = 'payment'")
    pub.call('POST', '/api/payments/webhook', {'event': 'payment.succeeded', 'object': {'id': ext_b, 'status': 'succeeded'}}, headers=wh)
    kinds = sql(f"SELECT GROUP_CONCAT(type ORDER BY id) FROM payments WHERE appointment_id = {b['id']}")
    check('поздняя оплата → автоматический возврат', kinds == 'payment,refund', kinds)

    # правила возврата: 50 % (2–24 ч), 0 % (<2 ч), 100 % при отмене консультантом
    def confirmed_at(client, cons_key, svc_name, skip, hours):
        x = book(client, cons_ids[cons_key], svc_by[svc_name], skip)
        client.call('POST', f"/api/payments/{x['payment']['id']}/mock-pay", {'result': 'success'})
        dur = int(sql(f"SELECT duration_min FROM services WHERE id = {svc_by[svc_name]}"))
        sql(f"UPDATE appointments SET start_at = DATE_ADD(NOW(), INTERVAL {int(hours * 60)} MINUTE), end_at = DATE_ADD(NOW(), INTERVAL {int(hours * 60) + dur} MINUTE) WHERE id = {x['id']}")
        return x
    c50 = confirmed_at(cl2, 'Громов', 'Аудит ИТ-безопасности', 5, 10)
    code, r = cl2.call('POST', f"/api/appointments/{c50['id']}/cancel", {})
    check('отмена за 10 ч → возврат 50 %', code == 200 and r['refund_percent'] == 50 and float(r['refund_amount']) == 2250.0, r)
    c0 = confirmed_at(cl2, 'Громов', 'Консультация по инфраструктуре', 6, 1)
    code, r = cl2.call('POST', f"/api/appointments/{c0['id']}/cancel", {})
    check('отмена за 1 ч → возврата нет', code == 200 and r['refund_percent'] == 0 and sql(f"SELECT COUNT(*) FROM payments WHERE appointment_id = {c0['id']} AND type = 'refund'") == '0', r)
    cc = confirmed_at(cl2, 'Орехова', 'Проектирование автоматизации', 7, 1.5)
    orx = as_user('orehova@tritech-service.ru')
    code, r = orx.call('POST', f"/api/appointments/{cc['id']}/cancel", {'reason': 'Болезнь'})
    check('отмена консультантом → возврат 100 %', code == 200 and r['refund_percent'] == 100 and float(r['refund_amount']) == 6000.0, r)
    code, n = cl2.call('GET', '/api/notifications')
    check('клиент уведомлён об отмене консультантом', any('отменена' in x['title'] for x in n['items']))
    code, r = cl2.call('POST', f"/api/appointments/{a['id']}/reschedule", {'start_at': a['start_at']})
    check('перенос <24 ч клиентом запрещён', code == 409, r)

    # автоназначение по минимальной нагрузке L
    mgr = as_user('manager@tritech-service.ru')
    code, load = mgr.call('GET', '/api/reports/support_load')
    loads = {row['specialist']: int(row['load_l']) for row in load['rows']}
    cl3 = as_user('markova@example.com')
    code, meta3 = cl3.call('GET', '/api/ticket-meta')
    code, t = cl3.call('POST', '/api/tickets', {'subject': 'Проверка автоназначения', 'description': 'Тестовая заявка для проверки автоназначения', 'category_id': 1, 'priority_id': meta3['priorities'][1]['id']})
    chosen = sql(f"SELECT CONCAT(last_name, ' ', first_name) FROM users WHERE id = {t['assignee_id']}")
    check('автоназначение: выбран специалист с минимальной нагрузкой', loads[chosen] == min(loads.values()), (chosen, loads))
    check('assigned_load фиксируется', int(t['assigned_load']) == loads[chosen])

    print(f'\nПройдено: {passed}, провалено: {len(fails)}')
    for f in fails:
        print(' -', f)
    sys.exit(1 if fails else 0)


if __name__ == '__main__':
    main()
