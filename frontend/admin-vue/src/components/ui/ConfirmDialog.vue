<script setup lang="ts">
import { nextTick, ref, useId } from 'vue';

const props = withDefaults(defineProps<{
  title: string;
  confirmLabel?: string;
  tone?: 'default' | 'danger';
}>(), {
  confirmLabel: 'Confirmer',
  tone: 'default'
});
const emit = defineEmits<{ confirm: [] }>();
const dialog = ref<HTMLDialogElement | null>(null);
const cancel = ref<HTMLButtonElement | null>(null);
const titleId = useId();

async function open(): Promise<void> {
  dialog.value?.showModal();
  await nextTick();
  cancel.value?.focus();
}

function close(): void {
  dialog.value?.close();
}

function confirm(): void {
  close();
  emit('confirm');
}

defineExpose({ open, close });
</script>

<template>
  <dialog ref="dialog" class="confirm-dialog" :aria-labelledby="titleId" @cancel="close">
    <form method="dialog" @submit.prevent>
      <h2 :id="titleId">{{ title }}</h2>
      <div class="dialog-content"><slot /></div>
      <div class="dialog-actions">
        <button ref="cancel" type="button" class="button secondary" @click="close">Annuler</button>
        <button type="button" class="button" :class="{ danger: props.tone === 'danger' }" @click="confirm">
          {{ confirmLabel }}
        </button>
      </div>
    </form>
  </dialog>
</template>
