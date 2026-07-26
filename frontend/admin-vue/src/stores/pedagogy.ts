import { defineStore } from 'pinia';
import { api, errorMessage } from '@/api/client';
import type { PedagogyWorkspace } from '@/api/contracts';

export const usePedagogyStore = defineStore('pedagogy', {
  state: () => ({
    workspace: null as PedagogyWorkspace | null,
    loading: false,
    saving: false,
    error: '',
    notice: '',
    hint: '',
    correction: null as Record<string, unknown> | null
  }),
  actions: {
    async load(): Promise<void> {
      this.loading = true;
      this.error = '';
      try {
        this.workspace = (await api.get<PedagogyWorkspace>('/pedagogie')).data;
      } catch (error) {
        this.error = errorMessage(error);
      } finally {
        this.loading = false;
      }
    },
    async mutate(
      path: string,
      data: Record<string, unknown>,
      notice: string,
      reload = true
    ): Promise<Record<string, unknown> | null> {
      this.saving = true;
      this.error = '';
      this.notice = '';
      try {
        const result = await api.post<Record<string, unknown>>(path, data);
        this.notice = notice;
        if (reload) await this.load();
        return result.data;
      } catch (error) {
        this.error = errorMessage(error);
        return null;
      } finally {
        this.saving = false;
      }
    },
    async requestHint(stepId: number): Promise<void> {
      const result = await this.mutate(
        '/pedagogie/indices',
        { step_id: stepId },
        'Indice affiché.',
        false
      );
      if (result) {
        this.hint = `Indice ${Number(result.niveau)} : ${String(result.contenu)}`;
        await this.load();
      }
    },
    async requestCorrection(): Promise<void> {
      this.saving = true;
      this.error = '';
      try {
        const result = await api.get<{ solution: Record<string, unknown> }>(
          '/pedagogie/correction'
        );
        this.correction = result.data.solution;
      } catch (error) {
        this.error = errorMessage(error);
      } finally {
        this.saving = false;
      }
    },
    clearFeedback(): void {
      this.hint = '';
      this.correction = null;
      this.notice = '';
    }
  }
});
