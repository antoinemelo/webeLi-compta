import { defineStore } from 'pinia';
import { api, errorMessage } from '@/api/client';
import type { OcasRatePreview, PayrollWorkspace } from '@/api/contracts';

export const usePayrollStore = defineStore('payroll', {
  state: () => ({
    workspace: null as PayrollWorkspace | null,
    ocas: null as OcasRatePreview | null,
    loading: false,
    saving: false,
    error: '',
    notice: ''
  }),
  actions: {
    async load(year: number, payrollId?: number): Promise<void> {
      this.loading = true;
      this.error = '';
      try {
        this.workspace = (await api.get<PayrollWorkspace>('/salaires', {
          year,
          payroll_id: payrollId
        })).data;
      } catch (error) {
        this.error = errorMessage(error);
      } finally {
        this.loading = false;
      }
    },
    async mutate(path: string, data: Record<string, unknown>, notice: string): Promise<void> {
      this.saving = true;
      this.error = '';
      this.notice = '';
      try {
        await api.post(path, data);
        this.notice = notice;
      } catch (error) {
        this.error = errorMessage(error);
        throw error;
      } finally {
        this.saving = false;
      }
    },
    async previewOcas(year: number, sourceCsv = ''): Promise<void> {
      this.saving = true;
      this.error = '';
      try {
        this.ocas = (await api.post<OcasRatePreview>(
          '/salaires/taux-ocas/previsualiser',
          { year, source_csv: sourceCsv }
        )).data;
      } catch (error) {
        this.error = errorMessage(error);
      } finally {
        this.saving = false;
      }
    }
  }
});
