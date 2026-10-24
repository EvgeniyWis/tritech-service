// Консультации: список клиента/консультанта, журнал менеджера, карточка записи, оплата
import * as api from '../api.js';
import { state, has, go } from '../app.js';
import {
  h, mount, icon, spinner, appBadge, badge, fmtDateTime, fmtDate, fmtTime, fmtMoney, fmtDur, fmtLong, dataTable, pager, tabs, field, select, kv, toast, toastErr,
  modal, confirmDialog, guard, stars, avatar, debounce, todayIso, iso, addDays, emptyState, APP_STATUS, formData, showErrors, parseDate,
} from '../ui.js';

// ------------------------------------------------------------------ списки
export async function myAppointments(ctx) {
  const isCons = ctx.path === '/consultations';
  ctx.header(isCons ? 'Консультации' : 'Мои консультации', isCons ? 'Записи к вам' : 'Предстоящие и прошедшие консультации');
  let tab = ctx.query.tab || 'upcoming';
  let page = 1;
  const host = h('div', { class: 'col gap-16' });
  mount(ctx.root, host);

  async function draw() {
    const params = { page, limit: 15, scope: isCons ? undefined : undefined };
    if (tab === 'upcoming') { params.upcoming = 1; params.sort = 'asc'; }
    if (tab === 'completed') params.status = 'completed';
    if (tab === 'cancelled') params.status = 'cancelled';
    if (tab === 'pending') params.status = 'pending_payment';
    const d = await api.get('/api/appointments', params);
    const list = d.items;
    mount(host,
      h('div', { class: 'row between wrap' },
        tabs([{ key: 'upcoming', label: 'Предстоящие' }, { key: 'pending', label: 'Ожидают оплаты' }, { key: 'completed', label: 'Проведённые' }, { key: 'cancelled', label: 'Отменённые' }, { key: 'all', label: 'Все' }], tab, (k) => { tab = k; page = 1; draw(); }),
        has('client') && !isCons ? h('a', { class: 'btn accent', href: '#/book' }, icon('plus'), 'Записаться') : null),
      h('div', { class: 'card' }, dataTable([
        { label: '№', render: (a) => h('span', { class: 'num' }, a.number), cls: 'nowrap' },
        { label: 'Дата и время', render: (a) => [fmtDateTime(a.start_at), h('div', { class: 'sub' }, fmtDur(a.duration_min))], cls: 'nowrap' },
        { label: 'Услуга', render: (a) => a.service_name },
        { label: isCons ? 'Клиент' : 'Консультант', render: (a) => isCons ? [a.client_name, h('div', { class: 'sub' }, a.client_company || '')] : [a.consultant_name, h('div', { class: 'sub' }, a.consultant_position)] },
        { label: 'Сумма', render: (a) => fmtMoney(a.price), cls: 'nowrap' },
        { label: 'Статус', render: (a) => [appBadge(a.status), a.status === 'pending_payment' ? h('div', { class: 'sub' }, 'оплатить до ' + fmtTime(a.payment_deadline)) : null] },
        { label: '', render: (a) => a.can_pay ? h('a', { class: 'btn sm accent', href: '#/pay/' + a.payment.id }, 'Оплатить') : null },
      ], list, { onRow: (a) => go('/appointment/' + a.id), empty: 'Записей нет' })),
      pager({ page: d.page, limit: d.limit, total: d.total, onPage: (p) => { page = p; draw(); } }));
  }
  mount(host, spinner());
  await draw();
}

export async function journal(ctx) {
  ctx.header('Консалтинг', 'Журнал записей на консультации');
  const f = { status: ctx.query.status || '', consultant_id: '', from: '', to: '', q: '' };
  let page = 1;
  const consultants = await api.get('/api/consultants');
  const filters = h('div', { class: 'filters' },
    select([{ value: '', label: 'Статус: все' }, ...Object.entries(APP_STATUS).map(([v, [l]]) => ({ value: v, label: l }))], f.status, { onChange: (e) => { f.status = e.target.value; page = 1; draw(); } }),
    select([{ value: '', label: 'Консультант: все' }, ...consultants.map((c) => ({ value: c.id, label: c.name }))], '', { onChange: (e) => { f.consultant_id = e.target.value; page = 1; draw(); } }),
    h('input', { type: 'date', title: 'С даты', onChange: (e) => { f.from = e.target.value; page = 1; draw(); } }),
    h('input', { type: 'date', title: 'По дату', onChange: (e) => { f.to = e.target.value; page = 1; draw(); } }),
    h('input', { type: 'search', placeholder: 'Клиент или номер', onInput: debounce((e) => { f.q = e.target.value; page = 1; draw(); }) }));
  const out = h('div', { class: 'col gap-16' });
  mount(ctx.root, h('div', { class: 'row between wrap' }, filters, h('a', { class: 'btn accent', href: '#/book' }, icon('plus'), 'Записать клиента')), out);
  async function draw() {
    const d = await api.get('/api/appointments', { ...f, page, limit: 20 });
    mount(out, h('div', { class: 'card' }, dataTable([
      { label: '№', render: (a) => h('span', { class: 'num' }, a.number) },
      { label: 'Дата и время', render: (a) => fmtDateTime(a.start_at), cls: 'nowrap' },
      { label: 'Клиент', render: (a) => [a.client_name, h('div', { class: 'sub' }, a.client_company || '')] },
      { label: 'Консультант', render: (a) => a.consultant_name },
      { label: 'Услуга', render: (a) => a.service_name },
      { label: 'Сумма', render: (a) => fmtMoney(a.price), cls: 'nowrap' },
      { label: 'Статус', render: (a) => appBadge(a.status) },
    ], d.items, { onRow: (a) => go('/appointment/' + a.id), empty: 'Записей по фильтру нет' })), pager({ page: d.page, limit: d.limit, total: d.total, onPage: (p) => { page = p; draw(); } }));
  }
  await draw();
}

// ------------------------------------------------------------------ карточка записи
export async function detail(ctx) {
  const id = ctx.params.id;
  mount(ctx.root, spinner());
  let a;
  async function load() {
    a = await api.get('/api/appointments/' + id);
    ctx.header('Запись № ' + a.number, `${a.service_name} · ${fmtDateTime(a.start_at)}`);
    draw();
  }
  const me = state.user;
  function draw() {
    const isClientSide = a.client_id === me.id;
    const isConsSide = me.consultant_id && a.consultant_id === me.consultant_id;
    const canManage = has('manager', 'admin');
    const left = h('div', { class: 'col gap-16' },
      h('div', { class: 'card pad col gap-16' },
        h('div', { class: 'row between wrap' },
          h('div', { class: 'row gap-8' }, h('span', { class: 'num bold', style: { color: 'var(--primary)' } }, '№ ' + a.number), appBadge(a.status)),
          a.status === 'pending_payment' || a.status === 'confirmed' ? h('a', { class: 'btn secondary sm', href: a.meeting_url, target: '_blank', rel: 'noopener' }, icon('video'), 'Видеовстреча (Jitsi)') : null),
        h('h2', null, a.service_name),
        h('div', { class: 'grid c2' }, kv('Дата', fmtLong(a.start_at)), kv('Время', `${fmtTime(a.start_at)} – ${fmtTime(a.end_at)} (МСК)`),
          kv('Консультант', a.consultant_name), kv('Клиент', [a.client_name, a.client_company ? ` · ${a.client_company}` : '']),
          kv('Стоимость', fmtMoney(a.price)), kv('Длительность', fmtDur(a.duration_min))),
        a.status === 'pending_payment' ? h('div', { class: 'alert warn' }, `Слот удерживается до ${fmtTime(a.payment_deadline)}. Если оплата не поступит — запись будет отменена автоматически.`) : null,
        a.status === 'cancelled' ? h('div', { class: 'alert' }, `Отменена ${a.cancelled_by === 'system' ? 'автоматически' : a.cancelled_by === 'client' ? 'клиентом' : a.cancelled_by === 'consultant' ? 'консультантом' : 'менеджером'}${a.cancel_reason ? ': ' + a.cancel_reason : ''}`) : null,
        a.client_comment ? h('div', null, h('div', { class: 'lbl mb8' }, 'Тема вопроса'), h('div', { class: 'card pad', style: { background: 'var(--bg)' } }, a.client_comment)) : null),
      notesCard(isConsSide),
      reviewCard(isClientSide));

    const actions = [];
    if (a.can_pay) actions.push(h('a', { class: 'btn accent block', href: '#/pay/' + a.payment.id }, 'Оплатить ' + fmtMoney(a.price)));
    if (a.status === 'pending_payment' && !a.can_pay && (isClientSide || canManage)) actions.push(h('button', { class: 'btn accent block', onClick: async () => { const p = await guard(() => api.post(`/api/appointments/${a.id}/pay`)); if (p) go('/pay/' + p.id); } }, 'Оплатить заново'));
    if (a.actions.reschedule) actions.push(h('button', { class: 'btn secondary block', onClick: reschedule }, 'Перенести'));
    if (a.actions.cancel) actions.push(h('button', { class: 'btn danger block', onClick: cancel }, 'Отменить запись'));
    if (a.actions.mark) actions.push(h('div', { class: 'row' }, h('button', { class: 'btn block', onClick: () => mark('completed') }, 'Проведена'), h('button', { class: 'btn secondary block', onClick: () => mark('no_show') }, 'Неявка')));
    if (a.actions.create_project) actions.push(h('button', { class: 'btn secondary block', onClick: createProject }, icon('dev'), 'Создать проект по итогам'));
    if (a.project) actions.push(h('a', { class: 'btn secondary block', href: '#/project/' + a.project.id }, 'Проект: ' + a.project.name));

    const right = h('div', { class: 'col gap-16' },
      actions.length ? h('div', { class: 'card pad col gap-8' }, h('h3', null, 'Действия'), actions) : null,
      h('div', { class: 'card pad' }, h('h3', { class: 'mb8' }, 'Платежи'),
        a.payments.length ? a.payments.map((p) => h('div', { class: 'kv' }, h('span', null, (p.type === 'refund' ? 'Возврат · ' : 'Оплата · ') + fmtDateTime(p.paid_at || p.created_at)),
          h('span', null, (p.type === 'refund' ? '−' : '') + fmtMoney(p.amount), ' ', badge({ succeeded: 'проведён', pending: 'ожидание', canceled: 'отменён' }[p.status], { succeeded: 'success', pending: 'warning', canceled: '' }[p.status], true)))) : h('div', { class: 'muted small' }, 'Платежей пока нет')),
      h('div', { class: 'card pad' }, h('h3', { class: 'mb8' }, 'История'),
        h('div', { class: 'timeline' }, [...a.history].reverse().map((x) => h('div', { class: 'it' }, h('div', null, x.comment || x.status_name), h('div', { class: 'm' }, fmtDateTime(x.changed_at) + ' · ' + (x.user_name || 'система')))))));
    mount(ctx.root, h('div', { class: 'split' }, left, right));
  }

  function notesCard(isConsSide) {
    const notes = a.notes || [];
    const last = notes[0];
    if (a.actions.note) {
      const sum = h('textarea', { rows: 6, placeholder: 'Итоги консультации: что обсуждалось и к каким выводам пришли' }, last ? last.summary : '');
      const rec = h('textarea', { rows: 4, placeholder: 'Рекомендации клиенту (по пунктам)' }, last && last.recommendations || '');
      const saveNote = async (status) => {
        const r = await guard(() => api.post(`/api/appointments/${a.id}/note`, { summary: sum.value, recommendations: rec.value, status }), status === 'final' ? 'Заключение опубликовано для клиента' : 'Черновик сохранён');
        if (r) load();
      };
      return h('div', { class: 'card pad col gap-16' },
        h('div', { class: 'row between' }, h('h2', null, 'Заключение консультанта'), last ? badge(last.status === 'final' ? `версия ${last.version} · опубликовано` : `версия ${last.version} · черновик`, last.status === 'final' ? 'success' : 'warning') : null),
        field('Итоги', sum), field('Рекомендации', rec),
        h('div', { class: 'row' }, h('button', { class: 'btn secondary', onClick: () => saveNote('draft') }, 'Сохранить черновик'), h('button', { class: 'btn', onClick: () => saveNote('final') }, last && last.status === 'final' ? 'Опубликовать новую версию' : 'Опубликовать заключение')),
        notes.length > 1 ? h('details', null, h('summary', { class: 'muted', style: { cursor: 'pointer' } }, 'Предыдущие версии'), notes.slice(1).map((n) => h('div', { class: 'card pad mt8' }, h('div', { class: 'small muted' }, `Версия ${n.version} · ${fmtDateTime(n.created_at)}`), h('div', null, n.summary)))) : null);
    }
    if (!notes.length) return null;
    return h('div', { class: 'card pad col gap-8' }, h('div', { class: 'row between' }, h('h2', null, 'Заключение консультанта'), h('button', { class: 'btn secondary sm', onClick: () => downloadNote(notes[0]) }, icon('download'), 'Скачать')), h('div', { class: 'small muted' }, `Версия ${notes[0].version} · ${fmtDateTime(notes[0].created_at)}`),
      h('div', { style: { whiteSpace: 'pre-wrap' } }, notes[0].summary), notes[0].recommendations ? [h('div', { class: 'lbl mt8' }, 'Рекомендации'), h('div', { style: { whiteSpace: 'pre-wrap' } }, notes[0].recommendations)] : null);
  }

  function downloadNote(n) {
    const text = `Заключение по консультации № ${a.number}
${a.service_name}, ${fmtDateTime(a.start_at)}
Консультант: ${a.consultant_name}

Итоги:
${n.summary}

Рекомендации:
${n.recommendations || '—'}
`;
    const url = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }));
    const el = h('a', { href: url, download: `zaklyuchenie-${a.number}.txt` });
    document.body.append(el); el.click(); el.remove(); URL.revokeObjectURL(url);
  }

  function reviewCard(isClientSide) {
    if (a.review) return h('div', { class: 'card pad col gap-8' }, h('div', { class: 'row between' }, h('h2', null, 'Отзыв'), badge({ pending: 'на модерации', published: 'опубликован', rejected: 'отклонён' }[a.review.status], { pending: 'warning', published: 'success', rejected: 'danger' }[a.review.status])), stars(a.review.rating), a.review.text ? h('p', null, a.review.text) : null);
    if (!a.actions.review) return null;
    let rating = 0;
    const starsEl = h('div', { class: 'star-input' }, [1, 2, 3, 4, 5].map((i) => h('span', { onClick: () => { rating = i; [...starsEl.children].forEach((s, k) => s.classList.toggle('on', k < i)); } }, '★')));
    const text = h('textarea', { rows: 3, placeholder: 'Что понравилось, что можно улучшить?', maxlength: 2000 });
    return h('div', { class: 'card pad col gap-16' }, h('h2', null, 'Оцените консультацию'), starsEl, text,
      h('div', null, h('button', { class: 'btn', onClick: async () => { if (!rating) return toast('Поставьте оценку', 'err'); const r = await guard(() => api.post(`/api/appointments/${a.id}/review`, { rating, text: text.value })); if (r) { toast(r.message, 'ok'); load(); } } }, 'Отправить отзыв')));
  }

  async function cancel() {
    const reason = h('textarea', { rows: 3, placeholder: 'Причина отмены (необязательно)' });
    let info = null;
    if (a.status === 'confirmed' && a.refund_preview !== null) {
      info = h('div', { class: 'alert ' + (a.refund_preview === 100 ? 'ok' : a.refund_preview === 0 ? 'err' : 'warn') }, a.refund_preview === 100 ? `Возврат будет полным: ${fmtMoney(a.price)}.` : a.refund_preview === 0 ? 'Возврат не предусмотрен: до начала менее 2 часов.' : `Возврат составит ${a.refund_preview}%: ${fmtMoney(a.price * a.refund_preview / 100)}.`);
    }
    const m = modal({
      title: 'Отмена записи № ' + a.number, body: h('div', { class: 'col gap-16' }, info, field('Причина', reason)),
      footer: [h('button', { class: 'btn secondary', onClick: () => m.close() }, 'Назад'), h('button', { class: 'btn danger-solid', onClick: async () => {
        const r = await guard(() => api.post(`/api/appointments/${a.id}/cancel`, { reason: reason.value }));
        if (r) { m.close(); toast(r.refund_amount > 0 ? `Запись отменена. Возврат: ${fmtMoney(r.refund_amount)} (${r.refund_percent}%)` : 'Запись отменена', 'ok'); load(); }
      } }, 'Отменить запись')],
    });
  }

  async function mark(status) {
    if (!(await confirmDialog(status === 'completed' ? 'Отметить консультацию как проведённую?' : 'Отметить неявку клиента?', { ok: 'Да' }))) return;
    if (await guard(() => api.post(`/api/appointments/${a.id}/mark`, { status }), 'Статус обновлён')) load();
  }

  async function reschedule() {
    let chosen = null;
    const slotsBox = h('div', { class: 'mt8' });
    const dateInp = h('input', { type: 'date', min: todayIso(), max: iso(addDays(new Date(), 60)) });
    const loadSlots = async () => {
      chosen = null;
      if (!dateInp.value) return;
      mount(slotsBox, spinner());
      try {
        const d = await api.get('/api/slots', { consultant_id: a.consultant_id, service_id: a.service_id, from: dateInp.value, to: dateInp.value });
        const day = d.days[dateInp.value];
        mount(slotsBox, day && day.slots.length ? h('div', { class: 'slots' }, day.slots.map((s) => h('div', { class: 'slot' + (s.available ? '' : ' busy'), onClick: s.available ? (e) => { chosen = s.start; slotsBox.querySelectorAll('.slot').forEach((x) => x.classList.remove('sel')); e.target.classList.add('sel'); } : null }, s.start))) : h('div', { class: 'muted' }, 'В этот день нет слотов'));
      } catch (e) { mount(slotsBox, h('div', { class: 'alert err' }, e.message)); }
    };
    dateInp.addEventListener('change', loadSlots);
    const m = modal({
      title: 'Перенос записи № ' + a.number, body: h('div', { class: 'col gap-8' }, h('div', { class: 'muted' }, `Сейчас: ${fmtDateTime(a.start_at)}. ${has('client') && !has('manager') ? 'Перенос возможен один раз и не позднее чем за 24 часа до начала.' : ''}`), field('Новая дата', dateInp), slotsBox),
      footer: [h('button', { class: 'btn secondary', onClick: () => m.close() }, 'Отмена'), h('button', { class: 'btn', onClick: async () => {
        if (!chosen) return toast('Выберите время', 'err');
        const r = await guard(() => api.post(`/api/appointments/${a.id}/reschedule`, { start_at: `${dateInp.value} ${chosen}:00` }), 'Запись перенесена');
        if (r) { m.close(); load(); }
      } }, 'Перенести')],
    });
  }

  async function createProject() {
    const devs = await api.get('/api/developers');
    const form = h('form', { class: 'col gap-16', novalidate: true },
      field('Название проекта', h('input', { type: 'text', name: 'name', value: `${a.client_company || a.client_name}: ${a.service_name}` })),
      field('Описание', h('textarea', { name: 'description', rows: 3 }, a.client_comment || '')),
      h('div', { class: 'form-grid' }, field('Начало', h('input', { type: 'date', name: 'start_date', value: todayIso() })), field('Окончание', h('input', { type: 'date', name: 'end_date' }))),
      field('Руководитель проекта', select([{ value: '', label: 'Я (создатель)' }, ...devs.map((d) => ({ value: d.id, label: d.name }))], '', { name: 'owner_id' })));
    const m = modal({
      title: 'Проект по итогам консультации', body: form,
      footer: [h('button', { class: 'btn secondary', onClick: () => m.close() }, 'Отмена'), h('button', { class: 'btn', onClick: async () => {
        const d = formData(form); d.appointment_id = a.id; d.owner_id = d.owner_id ? +d.owner_id : undefined;
        try { const p = await api.post('/api/projects', d); m.close(); toast('Проект создан', 'ok'); go('/project/' + p.id); } catch (e) { showErrors(form, e); }
      } }, 'Создать')],
    });
  }

  await load();
}

// ------------------------------------------------------------------ оплата (песочница ЮKassa)
export async function pay(ctx) {
  ctx.header('Оплата консультации', 'Тестовый режим ЮKassa');
  mount(ctx.root, spinner());
  const d = await api.get('/api/payments/' + ctx.params.id);
  const { payment: p, appointment: a } = d;
  if (p.status !== 'pending' || a.status !== 'pending_payment') {
    mount(ctx.root, h('div', { class: 'card pad col gap-16', style: { maxWidth: '560px' } }, h('h2', null, p.status === 'succeeded' ? 'Оплата уже выполнена' : 'Платёж недоступен'),
      h('p', { class: 'muted' }, a.status === 'cancelled' ? 'Запись отменена: срок оплаты истёк.' : 'Этот платёж уже обработан.'), h('a', { class: 'btn', href: '#/appointment/' + a.id }, 'К записи')));
    return;
  }
  const timer = h('b');
  const tick = () => {
    const left = Math.max(0, Math.floor((parseDate(a.payment_deadline) - new Date()) / 1000));
    timer.textContent = `${String(Math.floor(left / 60)).padStart(2, '0')}:${String(left % 60).padStart(2, '0')}`;
    if (left === 0) { clearInterval(iv); toast('Время оплаты истекло, слот освобождён', 'err'); go('/appointment/' + a.id); }
  };
  const iv = setInterval(tick, 1000); ctx.onLeave(() => clearInterval(iv)); tick();
  let method = 'card';
  const cardBox = h('div', { class: 'col gap-8' },
    field('Номер карты', h('input', { type: 'text', value: '5555 5555 5555 4444', inputmode: 'numeric' })),
    h('div', { class: 'form-grid' }, field('Срок', h('input', { type: 'text', value: '12/30' })), field('CVC', h('input', { type: 'password', value: '123' }))));
  const spbBox = h('div', { class: 'alert', hidden: true }, 'В реальном режиме здесь отобразится QR-код СБП. В песочнице оплата подтверждается кнопкой ниже.');
  const btn = h('button', { class: 'btn accent block', onClick: async () => {
    btn.disabled = true;
    const r = await guard(() => api.post(`/api/payments/${p.id}/mock-pay`, { result: 'success' }));
    if (r) { toast('Оплата прошла успешно. Запись подтверждена', 'ok'); go('/appointment/' + a.id); } else btn.disabled = false;
  } }, 'Оплатить ' + fmtMoney(p.amount));
  mount(ctx.root, h('div', { class: 'split' },
    h('div', { class: 'card pad col gap-16' }, h('div', { class: 'row between' }, h('h2', null, 'Способ оплаты'), badge('песочница', 'warning')),
      h('div', { class: 'seg' }, h('button', { class: 'active', onClick: (e) => { method = 'card'; cardBox.hidden = false; spbBox.hidden = true; e.target.parentElement.querySelectorAll('button').forEach((b) => b.classList.toggle('active', b === e.target)); } }, 'Банковская карта'),
        h('button', { onClick: (e) => { method = 'sbp'; cardBox.hidden = true; spbBox.hidden = false; e.target.parentElement.querySelectorAll('button').forEach((b) => b.classList.toggle('active', b === e.target)); } }, 'СБП')),
      cardBox, spbBox, btn,
      h('button', { class: 'btn secondary block', onClick: async () => { await guard(() => api.post(`/api/payments/${p.id}/mock-pay`, { result: 'cancel' })); go('/appointment/' + a.id); } }, 'Отказаться от оплаты'),
      h('div', { class: 'small muted' }, 'Это тестовая страница оплаты: в боевом режиме клиент перенаправляется на платёжную форму ЮKassa, а подтверждение приходит webhook-уведомлением.')),
    h('div', { class: 'card pad col gap-8' }, h('h2', null, 'Ваш заказ'), kv('Запись', '№ ' + a.number), kv('Услуга', a.service_name), kv('Консультант', a.consultant_name), kv('Время', fmtDateTime(a.start_at)),
      h('div', { class: 'sep' }), h('div', { class: 'row between' }, h('b', null, 'Итого'), h('b', { style: { fontSize: '24px' } }, fmtMoney(p.amount))),
      h('div', { class: 'alert warn' }, 'Слот удерживается ещё ', timer))));
}
