// Консультант: недельный календарь, расписание, отзывы; менеджер: модерация отзывов
import * as api from '../api.js';
import { state, has, go } from '../app.js';
import {
  h, mount, icon, spinner, badge, fmtDate, fmtDateTime, fmtShort, field, select, toast, guard, stars, avatar, iso, addDays, todayIso, parseDate, DOW_SHORT, MONTHS_GEN,
  tabs, confirmDialog, emptyState, fmtNum, APP_STATUS,
} from '../ui.js';

const PPH = 56; // пикселей на час

function mondayOf(d) { const x = new Date(d); x.setHours(0, 0, 0, 0); x.setDate(x.getDate() - ((x.getDay() + 6) % 7)); return x; }
const toMin = (hm) => { const [a, b] = hm.split(':').map(Number); return a * 60 + b; };

export async function calendar(ctx) {
  ctx.header('Календарь', 'Недельное расписание консультаций');
  let monday = mondayOf(new Date());
  const host = h('div', { class: 'col gap-16' });
  mount(ctx.root, host);

  async function draw() {
    mount(host, spinner());
    const sun = addDays(monday, 6);
    const d = await api.get('/api/consultants/me/calendar', { from: iso(monday), to: iso(sun) });
    // границы сетки по расписанию
    let minH = 9; let maxH = 19;
    for (const w of d.weekly) { minH = Math.min(minH, Math.floor(toMin(w.start_time) / 60)); maxH = Math.max(maxH, Math.ceil(toMin(w.end_time) / 60)); }
    for (const e of d.items) { minH = Math.min(minH, parseDate(e.start_at).getHours()); maxH = Math.max(maxH, Math.ceil(parseDate(e.end_at).getHours() + parseDate(e.end_at).getMinutes() / 60)); }
    const height = (maxH - minH) * PPH;
    const today = todayIso();
    const days = Array.from({ length: 7 }, (_, i) => addDays(monday, i));
    const exc = Object.fromEntries(d.exceptions.map((e) => [e.date.slice(0, 10), e]));

    const head = [h('div')];
    days.forEach((dt) => head.push(h('div', { class: 'hd' + (iso(dt) === today ? ' today' : '') }, `${DOW_SHORT[dt.getDay()]} ${dt.getDate()}`, h('small', null, exc[iso(dt)] ? (exc[iso(dt)].is_working ? 'особый день' : exc[iso(dt)].reason || 'выходной') : ''))));
    const timeCol = h('div', { style: { position: 'relative', height: height + 'px' } }, Array.from({ length: maxH - minH + 1 }, (_, i) => h('div', { class: 'tm', style: { position: 'absolute', top: i * PPH - 7 + 'px', right: 0, left: 0 } }, `${String(minH + i).padStart(2, '0')}:00`)));
    const cols = days.map((dt) => {
      const ds = iso(dt);
      const col = h('div', { class: 'colday', style: { height: height + 'px' } });
      for (let i = 0; i <= maxH - minH; i++) col.append(h('div', { class: 'hl', style: { top: i * PPH + 'px' } }));
      let intervals = d.weekly.filter((w) => w.weekday === (dt.getDay() || 7)).map((w) => [toMin(w.start_time), toMin(w.end_time)]);
      if (exc[ds]) intervals = exc[ds].is_working && exc[ds].start_time ? [[toMin(exc[ds].start_time), toMin(exc[ds].end_time)]] : [];
      intervals.sort((a, b) => a[0] - b[0]);
      let cur = minH * 60;
      for (const [s, e] of intervals) { if (s > cur) col.append(h('div', { class: 'off', style: { top: (cur - minH * 60) * PPH / 60 + 'px', height: (s - cur) * PPH / 60 + 'px' } })); cur = Math.max(cur, e); }
      if (cur < maxH * 60) col.append(h('div', { class: 'off', style: { top: (cur - minH * 60) * PPH / 60 + 'px', height: (maxH * 60 - cur) * PPH / 60 + 'px' } }));
      for (const e of d.items.filter((x) => x.start_at.slice(0, 10) === ds)) {
        const s = parseDate(e.start_at); const en = parseDate(e.end_at);
        const top = ((s.getHours() * 60 + s.getMinutes()) - minH * 60) * PPH / 60;
        const hh = Math.max(24, (en - s) / 60000 * PPH / 60 - 2);
        col.append(h('div', { class: 'ev ' + e.status, style: { top: top + 'px', height: hh + 'px' }, title: `${e.service_name} · ${e.client_name}`, onClick: () => go('/appointment/' + e.id) },
          h('b', null, `${String(s.getHours()).padStart(2, '0')}:${String(s.getMinutes()).padStart(2, '0')} ${e.client_company || e.client_name}`), e.service_name));
      }
      if (ds === today) { const n = new Date(); const t = (n.getHours() * 60 + n.getMinutes() - minH * 60) * PPH / 60; if (t > 0 && t < height) col.append(h('div', { class: 'nowline', style: { top: t + 'px' } })); }
      return col;
    });
    const title = `${monday.getDate()} ${monday.getMonth() === sun.getMonth() ? '' : MONTHS_GEN[monday.getMonth()] + ' '}– ${sun.getDate()} ${MONTHS_GEN[sun.getMonth()]} ${sun.getFullYear()}`;
    mount(host,
      h('div', { class: 'row between wrap' },
        h('div', { class: 'row' }, h('button', { class: 'btn secondary sm', onClick: () => { monday = addDays(monday, -7); draw(); } }, '‹'), h('b', { style: { fontSize: '16px' } }, title),
          h('button', { class: 'btn secondary sm', onClick: () => { monday = addDays(monday, 7); draw(); } }, '›'), h('button', { class: 'btn secondary sm', onClick: () => { monday = mondayOf(new Date()); draw(); } }, 'Сегодня')),
        h('div', { class: 'legend' }, h('span', null, h('i', { style: { background: 'var(--primary)' } }), 'подтверждена'), h('span', null, h('i', { style: { background: 'var(--success)' } }), 'проведена'), h('span', null, h('i', { style: { background: 'var(--warning)' } }), 'ожидает оплаты'), h('span', null, h('i', { style: { background: '#C8CFDA' } }), 'нерабочее время'))),
      h('div', { class: 'card', style: { overflowX: 'auto' } }, h('div', { class: 'wk', style: { minWidth: '820px' } }, head, timeCol, cols)));
  }
  await draw();
}

// ------------------------------------------------------------------ расписание
const WD = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'];

export async function schedule(ctx) {
  ctx.header('Расписание', 'Рабочие часы и исключения');
  let cid = 'me';
  const host = h('div', { class: 'col gap-16' });
  let consultants = [];
  if (!state.user.consultant_id) {
    consultants = await api.get('/api/consultants');
    if (!consultants.length) return mount(ctx.root, emptyState('Нет консультантов'));
    cid = String(consultants[0].id);
  }
  mount(ctx.root, host);

  async function draw() {
    mount(host, spinner());
    const d = await api.get(`/api/consultants/${cid}/schedule`);
    const intervals = {}; // weekday → [{start,end}]
    for (const w of d.weekly) (intervals[w.weekday] ||= []).push({ start: w.start_time, end: w.end_time });

    const rows = WD.map((name, i) => {
      const wd = i + 1;
      const list = intervals[wd] || [];
      const box = h('div', { class: 'col gap-8 grow' });
      const redraw = () => {
        mount(box, list.length ? list.map((it, idx) => h('div', { class: 'row wrap' },
          h('input', { type: 'time', value: it.start, style: { width: '120px' }, onChange: (e) => { it.start = e.target.value; } }), '—',
          h('input', { type: 'time', value: it.end, style: { width: '120px' }, onChange: (e) => { it.end = e.target.value; } }),
          h('button', { class: 'icon-btn', title: 'Удалить интервал', onClick: () => { list.splice(idx, 1); redraw(); } }, icon('trash'))))
          : h('span', { class: 'muted' }, 'Выходной'));
      };
      redraw();
      intervals[wd] = list;
      return h('div', { class: 'row top wrap', style: { padding: '10px 0', borderBottom: '1px solid var(--border)' } }, h('b', { style: { width: '130px' } }, name), box,
        h('button', { class: 'btn secondary sm', onClick: () => { list.push({ start: '10:00', end: '18:00' }); redraw(); } }, icon('plus'), 'Интервал'));
    });

    const exForm = h('form', { class: 'filters', onSubmit: async (e) => {
      e.preventDefault();
      const f = e.target;
      const body = { date: f.date.value, is_working: f.kind.value === 'work', start_time: f.start.value || null, end_time: f.end.value || null, reason: f.reason.value };
      if (await guard(() => api.post(`/api/consultants/${cid}/exceptions`, body), 'Исключение добавлено')) draw();
    } },
      h('input', { type: 'date', name: 'date', min: todayIso(), required: true }),
      select([{ value: 'off', label: 'Выходной день' }, { value: 'work', label: 'Особые часы' }], 'off', { name: 'kind' }),
      h('input', { type: 'time', name: 'start', title: 'С', style: { minWidth: '110px' } }), h('input', { type: 'time', name: 'end', title: 'До', style: { minWidth: '110px' } }),
      h('input', { type: 'text', name: 'reason', placeholder: 'Причина', style: { minWidth: '180px' } }), h('button', { class: 'btn', type: 'submit' }, 'Добавить'));

    mount(host,
      consultants.length ? h('div', { class: 'filters' }, select(consultants.map((c) => ({ value: c.id, label: c.name })), cid, { onChange: (e) => { cid = e.target.value; draw(); } })) : null,
      h('div', { class: 'card pad' }, h('div', { class: 'row between' }, h('h2', null, 'Недельное расписание'),
        h('button', { class: 'btn', onClick: async () => {
          const weekly = [];
          for (const [wd, list] of Object.entries(intervals)) for (const it of list) weekly.push({ weekday: +wd, start_time: it.start, end_time: it.end });
          if (await guard(() => api.put(`/api/consultants/${cid}/schedule`, { weekly }), 'Расписание сохранено')) draw();
        } }, 'Сохранить расписание')), h('p', { class: 'muted small mb8' }, 'Слоты формируются с шагом сетки из настроек; при записи учитывается технический перерыв между консультациями.'), rows),
      h('div', { class: 'card pad col gap-16' }, h('h2', null, 'Исключения: выходные и особые дни'), exForm,
        d.exceptions.length ? h('div', { class: 'table-wrap' }, h('table', { class: 'tbl' }, h('thead', null, h('tr', null, ['Дата', 'Тип', 'Время', 'Причина', ''].map((x) => h('th', null, x)))),
          h('tbody', null, d.exceptions.map((e) => h('tr', null, h('td', null, fmtDate(e.date)), h('td', null, e.is_working ? badge('Особые часы', 'info') : badge('Выходной')), h('td', null, e.is_working ? `${e.start_time}–${e.end_time}` : '—'), h('td', null, e.reason || '—'),
            h('td', null, h('button', { class: 'btn ghost sm', onClick: async () => { if (await guard(() => api.del(`/api/consultants/${cid}/exceptions/${e.id}`))) draw(); } }, 'Удалить'))))))) : h('div', { class: 'muted' }, 'Исключений нет')));
  }
  await draw();
}

// ------------------------------------------------------------------ отзывы
export async function myReviews(ctx) {
  ctx.header('Мои отзывы', 'Опубликованные отзывы клиентов');
  mount(ctx.root, spinner());
  const d = await api.get('/api/reviews');
  const avg = d.items.length ? d.items.reduce((s, r) => s + r.rating, 0) / d.items.length : 0;
  mount(ctx.root,
    h('div', { class: 'card pad row' }, h('div', { style: { fontSize: '40px', fontWeight: 800 } }, avg ? fmtNum(avg) : '—'), h('div', null, stars(avg), h('div', { class: 'muted small' }, `${d.items.length} опубликованных отзывов`))),
    d.items.length ? h('div', { class: 'col' }, d.items.map(reviewCard)) : emptyState('Пока нет опубликованных отзывов'));
}

function reviewCard(r, actions) {
  return h('div', { class: 'card pad col gap-8' },
    h('div', { class: 'row between wrap' }, h('div', { class: 'row' }, avatar(r.client_name, 'soft'), h('div', null, h('b', null, r.client_name), h('div', { class: 'muted small' }, `${r.client_company || ''} · ${r.service_name} · консультация № ${r.appointment_number}`))),
      h('div', { class: 'row' }, stars(r.rating), actions ? badge({ pending: 'на модерации', published: 'опубликован', rejected: 'отклонён' }[r.status], { pending: 'warning', published: 'success', rejected: 'danger' }[r.status]) : null)),
    r.text ? h('p', null, r.text) : h('p', { class: 'muted' }, 'Без текста'),
    h('div', { class: 'small muted' }, 'Консультант: ' + (r.consultant_name || '') + ' · ' + fmtDateTime(r.created_at)),
    actions || null);
}

export async function moderation(ctx) {
  ctx.header('Отзывы', 'Модерация отзывов клиентов');
  let tab = 'pending';
  const host = h('div', { class: 'col gap-16' });
  mount(ctx.root, host);
  async function draw() {
    const d = await api.get('/api/reviews', { status: tab });
    mount(host, tabs([{ key: 'pending', label: 'На модерации', count: d.pending }, { key: 'published', label: 'Опубликованные' }, { key: 'rejected', label: 'Отклонённые' }], tab, (k) => { tab = k; draw(); }),
      d.items.length ? d.items.map((r) => reviewCard(r, tab === 'pending' ? h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: async () => { if (await guard(() => api.post(`/api/reviews/${r.id}/moderate`, { status: 'published' }), 'Отзыв опубликован')) draw(); } }, 'Опубликовать'),
        h('button', { class: 'btn danger', onClick: async () => { if (await guard(() => api.post(`/api/reviews/${r.id}/moderate`, { status: 'rejected' }), 'Отзыв отклонён')) draw(); } }, 'Отклонить')) : null)) : emptyState('Отзывов нет'));
  }
  await draw();
}
