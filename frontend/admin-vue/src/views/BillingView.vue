<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AgingChart from '@/components/billing/AgingChart.vue';
import AccountCombobox from '@/components/ui/AccountCombobox.vue';
import ActionMenu from '@/components/ui/ActionMenu.vue';
import CompactTabs from '@/components/ui/CompactTabs.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataTable from '@/components/ui/DataTable.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import FormField from '@/components/ui/FormField.vue';
import ModalDialog from '@/components/ui/ModalDialog.vue';
import SkeletonBlock from '@/components/ui/SkeletonBlock.vue';
import type {
  BillingDocument,
  BillingPayment,
  CommercialDocument
} from '@/api/contracts';
import { runtimeConfig } from '@/config';
import { useToastFeedback } from '@/composables/toastFeedback';
import { subNavigation } from '@/router/navigation';
import { useBillingStore } from '@/stores/billing';
import { useContextStore } from '@/stores/context';
import { useNotificationStore } from '@/stores/notifications';

type DraftLine = {
  label: string;
  quantity: string;
  amount: string;
  input_mode: 'net' | 'brut';
  account_id: number;
  vat_code_id: number | '';
};

const route = useRoute();
const router = useRouter();
const context = useContextStore();
const store = useBillingStore();
useToastFeedback(store);
const notifications = useNotificationStore();
const today = new Date().toISOString().slice(0, 10);
const activeTab = computed(() => {
  const tab = String(route.params.tab || 'echeancier');
  return tab === 'ventes' ? 'sales' : tab;
});
const workspace = computed(() => store.workspace);
const showRecurrenceForm = ref(false);
const documentDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const commercialDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const commercialViewerDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const financialViewerDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const reversalDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const conversionDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const contactEditorDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const contact360Dialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const removeContactDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
const pendingContactRemoval = ref<number>(0);
const reminderDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const paymentDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const allocationDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const editingDocument = ref<BillingDocument | null>(null);
const viewedDocument = ref<BillingDocument | null>(null);
const reversingDocument = ref<BillingDocument | null>(null);
const viewedCommercial = ref<CommercialDocument | null>(null);
const documentAttachment = ref<{ name: string; content_base64: string } | null>(null);
const contactSearch = ref('');
const contactStatus = ref<'active' | 'archived' | 'all'>('active');

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
  id: 0,
  version: 0,
  type: 'entreprise' as 'entreprise' | 'personne',
  company_contact_id: null as number | null,
  company: '',
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  iban: '',
  bic: '',
  language: 'fr',
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
  treasury_account_id: 0,
  currency: context.context?.selection?.dossier.currency || 'CHF',
  exchange_rate_id: 0
});
const allocationDraft = reactive({
  payment_id: 0,
  document_id: 0,
  amount: ''
});
const reversalDraft = reactive({ date: today });
const availableAllocationPayments = computed(() =>
  (workspace.value?.payments ?? []).filter(
    (item) => item.matching_eligible && item.unallocated_cents > 0
  )
);
const selectedAllocationPayment = computed(() =>
  availableAllocationPayments.value.find(
    (item) => item.id === Number(allocationDraft.payment_id)
  ) ?? null
);
const compatibleAllocationDocuments = computed(() => {
  const payment = selectedAllocationPayment.value;
  if (!payment) return [];
  const expectedType = payment.direction === 'encaissement'
    ? 'facture_client'
    : 'facture_fournisseur';
  return (workspace.value?.documents ?? []).filter((document) =>
    document.open_cents > 0
    && document.type === expectedType
    && document.contact_id === payment.contact_id
    && document.currency === payment.currency
    && ['emis', 'comptabilise'].includes(document.status)
  );
});
const commercialDraft = reactive({
  id: 0,
  version: 0,
  type: 'offre_client' as CommercialDocument['type'],
  contact_id: 0,
  document_date: today,
  valid_until: '',
  currency: context.context?.selection?.dossier.currency || 'CHF',
  external_number: '',
  source_document_id: null as number | null,
  header_text: '',
  footer_text: '',
  internal_note: '',
  lines: [newLine()] as DraftLine[]
});
const conversionDraft = reactive({
  source_document_id: 0,
  target_type: 'commande_client' as
    | 'reponse_offre_fournisseur'
    | 'commande_client'
    | 'commande_fournisseur'
    | 'facture_client'
    | 'facture_fournisseur',
  document_date: today,
  due_date: today,
  valid_until: '',
  collective_account_id: 0,
  external_number: '',
  line_accounts: [] as Array<{
    line_id: number;
    label: string;
    account_id: number;
  }>
});

const direction = computed<'sales' | 'purchases'>(() =>
  activeTab.value === 'achats' ? 'purchases' : 'sales'
);
const documentType = computed<'facture_client' | 'facture_fournisseur'>(() =>
  direction.value === 'sales' ? 'facture_client' : 'facture_fournisseur'
);
const documentContacts = computed(() =>
  (workspace.value?.contacts ?? []).filter((contact) =>
    contact.active
    && contact.roles.includes(direction.value === 'sales' ? 'client' : 'fournisseur')
  )
);
const activeCompanies = computed(() =>
  (workspace.value?.contacts ?? []).filter((contact) =>
    contact.active && contact.type === 'entreprise'
  )
);
const documentVatExempt = computed(() => {
  const date = documentDraft.document_date;
  return (workspace.value?.catalog.vat_regimes ?? []).some((regime) =>
    regime.status === 'non_assujetti'
    && regime.valid_from <= date
    && (regime.valid_until === null || regime.valid_until >= date)
  );
});
const documentVatInputEnabled = computed(() =>
  direction.value === 'purchases' || !documentVatExempt.value
);
const documentPaymentDefault = computed(() => {
  const date = documentDraft.document_date;
  const requestedDirection = direction.value === 'sales' ? 'client' : 'fournisseur';
  return (workspace.value?.catalog.payment_defaults ?? []).find((item) =>
    item.direction === requestedDirection
    && item.valid_from <= date
    && (item.valid_until === null || item.valid_until >= date)
  ) ?? null;
});
const commercialDirection = computed<'client' | 'fournisseur'>(() =>
  ['offre_client', 'commande_client'].includes(commercialDraft.type)
    ? 'client'
    : 'fournisseur'
);
const commercialContacts = computed(() =>
  (workspace.value?.contacts ?? []).filter((contact) =>
    contact.active && contact.roles.includes(commercialDirection.value)
  )
);
const commercialVatExempt = computed(() =>
  vatExemptAt(commercialDraft.document_date)
);
const commercialVatCodes = computed(() =>
  availableVatCodes(
    commercialDirection.value === 'client'
      ? 'facture_client'
      : 'facture_fournisseur',
    commercialDraft.document_date
  )
);
const commercialRows = computed(() =>
  (workspace.value?.commercial_documents ?? [])
    .filter((item) => activeTab.value === 'commandes'
      ? item.type.startsWith('commande_')
      : !item.type.startsWith('commande_'))
    .map((item) => ({
      ...item,
      display_number: item.numero || `Brouillon #${item.id}`,
      type_label: commercialTypeLabel(item.type),
      status_label: commercialStatusLabel(item),
      total: money(item.total_brut_centimes, item.monnaie),
      source: item.document_source_id
        ? commercialNumber(item.document_source_id)
        : 'Création directe'
    }))
);
const documentVatCodes = computed(() =>
  availableVatCodes(documentType.value, documentDraft.document_date)
);
const recurrenceVatCodes = computed(() =>
  availableVatCodes(recurrenceDraft.type, recurrenceDraft.next_date)
);
const recurrenceVatExempt = computed(() =>
  vatExemptAt(recurrenceDraft.next_date)
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
    status_label: documentStatusLabel(item),
    payment_label: paymentLabel(item.payment_state),
    accounting_label: item.reversal_entry_id
      ? 'Extournée'
      : (item.entry_id ? 'Comptabilisée' : 'Non comptabilisée')
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
const contactRows = computed(() => {
  const query = contactSearch.value.trim().toLocaleLowerCase('fr-CH');
  return (workspace.value?.contacts ?? []).filter((item) => {
    if (
      contactStatus.value !== 'all'
      && item.active !== (contactStatus.value === 'active')
    ) return false;
    return !query || [
      item.label, item.company_contact_name, item.email, item.phone, ...item.roles
    ].join(' ').toLocaleLowerCase('fr-CH').includes(query);
  }).map((item) => ({
    ...item,
    roles_label: item.roles.join(', '),
    receivable_cents: item.balance.receivable_cents,
    payable_cents: item.balance.payable_cents,
    net_cents: item.balance.net_cents,
    receivable: money(item.balance.receivable_cents),
    payable: money(item.balance.payable_cents),
    net: money(item.balance.net_cents)
  }));
});
const contactDocumentRows = computed(() =>
  (workspace.value?.contact_360?.documents ?? []).map((item) => ({
    ...item,
    display_number: item.number || `Brouillon #${item.id}`,
    type_label: item.type.includes('fournisseur') ? 'Achat' : 'Vente',
    total: dualMoney(
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
    status_label: statusLabel(item.status)
  }))
);
const contactPaymentRows = computed(() =>
  (workspace.value?.contact_360?.payments ?? []).map((item) => ({
    ...item,
    direction_label: item.direction === 'encaissement'
      ? 'Encaissement' : 'Décaissement',
    amount: money(item.amount_cents, item.currency),
    allocated: money(item.allocated_cents, item.currency),
    unallocated: money(item.unallocated_cents, item.currency)
  }))
);
const contactCommercialRows = computed(() =>
  (workspace.value?.contact_360?.commercial_documents ?? []).map((item) => ({
    ...item,
    display_number: item.numero || `Brouillon #${item.id}`,
    type_label: commercialTypeLabel(item.type),
    status_label: commercialStatusLabel(item),
    total: money(item.total_brut_centimes, item.monnaie)
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
  if (context.context?.selection) {
    if (typeof route.query.as_of_date === 'string') {
      store.filters.as_of_date = route.query.as_of_date;
    }
    await store.load();
    const requested = Number(route.query.document || 0);
    const document = workspace.value?.documents.find((item) => item.id === requested);
    if (document) {
      await nextTick();
      openDocument(document);
      return;
    }
    await openRequestedContact();
  }
});
watch(
  () => context.context?.selection?.dossier.id,
  async () => {
    store.clear();
    store.filters.contact_id = 0;
    if (context.context?.selection) await store.load();
  }
);
watch(
  () => [
    recurrenceDraft.next_date,
    recurrenceDraft.type,
    recurrenceVatExempt.value
  ],
  () => {
    if (!recurrenceVatExempt.value) return;
    recurrenceDraft.lines.forEach((line) => {
      line.vat_code_id = '';
      line.input_mode = recurrenceDraft.type === 'facture_fournisseur'
        ? 'brut'
        : 'net';
    });
  }
);
watch(activeTab, async () => {
  syncDirection();
  if (context.context?.selection) {
    await store.load();
    await openRequestedContact();
  }
});
watch(
  () => allocationDraft.payment_id,
  () => {
    allocationDraft.document_id = 0;
    allocationDraft.amount = '';
    if (compatibleAllocationDocuments.value.length === 1) {
      const document = compatibleAllocationDocuments.value[0];
      allocationDraft.document_id = document.id;
      allocationDraft.amount = inputMoney(Math.min(
        selectedAllocationPayment.value?.unallocated_cents ?? 0,
        document.open_cents
      ));
    }
  }
);
watch(
  () => allocationDraft.document_id,
  () => {
    const document = compatibleAllocationDocuments.value.find(
      (item) => item.id === Number(allocationDraft.document_id)
    );
    if (!document || !selectedAllocationPayment.value) return;
    allocationDraft.amount = inputMoney(Math.min(
      selectedAllocationPayment.value.unallocated_cents,
      document.open_cents
    ));
  }
);
watch(
  () => [
    documentDraft.document_date,
    documentType.value,
    documentPaymentDefault.value?.condition_id ?? 0,
    documentVatExempt.value
  ],
  () => {
    if (!editingDocument.value && documentPaymentDefault.value) {
      documentDraft.due_date = calculateDueDate(
        documentDraft.document_date,
        documentPaymentDefault.value.days,
        documentPaymentDefault.value.end_of_month
      );
    }
    if (documentVatExempt.value && direction.value === 'sales') {
      documentDraft.lines.forEach((line) => {
        line.vat_code_id = '';
        line.input_mode = 'net';
      });
    }
  }
);

function syncDirection(): void {
  store.filters.direction = ['sales', 'achats'].includes(activeTab.value)
    ? direction.value : 'all';
}

function calculateDueDate(date: string, days: number, endOfMonth: boolean): string {
  const due = new Date(`${date}T12:00:00Z`);
  if (Number.isNaN(due.getTime())) return date;
  due.setUTCDate(due.getUTCDate() + days);
  if (endOfMonth) {
    due.setUTCMonth(due.getUTCMonth() + 1, 0);
  }
  return due.toISOString().slice(0, 10);
}

function inputMoney(value: number): string {
  return (value / 100).toFixed(2);
}

function newLine(): DraftLine {
  return {
    label: '',
    quantity: '1',
    amount: '',
    input_mode: 'net',
    account_id: 0,
    vat_code_id: ''
  };
}

function quantityMilli(value: string): number {
  const normalized = value.trim().replace(',', '.');
  const match = normalized.match(/^(\d+)(?:\.(\d{0,3}))?$/);
  if (!match) {
    throw new Error('Quantité invalide : utilisez au plus trois décimales.');
  }
  const result = Number(match[1]) * 1000
    + Number((match[2] || '').padEnd(3, '0'));
  if (result < 1) throw new Error('La quantité doit être positive.');
  return result;
}

function quantityInput(value: number): string {
  const whole = Math.floor(value / 1000);
  const fraction = String(value % 1000).padStart(3, '0').replace(/0+$/, '');
  return fraction ? `${whole}.${fraction}` : String(whole);
}

function amountInput(centsValue: number): string {
  return `${Math.floor(Math.abs(centsValue) / 100)}.${String(
    Math.abs(centsValue) % 100
  ).padStart(2, '0')}`;
}

function resetDocumentDraft(): void {
  editingDocument.value = null;
  Object.assign(documentDraft, {
    contact_id: 0,
    document_date: today,
    due_date: today,
    external_number: '',
    collective_account_id: 0,
    currency: context.context?.selection?.dossier.currency || 'CHF',
    exchange_rate_id: 0,
    lines: [newLine()]
  });
  documentAttachment.value = null;
}

async function openNewDocument(): Promise<void> {
  resetDocumentDraft();
  if (documentPaymentDefault.value) {
    documentDraft.due_date = calculateDueDate(
      documentDraft.document_date,
      documentPaymentDefault.value.days,
      documentPaymentDefault.value.end_of_month
    );
  }
  if (documentVatExempt.value && direction.value === 'purchases') {
    documentDraft.lines[0].input_mode = 'brut';
  }
  documentDialog.value?.open();
}

async function editDocument(item: BillingDocument): Promise<void> {
  editingDocument.value = item;
  Object.assign(documentDraft, {
    contact_id: item.contact_id,
    document_date: item.document_date,
    due_date: item.due_date,
    external_number: item.external_number,
    collective_account_id: item.collective_account_id,
    currency: item.currency,
    exchange_rate_id: 0,
    lines: item.lines.map((line) => ({
      label: line.label,
      quantity: quantityInput(line.quantity_milli),
      amount: amountInput(line.unit_price_cents),
      input_mode: line.input_mode,
      account_id: line.account_id,
      vat_code_id: line.vat_code_id
    }))
  });
  documentAttachment.value = null;
  documentDialog.value?.open();
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

function vatExemptAt(date: string): boolean {
  return (workspace.value?.catalog.vat_regimes ?? []).some((regime) =>
    regime.status === 'non_assujetti'
    && regime.valid_from <= date
    && (regime.valid_until === null || regime.valid_until >= date)
  );
}

function commercialTypeLabel(type: CommercialDocument['type']): string {
  return {
    offre_client: 'Offre client',
    demande_offre_fournisseur: 'Demande d’offre fournisseur',
    reponse_offre_fournisseur: 'Offre fournisseur',
    commande_client: 'Commande client',
    commande_fournisseur: 'Commande fournisseur'
  }[type];
}

function commercialNumber(id: number): string {
  const document = workspace.value?.commercial_documents.find(
    (item) => item.id === id
  );
  return document?.numero || (document ? `Brouillon #${document.id}` : `#${id}`);
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

function plainMoney(value: number): string {
  const sign = value < 0 ? '−' : '';
  const absolute = Math.abs(value);
  return `${sign}${Math.floor(absolute / 100).toLocaleString('fr-CH')}.${
    String(absolute % 100).padStart(2, '0')
  }`;
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
    termine: 'Terminé',
    envoye: 'Envoyé',
    livre: 'Livré',
    recu: 'Reçu',
    accepte: 'Accepté',
    refuse: 'Refusé',
    remplace: 'Remplacé',
    commande: 'Commandé',
    facture: 'Facturé',
    archive: 'Archivé'
  }[status] || status;
}

function commercialStatusLabel(document: Pick<CommercialDocument, 'type' | 'statut'>): string {
  if (document.type.startsWith('commande_')) {
    const commandLabels: Partial<Record<CommercialDocument['statut'], string>> = {
      envoye: 'Envoyée',
      livre: 'Livrée',
      facture: 'Facturée',
      annule: 'Annulée',
      archive: 'Archivée'
    };
    return commandLabels[document.statut] || statusLabel(document.statut);
  }
  return statusLabel(document.statut);
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

function documentStatusLabel(document: BillingDocument): string {
  return document.reversal_entry_id ? 'Extournée' : statusLabel(document.status);
}

function financialTypeLabel(type: BillingDocument['type']): string {
  return {
    facture_client: 'Facture client',
    avoir_client: 'Avoir client',
    facture_fournisseur: 'Facture fournisseur',
    avoir_fournisseur: 'Avoir fournisseur'
  }[type];
}

function priceModeLabel(
  line: BillingDocument['lines'][number],
  document: BillingDocument
): string {
  if (line.input_mode !== 'brut') return 'Hors TVA';
  return document.direction === 'purchases'
    && (line.vat_code_id === 0
      || (line.vat_cents > 0 && line.deductible_vat_cents === 0))
    ? 'TVA comprise · non récupérable'
    : 'TVA comprise';
}

function apiLines(
  lines: DraftLine[],
  date: string,
  vatExempt = false
): Array<Record<string, unknown>> {
  return lines.map((line) => ({
    label: line.label,
    quantity_milli: quantityMilli(line.quantity),
    unit_price_cents: cents(line.amount),
    input_mode: line.input_mode,
    account_id: Number(line.account_id) || null,
    vat_code_id: vatExempt
      ? null
      : requiredPositiveId(line.vat_code_id, 'Sélectionnez un code TVA.'),
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
    if (documentVatInputEnabled.value && documentVatCodes.value.length === 0) {
      throw new Error(
        'Aucun code TVA applicable. Configurez les codes TVA dans Configuration > Référentiels.'
      );
    }
    const payload = {
      type: documentType.value,
      contact_id: Number(documentDraft.contact_id),
      document_date: documentDraft.document_date,
      due_date: documentDraft.due_date,
      collective_account_id: Number(documentDraft.collective_account_id),
      currency: documentDraft.currency,
      exchange_rate_id: documentDraft.exchange_rate_id || null,
      external_number: documentDraft.external_number,
      attachment: documentAttachment.value,
      lines: apiLines(
        documentDraft.lines,
        documentDraft.document_date,
        documentVatExempt.value && direction.value === 'sales'
      )
    };
    const wasEditing = editingDocument.value !== null;
    if (editingDocument.value) {
      await store.mutate('/facturation/documents/modifier', {
        ...payload,
        document_id: editingDocument.value.id,
        version: editingDocument.value.version
      });
    } else {
      await store.mutate('/facturation/documents', payload);
    }
    documentDialog.value?.close();
    documentAttachment.value = null;
    notifications.push(
      wasEditing
        ? 'Brouillon mis à jour.'
        : 'Document enregistré comme brouillon, sans numéro.',
      'success'
    );
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
  } catch (error) {
    notifications.push(
      store.error || (error instanceof Error ? error.message : 'Impossible de poursuivre.'),
      'warning'
    );
  }
}

async function postDocument(item: BillingDocument): Promise<void> {
  const exercise = workspace.value?.catalog.exercises[0];
  const journal = preferredDocumentJournal(item.type);
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

function preferredDocumentJournal(type: BillingDocument['type']) {
  const journals = workspace.value?.catalog.journals ?? [];
  const expectedType = type.includes('fournisseur') ? 'achats' : 'ventes';
  return journals.find((journal) => journal.type === expectedType)
    ?? journals.find((journal) => journal.type === 'general')
    ?? journals[0];
}

function preferredPaymentJournal() {
  const journals = workspace.value?.catalog.journals ?? [];
  return journals.find((journal) => journal.type === 'banque')
    ?? journals.find((journal) => journal.type === 'caisse')
    ?? journals.find((journal) => journal.type === 'general')
    ?? journals[0];
}

async function createCredit(item: BillingDocument): Promise<void> {
  try {
    await store.mutate('/facturation/documents/avoirs', {
      document_id: item.id,
      date: today
    });
    notifications.push('Brouillon d’avoir créé. Il devra être émis puis comptabilisé.', 'success');
  } catch (error) {
    notifications.push(
      store.error || (error instanceof Error ? error.message : 'Impossible de poursuivre.'),
      'warning'
    );
  }
}

function openReversal(item: BillingDocument): void {
  reversingDocument.value = item;
  reversalDraft.date = item.document_date;
  reversalDialog.value?.open();
}

async function reverseInvoice(): Promise<void> {
  const item = reversingDocument.value;
  if (!item) return;
  try {
    await store.mutate('/facturation/documents/extourner', {
      document_id: item.id,
      version: item.version,
      date: reversalDraft.date
    });
    reversalDialog.value?.close();
    reversingDocument.value = null;
    notifications.push(
      'Facture extournée : seul le solde restant a été contre-passé et la facture est soldée.',
      'success'
    );
  } catch (error) {
    notifications.push(
      store.error || (error instanceof Error ? error.message : 'Extourne impossible.'),
      'warning'
    );
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
      { document_id: item.id },
      false
    );
    downloadBase64Pdf(result.filename, result.content_base64);
    notifications.push(
      result.warning || 'PDF téléchargé.',
      result.warning ? 'warning' : 'success'
    );
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function downloadCommercialPdf(item: CommercialDocument): Promise<void> {
  try {
    const result = await store.mutate<{
      filename: string;
      content_base64: string;
    }>('/facturation/commerciaux/pdf', { document_id: item.id }, false);
    downloadBase64Pdf(result.filename, result.content_base64);
    notifications.push('PDF du document commercial téléchargé.', 'success');
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function downloadPaymentPdf(item: BillingPayment): Promise<void> {
  try {
    const result = await store.mutate<{
      filename: string;
      content_base64: string;
    }>('/facturation/paiements/pdf', { payment_id: item.id }, false);
    downloadBase64Pdf(result.filename, result.content_base64);
    notifications.push('Justificatif de paiement téléchargé.', 'success');
  } catch {
    notifications.push(store.error, 'warning');
  }
}

function downloadBase64Pdf(filename: string, content: string): void {
  const bytes = Uint8Array.from(
    atob(content),
    (character) => character.charCodeAt(0)
  );
  const url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

function resetContactDraft(): void {
  Object.assign(contactDraft, {
    id: 0,
    version: 0,
    type: 'entreprise',
    company_contact_id: null,
    company: '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    iban: '',
    bic: '',
    language: 'fr',
    client: true,
    supplier: false,
    line1: '',
    line2: '',
    postal_code: '',
    city: '',
    country: 'CH'
  });
}

function openContactEditor(): void {
  resetContactDraft();
  contactEditorDialog.value?.open();
}

function editContact(contactId: number): void {
  const contact = workspace.value?.contacts.find((item) => item.id === contactId);
  if (!contact || !contact.active) return;
  Object.assign(contactDraft, {
    id: contact.id,
    version: contact.version,
    type: contact.type,
    company_contact_id: contact.company_contact_id,
    company: contact.company,
    first_name: contact.first_name,
    last_name: contact.last_name,
    email: contact.email,
    phone: contact.phone,
    iban: contact.iban,
    bic: contact.bic,
    language: contact.language,
    client: contact.roles.includes('client'),
    supplier: contact.roles.includes('fournisseur'),
    line1: contact.address.line1,
    line2: contact.address.line2,
    postal_code: contact.address.postal_code,
    city: contact.address.city,
    country: contact.address.country
  });
  contactEditorDialog.value?.open();
}

async function saveContact(): Promise<void> {
  const roles = [
    contactDraft.client ? 'client' : '',
    contactDraft.supplier ? 'fournisseur' : ''
  ].filter(Boolean);
  try {
    const payload = {
      type: contactDraft.type,
      company_contact_id: contactDraft.type === 'personne'
        ? contactDraft.company_contact_id
        : null,
      company: contactDraft.company,
      first_name: contactDraft.first_name,
      last_name: contactDraft.last_name,
      email: contactDraft.email,
      phone: contactDraft.phone,
      iban: contactDraft.iban,
      bic: contactDraft.bic,
      language: contactDraft.language,
      roles,
      address: {
        line1: contactDraft.line1,
        line2: contactDraft.line2,
        postal_code: contactDraft.postal_code,
        city: contactDraft.city,
        country: contactDraft.country
      }
    };
    if (contactDraft.id > 0) {
      await store.mutate('/facturation/contacts/modifier', {
        ...payload,
        contact_id: contactDraft.id,
        version: contactDraft.version
      });
    } else {
      await store.mutate('/facturation/contacts', {
        ...payload,
        idempotency_key: crypto.randomUUID()
      });
    }
    contactEditorDialog.value?.close();
    notifications.push('Contact enregistré dans le registre unique.', 'success');
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function openContactDialog(id: number): Promise<void> {
  store.filters.contact_id = id;
  await store.load();
  await nextTick();
  contact360Dialog.value?.open();
}

async function openRequestedContact(): Promise<void> {
  if (activeTab.value !== 'contacts') return;
  const id = Number(route.query.contact || 0);
  if (id > 0) await openContactDialog(id);
}

async function selectContact(id: number): Promise<void> {
  if (activeTab.value !== 'contacts') {
    await router.push({
      path: '/facturation/contacts',
      query: { contact: String(id) }
    });
    return;
  }
  await openContactDialog(id);
}

async function removeContact(contactId?: number): Promise<void> {
  if (contactId) {
    pendingContactRemoval.value = contactId;
    await removeContactDialog.value?.open();
    return;
  }
  const contact = workspace.value?.contacts.find(
    (item) => item.id === pendingContactRemoval.value
  );
  if (!contact) return;
  try {
    await store.mutate('/facturation/contacts/supprimer', {
      contact_id: contact.id,
      version: contact.version
    });
    pendingContactRemoval.value = 0;
    notifications.push(
      'Contact supprimé s’il était inutilisé, sinon archivé avec son historique.',
      'success'
    );
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function restoreContact(contactId: number): Promise<void> {
  const contact = workspace.value?.contacts.find((item) => item.id === contactId);
  if (!contact) return;
  try {
    await store.mutate('/facturation/contacts/reactiver', {
      contact_id: contact.id,
      version: contact.version
    });
    notifications.push('Contact désarchivé et à nouveau disponible.', 'success');
  } catch {
    notifications.push(store.error, 'warning');
  }
}

function viewDocument(document: BillingDocument): void {
  viewedDocument.value = document;
  financialViewerDialog.value?.open();
}

function openDocument(document: BillingDocument): void {
  if (document.status === 'brouillon') {
    editDocument(document);
    return;
  }
  viewDocument(document);
}

function viewCommercial(document: CommercialDocument): void {
  viewedCommercial.value = document;
  commercialViewerDialog.value?.open();
}

function openCommercial(document: CommercialDocument): void {
  if (document.statut === 'brouillon') {
    editCommercial(document);
    return;
  }
  viewCommercial(document);
}

async function openContactFromDocument(contactId: number): Promise<void> {
  financialViewerDialog.value?.close();
  commercialViewerDialog.value?.close();
  await selectContact(contactId);
}

function printCommercial(): void {
  document.body.classList.add('printing-commercial-document');
  const cleanup = () => {
    document.body.classList.remove('printing-commercial-document');
    window.removeEventListener('afterprint', cleanup);
  };
  window.addEventListener('afterprint', cleanup);
  window.print();
  window.setTimeout(cleanup, 1000);
}

async function clearContact(): Promise<void> {
  store.filters.contact_id = 0;
  await store.load();
  if (route.query.contact) {
    await router.replace({ path: '/facturation/contacts' });
  }
}

function resetCommercialDraft(type?: CommercialDocument['type']): void {
  const defaultType = type ?? (
    activeTab.value === 'commandes' ? 'commande_client' : 'offre_client'
  );
  Object.assign(commercialDraft, {
    id: 0,
    version: 0,
    type: defaultType,
    contact_id: 0,
    document_date: today,
    valid_until: '',
    currency: context.context?.selection?.dossier.currency || 'CHF',
    external_number: '',
    source_document_id: null,
    header_text: '',
    footer_text: '',
    internal_note: '',
    lines: [newLine()]
  });
  if (vatExemptAt(today) && !['offre_client', 'commande_client'].includes(defaultType)) {
    commercialDraft.lines[0].input_mode = 'brut';
  }
}

function openCommercialEditor(type?: CommercialDocument['type']): void {
  resetCommercialDraft(type);
  commercialDialog.value?.open();
}

function editCommercial(document: CommercialDocument): void {
  if (document.statut !== 'brouillon') return;
  Object.assign(commercialDraft, {
    id: document.id,
    version: document.version,
    type: document.type,
    contact_id: document.contact_id,
    document_date: document.date_document,
    valid_until: document.date_validite || '',
    currency: document.monnaie,
    external_number: document.numero_externe,
    source_document_id: document.document_source_id,
    header_text: document.texte_entete,
    footer_text: document.texte_pied,
    internal_note: document.note_interne,
    lines: document.lines.map((line) => ({
      label: line.libelle,
      quantity: quantityInput(line.quantite_milli),
      amount: amountInput(line.prix_unitaire_centimes),
      input_mode: line.mode_saisie,
      account_id: line.compte_id || 0,
      vat_code_id: line.code_tva_id || ''
    }))
  });
  commercialDialog.value?.open();
}

async function saveCommercial(): Promise<void> {
  try {
    await store.mutate('/facturation/commerciaux', {
      id: commercialDraft.id || null,
      version: commercialDraft.version || null,
      type: commercialDraft.type,
      contact_id: Number(commercialDraft.contact_id),
      document_date: commercialDraft.document_date,
      valid_until: commercialDraft.valid_until,
      currency: commercialDraft.currency,
      external_number: commercialDraft.external_number,
      source_document_id: commercialDraft.source_document_id,
      header_text: commercialDraft.header_text,
      footer_text: commercialDraft.footer_text,
      internal_note: commercialDraft.internal_note,
      lines: apiLines(
        commercialDraft.lines,
        commercialDraft.document_date,
        commercialVatExempt.value
      )
    });
    commercialDialog.value?.close();
    notifications.push('Document commercial enregistré en brouillon.', 'success');
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function setCommercialStatus(
  document: CommercialDocument,
  status: CommercialDocument['statut']
): Promise<void> {
  try {
    await store.mutate('/facturation/commerciaux/statut', {
      document_id: document.id,
      version: document.version,
      status
    });
    notifications.push(
      `Document marqué « ${commercialStatusLabel({ ...document, statut: status })} ».`,
      'success'
    );
  } catch {
    notifications.push(store.error, 'warning');
  }
}

function paymentDefaultFor(
  directionValue: 'client' | 'fournisseur',
  date: string
) {
  return (workspace.value?.catalog.payment_defaults ?? []).find((item) =>
    item.direction === directionValue
    && item.valid_from <= date
    && (item.valid_until === null || item.valid_until >= date)
  ) ?? null;
}

function openCommercialConversion(
  document: CommercialDocument,
  targetType: typeof conversionDraft.target_type
): void {
  const directionValue = targetType === 'facture_fournisseur'
    ? 'fournisseur'
    : 'client';
  const paymentDefault = paymentDefaultFor(directionValue, today);
  Object.assign(conversionDraft, {
    source_document_id: document.id,
    target_type: targetType,
    document_date: today,
    due_date: paymentDefault
      ? calculateDueDate(today, paymentDefault.days, paymentDefault.end_of_month)
      : today,
    valid_until: '',
    collective_account_id: 0,
    external_number: '',
    line_accounts: document.lines.map((line) => ({
      line_id: line.id,
      label: line.libelle,
      account_id: line.compte_id || 0
    }))
  });
  conversionDialog.value?.open();
}

async function convertCommercial(): Promise<void> {
  try {
    const targetType = conversionDraft.target_type;
    await store.mutate('/facturation/commerciaux/convertir', {
      ...conversionDraft,
      collective_account_id: targetType.startsWith('facture_')
        ? Number(conversionDraft.collective_account_id)
        : null,
      due_date: targetType.startsWith('facture_')
        ? conversionDraft.due_date
        : '',
      external_number: conversionDraft.external_number
    });
    conversionDialog.value?.close();
    notifications.push(
      targetType.startsWith('facture_')
        ? 'Brouillon de facture créé et relié au document d’origine.'
        : 'Nouveau document commercial relié à son origine.',
      'success'
    );
    await router.push(targetType === 'facture_client'
      ? '/facturation/ventes'
      : targetType === 'facture_fournisseur'
        ? '/facturation/achats'
        : targetType === 'reponse_offre_fournisseur'
          ? '/facturation/offres'
          : '/facturation/commandes');
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function saveRecurrence(): Promise<void> {
  try {
    if (!recurrenceVatExempt.value && recurrenceVatCodes.value.length === 0) {
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
      lines: apiLines(
        recurrenceDraft.lines,
        recurrenceDraft.next_date,
        recurrenceVatExempt.value
      )
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
    reminderDialog.value?.close();
    notifications.push('Rappel tracé dans l’historique.', 'success');
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function savePayment(): Promise<void> {
  const treasuryAccount = workspace.value?.catalog.treasury_accounts.find(
    (account) => account.id === Number(paymentDraft.treasury_account_id)
  );
  if (!treasuryAccount) return;
  try {
    await store.mutate('/facturation/paiements', {
      contact_id: Number(paymentDraft.contact_id),
      direction: paymentDraft.direction,
      date: paymentDraft.date,
      amount_cents: cents(paymentDraft.amount),
      reference: paymentDraft.reference,
      ledger_account_id: treasuryAccount.ledger_account_id,
      treasury_account_id: treasuryAccount.id,
      currency: treasuryAccount.currency,
      exchange_rate_id: paymentDraft.exchange_rate_id || null
    });
    paymentDraft.amount = '';
    paymentDraft.reference = '';
    paymentDialog.value?.close();
    notifications.push('Paiement saisi indépendamment des factures.', 'success');
  } catch {
    notifications.push(store.error, 'warning');
  }
}

async function allocatePayment(): Promise<void> {
  try {
    const paymentId = Number(allocationDraft.payment_id);
    const documentId = Number(allocationDraft.document_id);
    const amountCents = cents(allocationDraft.amount);
    const payment = workspace.value?.payments.find((item) => item.id === paymentId);
    const document = workspace.value?.documents.find((item) => item.id === documentId);
    const fullyAllocated = payment?.unallocated_cents === amountCents;
    await store.mutate('/facturation/allocations', {
      payment_id: paymentId,
      document_id: documentId,
      amount_cents: amountCents
    });
    let accountingCompleted = false;
    if (fullyAllocated && document && document.status === 'emis') {
      if (!workspace.value?.capabilities.post) {
        throw new Error(
          'Le paiement est lettré. Une personne autorisée doit comptabiliser la facture avant le paiement.'
        );
      }
      await postDocumentForSettlement(document);
    }
    if (
      fullyAllocated
      && document
      && ['emis', 'comptabilise'].includes(document.status)
    ) {
      await postPayment(paymentId, document.collective_account_id);
      accountingCompleted = true;
    }
    allocationDraft.amount = '';
    allocationDialog.value?.close();
    notifications.push(
      accountingCompleted
        ? 'Paiement intégralement lettré et comptabilisé.'
        : 'Paiement partiellement alloué ; il sera comptabilisé au lettrage complet.',
      'success'
    );
  } catch (error) {
    notifications.push(
      store.error || (error instanceof Error ? error.message : 'Impossible de poursuivre.'),
      'warning'
    );
  }
}

async function postDocumentForSettlement(document: BillingDocument): Promise<void> {
  const exercise = workspace.value?.catalog.exercises[0];
  const journal = preferredDocumentJournal(document.type);
  if (!exercise || !journal) {
    throw new Error('Configurez un exercice ouvert et un journal.');
  }
  await store.mutate('/facturation/documents/comptabiliser', {
    document_id: document.id,
    exercise_id: exercise.id,
    journal_id: journal.id
  });
}

async function postPayment(
  paymentId: number,
  collectiveAccountId: number
): Promise<void> {
  const exercise = workspace.value?.catalog.exercises[0];
  const journal = preferredPaymentJournal();
  if (!exercise || !journal) {
    throw new Error('Configurez un exercice ouvert et un journal.');
  }
  await store.mutate('/facturation/paiements/comptabiliser', {
    payment_id: paymentId,
    collective_account_id: collectiveAccountId,
    exercise_id: exercise.id,
    journal_id: journal.id
  });
}
</script>

<template>
  <section class="page-stack">
    <CompactTabs :items="subNavigation.billing" label="Navigation de la facturation">
      <template #actions>
        <a v-if="workspace" class="button secondary small" :href="exportUrl">
          Exporter la vue CSV
        </a>
      </template>
    </CompactTabs>

    <form
      v-if="['sales', 'achats', 'echeancier'].includes(activeTab)"
      class="filter-bar"
      @submit.prevent="applyFilters"
    >
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

    <p
      v-if="workspace && ['sales', 'achats', 'echeancier'].includes(activeTab)"
      class="reference-note"
    >
      Situation calculée au <strong>{{ workspace.reference_date }}</strong>.
      Les paiements non alloués sont présentés séparément des tranches d’âge.
    </p>
    <ErrorSummary v-if="store.error" :message="store.error" />
    <SkeletonBlock v-if="store.loading && !workspace" :lines="8" />

    <template v-else-if="workspace && ['sales', 'achats'].includes(activeTab)">
      <div class="toolbar">
        <div>
          <p>Le numéro n’est attribué qu’à l’émission ; le brouillon reste modifiable.</p>
        </div>
        <button
          v-if="workspace.capabilities.manage"
          class="button primary"
          type="button"
          :disabled="!documentVatExempt && documentVatCodes.length === 0"
          @click="openNewDocument"
        >Nouveau document</button>
      </div>

      <div v-if="!documentVatExempt && documentVatCodes.length === 0" class="notice warning" role="alert">
        Aucun code TVA actif et compatible ne couvre la date du document.
        <RouterLink to="/configuration/referentiels">Configurer les codes TVA</RouterLink>.
      </div>

      <ModalDialog
        ref="documentDialog"
        :title="editingDocument
          ? `Modifier le brouillon #${editingDocument.id}`
          : direction === 'sales'
            ? 'Nouvelle facture client'
            : 'Nouvelle facture fournisseur'"
        description="Renseignez l’en-tête puis ventilez la facture sur une ou plusieurs lignes."
        wide
        @closed="resetDocumentDraft"
      >
      <form class="invoice-form" @submit.prevent="saveDocument">
        <div class="form-grid invoice-header-grid">
          <FormField id="billing-contact" :label="direction === 'sales' ? 'Client' : 'Fournisseur'">
            <template #default="{ describedBy }">
              <AccountCombobox
                id="billing-contact"
                v-model="documentDraft.contact_id"
                :options="documentContacts"
                number-key="company_contact_name"
                label-key="label"
                placeholder="Rechercher par entreprise, prénom ou nom…"
                :aria-describedby="describedBy"
                required
              />
            </template>
          </FormField>
          <FormField v-if="direction === 'purchases'" id="billing-external" label="Référence fournisseur">
            <template #default="{ describedBy }"><input id="billing-external" v-model="documentDraft.external_number" :aria-describedby="describedBy" required></template>
          </FormField>
          <FormField id="billing-date" label="Date du document">
            <template #default="{ describedBy }"><input id="billing-date" v-model="documentDraft.document_date" type="date" :aria-describedby="describedBy" required></template>
          </FormField>
          <FormField
            id="billing-due"
            label="Échéance explicite"
            :hint="documentPaymentDefault
              ? `${documentPaymentDefault.label} · ${documentPaymentDefault.days} jour(s)${documentPaymentDefault.end_of_month ? ' · fin de mois' : ''}`
              : 'Aucune condition par défaut ne couvre cette date.'"
          >
            <template #default="{ describedBy }"><input id="billing-due" v-model="documentDraft.due_date" type="date" :aria-describedby="describedBy" required></template>
          </FormField>
          <FormField
            id="billing-collective"
            label="Compte de paiement"
            :hint="direction === 'sales'
              ? 'Compte débiteur utilisé lors de la comptabilisation.'
              : 'Compte créancier utilisé lors de la comptabilisation.'"
          >
            <template #default="{ describedBy }">
              <AccountCombobox
                id="billing-collective"
                v-model="documentDraft.collective_account_id"
                :options="workspace.catalog.accounts"
                :aria-describedby="describedBy"
                required
              />
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
        <div v-if="!documentPaymentDefault" class="notice warning" role="alert">
          Aucune condition de paiement par défaut ne couvre le {{ documentDraft.document_date }}.
          L’échéance reste saisissable manuellement.
          <RouterLink to="/configuration/paiements">Créer ou définir une condition</RouterLink>.
        </div>
        <div v-if="documentVatExempt" class="notice info">
          Dossier non assujetti.
          <span v-if="direction === 'sales'">Aucune TVA n’est calculée sur les ventes.</span>
          <span v-if="direction === 'purchases'">
            La TVA indiquée par le fournisseur peut être saisie en montant net ou brut ; elle reste non récupérable et entièrement comptabilisée en charge.
          </span>
        </div>
        <div class="invoice-lines-heading">
          <div><h3>Lignes de facture</h3><p>Le montant peut être saisi net ou brut selon la ligne.</p></div>
          <button class="button secondary small" type="button" @click="documentDraft.lines.push(newLine())">Ajouter une ligne</button>
        </div>
        <fieldset v-for="(line, index) in documentDraft.lines" :key="index" class="invoice-line">
          <legend>Ligne {{ index + 1 }}</legend>
          <div class="invoice-line-grid">
            <FormField :id="`billing-line-label-${index}`" label="Libellé">
              <template #default="{ describedBy }"><input :id="`billing-line-label-${index}`" v-model="line.label" :aria-describedby="describedBy" placeholder="Prestation ou article" required></template>
            </FormField>
            <FormField :id="`billing-line-account-${index}`" label="Compte">
              <template #default="{ describedBy }">
                <AccountCombobox
                  :id="`billing-line-account-${index}`"
                  v-model="line.account_id"
                  :options="workspace.catalog.accounts"
                  :aria-describedby="describedBy"
                  placeholder="Rechercher un compte"
                  required
                />
              </template>
            </FormField>
            <FormField :id="`billing-line-quantity-${index}`" label="Quantité">
              <template #default="{ describedBy }"><input :id="`billing-line-quantity-${index}`" v-model="line.quantity" inputmode="decimal" :aria-describedby="describedBy" required></template>
            </FormField>
            <FormField :id="`billing-line-amount-${index}`" label="Prix unitaire">
              <template #default="{ describedBy }"><input :id="`billing-line-amount-${index}`" v-model="line.amount" inputmode="decimal" :aria-describedby="describedBy" placeholder="0.00" required></template>
            </FormField>
            <FormField v-if="documentVatInputEnabled" :id="`billing-line-vat-${index}`" label="Code TVA">
              <template #default="{ describedBy }">
                <select :id="`billing-line-vat-${index}`" v-model.number="line.vat_code_id" :aria-describedby="describedBy" required>
                  <option value="" disabled>Sélectionner</option>
                  <option v-for="vat in documentVatCodes" :key="vat.id" :value="vat.id">{{ vat.code }} · {{ vat.label }}</option>
                </select>
              </template>
            </FormField>
            <FormField v-if="documentVatInputEnabled" :id="`billing-line-mode-${index}`" label="Saisie">
              <template #default="{ describedBy }">
                <select :id="`billing-line-mode-${index}`" v-model="line.input_mode" :aria-describedby="describedBy">
                  <option value="net">Montant net</option>
                  <option value="brut">Montant brut</option>
                </select>
              </template>
            </FormField>
            <button v-if="documentDraft.lines.length > 1" class="button ghost small remove-line" type="button" @click="documentDraft.lines.splice(index, 1)">Retirer</button>
          </div>
        </fieldset>
        <div class="dialog-actions">
          <button class="button primary" :disabled="store.saving">
            {{ editingDocument ? 'Enregistrer les modifications' : 'Enregistrer le brouillon' }}
          </button>
        </div>
      </form>
      </ModalDialog>

      <DataTable
        v-if="documentRows.length"
        sortable
        :caption="direction === 'sales' ? 'Documents clients' : 'Documents fournisseurs'"
        :columns="[
          { key: 'display_number', label: 'Document' },
          { key: 'contact', label: 'Contact' },
          { key: 'document_date', label: 'Date' },
          { key: 'due_date', label: 'Échéance' },
          { key: 'amount', label: 'Total', sortKey: 'gross_cents', type: 'number' },
          { key: 'open', label: 'Ouvert', sortKey: 'open_cents', type: 'number' },
          { key: 'payment_label', label: 'Paiement' },
          { key: 'accounting_label', label: 'Comptabilité' },
          { key: 'actions', label: 'Actions' }
        ]"
        :rows="documentRows"
      >
        <template #cell-display_number="{ row }">
          <button
            class="table-primary-link"
            type="button"
            @click="openDocument(row as BillingDocument)"
          >{{ row.display_number }}</button>
        </template>
        <template #cell-contact="{ row }">
          <button
            class="table-secondary-link"
            type="button"
            @click="selectContact(Number(row.contact_id))"
          >{{ row.contact }}</button>
        </template>
        <template #cell-accounting_label="{ row }">
          <span :class="['status-chip', row.reversal_entry_id ? 'warning' : (row.entry_id ? 'ok' : 'warning')]">
            {{ row.accounting_label }}
          </span>
        </template>
        <template #cell-actions="{ row }">
          <ActionMenu :label="`Actions pour ${row.display_number}`">
            <button type="button" @click="viewDocument(row as BillingDocument)">Consulter</button>
            <button v-if="row.status === 'brouillon' && workspace.capabilities.manage" type="button" @click="editDocument(row as BillingDocument)">Modifier</button>
            <button v-if="row.status === 'brouillon' && workspace.capabilities.issue" type="button" @click="issueDocument(row as BillingDocument)">Émettre</button>
            <button v-if="row.status === 'emis' && workspace.capabilities.post" type="button" @click="postDocument(row as BillingDocument)">Comptabiliser</button>
            <button v-if="['emis', 'comptabilise'].includes(String(row.status)) && String(row.type).startsWith('facture_') && workspace.capabilities.manage" type="button" @click="createCredit(row as BillingDocument)">Créer un avoir</button>
            <button v-if="row.status === 'comptabilise' && String(row.type).startsWith('facture_') && Number(row.open_cents) > 0 && !row.reversal_entry_id && workspace.capabilities.post" class="danger" type="button" @click="openReversal(row as BillingDocument)">Extourner</button>
            <button v-if="['emis', 'comptabilise', 'annule'].includes(String(row.status)) && workspace.capabilities.issue" type="button" @click="downloadPdf(row as BillingDocument)">PDF</button>
          </ActionMenu>
        </template>
      </DataTable>
      <EmptyState v-else title="Aucun document" description="Aucun document ne correspond aux filtres et à la date de référence." />
      <ModalDialog
        ref="reversalDialog"
        title="Extourner la facture"
        :description="reversingDocument ? `Contre-passation du solde ouvert de ${money(reversingDocument.open_cents, reversingDocument.currency)} sur ${reversingDocument.number}.` : ''"
        @closed="reversingDocument = null"
      >
        <form v-if="reversingDocument" class="modal-form-grid" @submit.prevent="reverseInvoice">
          <div class="notice warning full-row">
            Les lettrages existants seront conservés. Seul le montant encore ouvert sera
            contre-passé, avec sa part de TVA, puis la facture sera considérée comme soldée.
          </div>
          <FormField id="invoice-reversal-date" label="Date de l’extourne">
            <template #default="{ describedBy }">
              <input
                id="invoice-reversal-date"
                v-model="reversalDraft.date"
                type="date"
                :min="reversingDocument.document_date"
                :aria-describedby="describedBy"
                required
              >
            </template>
          </FormField>
          <div class="dialog-actions full-row">
            <button class="button secondary" type="button" @click="reversalDialog?.close()">
              Annuler
            </button>
            <button class="button danger" :disabled="store.saving">
              Confirmer l’extourne
            </button>
          </div>
        </form>
      </ModalDialog>
      <ModalDialog
        ref="financialViewerDialog"
        :title="viewedDocument?.number || `Brouillon #${viewedDocument?.id || ''}`"
        description="Pièce financière du dossier, consultable quel que soit son statut."
        extra-wide
        @closed="viewedDocument = null"
      >
        <article v-if="viewedDocument" class="financial-document-sheet">
          <header class="financial-document-hero">
            <div class="financial-document-identity">
              <p class="eyebrow">{{ financialTypeLabel(viewedDocument.type) }}</p>
              <h2>{{ viewedDocument.number || `Brouillon #${viewedDocument.id}` }}</h2>
              <button
                class="financial-contact-link"
                type="button"
                @click="openContactFromDocument(viewedDocument.contact_id)"
              >{{ viewedDocument.contact }}</button>
              <span v-if="viewedDocument.external_number" class="financial-external-reference">
                Référence fournisseur : <strong>{{ viewedDocument.external_number }}</strong>
              </span>
            </div>
            <div class="financial-document-state">
              <span class="status-chip">{{ documentStatusLabel(viewedDocument) }}</span>
              <span :class="['status-chip', viewedDocument.reversal_entry_id ? 'warning' : (viewedDocument.entry_id ? 'ok' : 'warning')]">
                {{ viewedDocument.reversal_entry_id ? 'Extournée' : (viewedDocument.entry_id ? 'Comptabilisée' : 'Non comptabilisée') }}
              </span>
            </div>
          </header>

          <div class="financial-document-overview">
            <dl class="financial-document-dates">
              <div><dt>Date du document</dt><dd>{{ viewedDocument.document_date }}</dd></div>
              <div><dt>Échéance</dt><dd>{{ viewedDocument.due_date }}</dd></div>
              <div><dt>État du paiement</dt><dd>{{ paymentLabel(viewedDocument.payment_state) }}</dd></div>
              <div><dt>Devise</dt><dd>{{ viewedDocument.currency }}</dd></div>
            </dl>
            <aside class="financial-amount-card" aria-label="Résumé financier">
              <span>Total de la pièce</span>
              <strong>{{ money(viewedDocument.gross_cents, viewedDocument.currency) }}</strong>
              <dl>
                <div>
                  <dt>Déjà lettré</dt>
                  <dd>{{ money(viewedDocument.allocated_cents, viewedDocument.currency) }}</dd>
                </div>
                <div class="financial-open-amount">
                  <dt>Solde ouvert</dt>
                  <dd>{{ money(viewedDocument.open_cents, viewedDocument.currency) }}</dd>
                </div>
              </dl>
            </aside>
          </div>

          <div class="table-wrap financial-lines-table">
            <table>
              <thead>
                <tr>
                  <th>Référence</th>
                  <th>Quantité</th>
                  <th>Prix unitaire</th>
                  <th>Mode du prix</th>
                  <th>Net</th>
                  <th>TVA</th>
                  <th>Total</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="line in viewedDocument.lines" :key="line.id">
                  <td><strong>{{ line.label }}</strong></td>
                  <td>{{ quantityInput(line.quantity_milli) }}</td>
                  <td>{{ money(line.unit_price_cents, viewedDocument.currency) }}</td>
                  <td>{{ priceModeLabel(line, viewedDocument) }}</td>
                  <td>{{ money(line.net_cents, viewedDocument.currency) }}</td>
                  <td>{{ money(line.vat_cents, viewedDocument.currency) }}</td>
                  <td><strong>{{ money(line.gross_cents, viewedDocument.currency) }}</strong></td>
                </tr>
              </tbody>
              <tfoot>
                <tr>
                  <th colspan="4">Totaux</th>
                  <th>{{ money(viewedDocument.net_cents, viewedDocument.currency) }}</th>
                  <th>{{ money(viewedDocument.vat_cents, viewedDocument.currency) }}</th>
                  <th>{{ money(viewedDocument.gross_cents, viewedDocument.currency) }}</th>
                </tr>
              </tfoot>
            </table>
          </div>

          <dl class="financial-document-traceability">
            <div v-if="viewedDocument.entry_id">
              <dt>Écriture comptable</dt>
              <dd>#{{ viewedDocument.entry_id }}</dd>
            </div>
            <div v-if="viewedDocument.reversal_entry_id">
              <dt>Écriture d’extourne</dt>
              <dd>#{{ viewedDocument.reversal_entry_id }}</dd>
            </div>
            <div v-if="viewedDocument.scor_reference">
              <dt>Référence de paiement</dt>
              <dd>{{ viewedDocument.scor_reference }}</dd>
            </div>
            <div v-if="viewedDocument.currency !== viewedDocument.base_currency">
              <dt>Contre-valeur</dt>
              <dd>{{ money(viewedDocument.gross_base_cents, viewedDocument.base_currency) }}</dd>
            </div>
            <div v-if="viewedDocument.exchange_rate.source">
              <dt>Taux de change</dt>
              <dd>
                {{ viewedDocument.exchange_rate.date }} ·
                {{ viewedDocument.exchange_rate.numerator }}/{{ viewedDocument.exchange_rate.denominator }} ·
                {{ viewedDocument.exchange_rate.source }}
              </dd>
            </div>
          </dl>

          <div class="dialog-actions financial-document-actions">
            <button
              v-if="['emis', 'comptabilise', 'annule'].includes(viewedDocument.status) && workspace.capabilities.issue"
              class="button primary"
              type="button"
              @click="downloadPdf(viewedDocument)"
            >Télécharger le PDF</button>
          </div>
        </article>
      </ModalDialog>
    </template>

    <template v-else-if="workspace && ['offres', 'commandes'].includes(activeTab)">
      <div class="toolbar">
        <div>
          <p v-if="activeTab === 'offres'">Offres clients et fournisseurs reliées sans imposer de parcours préalable.</p>
          <p v-else>Une commande peut provenir d’une offre ou être créée directement.</p>
        </div>
        <div v-if="workspace.capabilities.manage" class="button-row">
          <template v-if="activeTab === 'offres'">
            <button class="button primary" type="button" @click="openCommercialEditor('offre_client')">Offre client</button>
            <button class="button secondary" type="button" @click="openCommercialEditor('reponse_offre_fournisseur')">Offre fournisseur</button>
          </template>
          <template v-else>
            <button class="button primary" type="button" @click="openCommercialEditor('commande_client')">Commande client</button>
            <button class="button secondary" type="button" @click="openCommercialEditor('commande_fournisseur')">Commande fournisseur</button>
          </template>
        </div>
      </div>

      <ModalDialog
        ref="commercialDialog"
        :title="commercialDraft.id ? 'Modifier le document commercial' : commercialTypeLabel(commercialDraft.type)"
        description="Le brouillon n’a aucun effet comptable et peut être créé sans document préalable."
        wide
        @closed="resetCommercialDraft()"
      >
        <form class="invoice-form" @submit.prevent="saveCommercial">
          <div class="form-grid invoice-header-grid">
            <FormField id="commercial-contact" :label="commercialDirection === 'client' ? 'Client' : 'Fournisseur'">
              <template #default="{ describedBy }">
                <AccountCombobox id="commercial-contact" v-model="commercialDraft.contact_id" :options="commercialContacts" number-key="company_contact_name" label-key="label" placeholder="Rechercher un contact…" :aria-describedby="describedBy" required />
              </template>
            </FormField>
            <FormField id="commercial-date" label="Date"><template #default="{ describedBy }"><input id="commercial-date" v-model="commercialDraft.document_date" type="date" :aria-describedby="describedBy" required></template></FormField>
            <FormField id="commercial-validity" label="Valable jusqu’au"><template #default="{ describedBy }"><input id="commercial-validity" v-model="commercialDraft.valid_until" type="date" :aria-describedby="describedBy"></template></FormField>
            <FormField id="commercial-external" label="Référence externe"><template #default="{ describedBy }"><input id="commercial-external" v-model="commercialDraft.external_number" :aria-describedby="describedBy"></template></FormField>
            <FormField id="commercial-currency" label="Devise"><template #default="{ describedBy }"><select id="commercial-currency" v-model="commercialDraft.currency" :aria-describedby="describedBy"><option v-for="item in workspace.catalog.currencies" :key="item.code" :value="item.code">{{ item.code }}</option></select></template></FormField>
            <FormField id="commercial-header" label="Introduction"><template #default="{ describedBy }"><textarea id="commercial-header" v-model="commercialDraft.header_text" :aria-describedby="describedBy"></textarea></template></FormField>
            <FormField id="commercial-footer" label="Conditions et conclusion"><template #default="{ describedBy }"><textarea id="commercial-footer" v-model="commercialDraft.footer_text" :aria-describedby="describedBy"></textarea></template></FormField>
            <FormField id="commercial-note" label="Note interne"><template #default="{ describedBy }"><textarea id="commercial-note" v-model="commercialDraft.internal_note" :aria-describedby="describedBy"></textarea></template></FormField>
          </div>
          <div v-if="commercialVatExempt" class="notice info">Dossier non assujetti : aucune TVA n’est présentée sur ce document.</div>
          <div class="invoice-lines-heading">
            <div>
              <h3>Références</h3>
              <p v-if="commercialDraft.type.startsWith('commande_')">
                La répartition comptable est facultative à ce stade et sera
                confirmée lors de la facturation.
              </p>
              <p v-else>
                Une offre ou demande d’offre décrit les quantités et prix sans
                imputation comptable.
              </p>
            </div>
            <button class="button secondary small" type="button" @click="commercialDraft.lines.push(newLine())">Ajouter une référence</button>
          </div>
          <fieldset v-for="(line, index) in commercialDraft.lines" :key="index" class="invoice-line">
            <legend>Référence {{ index + 1 }}</legend>
            <div class="invoice-line-grid">
              <FormField :id="`commercial-line-label-${index}`" label="Libellé"><template #default="{ describedBy }"><input :id="`commercial-line-label-${index}`" v-model="line.label" :aria-describedby="describedBy" required></template></FormField>
              <FormField v-if="commercialDraft.type.startsWith('commande_')" :id="`commercial-line-account-${index}`" label="Répartition comptable (facultative)"><template #default="{ describedBy }"><AccountCombobox :id="`commercial-line-account-${index}`" v-model="line.account_id" :options="workspace.catalog.accounts" :aria-describedby="describedBy" /></template></FormField>
              <FormField :id="`commercial-line-quantity-${index}`" label="Quantité"><template #default="{ describedBy }"><input :id="`commercial-line-quantity-${index}`" v-model="line.quantity" inputmode="decimal" :aria-describedby="describedBy" required></template></FormField>
              <FormField :id="`commercial-line-amount-${index}`" label="Prix unitaire"><template #default="{ describedBy }"><input :id="`commercial-line-amount-${index}`" v-model="line.amount" inputmode="decimal" :aria-describedby="describedBy" required></template></FormField>
              <FormField v-if="!commercialVatExempt" :id="`commercial-line-vat-${index}`" label="TVA"><template #default="{ describedBy }"><select :id="`commercial-line-vat-${index}`" v-model.number="line.vat_code_id" :aria-describedby="describedBy" required><option value="" disabled>Sélectionner</option><option v-for="vat in commercialVatCodes" :key="vat.id" :value="vat.id">{{ vat.code }} · {{ vat.label }}</option></select></template></FormField>
              <FormField v-if="!commercialVatExempt" :id="`commercial-line-mode-${index}`" label="Saisie"><template #default="{ describedBy }"><select :id="`commercial-line-mode-${index}`" v-model="line.input_mode" :aria-describedby="describedBy"><option value="net">Net</option><option value="brut">Brut</option></select></template></FormField>
              <button v-if="commercialDraft.lines.length > 1" class="button ghost small" type="button" @click="commercialDraft.lines.splice(index, 1)">Retirer</button>
            </div>
          </fieldset>
          <div class="dialog-actions"><button class="button primary" :disabled="store.saving">Enregistrer le brouillon</button></div>
        </form>
      </ModalDialog>

      <DataTable
        v-if="commercialRows.length"
        sortable
        :caption="activeTab === 'offres' ? 'Offres, demandes et réponses' : 'Commandes clients et fournisseurs'"
        :columns="[
          { key: 'display_number', label: 'Document' },
          { key: 'type_label', label: 'Nature' },
          { key: 'contact', label: 'Contact' },
          { key: 'date_document', label: 'Date' },
          { key: 'source', label: 'Origine' },
          { key: 'total', label: 'Total', sortKey: 'total_brut_centimes', type: 'number' },
          { key: 'status_label', label: 'Statut' },
          { key: 'actions', label: 'Actions' }
        ]"
        :rows="commercialRows"
      >
        <template #cell-display_number="{ row }">
          <button
            class="table-primary-link"
            type="button"
            @click="openCommercial(row as unknown as CommercialDocument)"
          >{{ row.display_number }}</button>
        </template>
        <template #cell-contact="{ row }">
          <button
            class="table-secondary-link"
            type="button"
            @click="selectContact(Number(row.contact_id))"
          >{{ row.contact }}</button>
        </template>
        <template #cell-actions="{ row }">
          <ActionMenu :label="`Actions pour ${row.display_number}`">
            <button type="button" @click="viewCommercial(row as unknown as CommercialDocument)">Consulter</button>
            <button v-if="row.statut === 'brouillon'" type="button" @click="editCommercial(row as unknown as CommercialDocument)">Modifier</button>
            <button v-if="row.statut === 'brouillon' && String(row.type).startsWith('commande_')" type="button" @click="setCommercialStatus(row as unknown as CommercialDocument, 'envoye')">Marquer envoyée</button>
            <button v-if="row.statut === 'envoye' && String(row.type).startsWith('commande_')" type="button" @click="setCommercialStatus(row as unknown as CommercialDocument, 'livre')">Confirmer la livraison</button>
            <button v-if="row.statut === 'brouillon' && !String(row.type).startsWith('commande_') && row.type !== 'reponse_offre_fournisseur'" type="button" @click="setCommercialStatus(row as unknown as CommercialDocument, 'envoye')">Marquer envoyé</button>
            <button v-if="row.statut === 'brouillon' && row.type === 'reponse_offre_fournisseur'" type="button" @click="setCommercialStatus(row as unknown as CommercialDocument, 'recu')">Marquer reçue</button>
            <button v-if="row.type === 'demande_offre_fournisseur' && row.statut === 'envoye'" type="button" @click="openCommercialConversion(row as unknown as CommercialDocument, 'reponse_offre_fournisseur')">Enregistrer la réponse</button>
            <button v-if="['offre_client', 'reponse_offre_fournisseur'].includes(String(row.type)) && ['envoye', 'recu'].includes(String(row.statut))" type="button" @click="setCommercialStatus(row as unknown as CommercialDocument, 'accepte')">Accepter</button>
            <button v-if="['offre_client', 'reponse_offre_fournisseur'].includes(String(row.type)) && ['envoye', 'recu'].includes(String(row.statut))" type="button" @click="setCommercialStatus(row as unknown as CommercialDocument, 'refuse')">Refuser</button>
            <button v-if="row.type === 'reponse_offre_fournisseur' && ['recu', 'accepte'].includes(String(row.statut))" type="button" @click="openCommercialConversion(row as unknown as CommercialDocument, 'reponse_offre_fournisseur')">Nouvelle offre modifiée</button>
            <button v-if="row.type === 'offre_client' && row.statut === 'accepte'" type="button" @click="openCommercialConversion(row as unknown as CommercialDocument, 'commande_client')">Créer la commande</button>
            <button v-if="row.type === 'reponse_offre_fournisseur' && row.statut === 'accepte'" type="button" @click="openCommercialConversion(row as unknown as CommercialDocument, 'commande_fournisseur')">Créer la commande</button>
            <button v-if="row.type === 'offre_client' && row.statut === 'accepte'" type="button" @click="openCommercialConversion(row as unknown as CommercialDocument, 'facture_client')">Facturer directement</button>
            <button v-if="row.type === 'reponse_offre_fournisseur' && row.statut === 'accepte'" type="button" @click="openCommercialConversion(row as unknown as CommercialDocument, 'facture_fournisseur')">Créer la facture</button>
            <button v-if="row.type === 'commande_client' && row.statut === 'livre'" type="button" @click="openCommercialConversion(row as unknown as CommercialDocument, 'facture_client')">Créer la facture</button>
            <button v-if="row.type === 'commande_fournisseur' && row.statut === 'livre'" type="button" @click="openCommercialConversion(row as unknown as CommercialDocument, 'facture_fournisseur')">Créer la facture</button>
            <button v-if="['brouillon', 'envoye', 'livre', 'recu', 'accepte'].includes(String(row.statut))" class="danger" type="button" @click="setCommercialStatus(row as unknown as CommercialDocument, 'annule')">Annuler</button>
            <button type="button" @click="downloadCommercialPdf(row as unknown as CommercialDocument)">PDF</button>
          </ActionMenu>
        </template>
      </DataTable>
      <EmptyState v-else title="Aucun document commercial" description="Créez directement un document ou convertissez une étape précédente." />

      <ModalDialog
        ref="commercialViewerDialog"
        :title="viewedCommercial?.numero || `Brouillon #${viewedCommercial?.id || ''}`"
        :description="viewedCommercial ? commercialTypeLabel(viewedCommercial.type) : ''"
        extra-wide
        @closed="viewedCommercial = null"
      >
        <article v-if="viewedCommercial" class="commercial-print-sheet">
          <header class="document-preview-heading">
            <div>
              <p class="eyebrow">{{ commercialTypeLabel(viewedCommercial.type) }}</p>
              <h2>{{ viewedCommercial.numero || `Brouillon #${viewedCommercial.id}` }}</h2>
              <button
                class="table-secondary-link"
                type="button"
                @click="openContactFromDocument(viewedCommercial.contact_id)"
              >{{ viewedCommercial.contact }}</button>
            </div>
            <div>
              <strong>{{ viewedCommercial.date_document }}</strong>
              <span class="status-chip">{{ commercialStatusLabel(viewedCommercial) }}</span>
            </div>
          </header>
          <p v-if="viewedCommercial.texte_entete">{{ viewedCommercial.texte_entete }}</p>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Référence</th><th>Quantité</th><th>Prix unitaire</th><th>Total</th></tr></thead>
              <tbody>
                <tr v-for="line in viewedCommercial.lines" :key="line.id">
                  <td>{{ line.libelle }}</td>
                  <td>{{ quantityInput(line.quantite_milli) }}</td>
                  <td>{{ money(line.prix_unitaire_centimes, viewedCommercial.monnaie) }}</td>
                  <td>{{ money(line.total_brut_centimes, viewedCommercial.monnaie) }}</td>
                </tr>
              </tbody>
              <tfoot><tr><th colspan="3">Total</th><th>{{ money(viewedCommercial.total_brut_centimes, viewedCommercial.monnaie) }}</th></tr></tfoot>
            </table>
          </div>
          <p v-if="viewedCommercial.texte_pied">{{ viewedCommercial.texte_pied }}</p>
          <div class="dialog-actions no-print">
            <button class="button secondary" type="button" @click="printCommercial">Imprimer cette vue</button>
            <button class="button primary" type="button" @click="downloadCommercialPdf(viewedCommercial)">Télécharger le PDF</button>
          </div>
        </article>
      </ModalDialog>

      <ModalDialog
        ref="conversionDialog"
        title="Créer le document suivant"
        description="Le nouveau document gardera un lien auditable vers son origine."
        wide
      >
        <form class="modal-editor" @submit.prevent="convertCommercial">
          <div class="form-grid">
            <FormField id="conversion-date" label="Date"><template #default="{ describedBy }"><input id="conversion-date" v-model="conversionDraft.document_date" type="date" :aria-describedby="describedBy" required></template></FormField>
            <FormField v-if="conversionDraft.target_type.startsWith('facture_')" id="conversion-due" label="Échéance"><template #default="{ describedBy }"><input id="conversion-due" v-model="conversionDraft.due_date" type="date" :aria-describedby="describedBy" required></template></FormField>
            <FormField v-if="conversionDraft.target_type.startsWith('facture_')" id="conversion-account" label="Compte de paiement"><template #default="{ describedBy }"><AccountCombobox id="conversion-account" v-model="conversionDraft.collective_account_id" :options="workspace.catalog.accounts" :aria-describedby="describedBy" required /></template></FormField>
            <FormField v-if="conversionDraft.target_type === 'facture_fournisseur' || conversionDraft.target_type === 'reponse_offre_fournisseur'" id="conversion-reference" label="Référence fournisseur"><template #default="{ describedBy }"><input id="conversion-reference" v-model="conversionDraft.external_number" :aria-describedby="describedBy" :required="conversionDraft.target_type === 'facture_fournisseur'"></template></FormField>
          </div>
          <fieldset v-if="conversionDraft.target_type.startsWith('facture_')">
            <legend>Répartition comptable des références</legend>
            <div class="configuration-grid">
              <label v-for="line in conversionDraft.line_accounts" :key="line.line_id">
                {{ line.label }}
                <AccountCombobox v-model="line.account_id" :options="workspace.catalog.accounts" required />
              </label>
            </div>
          </fieldset>
          <div class="dialog-actions"><button class="button primary" :disabled="store.saving">Créer le brouillon relié</button></div>
        </form>
      </ModalDialog>
    </template>

    <template v-else-if="workspace && activeTab === 'recurrences'">
      <div class="toolbar">
        <div><p>Chaque échéance crée un brouillon idempotent à contrôler avant émission.</p></div>
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
          <FormField id="billing-rec-collective" label="Compte de paiement"><template #default="{ describedBy }"><AccountCombobox id="billing-rec-collective" v-model="recurrenceDraft.collective_account_id" :options="workspace.catalog.accounts" :aria-describedby="describedBy" required /></template></FormField>
        </div>
        <div v-if="recurrenceVatExempt" class="notice info">
          Dossier non assujetti : les brouillons générés ne contiendront aucune TVA.
          Les achats récurrents sont saisis TVA comprise.
        </div>
        <fieldset v-for="(line, index) in recurrenceDraft.lines" :key="index" class="line-editor">
          <legend>Ligne {{ index + 1 }}</legend>
          <input v-model="line.label" aria-label="Libellé récurrent" placeholder="Libellé" required>
          <input v-model="line.amount" aria-label="Montant récurrent" inputmode="decimal" placeholder="Montant" required>
          <AccountCombobox v-model="line.account_id" :options="workspace.catalog.accounts" aria-label="Compte récurrent" placeholder="Compte" required />
          <select v-if="!recurrenceVatExempt" v-model.number="line.vat_code_id" aria-label="TVA récurrente" required><option value="" disabled>Sélectionner un code TVA</option><option v-for="vat in recurrenceVatCodes" :key="vat.id" :value="vat.id">{{ vat.code }} · {{ vat.label }}</option></select>
          <select v-if="!recurrenceVatExempt" v-model="line.input_mode" aria-label="Mode récurrent"><option value="net">Net</option><option value="brut">Brut</option></select>
        </fieldset>
        <div class="button-row"><button class="button ghost" type="button" @click="recurrenceDraft.lines.push(newLine())">Ajouter une ligne</button><button class="button primary" :disabled="store.saving">Enregistrer</button></div>
      </form>
      <DataTable
        v-if="recurrenceRows.length"
        sortable
        caption="Modèles de factures récurrentes"
        :columns="[{ key: 'label', label: 'Modèle' }, { key: 'type_label', label: 'Parcours' }, { key: 'contact', label: 'Contact' }, { key: 'cadence', label: 'Cadence' }, { key: 'next_date', label: 'Prochaine' }, { key: 'generation_count', label: 'Brouillons' }, { key: 'status_label', label: 'Statut' }, { key: 'actions', label: 'Actions' }]"
        :rows="recurrenceRows"
      >
        <template #cell-actions="{ row }">
          <ActionMenu :label="`Actions pour ${row.label}`">
            <button v-if="row.status !== 'termine' && workspace.capabilities.manage" type="button" @click="toggleRecurrence(row as { id: number; version: number; status: string })">{{ row.status === 'actif' ? 'Mettre en pause' : 'Reprendre' }}</button>
          </ActionMenu>
        </template>
      </DataTable>
      <EmptyState v-else title="Aucune récurrence" description="Créez un modèle client ou fournisseur ; aucune émission ne sera automatique." />
    </template>

    <template v-else-if="workspace && activeTab === 'contacts'">
      <div class="toolbar">
        <div><p>Un registre unique pour les rôles client et fournisseur.</p></div>
        <button v-if="workspace.capabilities.manage" class="button primary" type="button" @click="openContactEditor">Nouveau contact</button>
      </div>
      <div class="filter-bar contextual-filter">
        <FormField id="billing-contact-search" label="Rechercher un contact">
          <template #default="{ describedBy }">
            <input
              id="billing-contact-search"
              v-model="contactSearch"
              :aria-describedby="describedBy"
              placeholder="Nom, entreprise, courriel…"
            >
          </template>
        </FormField>
        <FormField id="billing-contact-status" label="Statut">
          <template #default="{ describedBy }">
            <select
              id="billing-contact-status"
              v-model="contactStatus"
              :aria-describedby="describedBy"
            >
              <option value="active">Actifs</option>
              <option value="archived">Archivés</option>
              <option value="all">Tous</option>
            </select>
          </template>
        </FormField>
      </div>
      <ModalDialog
        ref="contactEditorDialog"
        :title="contactDraft.id ? 'Modifier le contact' : 'Nouveau contact'"
        description="La personne peut être indépendante ou rattachée à une entreprise du registre."
        wide
        @closed="resetContactDraft"
      >
        <form class="modal-editor" @submit.prevent="saveContact">
          <div class="form-grid">
            <FormField id="contact-kind" label="Type"><template #default="{ describedBy }"><select id="contact-kind" v-model="contactDraft.type" :aria-describedby="describedBy"><option value="entreprise">Entreprise</option><option value="personne">Personne</option></select></template></FormField>
            <FormField v-if="contactDraft.type === 'entreprise'" id="contact-company" label="Raison sociale"><template #default="{ describedBy }"><input id="contact-company" v-model="contactDraft.company" :aria-describedby="describedBy" required></template></FormField>
            <FormField v-else id="contact-parent-company" label="Entreprise associée" hint="Facultatif">
              <template #default="{ describedBy }">
                <AccountCombobox id="contact-parent-company" v-model="contactDraft.company_contact_id" :options="activeCompanies.filter((item) => item.id !== contactDraft.id)" number-key="__none__" label-key="company" :empty-value="null" placeholder="Rechercher une entreprise…" :aria-describedby="describedBy" />
              </template>
            </FormField>
            <FormField v-if="contactDraft.type === 'personne'" id="contact-first" label="Prénom"><template #default="{ describedBy }"><input id="contact-first" v-model="contactDraft.first_name" :aria-describedby="describedBy"></template></FormField>
            <FormField v-if="contactDraft.type === 'personne'" id="contact-last" label="Nom"><template #default="{ describedBy }"><input id="contact-last" v-model="contactDraft.last_name" :aria-describedby="describedBy" required></template></FormField>
            <FormField id="contact-email" label="Courriel"><template #default="{ describedBy }"><input id="contact-email" v-model="contactDraft.email" type="email" :aria-describedby="describedBy"></template></FormField>
            <FormField id="contact-phone" label="Téléphone"><template #default="{ describedBy }"><input id="contact-phone" v-model="contactDraft.phone" type="tel" :aria-describedby="describedBy"></template></FormField>
            <FormField id="contact-line1" label="Adresse"><template #default="{ describedBy }"><input id="contact-line1" v-model="contactDraft.line1" :aria-describedby="describedBy" required></template></FormField>
            <FormField id="contact-line2" label="Complément"><template #default="{ describedBy }"><input id="contact-line2" v-model="contactDraft.line2" :aria-describedby="describedBy"></template></FormField>
            <FormField id="contact-postal" label="NPA"><template #default="{ describedBy }"><input id="contact-postal" v-model="contactDraft.postal_code" :aria-describedby="describedBy" required></template></FormField>
            <FormField id="contact-city" label="Localité"><template #default="{ describedBy }"><input id="contact-city" v-model="contactDraft.city" :aria-describedby="describedBy" required></template></FormField>
            <FormField id="contact-country" label="Pays ISO"><template #default="{ describedBy }"><input id="contact-country" v-model="contactDraft.country" maxlength="2" :aria-describedby="describedBy" required></template></FormField>
            <FormField id="contact-language" label="Langue"><template #default="{ describedBy }"><select id="contact-language" v-model="contactDraft.language" :aria-describedby="describedBy"><option value="fr">Français</option><option value="de">Allemand</option><option value="it">Italien</option><option value="en">Anglais</option></select></template></FormField>
            <FormField id="contact-iban" label="IBAN de paiement"><template #default="{ describedBy }"><input id="contact-iban" v-model="contactDraft.iban" :aria-describedby="describedBy"></template></FormField>
            <FormField id="contact-bic" label="BIC"><template #default="{ describedBy }"><input id="contact-bic" v-model="contactDraft.bic" :aria-describedby="describedBy"></template></FormField>
          </div>
          <fieldset><legend>Rôles</legend><label><input v-model="contactDraft.client" type="checkbox"> Client</label><label><input v-model="contactDraft.supplier" type="checkbox"> Fournisseur</label></fieldset>
          <div class="dialog-actions"><button class="button secondary" type="button" @click="contactEditorDialog?.close()">Annuler</button><button class="button primary" :disabled="store.saving">{{ contactDraft.id ? 'Enregistrer' : 'Créer le contact' }}</button></div>
        </form>
      </ModalDialog>
      <DataTable
        v-if="contactRows.length"
        sortable
        caption="Contacts du dossier"
        :columns="[{ key: 'label', label: 'Contact' }, { key: 'company_contact_name', label: 'Entreprise' }, { key: 'roles_label', label: 'Rôles' }, { key: 'offers_count', label: 'Offres actives', type: 'number' }, { key: 'orders_count', label: 'Commandes actives', type: 'number' }, { key: 'receivable', label: 'Créances', sortKey: 'receivable_cents', type: 'number' }, { key: 'payable', label: 'Dettes', sortKey: 'payable_cents', type: 'number' }, { key: 'net', label: 'Net', sortKey: 'net_cents', type: 'number' }, { key: 'active', label: 'Statut' }, { key: 'actions', label: 'Actions' }]"
        :rows="contactRows"
      >
        <template #cell-active="{ row }">{{ row.active ? 'Actif' : 'Archivé' }}</template>
        <template #cell-company_contact_name="{ row }">{{ row.company_contact_name || (row.type === 'entreprise' ? 'Entreprise' : 'Indépendant') }}</template>
        <template #cell-actions="{ row }">
          <ActionMenu :label="`Actions pour ${row.label}`">
            <button type="button" @click="selectContact(Number(row.id))">Vue 360°</button>
            <button v-if="row.active && workspace.capabilities.manage" type="button" @click="editContact(Number(row.id))">Modifier</button>
            <button v-if="row.active && workspace.capabilities.manage" class="danger" type="button" @click="removeContact(Number(row.id))">Supprimer ou archiver</button>
            <button v-if="!row.active && workspace.capabilities.manage" type="button" @click="restoreContact(Number(row.id))">Désarchiver</button>
          </ActionMenu>
        </template>
      </DataTable>
      <ModalDialog
        ref="contact360Dialog"
        :title="selectedContact?.label || 'Vue 360°'"
        :description="`Historique au ${workspace.reference_date}`"
        wide
        @closed="clearContact"
      >
        <div v-if="selectedContact && workspace.contact_360" class="contact-history">
          <p class="contact-summary">
            {{ selectedContact.email || 'Sans courriel' }}
            · {{ selectedContact.phone || 'Sans téléphone' }}
            · {{ selectedContact.address.postal_code }} {{ selectedContact.address.city }}
          </p>
          <dl class="detail-grid contact-kpis">
            <div><dt>Créances nettes</dt><dd>{{ money(workspace.contact_360.balance.receivable_cents) }}</dd></div>
            <div><dt>Dettes nettes</dt><dd>{{ money(workspace.contact_360.balance.payable_cents) }}</dd></div>
            <div><dt>Factures</dt><dd>{{ workspace.contact_360.documents.length }}</dd></div>
            <div><dt>Offres et commandes</dt><dd>{{ workspace.contact_360.commercial_documents.length }}</dd></div>
            <div><dt>Paiements</dt><dd>{{ workspace.contact_360.payments.length }}</dd></div>
          </dl>
          <section class="history-section">
            <h3>Offres, demandes et commandes</h3>
            <DataTable
              v-if="contactCommercialRows.length"
              caption="Documents commerciaux du contact"
              :columns="[
                { key: 'display_number', label: 'Document' },
                { key: 'type_label', label: 'Nature' },
                { key: 'date_document', label: 'Date' },
                { key: 'total', label: 'Total' },
                { key: 'status_label', label: 'Statut' }
              ]"
              :rows="contactCommercialRows"
            >
              <template #cell-display_number="{ row }">
                <button
                  class="table-primary-link"
                  type="button"
                  title="Télécharger le PDF sans quitter la fiche contact"
                  @click="downloadCommercialPdf(row as unknown as CommercialDocument)"
                >{{ row.display_number }}</button>
              </template>
            </DataTable>
            <EmptyState v-else title="Aucun document commercial" description="Aucune offre, demande ou commande pour ce contact." />
          </section>
          <section class="history-section">
            <h3>Factures</h3>
            <DataTable
              v-if="contactDocumentRows.length"
              caption="Historique des factures du contact"
              :columns="[
                { key: 'display_number', label: 'Document' },
                { key: 'type_label', label: 'Nature' },
                { key: 'document_date', label: 'Date' },
                { key: 'due_date', label: 'Échéance' },
                { key: 'total', label: 'Total' },
                { key: 'open', label: 'Ouvert' },
                { key: 'status_label', label: 'Statut' }
              ]"
              :rows="contactDocumentRows"
            >
              <template #cell-display_number="{ row }">
                <button
                  class="table-primary-link"
                  type="button"
                  title="Télécharger le PDF sans quitter la fiche contact"
                  :disabled="!workspace.capabilities.issue || !['emis', 'comptabilise', 'annule'].includes(String(row.status))"
                  @click="downloadPdf(row as BillingDocument)"
                >{{ row.display_number }}</button>
              </template>
            </DataTable>
            <EmptyState v-else title="Aucune facture" description="Aucune facture pour ce contact à la date choisie." />
          </section>
          <section class="history-section">
            <h3>Paiements</h3>
            <DataTable
              v-if="contactPaymentRows.length"
              caption="Historique des paiements du contact"
              :columns="[
                { key: 'payment_date', label: 'Date' },
                { key: 'direction_label', label: 'Sens' },
                { key: 'reference', label: 'Référence' },
                { key: 'amount', label: 'Montant' },
                { key: 'allocated', label: 'Alloué' },
                { key: 'unallocated', label: 'Non alloué' }
              ]"
              :rows="contactPaymentRows"
            >
              <template #cell-reference="{ row }">
                <button
                  class="table-primary-link"
                  type="button"
                  title="Télécharger le justificatif sans quitter la fiche contact"
                  @click="downloadPaymentPdf(row as BillingPayment)"
                >{{ row.reference || `Paiement #${row.id}` }}</button>
              </template>
            </DataTable>
            <EmptyState v-else title="Aucun paiement" description="Aucun paiement n’est rattaché à ce contact." />
          </section>
        </div>
      </ModalDialog>
    </template>

    <template v-else-if="workspace && activeTab === 'echeancier'">
      <div class="toolbar">
        <div><p>Créances et dettes ouvertes, calculées au {{ workspace.reference_date }}.</p></div>
        <div
          v-if="workspace.capabilities.pay || workspace.capabilities.remind"
          class="button-row"
        >
          <button v-if="workspace.capabilities.remind" class="button ghost" type="button" @click="reminderDialog?.open()">Tracer un rappel</button>
          <button v-if="workspace.capabilities.pay" class="button secondary" type="button" @click="paymentDialog?.open()">Saisir un paiement</button>
          <button v-if="workspace.capabilities.pay" class="button primary" type="button" @click="allocationDialog?.open()">Allouer un paiement</button>
        </div>
      </div>
      <div class="kpi-grid">
        <article class="kpi-card"><span>Créances nettes</span><strong>{{ money(workspace.aging.receivables.net_open_cents) }}</strong><small>{{ workspace.aging.receivables.item_count }} document(s)</small></article>
        <article class="kpi-card"><span>Dettes nettes</span><strong>{{ money(workspace.aging.payables.net_open_cents) }}</strong><small>{{ workspace.aging.payables.item_count }} document(s)</small></article>
        <article class="kpi-card"><span>Acomptes clients</span><strong>{{ money(workspace.aging.receivables.unallocated_payments_cents) }}</strong><small>Non ventilés dans l’aging</small></article>
        <article class="kpi-card"><span>Avances fournisseurs</span><strong>{{ money(workspace.aging.payables.unallocated_payments_cents) }}</strong><small>Non ventilées dans l’aging</small></article>
      </div>
      <DataTable
        class="aging-table"
        caption="Tranches d’âge des créances et dettes"
        :columns="[{ key: 'side', label: 'Nature' }, { key: 'not_due', label: 'Non échu' }, { key: 'd0', label: '0–30' }, { key: 'd31', label: '31–60' }, { key: 'd61', label: '61–90' }, { key: 'd91', label: '> 90' }, { key: 'net', label: 'Solde net' }]"
        :rows="[
          { id: 'receivables', side: 'Créances', not_due: plainMoney(workspace.aging.receivables.buckets.not_due), d0: plainMoney(workspace.aging.receivables.buckets.days_0_30), d31: plainMoney(workspace.aging.receivables.buckets.days_31_60), d61: plainMoney(workspace.aging.receivables.buckets.days_61_90), d91: plainMoney(workspace.aging.receivables.buckets.days_91_plus), net: plainMoney(workspace.aging.receivables.net_open_cents) },
          { id: 'payables', side: 'Dettes', not_due: plainMoney(workspace.aging.payables.buckets.not_due), d0: plainMoney(workspace.aging.payables.buckets.days_0_30), d31: plainMoney(workspace.aging.payables.buckets.days_31_60), d61: plainMoney(workspace.aging.payables.buckets.days_61_90), d91: plainMoney(workspace.aging.payables.buckets.days_91_plus), net: plainMoney(workspace.aging.payables.net_open_cents) }
        ]"
      >
        <template #cell-side="{ row }">
          <span :class="['aging-table-label', String(row.id)]">
            <i aria-hidden="true"></i>{{ row.side }}
          </span>
        </template>
      </DataTable>
      <AgingChart
        :receivables="workspace.aging.receivables"
        :payables="workspace.aging.payables"
      />

      <ModalDialog ref="paymentDialog" title="Saisir un paiement" description="Enregistrez le règlement indépendamment de son allocation aux factures.">
        <form v-if="workspace.capabilities.pay" class="modal-form-grid" @submit.prevent="savePayment">
          <FormField id="payment-contact" label="Contact"><template #default="{ describedBy }"><select id="payment-contact" v-model.number="paymentDraft.contact_id" :aria-describedby="describedBy" required><option :value="0" disabled>Sélectionner</option><option v-for="contact in workspace.contacts" :key="contact.id" :value="contact.id">{{ contact.label }}</option></select></template></FormField>
          <FormField id="payment-direction" label="Sens"><template #default="{ describedBy }"><select id="payment-direction" v-model="paymentDraft.direction" :aria-describedby="describedBy"><option value="encaissement">Encaissement client</option><option value="decaissement">Décaissement fournisseur</option></select></template></FormField>
          <FormField id="payment-date" label="Date"><template #default="{ describedBy }"><input id="payment-date" v-model="paymentDraft.date" type="date" :aria-describedby="describedBy" required></template></FormField>
          <FormField id="payment-amount" :label="`Montant ${paymentDraft.currency}`"><template #default="{ describedBy }"><input id="payment-amount" v-model="paymentDraft.amount" inputmode="decimal" :aria-describedby="describedBy" required></template></FormField>
          <FormField id="payment-reference" label="Référence"><template #default="{ describedBy }"><input id="payment-reference" v-model="paymentDraft.reference" :aria-describedby="describedBy" placeholder="Communication ou référence bancaire"></template></FormField>
          <FormField id="payment-currency" label="Devise"><template #default="{ describedBy }"><select id="payment-currency" v-model="paymentDraft.currency" :aria-describedby="describedBy" required @change="paymentDraft.exchange_rate_id = 0"><option v-for="currencyItem in workspace.catalog.currencies" :key="currencyItem.code" :value="currencyItem.code">{{ currencyItem.code }}{{ currencyItem.is_base ? ' · base' : '' }}</option></select></template></FormField>
          <FormField v-if="paymentDraft.currency !== context.context?.selection?.dossier.currency" id="payment-rate" label="Taux figé"><template #default="{ describedBy }"><select id="payment-rate" v-model.number="paymentDraft.exchange_rate_id" :aria-describedby="describedBy" required><option :value="0" disabled>Sélectionner</option><option v-for="rate in paymentExchangeRates" :key="rate.id" :value="rate.id">{{ rate.rate_date }} · {{ rate.numerator }}/{{ rate.denominator }} · {{ rate.source }}</option></select></template></FormField>
          <FormField id="payment-account" label="Compte de trésorerie"><template #default="{ describedBy }"><AccountCombobox id="payment-account" v-model="paymentDraft.treasury_account_id" :options="workspace.catalog.treasury_accounts" number-key="ledger_number" label-key="label" :aria-describedby="describedBy" required /></template></FormField>
          <div class="dialog-actions full-row"><button class="button primary" :disabled="store.saving">Enregistrer</button></div>
        </form>
      </ModalDialog>
      <ModalDialog ref="allocationDialog" title="Allouer un paiement" description="Choisissez un paiement disponible puis la facture ouverte à solder.">
        <form v-if="workspace.capabilities.pay" class="modal-form-grid" @submit.prevent="allocatePayment">
          <FormField id="allocation-payment" label="Paiement disponible"><template #default="{ describedBy }"><select id="allocation-payment" v-model.number="allocationDraft.payment_id" :aria-describedby="describedBy" required><option :value="0" disabled>Sélectionner</option><option v-for="payment in availableAllocationPayments" :key="payment.id" :value="payment.id">{{ payment.contact }} · {{ payment.direction === 'encaissement' ? 'Encaissement' : 'Décaissement' }} · {{ money(payment.unallocated_cents, payment.currency) }}</option></select></template></FormField>
          <FormField id="allocation-document" label="Facture compatible" :hint="selectedAllocationPayment && !compatibleAllocationDocuments.length ? 'Aucune facture émise du même contact, du même sens et dans la même devise.' : 'Seules les factures compatibles sont proposées.'"><template #default="{ describedBy }"><select id="allocation-document" v-model.number="allocationDraft.document_id" :aria-describedby="describedBy" :disabled="!selectedAllocationPayment || !compatibleAllocationDocuments.length" required><option :value="0" disabled>{{ selectedAllocationPayment ? 'Sélectionner' : 'Choisir d’abord un paiement' }}</option><option v-for="document in compatibleAllocationDocuments" :key="document.id" :value="document.id">{{ document.number }} · {{ document.contact }} · {{ money(document.open_cents, document.currency) }}</option></select></template></FormField>
          <FormField id="allocation-amount" label="Montant à allouer"><template #default="{ describedBy }"><input id="allocation-amount" v-model="allocationDraft.amount" inputmode="decimal" :aria-describedby="describedBy" required></template></FormField>
          <div class="dialog-actions full-row"><button class="button primary" :disabled="store.saving">Lettrer et comptabiliser si soldé</button></div>
        </form>
      </ModalDialog>

      <ModalDialog ref="reminderDialog" title="Tracer un rappel" description="Conservez une trace datée de la relance sans modifier la facture.">
      <form v-if="workspace.capabilities.remind" class="modal-form-grid" @submit.prevent="saveReminder">
          <FormField id="reminder-document" label="Facture client ouverte"><template #default="{ describedBy }"><select id="reminder-document" v-model.number="reminderDraft.document_id" :aria-describedby="describedBy" required><option :value="0" disabled>Sélectionner</option><option v-for="document in workspace.documents.filter((item) => item.type === 'facture_client' && item.open_cents > 0)" :key="document.id" :value="document.id">{{ document.number }} · {{ document.contact }}</option></select></template></FormField>
          <FormField id="reminder-level" label="Niveau"><template #default="{ describedBy }"><input id="reminder-level" v-model.number="reminderDraft.level" type="number" min="1" max="9" :aria-describedby="describedBy" required></template></FormField>
          <FormField id="reminder-channel" label="Canal"><template #default="{ describedBy }"><select id="reminder-channel" v-model="reminderDraft.channel" :aria-describedby="describedBy"><option value="email">Courriel</option><option value="courrier">Courrier</option><option value="telephone">Téléphone</option><option value="autre">Autre</option></select></template></FormField>
          <FormField id="reminder-note" label="Note"><template #default="{ describedBy }"><input id="reminder-note" v-model="reminderDraft.note" :aria-describedby="describedBy"></template></FormField>
        <div class="dialog-actions full-row"><button class="button primary" :disabled="store.saving">Enregistrer le rappel</button></div>
      </form>
      </ModalDialog>
    </template>
    <ConfirmDialog
      ref="removeContactDialog"
      title="Supprimer ou archiver ce contact ?"
      confirm-label="Continuer"
      tone="danger"
      @confirm="removeContact()"
    >
      <p>
        Le contact sera supprimé s’il n’a aucun historique. Dans le cas
        contraire, il sera archivé et restera consultable dans la vue 360°.
      </p>
    </ConfirmDialog>
  </section>
</template>

<style scoped>
.editor-card {
  padding: 1.1rem;
  border: 1px solid var(--border);
  border-radius: 0.75rem;
  background: var(--surface);
  box-shadow: var(--shadow);
}

.form-grid,
.modal-form-grid,
.invoice-line-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
}

.invoice-form,
.contact-history,
.history-section {
  display: grid;
  gap: 1rem;
}

.invoice-header-grid {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.invoice-lines-heading {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 1rem;
  padding-top: 0.25rem;
  border-top: 1px solid var(--border);
}

.invoice-lines-heading h3,
.invoice-lines-heading p,
.history-section h3,
.contact-summary {
  margin: 0;
}

.invoice-lines-heading p,
.contact-summary {
  margin-top: 0.25rem;
  color: var(--muted);
}

.invoice-line {
  min-width: 0;
  margin: 0;
  padding: 1rem;
  border: 1px solid var(--border);
  border-radius: 0.65rem;
  background: var(--surface-soft);
}

.invoice-line legend {
  padding: 0 0.35rem;
  font-weight: 750;
}

.invoice-line-grid {
  grid-template-columns:
    minmax(12rem, 1.4fr)
    minmax(14rem, 1.6fr)
    minmax(7rem, 0.7fr)
    minmax(9rem, 0.9fr)
    minmax(7rem, 0.65fr)
    auto;
  align-items: end;
}

.invoice-line-grid :deep(input),
.invoice-line-grid :deep(select),
.invoice-header-grid :deep(input),
.invoice-header-grid :deep(select),
.modal-form-grid :deep(input),
.modal-form-grid :deep(select) {
  width: 100%;
  min-width: 0;
}

.remove-line {
  min-height: 2.75rem;
  align-self: end;
}

.full-row {
  grid-column: 1 / -1;
}

.contact-summary {
  padding: 0.75rem 1rem;
  border-radius: 0.55rem;
  background: var(--surface-soft);
}

.contact-kpis {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 0.75rem;
  margin: 0;
}

.contact-kpis > div {
  padding: 0.8rem;
  border: 1px solid var(--border);
  border-radius: 0.55rem;
}

.contact-kpis dt {
  color: var(--muted);
  font-size: 0.8rem;
}

.contact-kpis dd {
  margin: 0.25rem 0 0;
  font-weight: 750;
}

.aging-table-label {
  display: inline-flex;
  align-items: center;
  gap: .5rem;
  font-weight: 750;
}

.aging-table-label i {
  width: .8rem;
  height: .8rem;
  border-radius: .2rem;
}

.aging-table-label.receivables i {
  background: #087f8c;
}

.aging-table-label.payables i {
  background: #c2413b;
}

:deep(.aging-table tbody tr:first-child td) {
  background: color-mix(in srgb, #087f8c 7%, var(--surface));
}

:deep(.aging-table tbody tr:last-child td) {
  background: color-mix(in srgb, #c2413b 7%, var(--surface));
}

@media (max-width: 980px) {
  .invoice-header-grid,
  .invoice-line-grid,
  .contact-kpis {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 640px) {
  .form-grid,
  .modal-form-grid,
  .invoice-header-grid,
  .invoice-line-grid,
  .contact-kpis {
    grid-template-columns: 1fr;
  }

  .invoice-lines-heading {
    align-items: stretch;
    flex-direction: column;
  }
}
</style>
