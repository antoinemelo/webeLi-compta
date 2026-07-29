<script setup lang="ts">
import { useRoute } from 'vue-router';
import type { SubNavigationItem } from '@/router/navigation';

defineProps<{ items: SubNavigationItem[]; label: string }>();
const route = useRoute();

function isActive(item: SubNavigationItem): boolean {
  return item.activePrefix
    ? route.path.startsWith(item.activePrefix)
    : route.path === item.path;
}
</script>

<template>
  <nav class="compact-tabs" :aria-label="label">
    <div class="compact-tab-links">
      <RouterLink
        v-for="item in items"
        :key="item.key"
        :to="item.path"
        :class="{ active: isActive(item) }"
        :aria-current="isActive(item) ? 'page' : undefined"
      >
        {{ item.label }}
      </RouterLink>
    </div>
    <div v-if="$slots.actions" class="compact-tab-actions">
      <slot name="actions" />
    </div>
  </nav>
</template>
