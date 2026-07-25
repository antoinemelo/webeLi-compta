import { defineStore } from 'pinia';
import { api, errorMessage } from '@/api/client';
import type { Dossier, Exercise, ShellContext } from '@/api/contracts';

export const useContextStore = defineStore('context', {
  state: () => ({
    context: null as ShellContext | null,
    dossiers: [] as Dossier[],
    exercises: [] as Exercise[],
    loading: false,
    error: ''
  }),
  getters: {
    can: (state) => (permission: string): boolean =>
      state.context?.permissions.includes(permission) ?? false,
    selection: (state) => state.context?.selection ?? null,
    csrfToken: (state) => state.context?.csrf_token ?? ''
  },
  actions: {
    async load(): Promise<void> {
      this.loading = true;
      this.error = '';
      try {
        const [context, dossiers] = await Promise.all([
          api.get<ShellContext>('/context'),
          api.get<Dossier[]>('/dossiers', { per_page: 100, sort: 'organization_name' })
        ]);
        this.context = context.data;
        this.dossiers = dossiers.data;
        api.setCsrfToken(context.data.csrf_token);
        if (context.data.selection) await this.loadExercises();
      } catch (error) {
        this.error = errorMessage(error);
      } finally {
        this.loading = false;
      }
    },
    async selectDossier(dossier: Dossier): Promise<void> {
      this.loading = true;
      this.error = '';
      try {
        const response = await api.post<ShellContext>('/context/dossier', {
          organisation_id: dossier.organization_id,
          dossier_id: dossier.id
        });
        this.context = response.data;
        api.setCsrfToken(response.data.csrf_token);
        await this.loadExercises();
      } catch (error) {
        this.error = errorMessage(error);
        throw error;
      } finally {
        this.loading = false;
      }
    },
    async loadExercises(): Promise<void> {
      const response = await api.get<Exercise[]>('/exercises', {
        per_page: 100,
        sort: 'start_date',
        order: 'desc'
      });
      this.exercises = response.data;
    }
  }
});
