import { defineStore } from 'pinia';
import { api, errorMessage } from '@/api/client';
import type { BillingPayload } from '@/api/contracts';

export const useBillingStore = defineStore('billing', {
  state: () => ({
    workspace: null as BillingPayload | null,
    loading: false,
    saving: false,
    error: '',
    filters: {
      as_of_date: new Date().toISOString().slice(0, 10),
      direction: 'all',
      status: 'all',
      search: '',
      contact_id: 0
    }
  }),
  actions: {
    clear(): void {
      this.workspace = null;
      this.error = '';
    },
    async load(): Promise<void> {
      this.loading = true;
      this.error = '';
      try {
        this.workspace = (await api.get<BillingPayload>('/facturation', {
          as_of_date: this.filters.as_of_date,
          direction: this.filters.direction,
          status: this.filters.status,
          search: this.filters.search,
          contact_id: this.filters.contact_id || undefined
        })).data;
      } catch (error) {
        this.error = errorMessage(error);
      } finally {
        this.loading = false;
      }
    },
    async mutate<T = unknown>(
      path: string,
      data: Record<string, unknown>,
      reload = true
    ): Promise<T> {
      this.saving = true;
      this.error = '';
      try {
        const result = (await api.post<T>(path, data)).data;
        if (reload) await this.load();
        return result;
      } catch (error) {
        this.error = errorMessage(error);
        throw error;
      } finally {
        this.saving = false;
      }
    }
  }
});
