// UI-хелперы: построитель DOM, иконки, форматирование, модальные окна, таблицы
import { ApiError } from './api.js';

// ---------------------------------------------------------------- DOM
export function h(tag, attrs, ...children) {
  const el = document.createElement(tag);
  if (attrs) {
    for (const [k, v] of Object.entries(attrs)) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
      else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2).toLowerCase(), v);
      else if (k === 'value') el.value = v;
      else if (k === 'checked' || k === 'disabled' || k === 'selected' || k === 'hidden' || k === 'required' || k === 'draggable') el[k] = !!v;
      else if (k === 'html') el.innerHTML = v; // только для статических SVG
      else if (k === 'data') Object.assign(el.dataset, v);
      else el.setAttribute(k, v === true ? '' : v);
    }
  }
  append(el, children);
  return el;
}

function append(el, children) {
  for (const c of children.flat(Infinity)) {
    if (c === null || c === undefined || c === false) continue;
    el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
}

export function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); return el; }
export function mount(el, ...children) { clear(el); append(el, children); return el; }

// ---------------------------------------------------------------- иконки
const P = {
  dashboard: '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
  consult: '<rect x="2" y="6" width="14" height="12" rx="2"/><path d="M16 10l6-3v10l-6-3z"/>',
  dev: '<path d="M8 6l-6 6 6 6M16 6l6 6-6 6"/>',
  support: '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><path d="M4.9 4.9l4.2 4.2M14.9 14.9l4.2 4.2M14.9 9.1l4.2-4.2M4.9 19.1l4.2-4.2"/>',
  clients: '<circle cx="9" cy="8" r="4"/><path d="M2 20c0-3.3 3.1-6 7-6s7 2.7 7 6"/><path d="M16 4.5a4 4 0 010 7M18 14c2.4.7 4 2.5 4 4.5"/>',
  reports: '<path d="M3 3v18h18"/><path d="M8 17v-5M13 17V8M18 17v-9"/>',
  settings: '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
  calendar: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 10h18M8 2v4M16 2v4"/>',
  folder: '<path d="M3 6a2 2 0 012-2h4l2 2h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
  bell: '<path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 01-3.4 0"/>',
  clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
  filter: '<path d="M3 4h18l-7 8v6l-4 2v-8z"/>',
  paperclip: '<path d="M21.4 11.1l-9.2 9.2a5.5 5.5 0 01-7.8-7.8l9.2-9.2a3.7 3.7 0 015.2 5.2l-9.2 9.2a1.8 1.8 0 01-2.6-2.6l8.5-8.5"/>',
  chevron: '<path d="M6 9l6 6 6-6"/>',
  check: '<path d="M4 12l5 5L20 6"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  x: '<path d="M6 6l12 12M18 6L6 18"/>',
  arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
  user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/>',
  home: '<path d="M3 11l9-8 9 8M5 10v10h14V10"/>',
  edit: '<path d="M4 20h4L19 9l-4-4L4 16zM14 6l4 4"/>',
  trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
  download: '<path d="M12 3v12M7 10l5 5 5-5M4 20h16"/>',
  star: '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
  lock: '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 018 0v3"/>',
  mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
  video: '<rect x="2" y="6" width="14" height="12" rx="2"/><path d="M16 10l6-3v10l-6-3z"/>',
  alert: '<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18h.01"/>',
  db: '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
  list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
  shield: '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
  log: '<path d="M6 3h9l4 4v14H6zM14 3v5h5M9 13h7M9 17h7"/>',
  logout: '<path d="M9 4H5a2 2 0 00-2 2v12a2 2 0 002 2h4M16 8l4 4-4 4M20 12H9"/>',
  menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
  refresh: '<path d="M20 11a8 8 0 10-2.3 6M20 4v7h-7"/>',
  link: '<path d="M10 14a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1 1M14 10a4 4 0 00-5.7 0l-3 3a4 4 0 005.7 5.7l1-1"/>',
};
export function icon(name, cls) {
  return h('span', { class: 'ico' + (cls ? ' ' + cls : ''), style: { display: 'inline-flex' }, html: `<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${P[name] || ''}</svg>` });
}
export function svgIcon(name) {
  return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${P[name] || ''}</svg>`;
}

// ---------------------------------------------------------------- словари статусов
export const APP_STATUS = {
  pending_payment: ['Ожидает оплаты', 'warning'], confirmed: ['Подтверждена', 'info'], completed: ['Проведена', 'success'],
  no_show: ['Неявка', 'danger'], cancelled: ['Отменена', ''],
};
export const TICKET_STATUS = {
  new: ['Новая', 'purple'], accepted: ['Принята в работу', 'info'], in_progress: ['В работе', 'info'],
  waiting_client: ['Ожидание клиента', 'warning'], resolved: ['Решена', 'success'], closed: ['Закрыта', ''],
};
export const PRIORITY = { low: ['Низкий', ''], medium: ['Средний', 'info'], high: ['Высокий', 'warning'], critical: ['Критический', 'danger'] };
export const PROJECT_STATUS = {
  planned: ['Планируется', ''], in_progress: ['В работе', 'info'], testing: ['Тестирование', 'purple'],
  delivered: ['Сдан', 'success'], support: ['На сопровождении', 'warning'], closed: ['Закрыт', ''],
};
export const TASK_STATUS = { backlog: 'Backlog', in_progress: 'В работе', testing: 'Тестирование', done: 'Выполнена' };
export const TASK_TYPE = { feature: 'Функция', bug: 'Ошибка', improvement: 'Улучшение', research: 'Исследование' };
export const CHANNEL = { web: 'веб-форма', email: 'e-mail', phone: 'телефон' };
export const ROLE_NAME = {
  client: 'Клиент', consultant: 'Консультант', developer: 'Разработчик', support: 'Специалист ТП',
  manager: 'Менеджер', tech_director: 'Технический директор', admin: 'Администратор',
};
export const MEMBER_ROLE = { lead: 'Руководитель', developer: 'Разработчик', tester: 'Тестировщик', analyst: 'Аналитик' };

export function badge(text, tone, nodot) {
  return h('span', { class: 'badge' + (tone ? ' ' + tone : '') + (nodot ? ' nodot' : '') }, text);
}
export const appBadge = (code) => badge(...(APP_STATUS[code] || [code, '']));
export const ticketBadge = (code) => badge(...(TICKET_STATUS[code] || [code, '']));
export const prioBadge = (code) => badge(...(PRIORITY[code] || [code, '']));
export const projectBadge = (code) => badge(...(PROJECT_STATUS[code] || [code, '']));

// ---------------------------------------------------------------- форматирование
const pad = (n) => String(n).padStart(2, '0');
export function parseDate(s) {
  if (!s) return null;
  if (s instanceof Date) return s;
  const m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/);
  if (!m) return new Date(s);
  return new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0));
}
export const fmtDate = (s) => { const d = parseDate(s); return d ? `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()}` : '—'; };
export const fmtShort = (s) => { const d = parseDate(s); return d ? `${pad(d.getDate())}.${pad(d.getMonth() + 1)}` : '—'; };
export const fmtTime = (s) => { const d = parseDate(s); return d ? `${pad(d.getHours())}:${pad(d.getMinutes())}` : ''; };
export const fmtDateTime = (s) => { const d = parseDate(s); return d ? `${fmtDate(d)}, ${fmtTime(d)}` : '—'; };
export const MONTHS = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
export const MONTHS_GEN = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
export const DOW = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];
export const DOW_CAP = DOW.map((d) => d[0].toUpperCase() + d.slice(1));
export const DOW_SHORT = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
export const fmtLong = (s) => { const d = parseDate(s); return `${d.getDate()} ${MONTHS_GEN[d.getMonth()]} ${d.getFullYear()}, ${DOW_SHORT[d.getDay()].toLowerCase()}`; };
export const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
export const todayIso = () => iso(new Date());
export function addDays(d, n) { const x = new Date(d); x.setDate(x.getDate() + n); return x; }
export const fmtMoney = (v) => new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(+v || 0).replace(/ /g, ' ') + ' ₽';
export const fmtNum = (v) => new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 }).format(+v || 0);
export function fmtSize(b) { b = +b; return b < 1024 ? b + ' Б' : b < 1048576 ? Math.round(b / 1024) + ' КБ' : (b / 1048576).toFixed(1) + ' МБ'; }
/** 125 → «2 ч 05 мин», 2000 → «1 д 9 ч» */
export function fmtDur(min) {
  min = Math.abs(Math.round(min));
  const d = Math.floor(min / 1440); const hh = Math.floor((min % 1440) / 60); const m = min % 60;
  if (d > 0) return `${d} д${hh ? ' ' + hh + ' ч' : ''}`;
  if (hh > 0) return `${hh} ч${m ? ' ' + pad(m) + ' мин' : ''}`;
  return `${m} мин`;
}
export const initials = (name) => (name || '?').split(/\s+/).slice(0, 2).map((x) => x[0]).join('').toUpperCase();
/** «Белов Игорь» → «И. Белов» */
export const shortName = (n) => { const p = String(n || '').trim().split(/\s+/); return p.length >= 2 ? `${p[1][0]}. ${p[0]}` : (n || ''); };
/** «Белов Игорь» → «Игорь Белов» */
export const personName = (n) => { const p = String(n || '').trim().split(/\s+/); return p.length >= 2 ? `${p[1]} ${p[0]}${p[2] ? ' ' + p.slice(2).join(' ') : ''}` : (n || ''); };
export const pluralize = (n, a, b, c) => { n = Math.abs(n) % 100; const k = n % 10; return n > 10 && n < 20 ? c : k > 1 && k < 5 ? b : k === 1 ? a : c; };

// ---------------------------------------------------------------- сообщения
export function toast(msg, type) {
  const box = document.getElementById('toasts');
  const t = h('div', { class: 'toast ' + (type || '') }, msg);
  box.append(t);
  setTimeout(() => t.remove(), type === 'err' ? 6000 : 3500);
}
export const toastErr = (e) => toast(e instanceof ApiError ? e.message : (e && e.message) || 'Ошибка', 'err');

/** Выполняет действие, показывая ошибку тостом; возвращает результат или undefined. */
export async function guard(fn, okMsg) {
  try {
    const r = await fn();
    if (okMsg) toast(okMsg, 'ok');
    return r;
  } catch (e) {
    toastErr(e);
    return undefined;
  }
}

// ---------------------------------------------------------------- модальные окна
export function modal({ title, body, footer, size, onClose }) {
  const close = () => { ov.remove(); document.removeEventListener('keydown', esc); if (onClose) onClose(); };
  const esc = (e) => { if (e.key === 'Escape') close(); };
  const ov = h('div', { class: 'overlay', onMousedown: (e) => { if (e.target === ov) close(); } },
    h('div', { class: 'modal ' + (size || ''), role: 'dialog', 'aria-modal': 'true' },
      h('div', { class: 'modal-h' }, h('h2', null, title), h('button', { class: 'icon-btn', 'aria-label': 'Закрыть', onClick: close }, icon('x'))),
      h('div', { class: 'modal-b' }, body),
      footer && footer.length ? h('div', { class: 'modal-f' }, footer) : null));
  document.addEventListener('keydown', esc);
  document.body.append(ov);
  const first = ov.querySelector('input, textarea, select');
  if (first) first.focus();
  return { close, el: ov };
}

export function confirmDialog(message, { title = 'Подтверждение', ok = 'Подтвердить', danger = false } = {}) {
  return new Promise((resolve) => {
    let done = false;
    const fin = (v) => { if (!done) { done = true; resolve(v); } };
    const m = modal({
      title, onClose: () => fin(false),
      body: h('p', { style: { whiteSpace: 'pre-line' } }, message),
      footer: [
        h('button', { class: 'btn secondary', onClick: () => { fin(false); m.close(); } }, 'Отмена'),
        h('button', { class: 'btn ' + (danger ? 'danger-solid' : ''), onClick: () => { fin(true); m.close(); } }, ok),
      ],
    });
  });
}

// ---------------------------------------------------------------- формы
export function field(label, control, { hint, error, full } = {}) {
  return h('div', { class: 'field' + (full ? ' full' : '') }, label ? h('label', null, label) : null, control, hint ? h('div', { class: 'hint' }, hint) : null, h('div', { class: 'err', hidden: !error }, error || ''));
}
export function select(options, value, attrs) {
  const s = h('select', attrs, options.map((o) => {
    const opt = typeof o === 'object' ? o : { value: o, label: o };
    return h('option', { value: opt.value, selected: String(opt.value) === String(value ?? '') }, opt.label);
  }));
  if (value !== undefined && value !== null) s.value = String(value);
  return s;
}
export function formData(form) {
  const out = {};
  for (const el of form.querySelectorAll('[name]')) {
    if (el.type === 'checkbox') {
      if (el.name.endsWith('[]')) { (out[el.name.slice(0, -2)] ||= []); if (el.checked) out[el.name.slice(0, -2)].push(el.value); } else out[el.name] = el.checked;
    } else if (el.type === 'radio') { if (el.checked) out[el.name] = el.value; } else if (el.type === 'number') out[el.name] = el.value === '' ? null : el.value;
    else out[el.name] = el.value;
  }
  return out;
}
export function showErrors(form, err) {
  for (const el of form.querySelectorAll('.invalid')) el.classList.remove('invalid');
  for (const el of form.querySelectorAll('.field .err')) { el.hidden = true; el.textContent = ''; }
  const fields = err && err.fields ? err.fields : {};
  let any = false;
  for (const [name, msg] of Object.entries(fields)) {
    const el = form.querySelector(`[name="${name}"]`);
    if (!el) continue;
    any = true;
    el.classList.add('invalid');
    const holder = el.closest('.field');
    const box = holder && holder.querySelector('.err');
    if (box) { box.textContent = msg; box.hidden = false; }
  }
  if (!any) toastErr(err);
}

// ---------------------------------------------------------------- таблицы и пагинация
/** columns: [{label, render(row)|key, cls}] */
export function dataTable(columns, rows, { onRow, rowClass, empty = 'Ничего не найдено' } = {}) {
  if (!rows.length) return h('div', { class: 'empty' }, empty);
  return h('div', { class: 'table-wrap' }, h('table', { class: 'tbl' },
    h('thead', null, h('tr', null, columns.map((c) => h('th', { class: c.cls || '' }, c.label)))),
    h('tbody', null, rows.map((r) => h('tr', {
      class: (onRow ? 'click ' : '') + ((rowClass && rowClass(r)) || ''),
      onClick: onRow ? (e) => { if (!e.target.closest('a,button,input,select')) onRow(r); } : null,
    }, columns.map((c) => h('td', { class: c.cls || '' }, typeof c.render === 'function' ? c.render(r) : r[c.key])))))));
}

export function pager({ page, limit, total, onPage }) {
  const pages = Math.max(1, Math.ceil(total / limit));
  const from = total ? (page - 1) * limit + 1 : 0;
  const to = Math.min(total, page * limit);
  const nums = [];
  const add = (p) => nums.push(h('button', { class: p === page ? 'active' : '', onClick: () => onPage(p) }, p));
  const dots = () => nums.push(h('button', { disabled: true }, '…'));
  if (pages <= 7) { for (let i = 1; i <= pages; i++) add(i); } else {
    add(1);
    if (page > 3) dots();
    for (let i = Math.max(2, page - 1); i <= Math.min(pages - 1, page + 1); i++) add(i);
    if (page < pages - 2) dots();
    add(pages);
  }
  return h('div', { class: 'pager' },
    h('span', null, `${from}–${to} из ${total}`),
    pages > 1 ? h('div', { class: 'pages' },
      h('button', { disabled: page <= 1, onClick: () => onPage(page - 1) }, '‹'), nums,
      h('button', { disabled: page >= pages, onClick: () => onPage(page + 1) }, '›')) : null);
}

export function tabs(items, active, onChange, cls) {
  return h('div', { class: cls || 'tabs' }, items.map((t) => h('button', {
    class: 'tab' + (t.key === active ? ' active' : ''), onClick: () => onChange(t.key),
  }, t.label, t.count !== undefined && t.count !== null ? h('span', { class: 'count' + (t.red && t.count ? ' red' : '') }, t.count) : null)));
}

export function progressBar(pct, tone, lg) {
  return h('div', { class: 'bar ' + (tone || '') + (lg ? ' lg' : '') }, h('i', { style: { width: Math.max(0, Math.min(100, pct)) + '%' } }));
}

export function kv(label, value) { return h('div', { class: 'kv' }, h('span', null, label), h('span', null, value)); }
export function avatar(name, cls) { return h('span', { class: 'avatar ' + (cls || '') }, initials(name)); }
export function spinner() { return h('div', { class: 'spinner' }); }
export function emptyState(text) { return h('div', { class: 'empty' }, text); }
export function stars(rating) {
  const r = Math.round(+rating || 0);
  return h('span', { class: 'stars' }, '★'.repeat(r) + '☆'.repeat(5 - r));
}
export function debounce(fn, ms = 300) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }

/** Скачивание защищённого файла (с cookie) через ссылку. */
export function downloadLink(href, label, cls) {
  return h('a', { href, class: cls || 'chip-file', download: '' }, icon('paperclip'), label);
}
