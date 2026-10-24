// Клиент REST API (JSON, сессионная cookie + CSRF-токен)
let csrf = '';

export const setCsrf = (v) => { csrf = v; };

export class ApiError extends Error {
  constructor(message, status, fields) {
    super(message);
    this.status = status;
    this.fields = fields || {};
  }
}

function qs(params) {
  if (!params) return '';
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== null && v !== '') p.set(k, v);
  }
  const s = p.toString();
  return s ? '?' + s : '';
}

async function request(method, path, body, isForm) {
  const headers = { 'X-CSRF-Token': csrf };
  const init = { method, headers, credentials: 'same-origin' };
  if (body !== undefined && body !== null) {
    if (isForm) {
      init.body = body;
    } else {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
  }
  let res;
  try {
    res = await fetch(path, init);
  } catch (e) {
    throw new ApiError('Нет связи с сервером. Проверьте подключение.', 0);
  }
  const ct = res.headers.get('content-type') || '';
  const data = ct.includes('application/json') ? await res.json() : null;
  if (!res.ok) {
    const err = new ApiError((data && data.error) || `Ошибка ${res.status}`, res.status, data && data.fields);
    if (res.status === 401 && !path.startsWith('/api/auth/')) {
      window.dispatchEvent(new CustomEvent('tt:unauth'));
    }
    throw err;
  }
  return data;
}

export const get = (path, params) => request('GET', path + qs(params));
export const post = (path, body) => request('POST', path, body ?? {});
export const put = (path, body) => request('PUT', path, body ?? {});
export const del = (path) => request('DELETE', path);
export const upload = (path, formData) => request('POST', path, formData, true);
export const url = (path, params) => path + qs(params);
