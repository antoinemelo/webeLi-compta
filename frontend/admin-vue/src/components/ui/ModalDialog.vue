<script setup lang="ts">
import { nextTick, ref, useId } from 'vue';

withDefaults(defineProps<{
  title: string;
  description?: string;
  wide?: boolean;
}>(), {
  description: '',
  wide: false
});

const dialog = ref<HTMLDialogElement | null>(null);
const closeButton = ref<HTMLButtonElement | null>(null);
const titleId = useId();
const emit = defineEmits<{ closed: [] }>();

async function open(): Promise<void> {
  dialog.value?.showModal();
  await nextTick();
  const firstField = dialog.value?.querySelector<HTMLElement>(
    'input:not([disabled]), select:not([disabled]), textarea:not([disabled])'
  );
  (firstField || closeButton.value)?.focus();
}

function close(): void {
  dialog.value?.close();
}

defineExpose({ open, close });
</script>

<template>
  <dialog
    ref="dialog"
    :class="['form-dialog', { wide }]"
    :aria-labelledby="titleId"
    @cancel="close"
    @close="emit('closed')"
  >
    <header class="form-dialog-header">
      <div>
        <h2 :id="titleId">{{ title }}</h2>
        <p v-if="description">{{ description }}</p>
      </div>
      <button
        ref="closeButton"
        class="icon-button"
        type="button"
        aria-label="Fermer"
        @click="close"
      >×</button>
    </header>
    <div class="form-dialog-content">
      <slot />
    </div>
  </dialog>
</template>
