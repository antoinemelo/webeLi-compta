<script setup lang="ts">
import type { SubNavigationItem } from '@/router/navigation';

defineProps<{ items: SubNavigationItem[]; label: string }>();
</script>

<template>
  <nav class="compact-tabs" :aria-label="label">
    <RouterLink v-for="item in items" :key="item.key" :to="item.path">
      {{ item.label }}
    </RouterLink>
  </nav>
</template>
