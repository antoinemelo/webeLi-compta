import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { runtimeConfig } from '@/config';
import { canDiscardChanges } from '@/composables/unsavedChanges';
import DashboardView from '@/views/DashboardView.vue';
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
  workspace('/liquidites/:tab?', 'liquidity', 'Liquidités', 'liquidity', '/'),
  workspace('/facturation/:tab?', 'billing', 'Facturation', 'billing', '/facturation'),
  workspace('/compta/:tab?', 'accounting', 'Comptabilité', 'accounting', '/compta'),
  workspace('/salaires/:tab?', 'payroll', 'Salaires', 'payroll', '/salaires'),
  workspace('/configuration/:tab?', 'settings', 'Configuration', 'settings', '/compta/plan'),
  { path: '/:pathMatch(.*)*', redirect: '/' }
];

export const router = createRouter({
  history: createWebHistory(`${runtimeConfig.appBaseUrl}/`),
  routes,
  scrollBehavior: () => ({ top: 0 })
});

router.beforeEach(() => canDiscardChanges());
