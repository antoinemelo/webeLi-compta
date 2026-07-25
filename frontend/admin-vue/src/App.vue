<script setup lang="ts">
import { onMounted } from 'vue';
import AppShell from '@/components/layout/AppShell.vue';
import { useContextStore } from '@/stores/context';

const context = useContextStore();
onMounted(() => context.load());
</script>

<template>
  <AppShell>
    <RouterView />
  </AppShell>
</template>
