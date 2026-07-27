<script setup lang="ts">
import { computed } from 'vue';
import OrganisationRegistryPanel from '@/components/configuration/OrganisationRegistryPanel.vue';
import { useContextStore } from '@/stores/context';

const context = useContextStore();
const canManageRegistry = computed(() => (
  context.can('installation.admin') || context.can('organisation.manage')
));
</script>

<template>
  <header class="page-heading">
    <div>
      <h1>Organisations et dossiers</h1>
      <p>Créez vos organisations, leurs dossiers comptables et les accès associés.</p>
    </div>
  </header>

  <OrganisationRegistryPanel v-if="canManageRegistry" />
  <section v-else class="access-message denied" role="alert">
    <strong>Accès refusé</strong>
    <p>La gestion d’une organisation ou de l’installation est requise.</p>
  </section>
</template>
