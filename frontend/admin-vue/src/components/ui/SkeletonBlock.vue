<script setup lang="ts">
withDefaults(defineProps<{ lines?: number; label?: string }>(), {
  lines: 4,
  label: 'Chargement en cours'
});
</script>

<template>
  <div class="skeleton" role="status" :aria-label="label">
    <span v-for="line in lines" :key="line" class="skeleton-line" aria-hidden="true"></span>
  </div>
</template>
