// Техническая поддержка: список заявок (SLA), карточка заявки, шаблоны ответов
import * as api from '../api.js';
import { state, has, go } from '../app.js';
import {
  h, mount, icon, spinner, badge, ticketBadge, prioBadge, fmtDateTime, fmtDur, fmtSize, dataTable, pager, tabs, field, select, kv, toast, toastErr, modal, confirmDialog, guard,
  avatar, debounce, progressBar, shortName, personName, CHANNEL, TICKET_STATUS, PRIORITY, initials, formData, showErrors, emptyState,
} from '../ui.js';

const STAFF = ['support', 'manager', 'tech_director', 'admin'];

// ------------------------------------------------------------------ вспомогательное: SLA
export function slaCell(t) {
  const s = t.sla;
  let text; let tone; let cls;
  if (s.state === 'breached') { text = 'Просрочено ' + fmtDur(s.remaining_min); tone = 'red'; cls = 'breached'; }
  else if (s.state === 'warning') { text = 'Осталось ' + fmtDur(s.remaining_min); tone = 'orange'; cls = 'warning'; }
  else if (s.state === 'ok') { text = 'Осталось ' + fmtDur(s.remaining_min); tone = 'green'; cls = 'ok'; }
  else if (s.state === 'done_ok') { text = (t.status === 'closed' ? 'Закрыта' : 'Решена') + ' в срок'; tone = t.status === 'closed' ? 'gray' : 'green'; cls = 'done'; }
  else { text = (t.status === 'closed' ? 'Закрыта' : 'Решена') + ' с нарушением'; tone = 'red'; cls = 'done'; }
  return h('div', { class: 'sla ' + cls }, h('div', { class: 't' }, s.state === 'breached' ? h('span', { style: { color: 'var(--danger)', display: 'inline-flex' } }, icon('clock')) : icon('clock'), text), progressBar(s.elapsed_pct, tone));
}

// ------------------------------------------------------------------ список заявок
export async function tickets(ctx) {
  const staff = has(...STAFF) || (has('developer') && !has('client'));
  const isClient = !has(...STAFF) && has('client');
  ctx.header(isClient ? 'Поддержка' : 'Техподдержка', isClient ? 'Ваши заявки в службу поддержки' : 'Заявки · сортировка по оставшемуся времени SLA');
  const meta = await api.get('/api/ticket-meta');
  const f = { tab: ctx.query.tab || 'all', priority_id: '', category_id: '', channel: '', assignee_id: '', period: '', q: ctx.query.q || '' };
  let page = 1;
  const out = h('div', { class: 'col gap-16' });
  mount(ctx.root, out);
  const canCreate = true;

  async function openCreate() {
    let clients = [];
    if (has(...STAFF)) clients = (await api.get('/api/clients', { limit: 100 })).items;
    const files = h('input', { type: 'file', multiple: true });
    const isStaff = has(...STAFF);
    const form = h('form', { class: 'col gap-16', novalidate: true },
      isStaff ? field('Клиент *', select([{ value: '', label: '— выберите клиента —' }, ...clients.map((c) => ({ value: c.id, label: `${c.company || c.name} · ${c.name}` }))], '', { name: 'client_id' })) : null,
      h('div', { class: 'form-grid' },
        field('Категория *', select([{ value: '', label: 'Выберите категорию' }, ...meta.categories.map((c) => ({ value: c.id, label: c.name }))], '', { name: 'category_id' })),
        field('Приоритет', select(meta.priorities.filter((p) => isStaff || p.code !== 'critical').map((p) => ({ value: p.id, label: `${p.name} (реакция ${fmtDur(p.response_minutes)}, решение ${p.resolve_hours} ч)` })), meta.priorities.find((p) => p.code === 'medium').id, { name: 'priority_id' })),
        isStaff ? field('Канал обращения', select(Object.entries(CHANNEL).map(([v, l]) => ({ value: v, label: l })), 'phone', { name: 'channel' })) : null,
        meta.projects && meta.projects.length ? field('Проект', select([{ value: '', label: 'Не указан' }, ...meta.projects.map((p) => ({ value: p.id, label: p.name }))], '', { name: 'project_id' })) : null),
      field('Тема *', h('input', { type: 'text', name: 'subject', maxlength: 200, placeholder: 'Кратко: что не работает' })),
      field('Описание *', h('textarea', { name: 'description', rows: 5, placeholder: 'Что происходит, когда началось, какие сообщения об ошибках, сколько пользователей затронуто' })),
      field('Вложения', files, { hint: 'До 10 МБ на файл: png, jpg, pdf, txt, log, doc(x), xls(x), zip' }));
    const m = modal({
      title: 'Новая заявка', size: 'lg', body: form,
      footer: [h('button', { class: 'btn secondary', onClick: () => m.close() }, 'Отмена'), h('button', { class: 'btn', onClick: async (e) => {
        const btn = e.target; btn.disabled = true;
        try {
          const d = formData(form);
          const t = await api.post('/api/tickets', d);
          for (const file of files.files) {
            const fd = new FormData(); fd.append('ticket_id', t.id); fd.append('file', file);
            try { await api.upload('/api/attachments', fd); } catch (err) { toastErr(err); }
          }
          m.close(); toast(`Заявка ${t.number} создана`, 'ok'); go('/ticket/' + t.id);
        } catch (err) { showErrors(form, err); btn.disabled = false; }
      } }, 'Создать заявку')],
    });
  }

  async function draw() {
    const params = { ...f, page, limit: 15 };
    const d = await api.get('/api/tickets', params);
    const c = d.counts;
    const tabsDef = isClient ? [{ key: 'all', label: 'Все', count: c.all }, { key: 'open', label: 'Открытые' }, { key: 'waiting', label: 'Ожидают ответа', count: c.waiting }, { key: 'resolved', label: 'Решённые' }]
      : [{ key: 'all', label: 'Все', count: c.all }, { key: 'mine', label: 'Мои', count: c.mine }, { key: 'new', label: 'Новые', count: c.new }, { key: 'breached', label: 'Нарушен SLA', count: c.breached, red: true }, { key: 'waiting', label: 'Ожидание клиента', count: c.waiting }, { key: 'escalated', label: 'Эскалированы', count: c.escalated }];
    const months = []; const now = new Date();
    for (let i = 0; i < 6; i++) { const x = new Date(now.getFullYear(), now.getMonth() - i, 1); months.push({ value: `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}`, label: ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'][x.getMonth()] + ' ' + x.getFullYear() }); }
    const filters = h('div', { class: 'filters' },
      h('span', { class: 'row muted gap-8' }, icon('filter'), 'Фильтры:'),
      select([{ value: '', label: 'Приоритет: все' }, ...meta.priorities.map((p) => ({ value: p.id, label: p.name }))], f.priority_id, { onChange: (e) => { f.priority_id = e.target.value; page = 1; draw(); } }),
      select([{ value: '', label: 'Категория: все' }, ...meta.categories.map((x) => ({ value: x.id, label: x.name }))], f.category_id, { onChange: (e) => { f.category_id = e.target.value; page = 1; draw(); } }),
      staff ? select([{ value: '', label: 'Канал: все' }, ...Object.entries(CHANNEL).map(([v, l]) => ({ value: v, label: l }))], f.channel, { onChange: (e) => { f.channel = e.target.value; page = 1; draw(); } }) : null,
      staff && meta.specialists.length ? select([{ value: '', label: 'Исполнитель: все' }, ...meta.specialists.map((s) => ({ value: s.id, label: s.name }))], f.assignee_id, { onChange: (e) => { f.assignee_id = e.target.value; page = 1; draw(); } }) : null,
      select([{ value: '', label: 'Период: все' }, ...months], f.period, { onChange: (e) => { f.period = e.target.value; page = 1; draw(); } }),
      h('input', { type: 'search', placeholder: 'Поиск по теме или номеру', value: f.q, onInput: debounce((e) => { f.q = e.target.value; page = 1; draw(); }) }),
      h('span', { class: 'muted small', style: { marginLeft: 'auto' } }, 'Обновлено только что'));
    const cols = [
      { label: 'Номер', render: (t) => h('span', { class: 'num' }, t.number) },
      { label: 'Тема', render: (t) => h('div', { style: { maxWidth: '340px' } }, h('div', { class: 'bold' }, t.subject), h('div', { class: 'sub' }, `${t.category_name} · ${CHANNEL[t.channel]}`)) },
      isClient ? null : { label: 'Клиент', render: (t) => t.client_company },
      { label: 'Приоритет', render: (t) => prioBadge(t.priority_code) },
      isClient ? null : { label: 'Исполнитель', render: (t) => t.assignee_name ? shortName(t.assignee_name) : h('span', { class: 'muted' }, 'не назначен') },
      { label: 'Статус', render: (t) => [ticketBadge(t.status), t.task_id ? h('div', { class: 'sub', style: { color: 'var(--danger)' } }, '→ задача TS-' + t.task_id) : null] },
      isClient ? { label: 'Создана', render: (t) => fmtDateTime(t.created_at), cls: 'nowrap' } : { label: 'SLA: до решения', render: slaCell },
    ].filter(Boolean);
    mount(out,
      h('div', { class: 'row between wrap' }, tabs(tabsDef, f.tab, (k) => { f.tab = k; page = 1; draw(); }), canCreate ? h('button', { class: 'btn', onClick: openCreate }, icon('plus'), 'Создать заявку') : null),
      filters,
      h('div', { class: 'card' }, dataTable(cols, d.items, { onRow: (t) => go('/ticket/' + t.id), rowClass: (t) => (!isClient && t.sla.state === 'breached' ? 'row-danger' : ''), empty: 'Заявок по условиям нет' })),
      h('div', { class: 'row between wrap' }, !isClient ? h('div', { class: 'legend' }, h('span', null, 'Таймер SLA:'), h('span', null, h('i', { style: { background: 'var(--success)' } }), 'больше 25 % времени'), h('span', null, h('i', { style: { background: 'var(--warning)' } }), 'меньше 25 % / критично'), h('span', null, h('i', { style: { background: 'var(--danger)' } }), 'срок нарушен')) : h('span'),
        pager({ page: d.page, limit: d.limit, total: d.total, onPage: (p) => { page = p; draw(); } })));
  }
  mount(out, spinner());
  await draw();
}

// ------------------------------------------------------------------ карточка заявки
export async function ticket(ctx) {
  mount(ctx.root, spinner());
  let t; let meta; let templates = [];
  let filter = 'all'; let mode = 'reply';
  const draftFiles = [];

  async function load() {
    t = await api.get('/api/tickets/' + ctx.params.id);
    ctx.header('Заявка ' + t.number, `${has('client') && !t.staff_view ? 'Поддержка' : 'Техподдержка'} / Заявки / ${t.number}`);
    draw();
  }
  meta = await api.get('/api/ticket-meta');
  async function ensureTemplates() { if (!templates.length && has(...STAFF)) templates = await api.get('/api/templates', { category_id: t.category_id }); }

  function messages() {
    const list = t.comments.filter((c) => filter === 'all' || (filter === 'public' ? !c.is_internal : !!c.is_internal));
    if (!list.length) return h('div', { class: 'muted' }, 'Сообщений пока нет');
    return h('div', { class: 'col gap-16' }, list.map((c) => {
      const clientAuthor = c.is_client_author === 1 || c.is_client_author === '1' || c.author_id === t.client_id;
      const cls = c.is_internal ? 'internal' : clientAuthor ? '' : 'staff';
      return h('div', { class: 'msg ' + cls },
        h('span', { class: 'avatar ' + (clientAuthor ? 'gray' : '') }, c.author_initials),
        h('div', { class: 'bubble' }, h('div', { class: 'who' }, h('b', null, personName(c.author_name)), h('span', null, clientAuthor ? `клиент, ${t.client_company} · ${fmtDateTime(c.created_at)}` : `${c.is_internal ? '' : 'ответ клиенту · '}${fmtDateTime(c.created_at)}`),
          c.is_internal ? h('span', { class: 'internal-tag' }, '● Внутренняя заметка · не видна клиенту') : null), h('div', { class: 'text' }, c.text)));
    }));
  }

  function composer() {
    if (t.status === 'closed' && !t.can_manage) return h('div', { class: 'alert' }, 'Заявка закрыта. Чтобы продолжить, создайте новую заявку.');
    const canInternal = t.staff_view;
    const canReply = !t.staff_view || t.can_manage;
    if (!canReply) mode = 'note';
    const ta = h('textarea', { rows: 3, placeholder: mode === 'reply' ? (t.can_manage ? 'Напишите ответ клиенту…' : 'Напишите сообщение…') : 'Внутренняя заметка для коллег…' });
    const fileInp = h('input', { type: 'file', multiple: true, hidden: true, onChange: () => { draftFiles.push(...fileInp.files); fileLabel.textContent = draftFiles.length ? draftFiles.map((f) => f.name).join(', ') : 'Прикрепить файл · до 10 МБ'; } });
    const fileLabel = h('span', { class: 'small' }, 'Прикрепить файл · до 10 МБ');
    const tplSel = t.can_manage ? select([{ value: '', label: 'Шаблон ответа' }, ...templates.map((x) => ({ value: x.id, label: x.title }))], '', { style: { width: '200px' }, onChange: (e) => { const x = templates.find((y) => String(y.id) === e.target.value); if (x) { ta.value = x.body; e.target.value = ''; ta.focus(); } } }) : null;
    return h('div', { class: 'card pad col gap-8', style: { background: '#fff' } },
      h('div', { class: 'row between wrap' },
        canInternal ? h('div', { class: 'seg' }, canReply ? h('button', { class: mode === 'reply' ? 'active' : '', onClick: () => { mode = 'reply'; draw(); } }, t.can_manage ? 'Ответ клиенту' : 'Сообщение') : null, h('button', { class: mode === 'note' ? 'active' : '', onClick: () => { mode = 'note'; draw(); } }, 'Внутренняя заметка')) : h('b', null, 'Ваш ответ'),
        tplSel),
      ta,
      h('div', { class: 'row between wrap' }, h('label', { class: 'row muted', style: { cursor: 'pointer' } }, icon('paperclip'), fileLabel, fileInp, h('span', { hidden: true })),
        h('button', { class: 'btn', onClick: async (e) => {
          if (!ta.value.trim() && !draftFiles.length) return toast('Введите текст сообщения', 'err');
          e.target.disabled = true;
          try {
            if (ta.value.trim()) await api.post(`/api/tickets/${t.id}/comments`, { text: ta.value, is_internal: mode === 'note' });
            for (const file of draftFiles) { const fd = new FormData(); fd.append('ticket_id', t.id); fd.append('file', file); await api.upload('/api/attachments', fd); }
            draftFiles.length = 0;
            load();
          } catch (err) { toastErr(err); e.target.disabled = false; }
        } }, mode === 'note' ? 'Добавить заметку' : 'Отправить')));
  }

  function slaCard() {
    const s = t.sla; const p = t.priority_name;
    const tone = s.state === 'breached' ? 'danger' : s.state === 'warning' ? 'warning' : s.state === 'ok' ? 'success' : s.state === 'done_ok' ? 'success' : 'danger';
    const label = { breached: 'Просрочено', warning: 'Под угрозой', ok: 'В срок', done_ok: 'Выполнено в срок', done_late: 'Выполнено с нарушением' }[s.state];
    const big = s.remaining_min !== null ? (s.state === 'breached' ? 'Просрочено ' + fmtDur(s.remaining_min) : fmtDur(s.remaining_min)) : (s.state === 'done_ok' ? 'Решена в срок' : 'Решена с нарушением');
    const bigColor = { breached: 'var(--danger)', warning: 'var(--warning)', ok: 'var(--text)', done_ok: 'var(--success)', done_late: 'var(--danger)' }[s.state];
    const r = s.response;
    const respText = r.at ? `через ${fmtDur(r.delta_min)} · ${r.ok ? 'в срок' : 'с опозданием'}` : (r.remaining_min >= 0 ? `ждём · осталось ${fmtDur(r.remaining_min)}` : `не было · просрочено ${fmtDur(r.remaining_min)}`);
    const elapsed = s.total_min * s.elapsed_pct / 100;
    return h('div', { class: 'card pad col gap-8' },
      h('div', { class: 'row between' }, h('h2', null, 'Сроки SLA'), badge(label, tone)),
      h('div', { class: 'muted small' }, 'До срока решения'),
      h('div', { style: { fontSize: '30px', fontWeight: 800, color: bigColor } }, big),
      progressBar(s.elapsed_pct, { breached: 'red', warning: 'orange', ok: 'green', done_ok: 'green', done_late: 'red' }[s.state], true),
      h('div', { class: 'muted small' }, `Прошло ${fmtDur(elapsed)} из ${fmtDur(s.total_min)} (${s.elapsed_pct} %)`),
      h('div', { class: 'sep' }),
      kv('Решение до', fmtDateTime(t.due_at)), kv('Реакция до', fmtDateTime(t.first_response_due)),
      h('div', { class: 'kv' }, h('span', null, 'Первая реакция'), h('span', { style: { color: r.ok ? 'var(--success)' : 'var(--danger)' } }, respText)),
      h('div', { class: 'muted small' }, `${p} приоритет: реакция ${fmtDur(t.response_minutes)}, решение ${t.resolve_hours} ч, время ${t.calendar_time === '1' || t.calendar_time === 1 ? 'календарное' : 'рабочее (пн–пт 09–18)'}.`));
  }

  function actionsCard() {
    const items = [];
    if (t.can_manage) {
      if (!['resolved', 'closed'].includes(t.status) && !t.task) items.push(h('button', { class: 'btn danger block', onClick: escalate }, 'Эскалировать в разработку'), h('div', { class: 'muted small' }, 'Будет создана задача типа «ошибка» в проекте клиента (или «Сопровождение»); разработчик получит уведомление.'));
      if (t.task) items.push(h('a', { class: 'btn secondary block', href: '#/task/' + t.task.id }, `Задача ${t.task.number}: ${{ backlog: 'Backlog', in_progress: 'в работе', testing: 'тестирование', done: 'выполнена' }[t.task.status]}`));
      const resolvable = t.transitions.includes('resolved');
      items.push(h('div', { class: 'row' }, t.transitions.length ? h('button', { class: 'btn secondary grow', onClick: changeStatus }, 'Сменить статус') : null, resolvable ? h('button', { class: 'btn grow', onClick: () => setStatus('resolved') }, 'Решена') : null));
      if (t.status === 'resolved') items.push(h('button', { class: 'btn secondary block', onClick: () => setStatus('closed') }, 'Закрыть заявку'));
    } else if (t.client_actions.length) {
      if (t.client_actions.includes('closed')) items.push(h('button', { class: 'btn block', onClick: () => setStatus('closed', t.status === 'resolved' ? 'Клиент подтвердил решение' : 'Закрыта клиентом') }, t.status === 'resolved' ? 'Подтвердить решение' : 'Закрыть заявку'));
      if (t.client_actions.includes('in_progress')) items.push(h('button', { class: 'btn secondary block', onClick: () => setStatus('in_progress', 'Клиент вернул заявку в работу') }, 'Проблема осталась — вернуть в работу'));
    }
    return items.length ? h('div', { class: 'card pad col gap-8' }, h('h2', null, 'Действия'), items) : null;
  }

  function paramsCard() {
    return h('div', { class: 'card pad col gap-4' }, h('h2', { class: 'mb8' }, 'Параметры'),
      kv('Клиент', t.can_manage ? h('a', { href: '#/client/' + t.client_id }, t.client_company) : t.client_company),
      kv('Проект', t.project_id ? h('a', { href: '#/project/' + t.project_id }, t.project_name) : '—'),
      kv('Категория', t.category_name),
      h('div', { class: 'kv' }, h('span', null, 'Приоритет'), h('span', null, prioBadge(t.priority_code), t.can_manage ? [' ', h('button', { class: 'link-btn small', onClick: changePriority }, 'изменить')] : null)),
      h('div', { class: 'kv' }, h('span', null, 'Исполнитель'), h('span', null, t.assignee_name ? `${t.assignee_name}${t.auto_assigned === '1' || t.auto_assigned === 1 ? ' · автоназначение' : ''}` : 'не назначен', t.can_manage ? [' ', h('button', { class: 'link-btn small', onClick: assign }, 'изменить')] : null)),
      t.assignee_id && t.assigned_load !== null && t.can_manage ? kv('Нагрузка при назначении', `L = ${t.assigned_load}`) : null,
      kv('Связанная задача', t.task ? h('a', { href: '#/task/' + t.task.id }, t.task.number) : 'нет'));
  }

  function historyCard() {
    return h('div', { class: 'card pad' }, h('h2', { class: 'mb8' }, 'История'), h('div', { class: 'timeline' }, t.history.map((x) => h('div', { class: 'it' },
      h('div', null, x.comment || ('Статус: ' + (TICKET_STATUS[x.new_status] || [x.new_status])[0])), h('div', { class: 'm' }, fmtDateTime(x.changed_at) + ' · ' + (x.user_name || 'система'))))));
  }

  function draw() {
    const head = h('div', { class: 'card pad col gap-8' },
      h('div', { class: 'row between wrap' }, h('div', { class: 'row gap-8 wrap' }, h('span', { class: 'num bold', style: { color: 'var(--primary)' } }, t.number), prioBadge(t.priority_code), ticketBadge(t.status), badge(t.category_name, '')),
        h('span', { class: 'muted small' }, `Создана ${fmtDateTime(t.created_at)} · канал: ${CHANNEL[t.channel]}`)),
      h('h2', { style: { fontSize: '20px' } }, t.subject), h('p', { class: 'muted', style: { whiteSpace: 'pre-wrap' } }, t.description),
      t.attachments.length ? h('div', { class: 'row wrap' }, t.attachments.map((a) => h('a', { class: 'chip-file', href: '/api/attachments/' + a.id, download: '' }, icon('paperclip'), a.file_name, h('span', { class: 'muted small' }, fmtSize(a.file_size)),
        t.can_manage ? h('button', { class: 'icon-btn', style: { width: '22px', height: '22px' }, title: 'Удалить', onClick: async (e) => { e.preventDefault(); if (await confirmDialog('Удалить вложение?', { danger: true, ok: 'Удалить' })) { await guard(() => api.del('/api/attachments/' + a.id)); load(); } } }, icon('x')) : null))) : null);
    const conv = h('div', { class: 'card pad col gap-16' },
      h('div', { class: 'row between' }, h('h2', null, 'Переписка'), t.staff_view ? h('div', { class: 'seg' }, [['all', 'Все'], ['public', 'Публичные'], ['internal', 'Внутренние']].map(([k, l]) => h('button', { class: filter === k ? 'active' : '', onClick: () => { filter = k; draw(); } }, l))) : null),
      messages(), composer());
    mount(ctx.root, h('div', { class: 'split' }, h('div', { class: 'col gap-16' }, head, conv), h('div', { class: 'col gap-16' }, slaCard(), actionsCard(), paramsCard(), historyCard())));
  }

  async function setStatus(to, comment) {
    if (await guard(() => api.post(`/api/tickets/${t.id}/status`, { status: to, comment }), 'Статус обновлён')) load();
  }
  function changeStatus() {
    const sel = select(t.transitions.map((s) => ({ value: s, label: TICKET_STATUS[s][0] })), t.transitions[0]);
    const cm = h('input', { type: 'text', placeholder: 'Комментарий (необязательно)' });
    const m = modal({ title: 'Смена статуса', body: h('div', { class: 'col gap-16' }, field('Новый статус', sel), field('Комментарий', cm)),
      footer: [h('button', { class: 'btn secondary', onClick: () => m.close() }, 'Отмена'), h('button', { class: 'btn', onClick: async () => { if (await guard(() => api.post(`/api/tickets/${t.id}/status`, { status: sel.value, comment: cm.value }), 'Статус обновлён')) { m.close(); load(); } } }, 'Применить')] });
  }
  function changePriority() {
    const sel = select(meta.priorities.map((p) => ({ value: p.id, label: p.name })), t.priority_id);
    const m = modal({ title: 'Изменить приоритет', body: h('div', { class: 'col gap-16' }, field('Приоритет', sel), h('div', { class: 'alert warn' }, 'Сроки реакции и решения будут пересчитаны от времени создания заявки.')),
      footer: [h('button', { class: 'btn secondary', onClick: () => m.close() }, 'Отмена'), h('button', { class: 'btn', onClick: async () => { if (await guard(() => api.post(`/api/tickets/${t.id}/priority`, { priority_id: +sel.value }), 'Приоритет изменён')) { m.close(); load(); } } }, 'Сохранить')] });
  }
  function assign() {
    const sel = select([{ value: '', label: 'Автоназначение (по минимальной нагрузке)' }, ...meta.specialists.map((s) => ({ value: s.id, label: s.name }))], t.assignee_id || '');
    const m = modal({ title: 'Назначить исполнителя', body: field('Исполнитель', sel),
      footer: [h('button', { class: 'btn secondary', onClick: () => m.close() }, 'Отмена'), h('button', { class: 'btn', onClick: async () => { if (await guard(() => api.post(`/api/tickets/${t.id}/assign`, { assignee_id: sel.value ? +sel.value : null }), 'Исполнитель назначен')) { m.close(); load(); } } }, 'Назначить')] });
  }
  async function escalate() {
    const [projects, devs] = await Promise.all([api.get('/api/projects', { client_id: t.client_id }), api.get('/api/developers')]);
    const proj = select([{ value: '', label: t.project_id ? `Проект заявки: ${t.project_name}` : 'Проект «Сопровождение» (создастся автоматически)' }, ...projects.map((p) => ({ value: p.id, label: p.name }))], '');
    const dev = select([{ value: '', label: 'Автоматически (минимальная загрузка)' }, ...devs.map((d) => ({ value: d.id, label: d.name }))], '');
    const note = h('textarea', { rows: 3, placeholder: 'Что уже проверено, как воспроизвести' });
    const m = modal({ title: 'Эскалация в разработку', body: h('div', { class: 'col gap-16' }, field('Проект', proj), field('Исполнитель', dev), field('Комментарий для разработчика', note)),
      footer: [h('button', { class: 'btn secondary', onClick: () => m.close() }, 'Отмена'), h('button', { class: 'btn danger-solid', onClick: async () => {
        const r = await guard(() => api.post(`/api/tickets/${t.id}/escalate`, { project_id: proj.value ? +proj.value : null, assignee_id: dev.value ? +dev.value : null, note: note.value }));
        if (r) { m.close(); toast(`Создана задача TS-${r.id}`, 'ok'); load(); }
      } }, 'Эскалировать')] });
  }

  await load();
  await ensureTemplates();
  draw();
}

// ------------------------------------------------------------------ шаблоны ответов
export async function templates(ctx) {
  ctx.header('Шаблоны ответов', 'Быстрые ответы для переписки с клиентами');
  const canEdit = has('support', 'manager', 'admin');
  const meta = await api.get('/api/ticket-meta');
  const host = h('div', { class: 'col gap-16' });
  mount(ctx.root, host);
  function edit(x) {
    const form = h('form', { class: 'col gap-16', novalidate: true },
      field('Название', h('input', { type: 'text', name: 'title', value: x ? x.title : '' })),
      field('Категория', select([{ value: '', label: 'Для всех категорий' }, ...meta.categories.map((c) => ({ value: c.id, label: c.name }))], x ? x.category_id || '' : '', { name: 'category_id' })),
      field('Текст', h('textarea', { name: 'body', rows: 6 }, x ? x.body : '')),
      h('label', { class: 'chk' }, h('input', { type: 'checkbox', name: 'is_active', checked: x ? !!+x.is_active : true }), 'Шаблон активен'));
    const m = modal({ title: x ? 'Редактирование шаблона' : 'Новый шаблон', body: form,
      footer: [h('button', { class: 'btn secondary', onClick: () => m.close() }, 'Отмена'), h('button', { class: 'btn', onClick: async () => {
        const d = formData(form); d.category_id = d.category_id ? +d.category_id : null;
        try { await (x ? api.put('/api/templates/' + x.id, d) : api.post('/api/templates', d)); m.close(); toast('Сохранено', 'ok'); draw(); } catch (e) { showErrors(form, e); }
      } }, 'Сохранить')] });
  }
  async function draw() {
    const list = await api.get('/api/templates', { all: 1 });
    mount(host, h('div', { class: 'row between' }, h('span', { class: 'muted' }, `${list.length} шаблонов`), canEdit ? h('button', { class: 'btn', onClick: () => edit(null) }, icon('plus'), 'Новый шаблон') : null),
      list.length ? h('div', { class: 'grid c2' }, list.map((x) => h('div', { class: 'card pad col gap-8' }, h('div', { class: 'row between' }, h('b', null, x.title), x.category_name ? badge(x.category_name, 'info', true) : badge('Все категории', '', true)),
        h('p', { class: 'muted', style: { whiteSpace: 'pre-wrap' } }, x.body), !+x.is_active ? badge('отключён', 'warning') : null,
        canEdit ? h('div', { class: 'row' }, h('button', { class: 'btn secondary sm', onClick: () => edit(x) }, 'Изменить'), h('button', { class: 'btn danger sm', onClick: async () => { if (await confirmDialog('Удалить шаблон?', { danger: true, ok: 'Удалить' })) { await guard(() => api.del('/api/templates/' + x.id)); draw(); } } }, 'Удалить')) : null))) : emptyState('Шаблонов пока нет'));
  }
  await draw();
}
