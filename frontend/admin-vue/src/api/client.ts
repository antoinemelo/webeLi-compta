import type { ApiEnvelope, ApiErrorItem } from './contracts';
import { runtimeConfig } from '@/config';

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly correlationId = '',
    public readonly fields: Record<string, string[]> = {}
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

class ApiClient {
  private csrfToken = '';

  setCsrfToken(token: string): void {
    this.csrfToken = token;
  }

  get<T>(path: string, query: Record<string, string | number | undefined> = {}): Promise<ApiEnvelope<T>> {
    const url = new URL(`${runtimeConfig.apiBaseUrl}${path}`, window.location.origin);
    Object.entries(query).forEach(([key, value]) => {
      if (value !== undefined && value !== '') url.searchParams.set(key, String(value));
    });
    return this.request<T>(`${url.pathname}${url.search}`, { method: 'GET' });
  }

  post<T>(path: string, data: Record<string, unknown>): Promise<ApiEnvelope<T>> {
    return this.request<T>(`${runtimeConfig.apiBaseUrl}${path}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': this.csrfToken,
        'X-Contract-Version': 'compta-api-v1'
      },
      body: JSON.stringify({ data })
    });
  }

  private async request<T>(url: string, init: RequestInit): Promise<ApiEnvelope<T>> {
    const response = await fetch(url, {
      ...init,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-Correlation-ID': crypto.randomUUID(),
        ...(init.headers || {})
      }
    });
    const payload = await response.json().catch(() => null) as ApiEnvelope<T> | null;
    if (!payload || !Array.isArray(payload.errors)) {
      throw new ApiError(response.status, 'INVALID_RESPONSE', 'Réponse serveur invalide.');
    }
    if (!response.ok || payload.errors.length > 0) {
      const error: ApiErrorItem = payload.errors[0] || {
        code: 'HTTP_ERROR',
        message: `Erreur HTTP ${response.status}`,
        correlation_id: payload.meta?.correlation_id || ''
      };
      if (response.status === 401) {
        window.location.assign(runtimeConfig.loginUrl);
      }
      throw new ApiError(
        response.status,
        error.code,
        error.message,
        error.correlation_id,
        error.fields || {}
      );
    }
    return payload;
  }
}

export const api = new ApiClient();

export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    const fields = Object.entries(error.fields)
      .flatMap(([field, messages]) => messages.map((message) => `${field} : ${message}`));
    const suffix = error.correlationId ? ` Référence ${error.correlationId}.` : '';
    return `${fields.length > 0 ? fields.join(' ') : error.message}${suffix}`;
  }
  return error instanceof Error ? error.message : 'Une erreur inattendue est survenue.';
}
