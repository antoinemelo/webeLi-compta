<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import CompactTabs from '@/components/ui/CompactTabs.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import { subNavigation } from '@/router/navigation';
import { useContextStore } from '@/stores/context';

const route = useRoute();
const context = useContextStore();
const section = computed(() => String(route.meta.section || ''));
const title = computed(() => String(route.meta.label || 'Module'));
const tabs = computed(() => subNavigation[section.value] || []);
const requiredPermission: Record<string, string> = {
  learning: 'pedagogie.view',
  liquidity: 'tresorerie.view',
  billing: 'facturation.view',
  accounting: 'compta.view',
  payroll: 'salaires.view',
  settings: 'dossier.manage'
};
const moduleCodes: Record<string, string> = {
  learning: 'apprentissage',
  liquidity: 'liquidites',
  billing: 'facturation',
  accounting: 'comptabilite',
  payroll: 'salaires'
};
const moduleEnabled = computed(() => {
  const code = moduleCodes[section.value];
  return !code || context.moduleEnabled(code);
});
const allowed = computed(() =>
  moduleEnabled.value && context.can(requiredPermission[section.value] || 'dossier.view')
);
</script>

<template>
  <header class="page-header">
    <div>
      <p class="eyebrow">Espace de travail</p>
      <h1>{{ title }}</h1>
      <p>Navigation prête pour la migration progressive des parcours métier.</p>
    </div>
  </header>

  <CompactTabs v-if="allowed && tabs.length" :items="tabs" :label="`Navigation ${title}`" />

  <section v-if="!context.selection" class="access-message" role="status">
    <strong>Contexte requis</strong>
    <p>Sélectionnez un dossier autorisé dans le menu pour ouvrir ce module.</p>
  </section>

  <section v-else-if="!allowed" class="access-message denied" role="alert">
    <strong>{{ moduleEnabled ? 'Accès refusé' : 'Module désactivé' }}</strong>
    <p v-if="moduleEnabled">Votre rôle ne permet pas d’ouvrir {{ title }} dans ce dossier.</p>
    <p v-else>Ce module est désactivé dans la configuration du dossier.</p>
  </section>

  <EmptyState
    v-else
    :title="`${title} est indisponible`"
    description="Aucun écran supplémentaire n’est disponible pour ce module."
  />
</template>
