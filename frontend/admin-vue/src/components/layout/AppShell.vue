<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import DossierSwitcher from './DossierSwitcher.vue';
import GlobalNavigationSearch from './GlobalNavigationSearch.vue';
import SetupGuide from './SetupGuide.vue';
import AccountSecurityDialog from '@/components/security/AccountSecurityDialog.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import SkeletonBlock from '@/components/ui/SkeletonBlock.vue';
import ToastRegion from '@/components/ui/ToastRegion.vue';
import { runtimeConfig } from '@/config';
import { useToastFeedback } from '@/composables/toastFeedback';
import { subNavigation } from '@/router/navigation';
import { useContextStore } from '@/stores/context';

const context = useContextStore();
useToastFeedback(context, false);
const route = useRoute();
const mobileOpen = ref(false);
const scopeMenuOpen = ref(false);
const accountMenuOpen = ref(false);
const topbarActions = ref<HTMLElement | null>(null);
const logoutDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
const securityDialog = ref<InstanceType<typeof AccountSecurityDialog> | null>(null);
const logoutForm = ref<HTMLFormElement | null>(null);
const setupGuideCanResume = ref(false);

const shellPaths: Record<string, string> = {
  dashboard: '/',
  learning: '/apprentissage',
  liquidity: '/liquidites',
  billing: '/facturation',
  accounting: '/compta',
  payroll: '/salaires',
  settings: '/configuration'
};

const allNavigation = computed(() => (context.context?.navigation ?? [])
  .map((item) => ({
    ...item,
    path: shellPaths[item.key] ?? item.path
  })));
const navigation = computed(() => allNavigation.value
  .filter((item) => !['dashboard', 'settings'].includes(item.key)));
const selection = computed(() => context.selection);
const pageTitle = computed(() => String(route.meta.label || 'Compta'));
const activeSection = computed(() => String(route.meta.section || ''));
const organizationName = computed(() => selection.value?.organization.name || 'WebeLi');
const dossierName = computed(() => selection.value?.dossier.name || 'Compta');
const exerciseAndCurrency = computed(() => {
  if (!selection.value) return '';
  return [
    selection.value.exercise?.label || 'Exercice non défini',
    selection.value.dossier.currency
  ].join(' · ');
});

watch(() => route.fullPath, closeMenus);

onMounted(() => {
  document.addEventListener('pointerdown', closeOnOutsideClick);
  document.addEventListener('keydown', closeOnEscape);
  window.addEventListener('compta:setup-guide-state', updateSetupGuideState);
});
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', closeOnOutsideClick);
  document.removeEventListener('keydown', closeOnEscape);
  window.removeEventListener('compta:setup-guide-state', updateSetupGuideState);
});

function closeMenus(): void {
  mobileOpen.value = false;
  scopeMenuOpen.value = false;
  accountMenuOpen.value = false;
}

function toggleScopeMenu(): void {
  scopeMenuOpen.value = !scopeMenuOpen.value;
  accountMenuOpen.value = false;
}

function toggleAccountMenu(): void {
  accountMenuOpen.value = !accountMenuOpen.value;
  scopeMenuOpen.value = false;
}

function closeOnOutsideClick(event: PointerEvent): void {
  if (!topbarActions.value?.contains(event.target as Node)) {
    scopeMenuOpen.value = false;
    accountMenuOpen.value = false;
  }
}

function closeOnEscape(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    scopeMenuOpen.value = false;
    accountMenuOpen.value = false;
  }
}

function confirmLogout(): void {
  logoutForm.value?.submit();
}

function resumeSetupGuide(): void {
  closeMenus();
  window.dispatchEvent(new CustomEvent('compta:setup-guide-resume'));
}

function updateSetupGuideState(event: Event): void {
  if (!(event instanceof CustomEvent)) return;
  setupGuideCanResume.value = Boolean(event.detail?.cancelled)
    && !Boolean(event.detail?.finished);
}
</script>

<template>
  <div class="application-shell">
    <header class="topbar navbar">
      <div class="brand-group">
        <button
          type="button"
          class="menu-button btn btn-outline-primary rounded-circle"
          :aria-expanded="mobileOpen"
          aria-controls="main-navigation"
          aria-label="Ouvrir la navigation"
          @click="mobileOpen = !mobileOpen"
        >
          <span aria-hidden="true">☰</span>
        </button>
        <RouterLink to="/" class="brand" aria-label="Ouvrir le tableau de bord" @click="closeMenus">
          <strong>{{ organizationName }}</strong>
          <span>
            {{ dossierName }}
            <small v-if="exerciseAndCurrency">({{ exerciseAndCurrency }})</small>
          </span>
        </RouterLink>
      </div>
      <GlobalNavigationSearch :modules="allNavigation" />
      <nav
        class="desktop-module-navigation navbar-nav flex-row"
        aria-label="Navigation principale"
      >
        <div
          v-for="item in navigation"
          :key="item.key"
          class="desktop-module-menu nav-item"
          :class="{ active: activeSection === item.key }"
        >
          <RouterLink
            :to="item.path"
            class="desktop-module-link nav-link"
            :aria-haspopup="subNavigation[item.key]?.length ? 'menu' : undefined"
            @click="closeMenus"
          >
            <span>{{ item.label }}</span>
            <svg
              v-if="subNavigation[item.key]?.length"
              class="desktop-module-caret"
              viewBox="0 0 16 16"
              aria-hidden="true"
              focusable="false"
            >
              <path fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z" />
            </svg>
          </RouterLink>
          <div
            v-if="subNavigation[item.key]?.length"
            class="desktop-module-submenu dropdown-menu"
            role="menu"
          >
            <RouterLink
              v-for="child in subNavigation[item.key]"
              :key="child.key"
              :to="child.path"
              class="dropdown-item rounded-2"
              role="menuitem"
              @click="closeMenus"
            >
              {{ child.label }}
            </RouterLink>
          </div>
        </div>
      </nav>
      <div ref="topbarActions" class="topbar-actions">
        <div class="header-menu">
          <button
            type="button"
            class="header-icon-button"
            title="Organisation, dossier et configuration"
            aria-label="Organisation, dossier et configuration"
            :aria-expanded="scopeMenuOpen"
            aria-controls="scope-menu"
            @click="toggleScopeMenu"
          >
            <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false">
              <path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16M3.5 5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1 0-1M5 8.5a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5m2 3a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5" />
            </svg>
          </button>
          <div v-if="scopeMenuOpen" id="scope-menu" class="header-popover scope-popover">
            <p class="popover-title">Contexte de travail</p>
            <RouterLink
              class="popover-option scope-structure-link"
              to="/organisations-dossiers"
              @click="closeMenus"
            >
              Organisations et dossiers
            </RouterLink>
            <DossierSwitcher @selected="scopeMenuOpen = false" />
            <div class="scope-popover-footer">
              <hr>
              <RouterLink class="popover-option" to="/configuration" @click="closeMenus">
                Configuration
              </RouterLink>
              <button
                v-if="context.can('dossier.manage') && setupGuideCanResume"
                class="popover-option"
                type="button"
                @click="resumeSetupGuide"
              >
                Reprendre la configuration initiale
              </button>
            </div>
          </div>
        </div>

        <div class="header-menu">
          <button
            type="button"
            class="header-icon-button"
            title="Informations personnelles"
            aria-label="Informations personnelles"
            :aria-expanded="accountMenuOpen"
            aria-controls="account-menu"
            @click="toggleAccountMenu"
          >
            <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false">
              <path d="M11 6a3 3 0 1 1-6 0 3 3 0 0 1 6 0z" />
              <path fill-rule="evenodd" d="M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8zm8-7a7 7 0 0 0-5.468 11.37C3.242 11.226 4.805 10 8 10s4.757 1.225 5.468 2.37A7 7 0 0 0 8 1z" />
            </svg>
          </button>
          <div v-if="accountMenuOpen" id="account-menu" class="header-popover account-popover">
            <p class="popover-title">Informations personnelles</p>
            <strong>{{ context.context?.user.name || context.context?.user.email }}</strong>
            <small>{{ context.context?.user.email }}</small>
            <hr>
            <button
              type="button"
              class="popover-option"
              @click="accountMenuOpen = false; securityDialog?.open()"
            >
              Sécurité du compte
            </button>
            <button
              type="button"
              class="popover-option popover-option-danger"
              @click="accountMenuOpen = false; logoutDialog?.open()"
            >
              Déconnexion
            </button>
          </div>
        </div>
      </div>
    </header>

    <div class="workspace">
      <aside
        id="main-navigation"
        class="sidebar bg-white border-bottom shadow-sm"
        :class="{ open: mobileOpen }"
      >
        <nav class="nav nav-pills flex-column gap-1 p-2" aria-label="Navigation mobile">
          <RouterLink
            v-for="item in navigation"
            :key="item.key"
            :to="item.path"
            class="nav-link"
            active-class="active"
            @click="mobileOpen = false"
          >
            {{ item.label }}
          </RouterLink>
        </nav>
      </aside>

      <main id="contenu" class="main-content" tabindex="-1">
        <nav class="breadcrumbs" aria-label="Fil d’Ariane">
          <ol>
            <li><RouterLink to="/">Accueil</RouterLink></li>
            <li v-if="route.path !== '/'" aria-current="page">{{ pageTitle }}</li>
          </ol>
        </nav>

        <ErrorSummary :message="context.error" />
        <SkeletonBlock v-if="context.loading && !context.context" :lines="6" />
        <slot v-else />
      </main>
    </div>

    <SetupGuide />
    <ToastRegion />
    <AccountSecurityDialog ref="securityDialog" />
    <form ref="logoutForm" class="visually-hidden" method="post" :action="runtimeConfig.logoutUrl">
      <input type="hidden" name="_csrf" :value="context.csrfToken">
    </form>
    <ConfirmDialog
      ref="logoutDialog"
      title="Se déconnecter ?"
      confirm-label="Se déconnecter"
      tone="danger"
      @confirm="confirmLogout"
    >
      <p>Votre session COMPTA sera fermée sur cet appareil.</p>
    </ConfirmDialog>
  </div>
</template>
