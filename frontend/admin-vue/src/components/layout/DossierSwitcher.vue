<script setup lang="ts">
import { computed, ref } from 'vue';
import FormField from '@/components/ui/FormField.vue';
import { canDiscardChanges } from '@/composables/unsavedChanges';
import { useContextStore } from '@/stores/context';
import { useNotificationStore } from '@/stores/notifications';

const context = useContextStore();
const notifications = useNotificationStore();
const select = ref<HTMLSelectElement | null>(null);
const selectedId = computed(() => context.selection?.dossier.id ?? 0);

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
  } catch {
    target.value = String(selectedId.value);
  }
}
</script>

<template>
  <FormField v-slot="{ describedBy }" id="dossier-switcher" label="Dossier" hint="Définit le périmètre de travail.">
    <select
      id="dossier-switcher"
      ref="select"
      :value="selectedId"
      :aria-describedby="describedBy"
      :disabled="context.loading"
      @change="change"
    >
      <option :value="0" disabled>Sélectionner un dossier</option>
      <optgroup
        v-for="organization in [...new Set(context.dossiers.map((item) => item.organization_name))]"
        :key="organization"
        :label="organization"
      >
        <option
          v-for="dossier in context.dossiers.filter((item) => item.organization_name === organization)"
          :key="dossier.id"
          :value="dossier.id"
        >
          {{ dossier.name }}
        </option>
      </optgroup>
    </select>
  </FormField>
</template>
