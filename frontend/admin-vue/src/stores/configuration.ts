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
    async deleteContact(id: number, version: number): Promise<void> {
      await this.mutateReference('/configuration/references/contacts/delete', {
        id,
        version
      });
    },
    async saveVatCode(data: Record<string, unknown>): Promise<void> {
      await this.mutateReference('/configuration/references/vat-codes', data);
    },
    async deleteVatCode(id: number): Promise<void> {
      await this.mutateReference('/configuration/references/vat-codes/delete', { id });
    },
    async clearVatConfiguration(): Promise<void> {
      await this.mutateReference('/configuration/references/vat/clear', {});
    },
    async savePayrollRates(data: Record<string, unknown>): Promise<void> {
      await this.mutateReference('/configuration/references/payroll-rates', data);
    },
    async savePayrollEmployerSettings(data: Record<string, unknown>): Promise<void> {
      await this.mutateReference('/configuration/payroll/employer', data);
    },
    async savePayrollMappingSettings(data: Record<string, unknown>): Promise<void> {
      await this.mutateReference('/configuration/payroll/mapping', data);
    },
    async saveTreasuryAccount(data: Record<string, unknown>): Promise<void> {
      await this.mutateReference('/configuration/references/treasury-accounts', data);
    },
    async saveCurrency(data: Record<string, unknown>): Promise<void> {
      await this.mutateReference('/configuration/references/currencies', data);
    },
    async saveExchangeRate(data: Record<string, unknown>): Promise<void> {
      await this.mutateReference('/configuration/references/exchange-rates', data);
    },
    async saveExchangeMapping(data: Record<string, unknown>): Promise<void> {
      await this.mutateReference('/configuration/references/exchange-mapping', data);
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
    async clearAudit(): Promise<void> {
      await this.mutate('/configuration/audit/clear', {});
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
