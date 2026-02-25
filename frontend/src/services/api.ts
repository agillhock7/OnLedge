export class ApiError extends Error {
  status: number;
  details: unknown;

  constructor(message: string, status: number, details: unknown) {
    super(message);
    this.status = status;
    this.details = details;
  }
}

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '/api';

type ApiMethod = 'GET' | 'POST' | 'PUT' | 'DELETE';

async function request<T>(path: string, method: ApiMethod, body?: BodyInit | Record<string, unknown>, isForm = false): Promise<T> {
  const headers: HeadersInit = {
    'X-OnLedge-Client': 'web'
  };
  let payload: BodyInit | undefined;

  if (body instanceof FormData) {
    payload = body;
  } else if (body !== undefined) {
    if (isForm) {
      const form = new URLSearchParams();
      Object.entries(body).forEach(([key, value]) => {
        if (value !== undefined && value !== null) {
          form.append(key, String(value));
        }
      });
      payload = form;
      headers['Content-Type'] = 'application/x-www-form-urlencoded';
    } else {
      payload = JSON.stringify(body);
      headers['Content-Type'] = 'application/json';
    }
  }

  const response = await fetch(`${API_BASE_URL}${path}`, {
    method,
    headers,
    body: payload,
    credentials: 'include'
  });

  const contentType = response.headers.get('content-type') ?? '';
  const parsed = contentType.includes('application/json') ? await response.json() : await response.text();

  if (!response.ok) {
    const message = typeof parsed === 'object' && parsed !== null && 'error' in parsed
      ? String((parsed as { error: string }).error)
      : `Request failed with status ${response.status}`;
    throw new ApiError(message, response.status, parsed);
  }

  return parsed as T;
}

export function apiGet<T>(path: string): Promise<T> {
  return request<T>(path, 'GET');
}

export function apiPost<T>(path: string, body?: Record<string, unknown> | FormData): Promise<T> {
  return request<T>(path, 'POST', body);
}

export function apiPut<T>(path: string, body?: Record<string, unknown>): Promise<T> {
  return request<T>(path, 'PUT', body);
}

export function apiDelete<T>(path: string): Promise<T> {
  return request<T>(path, 'DELETE');
}
