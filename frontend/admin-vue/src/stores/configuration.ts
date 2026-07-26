import { defineStore } from 'pinia';
import { api, errorMessage } from '@/api/client';
import type { ConfigurationPayload, ManagedReferencesPayload } from '@/api/contracts';

export const useConfigurationStore = defineStore('configuration', {
  state: () => ({
    configuration: null as ConfigurationPayload | null,
    managedReferences: null as ManagedReferencesPayload | null,
    loading: false,
    saving: false,
    error: ''
  }),
  actions: {
    clear(): void {
      this.configuration = null;
      this.managedReferences = null;
      this.error = '';
    },
    async loadManagedReferences(): Promise<void> {
      this.loading = true;
      this.error = '';
      try {
        this.managedReferences = (
          await api.get<ManagedReferencesPayload>('/configuration/references')
        ).data;
      } catch (error) {
        this.error = errorMessage(error);
      } finally {
        this.loading = false;
      }
    },
    async createContact(data: Record<string, unknown>): Promise<void> {
      await this.mutateReference('/configuration/references/contacts', data);
    },
    async saveVatCode(data: Record<string, unknown>): Promise<void> {
      await this.mutateReference('/configuration/references/vat-codes', data);
    },
    async deleteVatCode(id: number): Promise<void> {
      await this.mutateReference('/configuration/references/vat-codes/delete', { id });
    },
    async savePayrollRates(data: Record<string, unknown>): Promise<void> {
      await this.mutateReference('/configuration/references/payroll-rates', data);
    },
    async saveTreasuryAccount(data: Record<string, unknown>): Promise<void> {
      await this.mutateReference('/configuration/references/treasury-accounts', data);
    },
    async saveJournal(data: Record<string, unknown>): Promise<void> {
      await this.mutateReference('/configuration/references/journals', data);
    },
    async saveExercise(data: Record<string, unknown>): Promise<void> {
      await this.mutateReference('/configuration/references/exercises', data);
    },
    async savePeriod(data: Record<string, unknown>): Promise<void> {
      await this.mutateReference('/configuration/references/periods', data);
    },
    async saveDossierAccess(data: Record<string, unknown>): Promise<void> {
      await this.mutateReference('/configuration/access', data);
    },
    async load(): Promise<void> {
      this.loading = true;
      this.error = '';
      try {
        this.configuration = (await api.get<ConfigurationPayload>('/configuration')).data;
      } catch (error) {
        this.error = errorMessage(error);
      } finally {
        this.loading = false;
      }
    },
    async saveIdentity(data: Record<string, unknown>): Promise<void> {
      await this.mutate('/configuration/identity', data);
    },
    async saveModule(code: string, enabled: boolean, version: number): Promise<void> {
      await this.mutate('/configuration/modules', { code, enabled, version });
    },
    async createPaymentTerm(data: Record<string, unknown>): Promise<void> {
      await this.mutate('/configuration/payment-terms', data);
    },
    async savePaymentDefault(
      direction: 'client' | 'fournisseur',
      conditionId: number,
      validFrom: string
    ): Promise<void> {
      await this.mutate('/configuration/payment-defaults', {
        direction,
        condition_id: conditionId,
        valid_from: validFrom
      });
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
    },
    async mutateReference(path: string, data: Record<string, unknown>): Promise<void> {
      this.saving = true;
      this.error = '';
      try {
        await api.post<unknown>(path, data);
        await Promise.all([this.load(), this.loadManagedReferences()]);
      } catch (error) {
        this.error = errorMessage(error);
        throw error;
      } finally {
        this.saving = false;
      }
    }
  }
});
