<script setup lang="ts">
import { computed } from 'vue';
import OrganisationRegistryPanel from '@/components/configuration/OrganisationRegistryPanel.vue';
import MaintenancePanel from '@/components/configuration/MaintenancePanel.vue';
import { useContextStore } from '@/stores/context';

const context = useContextStore();
const canManageRegistry = computed(() => (
  context.can('installation.admin') || context.can('organisation.manage')
));
</script>

<template>
  <MaintenancePanel v-if="context.can('installation.admin')" />
  <OrganisationRegistryPanel v-if="canManageRegistry" />
  <section v-else class="access-message denied" role="alert">
    <strong>Accès refusé</strong>
    <p>La gestion d’une organisation ou de l’installation est requise.</p>
  </section>
</template>
