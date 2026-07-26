import { defineStore } from 'pinia';
import { api, errorMessage } from '@/api/client';
import type { ExpensesPayload } from '@/api/contracts';

export const useExpensesStore = defineStore('expenses', {
  state: () => ({
    workspace: null as ExpensesPayload | null,
    loading: false,
    saving: false,
    error: ''
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
        this.workspace = (await api.get<ExpensesPayload>('/liquidites')).data;
      } catch (error) {
        this.error = errorMessage(error);
      } finally {
        this.loading = false;
      }
    },
    async mutate(path: string, data: Record<string, unknown>): Promise<void> {
      this.saving = true;
      this.error = '';
      try {
        await api.post<unknown>(path, data);
        await this.load();
      } catch (error) {
        this.error = errorMessage(error);
        throw error;
      } finally {
        this.saving = false;
      }
    }
  }
});
