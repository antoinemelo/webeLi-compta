<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';

const props = defineProps<{ message: string }>();
const summary = ref<HTMLElement | null>(null);

watch(() => props.message, async (message) => {
  if (!message) return;
  await nextTick();
  summary.value?.focus();
}, { immediate: true });
</script>

<template>
  <section v-if="message" ref="summary" class="error-summary" role="alert" tabindex="-1">
    <h2>Impossible de poursuivre</h2>
    <p>{{ message }}</p>
  </section>
</template>
