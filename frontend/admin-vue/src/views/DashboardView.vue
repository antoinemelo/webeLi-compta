<script setup lang="ts">
import { computed } from 'vue';
import DataTable from '@/components/ui/DataTable.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import { useContextStore } from '@/stores/context';

const context = useContextStore();
const columns = [
  { key: 'label', label: 'Exercice' },
  { key: 'period', label: 'Période' },
  { key: 'status_label', label: 'Statut' }
];
const rows = computed<Array<Record<string, unknown>>>(() => context.exercises.map((exercise) => ({
  id: exercise.id,
  label: exercise.label,
  period: `${exercise.start_date} – ${exercise.end_date}`,
  status: exercise.status,
  status_label: exercise.status === 'ouvert' ? 'Ouvert' : 'Fermé'
})));
</script>

<template>
  <header class="page-header">
    <div>
      <p class="eyebrow">Pilotage</p>
      <h1>Tableau de bord</h1>
      <p>Votre point d’entrée vers les opérations et états comptables.</p>
    </div>
  </header>

  <EmptyState
    v-if="!context.selection"
    title="Sélectionnez un dossier"
    description="Le tableau de bord et les modules apparaîtront dans le périmètre autorisé."
  />

  <template v-else>
    <section class="dashboard-intro">
      <div>
        <span class="metric-label">Périmètre courant</span>
        <strong>{{ context.selection.dossier.name }}</strong>
        <small>{{ context.selection.organization.name }}</small>
      </div>
      <div>
        <span class="metric-label">Exercice courant</span>
        <strong>{{ context.selection.exercise?.label || 'À configurer' }}</strong>
        <small>{{ context.selection.exercise?.status || 'Aucun exercice' }}</small>
      </div>
      <div>
        <span class="metric-label">Devise de base</span>
        <strong>{{ context.selection.dossier.currency }}</strong>
        <small>Grand livre du dossier</small>
      </div>
    </section>

    <section class="panel">
      <div class="panel-heading">
        <div>
          <p class="eyebrow">Référentiel</p>
          <h2>Exercices comptables</h2>
        </div>
      </div>
      <DataTable v-if="rows.length" caption="Exercices comptables du dossier courant" :columns="columns" :rows="rows">
        <template #cell-status_label="{ row }">
          <span class="status-badge" :class="`status-${row.status}`">{{ row.status_label }}</span>
        </template>
      </DataTable>
      <EmptyState
        v-else
        title="Aucun exercice"
        description="Créez un exercice dans la configuration classique avant de commencer."
      />
    </section>
  </template>
</template>
