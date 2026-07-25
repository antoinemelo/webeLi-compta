<script setup lang="ts">
defineProps<{
  id: string;
  label: string;
  hint?: string;
  error?: string;
}>();
</script>

<template>
  <div class="form-field" :class="{ 'has-error': error }">
    <label :for="id">{{ label }}</label>
    <p v-if="hint" :id="`${id}-hint`" class="field-hint">{{ hint }}</p>
    <slot :described-by="[hint ? `${id}-hint` : '', error ? `${id}-error` : ''].filter(Boolean).join(' ') || undefined" />
    <p v-if="error" :id="`${id}-error`" class="field-error">{{ error }}</p>
  </div>
</template>
