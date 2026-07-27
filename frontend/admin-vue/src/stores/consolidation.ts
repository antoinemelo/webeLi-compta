import { defineStore } from 'pinia';
import { api, errorMessage } from '@/api/client';
import type { ConsolidationWorkspace } from '@/api/contracts';

export const useConsolidationStore = defineStore('consolidation', {
  state: () => ({
    workspace: null as ConsolidationWorkspace | null,
    loading: false,
    saving: false,
    error: '',
    notice: ''
  }),
  actions: {
    clear(): void {
      this.workspace = null;
      this.error = '';
      this.notice = '';
    },
    async load(groupId?: number, periodId?: number): Promise<void> {
      this.loading = true;
      this.error = '';
      try {
        this.workspace = (await api.get<ConsolidationWorkspace>(
          '/consolidation',
          { group_id: groupId, period_id: periodId }
        )).data;
      } catch (error) {
        this.error = errorMessage(error);
      } finally {
        this.loading = false;
      }
    },
    async mutate<T = unknown>(
      path: string,
      data: Record<string, unknown>,
      notice: string
    ): Promise<T> {
      this.saving = true;
      this.error = '';
      this.notice = '';
      try {
        const response = await api.post<T>(path, data);
        this.notice = notice;
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
