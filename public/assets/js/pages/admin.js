// Администрирование: пользователи и роли, справочники, настройки, журнал, резервные копии, cron
import * as api from '../api.js';
import { state, go } from '../app.js';
import {
  h, mount, icon, spinner, badge, fmtDate, fmtDateTime, fmtSize, dataTable, pager, tabs, field, select, kv, toast, toastErr, modal, confirmDialog, guard, avatar, debounce, formData, showErrors,
  emptyState, ROLE_NAME, fmtMoney, fmtDur,
} from '../ui.js';

// ------------------------------------------------------------------ пользователи
export async function users(ctx) {
  ctx.header('Пользователи', 'Учётные записи и роли');
  const roles = await api.get('/api/admin/roles');
  const f = { q: '', role: '', active: '' };
  let page = 1;
  const out = h('div', { class: 'col gap-16' });
  mount(ctx.root, out);

  async function edit(id) {
    const specs = await api.get('/api/specializations');
    const u = id ? await api.get('/api/admin/users/' + id) : { roles: ['client'], is_active: 1, consultant: null };
    const form = h('form', { class: 'col gap-16', novalidate: true },
      h('div', { class: 'form-grid' },
        field('Фамилия *', h('input', { type: 'text', name: 'last_name', value: u.last_name || '' })), field('Имя *', h('input', { type: 'text', name: 'first_name', value: u.first_name || '' })),
        field('Отчество', h('input', { type: 'text', name: 'middle_name', value: u.middle_name || '' })), field('Телефон', h('input', { type: 'tel', name: 'phone', value: u.phone || '' })),
        field('E-mail *', h('input', { type: 'email', name: 'email', value: u.email || '' })), field('Организация', h('input', { type: 'text', name: 'company', value: u.company || '' })),
        !id ? field('Пароль', h('input', { type: 'text', name: 'password', placeholder: 'пусто — будет сгенерирован' }), { hint: 'Временный пароль отправляется на e-mail сотрудника' }) : null),
      h('div', { class: 'field' }, h('label', null, 'Роли *'), h('div', { class: 'row wrap' }, roles.map((r) => h('label', { class: 'chk', style: { width: '210px' } }, h('input', { type: 'checkbox', name: 'roles[]', value: r.code, checked: u.roles.includes(r.code), onChange: toggleCons }), r.name))), h('div', { class: 'err', hidden: true })),
      h('div', { name: 'consbox', class: 'card pad col gap-8', hidden: !u.roles.includes('consultant'), style: { background: 'var(--bg)' } }, h('b', null, 'Профиль консультанта'),
        field('Должность', h('input', { type: 'text', name: 'c_position', value: u.consultant ? u.consultant.position : '' })),
        field('О себе', h('textarea', { name: 'c_bio', rows: 3 }, u.consultant ? u.consultant.bio || '' : '')),
        field('Стаж, лет', h('input', { type: 'number', name: 'c_exp', min: 0, max: 60, value: u.consultant ? u.consultant.experience_years : 0 })),
        h('div', { class: 'field' }, h('label', null, 'Направления'), h('div', { class: 'row wrap' }, specs.map((s) => h('label', { class: 'chk' }, h('input', { type: 'checkbox', name: 'spec[]', value: s.id, checked: u.consultant && u.consultant.specialization_ids.includes(s.id) }), s.name))))),
      id ? h('label', { class: 'chk' }, h('input', { type: 'checkbox', name: 'is_active', checked: !!+u.is_active }), 'Учётная запись активна') : null);
    function toggleCons() { form.querySelector('[name=consbox]').hidden = !form.querySelector('input[value=consultant]').checked; }
    const m = modal({
      title: id ? 'Пользователь' : 'Новый сотрудник / пользователь', size: 'lg', body: form,
      footer: [id ? h('button', { class: 'btn secondary', style: { marginRight: 'auto' }, onClick: async () => { if (await confirmDialog('Сгенерировать новый временный пароль и отправить его на e-mail?', { ok: 'Сбросить' })) { const r = await guard(() => api.post(`/api/admin/users/${id}/reset-password`)); if (r) toast('Новый пароль: ' + r.temp_password, 'ok'); } } }, 'Сбросить пароль') : null,
        h('button', { class: 'btn secondary', onClick: () => m.close() }, 'Отмена'),
        h('button', { class: 'btn', onClick: async () => {
          const d = formData(form);
          const body = { last_name: d.last_name, first_name: d.first_name, middle_name: d.middle_name, phone: d.phone, email: d.email, company: d.company, roles: d.roles || [], is_active: id ? d.is_active : true, password: d.password };
          if (body.roles.includes('consultant')) body.consultant = { position: d.c_position, bio: d.c_bio, experience_years: +d.c_exp || 0, specialization_ids: (d.spec || []).map(Number) };
          try {
            const r = id ? await api.put('/api/admin/users/' + id, body) : await api.post('/api/admin/users', body);
            m.close();
            toast(r.temp_password ? `Пользователь создан. Временный пароль: ${r.temp_password}` : 'Сохранено', 'ok');
            draw();
          } catch (e) { showErrors(form, e); }
        } }, 'Сохранить')].filter(Boolean),
    });
  }

  async function draw() {
    const d = await api.get('/api/admin/users', { ...f, page, limit: 20 });
    mount(out,
      h('div', { class: 'row between wrap' }, h('div', { class: 'filters' },
        h('input', { type: 'search', placeholder: 'Поиск: имя, e-mail, организация', value: f.q, onInput: debounce((e) => { f.q = e.target.value; page = 1; draw(); }), style: { minWidth: '260px' } }),
        select([{ value: '', label: 'Роль: все' }, ...roles.map((r) => ({ value: r.code, label: r.name }))], f.role, { onChange: (e) => { f.role = e.target.value; page = 1; draw(); } }),
        select([{ value: '', label: 'Статус: все' }, { value: '1', label: 'Активные' }, { value: '0', label: 'Заблокированные' }], f.active, { onChange: (e) => { f.active = e.target.value; page = 1; draw(); } })),
        h('button', { class: 'btn', onClick: () => edit(null) }, icon('plus'), 'Добавить пользователя')),
      h('div', { class: 'card' }, dataTable([
        { label: 'Пользователь', render: (u) => h('span', { class: 'row gap-8' }, avatar(`${u.last_name} ${u.first_name}`, 'soft'), h('span', null, h('b', null, `${u.last_name} ${u.first_name}`), h('div', { class: 'sub' }, u.email))) },
        { label: 'Роли', render: (u) => h('div', { class: 'row wrap gap-4' }, u.roles.map((r) => badge(ROLE_NAME[r], 'info', true))) },
        { label: 'Организация', render: (u) => u.company || '—' },
        { label: 'Последний вход', render: (u) => u.last_login_at ? fmtDateTime(u.last_login_at) : '—', cls: 'nowrap' },
        { label: 'Статус', render: (u) => !+u.is_active ? badge('заблокирован', 'danger') : !u.email_verified_at ? badge('e-mail не подтверждён', 'warning') : u.locked_until && new Date(u.locked_until.replace(' ', 'T')) > new Date() ? badge('временная блокировка', 'warning') : badge('активен', 'success') },
      ], d.items, { onRow: (u) => edit(u.id), empty: 'Пользователи не найдены' })),
      pager({ page: d.page, limit: d.limit, total: d.total, onPage: (p) => { page = p; draw(); } }));
  }
  mount(out, spinner());
  await draw();
}

// ------------------------------------------------------------------ справочники
export async function directories(ctx) {
  ctx.header('Справочники', 'Направления, услуги, категории заявок, приоритеты и SLA');
  let tab = ctx.query.tab || 'services';
  const specs = await api.get('/api/admin/directories/specializations');
  const host = h('div', { class: 'col gap-16' });
  mount(ctx.root, host);

  function editDir(dir, item) {
    const isSvc = dir === 'services';
    const form = h('form', { class: 'col gap-16', novalidate: true },
      isSvc ? field('Направление', select(specs.map((s) => ({ value: s.id, label: s.name })), item ? item.specialization_id : specs[0].id, { name: 'specialization_id' })) : null,
      field('Название *', h('input', { type: 'text', name: 'name', value: item ? item.name : '' })),
      dir !== 'categories' ? field('Описание', h('textarea', { name: 'description', rows: 2 }, item ? item.description || '' : '')) : null,
      isSvc ? h('div', { class: 'form-grid' }, field('Длительность, мин (15–480)', h('input', { type: 'number', name: 'duration_min', min: 15, max: 480, step: 5, value: item ? item.duration_min : 60 })), field('Цена, ₽', h('input', { type: 'number', name: 'price', min: 0, step: 100, value: item ? item.price : 3000 }))) : null,
      h('label', { class: 'chk' }, h('input', { type: 'checkbox', name: 'is_active', checked: item ? !!+item.is_active : true }), 'Активно (отображается в каталоге)'));
    const m = modal({ title: item ? 'Редактирование' : 'Новая запись', body: form, footer: [h('button', { class: 'btn secondary', onClick: () => m.close() }, 'Отмена'), h('button', { class: 'btn', onClick: async () => {
      const d = formData(form);
      if (isSvc) { d.specialization_id = +d.specialization_id; d.duration_min = +d.duration_min; d.price = +d.price; }
      try { await (item ? api.put(`/api/admin/directories/${dir}/${item.id}`, d) : api.post('/api/admin/directories/' + dir, d)); m.close(); toast('Сохранено', 'ok'); draw(); } catch (e) { showErrors(form, e); }
    } }, 'Сохранить')] });
  }

  function editPriority(p) {
    const form = h('form', { class: 'col gap-16', novalidate: true }, field('Название', h('input', { type: 'text', name: 'name', value: p.name })),
      h('div', { class: 'form-grid' }, field('Вес для автоназначения', h('input', { type: 'number', name: 'weight', min: 1, value: p.weight })), field('Реакция, мин', h('input', { type: 'number', name: 'response_minutes', min: 1, value: p.response_minutes })),
        field('Решение, ч', h('input', { type: 'number', name: 'resolve_hours', min: 1, value: p.resolve_hours }))),
      h('label', { class: 'chk' }, h('input', { type: 'checkbox', name: 'calendar_time', checked: !!+p.calendar_time }), 'Календарное время (24/7). Иначе — рабочее: пн–пт, 09:00–18:00'),
      h('div', { class: 'alert warn' }, 'Изменения применяются к новым заявкам; сроки уже созданных заявок сохраняются.'));
    const m = modal({ title: 'Приоритет «' + p.name + '»', body: form, footer: [h('button', { class: 'btn secondary', onClick: () => m.close() }, 'Отмена'), h('button', { class: 'btn', onClick: async () => {
      const d = formData(form);
      try { await api.put('/api/admin/priorities/' + p.id, { name: d.name, weight: +d.weight, response_minutes: +d.response_minutes, resolve_hours: +d.resolve_hours, calendar_time: d.calendar_time }); m.close(); toast('Сохранено', 'ok'); draw(); } catch (e) { showErrors(form, e); }
    } }, 'Сохранить')] });
  }

  async function draw() {
    let body;
    if (tab === 'priorities') {
      const list = await api.get('/api/admin/priorities');
      body = h('div', { class: 'card' }, dataTable([{ label: 'Приоритет', render: (p) => h('b', null, p.name) }, { label: 'Вес (L)', render: (p) => p.weight }, { label: 'Реакция', render: (p) => fmtDur(p.response_minutes) }, { label: 'Решение', render: (p) => p.resolve_hours + ' ч' },
        { label: 'Время', render: (p) => +p.calendar_time ? 'календарное' : 'рабочее' }], list, { onRow: editPriority }));
    } else {
      const list = await api.get('/api/admin/directories/' + tab);
      const cols = { specializations: [{ label: 'Название', render: (x) => h('b', null, x.name) }, { label: 'Описание', render: (x) => x.description || '—' }],
        services: [{ label: 'Услуга', render: (x) => h('b', null, x.name) }, { label: 'Направление', render: (x) => x.specialization_name }, { label: 'Длительность', render: (x) => fmtDur(x.duration_min) }, { label: 'Цена', render: (x) => fmtMoney(x.price) }],
        categories: [{ label: 'Название', render: (x) => h('b', null, x.name) }] }[tab];
      body = h('div', { class: 'card' }, dataTable([...cols, { label: 'Статус', render: (x) => +x.is_active ? badge('активно', 'success') : badge('скрыто') }], list, { onRow: (x) => editDir(tab, x) }));
      body = h('div', { class: 'col gap-16' }, h('div', null, h('button', { class: 'btn', onClick: () => editDir(tab, null) }, icon('plus'), 'Добавить')), body);
    }
    mount(host, tabs([{ key: 'services', label: 'Услуги' }, { key: 'specializations', label: 'Направления' }, { key: 'categories', label: 'Категории заявок' }, { key: 'priorities', label: 'Приоритеты и SLA' }], tab, (k) => { tab = k; draw(); }), body);
  }
  await draw();
}

// ------------------------------------------------------------------ настройки
export async function settings(ctx) {
  ctx.header('Настройки', 'Параметры бизнес-правил системы');
  mount(ctx.root, spinner());
  const list = await api.get('/api/admin/settings');
  const groups = [
    ['Запись на консультации', ['payment_timeout_min', 'slot_step_min', 'buffer_min', 'min_booking_hours', 'booking_horizon_days']],
    ['Отмена и возврат', ['free_cancel_hours', 'partial_refund_percent', 'no_refund_hours']],
    ['Уведомления и контроль', ['reminder_hours', 'overdue_check_min', 'task_due_notify_hours']],
    ['Техподдержка и SLA', ['auto_assign', 'sla_warn_percent', 'sla_target_percent', 'business_start', 'business_end']],
    ['Организация', ['company_name', 'support_email']],
  ];
  const by = Object.fromEntries(list.map((s) => [s.name, s]));
  const form = h('form', { class: 'col gap-16', onSubmit: async (e) => {
    e.preventDefault();
    const d = formData(form);
    if (await guard(() => api.put('/api/admin/settings', { settings: d }), 'Настройки сохранены')) { /* ok */ }
  } },
  groups.map(([title, keys]) => h('div', { class: 'card pad col gap-16' }, h('h2', null, title), h('div', { class: 'form-grid' }, keys.filter((k) => by[k]).map((k) => field(by[k].description, h('input', { type: 'text', name: k, value: by[k].value }), { hint: k }))))),
  h('div', null, h('button', { class: 'btn', type: 'submit' }, 'Сохранить настройки')));
  mount(ctx.root, form);
}

// ------------------------------------------------------------------ журнал действий
export async function log(ctx) {
  ctx.header('Журнал действий', 'Аудит операций пользователей и системы');
  const f = { action: '', from: '', to: '' };
  let page = 1;
  const out = h('div', { class: 'col gap-16' });
  mount(ctx.root, out);
  async function draw() {
    const d = await api.get('/api/admin/log', { ...f, page, limit: 30 });
    mount(out, h('div', { class: 'filters' },
      select([{ value: '', label: 'Действие: все' }, ...['auth', 'appointment', 'payment', 'ticket', 'task', 'project', 'user', 'settings', 'schedule', 'review', 'backup', 'cron', 'report', 'attachment'].map((a) => ({ value: a, label: a }))], f.action, { onChange: (e) => { f.action = e.target.value; page = 1; draw(); } }),
      h('input', { type: 'date', value: f.from, onChange: (e) => { f.from = e.target.value; page = 1; draw(); } }), h('input', { type: 'date', value: f.to, onChange: (e) => { f.to = e.target.value; page = 1; draw(); } })),
      h('div', { class: 'card' }, dataTable([
        { label: 'Время', render: (l) => fmtDateTime(l.created_at), cls: 'nowrap' }, { label: 'Пользователь', render: (l) => l.user_name || 'система' }, { label: 'Действие', render: (l) => h('code', null, l.action) },
        { label: 'Объект', render: (l) => l.entity_type ? `${l.entity_type}${l.entity_id ? ' #' + l.entity_id : ''}` : '—' }, { label: 'Детали', render: (l) => l.details || '—' }, { label: 'IP', render: (l) => l.ip || '—' },
      ], d.items, { empty: 'Записей нет' })), pager({ page: d.page, limit: d.limit, total: d.total, onPage: (p) => { page = p; draw(); } }));
  }
  mount(out, spinner());
  await draw();
}

// ------------------------------------------------------------------ резервные копии, cron, почта
export async function backups(ctx) {
  ctx.header('Резервные копии и фоновые задачи', 'Резервное копирование БД, планировщик, письма');
  const host = h('div', { class: 'col gap-16' });
  mount(ctx.root, host);
  async function draw() {
    const [b, cron, mails] = await Promise.all([api.get('/api/admin/backups'), api.get('/api/admin/cron'), api.get('/api/admin/mails')]);
    mount(host,
      h('div', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Резервные копии базы данных'), h('button', { class: 'btn', onClick: async (e) => { e.target.disabled = true; if (await guard(() => api.post('/api/admin/backups'), 'Резервная копия создана')) draw(); else e.target.disabled = false; } }, icon('db'), 'Создать копию')),
        dataTable([{ label: 'Файл', render: (x) => h('code', null, x.name) }, { label: 'Создана', render: (x) => fmtDateTime(x.created_at) }, { label: 'Размер', render: (x) => fmtSize(x.size) }, { label: '', render: (x) => h('a', { class: 'btn secondary sm', href: '/api/admin/backups/' + x.name, download: '' }, icon('download'), 'Скачать') }], b.items, { empty: 'Копий пока нет' }),
        h('div', { class: 'card-b muted small' }, 'Автоматическая копия создаётся заданием cron «backup» ежедневно; копии старше 14 дней удаляются. Восстановление: импорт .sql в MySQL (mysql < файл.sql).')),
      h('div', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Фоновые задания (cron)'), h('span', { class: 'muted small' }, 'Расписание: cancel_unpaid и check_sla — каждые 5 мин, send_reminders — 15 мин, check_tasks — час, backup — ночью')),
        dataTable([{ label: 'Задание', render: (j) => [h('b', null, j.title), h('div', { class: 'sub' }, h('code', null, 'php cron/run.php ' + j.job))] }, { label: 'Последний запуск', render: (j) => j.last_run ? fmtDateTime(j.last_run) : 'не запускалось' }, { label: 'Результат', render: (j) => j.last_result || '—' },
          { label: '', render: (j) => h('button', { class: 'btn secondary sm', onClick: async () => { const r = await guard(() => api.post('/api/admin/cron/' + j.job), 'Задание выполнено: ' + JSON.stringify(r || {})); draw(); } }, 'Запустить') }], cron)),
      h('div', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Исходящие письма (режим без SMTP)')),
        mails.length ? h('div', { class: 'card-b col gap-8' }, mails.slice(0, 12).map((m) => h('details', null, h('summary', { style: { cursor: 'pointer' } }, ((m.content.match(/^To: (.*)$/m) || [])[1] || '') + ' — ' + ((m.content.match(/^Subject: (.*)$/m) || [])[1] || m.name)), h('pre', { style: { whiteSpace: 'pre-wrap', background: 'var(--bg)', padding: '12px', borderRadius: '8px', fontSize: '12px' } }, m.content)))) : emptyState('Писем нет')));
  }
  mount(host, spinner());
  await draw();
}
