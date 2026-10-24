// Кабинет клиента, клиенты (для сотрудников), профиль, уведомления
import * as api from '../api.js';
import { state, has, go, setUser, notifLink } from '../app.js';
import {
  h, mount, icon, spinner, badge, appBadge, ticketBadge, prioBadge, projectBadge, fmtDate, fmtDateTime, fmtMoney, dataTable, pager, field, kv, toast, guard, avatar, debounce, progressBar,
  formData, showErrors, emptyState, ROLE_NAME, fmtLong, fmtTime, pluralize,
} from '../ui.js';
import { slaCell } from './support.js';

export async function clientHome(ctx) {
  const u = state.user;
  ctx.header('Главная', 'Ваши консультации, проекты и заявки');
  mount(ctx.root, spinner());
  const [apps, tickets, projects, ntf] = await Promise.all([
    api.get('/api/appointments', { upcoming: 1, sort: 'asc', limit: 5 }), api.get('/api/tickets', { tab: 'open', limit: 5 }), api.get('/api/projects'), api.get('/api/notifications', { limit: 5 }),
  ]);
  const active = projects.filter((p) => !['closed', 'delivered'].includes(p.status));
  const kpi = (label, v, note, to, tone) => h('a', { class: 'card kpi' + (tone ? ' alert-tone' : ''), href: '#' + to, style: { color: 'inherit', textDecoration: 'none' } }, h('div', { class: 'l' }, h('i'), label), h('div', { class: 'v' }, v), h('div', { class: 'n' }, note));
  mount(ctx.root,
    h('div', { class: 'card pad row between wrap' }, h('div', null, h('h2', null, `Здравствуйте, ${u.first_name}!`), h('p', { class: 'muted' }, u.company || 'Рады видеть вас в ТрайтэкСервис')),
      h('div', { class: 'row wrap' }, h('a', { class: 'btn accent', href: '#/book' }, icon('calendar'), 'Записаться на консультацию'), h('a', { class: 'btn secondary', href: '#/support' }, icon('support'), 'Создать заявку'))),
    h('div', { class: 'grid c4' },
      kpi('Предстоящие консультации', apps.total, apps.items[0] ? `ближайшая: ${fmtDateTime(apps.items[0].start_at)}` : 'нет записей', '/my/appointments'),
      kpi('Открытые заявки', tickets.total, tickets.counts.waiting ? `ожидают вашего ответа: ${tickets.counts.waiting}` : 'нет ожидающих ответа', '/support', tickets.counts.waiting > 0),
      kpi('Активные проекты', active.length, active[0] ? active[0].name : 'проектов нет', '/my/projects'),
      kpi('Новые уведомления', ntf.unread, 'сообщения по записям и заявкам', '/notifications')),
    h('div', { class: 'grid c2' },
      h('div', { class: 'card' }, h('div', { class: 'card-h' }, h('h3', null, 'Ближайшие консультации'), h('a', { href: '#/my/appointments' }, 'Все →')),
        dataTable([{ label: 'Дата', render: (a) => [fmtDateTime(a.start_at)] }, { label: 'Услуга', render: (a) => [a.service_name, h('div', { class: 'sub' }, a.consultant_name)] }, { label: 'Статус', render: (a) => appBadge(a.status) }], apps.items, { onRow: (a) => go('/appointment/' + a.id), empty: 'Предстоящих консультаций нет' })),
      h('div', { class: 'card' }, h('div', { class: 'card-h' }, h('h3', null, 'Открытые заявки'), h('a', { href: '#/support' }, 'Все →')),
        dataTable([{ label: 'Номер', render: (t) => h('span', { class: 'num' }, t.number) }, { label: 'Тема', render: (t) => t.subject }, { label: 'Статус', render: (t) => ticketBadge(t.status) }], tickets.items, { onRow: (t) => go('/ticket/' + t.id), empty: 'Открытых заявок нет' }))),
    h('div', { class: 'card' }, h('div', { class: 'card-h' }, h('h3', null, 'Проекты'), h('a', { href: '#/my/projects' }, 'Все →')),
      projects.length ? h('div', { class: 'card-b col gap-16' }, projects.slice(0, 4).map((p) => h('div', { class: 'col gap-4', style: { cursor: 'pointer' }, onClick: () => go('/project/' + p.id) }, h('div', { class: 'row between' }, h('b', null, p.name), projectBadge(p.status)), progressBar(p.progress.percent), h('div', { class: 'muted small' }, `${p.progress.percent} % · ${p.progress.tasks_done} из ${p.progress.tasks_total} задач`)))) : emptyState('Проектов пока нет — они появляются по итогам консультаций')));
}

// ------------------------------------------------------------------ клиенты для сотрудников
export async function clients(ctx) {
  ctx.header('Клиенты', 'Справочник клиентов и история обращений');
  let q = ''; let page = 1;
  const out = h('div', { class: 'col gap-16' });
  mount(ctx.root, out);
  async function draw() {
    const d = await api.get('/api/clients', { q, page, limit: 20 });
    mount(out, h('div', { class: 'filters' }, h('input', { type: 'search', placeholder: 'Поиск: имя, организация, e-mail, телефон', style: { minWidth: '320px' }, value: q, onInput: debounce((e) => { q = e.target.value; page = 1; draw(); }) })),
      h('div', { class: 'card' }, dataTable([
        { label: 'Клиент', render: (c) => h('span', { class: 'row gap-8' }, avatar(c.name, 'soft'), h('span', null, h('b', null, c.company || c.name), h('div', { class: 'sub' }, c.name))) },
        { label: 'Контакты', render: (c) => [c.email, h('div', { class: 'sub' }, c.phone || '')] },
        { label: 'Консультации', render: (c) => c.appointments_count }, { label: 'Проекты', render: (c) => c.projects_count },
        { label: 'Заявки', render: (c) => [c.tickets_count, c.open_tickets > 0 ? h('span', { class: 'sub' }, ` · открыто ${c.open_tickets}`) : null] },
      ], d.items, { onRow: (c) => go('/client/' + c.id), empty: 'Клиенты не найдены' })), pager({ page: d.page, limit: d.limit, total: d.total, onPage: (p) => { page = p; draw(); } }));
  }
  mount(out, spinner());
  await draw();
}

export async function clientCard(ctx) {
  mount(ctx.root, spinner());
  const c = await api.get('/api/clients/' + ctx.params.id);
  ctx.header(c.company || `${c.last_name} ${c.first_name}`, 'Клиенты / карточка клиента');
  mount(ctx.root,
    h('div', { class: 'card pad row between wrap' }, h('div', { class: 'row' }, avatar(`${c.last_name} ${c.first_name}`, 'md soft'), h('div', null, h('h2', null, `${c.last_name} ${c.first_name} ${c.middle_name || ''}`), h('div', { class: 'muted' }, c.company || 'частное лицо'),
      h('div', { class: 'small' }, `${c.email}${c.phone ? ' · ' + c.phone : ''}`))), h('div', { class: 'muted small' }, `Клиент с ${fmtDate(c.created_at)}`, h('br'), `Последний вход: ${fmtDateTime(c.last_login_at)}`)),
    h('div', { class: 'grid c2' },
      h('div', { class: 'card' }, h('div', { class: 'card-h' }, h('h3', null, 'Консультации')), dataTable([{ label: '№', render: (a) => h('span', { class: 'num' }, a.number) }, { label: 'Дата', render: (a) => fmtDateTime(a.start_at) }, { label: 'Услуга', render: (a) => a.service_name }, { label: 'Статус', render: (a) => appBadge(a.status) }], c.appointments, { onRow: (a) => go('/appointment/' + a.id), empty: 'Консультаций нет' })),
      h('div', { class: 'card' }, h('div', { class: 'card-h' }, h('h3', null, 'Заявки')), dataTable([{ label: 'Номер', render: (t) => h('span', { class: 'num' }, t.number) }, { label: 'Тема', render: (t) => t.subject }, { label: 'Приоритет', render: (t) => prioBadge(t.priority_code) }, { label: 'Статус', render: (t) => ticketBadge(t.status) }], c.tickets, { onRow: (t) => go('/ticket/' + t.id), empty: 'Заявок нет' }))),
    h('div', { class: 'card' }, h('div', { class: 'card-h' }, h('h3', null, 'Проекты')), dataTable([{ label: 'Название', render: (p) => p.name }, { label: 'Статус', render: (p) => projectBadge(p.status) }, { label: 'Период', render: (p) => `${fmtDate(p.start_date)}${p.end_date ? ' — ' + fmtDate(p.end_date) : ''}` }], c.projects, { onRow: (p) => go('/project/' + p.id), empty: 'Проектов нет' })));
}

// ------------------------------------------------------------------ профиль
export async function profile(ctx) {
  const u = state.user;
  ctx.header('Профиль', 'Личные данные и безопасность');
  const form = h('form', { class: 'col gap-16', novalidate: true, onSubmit: async (e) => {
    e.preventDefault();
    try { const d = await api.put('/api/profile', formData(form)); setUser(d); toast('Профиль сохранён', 'ok'); } catch (err) { showErrors(form, err); }
  } },
  h('div', { class: 'form-grid' },
    field('Фамилия', h('input', { type: 'text', name: 'last_name', value: u.last_name })), field('Имя', h('input', { type: 'text', name: 'first_name', value: u.first_name })),
    field('Отчество', h('input', { type: 'text', name: 'middle_name', value: u.middle_name || '' })), field('Телефон', h('input', { type: 'tel', name: 'phone', value: u.phone || '' })),
    field('Организация', h('input', { type: 'text', name: 'company', value: u.company || '' })), field('E-mail', h('input', { type: 'email', value: u.email, disabled: true }), { hint: 'Изменить e-mail может администратор' })),
  h('div', null, h('button', { class: 'btn', type: 'submit' }, 'Сохранить')));
  const pform = h('form', { class: 'col gap-16', novalidate: true, onSubmit: async (e) => {
    e.preventDefault();
    const d = formData(pform);
    if (d.new_password !== d.repeat) return showErrors(pform, { fields: { repeat: 'Пароли не совпадают' } });
    try { await api.post('/api/profile/password', d); toast('Пароль изменён', 'ok'); pform.reset(); } catch (err) { showErrors(pform, err); }
  } }, field('Текущий пароль', h('input', { type: 'password', name: 'current_password', autocomplete: 'current-password' })),
  field('Новый пароль', h('input', { type: 'password', name: 'new_password', autocomplete: 'new-password' }), { hint: 'Не менее 8 символов, буквы и цифры' }), field('Повтор', h('input', { type: 'password', name: 'repeat', autocomplete: 'new-password' })),
  h('div', null, h('button', { class: 'btn', type: 'submit' }, 'Сменить пароль')));
  mount(ctx.root, h('div', { class: 'grid c2', style: { alignItems: 'start' } },
    h('div', { class: 'card pad col gap-16' }, h('div', { class: 'row' }, avatar(u.name, 'md'), h('div', null, h('h2', null, u.name), h('div', { class: 'row wrap gap-8 mt8' }, u.roles.map((r) => badge(ROLE_NAME[r], 'info', true))))), form),
    h('div', { class: 'card pad col gap-16' }, h('h2', null, 'Смена пароля'), pform)));
}

// ------------------------------------------------------------------ уведомления
export async function notifications(ctx) {
  ctx.header('Уведомления', 'События по вашим записям, заявкам и задачам');
  const host = h('div', { class: 'col gap-16' });
  mount(ctx.root, host);
  async function draw() {
    const d = await api.get('/api/notifications', { limit: 100 });
    mount(host, h('div', { class: 'row between' }, h('span', { class: 'muted' }, d.unread ? `Непрочитанных: ${d.unread}` : 'Все прочитано'), d.unread ? h('button', { class: 'btn secondary sm', onClick: async () => { await api.post('/api/notifications/read-all'); draw(); } }, 'Прочитать все') : null),
      d.items.length ? h('div', { class: 'card' }, d.items.map((n) => h('a', { class: 'ntf' + (n.is_read ? '' : ' unread'), href: '#' + notifLink(n), onClick: () => api.post(`/api/notifications/${n.id}/read`) },
        h('div', { class: 'tt' }, n.title), n.body ? h('div', { class: 'bd' }, n.body) : null, h('div', { class: 'bd' }, fmtDateTime(n.created_at))))) : emptyState('Уведомлений пока нет'));
  }
  mount(host, spinner());
  await draw();
}
