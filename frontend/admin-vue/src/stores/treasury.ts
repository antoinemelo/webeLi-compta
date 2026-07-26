import { defineStore } from 'pinia';
import { api, errorMessage } from '@/api/client';
import type {
  ExchangeHistoryPayload,
  InterestHistoryPayload,
  TreasuryWorkspace
} from '@/api/contracts';

export const useTreasuryStore = defineStore('treasury', {
  state: () => ({
    workspace: null as TreasuryWorkspace | null,
    exchangeHistory: null as ExchangeHistoryPayload | null,
    interestHistory: null as InterestHistoryPayload | null,
    loading: false,
    marketLoading: false,
    saving: false,
    error: '',
    marketError: ''
  }),
  actions: {
    clear(): void {
      this.workspace = null;
      this.exchangeHistory = null;
      this.interestHistory = null;
      this.error = '';
      this.marketError = '';
    },
    async loadExchangeHistory(exerciseId: number): Promise<void> {
      this.marketLoading = true;
      this.marketError = '';
      try {
        this.exchangeHistory = (
          await api.get<ExchangeHistoryPayload>('/liquidites/taux-change', {
            exercise_id: exerciseId
          })
        ).data;
      } catch (error) {
        this.marketError = errorMessage(error);
      } finally {
        this.marketLoading = false;
      }
    },
    async loadInterestHistory(exerciseId: number): Promise<void> {
      this.marketLoading = true;
      this.marketError = '';
      try {
        this.interestHistory = (
          await api.get<InterestHistoryPayload>('/liquidites/taux-interet', {
            exercise_id: exerciseId
          })
        ).data;
      } catch (error) {
        this.marketError = errorMessage(error);
      } finally {
        this.marketLoading = false;
      }
    },
    async load(): Promise<void> {
      this.loading = true;
      this.error = '';
      try {
        this.workspace = (await api.get<TreasuryWorkspace>('/liquidites/banque')).data;
      } catch (error) {
        this.error = errorMessage(error);
      } finally {
        this.loading = false;
      }
    },
    async mutate<T = unknown>(
      path: string,
      data: Record<string, unknown>,
      reload = true
    ): Promise<T> {
      this.saving = true;
      this.error = '';
      try {
        const response = await api.post<T>(path, data);
        if (reload) await this.load();
        return response.data;
      } catch (error) {
        this.error = errorMessage(error);
        throw error;
      } finally {
        this.saving = false;
      }
    }
  }
});
