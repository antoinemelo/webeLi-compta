<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import AccountCombobox from '@/components/ui/AccountCombobox.vue';
import CompactTabs from '@/components/ui/CompactTabs.vue';
import DataTable from '@/components/ui/DataTable.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import FormField from '@/components/ui/FormField.vue';
import MarketLineChart from '@/components/ui/MarketLineChart.vue';
import ModalDialog from '@/components/ui/ModalDialog.vue';
import SkeletonBlock from '@/components/ui/SkeletonBlock.vue';
import { useToastFeedback } from '@/composables/toastFeedback';
import { subNavigation } from '@/router/navigation';
import { useContextStore } from '@/stores/context';
import { useExpensesStore } from '@/stores/expenses';
import { useTreasuryStore } from '@/stores/treasury';
import { useNotificationStore } from '@/stores/notifications';
import type { ExpenseItem, PublicMarketSeries } from '@/api/contracts';

const route = useRoute();
const context = useContextStore();
const store = useExpensesStore();
const treasury = useTreasuryStore();
useToastFeedback(store, false);
useToastFeedback(treasury, false);
const notifications = useNotificationStore();
const activeTab = computed(() => String(route.params.tab || 'use'));
const workspace = computed(() => store.workspace);
const exchangeMode = ref<'moyenne' | 'fin_mois'>('moyenne');
const selectedInterestCode = ref('');
const reconciliationSection = ref<'import' | 'suggestion' | 'matching'>('import');
const ratesSection = ref<'exchange' | 'interest'>('exchange');
const today = new Date().toISOString().slice(0, 10);
const selectedId = ref(0);
const expenseDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const recurrenceDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const paymentDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const attachment = ref<{ name: string; content_base64: string } | null>(null);
const statement = ref<{ name: string; content_base64: string } | null>(null);
const importPreview = ref<Record<string, unknown> | null>(null);
const importAccountId = ref(0);
const selectedBankLines = ref<number[]>([]);
const selectedAccountingLines = ref<number[]>([]);
const reconciliationAccountId = ref(0);
const suggestionDraft = reactive({
  bank_line_id: 0,
  counterpart_account_id: 0,
  label: '',
  confidence: 80,
  reason: ''
});
const paymentDraft = reactive({
  contact_id: 0,
  direction: 'encaissement' as 'encaissement' | 'decaissement',
  date: today,
  amount: '',
  reference: '',
  treasury_account_id: 0,
  bank_line_id: 0
});
const allocationDraft = reactive({ payment_id: 0, document_id: 0, amount: '' });
const selectedDebtIds = ref<number[]>([]);
const batchDraft = reactive({
  treasury_account_id: 0,
  execution_date: today
});
const confirmationDraft = reactive({
  bank_line_id: 0,
  fee_account_id: 0
});
const selected = computed<ExpenseItem | null>(
  () => workspace.value?.expenses.find((item) => item.id === selectedId.value) ?? null
);
const selectedBankTotal = computed(() => (
  treasury.workspace?.bank_lines
    .filter((item) => selectedBankLines.value.includes(item.id))
    .reduce((total, item) => total + item.amount_cents, 0) ?? 0
));
const selectedAccountingTotal = computed(() => (
  treasury.workspace?.accounting_lines
    .filter((item) => selectedAccountingLines.value.includes(item.id))
    .reduce((total, item) => total + item.amount_cents, 0) ?? 0
));
const selectedReconciliationDifference = computed(
  () => selectedBankTotal.value - selectedAccountingTotal.value
);

type DraftLine = {
  libelle: string;
  prix: string;
  mode_saisie: 'net' | 'brut';
  compte_id: number;
  code_tva_id: number;
};

const expense = reactive({
  contact_id: 0,
  document_date: today,
  due_date: today,
  external_number: '',
  collective_account_id: 0,
  lines: [newLine()] as DraftLine[]
});

const recurrence = reactive({
  contact_id: 0,
  label: '',
  frequency: 'mensuelle' as 'hebdomadaire' | 'mensuelle' | 'trimestrielle' | 'annuelle',
  interval: 1,
  next_date: today,
  end_date: '',
  due_days: 30,
  collective_account_id: 0,
  external_prefix: 'REC',
  lines: [newLine()] as DraftLine[]
});

function resetExpenseDraft(): void {
  Object.assign(expense, {
    contact_id: 0,
    document_date: today,
    due_date: today,
    external_number: '',
    collective_account_id: 0,
    lines: [newLine()]
  });
  attachment.value = null;
}

function resetRecurrenceDraft(): void {
  Object.assign(recurrence, {
    contact_id: 0,
    label: '',
    frequency: 'mensuelle',
    interval: 1,
    next_date: today,
    end_date: '',
    due_days: 30,
    collective_account_id: 0,
    external_prefix: 'REC',
    lines: [newLine()]
  });
}

function openExpenseDialog(): void {
  resetExpenseDraft();
  expenseDialog.value?.open();
}

function openRecurrenceDialog(): void {
  resetRecurrenceDraft();
  recurrenceDialog.value?.open();
}

function openPaymentDialog(): void {
  Object.assign(paymentDraft, {
    contact_id: 0,
    direction: 'encaissement',
    date: today,
    amount: '',
    reference: '',
    treasury_account_id: 0,
    bank_line_id: 0
  });
  paymentDialog.value?.open();
}

const expenseRows = computed(() => (workspace.value?.expenses ?? []).map((item) => ({
  ...item,
  display_number: item.number || `Brouillon #${item.id}`,
  amount: money(item.gross_cents, item.currency),
  open: money(item.open_cents, item.currency),
  status_label: statusLabel(item.status)
})));

const recurrenceRows = computed(() => (workspace.value?.recurrences ?? []).map((item) => ({
  ...item,
  cadence: `${item.frequency}${item.interval > 1 ? ` × ${item.interval}` : ''}`,
  status_label: statusLabel(item.status)
})));

const exerciseId = computed(() => (
  context.context?.selection?.exercise?.id
  ?? treasury.workspace?.catalog.exercises.find((item) => item.statut === 'ouvert')?.id
  ?? 0
));

const exchangeSeries = computed(() => (
  treasury.exchangeHistory?.series.filter((item) => item.mode === exchangeMode.value) ?? []
));

const exchangeChartSeries = computed(() => marketChartSeries(
  treasury.exchangeHistory?.periods ?? [],
  exchangeSeries.value,
  (series) => `${series.currency} · ${series.base_unit === 1 ? '1 unité' : `${series.base_unit} unités`}`
));

const selectedInterestSeries = computed(() => (
  treasury.interestHistory?.series.find((item) => item.code === selectedInterestCode.value)
  ?? treasury.interestHistory?.series[0]
  ?? null
));

const interestChartSeries = computed(() => {
  const selected = selectedInterestSeries.value;
  return selected
    ? marketChartSeries(
        treasury.interestHistory?.periods ?? [],
        [selected],
        (series) => series.label
      )
    : [];
});

onMounted(load);
watch(
  () => context.context?.selection?.dossier.id,
  () => {
    selectedId.value = 0;
    store.clear();
    treasury.clear();
    void load();
  }
);

async function load(): Promise<void> {
  if (!context.context?.selection) return;
  await Promise.all([store.load(), treasury.load()]);
  await loadMarketTab();
}

watch(activeTab, () => { void loadMarketTab(); });
watch(
  () => context.context?.selection?.exercise?.id,
  () => { void loadMarketTab(); }
);

async function loadMarketTab(): Promise<void> {
  if (exerciseId.value < 1) return;
  if (activeTab.value === 'taux') {
    await treasury.loadExchangeHistory(exerciseId.value);
    await treasury.loadInterestHistory(exerciseId.value);
    if (
      !treasury.interestHistory?.series.some(
        (item) => item.code === selectedInterestCode.value
      )
    ) {
      selectedInterestCode.value = treasury.interestHistory?.series[0]?.code ?? '';
    }
  }
}

function marketChartSeries(
  periods: string[],
  series: PublicMarketSeries[],
  label: (series: PublicMarketSeries) => string
): Array<{ key: string; label: string; values: Array<string | null> }> {
  return series.map((item) => {
    const values = new Map(item.values.map((value) => [value.period, value.per_unit]));
    return {
      key: item.code,
      label: label(item),
      values: periods.map((period) => values.get(period) ?? null)
    };
  });
}

function marketValue(series: PublicMarketSeries, period: string): string {
  return series.values.find((value) => value.period === period)?.per_unit ?? '';
}

function periodLabel(period: string): string {
  const [year, month] = period.split('-').map(Number);
  return new Intl.DateTimeFormat('fr-CH', {
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC'
  }).format(new Date(Date.UTC(year, month - 1, 1)));
}

function rate(value: string, suffix = ''): string {
  if (value === '') return '—';
  const number = Number(value);
  return Number.isFinite(number)
    ? `${new Intl.NumberFormat('fr-CH', { maximumFractionDigits: 6 }).format(number)}${suffix}`
    : '—';
}

function newLine(): DraftLine {
  return { libelle: '', prix: '', mode_saisie: 'net', compte_id: 0, code_tva_id: 0 };
}

function money(cents: number, currency = 'CHF'): string {
  const sign = cents < 0 ? '−' : '';
  const absolute = Math.abs(cents);
  return `${sign}${Math.floor(absolute / 100).toLocaleString('fr-CH')}.${
    String(absolute % 100).padStart(2, '0')
  } ${currency}`;
}

function cents(value: string): number {
  const normalized = value.trim().replace(',', '.');
  const match = normalized.match(/^(\d+)(?:\.(\d{0,2}))?$/);
  if (!match) throw new Error('Montant invalide : utilisez au plus deux décimales.');
  return Number(match[1]) * 100 + Number((match[2] || '').padEnd(2, '0'));
}

function statusLabel(status: string): string {
  return {
    brouillon: 'Brouillon',
    a_approuver: 'À approuver',
    approuve: 'Approuvé',
    comptabilise: 'Comptabilisé',
    annule: 'Annulé',
    prepare: 'Préparé',
    exporte: 'Exporté',
    confirme: 'Confirmé par relevé',
    proposee: 'Proposée',
    acceptee: 'Acceptée',
    refusee: 'Refusée',
    actif: 'Actif',
    pause: 'En pause',
    termine: 'Terminé'
  }[status] || status;
}

function dateLabel(value: string): string {
  const [year, month, day] = value.split('-').map(Number);
  if (!year || !month || !day) return value || '—';
  return new Intl.DateTimeFormat('fr-CH', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: 'UTC'
  }).format(new Date(Date.UTC(year, month - 1, day)));
}

function fileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} octets`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} Ko`;
  return `${(bytes / (1024 * 1024)).toLocaleString('fr-CH', {
    maximumFractionDigits: 1
  })} Mo`;
}

function accountLabel(number: string, label: string): string {
  return [number, label].filter(Boolean).join(' ');
}

function apiLines(lines: DraftLine[], date: string): Array<Record<string, unknown>> {
  return lines.map((line) => ({
    libelle: line.libelle,
    quantite_milli: 1000,
    prix_unitaire_centimes: cents(line.prix),
    mode_saisie: line.mode_saisie,
    compte_id: Number(line.compte_id),
    code_tva_id: Number(line.code_tva_id),
    date_prestation: date
  }));
}

async function fileSelected(event: Event): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) {
    attachment.value = null;
    return;
  }
  const dataUrl = await new Promise<string>((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || ''));
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(file);
  });
  attachment.value = {
    name: file.name,
    content_base64: dataUrl.slice(dataUrl.indexOf(',') + 1)
  };
}

async function statementSelected(event: Event): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) {
    statement.value = null;
    return;
  }
  const dataUrl = await new Promise<string>((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || ''));
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(file);
  });
  statement.value = {
    name: file.name,
    content_base64: dataUrl.slice(dataUrl.indexOf(',') + 1)
  };
}

async function previewStatement(): Promise<void> {
  if (!statement.value) return;
  try {
    importPreview.value = await treasury.mutate<Record<string, unknown>>(
      '/liquidites/banque/imports/previsualiser',
      {
        treasury_account_id: Number(importAccountId.value),
        filename: statement.value.name,
        content_base64: statement.value.content_base64
      },
      false
    );
    notifications.push('Relevé prévisualisé sans écriture comptable.', 'success');
  } catch {
    notifications.push(treasury.error, 'warning');
  }
}

async function confirmStatement(): Promise<void> {
  const importId = Number(importPreview.value?.import_id || 0);
  if (!importId) return;
  try {
    await treasury.mutate('/liquidites/banque/imports/confirmer', { import_id: importId });
    importPreview.value = null;
    statement.value = null;
    notifications.push('Relevé confirmé, source et empreinte conservées.', 'success');
  } catch {
    notifications.push(treasury.error, 'warning');
  }
}

async function createReconciliation(): Promise<void> {
  try {
    await treasury.mutate('/liquidites/banque/rapprochements', {
      treasury_account_id: Number(reconciliationAccountId.value),
      bank_line_ids: [...selectedBankLines.value],
      accounting_line_ids: [...selectedAccountingLines.value],
      tolerance_cents: 0,
      label: 'Rapprochement manuel'
    });
    selectedBankLines.value = [];
    selectedAccountingLines.value = [];
    notifications.push('Rapprochement confirmé au centime.', 'success');
  } catch {
    notifications.push(treasury.error, 'warning');
  }
}

async function cancelReconciliation(item: { id: number; version: number }): Promise<void> {
  if (!window.confirm('Annuler ce rapprochement et libérer ses lignes ?')) return;
  try {
    await treasury.mutate('/liquidites/banque/rapprochements/annuler', {
      reconciliation_id: item.id,
      version: item.version
    });
    notifications.push('Rapprochement annulé et audité.', 'success');
  } catch {
    notifications.push(treasury.error, 'warning');
  }
}

async function proposeSuggestion(): Promise<void> {
  try {
    await treasury.mutate('/liquidites/banque/suggestions', {
      bank_line_id: Number(suggestionDraft.bank_line_id),
      counterpart_account_id: Number(suggestionDraft.counterpart_account_id),
      label: suggestionDraft.label,
      confidence: Number(suggestionDraft.confidence),
      reason: suggestionDraft.reason
    });
    Object.assign(suggestionDraft, {
      bank_line_id: 0,
      counterpart_account_id: 0,
      label: '',
      confidence: 80,
      reason: ''
    });
    notifications.push('Suggestion enregistrée sans écriture comptable.', 'success');
  } catch {
    notifications.push(treasury.error, 'warning');
  }
}

async function acceptSuggestion(id: number): Promise<void> {
  const exercise = treasury.workspace?.catalog.exercises.find(
    (entry) => entry.statut === 'ouvert'
  );
  const journal = treasury.workspace?.catalog.journals.find(
    (entry) => ['banque', 'general'].includes(entry.type)
  );
  if (!exercise || !journal) {
    notifications.push('Configurez un exercice ouvert et un journal de banque.', 'warning');
    return;
  }
  try {
    await treasury.mutate('/liquidites/banque/suggestions/accepter', {
      suggestion_id: id,
      exercise_id: exercise.id,
      journal_id: journal.id
    });
    notifications.push('Suggestion acceptée et comptabilisée explicitement.', 'success');
  } catch {
    notifications.push(treasury.error, 'warning');
  }
}

async function createPayment(): Promise<void> {
  const treasuryAccount = treasury.workspace?.treasury_accounts.find(
    (item) => item.id === Number(paymentDraft.treasury_account_id)
  );
  if (!treasuryAccount) return;
  try {
    await treasury.mutate('/liquidites/lettrage/paiements', {
      contact_id: Number(paymentDraft.contact_id),
      direction: paymentDraft.direction,
      date: paymentDraft.date,
      amount_cents: cents(paymentDraft.amount),
      reference: paymentDraft.reference,
      ledger_account_id: treasuryAccount.ledger_account_id,
      bank_line_id: paymentDraft.bank_line_id || null,
      currency: treasuryAccount.currency
    });
    paymentDialog.value?.close();
    notifications.push('Paiement créé indépendamment des factures.', 'success');
  } catch {
    notifications.push(treasury.error, 'warning');
  }
}

async function allocatePayment(): Promise<void> {
  try {
    await treasury.mutate('/liquidites/lettrage/allocations', {
      payment_id: Number(allocationDraft.payment_id),
      document_id: Number(allocationDraft.document_id),
      amount_cents: cents(allocationDraft.amount)
    });
    notifications.push('Paiement lettré.', 'success');
  } catch {
    notifications.push(treasury.error, 'warning');
  }
}

async function unallocate(id: number): Promise<void> {
  if (!window.confirm('Délettrer cette allocation ?')) return;
  try {
    await treasury.mutate('/liquidites/lettrage/allocations/annuler', {
      allocation_id: id
    });
    notifications.push('Allocation annulée.', 'success');
  } catch {
    notifications.push(treasury.error, 'warning');
  }
}

async function prepareBatch(): Promise<void> {
  const debts = treasury.workspace?.payable_debts.filter(
    (item) => selectedDebtIds.value.includes(item.id)
  ) ?? [];
  try {
    await treasury.mutate('/liquidites/paiements/lots', {
      treasury_account_id: Number(batchDraft.treasury_account_id),
      execution_date: batchDraft.execution_date,
      idempotency_key: crypto.randomUUID(),
      orders: debts.map((item) => ({
        document_id: item.id,
        amount_cents: item.open_cents
      }))
    });
    selectedDebtIds.value = [];
    notifications.push('Lot préparé. Aucun document n’est encore marqué payé.', 'success');
  } catch {
    notifications.push(treasury.error, 'warning');
  }
}

async function exportBatch(item: { id: number; version: number }): Promise<void> {
  try {
    const result = await treasury.mutate<{
      filename: string; hash: string; content_base64: string; transmitted: boolean;
    }>('/liquidites/paiements/lots/exporter', {
      batch_id: item.id,
      version: item.version
    });
    const bytes = Uint8Array.from(atob(result.content_base64), (character) => character.charCodeAt(0));
    const url = URL.createObjectURL(new Blob([bytes], { type: 'application/xml' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = result.filename;
    link.click();
    URL.revokeObjectURL(url);
    await treasury.load();
    notifications.push('pain.001 généré et téléchargé — non transmis.', 'success');
  } catch {
    notifications.push(treasury.error, 'warning');
  }
}

async function confirmBatch(item: { id: number }): Promise<void> {
  const exercise = treasury.workspace?.catalog.exercises.find((entry) => entry.statut === 'ouvert');
  const journal = treasury.workspace?.catalog.journals.find(
    (entry) => ['banque', 'general'].includes(entry.type)
  );
  if (!exercise || !journal) {
    notifications.push('Configurez un exercice ouvert et un journal de banque.', 'warning');
    return;
  }
  try {
    await treasury.mutate('/liquidites/paiements/lots/confirmer', {
      batch_id: item.id,
      bank_line_id: Number(confirmationDraft.bank_line_id),
      exercise_id: exercise.id,
      journal_id: journal.id,
      fee_account_id: confirmationDraft.fee_account_id || null
    });
    notifications.push('Lot confirmé par relevé, comptabilisé, lettré et rapproché.', 'success');
  } catch {
    notifications.push(treasury.error, 'warning');
  }
}

async function saveExpense(): Promise<void> {
  try {
    await store.mutate('/liquidites/depenses', {
      contact_id: Number(expense.contact_id),
      document_date: expense.document_date,
      due_date: expense.due_date,
      external_number: expense.external_number,
      collective_account_id: Number(expense.collective_account_id),
      lines: apiLines(expense.lines, expense.document_date),
      attachment: attachment.value
    });
    expenseDialog.value?.close();
    attachment.value = null;
    notifications.push('Dépense enregistrée comme brouillon, sans comptabilisation.', 'success');
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function transition(path: string, item: ExpenseItem, label: string): Promise<void> {
  try {
    await store.mutate(path, { document_id: item.id, version: item.version });
    notifications.push(label, 'success');
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function postExpense(item: ExpenseItem): Promise<void> {
  const exercise = workspace.value?.catalog.exercises[0];
  const journal = workspace.value?.catalog.journals[0];
  if (!exercise || !journal) {
    notifications.push('Configurez un exercice ouvert et un journal.', 'warning');
    return;
  }
  try {
    await store.mutate('/liquidites/depenses/comptabiliser', {
      document_id: item.id,
      exercise_id: exercise.id,
      journal_id: journal.id
    });
    notifications.push('Dépense comptabilisée.', 'success');
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function cancelExpense(item: ExpenseItem): Promise<void> {
  if (!window.confirm('Annuler cette dépense ? Une écriture validée sera contre-passée.')) return;
  try {
    await store.mutate('/liquidites/depenses/annuler', {
      document_id: item.id,
      version: item.version,
      date: today
    });
    notifications.push('Dépense annulée.', 'success');
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function saveRecurrence(): Promise<void> {
  try {
    await store.mutate('/liquidites/recurrences', {
      contact_id: Number(recurrence.contact_id),
      label: recurrence.label,
      frequency: recurrence.frequency,
      interval: Number(recurrence.interval),
      next_date: recurrence.next_date,
      end_date: recurrence.end_date || null,
      due_days: Number(recurrence.due_days),
      collective_account_id: Number(recurrence.collective_account_id),
      external_prefix: recurrence.external_prefix,
      lines: apiLines(recurrence.lines, recurrence.next_date)
    });
    recurrenceDialog.value?.close();
    notifications.push('Récurrence enregistrée.', 'success');
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function generateDue(): Promise<void> {
  try {
    await store.mutate('/liquidites/recurrences/generer', { through_date: today });
    notifications.push('Échéances générées sans doublon.', 'success');
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function toggleRecurrence(item: {
  id: number; version: number; status: string;
}): Promise<void> {
  try {
    await store.mutate('/liquidites/recurrences/pause', {
      recurrence_id: item.id,
      version: item.version,
      paused: item.status === 'actif'
    });
  } catch {
    notifications.push(store.error, 'warning');
  }
}
</script>

<template>
  <section class="page-stack">
    <header class="page-heading">
      <div>
        <h1>Liquidités</h1>
        <p>Dépenses, validation et récurrences reliées au grand livre.</p>
      </div>
    </header>

    <CompactTabs :items="subNavigation.liquidity" label="Navigation des liquidités" />
    <ErrorSummary v-if="store.error" title="Impossible de charger les dépenses" :message="store.error" />
    <ErrorSummary v-if="treasury.error" title="Impossible de charger les opérations de trésorerie" :message="treasury.error" />
    <ErrorSummary v-if="treasury.marketError" title="Impossible de charger les données de marché" :message="treasury.marketError" />
    <SkeletonBlock v-if="store.loading && !workspace" :lines="7" />

    <template v-else-if="workspace && activeTab === 'use'">
      <div class="toolbar">
        <div>
          <p>La création reste toujours en brouillon. Paiement et allocation sont séparés.</p>
        </div>
        <div class="button-row">
          <button
            v-if="workspace.capabilities.manage"
            class="button secondary"
            type="button"
            @click="openRecurrenceDialog"
          >Nouvelle récurrence</button>
          <button
            v-if="workspace.capabilities.manage"
            class="button primary"
            type="button"
            @click="openExpenseDialog"
          >Nouvelle dépense</button>
        </div>
      </div>

      <ModalDialog
        ref="expenseDialog"
        title="Nouvelle dépense ponctuelle"
        description="Renseignez le fournisseur puis répartissez la dépense entre ses contreparties."
        wide
        @closed="resetExpenseDraft"
      >
      <form class="modal-editor" @submit.prevent="saveExpense">
        <div class="form-grid">
          <FormField id="expense-supplier" label="Fournisseur">
            <template #default="{ describedBy }">
              <select id="expense-supplier" v-model.number="expense.contact_id" :aria-describedby="describedBy" required>
                <option :value="0" disabled>Sélectionner</option>
                <option v-for="item in workspace.catalog.suppliers" :key="item.id" :value="item.id">{{ item.label }}</option>
              </select>
            </template>
          </FormField>
          <FormField id="expense-number" label="Référence fournisseur">
            <template #default="{ describedBy }">
              <input id="expense-number" v-model="expense.external_number" :aria-describedby="describedBy" required>
            </template>
          </FormField>
          <FormField id="expense-date" label="Date du document">
            <template #default="{ describedBy }"><input id="expense-date" v-model="expense.document_date" type="date" :aria-describedby="describedBy" required></template>
          </FormField>
          <FormField id="expense-due" label="Échéance">
            <template #default="{ describedBy }"><input id="expense-due" v-model="expense.due_date" type="date" :aria-describedby="describedBy" required></template>
          </FormField>
          <FormField id="expense-collective" label="Paiement fournisseur">
            <template #default="{ describedBy }">
              <AccountCombobox
                id="expense-collective"
                v-model="expense.collective_account_id"
                :options="workspace.catalog.accounts"
                :aria-describedby="describedBy"
                required
              />
            </template>
          </FormField>
          <FormField id="expense-proof" label="Justificatif facultatif" hint="PDF, JPEG, PNG ou WebP, 10 Mo maximum.">
            <template #default="{ describedBy }"><input id="expense-proof" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" :aria-describedby="describedBy" @change="fileSelected"></template>
          </FormField>
        </div>
        <fieldset v-for="(line, index) in expense.lines" :key="index" class="line-editor">
          <legend>Contrepartie {{ index + 1 }}</legend>
          <input v-model="line.libelle" aria-label="Libellé" placeholder="Libellé" required>
          <input v-model="line.prix" aria-label="Montant" inputmode="decimal" placeholder="Montant" required>
          <AccountCombobox
            v-model="line.compte_id"
            :options="workspace.catalog.accounts"
            aria-label="Compte de charge"
            placeholder="Compte"
            required
          />
          <select v-model.number="line.code_tva_id" aria-label="Code TVA" required>
            <option :value="0" disabled>TVA</option>
            <option v-for="item in workspace.catalog.vat_codes" :key="item.id" :value="item.id">{{ item.code }} · {{ item.label }}</option>
          </select>
          <select v-model="line.mode_saisie" aria-label="Mode de saisie">
            <option value="net">Montant net</option>
            <option value="brut">Montant brut</option>
          </select>
          <button v-if="expense.lines.length > 1" type="button" class="button ghost" @click="expense.lines.splice(index, 1)">Retirer</button>
        </fieldset>
        <div class="button-row">
          <button type="button" class="button ghost" @click="expense.lines.push(newLine())">Ajouter une contrepartie</button>
          <button class="button primary" :disabled="store.saving">Enregistrer le brouillon</button>
        </div>
      </form>
      </ModalDialog>

      <DataTable
        v-if="expenseRows.length"
        caption="Dépenses du dossier"
        :columns="[
          { key: 'display_number', label: 'Dépense' },
          { key: 'supplier', label: 'Fournisseur' },
          { key: 'due_date', label: 'Échéance' },
          { key: 'amount', label: 'Montant' },
          { key: 'open', label: 'Ouvert' },
          { key: 'status_label', label: 'Statut' },
          { key: 'actions', label: 'Actions' }
        ]"
        :rows="expenseRows"
      >
        <template #cell-display_number="{ row }">
          <button class="table-link" type="button" @click="selectedId = Number(row.id)">{{ row.display_number }}</button>
        </template>
        <template #cell-actions="{ row }">
          <div class="table-actions">
            <button
              v-if="row.status === 'brouillon' && workspace.capabilities.manage"
              type="button"
              @click="transition('/liquidites/depenses/soumettre', row as ExpenseItem, 'Dépense transmise pour approbation.')"
            >Soumettre</button>
            <button
              v-if="row.status === 'a_approuver' && workspace.capabilities.approve"
              type="button"
              @click="transition('/liquidites/depenses/approuver', row as ExpenseItem, 'Dépense approuvée.')"
            >Approuver</button>
            <button
              v-if="row.status === 'approuve' && workspace.capabilities.post"
              type="button"
              @click="postExpense(row as ExpenseItem)"
            >Comptabiliser</button>
            <button
              v-if="!['annule'].includes(String(row.status)) && workspace.capabilities.post"
              type="button"
              @click="cancelExpense(row as ExpenseItem)"
            >Annuler</button>
          </div>
        </template>
      </DataTable>
      <EmptyState v-else title="Aucune dépense" description="Ajoutez une dépense ponctuelle ou générez une échéance récurrente." />

      <article v-if="selected" class="detail-card expense-detail">
        <header class="expense-detail-heading">
          <div>
            <p class="eyebrow">Détail de la dépense</p>
            <div class="expense-title-row">
              <h3>{{ selected.number || `Brouillon #${selected.id}` }}</h3>
              <span :class="['status-chip', `status-${selected.status}`]">
                {{ statusLabel(selected.status) }}
              </span>
            </div>
            <p>{{ selected.supplier }}</p>
          </div>
          <button class="button ghost small" type="button" @click="selectedId = 0">
            Fermer
          </button>
        </header>

        <dl class="expense-metadata">
          <div>
            <dt>Référence fournisseur</dt>
            <dd>{{ selected.external_number || '—' }}</dd>
          </div>
          <div>
            <dt>Date du document</dt>
            <dd>{{ dateLabel(selected.document_date) }}</dd>
          </div>
          <div>
            <dt>Échéance</dt>
            <dd>{{ dateLabel(selected.due_date) }}</dd>
          </div>
          <div>
            <dt>Paiement fournisseur</dt>
            <dd>{{ accountLabel(selected.collective_account.number, selected.collective_account.label) }}</dd>
          </div>
          <div>
            <dt>Montant payé</dt>
            <dd>{{ money(selected.allocated_cents, selected.currency) }}</dd>
          </div>
          <div>
            <dt>Solde ouvert</dt>
            <dd>{{ money(selected.open_cents, selected.currency) }}</dd>
          </div>
          <div>
            <dt>Écriture comptable</dt>
            <dd>{{ selected.entry_id ? `#${selected.entry_id}` : 'Non comptabilisée' }}</dd>
          </div>
          <div>
            <dt>Justificatif</dt>
            <dd v-if="selected.attachment">
              {{ selected.attachment.name }}
              <small>{{ selected.attachment.type }} · {{ fileSize(selected.attachment.size) }}</small>
            </dd>
            <dd v-else>Absent</dd>
          </div>
        </dl>

        <div class="expense-lines">
          <h4>Ventilation</h4>
          <div class="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Libellé</th>
                  <th>Compte</th>
                  <th>TVA</th>
                  <th class="amount">Net</th>
                  <th class="amount">TVA</th>
                  <th class="amount">Total</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="line in selected.lines" :key="line.id">
                  <td>{{ line.label }}</td>
                  <td>{{ accountLabel(line.account_number, line.account_label) }}</td>
                  <td><strong>{{ line.vat_code }}</strong><small>{{ line.vat_label }}</small></td>
                  <td class="amount">{{ money(line.net_cents, selected.currency) }}</td>
                  <td class="amount">{{ money(line.vat_cents, selected.currency) }}</td>
                  <td class="amount">{{ money(line.gross_cents, selected.currency) }}</td>
                </tr>
              </tbody>
              <tfoot>
                <tr>
                  <th colspan="3">Total</th>
                  <th class="amount">{{ money(selected.net_cents, selected.currency) }}</th>
                  <th class="amount">{{ money(selected.vat_cents, selected.currency) }}</th>
                  <th class="amount">{{ money(selected.gross_cents, selected.currency) }}</th>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </article>

      <section class="recurrence-section">
        <div class="toolbar">
          <div><h2>Dépenses récurrentes</h2><p>Chaque échéance crée uniquement un brouillon à compléter et approuver.</p></div>
          <button v-if="workspace.capabilities.manage" class="button secondary" type="button" @click="generateDue">Générer jusqu’à aujourd’hui</button>
        </div>
        <ModalDialog
          ref="recurrenceDialog"
          title="Nouvelle récurrence"
          description="Définissez la cadence et les contreparties qui seront reprises dans chaque brouillon."
          wide
          @closed="resetRecurrenceDraft"
        >
        <form class="modal-editor" @submit.prevent="saveRecurrence">
          <div class="form-grid">
            <FormField id="rec-label" label="Nom du modèle"><template #default="{ describedBy }"><input id="rec-label" v-model="recurrence.label" :aria-describedby="describedBy" required></template></FormField>
            <FormField id="rec-supplier" label="Fournisseur"><template #default="{ describedBy }"><select id="rec-supplier" v-model.number="recurrence.contact_id" :aria-describedby="describedBy" required><option :value="0" disabled>Sélectionner</option><option v-for="item in workspace.catalog.suppliers" :key="item.id" :value="item.id">{{ item.label }}</option></select></template></FormField>
            <FormField id="rec-frequency" label="Périodicité"><template #default="{ describedBy }"><select id="rec-frequency" v-model="recurrence.frequency" :aria-describedby="describedBy"><option value="hebdomadaire">Hebdomadaire</option><option value="mensuelle">Mensuelle</option><option value="trimestrielle">Trimestrielle</option><option value="annuelle">Annuelle</option></select></template></FormField>
            <FormField id="rec-next" label="Prochaine échéance"><template #default="{ describedBy }"><input id="rec-next" v-model="recurrence.next_date" type="date" :aria-describedby="describedBy" required></template></FormField>
            <FormField id="rec-end" label="Fin facultative"><template #default="{ describedBy }"><input id="rec-end" v-model="recurrence.end_date" type="date" :aria-describedby="describedBy"></template></FormField>
            <FormField id="rec-prefix" label="Préfixe fournisseur"><template #default="{ describedBy }"><input id="rec-prefix" v-model="recurrence.external_prefix" :aria-describedby="describedBy" required></template></FormField>
            <FormField id="rec-collective" label="Paiement fournisseur"><template #default="{ describedBy }"><AccountCombobox id="rec-collective" v-model="recurrence.collective_account_id" :options="workspace.catalog.accounts" :aria-describedby="describedBy" required /></template></FormField>
          </div>
          <fieldset v-for="(line, index) in recurrence.lines" :key="index" class="line-editor">
            <legend>Contrepartie {{ index + 1 }}</legend>
            <input v-model="line.libelle" aria-label="Libellé récurrent" placeholder="Libellé" required>
            <input v-model="line.prix" aria-label="Montant récurrent" inputmode="decimal" placeholder="Montant" required>
            <AccountCombobox v-model="line.compte_id" :options="workspace.catalog.accounts" aria-label="Compte récurrent" placeholder="Compte" required />
            <select v-model.number="line.code_tva_id" aria-label="TVA récurrente" required><option :value="0" disabled>TVA</option><option v-for="item in workspace.catalog.vat_codes" :key="item.id" :value="item.id">{{ item.code }} · {{ item.label }}</option></select>
            <select v-model="line.mode_saisie" aria-label="Mode récurrent"><option value="net">Net</option><option value="brut">Brut</option></select>
          </fieldset>
          <div class="button-row"><button type="button" class="button ghost" @click="recurrence.lines.push(newLine())">Ajouter une contrepartie</button><button class="button primary" :disabled="store.saving">Enregistrer la récurrence</button></div>
        </form>
        </ModalDialog>
        <DataTable
          v-if="recurrenceRows.length"
          caption="Modèles de dépenses récurrentes"
          :columns="[{ key: 'label', label: 'Modèle' }, { key: 'supplier', label: 'Fournisseur' }, { key: 'cadence', label: 'Cadence' }, { key: 'next_date', label: 'Prochaine' }, { key: 'generations', label: 'Générées' }, { key: 'status_label', label: 'Statut' }, { key: 'actions', label: 'Actions' }]"
          :rows="recurrenceRows"
        >
          <template #cell-actions="{ row }"><button v-if="row.status !== 'termine' && workspace.capabilities.manage" type="button" @click="toggleRecurrence(row as { id: number; version: number; status: string })">{{ row.status === 'actif' ? 'Mettre en pause' : 'Reprendre' }}</button></template>
        </DataTable>
      </section>
    </template>

    <template v-else-if="workspace && treasury.workspace && activeTab === 'rapprochement'">
      <div class="toolbar">
        <div>
          <p>Le relevé, ses empreintes et le grand livre restent des sources distinctes.</p>
        </div>
      </div>
      <nav class="subtabs secondary-tabs section-tabs" aria-label="Étapes du rapprochement">
        <button :class="{ active: reconciliationSection === 'import' }" type="button" @click="reconciliationSection = 'import'">Importer un relevé</button>
        <button :class="{ active: reconciliationSection === 'suggestion' }" type="button" @click="reconciliationSection = 'suggestion'">Proposer une comptabilisation</button>
        <button :class="{ active: reconciliationSection === 'matching' }" type="button" @click="reconciliationSection = 'matching'">Associer banque et comptabilité</button>
      </nav>

      <form v-if="reconciliationSection === 'import' && treasury.workspace.capabilities.import" class="editor-card" @submit.prevent="previewStatement">
        <h3>Importer un relevé</h3>
        <div class="form-grid">
          <FormField id="statement-account" label="Compte bancaire">
            <template #default="{ describedBy }">
              <AccountCombobox
                id="statement-account"
                v-model="importAccountId"
                :options="treasury.workspace.treasury_accounts"
                number-key="ledger_number"
                label-key="ledger_label"
                :aria-describedby="describedBy"
                required
              />
            </template>
          </FormField>
          <FormField id="statement-file" label="Relevé CAMT ou PostFinance">
            <template #default="{ describedBy }">
              <input id="statement-file" type="file" accept=".xml,.csv" :aria-describedby="describedBy" required @change="statementSelected">
            </template>
          </FormField>
        </div>
        <div class="button-row"><button class="button primary" :disabled="treasury.saving">Prévisualiser</button></div>
      </form>

      <article v-if="reconciliationSection === 'import' && importPreview" class="detail-card">
        <h3>Prévisualisation sans comptabilisation</h3>
        <dl class="detail-grid">
          <div><dt>Format</dt><dd>{{ importPreview.format }}</dd></div>
          <div><dt>Mouvements</dt><dd>{{ Array.isArray(importPreview.transactions) ? importPreview.transactions.length : 0 }}</dd></div>
          <div><dt>Doublons</dt><dd>{{ importPreview.duplicate_count }}</dd></div>
          <div><dt>Devise</dt><dd>{{ importPreview.currency }}</dd></div>
        </dl>
        <button class="button primary" type="button" @click="confirmStatement">Confirmer l’import</button>
      </article>

      <form
        v-if="reconciliationSection === 'suggestion' && treasury.workspace.capabilities.suggest"
        class="editor-card"
        @submit.prevent="proposeSuggestion"
      >
        <h3>Proposer une comptabilisation</h3>
        <p>La proposition ne crée aucune écriture avant son acceptation explicite.</p>
        <div class="form-grid">
          <FormField id="suggestion-bank-line" label="Ligne bancaire">
            <template #default="{ describedBy }">
              <select id="suggestion-bank-line" v-model.number="suggestionDraft.bank_line_id" :aria-describedby="describedBy" required>
                <option :value="0" disabled>Sélectionner</option>
                <option v-for="line in treasury.workspace.bank_lines.filter((item) => !item.reconciliation_id)" :key="line.id" :value="line.id">{{ line.booking_date }} · {{ line.label || line.counterparty }} · {{ money(line.amount_cents, line.currency) }}</option>
              </select>
            </template>
          </FormField>
          <FormField id="suggestion-account" label="Compte de contrepartie">
            <template #default="{ describedBy }">
              <AccountCombobox
                id="suggestion-account"
                v-model="suggestionDraft.counterpart_account_id"
                :options="treasury.workspace.catalog.accounts"
                :aria-describedby="describedBy"
                required
              />
            </template>
          </FormField>
          <FormField id="suggestion-label" label="Libellé">
            <template #default="{ describedBy }"><input id="suggestion-label" v-model="suggestionDraft.label" :aria-describedby="describedBy" required></template>
          </FormField>
          <FormField id="suggestion-confidence" label="Confiance (%)">
            <template #default="{ describedBy }"><input id="suggestion-confidence" v-model.number="suggestionDraft.confidence" type="number" min="0" max="100" :aria-describedby="describedBy" required></template>
          </FormField>
          <FormField id="suggestion-reason" label="Justification">
            <template #default="{ describedBy }"><input id="suggestion-reason" v-model="suggestionDraft.reason" :aria-describedby="describedBy"></template>
          </FormField>
        </div>
        <button class="button secondary" :disabled="treasury.saving">Enregistrer la suggestion</button>
      </form>

      <DataTable
        v-if="reconciliationSection === 'suggestion' && treasury.workspace.suggestions.length"
        caption="Suggestions de comptabilisation"
        :columns="[
          { key: 'label', label: 'Libellé' },
          { key: 'reason', label: 'Justification' },
          { key: 'confidence', label: 'Confiance' },
          { key: 'status', label: 'Statut' },
          { key: 'actions', label: 'Actions' }
        ]"
        :rows="treasury.workspace.suggestions"
      >
        <template #cell-confidence="{ row }">{{ row.confidence }} %</template>
        <template #cell-status="{ row }">{{ statusLabel(String(row.status)) }}</template>
        <template #cell-actions="{ row }">
          <button
            v-if="row.status === 'proposee' && treasury.workspace?.capabilities.accept_suggestion"
            type="button"
            @click="acceptSuggestion(Number(row.id))"
          >Accepter et comptabiliser</button>
        </template>
      </DataTable>

      <section v-if="reconciliationSection === 'matching'" class="editor-card">
        <div class="toolbar">
          <div><h3>Associer banque et comptabilité</h3><p>Sélections 1–1, 1–N ou N–1 ; écart exigé à zéro.</p></div>
          <AccountCombobox
            v-model="reconciliationAccountId"
            :options="treasury.workspace.treasury_accounts"
            number-key="ledger_number"
            label-key="ledger_label"
            aria-label="Compte à rapprocher"
            placeholder="Compte bancaire"
            required
          />
        </div>
        <div class="reconciliation-grid">
          <div>
            <h4>Lignes bancaires non rapprochées</h4>
            <label
              v-for="line in treasury.workspace.bank_lines.filter((item) => !item.reconciliation_id && (!reconciliationAccountId || item.treasury_account_id === reconciliationAccountId))"
              :key="line.id"
              class="selection-row"
            >
              <input v-model="selectedBankLines" type="checkbox" :value="line.id">
              <span>{{ line.booking_date }} · {{ line.label || line.counterparty }}</span>
              <strong>{{ money(line.amount_cents, line.currency) }}</strong>
            </label>
          </div>
          <div>
            <h4>Lignes comptables non rapprochées</h4>
            <label
              v-for="line in treasury.workspace.accounting_lines.filter((item) => !item.reconciliation_id && (!reconciliationAccountId || item.treasury_account_id === reconciliationAccountId))"
              :key="line.id"
              class="selection-row"
            >
              <input v-model="selectedAccountingLines" type="checkbox" :value="line.id">
              <span>{{ line.accounting_date }} · {{ line.entry_number }} · {{ line.label }}</span>
              <strong>{{ money(line.amount_cents) }}</strong>
            </label>
          </div>
        </div>
        <dl class="reconciliation-totals">
          <div><dt>Total banque</dt><dd>{{ money(selectedBankTotal) }}</dd></div>
          <div><dt>Total comptabilité</dt><dd>{{ money(selectedAccountingTotal) }}</dd></div>
          <div>
            <dt>Écart</dt>
            <dd :class="{ 'difference-error': selectedReconciliationDifference !== 0 }">
              {{ money(selectedReconciliationDifference) }}
            </dd>
          </div>
        </dl>
        <div class="button-row">
          <button
            v-if="treasury.workspace.capabilities.reconcile"
            class="button primary"
            type="button"
            :disabled="!reconciliationAccountId || !selectedBankLines.length || !selectedAccountingLines.length || selectedReconciliationDifference !== 0"
            @click="createReconciliation"
          >Confirmer le rapprochement</button>
        </div>
      </section>

      <DataTable
        v-if="reconciliationSection === 'matching' && treasury.workspace.reconciliations.length"
        caption="Historique des rapprochements"
        :columns="[
          { key: 'created_at', label: 'Créé le' },
          { key: 'label', label: 'Libellé' },
          { key: 'bank_line_count', label: 'Banque' },
          { key: 'accounting_line_count', label: 'Comptabilité' },
          { key: 'difference_cents', label: 'Écart' },
          { key: 'status', label: 'Statut' },
          { key: 'actions', label: 'Actions' }
        ]"
        :rows="treasury.workspace.reconciliations"
      >
        <template #cell-difference_cents="{ row }">{{ money(Number(row.difference_cents)) }}</template>
        <template #cell-status="{ row }">{{ statusLabel(String(row.status)) }}</template>
        <template #cell-actions="{ row }">
          <button
            v-if="row.status === 'confirme' && treasury.workspace?.capabilities.reconcile"
            type="button"
            @click="cancelReconciliation(row as { id: number; version: number })"
          >Annuler</button>
        </template>
      </DataTable>
    </template>

    <template v-else-if="workspace && treasury.workspace && activeTab === 'lettrage'">
      <div class="toolbar">
        <div><p>Un paiement reste indépendant et peut couvrir plusieurs documents.</p></div>
      </div>
      <form v-if="treasury.workspace.capabilities.match" class="editor-card" @submit.prevent="allocatePayment">
        <h3>Allouer à un document ouvert</h3>
        <div class="form-grid">
          <FormField id="allocation-payment" label="Paiement"><template #default="{ describedBy }"><select id="allocation-payment" v-model.number="allocationDraft.payment_id" :aria-describedby="describedBy" required><option :value="0" disabled>Sélectionner</option><option v-for="item in treasury.workspace.payments.filter((entry) => entry.non_alloue_centimes > 0)" :key="item.id" :value="item.id">{{ item.date_paiement }} · {{ item.reference || `#${item.id}` }} · {{ money(item.non_alloue_centimes) }}</option></select></template></FormField>
          <FormField id="allocation-document" label="Facture ou dette"><template #default="{ describedBy }"><select id="allocation-document" v-model.number="allocationDraft.document_id" :aria-describedby="describedBy" required><option :value="0" disabled>Sélectionner</option><option v-for="item in treasury.workspace.open_documents" :key="item.id" :value="item.id">{{ item.number }} · {{ item.contact }} · {{ money(item.open_cents, item.currency) }}</option></select></template></FormField>
          <FormField id="allocation-amount" label="Montant alloué"><template #default="{ describedBy }"><input id="allocation-amount" v-model="allocationDraft.amount" inputmode="decimal" :aria-describedby="describedBy" required></template></FormField>
        </div>
        <button class="button primary" :disabled="treasury.saving">Lettrer</button>
      </form>

      <DataTable
        v-if="treasury.workspace.allocations.length"
        caption="Allocations et délettrages"
        :columns="[
          { key: 'document_numero', label: 'Document' },
          { key: 'contact', label: 'Contact' },
          { key: 'paiement_reference', label: 'Paiement' },
          { key: 'montant_centimes', label: 'Montant' },
          { key: 'statut', label: 'Statut' },
          { key: 'actions', label: 'Actions' }
        ]"
        :rows="treasury.workspace.allocations"
      >
        <template #cell-montant_centimes="{ row }">{{ money(Number(row.montant_centimes)) }}</template>
        <template #cell-actions="{ row }"><button v-if="row.statut === 'valide' && treasury.workspace?.capabilities.match" type="button" @click="unallocate(Number(row.id))">Délettrer</button></template>
      </DataTable>
    </template>

    <template v-else-if="workspace && treasury.workspace && activeTab === 'paiements'">
      <div class="toolbar">
        <div><p>Préparation, export pain.001 non transmis, puis confirmation par relevé.</p></div>
        <button
          v-if="treasury.workspace.capabilities.match"
          class="button primary"
          type="button"
          @click="openPaymentDialog"
        >
          Saisir un paiement
        </button>
      </div>
      <ModalDialog
        v-if="treasury.workspace.capabilities.match"
        ref="paymentDialog"
        title="Saisir un paiement"
        description="Le paiement reste indépendant des factures jusqu’à son lettrage."
        wide
      >
        <form class="modal-editor" @submit.prevent="createPayment">
          <div class="form-grid">
            <FormField id="matching-contact" label="Contact"><template #default="{ describedBy }"><select id="matching-contact" v-model.number="paymentDraft.contact_id" :aria-describedby="describedBy" required><option :value="0" disabled>Sélectionner</option><option v-for="item in treasury.workspace.catalog.contacts" :key="item.id" :value="item.id">{{ item.label }}</option></select></template></FormField>
            <FormField id="matching-direction" label="Sens"><template #default="{ describedBy }"><select id="matching-direction" v-model="paymentDraft.direction" :aria-describedby="describedBy"><option value="encaissement">Encaissement</option><option value="decaissement">Décaissement</option></select></template></FormField>
            <FormField id="matching-date" label="Date"><template #default="{ describedBy }"><input id="matching-date" v-model="paymentDraft.date" type="date" :aria-describedby="describedBy" required></template></FormField>
            <FormField id="matching-amount" label="Montant"><template #default="{ describedBy }"><input id="matching-amount" v-model="paymentDraft.amount" inputmode="decimal" :aria-describedby="describedBy" required></template></FormField>
            <FormField id="matching-reference" label="Référence"><template #default="{ describedBy }"><input id="matching-reference" v-model="paymentDraft.reference" :aria-describedby="describedBy"></template></FormField>
            <FormField id="matching-account" label="Compte de trésorerie"><template #default="{ describedBy }"><AccountCombobox id="matching-account" v-model="paymentDraft.treasury_account_id" :options="treasury.workspace.treasury_accounts" number-key="ledger_number" label-key="ledger_label" :aria-describedby="describedBy" required /></template></FormField>
            <FormField id="matching-bank-line" label="Ligne bancaire facultative" hint="Le montant cumulé et le sens sont contrôlés côté serveur.">
              <template #default="{ describedBy }">
                <select id="matching-bank-line" v-model.number="paymentDraft.bank_line_id" :aria-describedby="describedBy">
                  <option :value="0">Paiement sans ligne bancaire</option>
                  <option
                    v-for="line in treasury.workspace.bank_lines.filter((item) => paymentDraft.direction === 'encaissement' ? item.amount_cents > 0 : item.amount_cents < 0)"
                    :key="line.id"
                    :value="line.id"
                  >{{ line.booking_date }} · {{ line.label || line.counterparty }} · {{ money(line.amount_cents, line.currency) }}</option>
                </select>
              </template>
            </FormField>
          </div>
          <div class="button-row">
            <button class="button primary" :disabled="treasury.saving">Créer le paiement</button>
          </div>
        </form>
      </ModalDialog>
      <form v-if="treasury.workspace.capabilities.prepare_payments" class="editor-card" @submit.prevent="prepareBatch">
        <h3>Dettes approuvées et comptabilisées</h3>
        <div class="form-grid">
          <FormField id="batch-account" label="Compte débiteur"><template #default="{ describedBy }"><AccountCombobox id="batch-account" v-model="batchDraft.treasury_account_id" :options="treasury.workspace.treasury_accounts" number-key="ledger_number" label-key="ledger_label" :aria-describedby="describedBy" required /></template></FormField>
          <FormField id="batch-date" label="Date d’exécution"><template #default="{ describedBy }"><input id="batch-date" v-model="batchDraft.execution_date" type="date" :aria-describedby="describedBy" required></template></FormField>
        </div>
        <label v-for="debt in treasury.workspace.payable_debts" :key="debt.id" class="selection-row">
          <input v-model="selectedDebtIds" type="checkbox" :value="debt.id" :disabled="!debt.iban">
          <span>{{ debt.number }} · {{ debt.supplier }} · échéance {{ debt.due_date }}<small v-if="!debt.iban">IBAN fournisseur manquant dans Configuration</small></span>
          <strong>{{ money(debt.open_cents, debt.currency) }}</strong>
        </label>
        <button class="button primary" :disabled="!selectedDebtIds.length || treasury.saving">Préparer le lot</button>
      </form>

      <section v-if="treasury.workspace.outgoing_batches.length" class="batch-list">
        <article v-for="batch in treasury.workspace.outgoing_batches" :key="batch.id" class="detail-card">
          <div class="toolbar">
            <div>
              <p class="eyebrow">{{ batch.pain_version }}</p>
              <h3>{{ batch.message_id }}</h3>
              <p>{{ batch.order_count }} ordre(s) · {{ money(batch.total_cents, batch.currency) }} · {{ statusLabel(batch.status) }}</p>
              <small v-if="batch.hash">SHA-256 {{ batch.hash }}</small>
            </div>
            <div class="button-row">
              <button v-if="batch.status === 'prepare' && treasury.workspace?.capabilities.export_payments" type="button" @click="exportBatch(batch)">Générer et télécharger</button>
            </div>
          </div>
          <ul><li v-for="order in batch.orders" :key="order.id">{{ order.beneficiary }} · {{ order.reference }} · {{ money(order.amount_cents, order.currency) }}</li></ul>
          <div v-if="batch.status === 'exporte' && treasury.workspace.capabilities.confirm_payments" class="confirmation-row">
            <select v-model.number="confirmationDraft.bank_line_id" aria-label="Ligne bancaire de confirmation" required>
              <option :value="0" disabled>Ligne bancaire débitée</option>
              <option v-for="line in treasury.workspace.bank_lines.filter((item) => !item.reconciliation_id && item.amount_cents < 0 && item.treasury_account_id === batch.treasury_account_id)" :key="line.id" :value="line.id">{{ line.booking_date }} · {{ line.label }} · {{ money(line.amount_cents, line.currency) }}</option>
            </select>
            <AccountCombobox
              v-model="confirmationDraft.fee_account_id"
              :options="treasury.workspace.catalog.accounts"
              aria-label="Compte de frais bancaires"
              placeholder="Sans frais séparés"
            />
            <button type="button" :disabled="!confirmationDraft.bank_line_id" @click="confirmBatch(batch)">Confirmer par le relevé</button>
          </div>
        </article>
      </section>
      <EmptyState v-else title="Aucun lot de paiements" description="Sélectionnez une ou plusieurs dettes comptabilisées." />
    </template>

    <template v-else-if="workspace && treasury.workspace && activeTab === 'taux'">
      <nav class="subtabs secondary-tabs section-tabs" aria-label="Types de taux">
        <button :class="{ active: ratesSection === 'exchange' }" type="button" @click="ratesSection = 'exchange'">Taux de change</button>
        <button :class="{ active: ratesSection === 'interest' }" type="button" @click="ratesSection = 'interest'">Taux d’intérêt</button>
      </nav>
      <div v-if="ratesSection === 'exchange'" class="toolbar">
        <div>
          <p v-if="treasury.exchangeHistory">
            {{ treasury.exchangeHistory.exercise.label }} ·
            {{ periodLabel(treasury.exchangeHistory.window.start) }} à
            {{ periodLabel(treasury.exchangeHistory.window.end) }}
          </p>
        </div>
        <label class="compact-control">
          Valeur mensuelle
          <select v-model="exchangeMode">
            <option value="moyenne">Moyenne mensuelle</option>
            <option value="fin_mois">Fin de mois</option>
          </select>
        </label>
      </div>

      <SkeletonBlock
        v-if="ratesSection === 'exchange' && treasury.marketLoading && !treasury.exchangeHistory"
        :lines="8"
      />
      <template v-else-if="ratesSection === 'exchange' && treasury.exchangeHistory">
        <p
          v-if="treasury.exchangeHistory.refresh.monthly.warning || treasury.exchangeHistory.refresh.daily.warning"
          class="market-warning"
        >
          {{ treasury.exchangeHistory.refresh.monthly.warning
            || treasury.exchangeHistory.refresh.daily.warning }}
        </p>
        <section class="market-summary">
          <div>
            <span>Monnaies suivies</span>
            <strong>{{ treasury.exchangeHistory.currencies.join(', ') }}</strong>
          </div>
          <div>
            <span>Dernière synchronisation BNS</span>
            <strong>{{ treasury.exchangeHistory.refresh.monthly.succeeded_at || 'Pas encore disponible' }}</strong>
          </div>
          <div>
            <span>Convention</span>
            <strong>CHF pour 1 unité</strong>
          </div>
        </section>

        <MarketLineChart
          v-if="exchangeChartSeries.length"
          :labels="treasury.exchangeHistory.periods.map(periodLabel)"
          :series="exchangeChartSeries"
          value-suffix="CHF par unité de monnaie"
          description="Évolution mensuelle des taux de change contre le franc suisse"
        />
        <EmptyState
          v-else
          title="Aucune série BNS pour les monnaies actives"
          description="Activez au moins une devise étrangère prise en charge sous Configuration > Référentiels."
        />

        <section class="market-panel">
          <div class="toolbar">
            <div>
              <h3>Taux quotidien OFDF</h3>
              <p>{{ treasury.exchangeHistory.definitions.daily }}</p>
            </div>
          </div>
          <div v-if="treasury.exchangeHistory.daily.length" class="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Monnaie</th>
                  <th>Taux CHF par unité</th>
                  <th>Publication</th>
                  <th>Validité</th>
                  <th>Source</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in treasury.exchangeHistory.daily" :key="item.currency">
                  <th scope="row">{{ item.currency }}</th>
                  <td>{{ rate(item.per_unit) }}</td>
                  <td>{{ item.publication_date }}</td>
                  <td>{{ item.validity.join(', ') }}</td>
                  <td><a :href="item.source_url" target="_blank" rel="noreferrer">OFDF</a></td>
                </tr>
              </tbody>
            </table>
          </div>
          <EmptyState
            v-else
            title="Aucun taux quotidien conservé"
            description="Le cache sera complété automatiquement dès que la source OFDF répondra."
          />
        </section>

        <section class="market-panel">
          <div class="toolbar">
            <div>
              <h3>Historique mensuel</h3>
              <p>{{ treasury.exchangeHistory.definitions.monthly }}</p>
            </div>
            <a
              href="https://data.snb.ch/fr/topics/ziredev/cube/devkum"
              target="_blank"
              rel="noreferrer"
            >Consulter la source BNS</a>
          </div>
          <div v-if="exchangeSeries.length" class="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Mois</th>
                  <th v-for="item in exchangeSeries" :key="item.code">
                    {{ item.currency }} / CHF
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="period in treasury.exchangeHistory.periods" :key="period">
                  <th scope="row">{{ periodLabel(period) }}</th>
                  <td v-for="item in exchangeSeries" :key="item.code">
                    {{ rate(marketValue(item, period)) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
        <p class="market-note">{{ treasury.exchangeHistory.definitions.accounting }}</p>
      </template>

      <div v-if="ratesSection === 'interest'" class="toolbar">
        <div>
          <p v-if="treasury.interestHistory">
            {{ treasury.interestHistory.exercise.label }} ·
            {{ periodLabel(treasury.interestHistory.window.start) }} à
            {{ periodLabel(treasury.interestHistory.window.end) }}
          </p>
        </div>
        <label v-if="treasury.interestHistory?.series.length" class="compact-control">
          Série
          <select v-model="selectedInterestCode">
            <option
              v-for="item in treasury.interestHistory.series"
              :key="item.code"
              :value="item.code"
            >{{ item.label }}</option>
          </select>
        </label>
      </div>

      <SkeletonBlock
        v-if="ratesSection === 'interest' && treasury.marketLoading && !treasury.interestHistory"
        :lines="8"
      />
      <template v-else-if="ratesSection === 'interest' && treasury.interestHistory">
        <p
          v-if="treasury.interestHistory.refresh.monthly.warning"
          class="market-warning"
        >{{ treasury.interestHistory.refresh.monthly.warning }}</p>
        <section class="market-summary">
          <div>
            <span>Monnaies suivies</span>
            <strong>{{ treasury.interestHistory.currencies.join(', ') }}</strong>
          </div>
          <div>
            <span>Séries disponibles</span>
            <strong>{{ treasury.interestHistory.series.length }}</strong>
          </div>
          <div>
            <span>Dernière synchronisation BNS</span>
            <strong>{{ treasury.interestHistory.refresh.monthly.succeeded_at || 'Pas encore disponible' }}</strong>
          </div>
        </section>

        <MarketLineChart
          v-if="selectedInterestSeries && interestChartSeries.length"
          :labels="treasury.interestHistory.periods.map(periodLabel)"
          :series="interestChartSeries"
          value-suffix="En pour-cent"
          description="Évolution mensuelle du taux d’intérêt sélectionné"
        />
        <EmptyState
          v-else
          title="Aucune série de taux pour les monnaies actives"
          description="Les données apparaîtront après synchronisation de la source BNS."
        />

        <section v-if="selectedInterestSeries" class="market-panel">
          <div class="toolbar">
            <div>
              <h3>{{ selectedInterestSeries.label }}</h3>
              <p>{{ treasury.interestHistory.definitions.monthly }}</p>
            </div>
            <a
              href="https://data.snb.ch/fr/topics/ziredev/cube/zimoma"
              target="_blank"
              rel="noreferrer"
            >Consulter la source BNS</a>
          </div>
          <div class="table-scroll">
            <table>
              <thead><tr><th>Mois</th><th>Taux</th><th>Monnaie</th></tr></thead>
              <tbody>
                <tr v-for="period in treasury.interestHistory.periods" :key="period">
                  <th scope="row">{{ periodLabel(period) }}</th>
                  <td>{{ rate(marketValue(selectedInterestSeries, period), ' %') }}</td>
                  <td>{{ selectedInterestSeries.currency }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </template>
    </template>
  </section>
</template>

<style scoped>
.page-stack { display: grid; gap: 1.25rem; }
.page-heading, .toolbar, .button-row, .table-actions { display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; }
.page-heading h1, .toolbar h2 { margin: 0; }
.page-heading p, .toolbar p { margin: .25rem 0 0; color: var(--muted); }
.eyebrow { margin: 0; color: var(--accent); font-size: .78rem; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; }
.editor-card, .detail-card, .recurrence-section { padding: 1.1rem; background: var(--surface); border: 1px solid var(--border); border-radius: .75rem; box-shadow: var(--shadow); }
.form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
.modal-editor { display: grid; gap: 1rem; }
.line-editor { display: grid; grid-template-columns: 1.5fr .7fr 1.2fr 1fr .8fr auto; gap: .6rem; margin: 1rem 0; padding: .9rem; border: 1px solid var(--border); border-radius: .5rem; }
.line-editor input, .line-editor select, .form-grid input, .form-grid select { width: 100%; min-height: 2.7rem; }
.table-link { color: var(--ink); background: none; border: 0; text-decoration: underline; cursor: pointer; }
.table-actions { justify-content: flex-start; }
.detail-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .8rem; }
.detail-grid dt { color: var(--muted); font-size: .8rem; }
.detail-grid dd { margin: .2rem 0 0; font-weight: 750; }
.expense-detail { display: grid; gap: 1.2rem; }
.expense-detail-heading, .expense-title-row { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; }
.expense-detail-heading h3, .expense-lines h4 { margin: 0; }
.expense-detail-heading p:not(.eyebrow) { margin: .3rem 0 0; color: var(--muted); }
.expense-title-row { align-items: center; justify-content: flex-start; margin-top: .25rem; }
.expense-metadata { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; margin: 0; }
.expense-metadata div { min-width: 0; padding: .8rem; background: #f7f7fb; border: 1px solid var(--border); border-radius: .55rem; }
.expense-metadata dt { color: var(--muted); font-size: .78rem; }
.expense-metadata dd { margin: .3rem 0 0; overflow-wrap: anywhere; font-weight: 700; }
.expense-metadata small, .expense-lines td small { display: block; margin-top: .2rem; color: var(--muted); font-size: .75rem; font-weight: 500; }
.expense-lines { display: grid; gap: .65rem; }
.expense-lines table { width: 100%; min-width: 760px; border-collapse: collapse; }
.expense-lines th, .expense-lines td { padding: .7rem; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
.expense-lines .amount { text-align: right; white-space: nowrap; }
.expense-lines tfoot th { color: var(--ink); border-bottom: 0; }
.status-chip { display: inline-flex; align-items: center; padding: .28rem .55rem; border-radius: 999px; color: var(--ink); background: #ebecf5; font-size: .75rem; font-weight: 800; white-space: nowrap; }
.status-approuve, .status-comptabilise { color: #16603d; background: #e9f7ef; }
.status-a_approuver { color: #765000; background: #fff4d5; }
.status-annule { color: #8d2727; background: #fdecec; }
.recurrence-section { display: grid; gap: 1rem; }
.reconciliation-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
.reconciliation-totals { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; margin: 1rem 0; }
.reconciliation-totals div { padding: .75rem; background: var(--surface-soft, #f7f7fb); border-radius: .5rem; }
.reconciliation-totals dt { color: var(--muted); font-size: .8rem; }
.reconciliation-totals dd { margin: .2rem 0 0; font-weight: 800; }
.difference-error { color: var(--danger, #9f1239); }
.selection-row { display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: .75rem; padding: .7rem; border-bottom: 1px solid var(--border); }
.selection-row small { display: block; color: var(--danger, #9f1239); margin-top: .2rem; }
.batch-list { display: grid; gap: 1rem; }
.confirmation-row { display: grid; grid-template-columns: 1fr 1fr auto; gap: .6rem; align-items: center; }
.confirmation-row select { min-height: 2.7rem; }
.compact-control { display: grid; gap: .25rem; min-width: min(100%, 22rem); color: var(--muted); font-size: .85rem; }
.compact-control select { min-height: 2.7rem; }
.market-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; }
.market-summary div { display: grid; gap: .25rem; padding: .9rem; background: var(--surface); border: 1px solid var(--border); border-radius: .65rem; }
.market-summary span { color: var(--muted); font-size: .8rem; }
.market-panel { display: grid; gap: 1rem; padding: 1rem; border: 1px solid var(--border); border-radius: .75rem; background: var(--surface); }
.market-panel h3 { margin: 0; }
.market-panel table { width: 100%; border-collapse: collapse; }
.market-panel th, .market-panel td { padding: .65rem; border-bottom: 1px solid var(--border); text-align: left; white-space: nowrap; }
.market-warning { margin: 0; padding: .75rem 1rem; border-left: 4px solid var(--warning, #a87500); background: var(--surface); }
.market-note { margin: 0; color: var(--muted); font-size: .9rem; }
.market-divider { width: 100%; margin: 1rem 0 0; border: 0; border-top: 1px solid var(--border); }
@media (max-width: 850px) {
  .form-grid, .detail-grid, .expense-metadata, .reconciliation-grid, .reconciliation-totals, .confirmation-row, .market-summary { grid-template-columns: 1fr; }
  .line-editor { grid-template-columns: 1fr; }
  .expense-detail-heading { align-items: flex-start; }
}
</style>
