import { defineStore } from 'pinia';
import { api, errorMessage } from '@/api/client';
import type { AssetWorkspace } from '@/api/contracts';

export const useAssetStore = defineStore('assets', {
  state: () => ({
    workspace: null as AssetWorkspace | null,
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
    async load(exerciseId: number, assetId?: number, page = 1): Promise<void> {
      this.loading = true;
      this.error = '';
      try {
        this.workspace = (await api.get<AssetWorkspace>('/accounting/assets', {
          exercise_id: exerciseId,
          asset_id: assetId,
          page,
          per_page: 50
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
