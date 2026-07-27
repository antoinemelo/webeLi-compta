<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import CompactTabs from '@/components/ui/CompactTabs.vue';
import DataTable from '@/components/ui/DataTable.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import FormField from '@/components/ui/FormField.vue';
import SkeletonBlock from '@/components/ui/SkeletonBlock.vue';
import type { BillingDocument } from '@/api/contracts';
import { runtimeConfig } from '@/config';
import { subNavigation } from '@/router/navigation';
import { useBillingStore } from '@/stores/billing';
import { useContextStore } from '@/stores/context';
import { useNotificationStore } from '@/stores/notifications';

type DraftLine = {
  label: string;
  amount: string;
  input_mode: 'net' | 'brut';
  account_id: number;
  vat_code_id: number | '';
};

const route = useRoute();
const context = useContextStore();
const store = useBillingStore();
const notifications = useNotificationStore();
const today = new Date().toISOString().slice(0, 10);
const activeTab = computed(() => String(route.params.tab || 'sales'));
const workspace = computed(() => store.workspace);
const showDocumentForm = ref(false);
const showContactForm = ref(false);
const showRecurrenceForm = ref(false);
const documentAttachment = ref<{ name: string; content_base64: string } | null>(null);

const documentDraft = reactive({
  contact_id: 0,
  document_date: today,
  due_date: today,
  external_number: '',
  collective_account_id: 0,
  currency: context.context?.selection?.dossier.currency || 'CHF',
  exchange_rate_id: 0,
  lines: [newLine()] as DraftLine[]
});
const contactDraft = reactive({
  type: 'entreprise' as 'entreprise' | 'personne',
  company: '',
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  client: true,
  supplier: false,
  line1: '',
  line2: '',
  postal_code: '',
  city: '',
  country: 'CH'
});
const recurrenceDraft = reactive({
  type: 'facture_client' as 'facture_client' | 'facture_fournisseur',
  contact_id: 0,
  label: '',
  frequency: 'mensuelle' as 'hebdomadaire' | 'mensuelle' | 'trimestrielle' | 'annuelle',
  interval: 1,
  next_date: today,
  end_date: '',
  due_days: 30,
  collective_account_id: 0,
  external_prefix: '',
  lines: [newLine()] as DraftLine[]
});
const reminderDraft = reactive({
  document_id: 0,
  level: 1,
  channel: 'email',
  note: ''
});
const paymentDraft = reactive({
  contact_id: 0,
  direction: 'encaissement' as 'encaissement' | 'decaissement',
  date: today,
  amount: '',
  reference: '',
  ledger_account_id: 0,
  currency: context.context?.selection?.dossier.currency || 'CHF',
  exchange_rate_id: 0
});
const allocationDraft = reactive({
  payment_id: 0,
  document_id: 0,
  amount: ''
});

const direction = computed<'sales' | 'purchases'>(() =>
  activeTab.value === 'achats' ? 'purchases' : 'sales'
);
const documentType = computed<'facture_client' | 'facture_fournisseur'>(() =>
  direction.value === 'sales' ? 'facture_client' : 'facture_fournisseur'
);
const documentContacts = computed(() =>
  (workspace.value?.contacts ?? []).filter((contact) =>
    contact.roles.includes(direction.value === 'sales' ? 'client' : 'fournisseur')
  )
);
const documentVatCodes = computed(() =>
  availableVatCodes(documentType.value, documentDraft.document_date)
);
const recurrenceVatCodes = computed(() =>
  availableVatCodes(recurrenceDraft.type, recurrenceDraft.next_date)
);
const documentExchangeRates = computed(() =>
  (workspace.value?.catalog.exchange_rates ?? []).filter((rate) =>
    rate.source_currency === documentDraft.currency
    && rate.rate_date <= documentDraft.document_date
  )
);
const paymentExchangeRates = computed(() =>
  (workspace.value?.catalog.exchange_rates ?? []).filter((rate) =>
    rate.source_currency === paymentDraft.currency
    && rate.rate_date <= paymentDraft.date
  )
);
const documentRows = computed(() =>
  (workspace.value?.documents ?? []).map((item) => ({
    ...item,
    display_number: item.number || `Brouillon #${item.id}`,
    amount: dualMoney(
      item.gross_cents,
      item.currency,
      item.gross_base_cents,
      item.base_currency
    ),
    open: dualMoney(
      item.open_cents,
      item.currency,
      item.open_base_cents,
      item.base_currency
    ),
    status_label: statusLabel(item.status),
    payment_label: paymentLabel(item.payment_state)
  }))
);
const recurrenceRows = computed(() =>
  (workspace.value?.recurrences ?? []).map((item) => ({
    ...item,
    type_label: item.type === 'facture_client' ? 'Vente' : 'Achat',
    cadence: `${item.frequency}${item.interval > 1 ? ` × ${item.interval}` : ''}`,
    status_label: statusLabel(item.status)
  }))
);
const contactRows = computed(() =>
  (workspace.value?.contacts ?? []).map((item) => ({
    ...item,
    roles_label: item.roles.join(', '),
    receivable: money(item.balance.receivable_cents),
    payable: money(item.balance.payable_cents),
    net: money(item.balance.net_cents)
  }))
);
const selectedContact = computed(() =>
  workspace.value?.contacts.find(
    (contact) => contact.id === store.filters.contact_id
  ) ?? null
);
const exportUrl = computed(() => {
  const query = new URLSearchParams({
    as_of_date: store.filters.as_of_date,
    direction: store.filters.direction,
    status: store.filters.status,
    search: store.filters.search
  });
  if (store.filters.contact_id) {
    query.set('contact_id', String(store.filters.contact_id));
  }
  return `${runtimeConfig.apiBaseUrl}/facturation/export?${query}`;
});

onMounted(async () => {
  syncDirection();
  if (context.context?.selection) await store.load();
});
watch(
  () => context.context?.selection?.dossier.id,
  async () => {
    store.clear();
    store.filters.contact_id = 0;
    if (context.context?.selection) await store.load();
  }
);
watch(activeTab, async () => {
  syncDirection();
  if (context.context?.selection) await store.load();
});

function syncDirection(): void {
  store.filters.direction = ['sales', 'achats'].includes(activeTab.value)
    ? direction.value : 'all';
}

function newLine(): DraftLine {
  return {
    label: '',
    amount: '',
    input_mode: 'net',
    account_id: 0,
    vat_code_id: ''
  };
}

function availableVatCodes(
  type: 'facture_client' | 'facture_fournisseur',
  date: string
) {
  const allowed = type === 'facture_fournisseur'
    ? ['prealable', 'acquisition', 'non_taxable', 'correction']
    : ['collectee', 'non_taxable', 'correction'];
  return (workspace.value?.catalog.vat_codes ?? []).filter((code) =>
    allowed.includes(code.nature)
      && code.valid_from <= date
      && (code.valid_until === null || code.valid_until >= date)
  );
}

function cents(value: string): number {
  const normalized = value.trim().replace(',', '.');
  const match = normalized.match(/^(\d+)(?:\.(\d{0,2}))?$/);
  if (!match) throw new Error('Montant invalide : utilisez au plus deux décimales.');
  return Number(match[1]) * 100 + Number((match[2] || '').padEnd(2, '0'));
}

function money(value: number, currency = 'CHF'): string {
  const sign = value < 0 ? '−' : '';
  const absolute = Math.abs(value);
  return `${sign}${Math.floor(absolute / 100).toLocaleString('fr-CH')}.${
    String(absolute % 100).padStart(2, '0')
  } ${currency}`;
}

function dualMoney(
  originalCents: number,
  originalCurrency: string,
  baseCents: number,
  baseCurrency: string
): string {
  const original = money(originalCents, originalCurrency);
  return originalCurrency === baseCurrency
    ? original
    : `${original} · ${money(baseCents, baseCurrency)}`;
}

function statusLabel(status: string): string {
  return {
    brouillon: 'Brouillon',
    emis: 'Émis',
    comptabilise: 'Comptabilisé',
    annule: 'Annulé',
    actif: 'Actif',
    pause: 'En pause',
    termine: 'Terminé'
  }[status] || status;
}

function paymentLabel(status: string): string {
  return {
    brouillon: 'Brouillon',
    annule: 'Annulé',
    solde: 'Soldé',
    non_echu: 'Non échu',
    retard_0_30: '0–30 jours',
    retard_31_60: '31–60 jours',
    retard_61_90: '61–90 jours',
    retard_91_plus: '> 90 jours'
  }[status] || status;
}

function apiLines(lines: DraftLine[], date: string): Array<Record<string, unknown>> {
  return lines.map((line) => ({
    label: line.label,
    quantity_milli: 1000,
    unit_price_cents: cents(line.amount),
    input_mode: line.input_mode,
    account_id: Number(line.account_id),
    vat_code_id: requiredPositiveId(line.vat_code_id, 'Sélectionnez un code TVA.'),
    service_date: date
  }));
}

function requiredPositiveId(value: number | '', message: string): number {
  if (!Number.isInteger(value) || Number(value) < 1) {
    throw new Error(message);
  }
  return Number(value);
}

async function attachmentSelected(event: Event): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) {
    documentAttachment.value = null;
    return;
  }
  const dataUrl = await new Promise<string>((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || ''));
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(file);
  });
  documentAttachment.value = {
    name: file.name,
    content_base64: dataUrl.slice(dataUrl.indexOf(',') + 1)
  };
}

async function applyFilters(): Promise<void> {
  await store.load();
}

async function saveDocument(): Promise<void> {
  try {
    if (documentVatCodes.value.length === 0) {
      throw new Error(
        'Aucun code TVA applicable. Configurez les codes TVA dans Configuration > Référentiels.'
      );
    }
    await store.mutate('/facturation/documents', {
      type: documentType.value,
      contact_id: Number(documentDraft.contact_id),
      document_date: documentDraft.document_date,
      due_date: documentDraft.due_date,
      collective_account_id: Number(documentDraft.collective_account_id),
      currency: documentDraft.currency,
      exchange_rate_id: documentDraft.exchange_rate_id || null,
      external_number: documentDraft.external_number,
      attachment: documentAttachment.value,
      lines: apiLines(documentDraft.lines, documentDraft.document_date)
    });
    showDocumentForm.value = false;
    documentAttachment.value = null;
    notifications.push('Document enregistré comme brouillon, sans numéro.', 'success');
  } catch (error) {
    notifications.push(
      store.error || (error instanceof Error ? error.message : 'Impossible de poursuivre.'),
      'warning'
    );
  }
}

async function issueDocument(item: BillingDocument): Promise<void> {
  try {
    await store.mutate('/facturation/documents/emettre', {
      document_id: item.id,
      version: item.version
    });
    notifications.push('Document émis et numéroté.', 'success');
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function postDocument(item: BillingDocument): Promise<void> {
  const exercise = workspace.value?.catalog.exercises[0];
  const journal = workspace.value?.catalog.journals[0];
  if (!exercise || !journal) {
    notifications.push('Configurez un exercice ouvert et un journal.', 'warning');
    return;
  }
  try {
    await store.mutate('/facturation/documents/comptabiliser', {
      document_id: item.id,
      exercise_id: exercise.id,
      journal_id: journal.id
    });
    notifications.push('Document comptabilisé dans le grand livre.', 'success');
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function createCredit(item: BillingDocument): Promise<void> {
  try {
    await store.mutate('/facturation/documents/avoirs', {
      document_id: item.id,
      date: today
    });
    notifications.push('Brouillon d’avoir créé. Il devra être émis puis comptabilisé.', 'success');
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function downloadPdf(item: BillingDocument): Promise<void> {
  try {
    const result = await store.mutate<{
      filename: string;
      content_base64: string;
      qr_included: boolean;
      warning: string;
    }>(
      '/facturation/documents/pdf',
      { document_id: item.id }
    );
    const bytes = Uint8Array.from(
      atob(result.content_base64),
      (character) => character.charCodeAt(0)
    );
    const url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = result.filename;
    link.click();
    URL.revokeObjectURL(url);
    notifications.push(
      result.warning || 'PDF et QR-facture archivés puis téléchargés.',
      result.qr_included ? 'success' : 'warning'
    );
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function saveContact(): Promise<void> {
  const roles = [
    contactDraft.client ? 'client' : '',
    contactDraft.supplier ? 'fournisseur' : ''
  ].filter(Boolean);
  try {
    await store.mutate('/facturation/contacts', {
      type: contactDraft.type,
      company: contactDraft.company,
      first_name: contactDraft.first_name,
      last_name: contactDraft.last_name,
      email: contactDraft.email,
      phone: contactDraft.phone,
      roles,
      address: {
        line1: contactDraft.line1,
        line2: contactDraft.line2,
        postal_code: contactDraft.postal_code,
        city: contactDraft.city,
        country: contactDraft.country
      },
      idempotency_key: crypto.randomUUID()
    });
    showContactForm.value = false;
    notifications.push('Contact ajouté au registre unique.', 'success');
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function selectContact(id: number): Promise<void> {
  store.filters.contact_id = id;
  await store.load();
}

async function clearContact(): Promise<void> {
  store.filters.contact_id = 0;
  await store.load();
}

async function saveRecurrence(): Promise<void> {
  try {
    if (recurrenceVatCodes.value.length === 0) {
      throw new Error(
        'Aucun code TVA applicable. Configurez les codes TVA dans Configuration > Référentiels.'
      );
    }
    await store.mutate('/facturation/recurrences', {
      type: recurrenceDraft.type,
      contact_id: Number(recurrenceDraft.contact_id),
      label: recurrenceDraft.label,
      frequency: recurrenceDraft.frequency,
      interval: Number(recurrenceDraft.interval),
      next_date: recurrenceDraft.next_date,
      end_date: recurrenceDraft.end_date || null,
      due_days: Number(recurrenceDraft.due_days),
      collective_account_id: Number(recurrenceDraft.collective_account_id),
      external_prefix: recurrenceDraft.external_prefix,
      lines: apiLines(recurrenceDraft.lines, recurrenceDraft.next_date)
    });
    showRecurrenceForm.value = false;
    notifications.push('Récurrence enregistrée.', 'success');
  } catch (error) {
    notifications.push(
      store.error || (error instanceof Error ? error.message : 'Impossible de poursuivre.'),
      'warning'
    );
  }
}

async function generateRecurrences(): Promise<void> {
  try {
    await store.mutate('/facturation/recurrences/generer', {
      through_date: store.filters.as_of_date
    });
    notifications.push('Échéances générées comme brouillons, sans doublon.', 'success');
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function toggleRecurrence(item: {
  id: number; version: number; status: string;
}): Promise<void> {
  try {
    await store.mutate('/facturation/recurrences/pause', {
      recurrence_id: item.id,
      version: item.version,
      paused: item.status === 'actif'
    });
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function saveReminder(): Promise<void> {
  try {
    await store.mutate('/facturation/rappels', {
      document_id: Number(reminderDraft.document_id),
      level: Number(reminderDraft.level),
      channel: reminderDraft.channel,
      note: reminderDraft.note
    });
    notifications.push('Rappel tracé dans l’historique.', 'success');
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function savePayment(): Promise<void> {
  try {
    await store.mutate('/facturation/paiements', {
      contact_id: Number(paymentDraft.contact_id),
      direction: paymentDraft.direction,
      date: paymentDraft.date,
      amount_cents: cents(paymentDraft.amount),
      reference: paymentDraft.reference,
      ledger_account_id: Number(paymentDraft.ledger_account_id),
      currency: paymentDraft.currency,
      exchange_rate_id: paymentDraft.exchange_rate_id || null
    });
    notifications.push('Paiement saisi indépendamment des factures.', 'success');
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function allocatePayment(): Promise<void> {
  try {
    await store.mutate('/facturation/allocations', {
      payment_id: Number(allocationDraft.payment_id),
      document_id: Number(allocationDraft.document_id),
      amount_cents: cents(allocationDraft.amount)
    });
    notifications.push('Paiement alloué au centime.', 'success');
  } catch {
    notifications.push(store.error, 'warning');
  }
}
</script>

<template>
  <section class="page-stack">
    <header class="page-heading">
      <div>
        <h1>Facturation</h1>
        <p>Documents, échéances, contacts et paiements reliés au même grand livre.</p>
      </div>
      <a v-if="workspace" class="button secondary" :href="exportUrl">Exporter la vue CSV</a>
    </header>

    <CompactTabs :items="subNavigation.billing" label="Navigation de la facturation" />

    <form class="filter-bar" @submit.prevent="applyFilters">
      <FormField id="billing-as-of" label="Date de référence">
        <template #default="{ describedBy }">
          <input id="billing-as-of" v-model="store.filters.as_of_date" type="date" :aria-describedby="describedBy" required>
        </template>
      </FormField>
      <FormField id="billing-search" label="Recherche">
        <template #default="{ describedBy }">
          <input id="billing-search" v-model="store.filters.search" :aria-describedby="describedBy" placeholder="Numéro ou contact">
        </template>
      </FormField>
      <FormField id="billing-status" label="État">
        <template #default="{ describedBy }">
          <select id="billing-status" v-model="store.filters.status" :aria-describedby="describedBy">
            <option value="all">Tous</option>
            <option value="brouillon">Brouillons</option>
            <option value="solde">Soldés</option>
            <option value="non_echu">Non échus</option>
            <option value="retard_0_30">0–30 jours</option>
            <option value="retard_31_60">31–60 jours</option>
            <option value="retard_61_90">61–90 jours</option>
            <option value="retard_91_plus">&gt; 90 jours</option>
          </select>
        </template>
      </FormField>
      <button class="button primary" :disabled="store.loading">Actualiser</button>
    </form>

    <p v-if="workspace" class="reference-note">
      Situation calculée au <strong>{{ workspace.reference_date }}</strong>.
      Les paiements non alloués sont présentés séparément des tranches d’âge.
    </p>
    <ErrorSummary v-if="store.error" :message="store.error" />
    <SkeletonBlock v-if="store.loading && !workspace" :lines="8" />

    <template v-else-if="workspace && ['sales', 'achats'].includes(activeTab)">
      <div class="toolbar">
        <div>
          <h2>{{ direction === 'sales' ? 'Factures clients' : 'Factures fournisseurs' }}</h2>
          <p>Le numéro n’est attribué qu’à l’émission ; le brouillon reste modifiable.</p>
        </div>
        <button
          v-if="workspace.capabilities.manage"
          class="button primary"
          type="button"
          :disabled="documentVatCodes.length === 0"
          @click="showDocumentForm = !showDocumentForm"
        >Nouveau document</button>
      </div>

      <div v-if="documentVatCodes.length === 0" class="notice warning" role="alert">
        Aucun code TVA actif et compatible ne couvre la date du document.
        <RouterLink to="/configuration/referentiels">Configurer les codes TVA</RouterLink>.
      </div>

      <form v-if="showDocumentForm" class="editor-card" @submit.prevent="saveDocument">
        <h3>{{ direction === 'sales' ? 'Nouvelle facture client' : 'Nouvelle facture fournisseur' }}</h3>
        <div class="form-grid">
          <FormField id="billing-contact" :label="direction === 'sales' ? 'Client' : 'Fournisseur'">
            <template #default="{ describedBy }">
              <select id="billing-contact" v-model.number="documentDraft.contact_id" :aria-describedby="describedBy" required>
                <option :value="0" disabled>Sélectionner</option>
                <option v-for="contact in documentContacts" :key="contact.id" :value="contact.id">{{ contact.label }}</option>
              </select>
            </template>
          </FormField>
          <FormField v-if="direction === 'purchases'" id="billing-external" label="Numéro fournisseur">
            <template #default="{ describedBy }"><input id="billing-external" v-model="documentDraft.external_number" :aria-describedby="describedBy" required></template>
          </FormField>
          <FormField id="billing-date" label="Date du document">
            <template #default="{ describedBy }"><input id="billing-date" v-model="documentDraft.document_date" type="date" :aria-describedby="describedBy" required></template>
          </FormField>
          <FormField id="billing-due" label="Échéance explicite">
            <template #default="{ describedBy }"><input id="billing-due" v-model="documentDraft.due_date" type="date" :aria-describedby="describedBy" required></template>
          </FormField>
          <FormField id="billing-collective" label="Compte collectif">
            <template #default="{ describedBy }">
              <select id="billing-collective" v-model.number="documentDraft.collective_account_id" :aria-describedby="describedBy" required>
                <option :value="0" disabled>Sélectionner</option>
                <option v-for="account in workspace.catalog.accounts" :key="account.id" :value="account.id">{{ account.number }} · {{ account.label }}</option>
              </select>
            </template>
          </FormField>
          <FormField id="billing-currency" label="Devise">
            <template #default="{ describedBy }">
              <select id="billing-currency" v-model="documentDraft.currency" :aria-describedby="describedBy" required @change="documentDraft.exchange_rate_id = 0">
                <option v-for="currencyItem in workspace.catalog.currencies" :key="currencyItem.code" :value="currencyItem.code">{{ currencyItem.code }}{{ currencyItem.is_base ? ' · base' : '' }}</option>
              </select>
            </template>
          </FormField>
          <FormField v-if="documentDraft.currency !== context.context?.selection?.dossier.currency" id="billing-rate" label="Taux figé" hint="Taux vers la devise de base, daté au plus tard le jour du document.">
            <template #default="{ describedBy }">
              <select id="billing-rate" v-model.number="documentDraft.exchange_rate_id" :aria-describedby="describedBy" required>
                <option :value="0" disabled>Sélectionner</option>
                <option v-for="rate in documentExchangeRates" :key="rate.id" :value="rate.id">{{ rate.rate_date }} · {{ rate.numerator }}/{{ rate.denominator }} · {{ rate.source }}</option>
              </select>
            </template>
          </FormField>
          <FormField v-if="direction === 'purchases'" id="billing-attachment" label="Justificatif" hint="PDF ou image, 10 Mo maximum.">
            <template #default="{ describedBy }"><input id="billing-attachment" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" :aria-describedby="describedBy" @change="attachmentSelected"></template>
          </FormField>
        </div>
        <fieldset v-for="(line, index) in documentDraft.lines" :key="index" class="line-editor">
          <legend>Ligne {{ index + 1 }}</legend>
          <input v-model="line.label" aria-label="Libellé" placeholder="Libellé" required>
          <input v-model="line.amount" aria-label="Montant" inputmode="decimal" placeholder="Montant" required>
          <select v-model.number="line.account_id" aria-label="Compte" required>
            <option :value="0" disabled>Compte</option>
            <option v-for="account in workspace.catalog.accounts" :key="account.id" :value="account.id">{{ account.number }} · {{ account.label }}</option>
          </select>
          <select v-model.number="line.vat_code_id" aria-label="Code TVA" required>
            <option value="" disabled>Sélectionner un code TVA</option>
            <option v-for="vat in documentVatCodes" :key="vat.id" :value="vat.id">{{ vat.code }} · {{ vat.label }}</option>
          </select>
          <select v-model="line.input_mode" aria-label="Mode de saisie"><option value="net">Net</option><option value="brut">Brut</option></select>
          <button v-if="documentDraft.lines.length > 1" class="button ghost" type="button" @click="documentDraft.lines.splice(index, 1)">Retirer</button>
        </fieldset>
        <div class="button-row">
          <button class="button ghost" type="button" @click="documentDraft.lines.push(newLine())">Ajouter une ligne</button>
          <button class="button primary" :disabled="store.saving">Enregistrer le brouillon</button>
        </div>
      </form>

      <DataTable
        v-if="documentRows.length"
        :caption="direction === 'sales' ? 'Documents clients' : 'Documents fournisseurs'"
        :columns="[
          { key: 'display_number', label: 'Document' },
          { key: 'contact', label: 'Contact' },
          { key: 'document_date', label: 'Date' },
          { key: 'due_date', label: 'Échéance' },
          { key: 'amount', label: 'Total' },
          { key: 'open', label: 'Ouvert' },
          { key: 'payment_label', label: 'Paiement' },
          { key: 'actions', label: 'Actions' }
        ]"
        :rows="documentRows"
      >
        <template #cell-actions="{ row }">
          <div class="table-actions">
            <button v-if="row.status === 'brouillon' && workspace.capabilities.issue" type="button" @click="issueDocument(row as BillingDocument)">Émettre</button>
            <button v-if="row.status === 'emis' && workspace.capabilities.post" type="button" @click="postDocument(row as BillingDocument)">Comptabiliser</button>
            <button v-if="['emis', 'comptabilise'].includes(String(row.status)) && String(row.type).startsWith('facture_') && workspace.capabilities.manage" type="button" @click="createCredit(row as BillingDocument)">Créer un avoir</button>
            <button v-if="String(row.type) === 'facture_client' && ['emis', 'comptabilise'].includes(String(row.status)) && workspace.capabilities.issue" type="button" @click="downloadPdf(row as BillingDocument)">PDF</button>
          </div>
        </template>
      </DataTable>
      <EmptyState v-else title="Aucun document" description="Aucun document ne correspond aux filtres et à la date de référence." />
    </template>

    <template v-else-if="workspace && activeTab === 'recurrences'">
      <div class="toolbar">
        <div><h2>Factures récurrentes</h2><p>Chaque échéance crée un brouillon idempotent à contrôler avant émission.</p></div>
        <div class="button-row">
          <button v-if="workspace.capabilities.manage" class="button secondary" type="button" @click="generateRecurrences">Générer jusqu’à la date de référence</button>
          <button v-if="workspace.capabilities.manage" class="button primary" type="button" @click="showRecurrenceForm = !showRecurrenceForm">Nouveau modèle</button>
        </div>
      </div>
      <form v-if="showRecurrenceForm" class="editor-card" @submit.prevent="saveRecurrence">
        <div class="form-grid">
          <FormField id="billing-rec-type" label="Parcours"><template #default="{ describedBy }"><select id="billing-rec-type" v-model="recurrenceDraft.type" :aria-describedby="describedBy"><option value="facture_client">Vente client</option><option value="facture_fournisseur">Achat fournisseur</option></select></template></FormField>
          <FormField id="billing-rec-label" label="Nom du modèle"><template #default="{ describedBy }"><input id="billing-rec-label" v-model="recurrenceDraft.label" :aria-describedby="describedBy" required></template></FormField>
          <FormField id="billing-rec-contact" label="Contact"><template #default="{ describedBy }"><select id="billing-rec-contact" v-model.number="recurrenceDraft.contact_id" :aria-describedby="describedBy" required><option :value="0" disabled>Sélectionner</option><option v-for="contact in workspace.contacts" :key="contact.id" :value="contact.id">{{ contact.label }}</option></select></template></FormField>
          <FormField id="billing-rec-frequency" label="Périodicité"><template #default="{ describedBy }"><select id="billing-rec-frequency" v-model="recurrenceDraft.frequency" :aria-describedby="describedBy"><option value="hebdomadaire">Hebdomadaire</option><option value="mensuelle">Mensuelle</option><option value="trimestrielle">Trimestrielle</option><option value="annuelle">Annuelle</option></select></template></FormField>
          <FormField id="billing-rec-next" label="Prochaine échéance"><template #default="{ describedBy }"><input id="billing-rec-next" v-model="recurrenceDraft.next_date" type="date" :aria-describedby="describedBy" required></template></FormField>
          <FormField id="billing-rec-end" label="Fin facultative"><template #default="{ describedBy }"><input id="billing-rec-end" v-model="recurrenceDraft.end_date" type="date" :aria-describedby="describedBy"></template></FormField>
          <FormField id="billing-rec-due" label="Délai en jours"><template #default="{ describedBy }"><input id="billing-rec-due" v-model.number="recurrenceDraft.due_days" type="number" min="0" max="365" :aria-describedby="describedBy" required></template></FormField>
          <FormField id="billing-rec-prefix" label="Préfixe fournisseur"><template #default="{ describedBy }"><input id="billing-rec-prefix" v-model="recurrenceDraft.external_prefix" :aria-describedby="describedBy" :required="recurrenceDraft.type === 'facture_fournisseur'"></template></FormField>
          <FormField id="billing-rec-collective" label="Compte collectif"><template #default="{ describedBy }"><select id="billing-rec-collective" v-model.number="recurrenceDraft.collective_account_id" :aria-describedby="describedBy" required><option :value="0" disabled>Sélectionner</option><option v-for="account in workspace.catalog.accounts" :key="account.id" :value="account.id">{{ account.number }} · {{ account.label }}</option></select></template></FormField>
        </div>
        <fieldset v-for="(line, index) in recurrenceDraft.lines" :key="index" class="line-editor">
          <legend>Ligne {{ index + 1 }}</legend>
          <input v-model="line.label" aria-label="Libellé récurrent" placeholder="Libellé" required>
          <input v-model="line.amount" aria-label="Montant récurrent" inputmode="decimal" placeholder="Montant" required>
          <select v-model.number="line.account_id" aria-label="Compte récurrent" required><option :value="0" disabled>Compte</option><option v-for="account in workspace.catalog.accounts" :key="account.id" :value="account.id">{{ account.number }} · {{ account.label }}</option></select>
          <select v-model.number="line.vat_code_id" aria-label="TVA récurrente" required><option value="" disabled>Sélectionner un code TVA</option><option v-for="vat in recurrenceVatCodes" :key="vat.id" :value="vat.id">{{ vat.code }} · {{ vat.label }}</option></select>
          <select v-model="line.input_mode" aria-label="Mode récurrent"><option value="net">Net</option><option value="brut">Brut</option></select>
        </fieldset>
        <div class="button-row"><button class="button ghost" type="button" @click="recurrenceDraft.lines.push(newLine())">Ajouter une ligne</button><button class="button primary" :disabled="store.saving">Enregistrer</button></div>
      </form>
      <DataTable
        v-if="recurrenceRows.length"
        caption="Modèles de factures récurrentes"
        :columns="[{ key: 'label', label: 'Modèle' }, { key: 'type_label', label: 'Parcours' }, { key: 'contact', label: 'Contact' }, { key: 'cadence', label: 'Cadence' }, { key: 'next_date', label: 'Prochaine' }, { key: 'generation_count', label: 'Brouillons' }, { key: 'status_label', label: 'Statut' }, { key: 'actions', label: 'Actions' }]"
        :rows="recurrenceRows"
      >
        <template #cell-actions="{ row }"><button v-if="row.status !== 'termine' && workspace.capabilities.manage" type="button" @click="toggleRecurrence(row as { id: number; version: number; status: string })">{{ row.status === 'actif' ? 'Mettre en pause' : 'Reprendre' }}</button></template>
      </DataTable>
      <EmptyState v-else title="Aucune récurrence" description="Créez un modèle client ou fournisseur ; aucune émission ne sera automatique." />
    </template>

    <template v-else-if="workspace && activeTab === 'contacts'">
      <div class="toolbar">
        <div><h2>Contacts et vue 360°</h2><p>Un registre unique pour les rôles client et fournisseur.</p></div>
        <button v-if="workspace.capabilities.manage" class="button primary" type="button" @click="showContactForm = !showContactForm">Nouveau contact</button>
      </div>
      <form v-if="showContactForm" class="editor-card" @submit.prevent="saveContact">
        <div class="form-grid">
          <FormField id="contact-kind" label="Type"><template #default="{ describedBy }"><select id="contact-kind" v-model="contactDraft.type" :aria-describedby="describedBy"><option value="entreprise">Entreprise</option><option value="personne">Personne</option></select></template></FormField>
          <FormField v-if="contactDraft.type === 'entreprise'" id="contact-company" label="Raison sociale"><template #default="{ describedBy }"><input id="contact-company" v-model="contactDraft.company" :aria-describedby="describedBy" required></template></FormField>
          <FormField v-else id="contact-first" label="Prénom"><template #default="{ describedBy }"><input id="contact-first" v-model="contactDraft.first_name" :aria-describedby="describedBy"></template></FormField>
          <FormField v-if="contactDraft.type === 'personne'" id="contact-last" label="Nom"><template #default="{ describedBy }"><input id="contact-last" v-model="contactDraft.last_name" :aria-describedby="describedBy" required></template></FormField>
          <FormField id="contact-email" label="Courriel"><template #default="{ describedBy }"><input id="contact-email" v-model="contactDraft.email" type="email" :aria-describedby="describedBy"></template></FormField>
          <FormField id="contact-line1" label="Adresse"><template #default="{ describedBy }"><input id="contact-line1" v-model="contactDraft.line1" :aria-describedby="describedBy" required></template></FormField>
          <FormField id="contact-postal" label="NPA"><template #default="{ describedBy }"><input id="contact-postal" v-model="contactDraft.postal_code" :aria-describedby="describedBy" required></template></FormField>
          <FormField id="contact-city" label="Localité"><template #default="{ describedBy }"><input id="contact-city" v-model="contactDraft.city" :aria-describedby="describedBy" required></template></FormField>
        </div>
        <fieldset><legend>Rôles</legend><label><input v-model="contactDraft.client" type="checkbox"> Client</label><label><input v-model="contactDraft.supplier" type="checkbox"> Fournisseur</label></fieldset>
        <button class="button primary" :disabled="store.saving">Ajouter au registre</button>
      </form>
      <DataTable
        v-if="contactRows.length"
        caption="Contacts du dossier"
        :columns="[{ key: 'label', label: 'Contact' }, { key: 'roles_label', label: 'Rôles' }, { key: 'receivable', label: 'Créances' }, { key: 'payable', label: 'Dettes' }, { key: 'net', label: 'Net' }, { key: 'actions', label: 'Vue 360°' }]"
        :rows="contactRows"
      >
        <template #cell-actions="{ row }"><button type="button" @click="selectContact(Number(row.id))">Ouvrir</button></template>
      </DataTable>
      <article v-if="selectedContact && workspace.contact_360" class="detail-card">
        <div class="toolbar"><div><p class="eyebrow">Vue 360° au {{ workspace.reference_date }}</p><h3>{{ selectedContact.label }}</h3><p>{{ selectedContact.email || 'Sans courriel' }} · {{ selectedContact.address.city }}</p></div><button class="button ghost" type="button" @click="clearContact">Fermer</button></div>
        <dl class="detail-grid">
          <div><dt>Créances nettes</dt><dd>{{ money(workspace.contact_360.balance.receivable_cents) }}</dd></div>
          <div><dt>Dettes nettes</dt><dd>{{ money(workspace.contact_360.balance.payable_cents) }}</dd></div>
          <div><dt>Solde net</dt><dd>{{ money(workspace.contact_360.balance.net_cents) }}</dd></div>
          <div><dt>Documents</dt><dd>{{ workspace.contact_360.documents.length }}</dd></div>
          <div><dt>Paiements</dt><dd>{{ workspace.contact_360.payments.length }}</dd></div>
        </dl>
      </article>
    </template>

    <template v-else-if="workspace && activeTab === 'echeancier'">
      <div class="toolbar"><div><h2>Échéancier et lettrage</h2><p>Créances et dettes ouvertes, calculées au {{ workspace.reference_date }}.</p></div></div>
      <div class="kpi-grid">
        <article class="kpi-card"><span>Créances nettes</span><strong>{{ money(workspace.aging.receivables.net_open_cents) }}</strong><small>{{ workspace.aging.receivables.item_count }} document(s)</small></article>
        <article class="kpi-card"><span>Dettes nettes</span><strong>{{ money(workspace.aging.payables.net_open_cents) }}</strong><small>{{ workspace.aging.payables.item_count }} document(s)</small></article>
        <article class="kpi-card"><span>Acomptes clients</span><strong>{{ money(workspace.aging.receivables.unallocated_payments_cents) }}</strong><small>Non ventilés dans l’aging</small></article>
        <article class="kpi-card"><span>Avances fournisseurs</span><strong>{{ money(workspace.aging.payables.unallocated_payments_cents) }}</strong><small>Non ventilées dans l’aging</small></article>
      </div>
      <DataTable
        caption="Tranches d’âge des créances et dettes"
        :columns="[{ key: 'side', label: 'Nature' }, { key: 'not_due', label: 'Non échu' }, { key: 'd0', label: '0–30' }, { key: 'd31', label: '31–60' }, { key: 'd61', label: '61–90' }, { key: 'd91', label: '> 90' }, { key: 'net', label: 'Solde net' }]"
        :rows="[
          { id: 'receivables', side: 'Créances', not_due: money(workspace.aging.receivables.buckets.not_due), d0: money(workspace.aging.receivables.buckets.days_0_30), d31: money(workspace.aging.receivables.buckets.days_31_60), d61: money(workspace.aging.receivables.buckets.days_61_90), d91: money(workspace.aging.receivables.buckets.days_91_plus), net: money(workspace.aging.receivables.net_open_cents) },
          { id: 'payables', side: 'Dettes', not_due: money(workspace.aging.payables.buckets.not_due), d0: money(workspace.aging.payables.buckets.days_0_30), d31: money(workspace.aging.payables.buckets.days_31_60), d61: money(workspace.aging.payables.buckets.days_61_90), d91: money(workspace.aging.payables.buckets.days_91_plus), net: money(workspace.aging.payables.net_open_cents) }
        ]"
      />

      <div class="split-grid">
        <form v-if="workspace.capabilities.pay" class="editor-card" @submit.prevent="savePayment">
          <h3>Saisir un paiement</h3>
          <FormField id="payment-contact" label="Contact"><template #default="{ describedBy }"><select id="payment-contact" v-model.number="paymentDraft.contact_id" :aria-describedby="describedBy" required><option :value="0" disabled>Sélectionner</option><option v-for="contact in workspace.contacts" :key="contact.id" :value="contact.id">{{ contact.label }}</option></select></template></FormField>
          <FormField id="payment-direction" label="Sens"><template #default="{ describedBy }"><select id="payment-direction" v-model="paymentDraft.direction" :aria-describedby="describedBy"><option value="encaissement">Encaissement client</option><option value="decaissement">Décaissement fournisseur</option></select></template></FormField>
          <FormField id="payment-date" label="Date"><template #default="{ describedBy }"><input id="payment-date" v-model="paymentDraft.date" type="date" :aria-describedby="describedBy" required></template></FormField>
          <FormField id="payment-amount" :label="`Montant ${paymentDraft.currency}`"><template #default="{ describedBy }"><input id="payment-amount" v-model="paymentDraft.amount" inputmode="decimal" :aria-describedby="describedBy" required></template></FormField>
          <FormField id="payment-currency" label="Devise"><template #default="{ describedBy }"><select id="payment-currency" v-model="paymentDraft.currency" :aria-describedby="describedBy" required @change="paymentDraft.exchange_rate_id = 0"><option v-for="currencyItem in workspace.catalog.currencies" :key="currencyItem.code" :value="currencyItem.code">{{ currencyItem.code }}{{ currencyItem.is_base ? ' · base' : '' }}</option></select></template></FormField>
          <FormField v-if="paymentDraft.currency !== context.context?.selection?.dossier.currency" id="payment-rate" label="Taux figé"><template #default="{ describedBy }"><select id="payment-rate" v-model.number="paymentDraft.exchange_rate_id" :aria-describedby="describedBy" required><option :value="0" disabled>Sélectionner</option><option v-for="rate in paymentExchangeRates" :key="rate.id" :value="rate.id">{{ rate.rate_date }} · {{ rate.numerator }}/{{ rate.denominator }} · {{ rate.source }}</option></select></template></FormField>
          <FormField id="payment-account" label="Compte de trésorerie"><template #default="{ describedBy }"><select id="payment-account" v-model.number="paymentDraft.ledger_account_id" :aria-describedby="describedBy" required><option :value="0" disabled>Sélectionner</option><option v-for="account in workspace.catalog.accounts" :key="account.id" :value="account.id">{{ account.number }} · {{ account.label }}</option></select></template></FormField>
          <button class="button primary" :disabled="store.saving">Enregistrer</button>
        </form>
        <form v-if="workspace.capabilities.pay" class="editor-card" @submit.prevent="allocatePayment">
          <h3>Allouer un paiement</h3>
          <FormField id="allocation-payment" label="Paiement disponible"><template #default="{ describedBy }"><select id="allocation-payment" v-model.number="allocationDraft.payment_id" :aria-describedby="describedBy" required><option :value="0" disabled>Sélectionner</option><option v-for="payment in workspace.payments.filter((item) => item.unallocated_cents > 0)" :key="payment.id" :value="payment.id">{{ payment.contact }} · {{ money(payment.unallocated_cents, payment.currency) }}</option></select></template></FormField>
          <FormField id="allocation-document" label="Facture ouverte"><template #default="{ describedBy }"><select id="allocation-document" v-model.number="allocationDraft.document_id" :aria-describedby="describedBy" required><option :value="0" disabled>Sélectionner</option><option v-for="document in workspace.documents.filter((item) => item.open_cents > 0 && item.type.startsWith('facture_'))" :key="document.id" :value="document.id">{{ document.number }} · {{ document.contact }} · {{ money(document.open_cents) }}</option></select></template></FormField>
          <FormField id="allocation-amount" label="Montant à allouer"><template #default="{ describedBy }"><input id="allocation-amount" v-model="allocationDraft.amount" inputmode="decimal" :aria-describedby="describedBy" required></template></FormField>
          <button class="button primary" :disabled="store.saving">Lettrer</button>
        </form>
      </div>

      <form v-if="workspace.capabilities.remind" class="editor-card" @submit.prevent="saveReminder">
        <h3>Tracer un rappel</h3>
        <div class="form-grid">
          <FormField id="reminder-document" label="Facture client ouverte"><template #default="{ describedBy }"><select id="reminder-document" v-model.number="reminderDraft.document_id" :aria-describedby="describedBy" required><option :value="0" disabled>Sélectionner</option><option v-for="document in workspace.documents.filter((item) => item.type === 'facture_client' && item.open_cents > 0)" :key="document.id" :value="document.id">{{ document.number }} · {{ document.contact }}</option></select></template></FormField>
          <FormField id="reminder-level" label="Niveau"><template #default="{ describedBy }"><input id="reminder-level" v-model.number="reminderDraft.level" type="number" min="1" max="9" :aria-describedby="describedBy" required></template></FormField>
          <FormField id="reminder-channel" label="Canal"><template #default="{ describedBy }"><select id="reminder-channel" v-model="reminderDraft.channel" :aria-describedby="describedBy"><option value="email">Courriel</option><option value="courrier">Courrier</option><option value="telephone">Téléphone</option><option value="autre">Autre</option></select></template></FormField>
          <FormField id="reminder-note" label="Note"><template #default="{ describedBy }"><input id="reminder-note" v-model="reminderDraft.note" :aria-describedby="describedBy"></template></FormField>
        </div>
        <button class="button primary" :disabled="store.saving">Enregistrer le rappel</button>
      </form>
    </template>
  </section>
</template>
