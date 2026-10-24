// Разработка: проекты, доска задач (drag-and-drop), карточка задачи, трудозатраты
import * as api from '../api.js';
import { state, has, go, replace } from '../app.js';
import {
  h, mount, icon, spinner, badge, projectBadge, prioBadge, fmtDate, fmtShort, fmtDateTime, fmtNum, fmtSize, dataTable, tabs, field, select, kv, toast, toastErr, modal, confirmDialog, guard,
  avatar, progressBar, debounce, PROJECT_STATUS, TASK_STATUS, TASK_TYPE, MEMBER_ROLE, formData, showErrors, emptyState, todayIso, iso, addDays, shortName, PRIORITY, pluralize,
} from '../ui.js';

const MGMT = ['manager', 'tech_director', 'admin'];

// ------------------------------------------------------------------ список проектов
export async function projects(ctx) {
  const isClient = ctx.path === '/my/projects';
  ctx.header(isClient ? 'Мои проекты' : 'Разработка', isClient ? 'Проекты по итогам консультаций' : 'Проекты и ход работ');
  const f = { status: '', q: '' };
  const host = h('div', { class: 'col gap-16' });
  mount(ctx.root, host);
  async function newProject() {
    const [clients, devs] = await Promise.all([api.get('/api/clients', { limit: 100 }), api.get('/api/developers')]);
    const form = h('form', { class: 'col gap-16', novalidate: true },
      field('Название *', h('input', { type: 'text', name: 'name' })),
      field('Заказчик *', select([{ value: '', label: '— выберите клиента —' }, ...clients.items.map((c) => ({ value: c.id, label: `${c.company || c.name} · ${c.name}` }))], '', { name: 'client_id' })),
      field('Описание', h('textarea', { name: 'description', rows: 3 })),
      h('div', { class: 'form-grid' }, field('Начало', h('input', { type: 'date', name: 'start_date', value: todayIso() })), field('Окончание', h('input', { type: 'date', name: 'end_date' }))),
      field('Руководитель проекта', select([{ value: '', label: 'Я (создатель)' }, ...devs.map((d) => ({ value: d.id, label: d.name }))], '', { name: 'owner_id' })));
    const m = modal({ title: 'Новый проект', body: form, footer: [h('button', { class: 'btn secondary', onClick: () => m.close() }, 'Отмена'), h('button', { class: 'btn', onClick: async () => {
      const d = formData(form); d.client_id = d.client_id ? +d.client_id : null; d.owner_id = d.owner_id ? +d.owner_id : undefined;
      try { const p = await api.post('/api/projects', d); m.close(); toast('Проект создан', 'ok'); go('/project/' + p.id); } catch (e) { showErrors(form, e); }
    } }, 'Создать')] });
  }
  async function draw() {
    const list = await api.get('/api/projects', f);
    mount(host,
      h('div', { class: 'row between wrap' }, h('div', { class: 'filters' }, select([{ value: '', label: 'Статус: все' }, ...Object.entries(PROJECT_STATUS).map(([v, [l]]) => ({ value: v, label: l }))], f.status, { onChange: (e) => { f.status = e.target.value; draw(); } }),
        h('input', { type: 'search', placeholder: 'Название или заказчик', value: f.q, onInput: debounce((e) => { f.q = e.target.value; draw(); }) })),
        has(...MGMT) ? h('button', { class: 'btn', onClick: newProject }, icon('plus'), 'Новый проект') : null),
      list.length ? h('div', { class: 'grid c2' }, list.map((p) => h('div', { class: 'card pad col gap-8', style: { cursor: 'pointer' }, onClick: () => go('/project/' + p.id) },
        h('div', { class: 'row between' }, h('h3', null, p.name), projectBadge(p.status)),
        h('div', { class: 'muted small' }, `${p.client_name} · руководитель ${shortName(p.owner_name)} · ${fmtDate(p.start_date)}${p.end_date ? ' — ' + fmtDate(p.end_date) : ''}`),
        h('div', { class: 'row between small' }, h('span', { class: 'muted' }, 'Прогресс по оценке'), h('b', null, p.progress.percent + ' %')),
        progressBar(p.progress.percent, p.progress.overdue ? 'orange' : ''),
        h('div', { class: 'row between small muted' }, h('span', null, `${p.progress.tasks_done} из ${p.progress.tasks_total} задач · ${p.members_count} ${pluralize(p.members_count, 'участник', 'участника', 'участников')}`),
          p.progress.overdue ? h('span', { style: { color: 'var(--danger)' } }, `просрочено: ${p.progress.overdue}`) : null)))) : emptyState('Проектов нет'));
  }
  mount(host, spinner());
  await draw();
}

// ------------------------------------------------------------------ карточка проекта и доска
export async function project(ctx) {
  const pid = ctx.params.id;
  let data; let tab = 'board';
  const filt = { assignee_id: '', type: '' };
  let dragging = null;
  const host = h('div', { class: 'col gap-16' });
  mount(ctx.root, spinner());

  async function load() {
    data = await api.get(`/api/projects/${pid}/board`, filt);
    const p = data.project;
    ctx.header(p.name, `Проекты / ${p.name} / ${{ board: 'Доска задач', list: 'Список', time: 'Трудозатраты', members: 'Участники' }[tab]}`);
    draw();
  }

  function header() {
    const p = data.project; const pr = p.progress;
    return h('div', { class: 'card pad row between wrap', style: { gap: '24px' } },
      h('div', { class: 'col gap-8 grow' },
        h('div', { class: 'row gap-8' }, h('h2', { style: { fontSize: '22px' } }, p.name), projectBadge(p.status), p.can_manage ? h('button', { class: 'btn ghost sm', onClick: edit }, icon('edit'), 'Изменить') : null),
        h('div', { class: 'muted' }, `Заказчик ${p.client_name} · руководитель ${shortName(p.owner_name)} · ${fmtDate(p.start_date)}${p.end_date ? ' — ' + fmtDate(p.end_date) : ''} · ${p.members.length} ${pluralize(p.members.length, 'участник', 'участника', 'участников')}`),
        p.description ? h('div', { class: 'small' }, p.description) : null,
        p.appointment_id ? h('a', { href: '#/appointment/' + p.appointment_id, class: 'row gap-8' }, icon('calendar'), `Создан по итогам консультации № ${p.appointment_number} от ${fmtDate(p.appointment_date)}`) : null),
      h('div', { style: { minWidth: '320px', flex: '0 1 380px' } }, h('div', { class: 'row between' }, h('span', { class: 'muted' }, 'Прогресс проекта'), h('b', { style: { fontSize: '20px' } }, pr.percent + ' %')),
        progressBar(pr.percent, '', true), h('div', { class: 'muted small mt8' }, `${fmtNum(pr.est_done)} из ${fmtNum(pr.est_total)} ч по оценке выполненных задач · ${pr.tasks_done} из ${pr.tasks_total} задач`),
        pr.logged_hours ? h('div', { class: 'muted small' }, `Списано времени: ${fmtNum(pr.logged_hours)} ч`) : null));
  }

  function card(t) {
    const done = t.status === 'done'; const over = +t.overdue === 1; const fromTicket = !!t.ticket_id;
    const est = +t.estimate_hours; const logged = +t.logged_hours;
    const tone = fromTicket ? 'red' : over ? 'orange' : '';
    const typeBadge = fromTicket ? badge('Ошибка · из ТП', 'danger') : badge(TASK_TYPE[t.type], '');
    return h('div', { class: 'tcard' + (fromTicket ? ' ticket' : '') + (over ? ' overdue' : ''), draggable: data.project.can_work, data: { id: t.id },
      onDragstart: (e) => { dragging = t; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(t.id)); setTimeout(() => e.target.classList.add('dragging'), 0); },
      onDragend: (e) => { e.target.classList.remove('dragging'); document.querySelectorAll('.col-board.over').forEach((c) => c.classList.remove('over')); dragging = null; },
      onClick: () => openTask(t.id) },
    h('div', { class: 'top' }, h('span', null, t.number), over && !fromTicket ? badge('Просрочена', 'warning') : typeBadge),
    h('div', { class: 'ttl' }, t.title),
    progressBar(est ? Math.min(100, logged * 100 / est) : (done ? 100 : 0), done && !fromTicket ? '' : tone),
    h('div', { class: 'ft' }, h('div', { class: 'who' }, t.assignee_id ? h('span', { class: 'avatar sm soft', title: t.assignee_name }, t.assignee_initials) : h('span', { class: 'avatar sm gray' }, '?'),
      done ? h('span', null, 'закрыта ' + fmtShort(t.completed_at || t.updated_at)) : over ? h('span', { class: 'over-t' }, 'Просрочено · ' + fmtShort(t.due_date)) : t.due_date ? h('span', null, 'до ' + fmtShort(t.due_date)) : h('span', null, 'без срока'),
      t.ticket_number ? h('span', null, '· ' + t.ticket_number) : null),
    h('span', null, `${fmtNum(logged)} / ${fmtNum(est)} ч`)));
  }

  function board() {
    const can = data.project.can_work;
    return h('div', null, h('div', { class: 'board' }, data.columns.map((c) => {
      const col = h('div', { class: 'col-board s-' + c.status, data: { status: c.status },
        onDragover: can ? (e) => { e.preventDefault(); col.classList.add('over'); } : null,
        onDragleave: can ? (e) => { if (!col.contains(e.relatedTarget)) col.classList.remove('over'); } : null,
        onDrop: can ? async (e) => {
          e.preventDefault(); col.classList.remove('over');
          if (!dragging) return;
          const cards = [...col.querySelectorAll('.tcard:not(.dragging)')];
          let idx = cards.findIndex((el) => e.clientY < el.getBoundingClientRect().top + el.offsetHeight / 2);
          if (idx < 0) idx = cards.length;
          const ids = cards.map((el) => +el.dataset.id);
          ids.splice(idx, 0, dragging.id);
          const moved = dragging; dragging = null;
          const r = await guard(() => api.post(`/api/tasks/${moved.id}/move`, { status: c.status, order: ids }));
          if (r && moved.status !== c.status && r.status === 'done' && r.ticket_id) toast('Эскалация выполнена: специалист поддержки уведомлён', 'ok');
          load();
        } : null },
      h('div', { class: 'ch' }, h('b', null, h('i'), c.name, h('span', { class: 'count' }, c.count)), h('span', { class: 'muted small' }, fmtNum(c.hours) + ' ч')),
      c.tasks.map(card), can ? h('div', { class: 'drop-hint', style: { display: c.tasks.length ? 'none' : 'block' } }, 'Перетащите карточку сюда') : null);
      return col;
    })),
    h('div', { class: 'legend mt16' }, h('span', null, h('i', { style: { background: 'var(--danger)' } }), 'Задача создана из заявки техподдержки (эскалация)'), h('span', null, h('i', { style: { background: 'var(--warning)' } }), 'Просрочена — выделяется автоматически'), h('span', null, h('i', { style: { background: 'var(--primary)' } }), 'Перетаскивание меняет статус, изменение пишется в историю')));
  }

  function list() {
    const all = data.columns.flatMap((c) => c.tasks);
    return h('div', { class: 'card' }, dataTable([
      { label: 'Номер', render: (t) => h('span', { class: 'num' }, t.number) },
      { label: 'Задача', render: (t) => [t.title, t.ticket_number ? h('div', { class: 'sub', style: { color: 'var(--danger)' } }, 'из заявки ' + t.ticket_number) : null] },
      { label: 'Тип', render: (t) => TASK_TYPE[t.type] },
      { label: 'Приоритет', render: (t) => prioBadge(t.priority_code) },
      { label: 'Статус', render: (t) => badge(TASK_STATUS[t.status], { done: 'success', testing: 'purple', in_progress: 'info', backlog: '' }[t.status]) },
      { label: 'Исполнитель', render: (t) => t.assignee_name ? shortName(t.assignee_name) : '—' },
      { label: 'Срок', render: (t) => +t.overdue ? h('span', { style: { color: 'var(--danger)', fontWeight: 600 } }, fmtDate(t.due_date)) : fmtDate(t.due_date) },
      { label: 'Факт / оценка', render: (t) => `${fmtNum(t.logged_hours)} / ${fmtNum(t.estimate_hours)} ч`, cls: 'nowrap' },
    ], all, { onRow: (t) => openTask(t.id), empty: 'Задач нет' }));
  }

  async function timeTab() {
    const box = h('div', { class: 'card' }, spinner());
    const d = await api.get('/api/timelogs', { project_id: pid, from: '2000-01-01', to: todayIso() });
    mount(box, h('div', { class: 'card-h' }, h('h2', null, 'Трудозатраты по проекту'), h('b', null, `Всего: ${fmtNum(d.total_hours)} ч`)),
      dataTable([{ label: 'Дата', render: (x) => fmtDate(x.work_date) }, { label: 'Сотрудник', render: (x) => shortName(x.user_name) }, { label: 'Задача', render: (x) => [h('span', { class: 'num' }, x.task_number), ' ', x.task_title] }, { label: 'Комментарий', render: (x) => x.comment || '—' }, { label: 'Часы', render: (x) => fmtNum(x.hours), cls: 'right' }], d.items, { empty: 'Время ещё не списывалось' }));
    return box;
  }

  function members() {
    const p = data.project;
    return h('div', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, 'Участники проекта'), p.can_manage ? h('button', { class: 'btn sm', onClick: addMember }, icon('plus'), 'Добавить') : null),
      dataTable([{ label: 'Сотрудник', render: (m) => h('span', { class: 'row gap-8' }, avatar(m.name, 'sm soft'), m.name) }, { label: 'Роль', render: (m) => MEMBER_ROLE[m.role_in_project] }, { label: 'Загрузка', render: (m) => m.allocation_percent + ' %' }, { label: 'С', render: (m) => fmtDate(m.joined_at) },
        p.can_manage ? { label: '', render: (m) => h('button', { class: 'btn ghost sm', onClick: async () => { if (await confirmDialog(`Убрать ${m.name} из проекта?`, { danger: true, ok: 'Убрать' })) { await guard(() => api.del(`/api/projects/${pid}/members/${m.user_id}`)); load(); } } }, 'Убрать') } : null].filter(Boolean), p.members, { empty: 'Участников нет' }));
  }

  async function draw() {
    const p = data.project;
    const devOpts = [{ value: '', label: 'Исполнитель: все' }, ...p.members.map((m) => ({ value: m.user_id, label: m.name }))];
    const bar = h('div', { class: 'row between wrap' },
      h('div', { class: 'row wrap' }, tabs([{ key: 'board', label: 'Доска' }, { key: 'list', label: 'Список' }, { key: 'time', label: 'Трудозатраты' }, { key: 'members', label: 'Участники' }], tab, (k) => { tab = k; load(); })),
      h('div', { class: 'row wrap' }, tab === 'board' || tab === 'list' ? [select(devOpts, filt.assignee_id, { onChange: (e) => { filt.assignee_id = e.target.value; load(); }, style: { width: 'auto' } }),
        select([{ value: '', label: 'Тип: все' }, ...Object.entries(TASK_TYPE).map(([v, l]) => ({ value: v, label: l }))], filt.type, { onChange: (e) => { filt.type = e.target.value; load(); }, style: { width: 'auto' } })] : null,
      p.can_work && p.status !== 'closed' ? h('button', { class: 'btn', onClick: () => openTask(null) }, icon('plus'), 'Новая задача') : null));
    const body = tab === 'board' ? board() : tab === 'list' ? list() : tab === 'members' ? members() : h('div', null, spinner());
    mount(host, header(), bar, body);
    if (tab === 'time') mount(host, header(), bar, await timeTab());
  }

  // ----- карточка задачи (модальное окно)
  async function openTask(id) {
    const p = data.project;
    const devs = (await api.get('/api/developers')).map((d) => ({ value: d.id, label: d.name }));
    const prios = (await api.get('/api/ticket-meta')).priorities;
    const t = id ? await api.get('/api/tasks/' + id) : { id: null, title: '', description: '', type: 'feature', priority_id: prios.find((x) => x.code === 'medium').id, assignee_id: '', estimate_hours: 0, due_date: '', status: 'backlog', comments: [], timelogs: [], attachments: [], history: [] };
    const can = id ? t.can_work : p.can_work;
    const form = h('form', { class: 'col gap-16', novalidate: true, onSubmit: (e) => e.preventDefault() },
      field('Название *', h('input', { type: 'text', name: 'title', value: t.title, disabled: !can })),
      h('div', { class: 'form-grid' },
        field('Тип', select(Object.entries(TASK_TYPE).map(([v, l]) => ({ value: v, label: l })), t.type, { name: 'type', disabled: !can })),
        field('Приоритет', select(prios.map((x) => ({ value: x.id, label: x.name })), t.priority_id, { name: 'priority_id', disabled: !can })),
        field('Исполнитель', select([{ value: '', label: 'Не назначен' }, ...devs], t.assignee_id || '', { name: 'assignee_id', disabled: !can })),
        field('Оценка, ч', h('input', { type: 'number', name: 'estimate_hours', min: 0, step: 0.5, value: +t.estimate_hours, disabled: !can })),
        field('Срок', h('input', { type: 'date', name: 'due_date', value: t.due_date || '', disabled: !can })),
        id ? field('Статус', select(Object.entries(TASK_STATUS).map(([v, l]) => ({ value: v, label: l })), t.status, { name: 'status', disabled: !can })) : null),
      field('Описание', h('textarea', { name: 'description', rows: 4, disabled: !can }, t.description || '')));
    const extras = h('div', { class: 'col gap-16' });
    if (id) {
      const cm = h('input', { type: 'text', placeholder: 'Написать комментарий…' });
      const tdate = h('input', { type: 'date', value: todayIso(), max: todayIso(), style: { width: '150px' } });
      const thours = h('input', { type: 'number', step: 0.25, min: 0.25, max: 24, placeholder: 'Часы', style: { width: '90px' } });
      const tcomm = h('input', { type: 'text', placeholder: 'Что сделано' });
      const file = h('input', { type: 'file', onChange: async () => { const fd = new FormData(); fd.append('task_id', id); fd.append('file', file.files[0]); if (await guard(() => api.upload('/api/attachments', fd), 'Файл загружен')) { m.close(); openTask(id); } } });
      mount(extras,
        t.ticket_id ? h('div', { class: 'alert warn row' }, icon('alert'), 'Задача создана из заявки ', h('a', { href: '#/ticket/' + t.ticket_id, onClick: () => m.close() }, t.ticket_number)) : null,
        can ? h('div', { class: 'col gap-8' }, h('h3', null, `Трудозатраты · ${fmtNum(t.logged_hours)} из ${fmtNum(t.estimate_hours)} ч`),
          t.timelogs.map((l) => h('div', { class: 'row between small' }, h('span', null, `${fmtDate(l.work_date)} · ${shortName(l.user_name)}${l.comment ? ' · ' + l.comment : ''}`), h('span', { class: 'row gap-8' }, h('b', null, fmtNum(l.hours) + ' ч'),
            (l.user_id === state.user.id || has(...MGMT)) ? h('button', { class: 'icon-btn', style: { width: '24px', height: '24px' }, title: 'Удалить', onClick: async () => { if (await guard(() => api.del('/api/timelogs/' + l.id))) { m.close(); openTask(id); } } }, icon('x')) : null))),
          h('div', { class: 'row wrap' }, tdate, thours, h('div', { class: 'grow' }, tcomm), h('button', { class: 'btn secondary sm', onClick: async () => { if (await guard(() => api.post(`/api/tasks/${id}/timelogs`, { work_date: tdate.value, hours: thours.value, comment: tcomm.value }), 'Время списано')) { m.close(); openTask(id); } } }, 'Списать'))) : null,
        can ? h('div', { class: 'col gap-8' }, h('h3', null, 'Вложения'), t.attachments.map((a) => h('a', { class: 'chip-file', href: '/api/attachments/' + a.id, download: '' }, icon('paperclip'), a.file_name, h('span', { class: 'muted small' }, fmtSize(a.file_size)))), file) : null,
        can ? h('div', { class: 'col gap-8' }, h('h3', null, 'Комментарии'), t.comments.map((c) => h('div', { class: 'msg' }, h('span', { class: 'avatar sm soft' }, c.author_initials), h('div', { class: 'bubble' }, h('div', { class: 'who' }, h('b', null, c.author_name), h('span', null, fmtDateTime(c.created_at))), h('div', { class: 'text' }, c.text)))),
          h('div', { class: 'row' }, h('div', { class: 'grow' }, cm), h('button', { class: 'btn secondary sm', onClick: async () => { if (cm.value.trim() && await guard(() => api.post(`/api/tasks/${id}/comments`, { text: cm.value }))) { m.close(); openTask(id); } } }, 'Отправить'))) : null,
        h('details', null, h('summary', { class: 'muted', style: { cursor: 'pointer' } }, 'История изменений'), h('div', { class: 'timeline mt8' }, t.history.map((x) => h('div', { class: 'it' }, h('div', null, x.comment || `Статус: ${TASK_STATUS[x.new_status]}`), h('div', { class: 'm' }, fmtDateTime(x.changed_at) + ' · ' + (x.user_name || 'система')))))));
    }
    const m = modal({
      title: id ? `${t.number} · ${p.name}` : 'Новая задача', size: 'lg', onClose: () => { if (ctx.query.task) replace(`/project/${pid}`); },
      body: h('div', { class: 'col gap-16' }, form, extras),
      footer: can ? [h('button', { class: 'btn secondary', onClick: () => m.close() }, 'Закрыть'), h('button', { class: 'btn', onClick: async () => {
        const d = formData(form);
        d.estimate_hours = d.estimate_hours || 0; d.assignee_id = d.assignee_id ? +d.assignee_id : null; d.priority_id = +d.priority_id; d.due_date = d.due_date || null;
        try {
          if (id) { const status = d.status; delete d.status; await api.put('/api/tasks/' + id, d); if (status !== t.status) await api.post(`/api/tasks/${id}/move`, { status }); } else { d.project_id = +pid; await api.post('/api/tasks', d); }
          m.close(); toast('Сохранено', 'ok'); load();
        } catch (e) { showErrors(form, e); }
      } }, id ? 'Сохранить' : 'Создать задачу')] : [h('button', { class: 'btn secondary', onClick: () => m.close() }, 'Закрыть')],
    });
  }

  async function edit() {
    const p = data.project;
    const devs = await api.get('/api/developers');
    const form = h('form', { class: 'col gap-16', novalidate: true },
      field('Название', h('input', { type: 'text', name: 'name', value: p.name })), field('Описание', h('textarea', { name: 'description', rows: 3 }, p.description || '')),
      h('div', { class: 'form-grid' }, field('Статус', select(Object.entries(PROJECT_STATUS).map(([v, [l]]) => ({ value: v, label: l })), p.status, { name: 'status' })), field('Руководитель', select(devs.map((d) => ({ value: d.id, label: d.name })), p.owner_id, { name: 'owner_id' })),
        field('Начало', h('input', { type: 'date', name: 'start_date', value: p.start_date })), field('Окончание', h('input', { type: 'date', name: 'end_date', value: p.end_date || '' }))));
    const m = modal({ title: 'Редактирование проекта', body: form, footer: [h('button', { class: 'btn secondary', onClick: () => m.close() }, 'Отмена'), h('button', { class: 'btn', onClick: async () => {
      const d = formData(form); d.owner_id = +d.owner_id;
      try { await api.put('/api/projects/' + pid, d); m.close(); toast('Проект обновлён', 'ok'); load(); } catch (e) { showErrors(form, e); }
    } }, 'Сохранить')] });
  }

  async function addMember() {
    const devs = (await api.get('/api/developers')).filter((d) => !data.project.members.some((m) => m.user_id === d.id));
    const form = h('form', { class: 'col gap-16' }, field('Разработчик', select(devs.map((d) => ({ value: d.id, label: d.name })), '', { name: 'user_id' })),
      field('Роль', select(Object.entries(MEMBER_ROLE).map(([v, l]) => ({ value: v, label: l })), 'developer', { name: 'role_in_project' })), field('Загрузка, %', h('input', { type: 'number', name: 'allocation_percent', min: 0, max: 100, value: 50 })));
    const m = modal({ title: 'Добавить участника', body: form, footer: [h('button', { class: 'btn secondary', onClick: () => m.close() }, 'Отмена'), h('button', { class: 'btn', onClick: async () => {
      const d = formData(form);
      if (await guard(() => api.post(`/api/projects/${pid}/members`, { ...d, user_id: +d.user_id, allocation_percent: +d.allocation_percent }), 'Участник добавлен')) { m.close(); load(); }
    } }, 'Добавить')] });
  }

  mount(ctx.root, host);
  await load();
  if (ctx.query.task) openTask(+ctx.query.task);
}

export async function taskRedirect(ctx) {
  mount(ctx.root, spinner());
  const t = await api.get('/api/tasks/' + ctx.params.id);
  replace(`/project/${t.project_id}?task=${t.id}`);
}

// ------------------------------------------------------------------ мои задачи
export async function myTasks(ctx) {
  ctx.header('Мои задачи', 'Задачи, назначенные на вас, по всем проектам');
  const host = h('div', { class: 'col gap-16' });
  mount(ctx.root, host);
  let q = ctx.query.q || '';
  let dragging = null;
  async function load() {
    const d = await api.get('/api/tasks', { assignee_id: 'me', limit: 200, q });
    const cols = Object.entries(TASK_STATUS).map(([status, name]) => ({ status, name, tasks: d.items.filter((t) => t.status === status) }));
    mount(host,
      h('div', { class: 'row between wrap' }, h('div', { class: 'filters' }, h('input', { type: 'search', placeholder: 'Поиск по задачам', value: q, onInput: debounce((e) => { q = e.target.value; load(); }) })),
        h('span', { class: 'muted' }, `${d.total} задач · просрочено ${d.items.filter((t) => +t.overdue).length}`)),
      h('div', { class: 'board' }, cols.map((c) => {
        const col = h('div', { class: 'col-board s-' + c.status, onDragover: (e) => { e.preventDefault(); col.classList.add('over'); }, onDragleave: (e) => { if (!col.contains(e.relatedTarget)) col.classList.remove('over'); },
          onDrop: async (e) => { e.preventDefault(); col.classList.remove('over'); if (!dragging) return; const t = dragging; dragging = null; if (t.status !== c.status && await guard(() => api.post(`/api/tasks/${t.id}/move`, { status: c.status }))) load(); } },
        h('div', { class: 'ch' }, h('b', null, h('i'), c.name, h('span', { class: 'count' }, c.tasks.length)), h('span', { class: 'muted small' }, fmtNum(c.tasks.reduce((s, t) => s + +t.estimate_hours, 0)) + ' ч')),
        c.tasks.map((t) => {
          const over = +t.overdue === 1; const fromTicket = !!t.ticket_id; const est = +t.estimate_hours; const logged = +t.logged_hours;
          return h('div', { class: 'tcard' + (fromTicket ? ' ticket' : ''), draggable: true, onDragstart: (e) => { dragging = t; e.dataTransfer.setData('text/plain', t.id); e.target.classList.add('dragging'); }, onDragend: (e) => e.target.classList.remove('dragging'), onClick: () => go(`/project/${t.project_id}?task=${t.id}`) },
            h('div', { class: 'top' }, h('span', null, `${t.number} · ${t.project_name}`), fromTicket ? badge('Ошибка · из ТП', 'danger') : over ? badge('Просрочена', 'warning') : badge(TASK_TYPE[t.type])),
            h('div', { class: 'ttl' }, t.title), progressBar(est ? Math.min(100, logged * 100 / est) : 0, fromTicket ? 'red' : over ? 'orange' : ''),
            h('div', { class: 'ft' }, h('span', { class: over ? 'over-t' : '' }, t.status === 'done' ? 'закрыта ' + fmtShort(t.completed_at) : t.due_date ? (over ? 'Просрочено · ' : 'до ') + fmtShort(t.due_date) : 'без срока'), h('span', null, `${fmtNum(logged)} / ${fmtNum(est)} ч`)));
        }));
        return col;
      })));
  }
  mount(host, spinner());
  await load();
}

// ------------------------------------------------------------------ трудозатраты
export async function timelogs(ctx) {
  ctx.header('Трудозатраты', 'Учёт времени по задачам');
  let from = iso(addDays(new Date(), -((new Date().getDay() + 6) % 7)));
  let to = todayIso();
  const host = h('div', { class: 'col gap-16' });
  mount(ctx.root, host);
  async function logTime() {
    const d = await api.get('/api/tasks', { assignee_id: 'me', limit: 200 });
    const open = d.items.filter((t) => t.status !== 'done');
    if (!open.length) return toast('Нет открытых задач, на которые можно списать время');
    const form = h('form', { class: 'col gap-16' }, field('Задача', select(open.map((t) => ({ value: t.id, label: `${t.number} · ${t.title}` })), open[0].id, { name: 'task' })),
      h('div', { class: 'form-grid' }, field('Дата', h('input', { type: 'date', name: 'work_date', value: todayIso(), max: todayIso() })), field('Часы', h('input', { type: 'number', name: 'hours', step: 0.25, min: 0.25, max: 24, value: 1 }))),
      field('Комментарий', h('input', { type: 'text', name: 'comment' })));
    const m = modal({ title: 'Списать время', body: form, footer: [h('button', { class: 'btn secondary', onClick: () => m.close() }, 'Отмена'), h('button', { class: 'btn', onClick: async () => {
      const f = formData(form);
      try { await api.post(`/api/tasks/${f.task}/timelogs`, { work_date: f.work_date, hours: f.hours, comment: f.comment }); m.close(); toast('Время списано', 'ok'); draw(); } catch (e) { showErrors(form, e); }
    } }, 'Списать')] });
  }
  async function draw() {
    const d = await api.get('/api/timelogs', { from, to });
    const days = Object.entries(d.by_day).sort((a, b) => b[0].localeCompare(a[0]));
    mount(host,
      h('div', { class: 'row between wrap' }, h('div', { class: 'filters' }, h('input', { type: 'date', value: from, onChange: (e) => { from = e.target.value; draw(); } }), '—', h('input', { type: 'date', value: to, onChange: (e) => { to = e.target.value; draw(); } }),
        h('button', { class: 'btn secondary sm', onClick: () => { from = iso(addDays(new Date(), -((new Date().getDay() + 6) % 7))); to = todayIso(); draw(); } }, 'Эта неделя'), h('button', { class: 'btn secondary sm', onClick: () => { from = iso(new Date(new Date().getFullYear(), new Date().getMonth(), 1)); to = todayIso(); draw(); } }, 'Этот месяц')),
        h('button', { class: 'btn', onClick: logTime }, icon('plus'), 'Списать время')),
      h('div', { class: 'grid c3' }, h('div', { class: 'card kpi' }, h('div', { class: 'l' }, h('i'), 'Всего за период'), h('div', { class: 'v' }, fmtNum(d.total_hours) + ' ч')),
        h('div', { class: 'card kpi' }, h('div', { class: 'l' }, h('i'), 'Рабочих дней с записями'), h('div', { class: 'v' }, days.length)), h('div', { class: 'card kpi' }, h('div', { class: 'l' }, h('i'), 'В среднем за день'), h('div', { class: 'v' }, days.length ? fmtNum(d.total_hours / days.length) + ' ч' : '—'))),
      h('div', { class: 'card' }, dataTable([{ label: 'Дата', render: (x) => fmtDate(x.work_date) }, { label: 'Задача', render: (x) => [h('a', { class: 'num', href: `#/project/${x.project_id}?task=${x.task_id}` }, x.task_number), ' ', x.task_title] }, { label: 'Проект', render: (x) => x.project_name },
        has(...MGMT) ? { label: 'Сотрудник', render: (x) => shortName(x.user_name) } : null, { label: 'Комментарий', render: (x) => x.comment || '—' }, { label: 'Часы', render: (x) => fmtNum(x.hours), cls: 'right' },
        { label: '', render: (x) => h('button', { class: 'btn ghost sm', onClick: async () => { if (await confirmDialog('Удалить запись о времени?', { danger: true, ok: 'Удалить' })) { await guard(() => api.del('/api/timelogs/' + x.id)); draw(); } } }, 'Удалить') }].filter(Boolean), d.items, { empty: 'За период записей нет' })));
  }
  mount(host, spinner());
  await draw();
}
