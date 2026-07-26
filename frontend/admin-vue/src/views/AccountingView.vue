<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import CompactTabs from '@/components/ui/CompactTabs.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import SkeletonBlock from '@/components/ui/SkeletonBlock.vue';
import { subNavigation } from '@/router/navigation';
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
const currentTab = computed(() => String(route.params.tab || 'journalisation'));
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
    if (!entry.journal_id && value.catalog.journals.length) {
      entry.journal_id = value.catalog.journals[0].id;
    }
  },
  { deep: true }
);

async function reload(accountId = selectedAccountId.value || undefined): Promise<void> {
  if (exerciseId.value > 0) await accounting.load(exerciseId.value, accountId);
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

function formatMoney(cents: number): string {
  const sign = cents < 0 ? '−' : '';
  const absolute = Math.abs(cents);
  return `${sign}${currency.value} ${Math.floor(absolute / 100).toLocaleString('fr-CH')}.${String(absolute % 100).padStart(2, '0')}`;
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
</script>

<template>
  <header class="page-header accounting-header">
    <div>
      <p class="eyebrow">Moteur comptable unique</p>
      <h1>Comptabilité</h1>
      <p>Journal, extraits et plan pilotés par les mêmes services PHP et la même base SQLite.</p>
    </div>
    <label v-if="context.exercises.length" class="compact-control">
      <span>Exercice</span>
      <select v-model.number="exerciseId" @change="reload()">
        <option v-for="exercise in context.exercises" :key="exercise.id" :value="exercise.id">
          {{ exercise.label }}
        </option>
      </select>
    </label>
  </header>

  <CompactTabs v-if="allowed" :items="subNavigation.accounting" label="Navigation comptable" />

  <EmptyState
    v-if="!context.selection"
    title="Sélectionnez un dossier"
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
            <table><thead><tr><th>Date</th><th>N°</th><th>Journal</th><th>Libellé</th><th>Débit</th><th>Crédit</th><th>Solde</th></tr></thead>
              <tbody><tr v-for="row in workspace.ledger.items" :key="`${row.ecriture_id}-${row.date_comptable}-${row.libelle}`">
                <td>{{ row.date_comptable }}</td><td>{{ row.numero }}</td><td>{{ row.journal }}</td><td>{{ row.libelle }}</td>
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

      <EmptyState
        v-else
        :title="currentTab === 'etats' ? 'États financiers' : currentTab === 'tva' ? 'TVA' : 'Amortissements'"
        description="Ce parcours sera porté dans Vue par le lot spécialisé prévu, en conservant le même grand livre."
      />
    </template>
  </template>
</template>
