// Публичная главная страница
import * as api from '../api.js';
import { h, mount, icon, fmtMoney, stars, avatar, spinner, pluralize } from '../ui.js';

export async function landing(ctx) {
  ctx.header('Главная');
  document.title = 'ТрайтэкСервис — консалтинг, разработка, техподдержка';
  mount(ctx.root, spinner());
  const [allSpecs, cons, svcs] = await Promise.all([api.get('/api/specializations'), api.get('/api/consultants'), api.get('/api/services')]);
  const specs = allSpecs.filter((s) => s.services_count > 0);
  mount(ctx.root,
    h('section', { class: 'hero' },
      h('div', null,
        h('h1', null, 'Консультации, разработка и поддержка для вашего бизнеса'),
        h('p', null, 'Запишитесь к эксперту на онлайн-консультацию, превратите её итоги в проект и получайте поддержку со строгим контролем сроков.'),
        h('div', { class: 'row wrap' }, h('a', { class: 'btn accent', href: '#/book' }, 'Записаться на консультацию'), h('a', { class: 'btn secondary', href: '#/register' }, 'Создать аккаунт'))),
      h('div', { class: 'stats' },
        h('div', { class: 'stat' }, h('b', null, cons.length), 'консультанта'),
        h('div', { class: 'stat' }, h('b', null, svcs.length), 'услуг в каталоге'),
        h('div', { class: 'stat' }, h('b', null, '24/7'), 'приём заявок в поддержку'),
        h('div', { class: 'stat' }, h('b', null, 'SLA'), 'контроль сроков реакции'))),
    h('div', { class: 'grid c3' },
      [['consult', 'Консалтинг', 'Онлайн-консультации с выбором времени, оплатой картой, видеовстречей и письменным заключением.'],
        ['dev', 'Разработка', 'Проекты по итогам консультаций: доска задач, учёт времени и прогресс, который видит заказчик.'],
        ['support', 'Техническая поддержка', 'Заявки с приоритетами и сроками SLA, автоназначением специалиста и эскалацией в разработку.']].map(([i, t, d]) =>
        h('div', { class: 'card mod-card' }, h('div', { class: 'ico' }, icon(i)), h('h3', null, t), h('p', { class: 'muted' }, d)))),
    h('div', null, h('h2', { class: 'mb8' }, 'Направления консультаций'),
      h('div', { class: 'grid c4' }, specs.map((s) => h('div', { class: 'card pad col gap-8' }, h('h3', null, s.name), h('p', { class: 'muted small' }, s.description),
        h('div', { class: 'small muted' }, `${s.services_count} ${pluralize(s.services_count, 'услуга', 'услуги', 'услуг')} · ${s.consultants_count} ${pluralize(s.consultants_count, 'консультант', 'консультанта', 'консультантов')}`),
        h('a', { href: '#/book?spec=' + s.id }, 'Выбрать →'))))),
    h('div', null, h('h2', { class: 'mb8' }, 'Наши консультанты'),
      h('div', { class: 'grid c3' }, cons.map((c) => h('div', { class: 'card pad row top' },
        avatar(c.name, 'md soft'),
        h('div', { class: 'col gap-4' }, h('b', null, c.name), h('span', { class: 'muted small' }, c.position),
          h('span', null, c.reviews_count ? [stars(c.rating), ` ${c.rating.toFixed(1)} · ${c.reviews_count} отзывов`] : 'Новый консультант'),
          h('p', { class: 'small muted' }, c.bio), h('a', { href: '#/book?consultant=' + c.id }, 'Записаться →')))))));
}
