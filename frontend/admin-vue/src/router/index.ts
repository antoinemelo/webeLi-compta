import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { runtimeConfig } from '@/config';
import { canDiscardChanges } from '@/composables/unsavedChanges';
import DashboardView from '@/views/DashboardView.vue';
import ConfigurationView from '@/views/ConfigurationView.vue';
import AccountingView from '@/views/AccountingView.vue';
import LiquidityView from '@/views/LiquidityView.vue';
import PayrollView from '@/views/PayrollView.vue';
import BillingView from '@/views/BillingView.vue';
import LearningView from '@/views/LearningView.vue';

const routes: RouteRecordRaw[] = [
  { path: '/', name: 'dashboard', component: DashboardView, meta: { label: 'Tableau de bord' } },
  {
    path: '/apprentissage/:tab?',
    name: 'learning',
    component: LearningView,
    meta: { label: 'Apprentissage', section: 'learning' }
  },
  {
    path: '/liquidites/:tab?',
    name: 'liquidity',
    component: LiquidityView,
    meta: { label: 'Liquidités', section: 'liquidity' }
  },
  {
    path: '/facturation/:tab?',
    name: 'billing',
    component: BillingView,
    meta: { label: 'Facturation', section: 'billing' }
  },
  {
    path: '/compta/:tab?',
    name: 'accounting',
    component: AccountingView,
    meta: { label: 'Comptabilité', section: 'accounting' }
  },
  {
    path: '/salaires/:tab?',
    name: 'payroll',
    component: PayrollView,
    meta: { label: 'Salaires', section: 'payroll' }
  },
  {
    path: '/configuration/referentiels/plan',
    name: 'chart-settings',
    component: AccountingView,
    meta: { label: 'Configuration', section: 'settings' }
  },
  {
    path: '/configuration/:tab?',
    name: 'settings',
    component: ConfigurationView,
    meta: { label: 'Configuration', section: 'settings' }
  },
  { path: '/:pathMatch(.*)*', redirect: '/' }
];

export const router = createRouter({
  history: createWebHistory(`${runtimeConfig.appBaseUrl}/`),
  routes,
  scrollBehavior: () => ({ top: 0 })
});

router.beforeEach(() => canDiscardChanges());
