import { defineStore } from 'pinia';
import { api, errorMessage } from '@/api/client';
import type { TreasuryWorkspace } from '@/api/contracts';

export const useTreasuryStore = defineStore('treasury', {
  state: () => ({
    workspace: null as TreasuryWorkspace | null,
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
        this.workspace = (await api.get<TreasuryWorkspace>('/liquidites/banque')).data;
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
        const response = await api.post<T>(path, data);
        if (reload) await this.load();
        return response.data;
      } catch (error) {
        this.error = errorMessage(error);
        throw error;
      } finally {
        this.saving = false;
      }
    }
  }
});
