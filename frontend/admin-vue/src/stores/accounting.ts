import { defineStore } from 'pinia';
import { api, errorMessage } from '@/api/client';
import type { AccountingWorkspace } from '@/api/contracts';

export const useAccountingStore = defineStore('accounting', {
  state: () => ({
    workspace: null as AccountingWorkspace | null,
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
    async load(
      exerciseId: number,
      accountId?: number,
      dateStart?: string,
      dateEnd?: string,
      vatStatementId?: number
    ): Promise<void> {
      this.loading = true;
      this.error = '';
      try {
        this.workspace = (await api.get<AccountingWorkspace>('/accounting', {
          exercise_id: exerciseId,
          account_id: accountId,
          date_start: dateStart,
          date_end: dateEnd,
          vat_statement_id: vatStatementId
        })).data;
      } catch (error) {
        this.error = errorMessage(error);
      } finally {
        this.loading = false;
      }
    },
    async mutate(
      path: string,
      data: Record<string, unknown>,
      notice: string
    ): Promise<void> {
      this.saving = true;
      this.error = '';
      this.notice = '';
      try {
        await api.post<unknown>(path, data);
        this.notice = notice;
      } catch (error) {
        this.error = errorMessage(error);
        throw error;
      } finally {
        this.saving = false;
      }
    }
  }
});
