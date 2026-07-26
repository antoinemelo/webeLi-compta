import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { runtimeConfig } from '@/config';
import { canDiscardChanges } from '@/composables/unsavedChanges';
import DashboardView from '@/views/DashboardView.vue';
import ConfigurationView from '@/views/ConfigurationView.vue';
import AccountingView from '@/views/AccountingView.vue';
import LiquidityView from '@/views/LiquidityView.vue';
import BillingView from '@/views/BillingView.vue';
import WorkspaceView from '@/views/WorkspaceView.vue';

const workspace = (
  path: string,
  name: string,
  label: string,
  section: string,
  legacyPath: string
): RouteRecordRaw => ({
  path,
  name,
  component: WorkspaceView,
  meta: { label, section, legacyPath }
});

const routes: RouteRecordRaw[] = [
  { path: '/', name: 'dashboard', component: DashboardView, meta: { label: 'Tableau de bord' } },
  workspace('/apprentissage/:tab?', 'learning', 'Apprentissage', 'learning', '/pedagogie'),
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
  workspace('/salaires/:tab?', 'payroll', 'Salaires', 'payroll', '/salaires'),
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
