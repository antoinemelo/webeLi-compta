<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import AccountCombobox from '@/components/ui/AccountCombobox.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import SkeletonBlock from '@/components/ui/SkeletonBlock.vue';
import type { AssetCategory, AssetRecord } from '@/api/contracts';
import { useToastFeedback } from '@/composables/toastFeedback';
import { useAssetStore } from '@/stores/assets';
import { useContextStore } from '@/stores/context';

const props = defineProps<{ exerciseId: number; currency: string }>();
const assets = useAssetStore();
useToastFeedback(assets);
const context = useContextStore();
const section = ref<'register' | 'schedule' | 'reconciliation' | 'categories'>('register');
const selectedAssetId = ref(0);
const journalId = ref(0);

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
  residual_value: '0.00',
  duration_months: 60,
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
  },
  { deep: true }
);

watch(
  () => assetDraft.category_id,
  (id) => {
    if (assetDraft.id > 0) return;
    const category = workspace.value?.categories.find((item) => item.id === id);
    if (category) assetDraft.duration_months = category.default_duration_months;
  }
);

async function load(assetId = selectedAssetId.value || undefined): Promise<void> {
  await assets.load(props.exerciseId, assetId);
}

async function selectAsset(id: number): Promise<void> {
  selectedAssetId.value = id;
  await load(id);
  section.value = 'schedule';
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
}

async function saveCategory(): Promise<void> {
  try {
    await assets.mutate(
      '/accounting/assets/categories',
      { ...categoryDraft },
      categoryDraft.id ? 'Catégorie mise à jour.' : 'Catégorie créée.'
    );
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
    residual_value: '0.00',
    duration_months:
      workspace.value?.categories.find((item) => item.active)
        ?.default_duration_months || 60,
    note: ''
  });
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
    duration_months: asset.duration_months,
    note: asset.note
  });
  section.value = 'register';
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
      duration_months: assetDraft.duration_months,
      note: assetDraft.note
    }, assetDraft.id ? 'Fiche corrigée et plan recalculé.' : 'Immobilisation créée.');
    resetAsset();
    await load();
  } catch {
    // Le store affiche l’erreur structurée.
  }
}

async function postSchedule(scheduleId: number): Promise<void> {
  try {
    await assets.mutate('/accounting/assets/depreciations', {
      schedule_id: scheduleId,
      exercise_id: props.exerciseId,
      journal_id: journalId.value
    }, 'Dotation comptabilisée dans le grand livre.');
    await load();
  } catch {
    // Le store affiche l’erreur structurée.
  }
}

async function reverseSchedule(scheduleId: number, date: string): Promise<void> {
  if (!window.confirm('Contre-passer cette dotation validée ?')) return;
  try {
    await assets.mutate('/accounting/assets/depreciations/reverse', {
      schedule_id: scheduleId,
      date
    }, 'Dotation contre-passée ; l’échéance redevient disponible.');
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
      <section class="panel">
        <div class="section-heading">
          <div>
            <p class="eyebrow">Registre relié au grand livre</p>
            <h2>Immobilisations et amortissements</h2>
          </div>
          <span class="status-chip">{{ workspace.pagination.total }} actif(s)</span>
        </div>
        <p>{{ workspace.definitions.method }}</p>
        <nav class="button-row" aria-label="Vues des immobilisations">
          <button class="button small" :class="{ primary: section === 'register' }" @click="section = 'register'">Registre</button>
          <button class="button small" :class="{ primary: section === 'schedule' }" @click="section = 'schedule'">Échéancier</button>
          <button class="button small" :class="{ primary: section === 'reconciliation' }" @click="section = 'reconciliation'">Réconciliation</button>
          <button class="button small" :class="{ primary: section === 'categories' }" @click="section = 'categories'">Catégories</button>
        </nav>
      </section>

      <template v-if="section === 'register'">
        <section class="panel">
          <div class="section-heading">
            <h3>{{ assetDraft.id ? 'Corriger la fiche' : 'Nouvelle immobilisation' }}</h3>
            <button v-if="assetDraft.id" class="button secondary small" type="button" @click="resetAsset">Annuler la correction</button>
          </div>
          <p class="notice warning">{{ workspace.definitions.correction }}</p>
          <EmptyState
            v-if="!workspace.categories.some((item) => item.active)"
            title="Créez d’abord une catégorie"
            description="La catégorie fixe les cinq comptes et la durée proposée."
          />
          <form v-else class="form-grid three" @submit.prevent="saveAsset">
            <label>Catégorie
              <select v-model.number="assetDraft.category_id" aria-label="Catégorie" required>
                <option :value="0">Choisir…</option>
                <option v-for="category in workspace.categories.filter((item) => item.active)" :key="category.id" :value="category.id">{{ category.code }} — {{ category.label }}</option>
              </select>
            </label>
            <label>Code<input v-model="assetDraft.code" maxlength="30" required></label>
            <label>Libellé<input v-model="assetDraft.label" maxlength="255" required></label>
            <label>Référence de pièce<input v-model="assetDraft.acquisition_reference" maxlength="190" required></label>
            <label>Facture fournisseur
              <select v-model="assetDraft.acquisition_document_id">
                <option :value="null">Référence externe uniquement</option>
                <option v-for="document in workspace.catalog.acquisition_documents" :key="document.id" :value="document.id">{{ document.date }} · {{ document.number || `#${document.id}` }} · {{ formatMoney(document.gross_cents) }}</option>
              </select>
            </label>
            <label>Date d’acquisition<input v-model="assetDraft.acquisition_date" type="date" required></label>
            <label>Mise en service<input v-model="assetDraft.in_service_date" type="date" required></label>
            <label>Valeur d’acquisition<input v-model="assetDraft.acquisition_value" inputmode="decimal" placeholder="0.00" required></label>
            <label>Valeur résiduelle<input v-model="assetDraft.residual_value" inputmode="decimal" required></label>
            <label>Durée utile (mois)<input v-model.number="assetDraft.duration_months" type="number" min="1" max="1200" required></label>
            <label class="span-all">Note<input v-model="assetDraft.note" maxlength="1000"></label>
            <button class="button primary" :disabled="!workspace.capabilities.setup || assets.saving">{{ assetDraft.id ? 'Enregistrer la correction' : 'Créer la fiche et le plan' }}</button>
          </form>
        </section>

        <section class="panel">
          <h3>Registre</h3>
          <div class="table-scroll">
            <table>
              <thead><tr><th>Actif</th><th>Catégorie</th><th>Mise en service</th><th>Valeur</th><th>Amorti</th><th>VNC</th><th>Statut</th><th>Actions</th></tr></thead>
              <tbody>
                <tr v-for="asset in workspace.assets" :key="asset.id">
                  <td><strong>{{ asset.code }}</strong><br><small>{{ asset.label }}</small></td>
                  <td>{{ asset.category_code }}</td>
                  <td>{{ asset.in_service_date }}</td>
                  <td>{{ formatMoney(asset.acquisition_value_cents) }}</td>
                  <td>{{ formatMoney(asset.posted_depreciation_cents) }}</td>
                  <td>{{ formatMoney(asset.net_book_value_cents) }}</td>
                  <td><span class="status-chip">{{ asset.status.replaceAll('_', ' ') }}</span></td>
                  <td class="button-row">
                    <button class="button small" @click="selectAsset(asset.id)">Ouvrir</button>
                    <button v-if="asset.status === 'actif'" class="button secondary small" :disabled="!workspace.capabilities.setup" @click="editAsset(asset)">Corriger</button>
                  </td>
                </tr>
                <tr v-if="!workspace.assets.length"><td colspan="8">Aucune immobilisation.</td></tr>
              </tbody>
            </table>
          </div>
        </section>
      </template>

      <template v-else-if="section === 'schedule'">
        <EmptyState v-if="!selected" title="Aucun actif sélectionné" description="Ouvrez une fiche depuis le registre." />
        <template v-else>
          <section class="panel">
            <div class="section-heading">
              <div><p class="eyebrow">{{ selected.category }}</p><h3>{{ selected.code }} — {{ selected.label }}</h3></div>
              <span class="status-chip">{{ selected.status.replaceAll('_', ' ') }}</span>
            </div>
            <div class="metric-strip">
              <span><small>Base amortissable</small><strong>{{ formatMoney(selected.totals.depreciable_base_cents) }}</strong></span>
              <span><small>Dotations comptabilisées</small><strong>{{ formatMoney(selected.totals.posted_depreciation_cents) }}</strong></span>
              <span><small>Base restante</small><strong>{{ formatMoney(selected.totals.remaining_depreciable_cents) }}</strong></span>
              <span><small>Valeur nette comptable</small><strong>{{ formatMoney(selected.totals.net_book_value_cents) }}</strong></span>
            </div>
            <label class="compact-control">Journal
              <select v-model.number="journalId">
                <option v-for="journal in workspace.catalog.journals" :key="journal.id" :value="journal.id">{{ journal.code }} — {{ journal.label }}</option>
              </select>
            </label>
          </section>
          <section class="panel">
            <h3>Plan prévisionnel</h3>
            <div class="table-scroll">
              <table>
                <thead><tr><th>Période</th><th>Jours</th><th>Date comptable</th><th>Dotation</th><th>Statut</th><th>Action</th></tr></thead>
                <tbody>
                  <tr v-for="row in selected.schedule" :key="row.id">
                    <td>{{ row.start_date }} – {{ row.end_date }}</td>
                    <td>{{ row.days }}</td>
                    <td>{{ row.posting_date }}</td>
                    <td>{{ formatMoney(row.amount_cents) }}</td>
                    <td><span class="status-chip">{{ row.status.replaceAll('_', ' ') }}</span></td>
                    <td>
                      <button v-if="row.status === 'planifiee' && row.amount_cents > 0" class="button small" :disabled="!workspace.capabilities.post || !journalId || row.posting_date < workspace.exercise.start_date || row.posting_date > workspace.exercise.end_date" @click="postSchedule(row.id)">Comptabiliser</button>
                      <button v-else-if="row.status === 'comptabilisee'" class="button danger small" :disabled="!workspace.capabilities.reverse" @click="reverseSchedule(row.id, row.posting_date)">Contre-passer</button>
                      <span v-else>—</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
          <section class="panel">
            <div class="section-heading"><h3>Sortie</h3><button v-if="selected.status !== 'actif'" class="button danger small" :disabled="!workspace.capabilities.reverse" @click="reverseDisposal">Contre-passer la sortie</button></div>
            <form v-if="selected.status === 'actif'" class="form-grid three" @submit.prevent="disposeAsset">
              <label>Opération<select v-model="disposal.type"><option value="cession">Cession</option><option value="mise_au_rebut">Mise au rebut</option></select></label>
              <label>Date<input v-model="disposal.date" type="date" :min="selected.in_service_date" required></label>
              <label v-if="disposal.type === 'cession'">Produit de cession<input v-model="disposal.proceeds" inputmode="decimal" required></label>
              <label v-if="disposal.type === 'cession'">Compte encaissé / à recevoir<AccountCombobox v-model="disposal.proceeds_account_id" :options="debitAccounts" :empty-value="null" placeholder="Choisir si produit non nul…" /></label>
              <button class="button danger" :disabled="!workspace.capabilities.post || !journalId">Comptabiliser la sortie</button>
            </form>
            <div v-if="selected.exits.length" class="table-scroll">
              <table><thead><tr><th>Date</th><th>Type</th><th>VNC</th><th>Produit</th><th>Résultat</th><th>Statut</th></tr></thead><tbody><tr v-for="item in selected.exits" :key="item.id"><td>{{ item.date }}</td><td>{{ item.type.replaceAll('_', ' ') }}</td><td>{{ formatMoney(item.net_cents) }}</td><td>{{ formatMoney(item.proceeds_cents) }}</td><td>{{ formatMoney(item.result_cents) }}</td><td><span class="status-chip">{{ item.status.replaceAll('_', ' ') }}</span></td></tr></tbody></table>
            </div>
          </section>
        </template>
      </template>

      <section v-else-if="section === 'reconciliation'" class="panel">
        <div class="section-heading"><div><h3>Réconciliation au grand livre</h3><p>{{ workspace.definitions.reconciliation }}</p></div><span>{{ workspace.exercise.end_date }}</span></div>
        <div class="table-scroll">
          <table>
            <thead><tr><th>Comptes</th><th>Registre brut</th><th>Grand livre brut</th><th>Écart brut</th><th>Registre amorti</th><th>Grand livre amorti</th><th>Écart amorti</th><th>Contrôle</th></tr></thead>
            <tbody>
              <tr v-for="row in workspace.reconciliation" :key="`${row.asset_account_id}-${row.accumulated_account_id}`">
                <td>{{ row.asset_account }}<br><small>{{ row.accumulated_account }}</small></td>
                <td>{{ formatMoney(row.register_gross_cents) }}</td><td>{{ formatMoney(row.ledger_gross_cents) }}</td><td>{{ formatMoney(row.gross_difference_cents) }}</td>
                <td>{{ formatMoney(row.register_accumulated_cents) }}</td><td>{{ formatMoney(row.ledger_accumulated_cents) }}</td><td>{{ formatMoney(row.accumulated_difference_cents) }}</td>
                <td><span :class="['status-chip', row.reconciled ? 'ok' : 'warning']">{{ row.reconciled ? 'Réconcilié' : 'Écart' }}</span></td>
              </tr>
              <tr v-if="!workspace.reconciliation.length"><td colspan="8">Aucune immobilisation à réconcilier.</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <template v-else-if="section === 'categories'">
        <section class="panel">
          <div class="section-heading"><h3>{{ categoryDraft.id ? 'Modifier la catégorie' : 'Nouvelle catégorie' }}</h3><button v-if="categoryDraft.id" class="button secondary small" @click="resetCategory">Annuler</button></div>
          <form class="form-grid three" @submit.prevent="saveCategory">
            <label>Code<input v-model="categoryDraft.code" maxlength="20" required></label>
            <label>Libellé<input v-model="categoryDraft.label" required></label>
            <label>Durée proposée (mois)<input v-model.number="categoryDraft.default_duration_months" type="number" min="1" max="1200" required></label>
            <label>Compte d’actif<AccountCombobox v-model="categoryDraft.asset_account_id" :options="assetAccounts" aria-label="Compte d’actif" required /></label>
            <label>Amortissements cumulés<AccountCombobox v-model="categoryDraft.accumulated_depreciation_account_id" :options="accumulatedAccounts" aria-label="Amortissements cumulés" required /></label>
            <label>Dotation<AccountCombobox v-model="categoryDraft.depreciation_expense_account_id" :options="expenseAccounts" aria-label="Dotation" required /></label>
            <label>Gain de cession<AccountCombobox v-model="categoryDraft.disposal_gain_account_id" :options="creditAccounts" aria-label="Gain de cession" required /></label>
            <label>Perte de cession<AccountCombobox v-model="categoryDraft.disposal_loss_account_id" :options="expenseAccounts" aria-label="Perte de cession" required /></label>
            <label class="checkbox-field"><input v-model="categoryDraft.active" type="checkbox"> Active</label>
            <button class="button primary" :disabled="!workspace.capabilities.setup || assets.saving">Enregistrer</button>
          </form>
        </section>
        <section class="panel">
          <h3>Catégories</h3>
          <div class="table-scroll"><table><thead><tr><th>Catégorie</th><th>Durée</th><th>Comptes</th><th>Statut</th><th></th></tr></thead><tbody><tr v-for="category in workspace.categories" :key="category.id"><td>{{ category.code }} — {{ category.label }}</td><td>{{ category.default_duration_months }} mois</td><td><small>{{ category.accounts.asset }}<br>{{ category.accounts.accumulated }}<br>{{ category.accounts.expense }}</small></td><td><span class="status-chip">{{ category.active ? 'active' : 'inactive' }}</span></td><td><button class="button small" :disabled="!workspace.capabilities.setup" @click="editCategory(category)">Modifier</button></td></tr><tr v-if="!workspace.categories.length"><td colspan="5">Aucune catégorie.</td></tr></tbody></table></div>
        </section>
      </template>
    </template>
  </section>
</template>
