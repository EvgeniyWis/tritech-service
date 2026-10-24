// Точка входа SPA: состояние, маршрутизация, оболочка (сайдбар + верхняя панель)
import * as api from './api.js';
import { h, mount, clear, icon, avatar, spinner, toast, toastErr, ROLE_NAME, fmtDateTime, debounce } from './ui.js';

export const state = { user: null, demo: false };
const app = document.getElementById('app');

// ------------------------------------------------------------ навигация по ролям
const NAV = {
  client: [
    { label: 'Главная', icon: 'dashboard', href: '/home' },
    { label: 'Записаться', icon: 'calendar', href: '/book' },
    { label: 'Мои консультации', icon: 'consult', href: '/my/appointments', match: ['/my/appointments', '/appointment/'] },
    { label: 'Мои проекты', icon: 'dev', href: '/my/projects', match: ['/my/projects', '/project/'] },
    { label: 'Поддержка', icon: 'support', href: '/support', match: ['/support', '/ticket/'] },
  ],
  consultant: [
    { label: 'Календарь', icon: 'calendar', href: '/calendar' },
    { label: 'Консультации', icon: 'consult', href: '/consultations', match: ['/consultations', '/appointment/'] },
    { label: 'Расписание', icon: 'settings', href: '/schedule' },
    { label: 'Мои отзывы', icon: 'star', href: '/my/reviews' },
  ],
  developer: [
    { label: 'Мои задачи', icon: 'dev', href: '/dev/my' },
    { label: 'Проекты', icon: 'folder', href: '/dev/projects', match: ['/dev/projects', '/project/'] },
    { label: 'Трудозатраты', icon: 'clock', href: '/dev/timelogs' },
  ],
  support: [
    { label: 'Заявки', icon: 'support', href: '/tickets', match: ['/tickets', '/ticket/'], badge: 'tickets' },
    { label: 'Шаблоны ответов', icon: 'mail', href: '/templates' },
    { label: 'Клиенты', icon: 'clients', href: '/clients', match: ['/clients', '/client/'] },
  ],
  manager: [
    { label: 'Сводка', icon: 'dashboard', href: '/dashboard' },
    { label: 'Консалтинг', icon: 'consult', href: '/journal', match: ['/journal', '/appointment/'] },
    { label: 'Разработка', icon: 'dev', href: '/dev/projects', match: ['/dev/projects', '/project/'] },
    { label: 'Техподдержка', icon: 'support', href: '/tickets', match: ['/tickets', '/ticket/', '/templates'] },
    { label: 'Клиенты', icon: 'clients', href: '/clients', match: ['/clients', '/client/'] },
    { label: 'Отзывы', icon: 'star', href: '/reviews' },
    { label: 'Отчёты', icon: 'reports', href: '/reports' },
  ],
  tech_director: [
    { label: 'Сводка', icon: 'dashboard', href: '/dashboard' },
    { label: 'Консалтинг', icon: 'consult', href: '/journal', match: ['/journal', '/appointment/'] },
    { label: 'Разработка', icon: 'dev', href: '/dev/projects', match: ['/dev/projects', '/project/', '/dev/'] },
    { label: 'Техподдержка', icon: 'support', href: '/tickets', match: ['/tickets', '/ticket/', '/templates'] },
    { label: 'Клиенты', icon: 'clients', href: '/clients', match: ['/clients', '/client/'] },
    { label: 'Отчёты', icon: 'reports', href: '/reports' },
  ],
  admin: [
    { label: 'Пользователи', icon: 'user', href: '/admin/users' },
    { label: 'Справочники', icon: 'list', href: '/admin/directories' },
    { label: 'Настройки', icon: 'settings', href: '/admin/settings' },
    { label: 'Журнал действий', icon: 'log', href: '/admin/log' },
    { label: 'Резервные копии и cron', icon: 'db', href: '/admin/backups' },
  ],
};
const ROLE_ORDER = ['admin', 'tech_director', 'manager', 'support', 'developer', 'consultant', 'client'];

export function navFor(user) {
  const seen = new Set();
  const items = [];
  for (const r of ROLE_ORDER) {
    if (!user.roles.includes(r)) continue;
    for (const it of NAV[r] || []) {
      if (seen.has(it.href)) continue;
      seen.add(it.href);
      items.push(it);
    }
  }
  return items;
}
export const primaryRole = (user) => ROLE_ORDER.find((r) => user.roles.includes(r));
export const homeFor = (user) => {
  const r = primaryRole(user);
  return r === 'client' ? '/home' : (NAV[r] || [{ href: '/profile' }])[0].href;
};
export const has = (...roles) => !!state.user && roles.some((r) => state.user.roles.includes(r));

// ------------------------------------------------------------ маршруты
// [шаблон, модуль, функция, {auth, roles, layout}]
const MGMT = ['manager', 'tech_director', 'admin'];
const ROUTES = [
  ['/', 'public', 'landing', { layout: 'public' }],
  ['/login', 'auth', 'login', { layout: 'auth' }],
  ['/register', 'auth', 'register', { layout: 'auth' }],
  ['/forgot', 'auth', 'forgot', { layout: 'auth' }],
  ['/reset', 'auth', 'reset', { layout: 'auth' }],
  ['/verify', 'auth', 'verify', { layout: 'auth' }],
  ['/book', 'booking', 'booking', { layout: 'mixed' }],
  ['/pay/:id', 'appointments', 'pay', { auth: true }],
  ['/home', 'people', 'clientHome', { auth: true, roles: ['client'] }],
  ['/my/appointments', 'appointments', 'myAppointments', { auth: true }],
  ['/consultations', 'appointments', 'myAppointments', { auth: true, roles: ['consultant'] }],
  ['/journal', 'appointments', 'journal', { auth: true, roles: MGMT }],
  ['/appointment/:id', 'appointments', 'detail', { auth: true }],
  ['/calendar', 'consultant', 'calendar', { auth: true, roles: ['consultant'] }],
  ['/schedule', 'consultant', 'schedule', { auth: true, roles: ['consultant', 'manager', 'admin'] }],
  ['/my/reviews', 'consultant', 'myReviews', { auth: true, roles: ['consultant'] }],
  ['/reviews', 'consultant', 'moderation', { auth: true, roles: ['manager', 'admin'] }],
  ['/support', 'support', 'tickets', { auth: true, roles: ['client'] }],
  ['/tickets', 'support', 'tickets', { auth: true, roles: ['support', 'manager', 'tech_director', 'admin', 'developer'] }],
  ['/ticket/:id', 'support', 'ticket', { auth: true }],
  ['/templates', 'support', 'templates', { auth: true, roles: ['support', 'manager', 'admin', 'tech_director'] }],
  ['/my/projects', 'dev', 'projects', { auth: true, roles: ['client'] }],
  ['/dev/projects', 'dev', 'projects', { auth: true, roles: ['developer', ...MGMT] }],
  ['/project/:id', 'dev', 'project', { auth: true }],
  ['/task/:id', 'dev', 'taskRedirect', { auth: true }],
  ['/dev/my', 'dev', 'myTasks', { auth: true, roles: ['developer', ...MGMT] }],
  ['/dev/timelogs', 'dev', 'timelogs', { auth: true, roles: ['developer', ...MGMT] }],
  ['/clients', 'people', 'clients', { auth: true, roles: ['support', ...MGMT] }],
  ['/client/:id', 'people', 'clientCard', { auth: true, roles: ['support', ...MGMT] }],
  ['/profile', 'people', 'profile', { auth: true }],
  ['/notifications', 'people', 'notifications', { auth: true }],
  ['/dashboard', 'analytics', 'dashboard', { auth: true, roles: MGMT }],
  ['/reports', 'analytics', 'reports', { auth: true, roles: MGMT }],
  ['/admin/users', 'admin', 'users', { auth: true, roles: ['admin'] }],
  ['/admin/directories', 'admin', 'directories', { auth: true, roles: ['admin'] }],
  ['/admin/settings', 'admin', 'settings', { auth: true, roles: ['admin'] }],
  ['/admin/log', 'admin', 'log', { auth: true, roles: ['admin'] }],
  ['/admin/backups', 'admin', 'backups', { auth: true, roles: ['admin'] }],
].map(([pattern, mod, fn, opt]) => ({
  re: new RegExp('^' + pattern.replace(/:(\w+)/g, '(?<$1>[^/]+)') + '$'), mod, fn, opt,
}));

export function parseHash() {
  const raw = location.hash.replace(/^#/, '') || '/';
  const [path, q] = raw.split('?');
  return { path: path.replace(/\/+$/, '') || '/', query: Object.fromEntries(new URLSearchParams(q || '')) };
}
export const go = (path) => { if (location.hash === '#' + path) window.dispatchEvent(new HashChangeEvent('hashchange')); else location.hash = path; };
export const replace = (path) => { history.replaceState(null, '', '#' + path); window.dispatchEvent(new HashChangeEvent('hashchange')); };

let cleanup = [];
let shell = null; // {root, title, sub, nav, ...}
let renderToken = 0;

async function render() {
  const token = ++renderToken;
  cleanup.forEach((f) => { try { f(); } catch (e) { /* noop */ } });
  cleanup = [];
  document.getElementById('side')?.classList.remove('open');
  const { path, query } = parseHash();
  const route = ROUTES.find((r) => r.re.test(path));
  const user = state.user;

  if (!route) return showMessage(user, notFound('Страница не найдена'), 'Страница не найдена');
  const params = { ...(path.match(route.re).groups || {}) };
  const opt = route.opt;

  if (opt.auth && !user) return go('/login?next=' + encodeURIComponent(path + (location.hash.includes('?') ? '?' + location.hash.split('?')[1] : '')));
  if (opt.roles && user && !opt.roles.some((r) => user.roles.includes(r))) {
    return showMessage(user, notFound('Нет доступа к этому разделу'), 'Нет доступа');
  }
  if (path === '/' && user) return go(homeFor(user));
  if ((opt.layout === 'auth') && user && path !== '/verify' && path !== '/reset') return go(query.next || homeFor(user));

  const mod = await import(`./pages/${route.mod}.js`);
  if (token !== renderToken) return;
  const layout = opt.layout === 'mixed' ? (user ? 'app' : 'public') : (opt.layout || 'app');
  const root = h('div', { class: layout === 'app' ? 'content' : layout === 'public' ? 'pub-body' : '' }, spinner());
  const meta = { title: '', sub: '' };
  const ctx = {
    root, params, query, user, path,
    header: (title, sub) => { meta.title = title; meta.sub = sub || ''; if (shell) shell.setHeader(title, sub); document.title = title + ' — ТрайтэкСервис'; },
    go, replace, onLeave: (f) => cleanup.push(f), reload: render,
  };
  if (layout === 'app') showShell(user, route, root, meta); else if (layout === 'public') showPublic(root); else mount(app, root);
  try {
    await mod[route.fn](ctx);
  } catch (e) {
    if (token !== renderToken) return;
    console.error(e);
    mount(root, h('div', { class: 'card pad' }, h('h2', null, 'Не удалось загрузить страницу'), h('p', { class: 'muted mt8' }, e.message || String(e)),
      h('button', { class: 'btn mt16', onClick: render }, 'Повторить')));
  }
  window.scrollTo(0, 0);
}

function showMessage(user, node, title) {
  const root = h('div', { class: user ? 'content' : 'pub-body' }, node);
  if (user) showShell(user, null, root, { title }); else showPublic(root);
}

function notFound(text) {
  return h('div', { class: 'card pad center' }, h('h2', null, text), h('p', { class: 'muted mt8' }, 'Проверьте адрес или вернитесь на главную.'),
    h('a', { class: 'btn mt16', href: '#/' }, 'На главную'));
}

// ------------------------------------------------------------ оболочки
function showPublic(root) {
  const user = state.user;
  mount(app,
    h('header', { class: 'pub-head' }, h('div', { class: 'in' },
      brand(false),
      h('nav', null, h('a', { href: '#/book' }, 'Записаться'), h('a', { href: '#/' }, 'О сервисе')),
      h('div', { class: 'sp' }),
      user ? h('a', { class: 'btn', href: '#' + homeFor(user) }, 'Личный кабинет')
        : [h('a', { class: 'btn secondary', href: '#/login' }, 'Войти'), h('a', { class: 'btn', href: '#/register' }, 'Регистрация')])),
    root);
}

function brand(light = true) {
  return h('a', { class: 'brand', href: '#/' },
    h('span', { class: 'brand-mark' }, 'T'),
    h('span', null, h('div', { class: 'brand-name' }, 'ТрайтэкСервис'), h('div', { class: 'brand-sub' }, 'Технический блок')));
}

function showShell(user, route, root, meta) {
  const nav = navFor(user);
  const { path } = parseHash();
  const isActive = (it) => {
    const list = it.match || [it.href];
    return list.some((m) => (m.endsWith('/') ? path.startsWith(m) : path === m || path.startsWith(m + '/')));
  };
  const active = nav.find(isActive);
  const title = h('h1', null, meta.title || '');
  const sub = h('div', { class: 'sub' }, meta.sub || '');
  const bell = h('button', { class: 'icon-btn', 'aria-label': 'Уведомления', id: 'bell', onClick: (e) => { e.stopPropagation(); toggleNotifications(); } }, icon('bell'), h('span', { class: 'dot', id: 'bell-dot', hidden: true }));
  const searchInput = h('input', { type: 'search', placeholder: 'Поиск по номеру, теме, клиенту', 'aria-label': 'Поиск', onKeydown: (e) => { if (e.key === 'Enter') globalSearch(e.target.value.trim()); } });
  const side = h('aside', { class: 'sidebar', id: 'side' },
    h('div', null,
      brand(),
      h('div', { class: 'nav-label' }, 'РАЗДЕЛЫ'),
      h('nav', { class: 'nav' }, nav.map((it) => h('a', { href: '#' + it.href, class: it === active ? 'active' : '' }, icon(it.icon), it.label)),
        h('a', { href: '#/profile', class: path === '/profile' ? 'active' : '' }, icon('user'), 'Профиль'))),
    h('div', { class: 'side-foot' }, 'ООО «Трайтэк»', h('br'), 'ТрайтэкСервис · версия 1.0'));
  mount(app, h('div', { class: 'shell' }, side, h('div', { class: 'main' },
    h('header', { class: 'topbar' },
      h('div', { class: 'row' },
        h('button', { class: 'icon-btn menu-btn', 'aria-label': 'Меню', onClick: () => side.classList.toggle('open') }, icon('menu')),
        h('div', null, title, sub)),
      h('div', { class: 'topbar-right' },
        h('div', { class: 'search' }, icon('search'), searchInput),
        h('div', { style: { position: 'relative' } }, bell),
        h('div', { class: 'user-chip' }, avatar(user.name),
          h('div', { class: 'who' }, h('div', { class: 'nm' }, user.first_name + ' ' + user.last_name), h('div', { class: 'rl' }, ROLE_NAME[primaryRole(user)]))),
        h('button', { class: 'btn secondary', onClick: logout }, 'Выйти'))),
    root)));
  shell = { setHeader: (t, s) => { title.textContent = t || ''; sub.textContent = s || ''; } };
  if (meta.title) shell.setHeader(meta.title, meta.sub);
  refreshUnread();
}

async function logout() {
  try { const d = await api.post('/api/auth/logout'); api.setCsrf(d.csrf); } catch (e) { /* noop */ }
  state.user = null;
  go('/login');
}

function globalSearch(q) {
  if (!q) return;
  const u = state.user;
  let m;
  if ((m = q.match(/^(?:ТП|TP|тп)[-\s]?0*(\d+)$/i))) return go('/ticket/' + m[1]);
  if ((m = q.match(/^TS[-\s]?(\d+)$/i))) return go('/task/' + m[1]);
  if ((m = q.match(/^\d{4}-0*(\d+)$/))) return go('/appointment/' + m[1]);
  if (has('support', 'manager', 'tech_director', 'admin')) return go('/tickets?q=' + encodeURIComponent(q));
  if (has('client')) return go('/support?q=' + encodeURIComponent(q));
  if (has('developer')) return go('/dev/my?q=' + encodeURIComponent(q));
  toast('Ничего не найдено');
}

// ------------------------------------------------------------ уведомления
async function refreshUnread() {
  if (!state.user) return;
  try {
    const d = await api.get('/api/notifications', { limit: 1 });
    const dot = document.getElementById('bell-dot');
    if (dot) dot.hidden = !d.unread;
    state.user.unread = d.unread;
  } catch (e) { /* noop */ }
}
setInterval(refreshUnread, 60000);

export function notifLink(n) {
  if (n.ticket_id) return '/ticket/' + n.ticket_id;
  if (n.task_id) return '/task/' + n.task_id;
  if (n.appointment_id) return '/appointment/' + n.appointment_id;
  return '/notifications';
}

async function toggleNotifications() {
  const old = document.getElementById('ntf-pop');
  if (old) { old.remove(); return; }
  const pop = h('div', { class: 'popover', id: 'ntf-pop' }, spinner());
  document.getElementById('bell').parentElement.append(pop);
  const close = (e) => { if (!pop.contains(e.target)) { pop.remove(); document.removeEventListener('click', close); } };
  setTimeout(() => document.addEventListener('click', close), 0);
  try {
    const d = await api.get('/api/notifications', { limit: 12 });
    mount(pop,
      h('div', { class: 'card-h' }, h('h3', null, 'Уведомления'), h('button', { class: 'link-btn', onClick: async () => { await api.post('/api/notifications/read-all'); pop.remove(); refreshUnread(); } }, 'Прочитать все')),
      d.items.length ? d.items.map((n) => h('a', {
        class: 'ntf' + (n.is_read ? '' : ' unread'), href: '#' + notifLink(n),
        onClick: async () => { pop.remove(); api.post(`/api/notifications/${n.id}/read`).then(refreshUnread); },
      }, h('div', { class: 'tt' }, n.title), n.body ? h('div', { class: 'bd' }, n.body) : null, h('div', { class: 'bd' }, fmtDateTime(n.created_at))))
        : h('div', { class: 'empty' }, 'Уведомлений нет'),
      h('a', { class: 'ntf center', href: '#/notifications', onClick: () => pop.remove() }, 'Все уведомления'));
  } catch (e) { toastErr(e); pop.remove(); }
}

// ------------------------------------------------------------ загрузка
export async function loadMe() {
  const d = await api.get('/api/auth/me');
  api.setCsrf(d.csrf);
  state.user = d.user ? { ...d.user } : null;
  state.demo = !!d.demo;
  return state.user;
}
export function setUser(d) { api.setCsrf(d.csrf); state.user = d.user ? { ...d.user } : null; }

window.addEventListener('hashchange', render);
window.addEventListener('tt:unauth', () => { state.user = null; go('/login'); });

(async function init() {
  try {
    await loadMe();
  } catch (e) {
    mount(app, h('div', { class: 'card pad', style: { margin: '60px auto', maxWidth: '480px' } }, h('h2', null, 'Сервер недоступен'), h('p', { class: 'muted mt8' }, e.message)));
    return;
  }
  render();
})();
