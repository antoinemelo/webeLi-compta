<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import CompactTabs from '@/components/ui/CompactTabs.vue';
import AssetsPanel from '@/components/accounting/AssetsPanel.vue';
import ConsolidationPanel from '@/components/accounting/ConsolidationPanel.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import SkeletonBlock from '@/components/ui/SkeletonBlock.vue';
import { runtimeConfig } from '@/config';
import type { AccountingWorkspace } from '@/api/contracts';
import { referenceNavigation, subNavigation } from '@/router/navigation';
import { useAccountingStore } from '@/stores/accounting';
import { useContextStore } from '@/stores/context';

type EntryLine = {
  account_id: number;
  label: string;
  debit: string;
  credit: string;
};

const route = useRoute();
const context = useContextStore();
const accounting = useAccountingStore();
const exerciseId = ref(0);
const selectedAccountId = ref(0);
const ledgerMode = ref<'list' | 't'>('list');
const reportSection = ref<'balance' | 'bilan' | 'resultat' | 'flux' | 'grand_livre'>('balance');
const reportStart = ref('');
const reportEnd = ref('');
const selectedVatStatementId = ref(0);
const vatPeriod = reactive({ start: '', end: '' });
const exchangeRevaluation = reactive({
  date: '',
  journal_id: 0,
  idempotency_key: ''
});
const taxAdjustment = reactive({
  label: '',
  nature: 'information',
  amount: '',
  note: '',
  idempotency_key: ''
});
const planSection = ref<'types' | 'sense' | 'rubrics' | 'accounts' | 'opening'>('types');
const rubricLevel = ref<'classe' | 'groupe_principal' | 'groupe' | 'sous_groupe'>('classe');
const accountSearch = ref('');
const typeLabels = reactive<Record<number, string>>({});
const prefixText = ref('');
const rubricDrafts = reactive<Record<number, {
  code: string;
  label: string;
  type: string;
  parent_id: number | null;
}>>({});
const accountDrafts = reactive<Record<number, {
  number: string;
  label: string;
  sense_mode: 'automatique' | 'debit' | 'credit';
  rubric_id: number | null;
}>>({});
const openingDrafts = reactive<Record<number, string>>({});
const newRubric = reactive({
  code: '',
  label: '',
  type: 'actif',
  parent_id: null as number | null
});
const newAccount = reactive({
  number: '',
  label: '',
  sense_mode: 'automatique' as 'automatique' | 'debit' | 'credit',
  rubric_id: null as number | null
});
const entry = reactive({
  date: '',
  journal_id: 0,
  label: '',
  reference: '',
  attachment_reference: '',
  lines: [
    { account_id: 0, label: '', debit: '', credit: '' },
    { account_id: 0, label: '', debit: '', credit: '' }
  ] as EntryLine[]
});
let initializedDossierId = 0;

const workspace = computed(() => accounting.workspace);
const isChartSettings = computed(() => route.name === 'chart-settings');
const currentTab = computed(() =>
  isChartSettings.value ? 'plan' : String(route.params.tab || 'journalisation')
);
const currency = computed(() => context.selection?.dossier.currency || 'CHF');
const allowed = computed(() =>
  context.moduleEnabled('comptabilite') && context.can('compta.view')
);
const canEdit = computed(() => context.can('compta.edit'));
const canSetup = computed(() => context.can('compta.setup'));
const canValidate = computed(() => context.can('compta.validate'));
const entryTotals = computed(() => entry.lines.reduce(
  (totals, line) => {
    totals.debit += safeCents(line.debit);
    totals.credit += safeCents(line.credit);
    return totals;
  },
  { debit: 0, credit: 0 }
));
const entryBalanced = computed(() =>
  entryTotals.value.debit > 0 && entryTotals.value.debit === entryTotals.value.credit
);
const visibleRubrics = computed(() =>
  (workspace.value?.chart.rubrics ?? []).filter(
    (rubric) => rubric.structure_level === rubricLevel.value
  )
);
const visibleAccounts = computed(() => {
  const search = accountSearch.value.trim().toLocaleLowerCase('fr-CH');
  const accounts = workspace.value?.chart.accounts ?? [];
  if (!search) return accounts;
  return accounts.filter((account) =>
    `${account.number} ${account.label} ${account.rubric_path}`
      .toLocaleLowerCase('fr-CH')
      .includes(search)
  );
});
const openingAccounts = computed(() =>
  (workspace.value?.chart.accounts ?? []).filter(
    (account) => account.active && ['actif', 'passif'].includes(account.type)
  )
);

watch(
  () => [context.selection?.dossier.id ?? 0, context.exercises] as const,
  ([dossierId, exercises]) => {
    if (!dossierId || exercises.length === 0 || !allowed.value) {
      initializedDossierId = 0;
      exerciseId.value = 0;
      accounting.clear();
      return;
    }
    if (initializedDossierId === dossierId) return;
    initializedDossierId = dossierId;
    exerciseId.value = context.selection?.exercise?.id || exercises[0].id;
    void reload();
  },
  { immediate: true, deep: true }
);

watch(
  () => accounting.workspace,
  (value) => {
    if (!value) return;
    Object.keys(typeLabels).forEach((key) => delete typeLabels[Number(key)]);
    value.chart.types.forEach((type) => { typeLabels[type.id] = type.label; });
    prefixText.value = value.chart.credit_prefixes.join(', ');
    Object.keys(rubricDrafts).forEach((key) => delete rubricDrafts[Number(key)]);
    value.chart.rubrics.forEach((rubric) => {
      rubricDrafts[rubric.id] = {
        code: rubric.code,
        label: rubric.label,
        type: rubric.type,
        parent_id: rubric.parent_id
      };
    });
    Object.keys(accountDrafts).forEach((key) => delete accountDrafts[Number(key)]);
    value.chart.accounts.forEach((account) => {
      accountDrafts[account.id] = {
        number: account.number,
        label: account.label,
        sense_mode: account.sense_mode,
        rubric_id: account.rubric_id
      };
    });
    Object.keys(openingDrafts).forEach((key) => delete openingDrafts[Number(key)]);
    Object.entries(value.opening.soldes).forEach(([id, cents]) => {
      openingDrafts[Number(id)] = centsToInput(cents);
    });
    if (!entry.date) entry.date = value.exercise.start_date;
    if (
      reportStart.value < value.exercise.start_date
      || reportStart.value > value.exercise.end_date
    ) reportStart.value = value.exercise.start_date;
    if (
      reportEnd.value < value.exercise.start_date
      || reportEnd.value > value.exercise.end_date
    ) reportEnd.value = value.exercise.end_date;
    if (!vatPeriod.start) vatPeriod.start = value.exercise.start_date;
    if (!vatPeriod.end) vatPeriod.end = value.exercise.end_date;
    if (!selectedVatStatementId.value && value.vat.selected_statement) {
      selectedVatStatementId.value = value.vat.selected_statement.summary.id;
    }
    if (!entry.journal_id && value.catalog.journals.length) {
      entry.journal_id = value.catalog.journals[0].id;
    }
    if (!exchangeRevaluation.date) {
      exchangeRevaluation.date = value.exercise.end_date;
    }
    if (!exchangeRevaluation.journal_id && value.catalog.journals.length) {
      exchangeRevaluation.journal_id = value.catalog.journals[0].id;
    }
  },
  { deep: true }
);

async function reload(accountId = selectedAccountId.value || undefined): Promise<void> {
  if (exerciseId.value > 0) {
    await accounting.load(
      exerciseId.value,
      accountId,
      reportStart.value || undefined,
      reportEnd.value || undefined,
      selectedVatStatementId.value || undefined
    );
  }
}

async function changeExercise(): Promise<void> {
  const exercise = context.exercises.find((item) => item.id === exerciseId.value);
  reportStart.value = exercise?.start_date || '';
  reportEnd.value = exercise?.end_date || '';
  vatPeriod.start = reportStart.value;
  vatPeriod.end = reportEnd.value;
  selectedVatStatementId.value = 0;
  await reload();
}

async function applyReportPeriod(): Promise<void> {
  await reload();
}

async function selectAccount(): Promise<void> {
  await reload(selectedAccountId.value || undefined);
}

function safeCents(value: string): number {
  try {
    return parseCents(value || '0');
  } catch {
    return 0;
  }
}

function parseCents(value: string): number {
  const normalized = value.trim().replace(/[’'\s]/g, '').replace(',', '.');
  const match = normalized.match(/^([+-]?)(\d+)(?:\.(\d{1,2}))?$/);
  if (!match) throw new Error(`Montant invalide : ${value}`);
  const cents = Number(match[2]) * 100 + Number((match[3] || '').padEnd(2, '0'));
  if (!Number.isSafeInteger(cents)) throw new Error('Montant trop élevé.');
  return match[1] === '-' ? -cents : cents;
}

function centsToInput(cents: number): string {
  const sign = cents < 0 ? '-' : '';
  const absolute = Math.abs(cents);
  return `${sign}${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, '0')}`;
}

function formatMoney(cents: number, displayCurrency = currency.value): string {
  const sign = cents < 0 ? '−' : '';
  const absolute = Math.abs(cents);
  return `${sign}${displayCurrency} ${Math.floor(absolute / 100).toLocaleString('fr-CH')}.${String(absolute % 100).padStart(2, '0')}`;
}

function addLine(): void {
  entry.lines.push({ account_id: 0, label: '', debit: '', credit: '' });
}

function removeLine(index: number): void {
  if (entry.lines.length > 2) entry.lines.splice(index, 1);
}

async function submitEntry(validate: boolean): Promise<void> {
  try {
    const lines = entry.lines.map((line) => ({
      account_id: line.account_id,
      label: line.label,
      debit_cents: parseCents(line.debit || '0'),
      credit_cents: parseCents(line.credit || '0')
    }));
    await accounting.mutate('/accounting/entries', {
      exercise_id: exerciseId.value,
      journal_id: entry.journal_id,
      date: entry.date,
      label: entry.label,
      reference: entry.reference,
      attachment_reference: entry.attachment_reference,
      validate,
      lines
    }, validate ? 'Écriture validée.' : 'Brouillon enregistré.');
    entry.label = '';
    entry.reference = '';
    entry.attachment_reference = '';
    entry.lines = [
      { account_id: 0, label: '', debit: '', credit: '' },
      { account_id: 0, label: '', debit: '', credit: '' }
    ];
    await reload();
  } catch {
    // Le store expose l’erreur structurée.
  }
}

async function saveTypes(): Promise<void> {
  const types = (workspace.value?.chart.types ?? []).map((type) => ({
    id: type.id,
    label: typeLabels[type.id],
    version: type.version
  }));
  await mutateAndReload('/accounting/chart/types', { types }, 'Types enregistrés.');
}

async function saveSense(): Promise<void> {
  const prefixes = prefixText.value.split(/[\s,;]+/).filter(Boolean);
  await mutateAndReload(
    '/accounting/chart/sense-rules',
    { prefixes },
    'Règles de sens enregistrées.'
  );
}

function parentOptions(level: string) {
  const parentLevel: Record<string, string> = {
    groupe_principal: 'classe',
    groupe: 'groupe_principal',
    sous_groupe: 'groupe'
  };
  return (workspace.value?.chart.rubrics ?? []).filter(
    (rubric) => rubric.structure_level === parentLevel[level]
  );
}

async function saveRubric(id: number): Promise<void> {
  const rubric = workspace.value?.chart.rubrics.find((item) => item.id === id);
  const draft = rubricDrafts[id];
  if (!rubric || !draft) return;
  await mutateAndReload('/accounting/chart/rubrics', {
    action: 'save',
    id,
    structure_level: rubric.structure_level,
    code: draft.code,
    label: draft.label,
    type: draft.type,
    parent_id: draft.parent_id,
    position: rubric.order,
    version: rubric.version,
    ordered_ids: []
  }, 'Rubrique enregistrée.');
}

async function createRubric(): Promise<void> {
  await mutateAndReload('/accounting/chart/rubrics', {
    action: 'save',
    id: 0,
    structure_level: rubricLevel.value,
    code: newRubric.code,
    label: newRubric.label,
    type: newRubric.type,
    parent_id: newRubric.parent_id,
    position: visibleRubrics.value.length * 10 + 10,
    version: 0,
    ordered_ids: []
  }, 'Rubrique créée.');
  newRubric.code = '';
  newRubric.label = '';
}

async function deleteRubric(id: number): Promise<void> {
  if (!window.confirm('Retirer cette rubrique ?')) return;
  const rubric = workspace.value?.chart.rubrics.find((item) => item.id === id);
  if (!rubric) return;
  await mutateAndReload('/accounting/chart/rubrics', {
    action: 'delete',
    id,
    structure_level: rubric.structure_level,
    code: '',
    label: '',
    type: rubric.type,
    parent_id: null,
    position: 0,
    version: rubric.version,
    ordered_ids: []
  }, 'Rubrique retirée.');
}

async function moveRubric(id: number, direction: -1 | 1): Promise<void> {
  const ids = visibleRubrics.value.map((item) => item.id);
  const index = ids.indexOf(id);
  const target = index + direction;
  if (index < 0 || target < 0 || target >= ids.length) return;
  [ids[index], ids[target]] = [ids[target], ids[index]];
  await mutateAndReload('/accounting/chart/rubrics', {
    action: 'reorder',
    id,
    structure_level: rubricLevel.value,
    code: '',
    label: '',
    type: 'actif',
    parent_id: null,
    position: 0,
    version: 0,
    ordered_ids: ids
  }, 'Ordre des rubriques enregistré.');
}

async function saveAccount(id: number): Promise<void> {
  const account = workspace.value?.chart.accounts.find((item) => item.id === id);
  const draft = accountDrafts[id];
  if (!account || !draft) return;
  await mutateAndReload('/accounting/chart/accounts', {
    action: 'save',
    id,
    number: draft.number,
    label: draft.label,
    sense_mode: draft.sense_mode,
    rubric_id: draft.rubric_id,
    version: account.version,
    ordered_ids: []
  }, 'Compte enregistré.');
}

async function createAccount(): Promise<void> {
  await mutateAndReload('/accounting/chart/accounts', {
    action: 'save',
    id: 0,
    number: newAccount.number,
    label: newAccount.label,
    sense_mode: newAccount.sense_mode,
    rubric_id: newAccount.rubric_id,
    version: 0,
    ordered_ids: []
  }, 'Compte créé.');
  newAccount.number = '';
  newAccount.label = '';
}

async function deleteAccount(id: number): Promise<void> {
  if (!window.confirm('Supprimer ce compte inutilisé ou désactiver le compte utilisé ?')) return;
  await mutateAndReload('/accounting/chart/accounts', {
    action: 'delete',
    id,
    number: '',
    label: '',
    sense_mode: 'automatique',
    rubric_id: null,
    version: 0,
    ordered_ids: []
  }, 'Compte retiré ou désactivé selon son historique.');
}

async function moveAccount(id: number, direction: -1 | 1): Promise<void> {
  const ids = (workspace.value?.chart.accounts ?? []).map((item) => item.id);
  const index = ids.indexOf(id);
  const target = index + direction;
  if (index < 0 || target < 0 || target >= ids.length) return;
  [ids[index], ids[target]] = [ids[target], ids[index]];
  await mutateAndReload('/accounting/chart/accounts', {
    action: 'reorder',
    id,
    number: '',
    label: '',
    sense_mode: 'automatique',
    rubric_id: null,
    version: 0,
    ordered_ids: ids
  }, 'Ordre des comptes enregistré.');
}

async function saveOpening(validate: boolean): Promise<void> {
  const balances: Record<string, number> = {};
  openingAccounts.value.forEach((account) => {
    const value = openingDrafts[account.id] || '';
    if (value.trim()) balances[String(account.id)] = parseCents(value);
  });
  await mutateAndReload('/accounting/opening', {
    exercise_id: exerciseId.value,
    validate,
    balances
  }, validate ? 'Soldes d’ouverture validés.' : 'Brouillon d’ouverture enregistré.');
}

async function mutateAndReload(
  path: string,
  data: Record<string, unknown>,
  notice: string
): Promise<void> {
  try {
    await accounting.mutate(path, data, notice);
    await reload();
    accounting.notice = notice;
  } catch {
    // Le store expose l’erreur structurée.
  }
}

function selectPlanSection(value: string): void {
  if (['types', 'sense', 'rubrics', 'accounts', 'opening'].includes(value)) {
    planSection.value = value as typeof planSection.value;
  }
}

function selectReportSection(value: string): void {
  if (['balance', 'bilan', 'resultat', 'flux', 'grand_livre'].includes(value)) {
    reportSection.value = value as typeof reportSection.value;
  }
}

function download(path: string, query: Record<string, string | number>): void {
  const url = new URL(`${runtimeConfig.apiBaseUrl}${path}`, window.location.origin);
  Object.entries(query).forEach(([key, value]) => url.searchParams.set(key, String(value)));
  window.location.assign(`${url.pathname}${url.search}`);
}

function exportReport(type: string): void {
  download('/accounting/reports/export', {
    exercise_id: exerciseId.value,
    type,
    date_start: reportStart.value,
    date_end: reportEnd.value
  });
}

async function selectVatStatement(): Promise<void> {
  await reload();
}

async function createVatPeriod(): Promise<void> {
  await mutateAndReload('/accounting/vat/periods', {
    start: vatPeriod.start,
    end: vatPeriod.end
  }, 'Période TVA créée.');
}

async function prepareVatStatement(periodId: number): Promise<void> {
  await mutateAndReload('/accounting/vat/statements/prepare', {
    period_id: periodId,
    corrects_id: null
  }, 'Décompte TVA préparé depuis les sources comptables.');
}

async function runVatAction(action: 'control' | 'export' | 'declare', statementId: number): Promise<void> {
  const labels = {
    control: 'Décompte TVA contrôlé.',
    export: 'Export eCH-0217 généré et validé.',
    declare: 'Décompte marqué comme déclaré manuellement.'
  };
  await mutateAndReload(`/accounting/vat/statements/${action}`, {
    statement_id: statementId
  }, labels[action]);
  selectedVatStatementId.value = statementId;
}

async function saveClosingControl(control: AccountingWorkspace['closing']['manual_controls'][number]): Promise<void> {
  await mutateAndReload('/accounting/closing/controls', {
    exercise_id: exerciseId.value,
    code: control.code,
    status: control.status,
    note: control.note,
    version: control.version
  }, 'Contrôle de clôture enregistré.');
}

async function togglePeriod(period: AccountingWorkspace['closing']['periods'][number]): Promise<void> {
  const status = period.status === 'ouverte' ? 'fermee' : 'ouverte';
  if (
    status === 'fermee'
    && !window.confirm('Fermer cette période et verrouiller les nouvelles écritures ?')
  ) return;
  await mutateAndReload('/accounting/closing/periods', {
    exercise_id: exerciseId.value,
    period_id: period.id,
    status,
    version: period.version
  }, status === 'fermee' ? 'Période fermée.' : 'Période rouverte.');
}

async function postExchangeRevaluation(): Promise<void> {
  if (!exchangeRevaluation.idempotency_key) {
    exchangeRevaluation.idempotency_key = crypto.randomUUID();
  }
  await mutateAndReload('/accounting/exchange-revaluations', {
    exercise_id: exerciseId.value,
    journal_id: exchangeRevaluation.journal_id,
    date: exchangeRevaluation.date,
    idempotency_key: exchangeRevaluation.idempotency_key
  }, 'Réévaluation de change comptabilisée.');
}

async function reverseExchangeRevaluation(id: number): Promise<void> {
  await mutateAndReload('/accounting/exchange-revaluations/reverse', {
    revaluation_id: id,
    date: exchangeRevaluation.date
  }, 'Réévaluation de change contre-passée.');
}

async function createTaxAdjustment(): Promise<void> {
  if (!taxAdjustment.idempotency_key) {
    taxAdjustment.idempotency_key = crypto.randomUUID();
  }
  await mutateAndReload('/accounting/tax-file/adjustments', {
    exercise_id: exerciseId.value,
    label: taxAdjustment.label,
    nature: taxAdjustment.nature,
    amount_cents: parseCents(taxAdjustment.amount || '0'),
    note: taxAdjustment.note,
    idempotency_key: taxAdjustment.idempotency_key
  }, 'Ajustement fiscal préparatoire ajouté.');
  taxAdjustment.label = '';
  taxAdjustment.amount = '';
  taxAdjustment.note = '';
  taxAdjustment.idempotency_key = '';
}

async function setTaxAdjustmentStatus(
  adjustment: AccountingWorkspace['tax_file']['adjustments'][number],
  status: 'propose' | 'valide' | 'ecarte'
): Promise<void> {
  await mutateAndReload('/accounting/tax-file/adjustments/status', {
    adjustment_id: adjustment.id,
    status,
    version: adjustment.version
  }, 'Statut de l’ajustement enregistré.');
}

async function createArchive(type: 'cloture' | 'dossier_fiscal'): Promise<void> {
  await mutateAndReload('/accounting/archives', {
    exercise_id: exerciseId.value,
    type,
    date_start: reportStart.value,
    date_end: reportEnd.value
  }, 'Archive financière immuable créée.');
}
</script>

<template>
  <header class="page-heading accounting-header">
    <div>
      <h1>{{ isChartSettings ? 'Plan comptable' : 'Comptabilité' }}</h1>
      <p>
        {{ isChartSettings
          ? 'Structure, comptes, règles de sens et soldes d’ouverture du dossier.'
          : 'Journal et états pilotés par les mêmes services PHP et la même base SQLite.' }}
      </p>
    </div>
    <label v-if="context.exercises.length" class="compact-control">
      <span>Exercice</span>
      <select v-model.number="exerciseId" @change="changeExercise">
        <option v-for="exercise in context.exercises" :key="exercise.id" :value="exercise.id">
          {{ exercise.label }}
        </option>
      </select>
    </label>
  </header>

  <CompactTabs
    v-if="allowed"
    :items="isChartSettings ? subNavigation.settings : subNavigation.accounting"
    :label="isChartSettings ? 'Navigation Configuration' : 'Navigation comptable'"
  />

  <nav v-if="allowed && isChartSettings" class="subtabs" aria-label="Référentiels gérés">
    <RouterLink
      v-for="item in referenceNavigation"
      :key="item.key"
      :to="item.path"
    >{{ item.label }}</RouterLink>
  </nav>

  <EmptyState
    v-if="!context.selection"
    title="Sélectionnez un dossier depuis l’icône filtre en haut à droite"
    description="Le plan et les écritures sont toujours limités au dossier actif."
  />
  <section v-else-if="!allowed" class="access-message denied" role="alert">
    <strong>{{ context.moduleEnabled('comptabilite') ? 'Accès refusé' : 'Module désactivé' }}</strong>
    <p>La comptabilité n’est pas disponible dans ce dossier.</p>
  </section>
  <template v-else>
    <ErrorSummary :message="accounting.error" />
    <p v-if="accounting.notice" class="notice success" role="status">{{ accounting.notice }}</p>
    <SkeletonBlock v-if="accounting.loading && !workspace" :lines="10" />

    <template v-if="workspace">
      <section v-if="currentTab === 'journalisation'" class="workspace-grid">
        <form class="panel entry-panel" @submit.prevent="submitEntry(false)">
          <div class="section-heading">
            <div>
              <p class="eyebrow">Débit / crédit</p>
              <h2>Nouvelle écriture</h2>
            </div>
            <span :class="['status-chip', entryBalanced ? 'ok' : 'warning']">
              {{ entryBalanced ? 'Équilibrée' : 'À équilibrer' }}
            </span>
          </div>
          <div class="form-grid three">
            <label>Date
              <input v-model="entry.date" type="date" :min="workspace.exercise.start_date" :max="workspace.exercise.end_date" required>
            </label>
            <label>Journal
              <select v-model.number="entry.journal_id" required>
                <option v-for="journal in workspace.catalog.journals" :key="journal.id" :value="journal.id">
                  {{ journal.code }} — {{ journal.label }}
                </option>
              </select>
            </label>
            <label>Référence
              <input v-model="entry.reference" maxlength="120">
            </label>
          </div>
          <label>Libellé
            <input v-model="entry.label" maxlength="255" required>
          </label>
          <label>Référence de pièce
            <input v-model="entry.attachment_reference" maxlength="190">
          </label>
          <div class="table-scroll">
            <table class="editable-table">
              <thead><tr><th>Compte</th><th>Libellé ligne</th><th>Débit</th><th>Crédit</th><th></th></tr></thead>
              <tbody>
                <tr v-for="(line, index) in entry.lines" :key="index">
                  <td>
                    <select v-model.number="line.account_id" required>
                      <option :value="0">Choisir…</option>
                      <option v-for="account in workspace.catalog.accounts" :key="account.id" :value="account.id">
                        {{ account.number }} — {{ account.label }}
                      </option>
                    </select>
                  </td>
                  <td><input v-model="line.label" maxlength="255"></td>
                  <td><input v-model="line.debit" inputmode="decimal" placeholder="0.00"></td>
                  <td><input v-model="line.credit" inputmode="decimal" placeholder="0.00"></td>
                  <td><button class="icon-button" type="button" :disabled="entry.lines.length <= 2" @click="removeLine(index)">×</button></td>
                </tr>
              </tbody>
              <tfoot>
                <tr><th colspan="2">Totaux</th><th>{{ formatMoney(entryTotals.debit) }}</th><th>{{ formatMoney(entryTotals.credit) }}</th><th></th></tr>
              </tfoot>
            </table>
          </div>
          <div class="button-row">
            <button class="button secondary" type="button" @click="addLine">Ajouter une ligne</button>
            <button class="button" type="submit" :disabled="!canEdit || accounting.saving">Enregistrer le brouillon</button>
            <button class="button primary" type="button" :disabled="!canValidate || !entryBalanced || accounting.saving" @click="submitEntry(true)">Valider l’écriture</button>
          </div>
        </form>

        <section class="panel">
          <div class="section-heading">
            <div><p class="eyebrow">Grand livre</p><h2>Écritures récentes</h2></div>
            <span>{{ workspace.journal.total }} écriture(s)</span>
          </div>
          <div class="table-scroll">
            <table>
              <thead><tr><th>Date</th><th>N°</th><th>Journal</th><th>Libellé</th><th>Débit</th><th>Crédit</th><th>Statut</th></tr></thead>
              <tbody>
                <tr v-for="row in workspace.journal.items" :key="row.id">
                  <td>{{ row.date_comptable }}</td><td>{{ row.numero || `#${row.id}` }}</td>
                  <td>{{ row.journal }}</td><td>{{ row.libelle }}</td>
                  <td>{{ formatMoney(row.debit_centimes) }}</td><td>{{ formatMoney(row.credit_centimes) }}</td>
                  <td><span class="status-chip">{{ row.statut }}</span></td>
                </tr>
                <tr v-if="!workspace.journal.items.length"><td colspan="7">Aucune écriture pour cet exercice.</td></tr>
              </tbody>
            </table>
          </div>
        </section>
      </section>

      <section v-else-if="currentTab === 'extraits'" class="panel">
        <div class="section-heading">
          <div><p class="eyebrow">Mouvements</p><h2>Extrait de compte</h2></div>
          <div class="button-row">
            <button :class="['button', ledgerMode === 'list' ? 'primary' : 'secondary']" type="button" @click="ledgerMode = 'list'">Liste</button>
            <button :class="['button', ledgerMode === 't' ? 'primary' : 'secondary']" type="button" @click="ledgerMode = 't'">Compte en T</button>
          </div>
        </div>
        <label class="wide-control">Compte
          <select v-model.number="selectedAccountId" @change="selectAccount">
            <option :value="0">Sélectionner un compte…</option>
            <option v-for="account in workspace.catalog.accounts" :key="account.id" :value="account.id">
              {{ account.number }} — {{ account.label }}
            </option>
          </select>
        </label>
        <EmptyState v-if="!workspace.ledger" title="Choisissez un compte" description="L’extrait est calculé directement depuis les écritures validées." />
        <template v-else>
          <div class="metric-strip">
            <span><small>Débit</small><strong>{{ formatMoney(workspace.ledger.total_debit_centimes) }}</strong></span>
            <span><small>Crédit</small><strong>{{ formatMoney(workspace.ledger.total_credit_centimes) }}</strong></span>
            <span><small>Solde naturel</small><strong>{{ formatMoney(workspace.ledger.solde_centimes) }}</strong></span>
          </div>
          <div v-if="ledgerMode === 'list'" class="table-scroll">
            <table><thead><tr><th>Date</th><th>N°</th><th>Journal</th><th>Libellé</th><th>Montant d’origine et taux</th><th>Débit</th><th>Crédit</th><th>Solde</th></tr></thead>
              <tbody><tr v-for="row in workspace.ledger.items" :key="`${row.ecriture_id}-${row.date_comptable}-${row.libelle}`">
                <td>{{ row.date_comptable }}</td><td>{{ row.numero }}</td><td>{{ row.journal }}</td><td>{{ row.libelle }}</td>
                <td><template v-if="row.devise_origine">{{ formatMoney(Math.abs(row.montant_origine_centimes || 0), row.devise_origine) }}<br><small>{{ row.taux_change_date }} · {{ row.taux_change_numerateur }}/{{ row.taux_change_denominateur }} · {{ row.taux_change_source }}</small></template><span v-else>—</span></td>
                <td>{{ row.debit_centimes ? formatMoney(row.debit_centimes) : '—' }}</td>
                <td>{{ row.credit_centimes ? formatMoney(row.credit_centimes) : '—' }}</td>
                <td>{{ formatMoney(row.solde_centimes) }}</td>
              </tr></tbody>
            </table>
          </div>
          <div v-else class="t-account">
            <h3>{{ workspace.ledger.account.numero }} — {{ workspace.ledger.account.libelle }}</h3>
            <div><section><h4>Débit</h4><p v-for="row in workspace.ledger.items.filter((item) => item.debit_centimes)" :key="`d-${row.ecriture_id}`">{{ row.date_comptable }} · {{ formatMoney(row.debit_centimes) }}</p></section>
              <section><h4>Crédit</h4><p v-for="row in workspace.ledger.items.filter((item) => item.credit_centimes)" :key="`c-${row.ecriture_id}`">{{ row.date_comptable }} · {{ formatMoney(row.credit_centimes) }}</p></section></div>
          </div>
        </template>
      </section>

      <section v-else-if="currentTab === 'plan'" class="panel plan-workspace">
        <div class="section-heading">
          <div><p class="eyebrow">Référentiel unique</p><h2>Plan comptable</h2></div>
          <span v-if="!canSetup" class="status-chip warning">Lecture seule</span>
        </div>
        <nav class="subtabs" aria-label="Sections du plan comptable">
          <button v-for="item in [
            ['types', 'Types'], ['sense', 'Sens'], ['rubrics', 'Rubriques'],
            ['accounts', 'Comptes'], ['opening', 'Ouverture']
          ]" :key="item[0]" :class="{ active: planSection === item[0] }" type="button" @click="selectPlanSection(item[0])">
            {{ item[1] }}
          </button>
        </nav>

        <form v-if="planSection === 'types'" class="stack" @submit.prevent="saveTypes">
          <label v-for="type in workspace.chart.types" :key="type.id">{{ type.code }}
            <input v-model="typeLabels[type.id]" :disabled="!canSetup" required>
          </label>
          <button class="button primary" :disabled="!canSetup || accounting.saving">Enregistrer les types</button>
        </form>

        <form v-else-if="planSection === 'sense'" class="stack" @submit.prevent="saveSense">
          <p>Les préfixes suivants donnent automatiquement un sens créditeur aux comptes concernés.</p>
          <label>Préfixes séparés par une virgule
            <input v-model="prefixText" :disabled="!canSetup" placeholder="2, 3, 9">
          </label>
          <button class="button primary" :disabled="!canSetup || accounting.saving">Enregistrer les règles</button>
        </form>

        <template v-else-if="planSection === 'rubrics'">
          <label class="compact-control">Niveau
            <select v-model="rubricLevel">
              <option value="classe">Classes</option><option value="groupe_principal">Groupes principaux</option>
              <option value="groupe">Groupes</option><option value="sous_groupe">Sous-groupes</option>
            </select>
          </label>
          <div class="table-scroll"><table class="editable-table"><thead><tr><th>Code</th><th>Libellé</th><th>Parent</th><th>Type</th><th>Ordre</th><th>Actions</th></tr></thead>
            <tbody>
              <tr v-for="rubric in visibleRubrics" :key="rubric.id">
                <td><input v-model="rubricDrafts[rubric.id].code" :disabled="!canSetup"></td>
                <td><input v-model="rubricDrafts[rubric.id].label" :disabled="!canSetup"></td>
                <td><select v-model="rubricDrafts[rubric.id].parent_id" :disabled="!canSetup || rubricLevel === 'classe'"><option :value="null">—</option><option v-for="parent in parentOptions(rubricLevel)" :key="parent.id" :value="parent.id">{{ parent.code }} {{ parent.label }}</option></select></td>
                <td><select v-model="rubricDrafts[rubric.id].type" :disabled="!canSetup || rubricLevel !== 'classe'"><option v-for="type in workspace.chart.types" :key="type.code" :value="type.code">{{ type.label }}</option></select></td>
                <td><button class="icon-button" type="button" :disabled="!canSetup" @click="moveRubric(rubric.id, -1)">↑</button><button class="icon-button" type="button" :disabled="!canSetup" @click="moveRubric(rubric.id, 1)">↓</button></td>
                <td><button class="button small" type="button" :disabled="!canSetup" @click="saveRubric(rubric.id)">Enregistrer</button><button class="button danger small" type="button" :disabled="!canSetup" @click="deleteRubric(rubric.id)">Retirer</button></td>
              </tr>
            </tbody>
          </table></div>
          <form class="inline-create" @submit.prevent="createRubric">
            <input v-model="newRubric.code" placeholder="Code"><input v-model="newRubric.label" placeholder="Nouvelle rubrique" required>
            <select v-model="newRubric.parent_id" :disabled="rubricLevel === 'classe'"><option :value="null">Parent…</option><option v-for="parent in parentOptions(rubricLevel)" :key="parent.id" :value="parent.id">{{ parent.code }} {{ parent.label }}</option></select>
            <select v-model="newRubric.type" :disabled="rubricLevel !== 'classe'"><option v-for="type in workspace.chart.types" :key="type.code" :value="type.code">{{ type.label }}</option></select>
            <button class="button primary" :disabled="!canSetup">Ajouter</button>
          </form>
        </template>

        <template v-else-if="planSection === 'accounts'">
          <label class="wide-control">Rechercher
            <input v-model="accountSearch" type="search" placeholder="Numéro, libellé ou rubrique">
          </label>
          <div class="table-scroll"><table class="editable-table"><thead><tr><th>N°</th><th>Libellé</th><th>Rubrique</th><th>Sens</th><th>Ordre</th><th>Actions</th></tr></thead>
            <tbody>
              <tr v-for="account in visibleAccounts" :key="account.id" :class="{ inactive: !account.active }">
                <td><input v-model="accountDrafts[account.id].number" :disabled="!canSetup"></td>
                <td><input v-model="accountDrafts[account.id].label" :disabled="!canSetup"></td>
                <td><select v-model="accountDrafts[account.id].rubric_id" :disabled="!canSetup"><option :value="null">Sans rubrique</option><option v-for="rubric in workspace.chart.rubrics" :key="rubric.id" :value="rubric.id">{{ rubric.path }}</option></select></td>
                <td><select v-model="accountDrafts[account.id].sense_mode" :disabled="!canSetup"><option value="automatique">Automatique</option><option value="debit">Débit</option><option value="credit">Crédit</option></select></td>
                <td><button class="icon-button" type="button" :disabled="!canSetup || !!accountSearch" @click="moveAccount(account.id, -1)">↑</button><button class="icon-button" type="button" :disabled="!canSetup || !!accountSearch" @click="moveAccount(account.id, 1)">↓</button></td>
                <td><button class="button small" type="button" :disabled="!canSetup" @click="saveAccount(account.id)">Enregistrer</button><button class="button danger small" type="button" :disabled="!canSetup" @click="deleteAccount(account.id)">Retirer</button></td>
              </tr>
            </tbody>
          </table></div>
          <form class="inline-create" @submit.prevent="createAccount">
            <input v-model="newAccount.number" placeholder="N°" required><input v-model="newAccount.label" placeholder="Nouveau compte" required>
            <select v-model="newAccount.rubric_id"><option :value="null">Rubrique…</option><option v-for="rubric in workspace.chart.rubrics" :key="rubric.id" :value="rubric.id">{{ rubric.path }}</option></select>
            <select v-model="newAccount.sense_mode"><option value="automatique">Automatique</option><option value="debit">Débit</option><option value="credit">Crédit</option></select>
            <button class="button primary" :disabled="!canSetup">Ajouter</button>
          </form>
        </template>

        <template v-else>
          <div class="section-heading"><div><h3>Soldes d’ouverture</h3><p>{{ workspace.opening.status === 'absent' ? 'Aucun brouillon' : `État : ${workspace.opening.status}` }}</p></div><span v-if="workspace.opening.number">{{ workspace.opening.number }}</span></div>
          <div class="table-scroll"><table class="editable-table"><thead><tr><th>Compte</th><th>Type</th><th>Sens naturel</th><th>Solde initial</th></tr></thead>
            <tbody><tr v-for="account in openingAccounts" :key="account.id"><td>{{ account.number }} — {{ account.label }}</td><td>{{ account.type }}</td><td>{{ account.normal_side }}</td><td><input v-model="openingDrafts[account.id]" :disabled="!canSetup || workspace.opening.status === 'validee'" inputmode="decimal" placeholder="0.00"></td></tr></tbody>
          </table></div>
          <div class="button-row"><button class="button" type="button" :disabled="!canSetup || workspace.opening.status === 'validee'" @click="saveOpening(false)">Enregistrer le brouillon</button><button class="button primary" type="button" :disabled="!canValidate || workspace.opening.status === 'validee'" @click="saveOpening(true)">Valider l’ouverture</button></div>
        </template>
      </section>

      <section v-else-if="currentTab === 'etats'" class="stack">
        <section class="panel">
          <div class="section-heading">
            <div>
              <p class="eyebrow">Lecture seule · grand livre validé</p>
              <h2>États financiers</h2>
            </div>
            <span class="status-chip ok">Paramètres affichés et exportés</span>
          </div>
          <form class="form-grid three report-filters" @submit.prevent="applyReportPeriod">
            <label>Du
              <input v-model="reportStart" type="date" :min="workspace.exercise.start_date" :max="workspace.exercise.end_date" required>
            </label>
            <label>Au
              <input v-model="reportEnd" type="date" :min="workspace.exercise.start_date" :max="workspace.exercise.end_date" required>
            </label>
            <button class="button primary" :disabled="accounting.loading">Recalculer</button>
          </form>
          <div class="control-grid">
            <span :class="['status-chip', workspace.reports.controls.debit_equals_credit ? 'ok' : 'warning']">Débit = crédit</span>
            <span :class="['status-chip', workspace.reports.controls.balance_sheet_balanced ? 'ok' : 'warning']">Bilan équilibré</span>
            <span :class="['status-chip', workspace.reports.controls.result_reconciled ? 'ok' : 'warning']">Résultat réconcilié</span>
            <span :class="['status-chip', workspace.reports.controls.cash_reconciled ? 'ok' : 'warning']">Flux réconcilié</span>
          </div>
          <nav class="subtabs" aria-label="États financiers">
            <button v-for="item in [
              ['balance', 'Balance'], ['bilan', 'Bilan'], ['resultat', 'Compte de résultat'],
              ['flux', 'Flux de trésorerie'], ['grand_livre', 'Grand livre']
            ]" :key="item[0]" :class="{ active: reportSection === item[0] }" type="button" @click="selectReportSection(item[0])">
              {{ item[1] }}
            </button>
          </nav>
        </section>

        <section v-if="reportSection === 'balance'" class="panel">
          <div class="section-heading"><h3>Balance de vérification</h3><button class="button secondary small" :disabled="!workspace.capabilities.export" @click="exportReport('balance')">Exporter CSV</button></div>
          <div class="table-scroll"><table><thead><tr><th>Compte</th><th>Rubrique</th><th>Débit</th><th>Crédit</th><th>Solde</th></tr></thead>
            <tbody><tr v-for="row in workspace.reports.trial_balance.items" :key="row.id"><td>{{ row.numero }} — {{ row.libelle }}</td><td>{{ row.rubrique_chemin || '—' }}</td><td>{{ formatMoney(row.debit_centimes) }}</td><td>{{ formatMoney(row.credit_centimes) }}</td><td>{{ formatMoney(row.solde_centimes) }}</td></tr>
              <tr v-if="!workspace.reports.trial_balance.items.length"><td colspan="5">Aucun mouvement validé sur la période.</td></tr></tbody>
            <tfoot><tr><th colspan="2">Totaux</th><th>{{ formatMoney(workspace.reports.trial_balance.total_debit_centimes) }}</th><th>{{ formatMoney(workspace.reports.trial_balance.total_credit_centimes) }}</th><th></th></tr></tfoot>
          </table></div>
        </section>

        <section v-else-if="reportSection === 'bilan'" class="panel">
          <div class="section-heading"><h3>Bilan</h3><button class="button secondary small" :disabled="!workspace.capabilities.export" @click="exportReport('bilan')">Exporter CSV</button></div>
          <div class="metric-strip"><span><small>Actif</small><strong>{{ formatMoney(workspace.reports.balance_sheet.total_actif_centimes) }}</strong></span><span><small>Passif et résultat</small><strong>{{ formatMoney(workspace.reports.balance_sheet.total_passif_centimes) }}</strong></span></div>
          <div class="table-scroll"><table><thead><tr><th>Compte</th><th>Rubrique</th><th>Classe</th><th>Solde</th></tr></thead>
            <tbody><tr v-for="row in workspace.reports.balance_sheet.items" :key="`${row.id}-${row.numero}`"><td>{{ row.numero }} — {{ row.libelle }}</td><td>{{ row.rubrique_chemin || '—' }}</td><td>{{ row.type }}</td><td>{{ formatMoney(row.solde_centimes) }}</td></tr></tbody>
          </table></div>
        </section>

        <section v-else-if="reportSection === 'resultat'" class="panel">
          <div class="section-heading"><div><h3>Compte de résultat comparatif</h3><p>{{ workspace.reports.income_statement.current.label }} / {{ workspace.reports.income_statement.previous.label || 'aucun exercice antérieur' }}</p></div><button class="button secondary small" :disabled="!workspace.capabilities.export" @click="exportReport('resultat')">Exporter CSV</button></div>
          <div class="metric-strip"><span><small>Produits</small><strong>{{ formatMoney(workspace.reports.income_statement.current.products_cents) }}</strong></span><span><small>Charges</small><strong>{{ formatMoney(workspace.reports.income_statement.current.expenses_cents) }}</strong></span><span><small>Résultat</small><strong>{{ formatMoney(workspace.reports.income_statement.current.result_cents) }}</strong></span></div>
          <div class="table-scroll"><table><thead><tr><th>Compte</th><th>Rubrique</th><th>Courant</th><th>Précédent</th><th>Écart</th></tr></thead>
            <tbody><tr v-for="row in workspace.reports.income_statement.items" :key="row.number"><td>{{ row.number }} — {{ row.label }}</td><td>{{ row.rubric_path || '—' }}</td><td>{{ formatMoney(row.current_cents) }}</td><td>{{ formatMoney(row.previous_cents) }}</td><td>{{ formatMoney(row.delta_cents) }}</td></tr></tbody>
          </table></div>
        </section>

        <section v-else-if="reportSection === 'flux'" class="panel">
          <div class="section-heading"><div><h3>Flux de trésorerie</h3><p>{{ workspace.reports.cash_flow.method_label }} · classement {{ workspace.reports.cash_flow.classification_status.replace('_', ' ') }}</p></div><button class="button secondary small" :disabled="!workspace.capabilities.export" @click="exportReport('flux_tresorerie')">Exporter CSV</button></div>
          <div class="metric-strip"><span><small>Ouverture</small><strong>{{ formatMoney(workspace.reports.cash_flow.opening_cash_cents) }}</strong></span><span><small>Entrées</small><strong>{{ formatMoney(workspace.reports.cash_flow.inflows_cents) }}</strong></span><span><small>Sorties</small><strong>{{ formatMoney(workspace.reports.cash_flow.outflows_cents) }}</strong></span><span><small>Clôture</small><strong>{{ formatMoney(workspace.reports.cash_flow.closing_cash_cents) }}</strong></span></div>
          <p :class="['notice', workspace.reports.controls.cash_reconciled ? 'success' : 'warning']">Variation calculée {{ formatMoney(workspace.reports.cash_flow.net_change_cents) }} · écart de réconciliation {{ formatMoney(workspace.reports.cash_flow.reconciliation_difference_cents) }}.</p>
          <div class="table-scroll"><table><thead><tr><th>Date</th><th>N°</th><th>Libellé</th><th>Catégorie proposée</th><th>Flux</th></tr></thead>
            <tbody><tr v-for="row in workspace.reports.cash_flow.items" :key="row.entry_id"><td>{{ row.date }}</td><td>{{ row.number }}</td><td>{{ row.label }}</td><td>{{ row.category.replace('_', ' ') }}</td><td>{{ formatMoney(row.amount_cents) }}</td></tr>
              <tr v-if="!workspace.reports.cash_flow.items.length"><td colspan="5">Aucun mouvement de liquidité sur la période.</td></tr></tbody>
          </table></div>
        </section>

        <section v-else class="panel">
          <div class="section-heading"><h3>Grand livre synthétique</h3><button class="button secondary small" :disabled="!workspace.capabilities.export" @click="exportReport('grand_livre')">Exporter CSV</button></div>
          <div class="table-scroll"><table><thead><tr><th>Compte</th><th>Initial</th><th>Débit</th><th>Crédit</th><th>Final</th></tr></thead>
            <tbody><tr v-for="row in workspace.reports.general_ledger.items" :key="row.id"><td>{{ row.numero }} — {{ row.libelle }}</td><td>{{ formatMoney(row.initial_centimes) }}</td><td>{{ formatMoney(row.debit_centimes) }}</td><td>{{ formatMoney(row.credit_centimes) }}</td><td>{{ formatMoney(row.solde_centimes) }}</td></tr>
              <tr v-if="!workspace.reports.general_ledger.items.length"><td colspan="5">Aucun compte mouvementé.</td></tr></tbody>
          </table></div>
        </section>
      </section>

      <section v-else-if="currentTab === 'tva'" class="stack">
        <section class="panel">
          <div class="section-heading"><div><p class="eyebrow">Référentiel TVA unique</p><h2>Décompte TVA</h2></div><span class="status-chip">{{ workspace.vat.standard.format }} {{ workspace.vat.standard.version }}</span></div>
          <p class="notice warning">L’application prépare et valide le fichier XML. La vérification puis la transmission à l’AFC restent manuelles.</p>
          <EmptyState v-if="!workspace.vat.regime" title="Aucun régime TVA actif" description="Configurez d’abord le régime, la méthode et les comptes TVA dans Configuration > Référentiels." />
          <template v-else>
            <div class="metric-strip"><span><small>N° TVA</small><strong>{{ workspace.vat.regime.vat_number }}</strong></span><span><small>Méthode</small><strong>{{ workspace.vat.regime.method }}</strong></span><span><small>Mode</small><strong>{{ workspace.vat.regime.reporting_mode }}</strong></span><span><small>Vérifié le</small><strong>{{ workspace.vat.regime.verified_on }}</strong></span></div>
            <form class="form-grid three" @submit.prevent="createVatPeriod"><label>Début<input v-model="vatPeriod.start" type="date" required></label><label>Fin<input v-model="vatPeriod.end" type="date" required></label><button class="button primary" :disabled="!workspace.capabilities.vat_setup">Créer la période</button></form>
          </template>
        </section>
        <section v-if="workspace.vat.regime" class="panel">
          <div class="section-heading"><h3>Périodes et décomptes</h3><label class="compact-control">Décompte<select v-model.number="selectedVatStatementId" @change="selectVatStatement"><option :value="0">Aucun</option><option v-for="statement in workspace.vat.statements" :key="statement.id" :value="statement.id">{{ statement.start_date }} – {{ statement.end_date }} · {{ statement.status }}</option></select></label></div>
          <div class="table-scroll"><table><thead><tr><th>Période</th><th>Statut</th><th>Action</th></tr></thead><tbody>
            <tr v-for="period in workspace.vat.periods" :key="period.id"><td>{{ period.start_date }} – {{ period.end_date }}</td><td><span class="status-chip">{{ period.status }}</span></td><td><button class="button small" :disabled="!workspace.capabilities.vat_prepare || !['ouverte', 'preparee'].includes(period.status)" @click="prepareVatStatement(period.id)">Préparer</button></td></tr>
            <tr v-if="!workspace.vat.periods.length"><td colspan="3">Aucune période TVA.</td></tr>
          </tbody></table></div>
        </section>
        <section v-if="workspace.vat.selected_statement" class="panel">
          <div class="section-heading"><div><h3>Décompte #{{ workspace.vat.selected_statement.summary.id }}</h3><p>Traçabilité jusqu’aux écritures sources et rapprochement avec les comptes TVA.</p></div><span class="status-chip">{{ workspace.vat.selected_statement.summary.status }}</span></div>
          <div class="metric-strip"><span><small>Chiffre d’affaires</small><strong>{{ formatMoney(workspace.vat.selected_statement.summary.turnover_cents) }}</strong></span><span><small>TVA due</small><strong>{{ formatMoney(workspace.vat.selected_statement.summary.vat_due_cents) }}</strong></span><span><small>Impôt préalable</small><strong>{{ formatMoney(workspace.vat.selected_statement.summary.input_tax_cents) }}</strong></span><span><small>Solde</small><strong>{{ formatMoney(workspace.vat.selected_statement.summary.balance_cents) }}</strong></span></div>
          <div class="button-row"><button class="button" :disabled="!workspace.capabilities.vat_control || workspace.vat.selected_statement.summary.status !== 'prepare'" @click="runVatAction('control', workspace.vat.selected_statement.summary.id)">Contrôler</button><button class="button" :disabled="!workspace.capabilities.vat_export || workspace.vat.selected_statement.summary.status !== 'controle'" @click="runVatAction('export', workspace.vat.selected_statement.summary.id)">Générer eCH-0217</button><button class="button primary" :disabled="!workspace.capabilities.vat_declare || workspace.vat.selected_statement.summary.status !== 'exporte'" @click="runVatAction('declare', workspace.vat.selected_statement.summary.id)">Marquer transmis</button></div>
          <div class="table-scroll"><table><thead><tr><th>Case AFC</th><th>Libellé</th><th>Montant</th></tr></thead><tbody><tr v-for="box in workspace.vat.selected_statement.boxes" :key="box.code"><td>{{ box.code }}</td><td>{{ box.label }}</td><td>{{ formatMoney(box.amount_cents) }}</td></tr></tbody></table></div>
          <h4>Sources</h4>
          <div class="table-scroll"><table><thead><tr><th>Date</th><th>Écriture</th><th>Case</th><th>Base</th><th>TVA</th></tr></thead><tbody><tr v-for="source in workspace.vat.selected_statement.sources" :key="source.vat_line_id"><td>{{ source.date }}</td><td>{{ source.entry_number }} — {{ source.label }}</td><td>{{ source.box }}</td><td>{{ formatMoney(source.base_cents) }}</td><td>{{ formatMoney(source.vat_cents - source.input_tax_cents) }}</td></tr><tr v-if="!workspace.vat.selected_statement.sources.length"><td colspan="5">Aucune ligne TVA dans ce décompte.</td></tr></tbody></table></div>
          <div v-if="workspace.vat.selected_statement.exports.length" class="archive-list"><a v-for="item in workspace.vat.selected_statement.exports" :key="item.id" class="button secondary small" :href="`${runtimeConfig.apiBaseUrl}/accounting/vat/exports/download?export_id=${item.id}`">XML {{ item.schema_version }} · {{ item.hash.slice(0, 12) }}…</a></div>
        </section>
      </section>

      <section v-else-if="currentTab === 'cloture'" class="stack">
        <section class="panel">
          <div class="section-heading"><div><p class="eyebrow">Checklist contrôlée</p><h2>Clôture et verrouillage</h2></div><span :class="['status-chip', workspace.closing.can_close ? 'ok' : 'warning']">{{ workspace.closing.can_close ? 'Contrôles automatiques conformes' : 'Écarts à corriger' }}</span></div>
          <p>{{ workspace.closing.definition }}</p>
          <div class="control-list"><p v-for="control in workspace.closing.automatic_controls" :key="control.code"><span :class="['status-chip', control.passed ? 'ok' : 'warning']">{{ control.passed ? 'OK' : 'Écart' }}</span> {{ control.label }} <small>{{ control.detail }}</small></p></div>
        </section>
        <section class="panel">
          <h3>Contrôles documentés</h3>
          <form v-for="control in workspace.closing.manual_controls" :key="control.code" class="closing-control" @submit.prevent="saveClosingControl(control)"><strong>{{ control.label }}</strong><select v-model="control.status" :disabled="!workspace.capabilities.setup"><option value="a_faire">À faire</option><option value="termine">Terminé</option><option value="non_applicable">Non applicable</option></select><input v-model="control.note" :disabled="!workspace.capabilities.setup" placeholder="Note de revue"><button class="button small" :disabled="!workspace.capabilities.setup">Enregistrer</button></form>
        </section>
        <section class="panel">
          <div class="section-heading">
            <div>
              <p class="eyebrow">Action explicite et réversible</p>
              <h3>Réévaluation des postes en devises</h3>
            </div>
          </div>
          <p>Les factures ouvertes sont valorisées au dernier taux daté disponible. Le taux, sa source et l’écart latent restent attachés à l’écriture.</p>
          <form class="form-grid three" @submit.prevent="postExchangeRevaluation">
            <label>Date de clôture<input v-model="exchangeRevaluation.date" type="date" required></label>
            <label>Journal
              <select v-model.number="exchangeRevaluation.journal_id" required>
                <option :value="0">Choisir…</option>
                <option v-for="journal in workspace.catalog.journals" :key="journal.id" :value="journal.id">{{ journal.code }} — {{ journal.label }}</option>
              </select>
            </label>
            <button class="button primary" :disabled="!workspace.capabilities.validate">Comptabiliser la réévaluation</button>
          </form>
          <div class="table-scroll">
            <table>
              <thead><tr><th>Date</th><th>Écriture</th><th>Postes</th><th>Écart net</th><th>Statut</th><th></th></tr></thead>
              <tbody>
                <tr v-for="item in workspace.exchange_revaluations" :key="item.id">
                  <td>{{ item.date }}</td><td>{{ item.entry_number }}</td><td>{{ item.item_count }}</td>
                  <td>{{ formatMoney(item.net_difference_cents) }}</td><td>{{ item.status }}</td>
                  <td><button v-if="item.status === 'comptabilisee'" class="button small danger" type="button" :disabled="!workspace.capabilities.validate" @click="reverseExchangeRevaluation(item.id)">Contre-passer</button></td>
                </tr>
                <tr v-if="!workspace.exchange_revaluations.length"><td colspan="6">Aucune réévaluation comptabilisée.</td></tr>
              </tbody>
            </table>
          </div>
        </section>
        <section class="panel">
          <div class="section-heading"><h3>Périodes</h3><button class="button secondary" :disabled="!workspace.capabilities.export" @click="createArchive('cloture')">Archiver la clôture</button></div>
          <div class="table-scroll"><table><thead><tr><th>Période</th><th>Dates</th><th>Statut</th><th>Action</th></tr></thead><tbody><tr v-for="period in workspace.closing.periods" :key="period.id"><td>{{ period.label }}</td><td>{{ period.start_date }} – {{ period.end_date }}</td><td><span class="status-chip">{{ period.status }}</span></td><td><button class="button small" :disabled="!workspace.capabilities.setup || (period.status === 'ouverte' && !workspace.closing.can_close)" @click="togglePeriod(period)">{{ period.status === 'ouverte' ? 'Fermer' : 'Rouvrir' }}</button></td></tr></tbody></table></div>
        </section>
      </section>

      <section v-else-if="currentTab === 'fiscal'" class="stack">
        <section class="panel">
          <div class="section-heading"><div><p class="eyebrow">Dossier préparatoire</p><h2>Dossier fiscal</h2></div><span class="status-chip warning">Pas une déclaration officielle</span></div>
          <p class="notice warning">{{ workspace.tax_file.disclaimer }}</p>
          <div class="metric-strip"><span><small>Lignes bancaires non rapprochées</small><strong>{{ workspace.tax_file.bank_reconciliation.unmatched_lines }}</strong></span><span><small>Écart non rapproché</small><strong>{{ formatMoney(workspace.tax_file.bank_reconciliation.unmatched_cents) }}</strong></span><span><small>Pièces liées</small><strong>{{ workspace.tax_file.supporting_documents.linked_attachments }} / {{ workspace.tax_file.supporting_documents.financial_documents }}</strong></span><span><small>Pièces fournisseurs manquantes</small><strong>{{ workspace.tax_file.supporting_documents.missing_supplier_attachments }}</strong></span></div>
          <div class="button-row"><button class="button secondary" :disabled="!workspace.capabilities.export" @click="createArchive('dossier_fiscal')">Créer l’archive du dossier</button></div>
        </section>
        <section class="panel">
          <h3>Ajustements de travail</h3>
          <form class="form-grid three" @submit.prevent="createTaxAdjustment"><label>Libellé<input v-model="taxAdjustment.label" required></label><label>Nature<select v-model="taxAdjustment.nature"><option value="augmentation">Augmentation</option><option value="deduction">Déduction</option><option value="information">Information</option></select></label><label>Montant<input v-model="taxAdjustment.amount" inputmode="decimal" placeholder="0.00" required></label><label class="span-all">Note<input v-model="taxAdjustment.note"></label><button class="button primary" :disabled="!workspace.capabilities.setup">Ajouter</button></form>
          <div class="table-scroll"><table><thead><tr><th>Libellé</th><th>Nature</th><th>Montant</th><th>Note</th><th>Statut</th><th>Actions</th></tr></thead><tbody><tr v-for="adjustment in workspace.tax_file.adjustments" :key="adjustment.id"><td>{{ adjustment.label }}</td><td>{{ adjustment.nature }}</td><td>{{ formatMoney(adjustment.amount_cents) }}</td><td>{{ adjustment.note || '—' }}</td><td><span class="status-chip">{{ adjustment.status }}</span></td><td><button class="button small" :disabled="!workspace.capabilities.setup || adjustment.status === 'valide'" @click="setTaxAdjustmentStatus(adjustment, 'valide')">Valider</button><button class="button small danger" :disabled="!workspace.capabilities.setup || adjustment.status === 'ecarte'" @click="setTaxAdjustmentStatus(adjustment, 'ecarte')">Écarter</button></td></tr><tr v-if="!workspace.tax_file.adjustments.length"><td colspan="6">Aucun ajustement préparatoire.</td></tr></tbody></table></div>
        </section>
        <section v-if="workspace.closing.archives.length" class="panel">
          <h3>Archives financières vérifiables</h3>
          <div class="archive-list"><a v-for="item in workspace.closing.archives" :key="item.id" class="archive-card" :href="`${runtimeConfig.apiBaseUrl}/accounting/archives/download?archive_id=${item.id}`"><strong>{{ item.type.replace('_', ' ') }} · {{ item.created_at }}</strong><small>{{ item.hash }}</small><small>Grand livre {{ item.ledger_hash }}</small></a></div>
        </section>
      </section>

      <AssetsPanel
        v-else-if="currentTab === 'amortissements'"
        :exercise-id="exerciseId"
        :currency="currency"
      />
      <ConsolidationPanel v-else-if="currentTab === 'consolidation'" />
      <EmptyState v-else title="Section inconnue" description="Choisissez un onglet comptable disponible." />
    </template>
  </template>
</template>
