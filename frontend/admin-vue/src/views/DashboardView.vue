<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import DataTable from '@/components/ui/DataTable.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import FormField from '@/components/ui/FormField.vue';
import SkeletonBlock from '@/components/ui/SkeletonBlock.vue';
import type { Exercise } from '@/api/contracts';
import { useContextStore } from '@/stores/context';
import { useDashboardStore } from '@/stores/dashboard';

const context = useContextStore();
const dashboard = useDashboardStore();
const exerciseId = ref(0);
const asOfDate = ref('');
let initializedDossierId = 0;

const currency = computed(() =>
  dashboard.projection?.scope.base_currency
    || context.selection?.dossier.currency
    || 'CHF'
);
const projection = computed(() => dashboard.projection);
const selectedExercise = computed(() =>
  context.exercises.find((exercise) => exercise.id === exerciseId.value) || null
);
const treasuryRows = computed<Array<Record<string, unknown>>>(() =>
  (projection.value?.treasury.accounts ?? []).map((account) => ({
    id: account.id,
    account: `${account.ledger_account.number} — ${account.label}`,
    accounting: formatMoney(account.accounting_balance_cents, currency.value),
    bank: account.bank_balance_cents === null
      ? 'Non importé'
      : formatMoney(account.bank_balance_cents, account.bank_balance_currency || account.currency),
    bank_date: account.bank_balance_date || '—',
    difference: account.difference_cents === null
      ? 'Non comparable'
      : formatMoney(account.difference_cents, currency.value)
  }))
);
const agingRows = computed<Array<Record<string, unknown>>>(() => {
  if (!projection.value) return [];
  const buckets = [
    ['not_due', 'Non échu'],
    ['days_1_30', '1–30 jours'],
    ['days_31_60', '31–60 jours'],
    ['days_61_90', '61–90 jours'],
    ['days_91_plus', 'Plus de 90 jours']
  ] as const;
  return buckets.map(([key, label]) => ({
    id: key,
    bucket: label,
    receivables: formatMoney(projection.value!.open_items.receivables.aging[key], currency.value),
    payables: formatMoney(projection.value!.open_items.payables.aging[key], currency.value)
  }));
});
const recentRows = computed<Array<Record<string, unknown>>>(() =>
  (projection.value?.recent_entries ?? []).map((entry) => ({
    id: entry.id,
    date: entry.date,
    number: entry.number || `#${entry.id}`,
    label: entry.label,
    journal: entry.journal,
    amount: formatMoney(entry.amount_cents, currency.value),
    source_path: entry.source.path
  }))
);

watch(
  () => [context.selection?.dossier.id ?? 0, context.exercises] as const,
  ([dossierId, exercises]) => {
    if (!dossierId || exercises.length === 0) {
      initializedDossierId = 0;
      exerciseId.value = 0;
      asOfDate.value = '';
      dashboard.clear();
      return;
    }
    if (initializedDossierId === dossierId) return;
    initializedDossierId = dossierId;
    const selected = exercises.find(
      (exercise) => exercise.id === context.selection?.exercise?.id
    ) || exercises[0];
    selectExercise(selected);
    void refresh();
  },
  { immediate: true, deep: true }
);

function formatMoney(cents: number, code: string): string {
  const sign = cents < 0 ? '−' : '';
  const absolute = Math.abs(cents);
  const units = Math.floor(absolute / 100);
  const decimals = String(absolute % 100).padStart(2, '0');
  return `${sign}${code} ${units.toLocaleString('fr-CH')}.${decimals}`;
}

function clampDate(exercise: Exercise): string {
  const today = new Date().toISOString().slice(0, 10);
  if (today < exercise.start_date) return exercise.start_date;
  if (today > exercise.end_date) return exercise.end_date;
  return today;
}

function selectExercise(exercise: Exercise): void {
  exerciseId.value = exercise.id;
  asOfDate.value = clampDate(exercise);
}

function onExerciseChange(): void {
  const exercise = context.exercises.find((item) => item.id === exerciseId.value);
  if (exercise) selectExercise(exercise);
}

async function refresh(): Promise<void> {
  if (exerciseId.value > 0 && asOfDate.value) {
    await dashboard.load(exerciseId.value, asOfDate.value);
  }
}
</script>

<template>
  <header class="page-header dashboard-header">
    <div>
      <p class="eyebrow">Pilotage comptable</p>
      <h1>Tableau de bord</h1>
      <p>Une lecture du grand livre, des échéances et de la banque à une date explicite.</p>
    </div>
    <form v-if="context.selection && context.exercises.length" class="dashboard-filters" @submit.prevent="refresh">
      <FormField id="dashboard-exercise" label="Exercice">
        <template #default="{ describedBy }">
          <select
            id="dashboard-exercise"
            v-model.number="exerciseId"
            :aria-describedby="describedBy"
            @change="onExerciseChange"
          >
            <option v-for="exercise in context.exercises" :key="exercise.id" :value="exercise.id">
              {{ exercise.label }}
            </option>
          </select>
        </template>
      </FormField>
      <FormField id="dashboard-date" label="Date d’arrêté">
        <template #default="{ describedBy }">
          <input
            id="dashboard-date"
            v-model="asOfDate"
            type="date"
            :min="selectedExercise?.start_date"
            :max="selectedExercise?.end_date"
            :aria-describedby="describedBy"
          >
        </template>
      </FormField>
      <button class="button primary" type="submit" :disabled="dashboard.loading">
        Actualiser
      </button>
    </form>
  </header>

  <EmptyState
    v-if="!context.selection"
    title="Sélectionnez un dossier"
    description="Les indicateurs apparaîtront uniquement dans le périmètre autorisé."
  />
  <EmptyState
    v-else-if="!context.exercises.length && !context.loading"
    title="Aucun exercice comptable"
    description="Créez un exercice avant de calculer le tableau de bord."
  />
  <template v-else>
    <ErrorSummary :message="dashboard.error" />
    <SkeletonBlock v-if="dashboard.loading && !projection" :lines="8" />

    <template v-if="projection">
      <section class="dashboard-scope" aria-label="Périmètre du calcul">
        <span>
          <small>Exercice</small>
          <strong>{{ projection.scope.exercise.label }}</strong>
        </span>
        <span>
          <small>Date d’arrêté</small>
          <strong>{{ projection.scope.as_of_date }}</strong>
        </span>
        <span>
          <small>Période</small>
          <strong>
            {{ projection.scope.period?.label || 'Aucune période correspondante' }}
            <span
              v-if="projection.scope.period"
              class="status-badge"
              :class="`status-${projection.scope.period.status}`"
            >
              {{ projection.scope.period.status === 'fermee' ? 'Fermée' : 'Ouverte' }}
            </span>
          </strong>
        </span>
        <span>
          <small>Devise de base</small>
          <strong>{{ currency }}</strong>
        </span>
      </section>

      <EmptyState
        v-if="projection.empty_state.is_empty"
        title="Aucune activité à cette date"
        :description="projection.empty_state.message || 'Aucune donnée disponible.'"
      />

      <section class="metric-grid" aria-label="Indicateurs principaux">
        <article class="metric-card">
          <span>Trésorerie comptable</span>
          <strong>{{ formatMoney(projection.treasury.accounting_balance_cents, currency) }}</strong>
          <small>Comptes de trésorerie configurés</small>
        </article>
        <article class="metric-card">
          <span>Chiffre d’affaires</span>
          <strong>{{ formatMoney(projection.profit_and_loss.revenue_cents, currency) }}</strong>
          <small>Produits comptabilisés</small>
        </article>
        <article class="metric-card">
          <span>Charges</span>
          <strong>{{ formatMoney(projection.profit_and_loss.expenses_cents, currency) }}</strong>
          <small>Charges comptabilisées</small>
        </article>
        <article class="metric-card">
          <span>Résultat courant</span>
          <strong>{{ formatMoney(projection.profit_and_loss.result_cents, currency) }}</strong>
          <small>Avant clôture</small>
        </article>
      </section>

      <section class="dashboard-two-columns">
        <article class="panel">
          <div class="panel-heading">
            <div>
              <p class="eyebrow">Clients</p>
              <h2>Créances ouvertes</h2>
            </div>
            <strong>{{ formatMoney(projection.open_items.receivables.open_cents, currency) }}</strong>
          </div>
          <p>
            {{ projection.open_items.receivables.open_count }} document(s),
            dont {{ projection.open_items.receivables.overdue_count }} échu(s) pour
            <strong>{{ formatMoney(projection.open_items.receivables.overdue_cents, currency) }}</strong>.
          </p>
        </article>
        <article class="panel">
          <div class="panel-heading">
            <div>
              <p class="eyebrow">Fournisseurs</p>
              <h2>Dettes ouvertes</h2>
            </div>
            <strong>{{ formatMoney(projection.open_items.payables.open_cents, currency) }}</strong>
          </div>
          <p>
            {{ projection.open_items.payables.open_count }} document(s),
            dont {{ projection.open_items.payables.overdue_count }} échu(s) pour
            <strong>{{ formatMoney(projection.open_items.payables.overdue_cents, currency) }}</strong>.
          </p>
        </article>
      </section>

      <section class="panel">
        <div class="panel-heading">
          <div>
            <p class="eyebrow">Échéancier</p>
            <h2>Aging des créances et dettes</h2>
          </div>
        </div>
        <DataTable
          caption="Répartition des montants ouverts par ancienneté"
          :columns="[
            { key: 'bucket', label: 'Ancienneté' },
            { key: 'receivables', label: 'Créances' },
            { key: 'payables', label: 'Dettes' }
          ]"
          :rows="agingRows"
        />
      </section>

      <section class="panel">
        <div class="panel-heading">
          <div>
            <p class="eyebrow">Banque et grand livre</p>
            <h2>Trésorerie par compte</h2>
          </div>
          <span>
            {{ projection.treasury.bank_balance_coverage.comparable_accounts }}
            / {{ projection.treasury.bank_balance_coverage.total_accounts }}
            solde(s) bancaire(s) comparable(s)
          </span>
        </div>
        <DataTable
          v-if="treasuryRows.length"
          caption="Soldes comptables et bancaires par compte de trésorerie"
          :columns="[
            { key: 'account', label: 'Compte' },
            { key: 'accounting', label: 'Solde comptable' },
            { key: 'bank', label: 'Dernier solde bancaire' },
            { key: 'bank_date', label: 'Date banque' },
            { key: 'difference', label: 'Écart' }
          ]"
          :rows="treasuryRows"
        />
        <EmptyState
          v-else
          title="Aucun compte de trésorerie"
          description="Configurez une banque, une caisse, un compte postal ou une carte."
        />
      </section>

      <section class="dashboard-two-columns">
        <article class="action-card">
          <span>Lignes bancaires non rapprochées</span>
          <strong>{{ projection.operations.unreconciled_bank_lines.count }}</strong>
          <small>
            Volume absolu :
            {{ formatMoney(projection.operations.unreconciled_bank_lines.absolute_cents, currency) }}
          </small>
          <RouterLink to="/liquidites/rapprochement">Ouvrir le rapprochement</RouterLink>
        </article>
        <article class="action-card">
          <span>Paiements à traiter</span>
          <strong>{{ projection.operations.payments_to_process.count }}</strong>
          <small>
            Non alloué :
            {{ formatMoney(projection.operations.payments_to_process.amount_cents, currency) }}
          </small>
          <RouterLink to="/facturation/paiements">Ouvrir le lettrage</RouterLink>
        </article>
      </section>

      <section class="panel">
        <div class="panel-heading">
          <div>
            <p class="eyebrow">Activité</p>
            <h2>Dernières écritures</h2>
          </div>
          <RouterLink to="/compta/journal">Voir le journal</RouterLink>
        </div>
        <DataTable
          v-if="recentRows.length"
          caption="Dix dernières écritures validées à la date d’arrêté"
          :columns="[
            { key: 'date', label: 'Date' },
            { key: 'number', label: 'N°' },
            { key: 'label', label: 'Libellé' },
            { key: 'journal', label: 'Journal' },
            { key: 'amount', label: 'Montant' },
            { key: 'source', label: 'Source' }
          ]"
          :rows="recentRows"
        >
          <template #cell-source="{ row }">
            <RouterLink :to="String(row.source_path)">Consulter</RouterLink>
          </template>
        </DataTable>
        <EmptyState
          v-else
          title="Aucune écriture validée"
          description="Les brouillons ne sont volontairement pas inclus."
        />
      </section>

      <details class="calculation-details">
        <summary>Définitions du calcul</summary>
        <ul>
          <li>{{ projection.calculation.revenue_definition }}</li>
          <li>{{ projection.calculation.expenses_definition }}</li>
          <li>{{ projection.calculation.open_items_definition }}</li>
          <li>{{ projection.calculation.overdue_definition }}</li>
        </ul>
      </details>
    </template>
  </template>
</template>
