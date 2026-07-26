<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import CompactTabs from '@/components/ui/CompactTabs.vue';
import DataTable from '@/components/ui/DataTable.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import FormField from '@/components/ui/FormField.vue';
import SkeletonBlock from '@/components/ui/SkeletonBlock.vue';
import { subNavigation } from '@/router/navigation';
import { useContextStore } from '@/stores/context';
import { useExpensesStore } from '@/stores/expenses';
import { useNotificationStore } from '@/stores/notifications';
import type { ExpenseItem } from '@/api/contracts';

const route = useRoute();
const context = useContextStore();
const store = useExpensesStore();
const notifications = useNotificationStore();
const activeTab = computed(() => String(route.params.tab || 'use'));
const workspace = computed(() => store.workspace);
const today = new Date().toISOString().slice(0, 10);
const selectedId = ref(0);
const showExpenseForm = ref(false);
const showRecurrenceForm = ref(false);
const attachment = ref<{ name: string; content_base64: string } | null>(null);
const selected = computed<ExpenseItem | null>(
  () => workspace.value?.expenses.find((item) => item.id === selectedId.value) ?? null
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

onMounted(load);
watch(
  () => context.context?.selection?.dossier.id,
  () => {
    selectedId.value = 0;
    store.clear();
    void load();
  }
);

async function load(): Promise<void> {
  if (context.context?.selection) await store.load();
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
    actif: 'Actif',
    pause: 'En pause',
    termine: 'Terminé'
  }[status] || status;
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
    showExpenseForm.value = false;
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
    showRecurrenceForm.value = false;
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
        <p class="eyebrow">Trésorerie · fournisseurs</p>
        <h1>Liquidités</h1>
        <p>Dépenses, validation et récurrences reliées au grand livre.</p>
      </div>
    </header>

    <CompactTabs :items="subNavigation.liquidity" label="Navigation des liquidités" />
    <ErrorSummary v-if="store.error" title="Impossible de charger les dépenses" :message="store.error" />
    <SkeletonBlock v-if="store.loading && !workspace" :lines="7" />

    <template v-else-if="workspace && activeTab === 'use'">
      <div class="toolbar">
        <div>
          <h2>Utilisation des liquidités</h2>
          <p>La création reste toujours en brouillon. Paiement et allocation sont séparés.</p>
        </div>
        <div class="button-row">
          <button
            v-if="workspace.capabilities.manage"
            class="button secondary"
            type="button"
            @click="showRecurrenceForm = !showRecurrenceForm"
          >Nouvelle récurrence</button>
          <button
            v-if="workspace.capabilities.manage"
            class="button primary"
            type="button"
            @click="showExpenseForm = !showExpenseForm"
          >Nouvelle dépense</button>
        </div>
      </div>

      <form v-if="showExpenseForm" class="editor-card" @submit.prevent="saveExpense">
        <h3>Nouvelle dépense ponctuelle</h3>
        <div class="form-grid">
          <FormField id="expense-supplier" label="Fournisseur">
            <template #default="{ describedBy }">
              <select id="expense-supplier" v-model.number="expense.contact_id" :aria-describedby="describedBy" required>
                <option :value="0" disabled>Sélectionner</option>
                <option v-for="item in workspace.catalog.suppliers" :key="item.id" :value="item.id">{{ item.label }}</option>
              </select>
            </template>
          </FormField>
          <FormField id="expense-number" label="Numéro fournisseur">
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
          <FormField id="expense-collective" label="Compte collectif fournisseur">
            <template #default="{ describedBy }">
              <select id="expense-collective" v-model.number="expense.collective_account_id" :aria-describedby="describedBy" required>
                <option :value="0" disabled>Sélectionner</option>
                <option v-for="item in workspace.catalog.accounts" :key="item.id" :value="item.id">{{ item.number }} · {{ item.label }}</option>
              </select>
            </template>
          </FormField>
          <FormField id="expense-proof" label="Justificatif" hint="PDF, JPEG, PNG ou WebP, 10 Mo maximum.">
            <template #default="{ describedBy }"><input id="expense-proof" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" :aria-describedby="describedBy" required @change="fileSelected"></template>
          </FormField>
        </div>
        <fieldset v-for="(line, index) in expense.lines" :key="index" class="line-editor">
          <legend>Ligne {{ index + 1 }}</legend>
          <input v-model="line.libelle" aria-label="Libellé" placeholder="Libellé" required>
          <input v-model="line.prix" aria-label="Montant" inputmode="decimal" placeholder="Montant" required>
          <select v-model.number="line.compte_id" aria-label="Compte de charge" required>
            <option :value="0" disabled>Compte</option>
            <option v-for="item in workspace.catalog.accounts" :key="item.id" :value="item.id">{{ item.number }} · {{ item.label }}</option>
          </select>
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
          <button type="button" class="button ghost" @click="expense.lines.push(newLine())">Ajouter une ligne</button>
          <button class="button primary" :disabled="store.saving">Enregistrer le brouillon</button>
        </div>
      </form>

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

      <article v-if="selected" class="detail-card">
        <div>
          <p class="eyebrow">Détail</p>
          <h3>{{ selected.number || `Brouillon #${selected.id}` }}</h3>
          <p>{{ selected.supplier }} · {{ selected.external_number }}</p>
        </div>
        <dl class="detail-grid">
          <div><dt>Net</dt><dd>{{ money(selected.net_cents) }}</dd></div>
          <div><dt>TVA</dt><dd>{{ money(selected.vat_cents) }}</dd></div>
          <div><dt>Brut</dt><dd>{{ money(selected.gross_cents) }}</dd></div>
          <div><dt>Justificatif</dt><dd>{{ selected.attachment?.name || 'Absent' }}</dd></div>
        </dl>
        <ul>
          <li v-for="line in selected.lines" :key="line.id">
            {{ line.label }} — {{ money(line.net_cents) }} + {{ money(line.vat_cents) }} TVA
          </li>
        </ul>
      </article>

      <section class="recurrence-section">
        <div class="toolbar">
          <div><h2>Dépenses récurrentes</h2><p>Chaque échéance crée uniquement un brouillon à compléter et approuver.</p></div>
          <button v-if="workspace.capabilities.manage" class="button secondary" type="button" @click="generateDue">Générer jusqu’à aujourd’hui</button>
        </div>
        <form v-if="showRecurrenceForm" class="editor-card" @submit.prevent="saveRecurrence">
          <div class="form-grid">
            <FormField id="rec-label" label="Nom du modèle"><template #default="{ describedBy }"><input id="rec-label" v-model="recurrence.label" :aria-describedby="describedBy" required></template></FormField>
            <FormField id="rec-supplier" label="Fournisseur"><template #default="{ describedBy }"><select id="rec-supplier" v-model.number="recurrence.contact_id" :aria-describedby="describedBy" required><option :value="0" disabled>Sélectionner</option><option v-for="item in workspace.catalog.suppliers" :key="item.id" :value="item.id">{{ item.label }}</option></select></template></FormField>
            <FormField id="rec-frequency" label="Périodicité"><template #default="{ describedBy }"><select id="rec-frequency" v-model="recurrence.frequency" :aria-describedby="describedBy"><option value="hebdomadaire">Hebdomadaire</option><option value="mensuelle">Mensuelle</option><option value="trimestrielle">Trimestrielle</option><option value="annuelle">Annuelle</option></select></template></FormField>
            <FormField id="rec-next" label="Prochaine échéance"><template #default="{ describedBy }"><input id="rec-next" v-model="recurrence.next_date" type="date" :aria-describedby="describedBy" required></template></FormField>
            <FormField id="rec-end" label="Fin facultative"><template #default="{ describedBy }"><input id="rec-end" v-model="recurrence.end_date" type="date" :aria-describedby="describedBy"></template></FormField>
            <FormField id="rec-prefix" label="Préfixe fournisseur"><template #default="{ describedBy }"><input id="rec-prefix" v-model="recurrence.external_prefix" :aria-describedby="describedBy" required></template></FormField>
            <FormField id="rec-collective" label="Compte collectif"><template #default="{ describedBy }"><select id="rec-collective" v-model.number="recurrence.collective_account_id" :aria-describedby="describedBy" required><option :value="0" disabled>Sélectionner</option><option v-for="item in workspace.catalog.accounts" :key="item.id" :value="item.id">{{ item.number }} · {{ item.label }}</option></select></template></FormField>
          </div>
          <fieldset v-for="(line, index) in recurrence.lines" :key="index" class="line-editor">
            <legend>Ligne {{ index + 1 }}</legend>
            <input v-model="line.libelle" aria-label="Libellé récurrent" placeholder="Libellé" required>
            <input v-model="line.prix" aria-label="Montant récurrent" inputmode="decimal" placeholder="Montant" required>
            <select v-model.number="line.compte_id" aria-label="Compte récurrent" required><option :value="0" disabled>Compte</option><option v-for="item in workspace.catalog.accounts" :key="item.id" :value="item.id">{{ item.number }} · {{ item.label }}</option></select>
            <select v-model.number="line.code_tva_id" aria-label="TVA récurrente" required><option :value="0" disabled>TVA</option><option v-for="item in workspace.catalog.vat_codes" :key="item.id" :value="item.id">{{ item.code }} · {{ item.label }}</option></select>
            <select v-model="line.mode_saisie" aria-label="Mode récurrent"><option value="net">Net</option><option value="brut">Brut</option></select>
          </fieldset>
          <div class="button-row"><button type="button" class="button ghost" @click="recurrence.lines.push(newLine())">Ajouter une ligne</button><button class="button primary" :disabled="store.saving">Enregistrer la récurrence</button></div>
        </form>
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

    <EmptyState
      v-else-if="workspace"
      title="Ce parcours arrive au lot 07"
      description="Le rapprochement bancaire, le lettrage et l’émission de paiements restent séparés des dépenses."
    />
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
.line-editor { display: grid; grid-template-columns: 1.5fr .7fr 1.2fr 1fr .8fr auto; gap: .6rem; margin: 1rem 0; padding: .9rem; border: 1px solid var(--border); border-radius: .5rem; }
.line-editor input, .line-editor select, .form-grid input, .form-grid select { width: 100%; min-height: 2.7rem; }
.table-link { color: var(--ink); background: none; border: 0; text-decoration: underline; cursor: pointer; }
.table-actions { justify-content: flex-start; }
.detail-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .8rem; }
.detail-grid dt { color: var(--muted); font-size: .8rem; }
.detail-grid dd { margin: .2rem 0 0; font-weight: 750; }
.recurrence-section { display: grid; gap: 1rem; }
@media (max-width: 850px) {
  .form-grid, .detail-grid { grid-template-columns: 1fr; }
  .line-editor { grid-template-columns: 1fr; }
}
</style>
