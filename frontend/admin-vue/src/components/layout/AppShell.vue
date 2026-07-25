<script setup lang="ts">
import { computed, ref } from 'vue';
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

const navigation = computed(() => (context.context?.navigation ?? []).map((item) => ({
  ...item,
  path: shellPaths[item.key] ?? item.path
})));
const selection = computed(() => context.selection);
const pageTitle = computed(() => String(route.meta.label || 'Compta'));
const band = computed(() => {
  const type = selection.value?.dossier.type;
  if (type === 'reel') return { tone: 'real', label: 'DOSSIER RÉEL — DONNÉES DE PRODUCTION' };
  if (type === 'demo') return { tone: 'demo', label: 'DÉMONSTRATION — DONNÉES FICTIVES' };
  if (type === 'exercice') return { tone: 'exercise', label: 'EXERCICE — DONNÉES FICTIVES' };
  return null;
});

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
        <RouterLink to="/" class="brand" @click="mobileOpen = false">
          <strong>WebeLi</strong>
          <span>Compta</span>
        </RouterLink>
      </div>
      <div class="topbar-tools">
        <span class="instance">{{ context.context?.instance }}</span>
        <a :href="runtimeConfig.legacyUrl" class="quiet-link">Interface classique</a>
        <button type="button" class="button secondary compact" @click="logoutDialog?.open()">
          Déconnexion
        </button>
      </div>
    </header>

    <div v-if="band" class="context-band" :class="`context-${band.tone}`" role="status">
      {{ band.label }}
    </div>

    <div class="workspace">
      <aside id="main-navigation" class="sidebar" :class="{ open: mobileOpen }">
        <DossierSwitcher />
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
        <div class="sidebar-user">
          <span>Connecté</span>
          <strong>{{ context.context?.user.name || context.context?.user.email }}</strong>
        </div>
      </aside>

      <main id="contenu" class="main-content" tabindex="-1">
        <nav class="breadcrumbs" aria-label="Fil d’Ariane">
          <ol>
            <li><RouterLink to="/">Accueil</RouterLink></li>
            <li v-if="route.path !== '/'" aria-current="page">{{ pageTitle }}</li>
          </ol>
        </nav>

        <section v-if="selection" class="context-summary" aria-label="Contexte de travail">
          <dl>
            <div><dt>Organisation</dt><dd>{{ selection.organization.name }}</dd></div>
            <div><dt>Dossier</dt><dd>{{ selection.dossier.name }}</dd></div>
            <div><dt>Exercice</dt><dd>{{ selection.exercise?.label || 'Non défini' }}</dd></div>
            <div><dt>Devise</dt><dd>{{ selection.dossier.currency }}</dd></div>
          </dl>
        </section>

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
