import { defineStore } from 'pinia';
import { api, errorMessage } from '@/api/client';
import type { DashboardProjection } from '@/api/contracts';

export const useDashboardStore = defineStore('dashboard', {
  state: () => ({
    projection: null as DashboardProjection | null,
    loading: false,
    error: ''
  }),
  actions: {
    clear(): void {
      this.projection = null;
      this.error = '';
    },
    async load(exerciseId: number, asOfDate: string): Promise<void> {
      this.loading = true;
      this.error = '';
      try {
        const response = await api.get<DashboardProjection>('/dashboard', {
          exercise_id: exerciseId,
          as_of_date: asOfDate
        });
        this.projection = response.data;
      } catch (error) {
        this.projection = null;
        this.error = errorMessage(error);
      } finally {
        this.loading = false;
      }
    }
  }
});
