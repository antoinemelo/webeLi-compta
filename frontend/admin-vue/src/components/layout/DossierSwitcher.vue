<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import FormField from '@/components/ui/FormField.vue';
import { canDiscardChanges } from '@/composables/unsavedChanges';
import { useContextStore } from '@/stores/context';
import { useNotificationStore } from '@/stores/notifications';

const context = useContextStore();
const notifications = useNotificationStore();
const emit = defineEmits<{ selected: [] }>();
const organizationId = ref(0);
const selectedId = computed(() => context.selection?.dossier.id ?? 0);
const organizations = computed(() => {
  const values = new Map<number, string>();
  context.dossiers.forEach((dossier) => {
    values.set(dossier.organization_id, dossier.organization_name);
  });
  return [...values.entries()].map(([id, name]) => ({ id, name }));
});
const dossiers = computed(() =>
  context.dossiers.filter((dossier) => dossier.organization_id === organizationId.value)
);
const visibleSelectedId = computed(() =>
  dossiers.value.some((dossier) => dossier.id === selectedId.value) ? selectedId.value : 0
);

watch(
  () => context.selection?.organization.id ?? 0,
  (selectedOrganizationId) => {
    if (selectedOrganizationId) organizationId.value = selectedOrganizationId;
  },
  { immediate: true }
);
watch(
  organizations,
  (values) => {
    if (!values.some((organization) => organization.id === organizationId.value)) {
      organizationId.value = context.selection?.organization.id || values[0]?.id || 0;
    }
  },
  { immediate: true }
);

async function change(event: Event): Promise<void> {
  const target = event.target as HTMLSelectElement;
  const dossier = context.dossiers.find((item) => item.id === Number(target.value));
  if (!dossier) return;
  if (!canDiscardChanges()) {
    target.value = String(selectedId.value);
    return;
  }
  try {
    await context.selectDossier(dossier);
    notifications.push(`Dossier « ${dossier.name} » sélectionné.`, 'success');
    emit('selected');
  } catch {
    target.value = String(selectedId.value);
  }
}
</script>

<template>
  <div class="dossier-switcher">
  <FormField
    v-slot="{ describedBy }"
    id="organization-switcher"
    label="Organisation"
    hide-label
  >
    <select
      id="organization-switcher"
      class="form-select form-select-sm scope-select"
      v-model.number="organizationId"
      :aria-describedby="describedBy"
      :disabled="context.loading"
    >
      <option :value="0" disabled>Sélectionner une organisation</option>
      <option v-for="organization in organizations" :key="organization.id" :value="organization.id">
        {{ organization.name }}
      </option>
    </select>
  </FormField>
  <FormField
    v-slot="{ describedBy }"
    id="dossier-switcher"
    label="Dossier"
    hide-label
  >
    <select
      id="dossier-switcher"
      class="form-select form-select-sm scope-select"
      :value="visibleSelectedId"
      :aria-describedby="describedBy"
      :disabled="context.loading"
      @change="change"
    >
      <option :value="0" disabled>Sélectionner un dossier</option>
      <option v-for="dossier in dossiers" :key="dossier.id" :value="dossier.id">
        {{ dossier.name }}
      </option>
    </select>
  </FormField>
  </div>
</template>
