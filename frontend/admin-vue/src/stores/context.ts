import { defineStore } from 'pinia';
import { api, errorMessage } from '@/api/client';
import type { Dossier, Exercise, ShellContext } from '@/api/contracts';

const LAST_DOSSIER_KEY = 'compta:last-dossier';

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
    moduleEnabled: (state) => (module: string): boolean =>
      state.context?.enabled_modules.includes(module) ?? false,
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
        const rememberedId = Number(window.localStorage.getItem(
          `${LAST_DOSSIER_KEY}:${context.data.user.id}`
        ) || 0);
        const remembered = dossiers.data.find((dossier) => dossier.id === rememberedId);
        if (
          remembered
          && (
            context.data.selection?.dossier.id !== remembered.id
            || context.data.selection?.organization.id !== remembered.organization_id
          )
        ) {
          const restored = await api.post<ShellContext>('/context/dossier', {
            organisation_id: remembered.organization_id,
            dossier_id: remembered.id
          });
          this.context = restored.data;
          api.setCsrfToken(restored.data.csrf_token);
        }
        if (this.context.selection) await this.loadExercises();
      } catch (error) {
        this.error = errorMessage(error);
      } finally {
        this.loading = false;
      }
    },
    async selectDossier(dossier: Dossier): Promise<void> {
      this.loading = true;
      this.error = '';
      this.exercises = [];
      try {
        const response = await api.post<ShellContext>('/context/dossier', {
          organisation_id: dossier.organization_id,
          dossier_id: dossier.id
        });
        this.context = response.data;
        api.setCsrfToken(response.data.csrf_token);
        window.localStorage.setItem(
          `${LAST_DOSSIER_KEY}:${response.data.user.id}`,
          String(dossier.id)
        );
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
