// Авторизация: вход, регистрация, восстановление пароля, подтверждение e-mail
import * as api from '../api.js';
import { h, mount, icon, field, formData, showErrors, toast } from '../ui.js';
import { state, setUser, homeFor, go } from '../app.js';

function layout(root, formNode) {
  mount(root,
    h('div', { class: 'auth-wrap' },
      h('div', { class: 'auth-brand' },
        h('a', { class: 'brand', href: '#/' }, h('span', { class: 'brand-mark' }, 'T'),
          h('span', null, h('div', { class: 'brand-name' }, 'ТрайтэкСервис'), h('div', { class: 'brand-sub' }, 'Технический блок ООО «Трайтэк»'))),
        h('div', null,
          h('h1', null, 'Консалтинг, разработка и поддержка — в одном окне'),
          h('p', { style: { opacity: .9 } }, 'Запись на консультацию, ход проекта и заявки техподдержки с контролем сроков SLA.'),
          h('ul', null,
            ['Онлайн-консультации с оплатой картой и видеовстречей', 'Прозрачный ход разработки: доска задач и прогресс проекта', 'Заявки в поддержку с таймером SLA и автоназначением'].map((t) =>
              h('li', null, icon('check'), t)))),
        h('div', { class: 'small', style: { opacity: .7 } }, '© ООО «Трайтэк»')),
      h('div', { class: 'auth-form' }, h('div', { class: 'auth-card' }, formNode))));
}

const DEMO = [
  ['Клиент', 'kozlova@example.com', 'Demo12345'], ['Консультант', 'volkov@tritech-service.ru', 'Demo12345'],
  ['Разработчик', 'petrov@tritech-service.ru', 'Demo12345'], ['Специалист ТП', 'belov@tritech-service.ru', 'Demo12345'],
  ['Менеджер', 'manager@tritech-service.ru', 'Demo12345'], ['Тех. директор', 'director@tritech-service.ru', 'Demo12345'],
  ['Администратор', 'admin@tritech-service.ru', 'Admin12345'],
];

export async function login(ctx) {
  document.title = 'Вход — ТрайтэкСервис';
  const msg = h('div');
  const email = h('input', { type: 'email', name: 'email', placeholder: 'name@company.ru', autocomplete: 'username', required: true });
  const pass = h('input', { type: 'password', name: 'password', autocomplete: 'current-password', required: true });
  const btn = h('button', { class: 'btn block', type: 'submit' }, 'Войти');
  const form = h('form', { class: 'col gap-16', novalidate: true, onSubmit: async (e) => {
    e.preventDefault();
    btn.disabled = true;
    mount(msg);
    try {
      const d = await api.post('/api/auth/login', formData(form));
      setUser(d);
      go(ctx.query.next || homeFor(d.user));
    } catch (err) {
      mount(msg, h('div', { class: 'alert err' }, err.message,
        err.status === 403 && /Подтвердите/.test(err.message) ? h('div', { class: 'mt8' }, h('button', { type: 'button', class: 'link-btn', onClick: async () => {
          const r = await api.post('/api/auth/resend', { email: email.value });
          toast(r.message, 'ok');
          if (r.dev_link) mount(msg, h('div', { class: 'alert ok' }, 'Режим разработки: ', h('a', { href: r.dev_link }, 'подтвердить e-mail')));
        } }, 'Отправить письмо повторно')) : null));
    } finally { btn.disabled = false; }
  } },
  field('E-mail', email), field('Пароль', pass),
  msg, btn,
  h('div', { class: 'row between small' }, h('a', { href: '#/forgot' }, 'Забыли пароль?'), h('a', { href: '#/register' }, 'Создать аккаунт')));
  const demo = (state.demo || ['127.0.0.1', 'localhost'].includes(location.hostname)) ? h('details', { class: 'card pad mt16' },
    h('summary', { style: { cursor: 'pointer', fontWeight: 600 } }, 'Демо-аккаунты (режим разработки)'),
    h('div', { class: 'col gap-8 mt8' }, DEMO.map(([r, e, p]) => h('button', { type: 'button', class: 'btn secondary sm', style: { justifyContent: 'space-between' }, onClick: () => { email.value = e; pass.value = p; } }, r, h('span', { class: 'muted' }, e))))) : null;
  layout(ctx.root, h('div', null, h('h1', null, 'Вход в систему'), h('p', { class: 'lead' }, 'Введите e-mail и пароль, указанные при регистрации'), form, demo));
}

export async function register(ctx) {
  document.title = 'Регистрация — ТрайтэкСервис';
  const form = h('form', { class: 'col gap-16', novalidate: true, onSubmit: async (e) => {
    e.preventDefault();
    const btn = form.querySelector('button[type=submit]');
    btn.disabled = true;
    try {
      const d = await api.post('/api/auth/register', formData(form));
      mount(card, h('div', { class: 'col gap-16' }, h('h1', null, 'Проверьте почту'), h('div', { class: 'alert ok' }, d.message),
        d.dev_link ? h('div', { class: 'alert' }, 'Режим разработки (письмо не отправляется по SMTP): ', h('a', { href: d.dev_link }, 'подтвердить e-mail')) : null,
        h('a', { class: 'btn secondary', href: '#/login' }, 'Перейти ко входу')));
    } catch (err) { showErrors(form, err); } finally { btn.disabled = false; }
  } },
  h('div', { class: 'form-grid' },
    field('Фамилия *', h('input', { type: 'text', name: 'last_name', required: true, autocomplete: 'family-name' })),
    field('Имя *', h('input', { type: 'text', name: 'first_name', required: true, autocomplete: 'given-name' })),
    field('Отчество', h('input', { type: 'text', name: 'middle_name', autocomplete: 'additional-name' })),
    field('Телефон', h('input', { type: 'tel', name: 'phone', placeholder: '+7 900 000-00-00', autocomplete: 'tel' })),
    field('Организация', h('input', { type: 'text', name: 'company', placeholder: 'ООО «Ромашка» или ИП', autocomplete: 'organization' }), { full: true }),
    field('E-mail *', h('input', { type: 'email', name: 'email', required: true, autocomplete: 'email' }), { full: true }),
    field('Пароль *', h('input', { type: 'password', name: 'password', autocomplete: 'new-password' }), { hint: 'Не менее 8 символов, буквы и цифры' }),
    field('Повтор пароля *', h('input', { type: 'password', name: 'password_confirm', autocomplete: 'new-password' }))),
  h('div', { class: 'field' }, h('label', { class: 'chk' }, h('input', { type: 'checkbox', name: 'consent' }),
    h('span', null, 'Даю согласие на обработку персональных данных в соответствии с Федеральным законом № 152-ФЗ «О персональных данных»')), h('div', { class: 'err', hidden: true })),
  h('button', { class: 'btn block', type: 'submit' }, 'Зарегистрироваться'),
  h('div', { class: 'small center' }, 'Уже есть аккаунт? ', h('a', { href: '#/login' }, 'Войти')));
  const card = h('div', null, h('h1', null, 'Регистрация клиента'), h('p', { class: 'lead' }, 'Создайте аккаунт, чтобы записываться на консультации и следить за проектами'), form);
  layout(ctx.root, card);
}

export async function forgot(ctx) {
  document.title = 'Восстановление пароля — ТрайтэкСервис';
  const out = h('div');
  const form = h('form', { class: 'col gap-16', novalidate: true, onSubmit: async (e) => {
    e.preventDefault();
    try {
      const d = await api.post('/api/auth/forgot', formData(form));
      mount(out, h('div', { class: 'alert ok' }, d.message), d.dev_link ? h('div', { class: 'alert mt8' }, 'Режим разработки: ', h('a', { href: d.dev_link }, 'перейти к смене пароля')) : null);
    } catch (err) { showErrors(form, err); }
  } }, field('E-mail', h('input', { type: 'email', name: 'email', required: true })), out, h('button', { class: 'btn block', type: 'submit' }, 'Отправить ссылку'),
  h('div', { class: 'small center' }, h('a', { href: '#/login' }, '← Вернуться ко входу')));
  layout(ctx.root, h('div', null, h('h1', null, 'Восстановление пароля'), h('p', { class: 'lead' }, 'Укажите e-mail — мы отправим ссылку для создания нового пароля'), form));
}

export async function reset(ctx) {
  document.title = 'Новый пароль — ТрайтэкСервис';
  const form = h('form', { class: 'col gap-16', novalidate: true, onSubmit: async (e) => {
    e.preventDefault();
    const d = formData(form);
    if (d.password !== d.password2) return showErrors(form, { fields: { password2: 'Пароли не совпадают' } });
    try {
      const r = await api.post('/api/auth/reset', { token: ctx.query.token, password: d.password });
      toast(r.message, 'ok');
      go('/login');
    } catch (err) { showErrors(form, err); }
  } }, field('Новый пароль', h('input', { type: 'password', name: 'password', autocomplete: 'new-password' }), { hint: 'Не менее 8 символов, буквы и цифры' }),
  field('Повтор пароля', h('input', { type: 'password', name: 'password2', autocomplete: 'new-password' })),
  h('button', { class: 'btn block', type: 'submit' }, 'Сохранить пароль'));
  layout(ctx.root, h('div', null, h('h1', null, 'Новый пароль'), h('p', { class: 'lead' }, 'Придумайте новый пароль для входа'), form));
}

export async function verify(ctx) {
  document.title = 'Подтверждение e-mail — ТрайтэкСервис';
  const box = h('div', { class: 'alert' }, 'Подтверждаем адрес…');
  layout(ctx.root, h('div', null, h('h1', null, 'Подтверждение e-mail'), h('div', { class: 'mt16' }, box), h('a', { class: 'btn mt16', href: '#/login' }, 'Перейти ко входу')));
  try {
    const d = await api.post('/api/auth/verify', { token: ctx.query.token || '' });
    mount(box, d.message); box.className = 'alert ok';
  } catch (e) { mount(box, e.message); box.className = 'alert err'; }
}
