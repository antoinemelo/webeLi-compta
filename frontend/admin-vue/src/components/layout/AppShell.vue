<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import DossierSwitcher from './DossierSwitcher.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import SkeletonBlock from '@/components/ui/SkeletonBlock.vue';
import ToastRegion from '@/components/ui/ToastRegion.vue';
import { runtimeConfig } from '@/config';
import { useContextStore } from '@/stores/context';

const context = useContextStore();
const route = useRoute();
const mobileOpen = ref(false);
const scopeMenuOpen = ref(false);
const accountMenuOpen = ref(false);
const topbarActions = ref<HTMLElement | null>(null);
const logoutDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
const logoutForm = ref<HTMLFormElement | null>(null);

const shellPaths: Record<string, string> = {
  dashboard: '/',
  learning: '/apprentissage',
  liquidity: '/liquidites',
  billing: '/facturation',
  accounting: '/compta',
  payroll: '/salaires',
  settings: '/configuration'
};

const navigation = computed(() => (context.context?.navigation ?? [])
  .filter((item) => !['dashboard', 'settings'].includes(item.key))
  .map((item) => ({
    ...item,
    path: shellPaths[item.key] ?? item.path
  })));
const selection = computed(() => context.selection);
const pageTitle = computed(() => String(route.meta.label || 'Compta'));
const organizationName = computed(() => selection.value?.organization.name || 'Organisation');
const dossierName = computed(() => selection.value?.dossier.name || 'Dossier');
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
});
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', closeOnOutsideClick);
  document.removeEventListener('keydown', closeOnEscape);
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
</script>

<template>
  <div class="application-shell">
    <header class="topbar">
      <div class="brand-group">
        <button
          type="button"
          class="menu-button"
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
              <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zM5 5.5a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 .4.8L9 8.333V11a.5.5 0 0 1-.276.447l-1 .5A.5.5 0 0 1 7 11.5V8.333L5.1 5.8a.5.5 0 0 1-.1-.3z" />
            </svg>
          </button>
          <div v-if="scopeMenuOpen" id="scope-menu" class="header-popover scope-popover">
            <p class="popover-title">Contexte de travail</p>
            <DossierSwitcher @selected="scopeMenuOpen = false" />
            <hr>
            <RouterLink class="popover-option" to="/configuration" @click="closeMenus">
              Configuration
            </RouterLink>
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
      <aside id="main-navigation" class="sidebar" :class="{ open: mobileOpen }">
        <nav aria-label="Navigation principale">
          <RouterLink
            v-for="item in navigation"
            :key="item.key"
            :to="item.path"
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

    <ToastRegion />
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
