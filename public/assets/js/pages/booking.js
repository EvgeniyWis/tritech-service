// Мастер записи на консультацию (4 шага) — по макету Figma «Запись на консультацию»
import * as api from '../api.js';
import { state, go, has } from '../app.js';
import { h, mount, icon, badge, spinner, fmtMoney, fmtDur, stars, avatar, toast, toastErr, field, select, kv, MONTHS, DOW_CAP, MONTHS_GEN, iso, todayIso, parseDate, fmtLong, pluralize } from '../ui.js';

const KEY = 'tt_booking';
const load = () => { try { return JSON.parse(sessionStorage.getItem(KEY)) || {}; } catch (e) { return {}; } };
const save = (s) => { try { sessionStorage.setItem(KEY, JSON.stringify(s)); } catch (e) { /* noop */ } };

export async function booking(ctx) {
  ctx.header('Запись на консультацию', 'Шаг 1 из 4 · услуга');
  mount(ctx.root, spinner());
  const [specs, services, consultants] = await Promise.all([api.get('/api/specializations'), api.get('/api/services'), api.get('/api/consultants')]);
  const S = load();
  if (ctx.query.consultant) { S.consultantId = +ctx.query.consultant; }
  if (ctx.query.spec) { S.spec = +ctx.query.spec; }
  let clients = null;
  if (has('manager', 'admin')) clients = (await api.get('/api/clients', { limit: 100 })).items;

  const svc = () => services.find((x) => x.id === S.serviceId);
  const cons = () => consultants.find((x) => x.id === S.consultantId);
  let step = S.serviceId ? (S.consultantId ? (S.date && S.time ? 4 : 3) : 2) : 1;
  if (ctx.query.step) step = +ctx.query.step;
  let monthShift = 0;
  let slotData = null;

  const stepsMeta = () => [
    ['Услуга', svc() ? svc().name : 'Выберите услугу'],
    ['Консультант', cons() ? cons().name : 'Выберите эксперта'],
    ['Дата и время', S.date && S.time ? `${fmtLong(S.date).split(',')[0]}, ${S.time}` : 'Выберите слот'],
    ['Подтверждение и оплата', 'Карта или СБП'],
  ];

  function stepper() {
    const items = stepsMeta();
    const out = [];
    items.forEach(([t, s], i) => {
      const n = i + 1;
      const cls = n < step ? 'done' : n === step ? 'cur' : '';
      out.push(h('div', { class: 'step ' + cls, style: { cursor: n < step ? 'pointer' : 'default' }, onClick: () => { if (n < step) { step = n; draw(); } } },
        h('div', { class: 'ic' }, n < step ? icon('check') : n), h('div', null, h('div', { class: 'tt' }, t), h('div', { class: 'ss' }, s))));
      if (i < 3) out.push(h('div', { class: 'step-line' + (n < step ? ' done' : '') }));
    });
    return h('div', { class: 'card stepper' }, out);
  }

  function draw() {
    save(S);
    ctx.header('Запись на консультацию', `Шаг ${step} из 4 · ${['услуга', 'консультант', 'дата и время', 'подтверждение и оплата'][step - 1]}`);
    mount(ctx.root, stepper(), [step1, step2, step3, step4][step - 1]());
  }

  // ---- шаг 1: услуга
  function step1() {
    const groups = specs.filter((sp) => (!S.spec || sp.id === S.spec) && services.some((x) => x.specialization_id === sp.id));
    return h('div', { class: 'col gap-16' },
      S.spec ? h('div', { class: 'alert' }, 'Показаны услуги выбранного направления. ', h('button', { class: 'link-btn', onClick: () => { S.spec = null; draw(); } }, 'Показать все')) : null,
      groups.map((sp) => h('div', { class: 'col gap-8' }, h('h3', null, sp.name),
        h('div', { class: 'grid c3' }, services.filter((x) => x.specialization_id === sp.id).map((x) => h('div', { class: 'opt' + (S.serviceId === x.id ? ' sel' : ''), onClick: () => {
          if (S.serviceId !== x.id) { S.serviceId = x.id; S.date = S.time = null; if (cons() && !consultants.find((c) => c.id === S.consultantId && c.specializations.some((s) => s.id === x.specialization_id))) S.consultantId = null; }
          step = 2; draw();
        } },
        h('div', { class: 'grow' }, h('b', null, x.name), h('div', { class: 'muted small' }, x.description), h('div', { class: 'small mt8' }, fmtDur(x.duration_min) + ' · видеовстреча')),
        h('b', { class: 'nowrap' }, fmtMoney(x.price))))))));
  }

  // ---- шаг 2: консультант
  function step2() {
    const list = consultants.filter((c) => c.specializations.some((s) => s.id === svc().specialization_id));
    return h('div', { class: 'col gap-16' }, h('h3', null, 'Консультанты по услуге «' + svc().name + '»'),
      list.length ? h('div', { class: 'grid c3' }, list.map((c) => h('div', { class: 'opt' + (S.consultantId === c.id ? ' sel' : ''), style: { alignItems: 'flex-start' }, onClick: () => {
        if (S.consultantId !== c.id) { S.consultantId = c.id; S.date = S.time = null; }
        step = 3; draw();
      } }, avatar(c.name, 'md soft'), h('div', { class: 'col gap-4' }, h('b', null, c.name), h('span', { class: 'muted small' }, c.position),
        h('span', { class: 'small' }, c.reviews_count ? [stars(c.rating), ` ${c.rating.toFixed(1)} · ${c.reviews_count} ${pluralize(c.reviews_count, 'отзыв', 'отзыва', 'отзывов')}`] : 'Пока без отзывов'),
        h('span', { class: 'small muted' }, `Опыт ${c.experience_years} ${pluralize(c.experience_years, 'год', 'года', 'лет')}`))))) : h('div', { class: 'empty' }, 'Для этой услуги пока нет консультантов'),
      h('div', null, h('button', { class: 'btn secondary', onClick: () => { step = 1; draw(); } }, 'Назад')));
  }

  // ---- шаг 3: дата и время
  function summaryCard(extra) {
    const c = cons(); const s = svc();
    return h('div', { class: 'card pad col gap-8' },
      h('h2', null, 'Ваша запись'),
      h('div', { class: 'row' }, avatar(c.name, 'md soft'), h('div', null, h('b', null, c.name), h('div', { class: 'muted small' }, c.position),
        h('div', { class: 'small' }, c.reviews_count ? [stars(c.rating), ` ${c.rating.toFixed(1)} · ${c.reviews_count} отзывов`] : ''))),
      h('div', { class: 'sep' }),
      kv('Услуга', s.name), kv('Длительность', fmtDur(s.duration_min)),
      kv('Дата', S.date ? fmtLong(S.date) : '—'),
      kv('Время', S.time ? `${S.time} – ${endTime()} МСК` : '—'),
      kv('Формат', 'Видеовстреча Jitsi Meet'),
      h('div', { class: 'sep' }),
      h('div', { class: 'row between' }, h('b', null, 'К оплате'), h('b', { style: { fontSize: '24px' } }, fmtMoney(s.price))),
      h('div', { class: 'alert warn row' }, icon('clock'), 'После перехода к оплате время удерживается за вами 30 минут.'),
      h('div', { class: 'small muted' }, 'Отмена за 24 ч и более — возврат 100 %, от 2 до 24 ч — 50 %, менее 2 ч — без возврата. Перенос — один раз, не позднее чем за 24 ч.'),
      extra);
  }
  const endTime = () => {
    const [hh, mm] = S.time.split(':').map(Number);
    const t = hh * 60 + mm + svc().duration_min;
    return `${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`;
  };

  function step3() {
    const holder = h('div', { class: 'card pad' }, spinner());
    const right = h('div');
    const redrawRight = () => mount(right, summaryCard(h('div', { class: 'row' },
      h('button', { class: 'btn secondary', onClick: () => { step = 2; draw(); } }, 'Назад'),
      h('button', { class: 'btn accent grow', disabled: !(S.date && S.time), onClick: () => { step = 4; draw(); } }, 'Далее: подтверждение'))));
    redrawRight();

    const render = () => {
      const base = new Date(); base.setDate(1); base.setMonth(base.getMonth() + monthShift);
      const y = base.getFullYear(); const m = base.getMonth();
      const first = (new Date(y, m, 1).getDay() + 6) % 7;
      const dim = new Date(y, m + 1, 0).getDate();
      const today = todayIso();
      const cells = [];
      for (let i = 0; i < first; i++) cells.push(h('div', { class: 'd' }));
      for (let d = 1; d <= dim; d++) {
        const ds = iso(new Date(y, m, d));
        const info = slotData.days[ds];
        const has_ = info && info.free > 0;
        cells.push(h('div', { class: 'd' + (has_ ? ' has' : '') + (ds === today ? ' today' : '') + (ds === S.date ? ' sel' : ''), onClick: has_ ? () => { S.date = ds; S.time = null; render(); redrawRight(); save(S); } : null }, d));
      }
      const day = S.date && slotData.days[S.date];
      const cardBody = h('div', { class: 'col gap-16' },
        h('div', { class: 'row between' }, h('h2', null, 'Выберите дату и время'),
          h('div', { class: 'row gap-8' }, h('button', { class: 'btn secondary sm', disabled: monthShift <= 0, onClick: () => { monthShift--; render(); } }, '‹'), h('b', null, `${MONTHS[m]} ${y}`),
            h('button', { class: 'btn secondary sm', disabled: monthShift >= 2, onClick: () => { monthShift++; render(); } }, '›'))),
        h('div', { class: 'grid c2', style: { gap: '28px', alignItems: 'start' } },
          h('div', null, h('div', { class: 'cal' }, ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'].map((x) => h('div', { class: 'dow' }, x)), cells),
            h('div', { class: 'legend mt8' }, h('span', null, h('i', { style: { background: 'var(--primary)' } }), 'выбрано'), h('span', null, h('i', { style: { border: '1px solid var(--border)' } }), 'есть время'), h('span', null, h('i', { style: { border: '1px solid var(--accent)' } }), 'сегодня'))),
          h('div', null, S.date ? [
            h('h3', { style: { fontSize: '18px' } }, fmtLongTitle(S.date)),
            h('p', { class: 'muted small mb8' }, `Свободное время ${cons().name} · длительность ${fmtDur(svc().duration_min)} · время московское (UTC+3)`),
            day && day.slots.length ? h('div', { class: 'slots' }, day.slots.map((s) => h('div', { class: 'slot' + (s.available ? '' : ' busy') + (S.time === s.start ? ' sel' : ''), onClick: s.available ? () => { S.time = s.start; render(); redrawRight(); save(S); } : null }, s.start))) : h('div', { class: 'muted' }, 'В этот день нет слотов'),
            h('p', { class: 'muted small mt8' }, 'Зачёркнутые слоты заняты: между консультациями нужен технический перерыв 10 мин. Запись возможна не позднее чем за 2 часа до начала.')]
            : h('div', { class: 'muted' }, 'Выберите день в календаре, чтобы увидеть свободное время.'))),
        h('div', { class: 'sep' }),
        field('Тема вопроса', h('textarea', { name: 'comment', rows: 3, maxlength: 2000, placeholder: 'Кратко опишите задачу, чтобы консультант подготовился', onInput: (e) => { S.comment = e.target.value; save(S); } }, S.comment || '')));
      mount(holder, cardBody);
    };

    (async () => {
      try {
        const from = todayIso();
        const to = iso(new Date(Date.now() + 89 * 86400000));
        slotData = await api.get('/api/slots', { consultant_id: S.consultantId, service_id: S.serviceId, from, to });
        if (S.date && !(slotData.days[S.date] && slotData.days[S.date].free)) { S.date = S.time = null; }
        if (S.time && S.date && !slotData.days[S.date].slots.find((s) => s.start === S.time && s.available)) S.time = null;
        if (!S.date) { const firstDay = Object.entries(slotData.days).find(([, v]) => v.free > 0); if (firstDay) S.date = firstDay[0]; }
        render(); redrawRight();
      } catch (e) { mount(holder, h('div', { class: 'alert err' }, e.message)); }
    })();
    return h('div', { class: 'split' }, holder, right);
  }

  const fmtLongTitle = (d) => { const x = parseDate(d); const w = DOW_CAP[x.getDay()]; return `${w}, ${x.getDate()} ${MONTHS_GEN[x.getMonth()]}`; };

  // ---- шаг 4: подтверждение
  function step4() {
    if (!S.date || !S.time) { step = 3; return step3(); }
    let agree = false;
    const btn = h('button', { class: 'btn accent grow', disabled: true, onClick: confirm }, 'Подтвердить и перейти к оплате');
    const clientSel = clients ? select([{ value: '', label: '— выберите клиента —' }, ...clients.map((c) => ({ value: c.id, label: `${c.company || c.name} · ${c.name}` }))], S.clientId || '', { onChange: (e) => { S.clientId = e.target.value; save(S); } }) : null;
    async function confirm() {
      if (!state.user) { toast('Войдите или зарегистрируйтесь, чтобы завершить запись'); return go('/login?next=' + encodeURIComponent('/book?step=4')); }
      if (clients && !S.clientId) return toast('Выберите клиента', 'err');
      btn.disabled = true;
      try {
        const a = await api.post('/api/appointments', {
          consultant_id: S.consultantId, service_id: S.serviceId, start_at: `${S.date} ${S.time}:00`, comment: S.comment || '', client_id: clients ? +S.clientId : undefined,
        });
        sessionStorage.removeItem(KEY);
        if (a.can_pay) {
          const u = a.payment.confirmation_url;
          if (u && u.startsWith(location.origin)) go('/pay/' + a.payment.id); else location.href = u;
        } else { toast('Запись подтверждена', 'ok'); go('/appointment/' + a.id); }
      } catch (e) {
        toastErr(e);
        if (e.status === 409) { step = 3; draw(); } else btn.disabled = false;
      }
    }
    const right = summaryCard(h('div', { class: 'col gap-8' },
      clientSel ? field('Клиент', clientSel) : null,
      !state.user ? h('div', { class: 'alert warn' }, 'Для завершения записи нужно войти в аккаунт. Выбранные услуга и время сохранятся.') : null,
      h('label', { class: 'chk' }, h('input', { type: 'checkbox', onChange: (e) => { agree = e.target.checked; btn.disabled = !agree; } }),
        h('span', null, 'Согласен с условиями оказания услуг, правилами отмены и возврата')),
      h('div', { class: 'row' }, h('button', { class: 'btn secondary', onClick: () => { step = 3; draw(); } }, 'Назад'), btn)));
    return h('div', { class: 'split' },
      h('div', { class: 'card pad col gap-16' }, h('h2', null, 'Проверьте детали записи'),
        h('div', { class: 'grid c2' }, kv('Услуга', svc().name), kv('Консультант', cons().name), kv('Дата', fmtLong(S.date)), kv('Время', `${S.time} – ${endTime()} (МСК)`)),
        S.comment ? h('div', null, h('div', { class: 'lbl mb8' }, 'Тема вопроса'), h('div', { class: 'card pad', style: { background: 'var(--bg)' } }, S.comment)) : null,
        h('div', { class: 'alert' }, 'Оплата производится через ЮKassa: банковская карта или СБП. После успешной оплаты запись подтверждается автоматически, ссылка на видеовстречу придёт в уведомлении и на e-mail.')),
      right);
  }

  draw();
}
