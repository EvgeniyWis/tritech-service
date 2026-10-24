// Сводка директора (Figma «01 Сводка директора») и отчёты
import * as api from '../api.js';
import { state, has, go } from '../app.js';
import {
  h, mount, icon, spinner, badge, fmtDate, fmtDur, fmtNum, fmtMoney, fmtDateTime, tabs, select, dataTable, field, toast, progressBar, shortName, todayIso, iso, addDays, MONTHS, emptyState,
} from '../ui.js';

const PERIODS = [['today', 'Сегодня'], ['week', 'Неделя'], ['month', 'Месяц'], ['quarter', 'Квартал']];

export async function dashboard(ctx) {
  const now = new Date();
  ctx.header('Сводка', `Технический блок · ${now.toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}`);
  let period = 'today'; let dept = 'all';
  const host = h('div', { class: 'col gap-16' });
  mount(ctx.root, host);

  async function draw() {
    const d = await api.get('/api/dashboard', { period, dept });
    ctx.header('Сводка', `Технический блок · ${now.toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })} · данные на ${d.updated_at}`);
    const k = d.kpi;
    const kpi = (label, v, note, alertTone, to) => h('a', { class: 'card kpi' + (alertTone ? ' alert-tone' : ''), href: '#' + to, style: { color: 'inherit', textDecoration: 'none' } }, h('div', { class: 'l' }, h('i'), label), h('div', { class: 'v' }, v), h('div', { class: 'n' }, note));
    const to = () => { const x = new Date(d.period.to); x.setDate(x.getDate() - 1); return iso(x); };
    const riskTime = (r) => r.remaining_min < 0 ? h('span', { style: { color: 'var(--danger)', fontWeight: 600 } }, '+' + fmtDur(r.remaining_min)) : h('span', { style: { color: r.state === 'warning' ? 'var(--warning)' : 'var(--text)', fontWeight: 600 } }, 'ещё ' + fmtDur(r.remaining_min));
    const link = (r) => r.kind === 'ticket' ? '/ticket/' + r.id : r.kind === 'task' ? '/task/' + r.id : '/appointment/' + r.id;
    const barTone = (p) => p >= 90 ? 'orange' : '';
    const periodName = { today: 'сегодня', week: 'неделя', month: MONTHS[now.getMonth()].toLowerCase(), quarter: 'квартал' }[period];
    mount(host,
      h('div', { class: 'row between wrap' }, tabs(PERIODS.map(([key, label]) => ({ key, label })), period, (p) => { period = p; draw(); }),
        h('div', { class: 'row' }, select([{ value: 'all', label: 'Отдел: все' }, { value: 'consulting', label: 'Консалтинг' }, { value: 'dev', label: 'Разработка' }, { value: 'support', label: 'Техподдержка' }], dept, { onChange: (e) => { dept = e.target.value; draw(); }, style: { width: 'auto' } }),
          h('a', { class: 'btn secondary', href: `/api/reports/summary?format=xlsx&from=${d.period.from}&to=${to()}`, download: '' }, 'Выгрузить в XLSX'))),
      h('div', { class: 'grid c4' },
        kpi('Консультации сегодня', k.consultations_today.value, `из ${k.consultations_today.planned} запланированных · ${k.consultations_today.no_show} неявки`, false, '/journal'),
        kpi('Задачи в работе', k.tasks_in_work.value, `${k.tasks_in_work.projects} проектов · ${k.tasks_in_work.overdue} просрочено`, false, '/dev/projects'),
        kpi('Открытые заявки ТП', k.open_tickets.value, `${k.open_tickets.new_today} новых за сегодня`, false, '/tickets?tab=open'),
        kpi('Нарушения SLA сегодня', k.sla_breaches.value, `в срок за месяц ${String(k.sla_breaches.month_percent).replace('.', ',')} % · цель ≥ ${k.sla_breaches.target} %`, k.sla_breaches.value > 0 || k.sla_breaches.open_breached > 0, '/tickets?tab=breached')),
      h('div', { class: 'split', style: { gridTemplateColumns: 'minmax(0, 1fr) 380px' } },
        h('div', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Просрочено и под угрозой'), h('a', { href: '#/tickets?tab=breached' }, 'Все просрочки →')),
          dataTable([
            { label: 'Объект', render: (r) => [h('a', { class: 'num', href: '#' + link(r) }, r.number), h('div', { class: 'sub' }, r.module)] },
            { label: 'Тема', render: (r) => h('div', { style: { maxWidth: '260px' } }, r.title) },
            { label: 'Исполнитель', render: (r) => r.assignee ? shortName(r.assignee) : '—' },
            { label: 'До срока', render: riskTime, cls: 'nowrap' },
            { label: 'Статус', render: (r) => badge(r.status_name, { new: 'purple', accepted: 'info', in_progress: 'info', testing: 'purple', waiting_client: 'warning', pending_payment: 'warning', backlog: '' }[r.status] || '') },
          ], d.risks, { onRow: (r) => go(link(r)), empty: 'Просрочек и рисков нет — всё в срок' })),
        h('div', { class: 'card pad col gap-16' },
          h('div', { class: 'row between' }, h('h2', null, 'Загрузка отделов'), h('span', { class: 'muted small' }, 'текущая неделя')),
          d.load.map((l) => h('div', { class: 'col gap-4' }, h('div', { class: 'row between' }, h('b', null, l.name), h('b', { style: { color: l.percent >= 90 ? 'var(--warning)' : 'var(--text)' } }, l.percent + ' %')), progressBar(l.percent, barTone(l.percent), true), h('div', { class: 'muted small' }, l.note))),
          h('div', { class: 'sep' }), h('b', null, 'Заявки в срок по приоритетам · месяц'),
          d.sla_by_priority.map((s) => h('div', { class: 'row between' }, badge(s.priority, { critical: 'danger', high: 'warning', medium: 'info', low: '' }[s.code]), h('span', null, String(s.percent).replace('.', ',') + ' %'))))),
      h('div', { class: 'card pad col gap-16' }, h('div', { class: 'row between wrap' }, h('h2', null, `Сквозной процесс · ${periodName}`), h('span', { class: 'muted small' }, 'консультация → проект → задачи → заявки → эскалации')),
        h('div', { class: 'row', style: { gap: '12px', flexWrap: 'wrap' } }, [
          [d.flow.consultations, 'Консультаций проведено', 'var(--primary)'], [d.flow.projects, 'Проектов по итогам', 'var(--primary)'], [d.flow.tasks_done, 'Задач разработки закрыто', 'var(--success)'],
          [d.flow.tickets, 'Заявок в поддержку', 'var(--purple)'], [d.flow.escalations, 'Эскалаций в разработку', 'var(--danger)'],
        ].map(([v, l, c], i, arr) => [h('div', { style: { flex: '1 1 150px', background: 'var(--bg)', borderRadius: '10px', padding: '16px' } }, h('div', { style: { fontSize: '30px', fontWeight: 800, color: c } }, v), h('div', { class: 'muted small' }, l)),
          i < arr.length - 1 ? h('span', { class: 'muted' }, icon('arrow')) : null]))));
  }
  mount(host, spinner());
  await draw();
}

// ------------------------------------------------------------------ отчёты
export async function reports(ctx) {
  ctx.header('Отчёты', 'Выгрузка в XLSX и CSV');
  const types = await api.get('/api/reports');
  let type = ctx.query.type || 'summary';
  let from = iso(new Date(now().getFullYear(), now().getMonth(), 1)); let to = todayIso();
  const host = h('div', { class: 'col gap-16' });
  mount(ctx.root, host);
  function now() { return new Date(); }

  async function draw() {
    mount(host, spinner());
    const rep = await api.get('/api/reports/' + type, { from, to });
    const exp = (fmt) => `/api/reports/${type}?format=${fmt}&from=${from}&to=${to}`;
    const quick = (label, f, t) => h('button', { class: 'btn secondary sm', onClick: () => { from = f; to = t; draw(); } }, label);
    const d = new Date();
    mount(host,
      h('div', { class: 'split', style: { gridTemplateColumns: '260px minmax(0, 1fr)' } },
        h('div', { class: 'card pad col gap-4' }, h('h3', { class: 'mb8' }, 'Тип отчёта'), types.map((t) => h('button', { class: 'tab' + (t.type === type ? ' active' : ''), style: { justifyContent: 'flex-start', textAlign: 'left' }, onClick: () => { type = t.type; draw(); } }, t.title))),
        h('div', { class: 'col gap-16' },
          h('div', { class: 'card pad col gap-16' }, h('div', { class: 'row between wrap' }, h('h2', null, rep.title), h('div', { class: 'row' }, h('a', { class: 'btn', href: exp('xlsx'), download: '' }, icon('download'), 'XLSX'), h('a', { class: 'btn secondary', href: exp('csv'), download: '' }, 'CSV'))),
            h('div', { class: 'filters' }, h('span', { class: 'muted' }, 'Период:'), h('input', { type: 'date', value: from, max: to, onChange: (e) => { from = e.target.value; draw(); } }), '—', h('input', { type: 'date', value: to, min: from, onChange: (e) => { to = e.target.value; draw(); } }),
              quick('Месяц', iso(new Date(d.getFullYear(), d.getMonth(), 1)), todayIso()), quick('30 дней', iso(addDays(d, -30)), todayIso()), quick('Квартал', iso(addDays(d, -90)), todayIso()), quick('Год', iso(new Date(d.getFullYear(), 0, 1)), todayIso())),
            rep.summary && Object.keys(rep.summary).length ? h('div', { class: 'row wrap' }, Object.entries(rep.summary).map(([k, v]) => h('div', { class: 'card pad', style: { background: 'var(--bg)' } }, h('div', { class: 'muted small' }, k), h('b', { style: { fontSize: '20px' } }, typeof v === 'number' ? fmtNum(v) : v)))) : null),
          rep.chart && rep.chart.length ? chart(rep.chart) : null,
          h('div', { class: 'card' }, dataTable(rep.columns.map((c) => ({ label: c.label, render: (r) => { const v = r[c.key]; return v === null || v === undefined || v === '' ? '—' : (!isNaN(v) && typeof v !== 'boolean' && /^-?\d+(\.\d+)?$/.test(String(v)) && !/(^|_)(date)$/.test(c.key) ? fmtNum(v) : v); } })), rep.rows, { empty: 'Нет данных за выбранный период' })))));
  }

  function chart(data) {
    const max = Math.max(...data.map((x) => +x.revenue), 1);
    const W = 640; const H = 180; const bw = Math.min(46, W / data.length - 10);
    const bars = data.map((x, i) => {
      const hh = Math.max(2, (+x.revenue / max) * (H - 40));
      const cx = 20 + i * (W - 40) / data.length + (W - 40) / data.length / 2;
      return `<rect x="${cx - bw / 2}" y="${H - 24 - hh}" width="${bw}" height="${hh}" rx="4" fill="#1F4E9E"/><text x="${cx}" y="${H - 8}" font-size="11" text-anchor="middle" fill="#5B6576">${x.month.slice(5)}.${x.month.slice(2, 4)}</text><text x="${cx}" y="${H - 28 - hh}" font-size="11" text-anchor="middle" fill="#1A2233">${fmtNum(+x.revenue / 1000)}к</text>`;
    }).join('');
    return h('div', { class: 'card pad' }, h('h3', { class: 'mb8' }, 'Выручка по месяцам, ₽'), h('div', { html: `<svg viewBox="0 0 ${W} ${H}" width="100%" height="${H}" role="img" aria-label="Выручка по месяцам">${bars}</svg>` }));
  }
  await draw();
}
