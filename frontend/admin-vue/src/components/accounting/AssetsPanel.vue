<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import type {
  AssetCategory,
  AssetRecord,
  AssetWorkspace
} from '@/api/contracts';
import AccountCombobox from '@/components/ui/AccountCombobox.vue';
import ActionMenu from '@/components/ui/ActionMenu.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import ModalDialog from '@/components/ui/ModalDialog.vue';
import SkeletonBlock from '@/components/ui/SkeletonBlock.vue';
import { useToastFeedback } from '@/composables/toastFeedback';
import { useAssetStore } from '@/stores/assets';
import { useContextStore } from '@/stores/context';
import { formatDate } from '@/utils/dateFormat';

const props = defineProps<{ exerciseId: number; currency: string }>();
const assets = useAssetStore();
useToastFeedback(assets);
const context = useContextStore();
const section = ref<'register' | 'schedule' | 'reconciliation' | 'categories'>('register');
const selectedAssetId = ref(0);
const journalId = ref(0);
const categoryEditorDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const assetEditorDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const assetViewerDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const expandedScheduleGroupKey = ref('');
const expandedReconciliationKey = ref('');
const scheduleTab = ref<'due' | 'posted' | 'future'>('due');

const categoryDraft = reactive({
  id: 0,
  version: 0,
  code: '',
  label: '',
  default_duration_months: 60,
  asset_account_id: 0,
  accumulated_depreciation_account_id: 0,
  depreciation_expense_account_id: 0,
  disposal_gain_account_id: 0,
  disposal_loss_account_id: 0,
  active: true
});
const assetDraft = reactive({
  id: 0,
  version: 0,
  category_id: 0,
  code: '',
  label: '',
  acquisition_reference: '',
  acquisition_document_id: null as number | null,
  acquisition_attachment_id: null as number | null,
  acquisition_date: '',
  in_service_date: '',
  acquisition_value: '',
  residual_value: '0.01',
  note: ''
});
const disposal = reactive({
  type: 'cession' as 'cession' | 'mise_au_rebut',
  date: '',
  proceeds: '0.00',
  proceeds_account_id: null as number | null
});

const workspace = computed(() => assets.workspace);
const selected = computed(() => workspace.value?.selected_asset ?? null);
const selectedDraftCategory = computed(() =>
  workspace.value?.categories.find((item) => item.id === assetDraft.category_id) ?? null
);
type ScheduleItem = AssetWorkspace['schedule'][number];
type SchedulePeriod = {
  key: string;
  startDate: string;
  endDate: string;
  postingDate: string;
  days: number | null;
  amountCents: number;
  rows: ScheduleItem[];
  assets: Array<{ id: number; code: string; label: string }>;
};
type ScheduleGroup = {
  key: string;
  account: string;
  categoryCode: string;
  categoryLabel: string;
  rows: AssetWorkspace['schedule'];
  periods: SchedulePeriod[];
};
const scheduleGroups = computed<ScheduleGroup[]>(() => {
  const groups = new Map<string, Omit<ScheduleGroup, 'periods'>>();
  for (const row of workspace.value?.schedule ?? []) {
    const key = `${row.asset_account_id}-${row.category_id}`;
    const group = groups.get(key) ?? {
      key,
      account: row.asset_account,
      categoryCode: row.category_code,
      categoryLabel: row.category_label,
      rows: []
    };
    group.rows.push(row);
    groups.set(key, group);
  }
  return [...groups.values()].map((group) => {
    const periods = new Map<string, SchedulePeriod>();
    for (const row of group.rows) {
      const key = `${row.start_date}-${row.end_date}-${row.posting_date}`;
      const period = periods.get(key) ?? {
        key,
        startDate: row.start_date,
        endDate: row.end_date,
        postingDate: row.posting_date,
        days: row.days,
        amountCents: 0,
        rows: [],
        assets: []
      };
      period.amountCents += row.amount_cents;
      period.rows.push(row);
      if (period.days !== row.days) period.days = null;
      if (!period.assets.some((asset) => asset.id === row.asset_id)) {
        period.assets.push({
          id: row.asset_id,
          code: row.asset_code,
          label: row.asset_label
        });
      }
      periods.set(key, period);
    }
    return { ...group, periods: [...periods.values()] };
  });
});
const assetAccounts = computed(() =>
  (workspace.value?.catalog.accounts ?? []).filter(
    (account) => account.type === 'actif' && account.normal_side === 'debit'
  )
);
const accumulatedAccounts = computed(() =>
  (workspace.value?.catalog.accounts ?? []).filter(
    (account) => account.type === 'actif' && account.normal_side === 'credit'
  )
);
const expenseAccounts = computed(() =>
  (workspace.value?.catalog.accounts ?? []).filter(
    (account) => account.type === 'charge' && account.normal_side === 'debit'
  )
);
const creditAccounts = computed(() =>
  (workspace.value?.catalog.accounts ?? []).filter(
    (account) => account.normal_side === 'credit'
  )
);
const debitAccounts = computed(() =>
  (workspace.value?.catalog.accounts ?? []).filter(
    (account) => account.normal_side === 'debit'
  )
);

watch(
  () => [props.exerciseId, context.selection?.dossier.id ?? 0] as const,
  ([exerciseId, dossierId]) => {
    if (exerciseId < 1 || dossierId < 1) {
      assets.clear();
      return;
    }
    selectedAssetId.value = 0;
    void load();
  },
  { immediate: true }
);

watch(
  () => assets.workspace,
  (value) => {
    if (!value) return;
    if (!selectedAssetId.value && value.selected_asset) {
      selectedAssetId.value = value.selected_asset.id;
    }
    if (!journalId.value && value.catalog.journals.length) {
      journalId.value = value.catalog.journals[0].id;
    }
    if (!disposal.date) disposal.date = value.exercise.end_date;
    if (!categoryDraft.asset_account_id) setSuggestedCategoryAccounts();
    if (!assetDraft.category_id) resetAsset();
  }
);

async function load(assetId = selectedAssetId.value || undefined): Promise<void> {
  await assets.load(props.exerciseId, assetId);
}

async function openAsset(id: number): Promise<void> {
  selectedAssetId.value = id;
  await load(id);
  if (!selected.value) return;
  disposal.date = selected.value.exit_date || workspace.value?.exercise.end_date || '';
  disposal.type = 'cession';
  disposal.proceeds = '0.00';
  disposal.proceeds_account_id = null;
  assetViewerDialog.value?.open();
}

function parseCents(value: string): number {
  const normalized = value.trim().replace(/[’'\s]/g, '').replace(',', '.');
  const match = normalized.match(/^(\d+)(?:\.(\d{1,2}))?$/);
  if (!match) throw new Error(`Montant invalide : ${value}`);
  const cents = Number(match[1]) * 100 + Number((match[2] || '').padEnd(2, '0'));
  if (!Number.isSafeInteger(cents)) throw new Error('Montant trop élevé.');
  return cents;
}

function centsToInput(cents: number): string {
  return `${Math.floor(cents / 100)}.${String(cents % 100).padStart(2, '0')}`;
}

function formatMoney(cents: number): string {
  const sign = cents < 0 ? '−' : '';
  const absolute = Math.abs(cents);
  return `${sign}${props.currency} ${Math.floor(absolute / 100).toLocaleString('fr-CH')}.${String(absolute % 100).padStart(2, '0')}`;
}

function statusLabel(status: string): string {
  return {
    actif: 'Actif',
    cede: 'Cédé',
    mis_au_rebut: 'Mis au rebut',
    planifiee: 'Planifiée',
    comptabilisee: 'Comptabilisée',
    partielle: 'Partiellement comptabilisée',
    contre_passee: 'Contre-passée',
    annulee: 'Annulée'
  }[status] || status.replaceAll('_', ' ');
}

function schedulePeriodStatus(period: SchedulePeriod): string {
  const statuses = new Set(period.rows.map((row) => row.status));
  return statuses.size === 1 ? period.rows[0]?.status ?? 'planifiee' : 'partielle';
}

function scheduleIds(period: SchedulePeriod, status: ScheduleItem['status']): number[] {
  return period.rows
    .filter((row) => row.status === status && row.amount_cents > 0)
    .map((row) => row.id);
}

function localIsoDate(): string {
  const date = new Date();
  return [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0')
  ].join('-');
}

function schedulePeriodsForTab(
  group: ScheduleGroup,
  tab: 'due' | 'posted' | 'future'
): SchedulePeriod[] {
  const today = localIsoDate();
  return group.periods.filter((period) => {
    const hasPlanned = scheduleIds(period, 'planifiee').length > 0;
    const hasPosted = scheduleIds(period, 'comptabilisee').length > 0;
    if (tab === 'future') {
      return period.postingDate > today && (hasPlanned || hasPosted);
    }
    if (period.postingDate > today) return false;
    return tab === 'due'
      ? hasPlanned
      : !hasPlanned && hasPosted;
  });
}

function toggleScheduleGroup(group: ScheduleGroup): void {
  if (expandedScheduleGroupKey.value === group.key) {
    expandedScheduleGroupKey.value = '';
    return;
  }
  expandedScheduleGroupKey.value = group.key;
  scheduleTab.value = 'due';
}

function reconciliationKey(
  row: AssetWorkspace['reconciliation'][number]
): string {
  return `${row.asset_account_id}-${row.accumulated_account_id}`;
}

function toggleReconciliation(
  row: AssetWorkspace['reconciliation'][number]
): void {
  const key = reconciliationKey(row);
  expandedReconciliationKey.value = expandedReconciliationKey.value === key
    ? ''
    : key;
}

function categoryForAsset(asset: AssetRecord): AssetCategory | null {
  return workspace.value?.categories.find((item) => item.id === asset.category_id) ?? null;
}

function setSuggestedCategoryAccounts(): void {
  const all = workspace.value?.catalog.accounts ?? [];
  const byNumber = (number: string) => all.find((account) => account.number === number)?.id || 0;
  categoryDraft.asset_account_id ||= byNumber('1500');
  categoryDraft.accumulated_depreciation_account_id ||= byNumber('1509');
  categoryDraft.depreciation_expense_account_id ||= byNumber('6800');
  categoryDraft.disposal_gain_account_id ||= byNumber('8510');
  categoryDraft.disposal_loss_account_id ||= byNumber('8500');
}

function resetCategory(): void {
  Object.assign(categoryDraft, {
    id: 0,
    version: 0,
    code: '',
    label: '',
    default_duration_months: 60,
    asset_account_id: 0,
    accumulated_depreciation_account_id: 0,
    depreciation_expense_account_id: 0,
    disposal_gain_account_id: 0,
    disposal_loss_account_id: 0,
    active: true
  });
  setSuggestedCategoryAccounts();
}

function openNewCategory(): void {
  resetCategory();
  categoryEditorDialog.value?.open();
}

function editCategory(category: AssetCategory): void {
  Object.assign(categoryDraft, {
    id: category.id,
    version: category.version,
    code: category.code,
    label: category.label,
    default_duration_months: category.default_duration_months,
    asset_account_id: category.asset_account_id,
    accumulated_depreciation_account_id:
      category.accumulated_depreciation_account_id,
    depreciation_expense_account_id: category.depreciation_expense_account_id,
    disposal_gain_account_id: category.disposal_gain_account_id,
    disposal_loss_account_id: category.disposal_loss_account_id,
    active: category.active
  });
  categoryEditorDialog.value?.open();
}

async function saveCategory(): Promise<void> {
  try {
    await assets.mutate(
      '/accounting/assets/categories',
      { ...categoryDraft },
      categoryDraft.id ? 'Catégorie mise à jour.' : 'Catégorie créée.'
    );
    categoryEditorDialog.value?.close();
    resetCategory();
    await load();
  } catch {
    // Le store affiche l’erreur structurée.
  }
}

function resetAsset(): void {
  const today = workspace.value?.exercise.start_date || '';
  Object.assign(assetDraft, {
    id: 0,
    version: 0,
    category_id: workspace.value?.categories.find((item) => item.active)?.id || 0,
    code: '',
    label: '',
    acquisition_reference: '',
    acquisition_document_id: null,
    acquisition_attachment_id: null,
    acquisition_date: today,
    in_service_date: today,
    acquisition_value: '',
    residual_value: '0.01',
    note: ''
  });
}

function openNewAsset(): void {
  resetAsset();
  assetEditorDialog.value?.open();
}

function editAsset(asset: AssetRecord): void {
  Object.assign(assetDraft, {
    id: asset.id,
    version: asset.version,
    category_id: asset.category_id,
    code: asset.code,
    label: asset.label,
    acquisition_reference: asset.acquisition_reference,
    acquisition_document_id: asset.acquisition_document_id,
    acquisition_attachment_id: asset.acquisition_attachment_id,
    acquisition_date: asset.acquisition_date,
    in_service_date: asset.in_service_date,
    acquisition_value: centsToInput(asset.acquisition_value_cents),
    residual_value: centsToInput(asset.residual_value_cents),
    note: asset.note
  });
  assetEditorDialog.value?.open();
}

function editSelectedAsset(): void {
  if (!selected.value) return;
  assetViewerDialog.value?.close();
  editAsset(selected.value);
}

async function saveAsset(): Promise<void> {
  try {
    await assets.mutate('/accounting/assets/records', {
      id: assetDraft.id,
      version: assetDraft.version,
      category_id: assetDraft.category_id,
      code: assetDraft.code,
      label: assetDraft.label,
      acquisition_reference: assetDraft.acquisition_reference,
      acquisition_document_id: assetDraft.acquisition_document_id,
      acquisition_attachment_id: assetDraft.acquisition_attachment_id,
      acquisition_date: assetDraft.acquisition_date,
      in_service_date: assetDraft.in_service_date,
      acquisition_value_cents: parseCents(assetDraft.acquisition_value),
      residual_value_cents: parseCents(assetDraft.residual_value),
      note: assetDraft.note
    }, assetDraft.id ? 'Fiche corrigée et échéancier recalculé.' : 'Immobilisation créée.');
    assetEditorDialog.value?.close();
    resetAsset();
    await load();
  } catch {
    // Le store affiche l’erreur structurée.
  }
}

async function postSchedules(scheduleIds: number[]): Promise<void> {
  try {
    await assets.mutate('/accounting/assets/depreciations', {
      schedule_ids: scheduleIds,
      exercise_id: props.exerciseId,
      journal_id: journalId.value
    }, 'Amortissements du groupe comptabilisés dans le grand livre.');
    await load();
  } catch {
    // Le store affiche l’erreur structurée.
  }
}

async function reverseSchedules(scheduleIds: number[], date: string): Promise<void> {
  if (!window.confirm('Contre-passer les amortissements comptabilisés de cette période ?')) return;
  try {
    await assets.mutate('/accounting/assets/depreciations/reverse', {
      schedule_ids: scheduleIds,
      date
    }, 'Amortissements du groupe contre-passés ; la période redevient disponible.');
    await load();
  } catch {
    // Le store affiche l’erreur structurée.
  }
}

async function disposeAsset(): Promise<void> {
  if (!selected.value || !window.confirm('Comptabiliser cette sortie d’immobilisation ?')) return;
  try {
    await assets.mutate('/accounting/assets/disposals', {
      asset_id: selected.value.id,
      type: disposal.type,
      date: disposal.date,
      proceeds_cents: disposal.type === 'mise_au_rebut'
        ? 0
        : parseCents(disposal.proceeds),
      proceeds_account_id: disposal.type === 'mise_au_rebut'
        ? null
        : disposal.proceeds_account_id,
      exercise_id: props.exerciseId,
      journal_id: journalId.value
    }, disposal.type === 'cession' ? 'Cession comptabilisée.' : 'Mise au rebut comptabilisée.');
    await load();
  } catch {
    // Le store affiche l’erreur structurée.
  }
}

async function reverseDisposal(): Promise<void> {
  if (!selected.value?.exit_date || !window.confirm('Contre-passer la sortie et restaurer l’actif ?')) return;
  try {
    await assets.mutate('/accounting/assets/disposals/reverse', {
      asset_id: selected.value.id,
      date: selected.value.exit_date
    }, 'Sortie contre-passée et actif restauré.');
    await load();
  } catch {
    // Le store affiche l’erreur structurée.
  }
}
</script>

<template>
  <section class="stack assets-workspace">
    <ErrorSummary :message="assets.error" />
    <SkeletonBlock v-if="assets.loading && !workspace" :lines="9" />

    <template v-if="workspace">
      <section class="panel assets-hero">
        <div class="section-heading">
          <div>
            <p class="eyebrow">Registre relié au grand livre</p>
            <h2>Immobilisations et amortissements</h2>
            <p>{{ workspace.definitions.method }}</p>
          </div>
          <span class="status-chip">{{ workspace.pagination.total }} actif(s)</span>
        </div>
        <nav class="button-row" aria-label="Vues des immobilisations">
          <button class="button small" :class="{ primary: section === 'register' }" @click="section = 'register'">Registre</button>
          <button class="button small" :class="{ primary: section === 'schedule' }" @click="section = 'schedule'">Échéancier</button>
          <button class="button small" :class="{ primary: section === 'reconciliation' }" @click="section = 'reconciliation'">Réconciliation</button>
          <button class="button small" :class="{ primary: section === 'categories' }" @click="section = 'categories'">Catégories</button>
        </nav>
      </section>

      <section v-if="section === 'register'" class="panel">
        <div class="section-heading">
          <div>
            <h3>Registre des immobilisations</h3>
            <p>{{ workspace.definitions.correction }}</p>
          </div>
          <button
            class="button primary"
            type="button"
            :disabled="!workspace.capabilities.setup || !workspace.categories.some((item) => item.active)"
            @click="openNewAsset"
          >Nouvelle immobilisation</button>
        </div>
        <EmptyState
          v-if="!workspace.categories.some((item) => item.active)"
          title="Créez d’abord une catégorie"
          description="La catégorie fixe les comptes et la durée utile."
        />
        <div v-else-if="workspace.assets.length" class="table-scroll">
          <table class="closure-table asset-register-table">
            <thead>
              <tr><th>Actif</th><th>Catégorie</th><th>Compte d’actif</th><th>Mise en service</th><th>Valeur brute</th><th>Amorti</th><th>VNC</th><th>Statut</th><th aria-label="Actions"></th></tr>
            </thead>
            <tbody>
              <tr v-for="asset in workspace.assets" :key="asset.id">
                <td>
                  <button class="asset-code-link" type="button" @click="openAsset(asset.id)">{{ asset.code }}</button>
                  <small>{{ asset.label }}</small>
                </td>
                <td><span class="category-badge">{{ asset.category_code }}</span><small>{{ asset.category }}</small></td>
                <td><span class="account-cell">{{ categoryForAsset(asset)?.accounts.asset }}</span></td>
                <td>{{ formatDate(asset.in_service_date) }}</td>
                <td class="amount">{{ formatMoney(asset.acquisition_value_cents) }}</td>
                <td class="amount">{{ formatMoney(asset.posted_depreciation_cents) }}</td>
                <td class="amount"><strong>{{ formatMoney(asset.net_book_value_cents) }}</strong></td>
                <td><span :class="['status-chip', asset.status === 'actif' ? 'ok' : '']">{{ statusLabel(asset.status) }}</span></td>
                <td class="table-action-cell">
                  <ActionMenu :label="`Actions pour ${asset.code}`">
                    <button type="button" @click="openAsset(asset.id)">Ouvrir la fiche</button>
                    <button v-if="asset.status === 'actif'" type="button" :disabled="!workspace.capabilities.setup" @click="editAsset(asset)">Corriger</button>
                  </ActionMenu>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <EmptyState v-else title="Aucune immobilisation" description="Créez la première fiche depuis ce registre." />
      </section>

      <template v-else-if="section === 'schedule'">
        <section class="panel schedule-toolbar">
          <div class="section-heading">
            <div><h3>Échéancier</h3><p>Dotations trimestrielles regroupées par compte d’actif, catégorie d’amortissement et période.</p></div>
            <label class="compact-control">Journal
              <select v-model.number="journalId">
                <option v-for="journal in workspace.catalog.journals" :key="journal.id" :value="journal.id">{{ journal.code }} — {{ journal.label }}</option>
              </select>
            </label>
          </div>
        </section>
        <EmptyState v-if="!scheduleGroups.length" title="Aucune échéance" description="Créez une immobilisation pour générer son échéancier trimestriel." />
        <section v-for="group in scheduleGroups" :key="group.key" class="panel account-schedule-card">
          <div class="account-group-heading">
            <div>
              <p class="eyebrow">Compte d’actif · Catégorie</p>
              <h3>{{ group.account }}</h3>
              <p><span class="category-badge">{{ group.categoryCode }}</span> {{ group.categoryLabel }}</p>
            </div>
            <div class="schedule-card-actions">
              <div class="schedule-card-summary" aria-label="Résumé de l’échéancier">
                <span class="status-chip warning">{{ schedulePeriodsForTab(group, 'due').length }} à comptabiliser</span>
                <span class="status-chip ok">{{ schedulePeriodsForTab(group, 'posted').length }} comptabilisée(s)</span>
                <span class="status-chip">{{ schedulePeriodsForTab(group, 'future').length }} à venir</span>
              </div>
              <button
                class="icon-button schedule-expand-button"
                type="button"
                :aria-expanded="expandedScheduleGroupKey === group.key"
                :aria-controls="`asset-schedule-${group.key}`"
                :aria-label="expandedScheduleGroupKey === group.key ? `Réduire l’échéancier ${group.categoryCode}` : `Déployer l’échéancier ${group.categoryCode}`"
                :title="expandedScheduleGroupKey === group.key ? `Réduire l’échéancier ${group.categoryCode}` : `Déployer l’échéancier ${group.categoryCode}`"
                @click="toggleScheduleGroup(group)"
              >
                <svg v-if="expandedScheduleGroupKey === group.key" aria-hidden="true" viewBox="0 0 16 16" width="18" height="18"><path fill="currentColor" d="M.172 15.828a.5.5 0 0 0 .707 0L5 11.707V14.5a.5.5 0 0 0 1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 0 0 1h2.793L.172 15.121a.5.5 0 0 0 0 .707zM15.828.172a.5.5 0 0 0-.707 0L11 4.293V1.5a.5.5 0 0 0-1 0v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 0 0-1h-2.793L15.828.879a.5.5 0 0 0 0-.707z" /></svg>
                <svg v-else aria-hidden="true" viewBox="0 0 16 16" width="18" height="18"><path fill="currentColor" d="M1 1v5h1V2.707L6.146 6.854l.708-.708L2.707 2H6V1H1zm9 0v1h3.293L9.146 6.146l.708.708L14 2.707V6h1V1h-5zM6.146 9.146 2 13.293V10H1v5h5v-1H2.707l4.147-4.146-.708-.708zm3.708 0-.708.708L13.293 14H10v1h5v-5h-1v3.293L9.854 9.146z" /></svg>
              </button>
            </div>
          </div>
          <div v-if="expandedScheduleGroupKey === group.key" :id="`asset-schedule-${group.key}`" class="schedule-inline-panel">
            <nav class="subtabs secondary-tabs schedule-inline-tabs" role="tablist" aria-label="Périodes de l’échéancier">
              <button id="asset-schedule-due-tab" :class="{ active: scheduleTab === 'due' }" type="button" role="tab" :aria-selected="scheduleTab === 'due'" aria-controls="asset-schedule-panel" @click="scheduleTab = 'due'">
                Échus à comptabiliser
                <span class="tab-count">{{ schedulePeriodsForTab(group, 'due').length }}</span>
              </button>
              <button id="asset-schedule-posted-tab" :class="{ active: scheduleTab === 'posted' }" type="button" role="tab" :aria-selected="scheduleTab === 'posted'" aria-controls="asset-schedule-panel" @click="scheduleTab = 'posted'">
                Échus comptabilisés
                <span class="tab-count">{{ schedulePeriodsForTab(group, 'posted').length }}</span>
              </button>
              <button id="asset-schedule-future-tab" :class="{ active: scheduleTab === 'future' }" type="button" role="tab" :aria-selected="scheduleTab === 'future'" aria-controls="asset-schedule-panel" @click="scheduleTab = 'future'">
                À venir
                <span class="tab-count">{{ schedulePeriodsForTab(group, 'future').length }}</span>
              </button>
            </nav>
            <div id="asset-schedule-panel" role="tabpanel" :aria-labelledby="`asset-schedule-${scheduleTab}-tab`">
              <div v-if="schedulePeriodsForTab(group, scheduleTab).length" class="table-scroll">
                <table class="closure-table schedule-inline-table">
                  <thead><tr><th>Période</th><th>Actifs</th><th>Jours</th><th>Date comptable</th><th>Dotation totale</th><th>Statut</th><th aria-label="Actions"></th></tr></thead>
                  <tbody>
                    <tr v-for="period in schedulePeriodsForTab(group, scheduleTab)" :key="period.key">
                      <td>{{ formatDate(period.startDate) }} – {{ formatDate(period.endDate) }}</td>
                      <td><div class="asset-code-list"><button v-for="asset in period.assets" :key="asset.id" class="asset-code-link compact" type="button" :title="asset.label" @click="openAsset(asset.id)">{{ asset.code }}</button></div></td>
                      <td>{{ period.days ?? '—' }}</td>
                      <td>{{ formatDate(period.postingDate) }}</td>
                      <td class="amount"><strong>{{ formatMoney(period.amountCents) }}</strong></td>
                      <td><span :class="['status-chip', schedulePeriodStatus(period) === 'comptabilisee' ? 'ok' : '']">{{ statusLabel(schedulePeriodStatus(period)) }}</span></td>
                      <td class="table-action-cell">
                        <ActionMenu :label="`Actions pour la période du ${period.postingDate}`">
                          <button
                            v-if="scheduleIds(period, 'planifiee').length"
                            type="button"
                            :disabled="!workspace.capabilities.post || !journalId || period.postingDate < workspace.exercise.start_date || period.postingDate > workspace.exercise.end_date"
                            @click="postSchedules(scheduleIds(period, 'planifiee'))"
                          >Comptabiliser</button>
                          <button v-if="scheduleIds(period, 'comptabilisee').length" class="danger" type="button" :disabled="!workspace.capabilities.reverse" @click="reverseSchedules(scheduleIds(period, 'comptabilisee'), period.postingDate)">Contre-passer</button>
                        </ActionMenu>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <EmptyState
                v-else
                :title="scheduleTab === 'due' ? 'Aucun amortissement échu à comptabiliser' : scheduleTab === 'posted' ? 'Aucun amortissement échu comptabilisé' : 'Aucun amortissement à venir'"
                :description="scheduleTab === 'due' ? 'Les prochaines dotations restent disponibles dans l’onglet À venir.' : 'Aucune période ne correspond à cette vue.'"
              />
            </div>
          </div>
        </section>
      </template>

      <section v-else-if="section === 'reconciliation'" class="panel">
        <div class="section-heading">
          <div><h3>Réconciliation au grand livre</h3><p>{{ workspace.definitions.reconciliation }}</p></div>
          <span class="status-chip">Au {{ formatDate(workspace.exercise.end_date) }}</span>
        </div>
        <div v-if="workspace.reconciliation.length" class="table-scroll">
          <table class="closure-table reconciliation-table">
            <thead><tr><th>Comptes</th><th>Actifs</th><th>Registre brut</th><th>Grand livre brut</th><th>Écart brut</th><th>Registre amorti</th><th>Grand livre amorti</th><th>Écart amorti</th><th>Contrôle</th></tr></thead>
            <tbody>
              <template v-for="row in workspace.reconciliation" :key="reconciliationKey(row)">
                <tr>
                  <td><strong>{{ row.asset_account }}</strong><small>{{ row.accumulated_account }}</small></td>
                  <td><div class="asset-code-list"><button v-for="asset in row.assets" :key="asset.id" class="asset-code-link compact" type="button" @click="openAsset(asset.id)">{{ asset.code }}</button></div></td>
                  <td class="amount">{{ formatMoney(row.register_gross_cents) }}</td>
                  <td class="amount">{{ formatMoney(row.ledger_gross_cents) }}</td>
                  <td class="amount difference">{{ formatMoney(row.gross_difference_cents) }}</td>
                  <td class="amount">{{ formatMoney(row.register_accumulated_cents) }}</td>
                  <td class="amount">{{ formatMoney(row.ledger_accumulated_cents) }}</td>
                  <td class="amount difference">{{ formatMoney(row.accumulated_difference_cents) }}</td>
                  <td>
                    <div class="reconciliation-control">
                      <span :class="['status-chip', row.reconciled ? 'ok' : 'warning']">{{ row.reconciled ? 'Réconcilié' : 'Écart' }}</span>
                      <button
                        class="icon-button reconciliation-expand-button"
                        type="button"
                        :aria-expanded="expandedReconciliationKey === reconciliationKey(row)"
                        :aria-controls="`reconciliation-detail-${reconciliationKey(row)}`"
                        :aria-label="expandedReconciliationKey === reconciliationKey(row) ? `Réduire le détail du compte ${row.asset_account}` : `Afficher le détail du compte ${row.asset_account}`"
                        @click="toggleReconciliation(row)"
                      ><span aria-hidden="true">⌄</span></button>
                    </div>
                  </td>
                </tr>
                <tr v-if="expandedReconciliationKey === reconciliationKey(row)" :id="`reconciliation-detail-${reconciliationKey(row)}`" class="reconciliation-detail-row">
                  <td colspan="9">
                    <div class="reconciliation-detail">
                      <div class="reconciliation-detail-summary">
                        <span><small>Registre brut</small><strong>{{ formatMoney(row.register_gross_cents) }}</strong></span>
                        <span><small>Grand livre brut</small><strong>{{ formatMoney(row.ledger_gross_cents) }}</strong></span>
                        <span :class="{ warning: row.gross_difference_cents !== 0 }"><small>Écart restant à justifier</small><strong>{{ formatMoney(row.gross_difference_cents) }}</strong></span>
                      </div>
                      <div class="reconciliation-detail-grid">
                        <section>
                          <div class="detail-heading"><div><p class="eyebrow">Source registre</p><h4>Actifs immobilisés</h4></div><span class="status-chip">{{ row.assets.length }} actif(s)</span></div>
                          <div class="table-scroll">
                            <table class="closure-table compact-detail-table">
                              <thead><tr><th>Actif</th><th>Référence</th><th>Acquisition</th><th>Mise en service</th><th>Valeur brute</th></tr></thead>
                              <tbody><tr v-for="asset in row.assets" :key="asset.id"><td><button class="asset-code-link" type="button" @click="openAsset(asset.id)">{{ asset.code }}</button><small>{{ asset.label }}</small></td><td>{{ asset.acquisition_reference }}</td><td>{{ formatDate(asset.acquisition_date) }}</td><td>{{ formatDate(asset.in_service_date) }}</td><td class="amount"><strong>{{ formatMoney(asset.acquisition_value_cents) }}</strong></td></tr></tbody>
                              <tfoot><tr><th colspan="4">Total du registre</th><th class="amount">{{ formatMoney(row.register_gross_cents) }}</th></tr></tfoot>
                            </table>
                          </div>
                        </section>
                        <section>
                          <div class="detail-heading"><div><p class="eyebrow">Source grand livre</p><h4>Mouvements sur {{ row.asset_account }}</h4></div><span class="status-chip">{{ row.ledger_gross_movements.length }} écriture(s)</span></div>
                          <div v-if="row.ledger_gross_movements.length" class="table-scroll">
                            <table class="closure-table compact-detail-table">
                              <thead><tr><th>Date</th><th>Journal / écriture</th><th>Référence</th><th>Libellé</th><th>Débit</th><th>Crédit</th><th>Net</th></tr></thead>
                              <tbody><tr v-for="movement in row.ledger_gross_movements" :key="movement.entry_id"><td>{{ formatDate(movement.date) }}</td><td><strong>{{ movement.journal }}</strong><small>{{ movement.entry_number || `#${movement.entry_id}` }}</small></td><td>{{ movement.reference || '—' }}</td><td>{{ movement.label }}</td><td class="amount">{{ formatMoney(movement.debit_cents) }}</td><td class="amount">{{ formatMoney(movement.credit_cents) }}</td><td class="amount"><strong>{{ formatMoney(movement.net_cents) }}</strong></td></tr></tbody>
                              <tfoot><tr><th colspan="6">Solde du grand livre</th><th class="amount">{{ formatMoney(row.ledger_gross_cents) }}</th></tr></tfoot>
                            </table>
                          </div>
                          <div v-else class="notice warning">Aucun mouvement validé n’a été retrouvé sur ce compte. Vérifiez le compte affecté aux lignes des factures d’acquisition ou passez l’écriture de reclassement nécessaire.</div>
                        </section>
                      </div>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
        <EmptyState v-else title="Aucune réconciliation" description="Aucune immobilisation ne couvre la date de contrôle." />
      </section>

      <section v-else-if="section === 'categories'" class="panel">
        <div class="section-heading">
          <div><h3>Catégories d’amortissement</h3><p>Chaque catégorie fixe la durée utile et les comptes repris par ses immobilisations.</p></div>
          <button class="button primary" type="button" :disabled="!workspace.capabilities.setup" @click="openNewCategory">Nouvelle catégorie d’amortissement</button>
        </div>
        <div v-if="workspace.categories.length" class="table-scroll">
          <table class="closure-table category-table">
            <thead><tr><th>Code</th><th>Catégorie</th><th>Durée utile</th><th>Compte d’actif</th><th>Amortissements cumulés</th><th>Dotation</th><th>Statut</th><th aria-label="Actions"></th></tr></thead>
            <tbody>
              <tr v-for="category in workspace.categories" :key="category.id">
                <td><strong class="category-code">{{ category.code }}</strong></td>
                <td>{{ category.label }}</td>
                <td><strong>{{ category.default_duration_months }}</strong> mois</td>
                <td><span class="account-cell">{{ category.accounts.asset }}</span></td>
                <td><span class="account-cell">{{ category.accounts.accumulated }}</span></td>
                <td><span class="account-cell">{{ category.accounts.expense }}</span></td>
                <td><span :class="['status-chip', category.active ? 'ok' : '']">{{ category.active ? 'Active' : 'Inactive' }}</span></td>
                <td class="table-action-cell">
                  <ActionMenu :label="`Actions pour ${category.code}`">
                    <button type="button" :disabled="!workspace.capabilities.setup" @click="editCategory(category)">Modifier</button>
                  </ActionMenu>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <EmptyState v-else title="Aucune catégorie" description="Ajoutez une catégorie pour commencer le registre." />
      </section>

      <ModalDialog
        ref="categoryEditorDialog"
        :title="categoryDraft.id ? 'Modifier la catégorie' : 'Nouvelle catégorie d’amortissement'"
        description="La durée et les comptes seront hérités par toutes les immobilisations de cette catégorie."
        extra-wide
      >
        <form class="form-grid three" @submit.prevent="saveCategory">
          <label>Code<input v-model="categoryDraft.code" maxlength="20" required></label>
          <label>Libellé<input v-model="categoryDraft.label" required></label>
          <label>Durée utile (mois)<input v-model.number="categoryDraft.default_duration_months" type="number" min="1" max="1200" required></label>
          <label>Compte d’actif<AccountCombobox v-model="categoryDraft.asset_account_id" :options="assetAccounts" aria-label="Compte d’actif" required /></label>
          <label>Amortissements cumulés<AccountCombobox v-model="categoryDraft.accumulated_depreciation_account_id" :options="accumulatedAccounts" aria-label="Amortissements cumulés" required /></label>
          <label>Dotation<AccountCombobox v-model="categoryDraft.depreciation_expense_account_id" :options="expenseAccounts" aria-label="Dotation" required /></label>
          <label>Gain de cession<AccountCombobox v-model="categoryDraft.disposal_gain_account_id" :options="creditAccounts" aria-label="Gain de cession" required /></label>
          <label>Perte de cession<AccountCombobox v-model="categoryDraft.disposal_loss_account_id" :options="expenseAccounts" aria-label="Perte de cession" required /></label>
          <label class="checkbox-field"><input v-model="categoryDraft.active" type="checkbox"> Catégorie active</label>
          <div class="dialog-actions span-all">
            <button class="button secondary" type="button" @click="categoryEditorDialog?.close()">Annuler</button>
            <button class="button primary" :disabled="!workspace.capabilities.setup || assets.saving">{{ categoryDraft.id ? 'Enregistrer les modifications' : 'Créer la catégorie' }}</button>
          </div>
        </form>
      </ModalDialog>

      <ModalDialog
        ref="assetEditorDialog"
        :title="assetDraft.id ? 'Corriger l’immobilisation' : 'Nouvelle immobilisation'"
        description="La durée utile et les comptes sont déterminés par la catégorie choisie."
        extra-wide
      >
        <form class="form-grid three" @submit.prevent="saveAsset">
          <label>Catégorie
            <select v-model.number="assetDraft.category_id" aria-label="Catégorie" required>
              <option :value="0">Choisir…</option>
              <option v-for="category in workspace.categories.filter((item) => item.active)" :key="category.id" :value="category.id">{{ category.code }} — {{ category.label }}</option>
            </select>
            <small v-if="selectedDraftCategory">Durée utile : {{ selectedDraftCategory.default_duration_months }} mois</small>
          </label>
          <label>Code<input v-model="assetDraft.code" maxlength="30" required></label>
          <label>Libellé<input v-model="assetDraft.label" maxlength="255" required></label>
          <label>Référence de pièce<input v-model="assetDraft.acquisition_reference" maxlength="190" required></label>
          <label>Facture fournisseur
            <select v-model="assetDraft.acquisition_document_id">
              <option :value="null">Référence externe uniquement</option>
              <option v-for="document in workspace.catalog.acquisition_documents" :key="document.id" :value="document.id">{{ formatDate(document.date) }} · {{ document.number || `#${document.id}` }} · {{ formatMoney(document.gross_cents) }}</option>
            </select>
          </label>
          <label>Date d’acquisition<input v-model="assetDraft.acquisition_date" type="date" required></label>
          <label>Mise en service<input v-model="assetDraft.in_service_date" type="date" required></label>
          <label>Valeur d’acquisition<input v-model="assetDraft.acquisition_value" inputmode="decimal" placeholder="0.00" required></label>
          <label>Valeur résiduelle<input v-model="assetDraft.residual_value" inputmode="decimal" required></label>
          <div class="notice info span-all">L’échéancier sera créé par trimestre selon la durée de la catégorie.</div>
          <div class="dialog-actions span-all">
            <button class="button secondary" type="button" @click="assetEditorDialog?.close()">Annuler</button>
            <button class="button primary" :disabled="!workspace.capabilities.setup || assets.saving">{{ assetDraft.id ? 'Enregistrer la correction' : 'Créer la fiche et l’échéancier' }}</button>
          </div>
        </form>
      </ModalDialog>

      <ModalDialog
        ref="assetViewerDialog"
        :title="selected ? `Fiche ${selected.code}` : 'Fiche d’immobilisation'"
        extra-wide
      >
        <template v-if="selected">
          <div class="asset-sheet-heading">
            <div><p class="eyebrow">{{ selected.category_code }} — {{ selected.category }}</p><h3>{{ selected.code }} — {{ selected.label }}</h3></div>
            <span :class="['status-chip', selected.status === 'actif' ? 'ok' : '']">{{ statusLabel(selected.status) }}</span>
          </div>
          <dl class="asset-detail-grid">
            <div><dt>Référence</dt><dd>{{ selected.acquisition_reference }}</dd></div>
            <div><dt>Acquisition</dt><dd>{{ formatDate(selected.acquisition_date) }}</dd></div>
            <div><dt>Mise en service</dt><dd>{{ formatDate(selected.in_service_date) }}</dd></div>
            <div><dt>Durée utile</dt><dd>{{ selected.duration_months }} mois</dd></div>
            <div><dt>Valeur brute</dt><dd>{{ formatMoney(selected.acquisition_value_cents) }}</dd></div>
            <div><dt>Valeur résiduelle</dt><dd>{{ formatMoney(selected.residual_value_cents) }}</dd></div>
          </dl>
          <div class="metric-strip">
            <span><small>Base amortissable</small><strong>{{ formatMoney(selected.totals.depreciable_base_cents) }}</strong></span>
            <span><small>Dotations comptabilisées</small><strong>{{ formatMoney(selected.totals.posted_depreciation_cents) }}</strong></span>
            <span><small>Base restante</small><strong>{{ formatMoney(selected.totals.remaining_depreciable_cents) }}</strong></span>
            <span><small>Valeur nette comptable</small><strong>{{ formatMoney(selected.totals.net_book_value_cents) }}</strong></span>
          </div>
          <div class="asset-viewer-actions">
            <button v-if="selected.status === 'actif'" class="button secondary small" type="button" :disabled="!workspace.capabilities.setup" @click="editSelectedAsset">Corriger la fiche</button>
          </div>
          <section class="asset-sheet-section">
            <h3>Échéancier</h3>
            <div class="table-scroll">
              <table class="closure-table">
                <thead><tr><th>Période</th><th>Date comptable</th><th>Dotation</th><th>Statut</th></tr></thead>
                <tbody><tr v-for="row in selected.schedule" :key="row.id"><td>{{ formatDate(row.start_date) }} – {{ formatDate(row.end_date) }}</td><td>{{ formatDate(row.posting_date) }}</td><td class="amount">{{ formatMoney(row.amount_cents) }}</td><td><span class="status-chip">{{ statusLabel(row.status) }}</span></td></tr></tbody>
              </table>
            </div>
          </section>
          <section class="asset-sheet-section">
            <div class="section-heading"><h3>Sortie</h3><button v-if="selected.status !== 'actif'" class="button danger small" :disabled="!workspace.capabilities.reverse" @click="reverseDisposal">Contre-passer la sortie</button></div>
            <form v-if="selected.status === 'actif'" class="form-grid three" @submit.prevent="disposeAsset">
              <label>Opération<select v-model="disposal.type"><option value="cession">Cession</option><option value="mise_au_rebut">Mise au rebut</option></select></label>
              <label>Date<input v-model="disposal.date" type="date" :min="selected.in_service_date" required></label>
              <label v-if="disposal.type === 'cession'">Produit de cession<input v-model="disposal.proceeds" inputmode="decimal" required></label>
              <label v-if="disposal.type === 'cession'">Compte encaissé / à recevoir<AccountCombobox v-model="disposal.proceeds_account_id" :options="debitAccounts" :empty-value="null" placeholder="Choisir si produit non nul…" /></label>
              <button class="button danger" :disabled="!workspace.capabilities.post || !journalId">Comptabiliser la sortie</button>
            </form>
            <div v-if="selected.exits.length" class="table-scroll">
              <table class="closure-table"><thead><tr><th>Date</th><th>Type</th><th>VNC</th><th>Produit</th><th>Résultat</th><th>Statut</th></tr></thead><tbody><tr v-for="item in selected.exits" :key="item.id"><td>{{ formatDate(item.date) }}</td><td>{{ statusLabel(item.type) }}</td><td class="amount">{{ formatMoney(item.net_cents) }}</td><td class="amount">{{ formatMoney(item.proceeds_cents) }}</td><td class="amount">{{ formatMoney(item.result_cents) }}</td><td><span class="status-chip">{{ statusLabel(item.status) }}</span></td></tr></tbody></table>
            </div>
          </section>
        </template>
      </ModalDialog>
    </template>
  </section>
</template>

<style scoped>
.assets-hero .section-heading { align-items: flex-start; }
.assets-hero .section-heading p:last-child { max-width: 74ch; margin-bottom: 0; }
.asset-register-table td:first-child small,
.category-table td small,
.closure-table td > small,
.closure-table td strong + small { display: block; margin-top: 0.18rem; color: var(--muted); }
.asset-code-link { padding: 0; border: 0; color: var(--accent); background: transparent; font: inherit; font-weight: 750; cursor: pointer; text-align: left; }
.asset-code-link:hover { text-decoration: underline; }
.asset-code-link.compact { padding: 0.18rem 0.42rem; border: 1px solid var(--border); border-radius: 999px; background: var(--surface-soft); font-size: 0.78rem; text-decoration: none; }
.asset-code-list { display: flex; flex-wrap: wrap; gap: 0.3rem; min-width: 8rem; }
.category-badge, .category-code { color: var(--accent); font-weight: 800; }
.category-badge { display: inline-flex; padding: 0.15rem 0.45rem; border-radius: 999px; background: color-mix(in srgb, var(--accent) 10%, var(--surface)); }
.account-cell { display: block; min-width: 11rem; font-size: 0.84rem; }
.table-action-cell { width: 3rem; text-align: right; }
.account-group-heading, .asset-sheet-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
.account-group-heading h3, .asset-sheet-heading h3 { margin: 0; }
.account-group-heading .eyebrow, .asset-sheet-heading .eyebrow { margin-bottom: 0.25rem; }
.account-schedule-card .account-group-heading { align-items: center; margin-bottom: 0; }
.schedule-card-actions { display: flex; align-items: center; gap: 0.75rem; }
.schedule-card-summary { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 0.4rem; }
.schedule-expand-button { flex: 0 0 auto; color: var(--accent); border: 1px solid var(--border); background: var(--surface-soft); }
.schedule-expand-button:hover { color: white; border-color: var(--accent); background: var(--accent); }
.schedule-inline-panel { margin-top: 1rem; padding-top: 0.4rem; border-top: 1px solid var(--border); }
.schedule-inline-tabs { position: static; margin-top: 0; border-bottom: 1px solid var(--border); background: var(--surface); backdrop-filter: none; }
.schedule-inline-tabs button { gap: 0.45rem; }
.schedule-inline-tabs .tab-count { display: inline-flex; align-items: center; justify-content: center; min-width: 1.45rem; min-height: 1.45rem; padding: 0 0.4rem; border-radius: 999px; color: var(--ink); background: var(--surface-soft); font-size: 0.75rem; font-weight: 800; }
.schedule-inline-tabs button.active .tab-count { color: white; background: var(--primary); }
.schedule-inline-table { min-width: 64rem; }
.schedule-toolbar { position: sticky; top: 0.5rem; z-index: 2; }
.asset-detail-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.75rem; margin: 0 0 1rem; }
.asset-detail-grid div { padding: 0.8rem; border: 1px solid var(--border); border-radius: 0.65rem; background: var(--surface-soft); }
.asset-detail-grid dt { color: var(--muted); font-size: 0.78rem; }
.asset-detail-grid dd { margin: 0.2rem 0 0; font-weight: 700; }
.asset-viewer-actions { display: flex; justify-content: flex-end; margin-top: 0.8rem; }
.asset-sheet-section { margin-top: 1.35rem; padding-top: 1.1rem; border-top: 1px solid var(--border); }
.difference { font-weight: 750; }
.reconciliation-control { display: flex; align-items: center; justify-content: space-between; gap: 0.45rem; min-width: 7.5rem; }
.reconciliation-expand-button { flex: 0 0 auto; border: 1px solid var(--border); background: var(--surface-soft); }
.reconciliation-expand-button span { display: block; font-size: 1.35rem; line-height: 1; transition: transform 160ms ease; }
.reconciliation-expand-button[aria-expanded="true"] span { transform: rotate(180deg); }
.reconciliation-detail-row > td { padding: 0; background: color-mix(in srgb, var(--accent) 3%, var(--surface)); }
.reconciliation-detail { padding: 1rem; border-top: 2px solid color-mix(in srgb, var(--accent) 30%, var(--border)); border-bottom: 1px solid var(--border); }
.reconciliation-detail-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.65rem; margin-bottom: 1rem; }
.reconciliation-detail-summary span { display: grid; gap: 0.2rem; padding: 0.75rem; border: 1px solid var(--border); border-radius: 0.65rem; background: var(--surface); }
.reconciliation-detail-summary span.warning { color: var(--danger); border-color: color-mix(in srgb, var(--danger) 35%, var(--border)); background: color-mix(in srgb, var(--danger) 5%, var(--surface)); }
.reconciliation-detail-summary small { color: var(--muted); }
.reconciliation-detail-grid { display: grid; grid-template-columns: minmax(30rem, 0.9fr) minmax(42rem, 1.1fr); gap: 1rem; align-items: start; }
.reconciliation-detail-grid > section { min-width: 0; padding: 0.85rem; border: 1px solid var(--border); border-radius: 0.65rem; background: var(--surface); }
.detail-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem; margin-bottom: 0.75rem; }
.detail-heading h4, .detail-heading .eyebrow { margin: 0; }
.detail-heading .eyebrow { margin-bottom: 0.2rem; }
.compact-detail-table { min-width: 100%; font-size: 0.82rem; }
.compact-detail-table th, .compact-detail-table td { padding: 0.55rem; }
.compact-detail-table tfoot th { border-top: 2px solid var(--border); }
@media (max-width: 820px) {
  .asset-detail-grid { grid-template-columns: 1fr; }
  .schedule-toolbar { position: static; }
  .account-schedule-card .account-group-heading,
  .schedule-card-actions { align-items: stretch; flex-direction: column; }
  .schedule-card-summary { justify-content: flex-start; }
  .schedule-expand-button { align-self: flex-end; }
  .reconciliation-detail-summary,
  .reconciliation-detail-grid { grid-template-columns: 1fr; }
}
</style>
