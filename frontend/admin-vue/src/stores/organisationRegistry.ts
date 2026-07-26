import { defineStore } from 'pinia';
import { api, errorMessage } from '@/api/client';
import type {
  DossierInitializationSummary,
  DossierRegistryDetail,
  DossierRegistryItem,
  OrganisationRegistryDetail,
  OrganisationRegistryPayload
} from '@/api/contracts';

export const useOrganisationRegistryStore = defineStore('organisationRegistry', {
  state: () => ({
    payload: null as OrganisationRegistryPayload | null,
    selected: null as OrganisationRegistryDetail | null,
    dossiers: [] as DossierRegistryItem[],
    selectedDossier: null as DossierRegistryDetail | null,
    creationSummary: null as DossierInitializationSummary | null,
    loading: false,
    saving: false,
    error: '',
    search: '',
    status: 'all' as 'active' | 'archived' | 'all',
    page: 1,
    perPage: 20
  }),
  actions: {
    async load(): Promise<void> {
      this.loading = true;
      this.error = '';
      try {
        this.payload = (await api.get<OrganisationRegistryPayload>(
          '/structures/organisations',
          {
            search: this.search,
            status: this.status,
            page: this.page,
            per_page: this.perPage
          }
        )).data;
      } catch (error) {
        this.error = errorMessage(error);
      } finally {
        this.loading = false;
      }
    },
    async select(id: number): Promise<void> {
      this.loading = true;
      this.error = '';
      try {
        this.selected = (await api.get<OrganisationRegistryDetail>(
          '/structures/organisations/detail',
          { id }
        )).data;
        await this.loadDossiers();
      } catch (error) {
        this.error = errorMessage(error);
      } finally {
        this.loading = false;
      }
    },
    async loadDossiers(): Promise<void> {
      if (!this.selected) {
        this.dossiers = [];
        return;
      }
      const response = await api.get<{ items: DossierRegistryItem[] }>(
        '/structures/dossiers',
        { organisation_id: this.selected.id, status: 'all' }
      );
      this.dossiers = response.data.items;
    },
    async selectDossier(id: number): Promise<void> {
      this.loading = true;
      this.error = '';
      try {
        this.selectedDossier = (await api.get<DossierRegistryDetail>(
          '/structures/dossiers/detail',
          { id }
        )).data;
      } catch (error) {
        this.error = errorMessage(error);
      } finally {
        this.loading = false;
      }
    },
    async createDossier(data: Record<string, unknown>): Promise<void> {
      this.saving = true;
      this.error = '';
      this.creationSummary = null;
      try {
        const response = await api.post<{
          id: number;
          summary: DossierInitializationSummary;
        }>('/structures/dossiers', data);
        this.creationSummary = response.data.summary;
        await this.load();
        if (this.selected) await this.select(this.selected.id);
        await this.selectDossier(response.data.id);
      } catch (error) {
        this.error = errorMessage(error);
        throw error;
      } finally {
        this.saving = false;
      }
    },
    async updateDossier(data: Record<string, unknown>): Promise<void> {
      await this.mutateDossier('/structures/dossiers/update', data);
    },
    async archiveDossier(): Promise<void> {
      if (!this.selectedDossier) return;
      await this.mutateDossier('/structures/dossiers/archive', {
        id: this.selectedDossier.id,
        version: this.selectedDossier.version
      });
    },
    async reactivateDossier(): Promise<void> {
      if (!this.selectedDossier) return;
      await this.mutateDossier('/structures/dossiers/reactivate', {
        id: this.selectedDossier.id,
        version: this.selectedDossier.version
      });
    },
    async removeDossier(): Promise<void> {
      if (!this.selectedDossier) return;
      await this.mutateDossier('/structures/dossiers/delete', {
        id: this.selectedDossier.id,
        version: this.selectedDossier.version
      }, false);
      this.selectedDossier = null;
    },
    async mutateDossier(
      path: string,
      data: Record<string, unknown>,
      reloadSelected = true
    ): Promise<void> {
      this.saving = true;
      this.error = '';
      try {
        await api.post<unknown>(path, data);
        await this.load();
        if (this.selected) await this.select(this.selected.id);
        if (reloadSelected && this.selectedDossier) {
          await this.selectDossier(this.selectedDossier.id);
        }
      } catch (error) {
        this.error = errorMessage(error);
        throw error;
      } finally {
        this.saving = false;
      }
    },
    async create(data: Record<string, unknown>): Promise<number> {
      return this.mutateCreate('/structures/organisations', data);
    },
    async update(data: Record<string, unknown>): Promise<void> {
      await this.mutate('/structures/organisations/update', data);
    },
    async saveLegalIdentity(data: Record<string, unknown>): Promise<void> {
      await this.mutate('/structures/organisations/legal-identities', data);
    },
    async archive(): Promise<void> {
      await this.selectedAction('/structures/organisations/archive');
    },
    async reactivate(): Promise<void> {
      await this.selectedAction('/structures/organisations/reactivate');
    },
    async remove(): Promise<void> {
      if (!this.selected) return;
      const id = this.selected.id;
      await this.mutate('/structures/organisations/delete', {
        id,
        version: this.selected.version
      }, false);
      this.selected = null;
      this.dossiers = [];
      this.selectedDossier = null;
    },
    async selectedAction(path: string): Promise<void> {
      if (!this.selected) return;
      await this.mutate(path, {
        id: this.selected.id,
        version: this.selected.version
      });
    },
    async mutate(
      path: string,
      data: Record<string, unknown>,
      reloadSelected = true
    ): Promise<void> {
      this.saving = true;
      this.error = '';
      try {
        await api.post<unknown>(path, data);
        await this.load();
        if (reloadSelected && this.selected) await this.select(this.selected.id);
      } catch (error) {
        this.error = errorMessage(error);
        throw error;
      } finally {
        this.saving = false;
      }
    },
    async mutateCreate(path: string, data: Record<string, unknown>): Promise<number> {
      this.saving = true;
      this.error = '';
      try {
        const response = await api.post<{ id: number }>(path, data);
        await this.load();
        await this.select(response.data.id);
        return response.data.id;
      } catch (error) {
        this.error = errorMessage(error);
        throw error;
      } finally {
        this.saving = false;
      }
    }
  }
});
