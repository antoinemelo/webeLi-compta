import { readonly, ref } from 'vue';

const dirty = ref(false);

export function markUnsavedChanges(value: boolean): void {
  dirty.value = value;
}

export function canDiscardChanges(): boolean {
  if (!dirty.value) return true;
  const accepted = window.confirm('Des modifications ne sont pas enregistrées. Les abandonner ?');
  if (accepted) dirty.value = false;
  return accepted;
}

export function useUnsavedChanges() {
  return {
    dirty: readonly(dirty),
    markUnsavedChanges,
    canDiscardChanges
  };
}

window.addEventListener('beforeunload', (event) => {
  if (!dirty.value) return;
  event.preventDefault();
  event.returnValue = '';
});
