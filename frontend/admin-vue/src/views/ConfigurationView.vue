<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import AccountCombobox from '@/components/ui/AccountCombobox.vue';
import CompactTabs from '@/components/ui/CompactTabs.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataTable from '@/components/ui/DataTable.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import FormField from '@/components/ui/FormField.vue';
import ModalDialog from '@/components/ui/ModalDialog.vue';
import SkeletonBlock from '@/components/ui/SkeletonBlock.vue';
import { markUnsavedChanges } from '@/composables/unsavedChanges';
import { useToastFeedback } from '@/composables/toastFeedback';
import { cantonLabel } from '@/data/swissCantons';
import { referenceNavigation, subNavigation } from '@/router/navigation';
import { useConfigurationStore } from '@/stores/configuration';
import { useContextStore } from '@/stores/context';
import { useNotificationStore } from '@/stores/notifications';
import { exerciseStatusLabel, periodStatusLabel } from '@/utils/statusLabels';

const route = useRoute();
const context = useContextStore();
const store = useConfigurationStore();
useToastFeedback(store, false);
const notifications = useNotificationStore();
const paymentTermDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const treasuryDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const exchangeRateDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const contactDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const vatDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const vatRegimeDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const payrollRatesDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const exerciseDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const periodDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const clearAuditDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
const clearVatDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
const removeContactDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
const removeTreasuryDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
const pendingContactRemoval = ref<
  NonNullable<typeof managedReferences.value>['contacts'][number] | null
>(null);
const pendingTreasuryRemoval = ref<
  NonNullable<typeof managedReferences.value>['treasury']['accounts'][number] | null
>(null);
const tabs = subNavigation.settings;
const activeTab = computed(() => (
  route.name === 'managed-reference'
    ? 'referentiels'
    : String(route.params.tab || 'entity')
));
const configuration = computed(() => store.configuration);
const managedReferences = computed(() => store.managedReferences);
const canManage = computed(() => context.can('dossier.manage'));
const today = new Date().toISOString().slice(0, 10);
type ReferenceSection =
  | 'treasury'
  | 'currencies'
  | 'contacts'
  | 'vat'
  | 'payroll'
  | 'journals'
  | 'exercises';
const referenceSections: ReferenceSection[] = [
  'treasury', 'currencies', 'contacts', 'vat', 'payroll', 'journals', 'exercises'
];
const referenceSection = computed<ReferenceSection>(() => {
  const requested = String(
    route.params.section || route.query.section || 'treasury'
  ) as ReferenceSection;
  return referenceSections.includes(requested) ? requested : 'treasury';
});

const identity = reactive({
  organization_version: 0,
  dossier_version: 0,
  name: '',
  legal_name: '',
  legal_form: '',
  uid: '',
  address_line1: '',
  address_line2: '',
  postal_code: '',
  city: '',
  canton: '',
  country: 'CH',
  phone: '',
  billing_treasury_account_id: null as number | null,
  vat_exempt: true,
  vat_effective_from: today,
  email: '',
  website: '',
  base_currency: 'CHF'
});
const paymentTerm = reactive({
  code: '',
  label: '',
  direction: 'tous' as 'client' | 'fournisseur' | 'tous',
  days: 30,
  end_of_month: false,
  valid_from: today,
  valid_until: ''
});
const clientDefault = ref(0);
const supplierDefault = ref(0);
const clientDefaultFrom = ref(today);
const supplierDefaultFrom = ref(today);
const contactReferenceSearch = ref('');
const contactReferenceStatus = ref<'active' | 'archived' | 'all'>('active');
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
  payment_iban: '',
  payment_bic: '',
  language: 'fr',
  roles: ['client'] as string[],
  address_line1: '',
  address_line2: '',
  postal_code: '',
  city: '',
  country: 'CH'
});
const vatDraft = reactive({
  id: 0,
  active: true,
  code: '',
  label: '',
  treatment: 'normal',
  nature: 'collectee',
  legal_rate_id: 0,
  deduction_right: false,
  default_deduction_percent: '0',
  afc_box: '',
  account_id: 0,
  valid_from: today,
  valid_until: ''
});
const vatRegimeDraft = reactive({
  status: 'non_assujetti' as 'non_assujetti' | 'assujetti' | 'volontaire',
  vat_number: '',
  method: 'effective' as 'effective' | 'tdfn',
  reporting_mode: 'convenues' as 'convenues' | 'recues',
  frequency: 'trimestrielle' as
    | 'mensuelle' | 'trimestrielle' | 'semestrielle' | 'annuelle',
  valid_from: today,
  valid_until: '',
  input_material_account_id: 0,
  input_investment_account_id: 0,
  vat_due_account_id: 0,
  vat_settlement_account_id: 0,
  corrections_account_id: 0
});
const payrollDraft = reactive<Record<string, string | number>>({
  year: new Date().getFullYear(),
  source: '',
  verified_on: today
});
const payrollRateFields = [
  { key: 'avs_ppm', label: 'AVS employé' },
  { key: 'ac_ppm', label: 'AC employé' },
  { key: 'amat_ppm', label: 'Assurance maternité employé' },
  { key: 'laa_reduit_ppm', label: 'LAA employé — taux réduit' },
  { key: 'laa_plein_ppm', label: 'LAA employé — taux plein' },
  { key: 'lpp_ppm', label: 'LPP employé' },
  { key: 'emp_avs_ppm', label: 'AVS employeur' },
  { key: 'emp_ac_ppm', label: 'AC employeur' },
  { key: 'emp_amat_ppm', label: 'Assurance maternité employeur' },
  { key: 'emp_af_ppm', label: 'Allocations familiales employeur' },
  { key: 'emp_laa_reduit_ppm', label: 'LAA employeur — taux réduit' },
  { key: 'emp_laa_plein_ppm', label: 'LAA employeur — taux plein' },
  { key: 'emp_frais_ppm', label: 'Frais administratifs employeur' },
  { key: 'emp_cpe_ppm', label: 'CPE employeur' },
  { key: 'emp_lfp_ppm', label: 'LFP employeur' },
  { key: 'emp_lpp_ppm', label: 'LPP employeur' }
] as const;
const payrollCsvColumns = [
  { key: 'year', header: 'annee' },
  { key: 'source', header: 'source' },
  { key: 'verified_on', header: 'verifie_le' },
  ...payrollRateFields.map((field) => ({
    key: field.key,
    header: `${field.key.replace(/_ppm$/, '')}_pct`
  }))
] as const;
payrollRateFields.forEach(({ key }) => { payrollDraft[key] = ''; });
const payrollSettings = reactive({
  weekly_hours: '40',
  mapping: {} as Record<string, number>
});
const payrollMappingFields = [
  ['charge_salaires_id', 'Charge salaires'],
  ['charge_ocas_id', 'Charge OCAS'],
  ['charge_laa_id', 'Charge LAA'],
  ['charge_lpp_id', 'Charge LPP'],
  ['dette_net_id', 'Dette salaires nets'],
  ['dette_ocas_id', 'Dette OCAS'],
  ['dette_laa_id', 'Dette LAA'],
  ['dette_lpp_id', 'Dette LPP'],
  ['dette_impot_id', 'Dette impôt source']
] as const;
payrollMappingFields.forEach(([key]) => { payrollSettings.mapping[key] = 0; });
const treasuryDraft = reactive({
  id: 0,
  version: 0,
  ledger_account_id: 0,
  label: '',
  type: 'banque' as 'banque' | 'poste' | 'caisse' | 'carte',
  iban: '',
  bic: '',
  currency: 'CHF',
  accounting_multiplier: 1 as -1 | 1,
  active: true
});
const currencyDraft = reactive({ currency: 'EUR', active: true });
const exchangeRateDraft = reactive({
  source_currency: 'EUR',
  rate_date: today,
  numerator: 100,
  denominator: 100,
  source: '',
  verified_on: today,
  active: true
});
const exchangeMappingDraft = reactive({
  realized_gain_account_id: 0,
  realized_loss_account_id: 0,
  unrealized_gain_account_id: 0,
  unrealized_loss_account_id: 0
});
const exchangeMappingFields = [
  ['realized_gain_account_id', 'Gain réalisé'],
  ['realized_loss_account_id', 'Perte réalisée'],
  ['unrealized_gain_account_id', 'Gain latent'],
  ['unrealized_loss_account_id', 'Perte latente']
] as const;
const journalDraft = reactive({
  id: 0,
  version: 0,
  code: '',
  label: '',
  type: 'general',
  active: true
});
const exerciseDraft = reactive({
  id: 0,
  version: 0,
  label: '',
  start_date: `${new Date().getFullYear()}-01-01`,
  end_date: `${new Date().getFullYear()}-12-31`,
  status: 'ouvert' as 'ouvert' | 'ferme'
});
const periodDraft = reactive({
  id: 0,
  version: 0,
  exercise_id: 0,
  label: '',
  start_date: today,
  end_date: today,
  status: 'ouverte' as 'ouverte' | 'fermee'
});
const vatTreatmentsWithRate = ['normal', 'reduit', 'special', 'acquisition', 'import'];
const paymentRows = computed<Array<Record<string, unknown>>>(() =>
  (configuration.value?.payment_terms ?? []).map((item) => ({
    defaults: (configuration.value?.payment_defaults ?? [])
      .filter((entry) => entry.condition_id === item.id)
      .map((entry) => (
        `${entry.direction === 'client' ? 'Clients' : 'Fournisseurs'}`
        + ` dès le ${entry.valid_from}`
        + `${entry.valid_until ? ` jusqu’au ${entry.valid_until}` : ''}`
        + `${entry.current ? ' · actuel' : ''}`
      ))
      .join(' ; ') || 'Aucun',
    id: item.id,
    code: item.code,
    label: item.label,
    direction: directionLabel(item.direction),
    calculation: `${item.days} jour(s)${item.end_of_month ? ', fin de mois' : ''}`,
    validity: `${item.valid_from} — ${item.valid_until || 'sans fin'}`,
  }))
);
const auditRows = computed<Array<Record<string, unknown>>>(() =>
  (configuration.value?.audit ?? []).map((item) => ({
    id: item.id,
    created_at: item.created_at,
    actor: item.actor,
    action: item.action,
    target: `${item.target_type}${item.target_id ? ` #${item.target_id}` : ''}`
  }))
);
const filteredReferenceContacts = computed(() => {
  const query = contactReferenceSearch.value.trim().toLocaleLowerCase('fr-CH');
  return (managedReferences.value?.contacts ?? []).filter((contact) => {
    if (
      contactReferenceStatus.value !== 'all'
      && contact.active !== (contactReferenceStatus.value === 'active')
    ) {
      return false;
    }
    return !query || [
      contactName(contact), contact.company_contact_name, contact.email,
      contact.city, ...contact.roles
    ].join(' ').toLocaleLowerCase('fr-CH').includes(query);
  });
});

watch(
  () => context.selection?.dossier.id ?? 0,
  async (dossierId, previous) => {
    if (!dossierId) {
      store.clear();
      return;
    }
    if (dossierId !== previous) await load();
  }
);
watch(
  () => store.configuration?.identity,
  (value) => {
    if (!value) return;
    Object.assign(identity, {
      organization_version: value.organization.version,
      dossier_version: value.dossier.version,
      name: value.organization.name,
      legal_name: value.organization.legal_name,
      legal_form: value.organization.legal_form,
      uid: value.organization.uid,
      address_line1: value.organization.address_line1,
      address_line2: value.organization.address_line2,
      postal_code: value.organization.postal_code,
      city: value.organization.city,
      canton: value.organization.canton,
      country: value.organization.country,
      phone: value.organization.phone,
      email: value.organization.email,
      website: value.organization.website,
      billing_treasury_account_id: value.dossier.billing_treasury_account_id,
      vat_exempt: value.dossier.vat_exempt,
      vat_effective_from: value.dossier.vat_effective_from,
      base_currency: value.dossier.base_currency
    });
    markUnsavedChanges(false);
  },
  { deep: true }
);
watch(
  managedReferences,
  (value) => {
    if (!value) return;
    if (!periodDraft.exercise_id && value.accounting_setup.exercises.length) {
      periodDraft.exercise_id = value.accounting_setup.exercises[0].id;
    }
    payrollSettings.weekly_hours = String(
      value.payroll.employer.weekly_hours_milli / 1000
    );
    payrollMappingFields.forEach(([key]) => {
      payrollSettings.mapping[key] = Number(value.payroll.mapping?.[key] || 0);
    });
    if (value.currencies.mapping) {
      Object.assign(exchangeMappingDraft, value.currencies.mapping);
    }
    const currentRegime = value.vat.regimes[0];
    if (currentRegime) {
      Object.assign(vatRegimeDraft, {
        status: currentRegime.status,
        vat_number: currentRegime.vat_number,
        method: currentRegime.method,
        reporting_mode: currentRegime.reporting_mode,
        frequency: currentRegime.frequency,
        valid_from: today,
        valid_until: '',
        input_material_account_id: currentRegime.input_material_account_id || 0,
        input_investment_account_id:
          currentRegime.input_investment_account_id || 0,
        vat_due_account_id: currentRegime.vat_due_account_id || 0,
        vat_settlement_account_id: currentRegime.vat_settlement_account_id || 0,
        corrections_account_id: currentRegime.corrections_account_id || 0
      });
    }
  },
  { deep: true }
);

onMounted(load);
onBeforeUnmount(() => markUnsavedChanges(false));

async function load(): Promise<void> {
  if (context.selection && canManage.value) {
    await Promise.all([store.load(), store.loadManagedReferences()]);
  }
}

function directionLabel(value: string): string {
  return value === 'client' ? 'Clients' : value === 'fournisseur' ? 'Fournisseurs' : 'Tous';
}

function contactName(contact: NonNullable<typeof managedReferences.value>['contacts'][number]): string {
  return contact.company || `${contact.first_name} ${contact.last_name}`.trim();
}

function percentFromBasisPoints(value: number): string {
  return (value / 100).toLocaleString('fr-CH', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2
  });
}

function percentFromPpm(value: number): string {
  const whole = Math.trunc(value / 10000);
  const fraction = String(value % 10000).padStart(4, '0').replace(/0+$/, '');
  return fraction ? `${whole},${fraction}` : String(whole);
}

function parseScaledPercent(value: string | number, scale: 100 | 10000): number {
  const normalized = String(value).trim().replace(',', '.');
  const decimals = scale === 100 ? 2 : 4;
  if (!new RegExp(`^\\d{1,3}(?:\\.\\d{1,${decimals}})?$`).test(normalized)) {
    throw new Error(`Pourcentage positif avec ${decimals} décimales au maximum requis.`);
  }
  const [whole, fraction = ''] = normalized.split('.');
  const result = Number(whole) * scale + Number(fraction.padEnd(decimals, '0'));
  if (!Number.isSafeInteger(result) || result > 100 * scale) {
    throw new Error('Le pourcentage doit être compris entre 0 et 100.');
  }
  return result;
}

function csvCell(value: unknown): string {
  const text = String(value ?? '');
  return /[;"\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

function parseSemicolonCsv(contents: string): string[][] {
  const rows: string[][] = [];
  let row: string[] = [];
  let cell = '';
  let quoted = false;

  for (let index = 0; index < contents.length; index += 1) {
    const character = contents[index];
    if (quoted) {
      if (character === '"' && contents[index + 1] === '"') {
        cell += '"';
        index += 1;
      } else if (character === '"') {
        quoted = false;
      } else {
        cell += character;
      }
      continue;
    }
    if (character === '"') {
      quoted = true;
    } else if (character === ';') {
      row.push(cell.trim());
      cell = '';
    } else if (character === '\n') {
      row.push(cell.trim());
      rows.push(row);
      row = [];
      cell = '';
    } else if (character !== '\r') {
      cell += character;
    }
  }
  if (quoted) throw new Error('Le fichier CSV contient une cellule non refermée.');
  if (cell.length || row.length) {
    row.push(cell.trim());
    rows.push(row);
  }
  return rows.filter((candidate) => candidate.some((value) => value !== ''));
}

function validIsoDate(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const date = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === value;
}

function exportPayrollRates(): void {
  const rates = managedReferences.value?.payroll.rates ?? [];
  const header = payrollCsvColumns.map((column) => csvCell(column.header)).join(';');
  const rows = rates.map((rate) => payrollCsvColumns.map((column) => {
    if (column.key === 'year') return csvCell(rate.year);
    if (column.key === 'source') return csvCell(rate.source);
    if (column.key === 'verified_on') return csvCell(rate.verified_on);
    return csvCell(percentFromPpm(Number(rate[column.key] || 0)));
  }).join(';'));
  const blob = new Blob(
    [`\uFEFF${[header, ...rows].join('\r\n')}\r\n`],
    { type: 'text/csv;charset=utf-8' }
  );
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `taux-charges-sociales-${today}.csv`;
  link.click();
  URL.revokeObjectURL(url);
  notifications.push(
    rates.length
      ? `${rates.length} millésime(s) exporté(s) en CSV.`
      : 'Modèle CSV des taux annuels exporté.',
    'success'
  );
}

async function importPayrollRates(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;
  try {
    if (file.size > 1_000_000) {
      throw new Error('Le fichier CSV dépasse la taille maximale de 1 Mo.');
    }
    const rows = parseSemicolonCsv((await file.text()).replace(/^\uFEFF/, ''));
    if (rows.length < 2) throw new Error('Le fichier CSV ne contient aucun millésime.');
    const expectedHeaders = payrollCsvColumns.map((column) => column.header);
    if (
      rows[0].length !== expectedHeaders.length
      || rows[0].some((header, index) => header !== expectedHeaders[index])
    ) {
      throw new Error(`En-têtes CSV attendus : ${expectedHeaders.join(';')}`);
    }

    const years = new Set<number>();
    const payloads = rows.slice(1).map((row, rowIndex) => {
      if (row.length !== expectedHeaders.length) {
        throw new Error(`Ligne ${rowIndex + 2} : ${expectedHeaders.length} colonnes requises.`);
      }
      const values = Object.fromEntries(
        payrollCsvColumns.map((column, index) => [column.key, row[index]])
      ) as Record<string, string>;
      const year = Number(values.year);
      if (!Number.isInteger(year) || year < 2000 || year > 9999) {
        throw new Error(`Ligne ${rowIndex + 2} : année invalide.`);
      }
      if (years.has(year)) {
        throw new Error(`Ligne ${rowIndex + 2} : le millésime ${year} est présent deux fois.`);
      }
      years.add(year);
      if (!values.source) throw new Error(`Ligne ${rowIndex + 2} : source requise.`);
      if (!validIsoDate(values.verified_on)) {
        throw new Error(`Ligne ${rowIndex + 2} : date de vérification invalide.`);
      }
      const payload: Record<string, unknown> = {
        year,
        source: values.source,
        verified_on: values.verified_on
      };
      payrollRateFields.forEach(({ key }) => {
        try {
          payload[key] = parseScaledPercent(values[key], 10000);
        } catch (error) {
          const message = error instanceof Error ? error.message : 'pourcentage invalide';
          throw new Error(`Ligne ${rowIndex + 2}, ${key.replace(/_ppm$/, '_pct')} : ${message}`);
        }
      });
      return payload;
    });

    for (const payload of payloads) await store.savePayrollRates(payload);
    notifications.push(
      `${payloads.length} millésime(s) importé(s) après validation du CSV.`,
      'success'
    );
  } catch (error) {
    if (!store.error) {
      notifications.push(
        error instanceof Error ? error.message : 'Import des taux annuels impossible.',
        'error'
      );
    }
  } finally {
    input.value = '';
  }
}

function syncVatRate(): void {
  if (!vatTreatmentsWithRate.includes(vatDraft.treatment)) {
    vatDraft.legal_rate_id = 0;
  }
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
    payment_iban: '',
    payment_bic: '',
    language: 'fr',
    roles: ['client'],
    address_line1: '',
    address_line2: '',
    postal_code: '',
    city: '',
    country: 'CH'
  });
}

async function createContact(): Promise<void> {
  await store.createContact({
    ...contactDraft,
    company_contact_id: contactDraft.type === 'personne'
      ? contactDraft.company_contact_id
      : null,
    roles: [...contactDraft.roles]
  });
  resetContactDraft();
  contactDialog.value?.close();
  notifications.push('Contact enregistré dans le registre unique.', 'success');
}

function editContact(
  contact: NonNullable<typeof managedReferences.value>['contacts'][number]
): void {
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
    payment_iban: contact.payment_iban,
    payment_bic: contact.payment_bic,
    language: contact.language,
    roles: [...contact.roles],
    address_line1: contact.address_line1,
    address_line2: contact.address_line2,
    postal_code: contact.postal_code,
    city: contact.city,
    country: contact.country
  });
  void contactDialog.value?.open();
}

async function deleteContact(
  contact?: NonNullable<typeof managedReferences.value>['contacts'][number]
): Promise<void> {
  if (contact) {
    pendingContactRemoval.value = contact;
    await removeContactDialog.value?.open();
    return;
  }
  const selected = pendingContactRemoval.value;
  if (!selected) return;
  await store.deleteContact(selected.id, selected.version);
  if (contactDraft.id === selected.id) resetContactDraft();
  pendingContactRemoval.value = null;
  notifications.push(
    'Contact supprimé s’il était inutilisé, sinon archivé avec son historique.',
    'success'
  );
}

async function restoreContact(
  contact: NonNullable<typeof managedReferences.value>['contacts'][number]
): Promise<void> {
  await store.restoreContact(contact.id, contact.version);
  notifications.push('Contact désarchivé et à nouveau disponible.', 'success');
}

async function saveVatRegime(): Promise<void> {
  const taxable = vatRegimeDraft.status !== 'non_assujetti';
  await store.saveVatRegime({
    ...vatRegimeDraft,
    vat_number: taxable ? vatRegimeDraft.vat_number : '',
    input_material_account_id: taxable
      ? vatRegimeDraft.input_material_account_id : null,
    input_investment_account_id: taxable
      ? vatRegimeDraft.input_investment_account_id : null,
    vat_due_account_id: taxable ? vatRegimeDraft.vat_due_account_id : null,
    vat_settlement_account_id: taxable
      ? vatRegimeDraft.vat_settlement_account_id : null,
    corrections_account_id: taxable
      ? vatRegimeDraft.corrections_account_id : null
  });
  vatRegimeDialog.value?.close();
  notifications.push('Nouveau régime TVA daté enregistré.', 'success');
}

function resetVatCode(): void {
  Object.assign(vatDraft, {
    id: 0,
    active: true,
    code: '',
    label: '',
    treatment: 'normal',
    nature: 'collectee',
    legal_rate_id: 0,
    deduction_right: false,
    default_deduction_percent: '0',
    afc_box: '',
    account_id: 0,
    valid_from: today,
    valid_until: ''
  });
}

function editVatCode(
  code: NonNullable<typeof managedReferences.value>['vat']['codes'][number]
): void {
  Object.assign(vatDraft, {
    id: code.id,
    active: code.active,
    code: code.code,
    label: code.label,
    treatment: code.treatment,
    nature: code.nature,
    legal_rate_id: code.legal_rate_id || 0,
    deduction_right: code.deduction_right,
    default_deduction_percent: percentFromBasisPoints(code.default_deduction_bp),
    afc_box: code.afc_box,
    account_id: code.account_id || 0,
    valid_from: code.valid_from,
    valid_until: code.valid_until || ''
  });
  void vatDialog.value?.open();
}

function vatPayload(
  code: NonNullable<typeof managedReferences.value>['vat']['codes'][number],
  active: boolean
): Record<string, unknown> {
  return {
    id: code.id,
    active,
    code: code.code,
    label: code.label,
    treatment: code.treatment,
    nature: code.nature,
    legal_rate_id: code.legal_rate_id,
    deduction_right: code.deduction_right,
    default_deduction_bp: code.default_deduction_bp,
    afc_box: code.afc_box,
    account_id: code.account_id,
    valid_from: code.valid_from,
    valid_until: code.valid_until || ''
  };
}

async function toggleVatCode(
  code: NonNullable<typeof managedReferences.value>['vat']['codes'][number]
): Promise<void> {
  await store.saveVatCode(vatPayload(code, !code.active));
  if (vatDraft.id === code.id) resetVatCode();
  notifications.push(code.active ? 'Code TVA désactivé.' : 'Code TVA réactivé.', 'success');
}

async function deleteVatCode(
  code: NonNullable<typeof managedReferences.value>['vat']['codes'][number]
): Promise<void> {
  if (!window.confirm(`Supprimer définitivement le code TVA ${code.code} ?`)) return;
  await store.deleteVatCode(code.id);
  if (vatDraft.id === code.id) resetVatCode();
  notifications.push('Code TVA inutilisé supprimé.', 'success');
}

async function saveVatCode(): Promise<void> {
  try {
    const edited = vatDraft.id > 0;
    await store.saveVatCode({
      id: vatDraft.id,
      active: vatDraft.active,
      code: vatDraft.code,
      label: vatDraft.label,
      treatment: vatDraft.treatment,
      nature: vatDraft.nature,
      legal_rate_id: vatDraft.legal_rate_id || null,
      deduction_right: vatDraft.deduction_right,
      default_deduction_bp: parseScaledPercent(
        vatDraft.default_deduction_percent,
        100
      ),
      afc_box: vatDraft.afc_box,
      account_id: vatDraft.account_id || null,
      valid_from: vatDraft.valid_from,
      valid_until: vatDraft.valid_until
    });
    resetVatCode();
    notifications.push(
      edited ? 'Code TVA modifié.' : 'Code TVA daté créé.',
      'success'
    );
    vatDialog.value?.close();
  } catch (error) {
    if (error instanceof Error && !store.error) store.error = error.message;
  }
}

function exportVatReferences(): void {
  if (!managedReferences.value) return;
  const payload = {
    format: 'compta-vat-references-v1',
    exported_at: new Date().toISOString(),
    legal_rates: managedReferences.value.vat.legal_rates,
    codes: managedReferences.value.vat.codes
  };
  const blob = new Blob(
    [JSON.stringify(payload, null, 2)],
    { type: 'application/json;charset=utf-8' }
  );
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `references-tva-${today}.json`;
  link.click();
  URL.revokeObjectURL(url);
  notifications.push('Références TVA exportées.', 'success');
}

async function importVatReferences(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;
  try {
    const payload = JSON.parse(await file.text()) as {
      format?: string;
      codes?: Array<Record<string, unknown>>;
    };
    if (payload.format !== 'compta-vat-references-v1' || !Array.isArray(payload.codes)) {
      throw new Error('Fichier de références TVA incompatible.');
    }
    for (const code of payload.codes) {
      await store.saveVatCode({
        id: 0,
        active: Boolean(code.active),
        code: String(code.code || ''),
        label: String(code.label || ''),
        treatment: String(code.treatment || ''),
        nature: String(code.nature || ''),
        legal_rate_id: Number(code.legal_rate_id || 0) || null,
        deduction_right: Boolean(code.deduction_right),
        default_deduction_bp: Number(code.default_deduction_bp || 0),
        afc_box: String(code.afc_box || ''),
        account_id: Number(code.account_id || 0) || null,
        valid_from: String(code.valid_from || ''),
        valid_until: String(code.valid_until || '')
      });
    }
    notifications.push(`${payload.codes.length} référence(s) TVA importée(s).`, 'success');
  } catch (error) {
    notifications.push(
      error instanceof Error ? error.message : 'Import TVA impossible.',
      'error'
    );
  } finally {
    input.value = '';
  }
}

async function clearVatReferences(): Promise<void> {
  try {
    await store.clearVatConfiguration();
    notifications.push(
      'Les codes et paramètres TVA inutilisés du dossier ont été effacés.',
      'success'
    );
  } catch {
    // Le message détaillé est affiché par le système de notifications.
  }
}

function loadPayrollRates(source: Record<string, string | number | null>): void {
  payrollDraft.year = Number(source.year);
  payrollDraft.source = String(source.source || '');
  payrollDraft.verified_on = String(source.verified_on || today);
  payrollRateFields.forEach(({ key }) => {
    payrollDraft[key] = percentFromPpm(Number(source[key] || 0));
  });
  void payrollRatesDialog.value?.open();
}

async function savePayrollRates(): Promise<void> {
  try {
    const payload: Record<string, unknown> = {
      year: Number(payrollDraft.year),
      source: String(payrollDraft.source),
      verified_on: String(payrollDraft.verified_on)
    };
    payrollRateFields.forEach(({ key }) => {
      payload[key] = parseScaledPercent(payrollDraft[key], 10000);
    });
    await store.savePayrollRates(payload);
    notifications.push(
      'Taux salariaux annuels enregistrés avec leur source.',
      'success'
    );
    payrollRatesDialog.value?.close();
  } catch (error) {
    if (error instanceof Error && !store.error) store.error = error.message;
  }
}

function resetTreasuryDraft(): void {
  Object.assign(treasuryDraft, {
    id: 0,
    version: 0,
    ledger_account_id: 0,
    label: '',
    type: 'banque',
    iban: '',
    bic: '',
    currency: configuration.value?.identity.dossier.base_currency || 'CHF',
    accounting_multiplier: 1,
    active: true
  });
}

function editTreasuryAccount(
  account: NonNullable<typeof managedReferences.value>['treasury']['accounts'][number]
): void {
  Object.assign(treasuryDraft, account);
  void treasuryDialog.value?.open();
}

async function saveTreasuryAccount(): Promise<void> {
  await store.saveTreasuryAccount({ ...treasuryDraft });
  resetTreasuryDraft();
  treasuryDialog.value?.close();
  notifications.push('Compte de trésorerie enregistré.', 'success');
}

async function removeTreasuryAccount(
  account?: NonNullable<typeof managedReferences.value>['treasury']['accounts'][number]
): Promise<void> {
  if (account) {
    pendingTreasuryRemoval.value = account;
    await removeTreasuryDialog.value?.open();
    return;
  }
  const selected = pendingTreasuryRemoval.value;
  if (!selected) return;
  await store.removeTreasuryAccount(selected.id, selected.version);
  if (treasuryDraft.id === selected.id) resetTreasuryDraft();
  pendingTreasuryRemoval.value = null;
  notifications.push(
    'Compte supprimé s’il était inutilisé, sinon archivé avec son historique.',
    'success'
  );
}

async function saveCurrency(): Promise<void> {
  await store.saveCurrency({ ...currencyDraft });
  notifications.push('Devise du dossier enregistrée.', 'success');
}

async function saveExchangeRate(): Promise<void> {
  await store.saveExchangeRate({ ...exchangeRateDraft });
  exchangeRateDialog.value?.close();
  notifications.push('Taux daté et sourcé enregistré.', 'success');
}

async function saveExchangeMapping(): Promise<void> {
  await store.saveExchangeMapping({ ...exchangeMappingDraft });
  notifications.push('Comptes de différences de change enregistrés.', 'success');
}

function resetJournalDraft(): void {
  Object.assign(journalDraft, {
    id: 0,
    version: 0,
    code: '',
    label: '',
    type: 'general',
    active: true
  });
}

function editJournal(
  journal: NonNullable<typeof managedReferences.value>['accounting_setup']['journals'][number]
): void {
  Object.assign(journalDraft, journal);
}

async function saveJournal(): Promise<void> {
  await store.saveJournal({ ...journalDraft });
  resetJournalDraft();
  notifications.push('Journal comptable enregistré.', 'success');
}

async function createExercise(): Promise<void> {
  await store.saveExercise({ ...exerciseDraft });
  await context.loadExercises();
  Object.assign(exerciseDraft, {
    id: 0,
    version: 0,
    label: '',
    status: 'ouvert'
  });
  exerciseDialog.value?.close();
  notifications.push('Exercice comptable créé.', 'success');
}

async function toggleExercise(
  exercise: NonNullable<typeof managedReferences.value>['accounting_setup']['exercises'][number]
): Promise<void> {
  await store.saveExercise({
    id: exercise.id,
    version: exercise.version,
    label: exercise.label,
    start_date: exercise.start_date,
    end_date: exercise.end_date,
    status: exercise.status === 'ouvert' ? 'ferme' : 'ouvert'
  });
  await context.load();
  notifications.push('Statut de l’exercice mis à jour.', 'success');
}

async function createPeriod(): Promise<void> {
  await store.savePeriod({ ...periodDraft });
  Object.assign(periodDraft, {
    id: 0,
    version: 0,
    label: '',
    status: 'ouverte'
  });
  periodDialog.value?.close();
  notifications.push('Période comptable créée.', 'success');
}

async function togglePeriod(
  period: NonNullable<typeof managedReferences.value>['accounting_setup']['periods'][number]
): Promise<void> {
  await store.savePeriod({
    id: period.id,
    version: period.version,
    exercise_id: period.exercise_id,
    label: period.label,
    start_date: period.start_date,
    end_date: period.end_date,
    status: period.status === 'ouverte' ? 'fermee' : 'ouverte'
  });
  notifications.push('Statut de la période mis à jour.', 'success');
}

async function saveIdentity(): Promise<void> {
  await store.saveIdentity({ ...identity });
  markUnsavedChanges(false);
  await context.load();
  notifications.push('Identité légale et devise enregistrées.', 'success');
}

async function savePayrollEmployerSettings(): Promise<void> {
  const normalizedHours = payrollSettings.weekly_hours.trim().replace(',', '.');
  if (!/^\d{1,3}(?:\.\d{1,3})?$/.test(normalizedHours)) {
    store.error = 'Les heures hebdomadaires doivent être un nombre positif.';
    return;
  }
  const weeklyHoursMilli = Math.round(Number(normalizedHours) * 1000);
  if (weeklyHoursMilli < 1 || weeklyHoursMilli > 168000) {
    store.error = 'Les heures hebdomadaires doivent être comprises entre 0,001 et 168.';
    return;
  }
  try {
    await store.savePayrollEmployerSettings({
      weekly_hours_milli: weeklyHoursMilli
    });
    notifications.push(
      'Paramètres de l’employeur salarial enregistrés.',
      'success'
    );
  } catch (error) {
    if (error instanceof Error && !store.error) store.error = error.message;
  }
}

async function savePayrollMappingSettings(): Promise<void> {
  try {
    await store.savePayrollMappingSettings({ ...payrollSettings.mapping });
    notifications.push('Mapping comptable des salaires enregistré.', 'success');
  } catch (error) {
    if (error instanceof Error && !store.error) store.error = error.message;
  }
}

async function toggleModule(code: string, enabled: boolean, version: number): Promise<void> {
  await store.saveModule(code, enabled, version);
  await context.load();
  notifications.push(
    enabled ? 'Module activé ; ses données sont à nouveau accessibles.' : 'Module désactivé sans suppression de données.',
    'success'
  );
}

async function createTerm(): Promise<void> {
  await store.createPaymentTerm({ ...paymentTerm });
  paymentTerm.code = '';
  paymentTerm.label = '';
  paymentTermDialog.value?.close();
  notifications.push('Condition de paiement datée créée.', 'success');
}

async function saveDefault(direction: 'client' | 'fournisseur'): Promise<void> {
  const conditionId = direction === 'client' ? clientDefault.value : supplierDefault.value;
  const validFrom = direction === 'client' ? clientDefaultFrom.value : supplierDefaultFrom.value;
  if (!conditionId) return;
  await store.savePaymentDefault(direction, conditionId, validFrom);
  notifications.push('Nouveau défaut daté enregistré sans effet rétroactif.', 'success');
}

async function clearAudit(): Promise<void> {
  await store.clearAudit();
  notifications.push('L’audit du dossier a été entièrement effacé.', 'success');
}
</script>

<template>
  <header class="page-heading">
    <div>
      <h1>Configuration</h1>
      <p>Une source unique par domaine, des valeurs datées et un historique auditable.</p>
    </div>
  </header>

  <CompactTabs
    v-if="context.selection && canManage"
    :items="tabs"
    label="Navigation Configuration"
  />

  <section v-if="!context.selection" class="access-message" role="status">
    <strong>Contexte requis</strong>
    <p>Sélectionnez un dossier avant d’ouvrir sa configuration.</p>
  </section>
  <section v-else-if="!canManage" class="access-message denied" role="alert">
    <strong>Accès refusé</strong>
    <p>La permission de gestion du dossier est requise.</p>
  </section>
  <template v-else>
    <ErrorSummary :message="store.error" />
    <SkeletonBlock v-if="store.loading && !configuration" :lines="8" />

    <template v-if="configuration">
      <form
        v-if="activeTab === 'entity'"
        class="panel configuration-form"
        @submit.prevent="saveIdentity"
        @input="markUnsavedChanges(true)"
      >
        <div class="panel-heading">
          <div>
            <p class="eyebrow">Entité légale</p>
            <h2>Identité et coordonnées</h2>
          </div>
          <span class="status-badge status-ouverte">Version {{ identity.organization_version }}</span>
        </div>
        <p class="help-text">
          La raison sociale, la forme, l’IDE et l’adresse sont pilotés par
          <RouterLink to="/organisations-dossiers">
            l’historique daté du registre des organisations
          </RouterLink>.
        </p>
        <section class="entity-readonly-card" aria-label="Identité juridique active">
          <div>
            <span>Raison sociale</span>
            <strong>{{ identity.legal_name || 'Non renseignée' }}</strong>
          </div>
          <div>
            <span>Forme juridique</span>
            <strong>{{ identity.legal_form || '—' }}</strong>
          </div>
          <div>
            <span>IDE / UID</span>
            <strong>{{ identity.uid || '—' }}</strong>
          </div>
          <div class="entity-address">
            <span>Adresse légale</span>
            <strong>
              {{ [identity.address_line1, identity.address_line2].filter(Boolean).join(', ') || '—' }}
            </strong>
            <small>{{ identity.postal_code }} {{ identity.city }}</small>
          </div>
          <div>
            <span>Canton</span>
            <strong>{{ cantonLabel(identity.canton) }} ({{ identity.canton || '—' }})</strong>
          </div>
          <div>
            <span>Pays</span>
            <strong>{{ identity.country || '—' }}</strong>
          </div>
        </section>
        <div class="configuration-grid">
          <FormField id="organization-name" label="Nom usuel">
            <template #default="{ describedBy }">
              <input id="organization-name" v-model="identity.name" :aria-describedby="describedBy" required>
            </template>
          </FormField>
          <FormField id="phone" label="Téléphone">
            <template #default="{ describedBy }">
              <input id="phone" v-model="identity.phone" type="tel" :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="email" label="E-mail">
            <template #default="{ describedBy }">
              <input id="email" v-model="identity.email" type="email" :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="website" label="Site web">
            <template #default="{ describedBy }">
              <input id="website" v-model="identity.website" type="url" :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField
            id="billing-account"
            label="Compte de facturation"
            hint="Seuls les comptes de trésorerie actifs avec un IBAN CH ou LI sont proposés."
          >
            <template #default="{ describedBy }">
              <select
                id="billing-account"
                v-model="identity.billing_treasury_account_id"
                :aria-describedby="describedBy"
              >
                <option :value="null">Aucun compte sélectionné</option>
                <option
                  v-for="account in configuration.identity.dossier.billing_treasury_accounts"
                  :key="account.id"
                  :value="account.id"
                >
                  {{ account.label }} · {{ account.iban }} · {{ account.currency }}
                </option>
              </select>
            </template>
          </FormField>
          <FormField
            id="base-currency"
            label="Devise de base du dossier"
            hint="Code ISO 4217 ; ce défaut ne convertit aucune écriture historique."
          >
            <template #default="{ describedBy }">
              <input
                id="base-currency"
                v-model="identity.base_currency"
                maxlength="3"
                :aria-describedby="describedBy"
                required
              >
            </template>
          </FormField>
        </div>
        <div class="form-actions">
          <button class="button primary" type="submit" :disabled="store.saving">
            Enregistrer l’entité
          </button>
        </div>
      </form>

      <section v-else-if="activeTab === 'modules'" class="configuration-stack">
        <article class="panel">
          <div class="panel-heading">
            <div>
              <p class="eyebrow">Activation par dossier</p>
              <h2>Modules</h2>
            </div>
          </div>
          <p>Désactiver masque la navigation et refuse les routes serveur. Aucune donnée n’est supprimée.</p>
          <div class="module-list">
            <article v-for="module in configuration.modules" :key="module.code" class="module-card">
              <div>
                <h3>{{ module.label }}</h3>
                <p>{{ module.description }}</p>
              </div>
              <div class="module-state">
                <span class="status-badge" :class="module.enabled ? 'status-ouverte' : 'status-fermee'">
                  {{ module.enabled ? 'Actif' : 'Inactif' }}
                </span>
                <button
                  class="button secondary compact"
                  type="button"
                  :disabled="store.saving"
                  @click="toggleModule(module.code, !module.enabled, module.version)"
                >
                  {{ module.enabled ? 'Désactiver' : 'Réactiver' }}
                </button>
              </div>
            </article>
          </div>
        </article>
      </section>

      <section v-else-if="activeTab === 'paiements'" class="configuration-stack">
        <div class="section-toolbar">
          <div>
            <p class="eyebrow">Valeurs datées</p>
            <h2>Conditions de paiement</h2>
          </div>
          <button class="button primary" type="button" @click="paymentTermDialog?.open()">
            Nouvelle condition
          </button>
        </div>
        <ModalDialog
          ref="paymentTermDialog"
          title="Nouvelle condition de paiement"
          description="Définissez une règle datée applicable aux clients, aux fournisseurs ou aux deux."
          wide
        >
          <form class="configuration-form" @submit.prevent="createTerm">
          <div class="configuration-grid">
            <FormField id="term-code" label="Code">
              <template #default="{ describedBy }">
                <input id="term-code" v-model="paymentTerm.code" :aria-describedby="describedBy" required>
              </template>
            </FormField>
            <FormField id="term-label" label="Libellé">
              <template #default="{ describedBy }">
                <input id="term-label" v-model="paymentTerm.label" :aria-describedby="describedBy" required>
              </template>
            </FormField>
            <FormField id="term-direction" label="Applicable à">
              <template #default="{ describedBy }">
                <select id="term-direction" v-model="paymentTerm.direction" :aria-describedby="describedBy">
                  <option value="tous">Clients et fournisseurs</option>
                  <option value="client">Clients</option>
                  <option value="fournisseur">Fournisseurs</option>
                </select>
              </template>
            </FormField>
            <FormField id="term-days" label="Délai en jours">
              <template #default="{ describedBy }">
                <input id="term-days" v-model.number="paymentTerm.days" type="number" min="0" max="3650" :aria-describedby="describedBy">
              </template>
            </FormField>
            <FormField id="term-from" label="Valable dès le">
              <template #default="{ describedBy }">
                <input id="term-from" v-model="paymentTerm.valid_from" type="date" :aria-describedby="describedBy" required>
              </template>
            </FormField>
            <FormField id="term-until" label="Valable jusqu’au">
              <template #default="{ describedBy }">
                <input id="term-until" v-model="paymentTerm.valid_until" type="date" :aria-describedby="describedBy">
              </template>
            </FormField>
            <label class="option-card">
              <input v-model="paymentTerm.end_of_month" type="checkbox">
              <span>
                <strong>Fin de mois</strong>
                <small>Reporter l’échéance au dernier jour du mois obtenu.</small>
              </span>
            </label>
          </div>
          <p class="field-hint">{{ configuration.definitions.payment_due_date }}</p>
          <div class="form-actions">
            <button class="button primary" type="submit" :disabled="store.saving">Créer la condition</button>
            <button class="button secondary" type="button" @click="paymentTermDialog?.close()">Annuler</button>
          </div>
          </form>
        </ModalDialog>

        <article class="panel">
          <div class="panel-heading">
            <div>
              <p class="eyebrow">Défauts datés</p>
              <h2>Conditions par défaut</h2>
            </div>
          </div>
          <div class="dashboard-two-columns">
            <form class="default-term" @submit.prevent="saveDefault('client')">
              <h3>Factures clients</h3>
              <select v-model.number="clientDefault" aria-label="Condition client">
                <option :value="0">Choisir…</option>
                <option
                  v-for="term in configuration.payment_terms.filter((item) => item.direction !== 'fournisseur')"
                  :key="term.id"
                  :value="term.id"
                >
                  {{ term.code }} — {{ term.label }}
                </option>
              </select>
              <input v-model="clientDefaultFrom" type="date" aria-label="Effet du défaut client">
              <button class="button secondary" type="submit" :disabled="!clientDefault || store.saving">
                Définir le défaut client
              </button>
            </form>
            <form class="default-term" @submit.prevent="saveDefault('fournisseur')">
              <h3>Factures fournisseurs</h3>
              <select v-model.number="supplierDefault" aria-label="Condition fournisseur">
                <option :value="0">Choisir…</option>
                <option
                  v-for="term in configuration.payment_terms.filter((item) => item.direction !== 'client')"
                  :key="term.id"
                  :value="term.id"
                >
                  {{ term.code }} — {{ term.label }}
                </option>
              </select>
              <input v-model="supplierDefaultFrom" type="date" aria-label="Effet du défaut fournisseur">
              <button class="button secondary" type="submit" :disabled="!supplierDefault || store.saving">
                Définir le défaut fournisseur
              </button>
            </form>
          </div>
        </article>

        <article class="panel">
          <DataTable
            caption="Conditions de paiement et périodes de validité"
            :columns="[
              { key: 'code', label: 'Code' },
              { key: 'label', label: 'Libellé' },
              { key: 'direction', label: 'Portée' },
              { key: 'calculation', label: 'Calcul' },
              { key: 'validity', label: 'Validité' },
              { key: 'defaults', label: 'Utilisation comme défaut' }
            ]"
            :rows="paymentRows"
          />
          <EmptyState
            v-if="!paymentRows.length"
            title="Aucune condition de paiement"
            description="Créez une première valeur datée, puis choisissez son usage par défaut."
          />
        </article>
      </section>

      <section v-else-if="activeTab === 'referentiels'" class="configuration-stack">
        <nav class="subtabs secondary-tabs" aria-label="Référentiels gérés">
          <RouterLink
            v-for="item in referenceNavigation"
            :key="item.key"
            :to="item.path"
          >{{ item.label }}</RouterLink>
        </nav>

        <template v-if="managedReferences && referenceSection === 'treasury'">
          <div class="section-toolbar">
            <div><p class="eyebrow">Grand livre lié</p><h2>Comptes de trésorerie</h2></div>
            <button
              v-if="managedReferences.capabilities.treasury"
              class="button primary"
              type="button"
              @click="resetTreasuryDraft(); treasuryDialog?.open()"
            >Nouveau compte de trésorerie</button>
          </div>
          <ModalDialog
            ref="treasuryDialog"
            :title="treasuryDraft.id ? 'Modifier le compte de trésorerie' : 'Nouveau compte de trésorerie'"
            description="Chaque compte de trésorerie est relié à un compte unique du grand livre."
            wide
          >
            <form class="configuration-form" @submit.prevent="saveTreasuryAccount">
            <div class="configuration-grid">
              <label>Compte comptable
                <AccountCombobox
                  v-model="treasuryDraft.ledger_account_id"
                  :options="managedReferences.treasury.ledger_accounts"
                  placeholder="Choisir…"
                  required
                />
              </label>
              <label>Libellé
                <input v-model="treasuryDraft.label" required>
              </label>
              <label>Type
                <select v-model="treasuryDraft.type">
                  <option value="banque">Banque</option>
                  <option value="poste">Poste</option>
                  <option value="caisse">Caisse</option>
                  <option value="carte">Carte</option>
                </select>
              </label>
              <label>Devise
                <input v-model="treasuryDraft.currency" maxlength="3" required>
              </label>
              <label>IBAN
                <input v-model="treasuryDraft.iban" autocomplete="off">
              </label>
              <label>BIC
                <input v-model="treasuryDraft.bic" autocomplete="off">
              </label>
              <label>Sens dans le grand livre
                <select v-model.number="treasuryDraft.accounting_multiplier">
                  <option :value="1">Normal</option>
                  <option :value="-1">Inversé</option>
                </select>
              </label>
            </div>
            <label class="checkbox-field">
              <input v-model="treasuryDraft.active" type="checkbox">
              Compte actif
            </label>
            <div class="form-actions">
              <button class="button primary" type="submit" :disabled="store.saving">
                Enregistrer le compte
              </button>
              <button
                v-if="treasuryDraft.id"
                class="button secondary"
                type="button"
                @click="resetTreasuryDraft"
              >
                Annuler la modification
              </button>
              <button v-else class="button secondary" type="button" @click="treasuryDialog?.close()">
                Annuler
              </button>
            </div>
            </form>
          </ModalDialog>
          <article class="panel">
            <div class="panel-heading">
              <div><p class="eyebrow">Source unique</p><h2>Comptes de trésorerie</h2></div>
              <strong>{{ managedReferences.treasury.accounts.length }}</strong>
            </div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr><th>Compte</th><th>Type</th><th>Coordonnées</th><th>Statut</th><th></th></tr>
                </thead>
                <tbody>
                  <tr v-for="account in managedReferences.treasury.accounts" :key="account.id">
                    <td>
                      <strong>{{ account.label }}</strong><br>
                      <small>{{ account.ledger_account_number }} · {{ account.currency }}</small>
                    </td>
                    <td>{{ account.type }}</td>
                    <td>{{ account.iban || '—' }}<br><small>{{ account.bic }}</small></td>
                    <td>{{ account.active ? 'Actif' : 'Archivé' }}</td>
                    <td class="button-row">
                      <button class="button secondary compact" type="button" @click="editTreasuryAccount(account)">
                        Modifier
                      </button>
                      <button
                        v-if="account.active"
                        class="button danger compact"
                        type="button"
                        :disabled="store.saving"
                        @click="removeTreasuryAccount(account)"
                      >
                        Supprimer ou archiver
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <EmptyState
              v-if="!managedReferences.treasury.accounts.length"
              title="Aucun compte de trésorerie"
              description="Créez le premier compte et liez-le au grand livre."
            />
          </article>
        </template>

        <template v-else-if="managedReferences && referenceSection === 'currencies'">
          <article class="panel">
            <div class="panel-heading">
              <div>
                <p class="eyebrow">Devise fonctionnelle {{ managedReferences.currencies.base_currency }}</p>
                <h2>Devises autorisées</h2>
              </div>
            </div>
            <form v-if="managedReferences.capabilities.currencies" class="currency-editor" @submit.prevent="saveCurrency">
              <label class="currency-code-control">
                <span>Code ISO</span>
                <input
                  v-model="currencyDraft.currency"
                  aria-label="Code ISO"
                  maxlength="3"
                  placeholder="EUR"
                  required
                >
              </label>
              <label class="currency-toggle">
                <input v-model="currencyDraft.active" type="checkbox">
                <span class="currency-switch" aria-hidden="true"></span>
                <span class="currency-toggle-copy">
                  <strong>Devise active</strong>
                  <small>Disponible pour les nouvelles opérations.</small>
                </span>
              </label>
              <button class="button primary" type="submit" :disabled="store.saving">Enregistrer</button>
            </form>
            <ul class="currency-list" aria-label="Devises configurées">
              <li
                v-for="item in managedReferences.currencies.currencies"
                :key="item.code"
                class="currency-card"
                :class="{ 'currency-card-base': item.is_base, 'currency-card-inactive': !item.active }"
              >
                <span class="currency-code">{{ item.code }}</span>
                <span class="currency-details">
                  <strong>{{ item.is_base ? 'Devise de base' : `Devise ${item.code}` }}</strong>
                  <small>
                    {{ item.is_base
                      ? 'Monnaie fonctionnelle du dossier'
                      : item.active
                        ? 'Disponible pour les nouvelles opérations'
                        : 'Conservée pour l’historique uniquement' }}
                  </small>
                </span>
                <span
                  class="currency-status"
                  :class="item.active ? 'currency-status-active' : 'currency-status-inactive'"
                >
                  <span aria-hidden="true"></span>
                  {{ item.is_base ? 'Toujours active' : item.active ? 'Active' : 'Inactive' }}
                </span>
              </li>
            </ul>
          </article>

          <div class="section-toolbar">
            <div><p class="eyebrow">Historique exact</p><h2>Taux de change datés</h2></div>
            <button
              v-if="managedReferences.capabilities.currencies"
              class="button primary"
              type="button"
              @click="exchangeRateDialog?.open()"
            >Nouveau taux daté</button>
          </div>
          <ModalDialog
            ref="exchangeRateDialog"
            title="Nouveau taux daté"
            description="Le ratio exact et sa source restent traçables sans nombre flottant."
            wide
          >
            <form class="configuration-form" @submit.prevent="saveExchangeRate">
            <div class="configuration-grid">
              <label>Devise source
                <select v-model="exchangeRateDraft.source_currency" required>
                  <option v-for="item in managedReferences.currencies.currencies.filter((entry) => !entry.is_base && entry.active)" :key="item.code" :value="item.code">{{ item.code }}</option>
                </select>
              </label>
              <label>Date du taux<input v-model="exchangeRateDraft.rate_date" type="date" required></label>
              <label>Numérateur<input v-model.number="exchangeRateDraft.numerator" type="number" min="1" required></label>
              <label>Dénominateur<input v-model.number="exchangeRateDraft.denominator" type="number" min="1" required></label>
              <label>Source<input v-model="exchangeRateDraft.source" placeholder="Banque, publication ou saisie contrôlée" required></label>
              <label>Vérifié le<input v-model="exchangeRateDraft.verified_on" type="date" required></label>
            </div>
            <p class="form-help">Le ratio signifie : centimes {{ managedReferences.currencies.base_currency }} par centime de la devise source.</p>
            <div class="form-actions">
              <button class="button primary" type="submit" :disabled="store.saving">Ajouter le taux</button>
              <button class="button secondary" type="button" @click="exchangeRateDialog?.close()">Annuler</button>
            </div>
            </form>
          </ModalDialog>

          <article class="panel">
            <div class="table-wrap">
              <table>
                <thead><tr><th>Date</th><th>Paire</th><th>Ratio exact</th><th>Source</th></tr></thead>
                <tbody>
                  <tr v-for="rate in managedReferences.currencies.rates" :key="rate.id">
                    <td>{{ rate.rate_date }}</td>
                    <td>{{ rate.source_currency }}/{{ rate.target_currency }}</td>
                    <td>{{ rate.numerator }}/{{ rate.denominator }}</td>
                    <td>{{ rate.source }}<br><small>Vérifié le {{ rate.verified_on }}</small></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </article>

          <form v-if="managedReferences.capabilities.currencies" class="panel configuration-form" @submit.prevent="saveExchangeMapping">
            <div class="panel-heading">
              <div><p class="eyebrow">Grand livre unique</p><h2>Comptes des différences de change</h2></div>
            </div>
            <div class="configuration-grid">
              <label v-for="field in exchangeMappingFields" :key="field[0]">{{ field[1] }}
                <AccountCombobox
                  v-model="exchangeMappingDraft[field[0]]"
                  :options="managedReferences.currencies.accounts"
                  placeholder="Choisir…"
                  required
                />
              </label>
            </div>
            <button class="button primary" type="submit" :disabled="store.saving">Enregistrer les comptes</button>
          </form>
        </template>

        <template v-else-if="managedReferences && referenceSection === 'contacts'">
          <div class="section-toolbar">
            <div><p class="eyebrow">Registre partagé</p><h2>Débiteurs et créanciers</h2></div>
            <button
              v-if="managedReferences.capabilities.contacts"
              class="button primary"
              type="button"
              @click="resetContactDraft(); contactDialog?.open()"
            >Nouveau débiteur ou créancier</button>
          </div>
          <ModalDialog
            ref="contactDialog"
            :title="contactDraft.id ? 'Modifier le contact' : 'Nouveau débiteur ou créancier'"
            description="Un même contact peut cumuler plusieurs rôles sans être dupliqué."
            wide
            @closed="resetContactDraft"
          >
            <form class="configuration-form" @submit.prevent="createContact">
            <div class="configuration-grid">
              <label>Type
                <select v-model="contactDraft.type">
                  <option value="entreprise">Entreprise</option>
                  <option value="personne">Personne</option>
                </select>
              </label>
              <label v-if="contactDraft.type === 'entreprise'">Raison sociale
                <input v-model="contactDraft.company" :required="contactDraft.type === 'entreprise'">
              </label>
              <label v-else>Entreprise associée (facultatif)
                <AccountCombobox
                  v-model="contactDraft.company_contact_id"
                  :options="managedReferences.contacts.filter((item) => item.type === 'entreprise' && item.active && item.id !== contactDraft.id)"
                  label-key="company"
                  number-key="__none__"
                  :empty-value="null"
                  placeholder="Rechercher une entreprise…"
                />
              </label>
              <label>Prénom
                <input v-model="contactDraft.first_name">
              </label>
              <label>Nom
                <input v-model="contactDraft.last_name">
              </label>
              <label>E-mail
                <input v-model="contactDraft.email" type="email">
              </label>
              <label>Téléphone
                <input v-model="contactDraft.phone" type="tel">
              </label>
              <label>IBAN de paiement
                <input v-model="contactDraft.payment_iban" autocomplete="off" placeholder="CH…">
              </label>
              <label>BIC
                <input v-model="contactDraft.payment_bic" autocomplete="off" placeholder="AAAA CH BB">
              </label>
              <label>Langue
                <select v-model="contactDraft.language">
                  <option value="fr">Français</option>
                  <option value="de">Allemand</option>
                  <option value="it">Italien</option>
                  <option value="en">Anglais</option>
                </select>
              </label>
              <label>Adresse
                <input v-model="contactDraft.address_line1" required>
              </label>
              <label>Complément
                <input v-model="contactDraft.address_line2">
              </label>
              <label>NPA
                <input v-model="contactDraft.postal_code" required>
              </label>
              <label>Localité
                <input v-model="contactDraft.city" required>
              </label>
              <label>Pays ISO
                <input v-model="contactDraft.country" maxlength="2" required>
              </label>
            </div>
            <fieldset class="checkbox-group">
              <legend>Rôles</legend>
              <label v-for="role in ['client', 'fournisseur', 'employe', 'autre']" :key="role">
                <input v-model="contactDraft.roles" type="checkbox" :value="role">
                {{ role }}
              </label>
            </fieldset>
            <div class="form-actions">
              <button class="button primary" type="submit" :disabled="store.saving">
                {{ contactDraft.id ? 'Enregistrer le contact' : 'Créer le contact' }}
              </button>
              <button class="button secondary" type="button" @click="contactDialog?.close()">Annuler</button>
            </div>
            </form>
          </ModalDialog>
          <article class="panel">
            <div class="panel-heading">
              <div><p class="eyebrow">Facturation</p><h2>Débiteurs et créanciers</h2></div>
              <strong>{{ filteredReferenceContacts.length }}</strong>
            </div>
            <div class="filter-bar contextual-filter">
              <FormField id="reference-contact-search" label="Rechercher">
                <template #default="{ describedBy }">
                  <input
                    id="reference-contact-search"
                    v-model="contactReferenceSearch"
                    :aria-describedby="describedBy"
                    placeholder="Nom, entreprise, courriel…"
                  >
                </template>
              </FormField>
              <FormField id="reference-contact-status" label="Statut">
                <template #default="{ describedBy }">
                  <select
                    id="reference-contact-status"
                    v-model="contactReferenceStatus"
                    :aria-describedby="describedBy"
                  >
                    <option value="active">Actifs</option>
                    <option value="archived">Archivés</option>
                    <option value="all">Tous</option>
                  </select>
                </template>
              </FormField>
            </div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Contact</th><th>Entreprise</th><th>Rôles</th><th>Offres</th><th>Commandes</th><th>Adresse</th><th>Statut</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="contact in filteredReferenceContacts" :key="contact.id">
                    <td>{{ contactName(contact) }}<br><small>{{ contact.email || '—' }}</small></td>
                    <td>{{ contact.company_contact_name || (contact.type === 'entreprise' ? 'Entreprise' : 'Indépendant') }}</td>
                    <td>{{ contact.roles.join(', ') }}</td>
                    <td>{{ contact.offers_count }}</td>
                    <td>{{ contact.orders_count }}</td>
                    <td>{{ contact.address_line1 }}, {{ contact.postal_code }} {{ contact.city }}</td>
                    <td>{{ contact.active ? 'Actif' : 'Archivé' }}</td>
                    <td class="button-row">
                      <button v-if="contact.active" class="button secondary compact" type="button" @click="editContact(contact)">
                        Modifier
                      </button>
                      <button
                        v-if="contact.active"
                        class="button danger compact"
                        type="button"
                        :disabled="store.saving"
                        @click="deleteContact(contact)"
                      >
                        Supprimer ou archiver
                      </button>
                      <button
                        v-else
                        class="button secondary compact"
                        type="button"
                        :disabled="store.saving"
                        @click="restoreContact(contact)"
                      >
                        Désarchiver
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <EmptyState
              v-if="!filteredReferenceContacts.length"
              title="Aucun contact correspondant"
              description="Modifiez la recherche ou le filtre de statut."
            />
          </article>
        </template>

        <template v-else-if="managedReferences && referenceSection === 'vat'">
          <div class="section-toolbar">
            <div><p class="eyebrow">Valeurs datées</p><h2>Références TVA</h2></div>
            <div v-if="managedReferences.capabilities.vat" class="button-row">
              <button class="button primary" type="button" @click="resetVatCode(); vatDialog?.open()">
                Nouveau code TVA
              </button>
              <button class="button secondary" type="button" @click="exportVatReferences">
                Exporter tout
              </button>
              <label class="button secondary file-button">
                Importer
                <input type="file" accept=".json,application/json" @change="importVatReferences">
              </label>
              <button class="button danger" type="button" @click="clearVatDialog?.open()">
                Tout effacer
              </button>
            </div>
          </div>
          <article class="panel vat-regime-card">
            <div class="panel-heading">
              <div>
                <p class="eyebrow">Dossier · régime daté</p>
                <h2>Avec ou sans TVA</h2>
              </div>
              <button
                v-if="managedReferences.capabilities.vat"
                class="button primary"
                type="button"
                @click="vatRegimeDialog?.open()"
              >
                Modifier le régime TVA
              </button>
            </div>
            <div v-if="managedReferences.vat.regimes[0]" class="entity-readonly-card">
              <div>
                <span>Régime actuel</span>
                <strong>
                  {{
                    managedReferences.vat.regimes[0].status === 'non_assujetti'
                      ? 'Sans TVA · non assujetti'
                      : managedReferences.vat.regimes[0].status === 'volontaire'
                        ? 'Avec TVA · assujettissement volontaire'
                        : 'Avec TVA · assujetti'
                  }}
                </strong>
              </div>
              <div>
                <span>Applicable dès le</span>
                <strong>{{ managedReferences.vat.regimes[0].valid_from }}</strong>
              </div>
              <div>
                <span>N° TVA</span>
                <strong>{{ managedReferences.vat.regimes[0].vat_number || 'Non applicable' }}</strong>
              </div>
              <div>
                <span>Méthode</span>
                <strong>{{ managedReferences.vat.regimes[0].method }}</strong>
              </div>
            </div>
            <p class="help-text">
              Sans TVA, les ventes ne présentent aucun calcul TVA et les achats
              sont saisis TVA comprise sans impôt préalable récupérable. Un
              changement crée une nouvelle valeur datée et conserve l’historique.
            </p>
          </article>
          <ModalDialog
            ref="vatRegimeDialog"
            title="Configurer le régime TVA"
            description="Le nouveau régime prend effet à la date choisie ; le régime précédent est automatiquement fermé."
            wide
          >
            <form class="configuration-form" @submit.prevent="saveVatRegime">
              <div class="configuration-grid">
                <label>Traitement du dossier
                  <select v-model="vatRegimeDraft.status">
                    <option value="non_assujetti">Sans TVA · non assujetti</option>
                    <option value="assujetti">Avec TVA · assujetti</option>
                    <option value="volontaire">Avec TVA · assujettissement volontaire</option>
                  </select>
                </label>
                <label>Applicable dès le
                  <input v-model="vatRegimeDraft.valid_from" type="date" required>
                </label>
                <label v-if="vatRegimeDraft.status !== 'non_assujetti'">Numéro IDE / TVA
                  <input v-model="vatRegimeDraft.vat_number" placeholder="CHE-123.456.789 TVA" required>
                </label>
                <label v-if="vatRegimeDraft.status !== 'non_assujetti'">Méthode
                  <select v-model="vatRegimeDraft.method">
                    <option value="effective">Méthode effective</option>
                    <option value="tdfn">Taux de la dette fiscale nette</option>
                  </select>
                </label>
                <label v-if="vatRegimeDraft.status !== 'non_assujetti'">Mode de décompte
                  <select v-model="vatRegimeDraft.reporting_mode">
                    <option value="convenues">Contre-prestations convenues</option>
                    <option value="recues">Contre-prestations reçues</option>
                  </select>
                </label>
                <label v-if="vatRegimeDraft.status !== 'non_assujetti'">Périodicité
                  <select v-model="vatRegimeDraft.frequency">
                    <option value="mensuelle">Mensuelle</option>
                    <option value="trimestrielle">Trimestrielle</option>
                    <option value="semestrielle">Semestrielle</option>
                    <option value="annuelle">Annuelle</option>
                  </select>
                </label>
              </div>
              <fieldset v-if="vatRegimeDraft.status !== 'non_assujetti'">
                <legend>Comptes TVA</legend>
                <div class="configuration-grid">
                  <label>Impôt préalable · matériel et services
                    <AccountCombobox v-model="vatRegimeDraft.input_material_account_id" :options="managedReferences.vat.accounts" required />
                  </label>
                  <label>Impôt préalable · investissements
                    <AccountCombobox v-model="vatRegimeDraft.input_investment_account_id" :options="managedReferences.vat.accounts" required />
                  </label>
                  <label>TVA due
                    <AccountCombobox v-model="vatRegimeDraft.vat_due_account_id" :options="managedReferences.vat.accounts" required />
                  </label>
                  <label>Décompte TVA
                    <AccountCombobox v-model="vatRegimeDraft.vat_settlement_account_id" :options="managedReferences.vat.accounts" required />
                  </label>
                  <label>Corrections TVA
                    <AccountCombobox v-model="vatRegimeDraft.corrections_account_id" :options="managedReferences.vat.accounts" required />
                  </label>
                </div>
              </fieldset>
              <div class="form-actions">
                <button class="button secondary" type="button" @click="vatRegimeDialog?.close()">Annuler</button>
                <button class="button primary" :disabled="store.saving">Enregistrer le nouveau régime</button>
              </div>
            </form>
          </ModalDialog>
          <ModalDialog
            ref="vatDialog"
            :title="vatDraft.id ? 'Modifier le code TVA' : 'Nouveau code TVA'"
            description="Le code relie un traitement fiscal daté à son taux légal et à son compte."
            wide
            @closed="resetVatCode"
          >
            <form class="configuration-form" @submit.prevent="saveVatCode">
            <div class="configuration-grid">
              <label>Code
                <input v-model="vatDraft.code" maxlength="20" required>
              </label>
              <label>Libellé
                <input v-model="vatDraft.label" required>
              </label>
              <label>Traitement
                <select v-model="vatDraft.treatment" @change="syncVatRate">
                  <option value="normal">Normal</option>
                  <option value="reduit">Réduit</option>
                  <option value="special">Spécial</option>
                  <option value="exonere">Exonéré</option>
                  <option value="exclu">Exclu</option>
                  <option value="hors_champ">Hors champ</option>
                  <option value="acquisition">Acquisition</option>
                  <option value="import">Importation</option>
                  <option value="correction">Correction</option>
                </select>
              </label>
              <label>Nature
                <select v-model="vatDraft.nature">
                  <option value="collectee">TVA collectée</option>
                  <option value="prealable">Impôt préalable</option>
                  <option value="acquisition">Acquisition</option>
                  <option value="non_taxable">Non taxable</option>
                  <option value="correction">Correction</option>
                </select>
              </label>
              <label>Taux légal
                <select
                  v-model.number="vatDraft.legal_rate_id"
                  :required="vatTreatmentsWithRate.includes(vatDraft.treatment)"
                  :disabled="!vatTreatmentsWithRate.includes(vatDraft.treatment)"
                >
                  <option :value="0">Aucun</option>
                  <option v-for="rate in managedReferences.vat.legal_rates" :key="rate.id" :value="rate.id">
                    {{ rate.label }} — {{ percentFromBasisPoints(rate.rate_bp) }} %
                  </option>
                </select>
              </label>
              <label>Compte TVA
                <AccountCombobox
                  v-model="vatDraft.account_id"
                  :options="managedReferences.vat.accounts"
                  placeholder="Aucun"
                />
              </label>
              <label>Déduction par défaut (%)
                <input v-model="vatDraft.default_deduction_percent" inputmode="decimal" required>
              </label>
              <label>Chiffre AFC
                <input v-model="vatDraft.afc_box">
              </label>
              <label>Valable dès le
                <input v-model="vatDraft.valid_from" type="date" required>
              </label>
              <label>Valable jusqu’au
                <input v-model="vatDraft.valid_until" type="date">
              </label>
            </div>
            <label class="checkbox-field">
              <input v-model="vatDraft.deduction_right" type="checkbox">
              Ouvre un droit à déduction
            </label>
            <label class="checkbox-field">
              <input v-model="vatDraft.active" type="checkbox">
              Code actif
            </label>
            <div class="form-actions">
              <button class="button primary" type="submit" :disabled="store.saving">
                {{ vatDraft.id ? 'Enregistrer les modifications' : 'Créer le code TVA' }}
              </button>
              <button
                class="button secondary"
                type="button"
                :disabled="store.saving"
                @click="vatDialog?.close()"
              >Annuler</button>
            </div>
            </form>
          </ModalDialog>
          <article class="panel">
            <div class="panel-heading">
              <div><p class="eyebrow">Référence légale</p><h2>Taux TVA suisses</h2></div>
            </div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Catégorie</th><th>Taux</th><th>Validité</th><th>Vérifié le</th></tr></thead>
                <tbody>
                  <tr v-for="rate in managedReferences.vat.legal_rates" :key="rate.id">
                    <td>{{ rate.label }}</td>
                    <td>{{ percentFromBasisPoints(rate.rate_bp) }} %</td>
                    <td>{{ rate.valid_from }} — {{ rate.valid_until || 'sans fin' }}</td>
                    <td>{{ rate.verified_on }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </article>
          <article class="panel">
            <div class="panel-heading">
              <div><p class="eyebrow">Dossier</p><h2>Codes TVA configurés</h2></div>
              <strong>{{ managedReferences.vat.codes.length }}</strong>
            </div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Code</th><th>Traitement</th><th>Taux</th><th>Compte</th><th>Validité</th><th>État</th><th>Actions</th></tr></thead>
                <tbody>
                  <tr v-for="code in managedReferences.vat.codes" :key="code.id">
                    <td><strong>{{ code.code }}</strong><br><small>{{ code.label }}</small></td>
                    <td>{{ code.treatment }} · {{ code.nature }}</td>
                    <td>{{ code.rate_bp === null ? '—' : `${percentFromBasisPoints(code.rate_bp)} %` }}</td>
                    <td>{{ code.account || '—' }}</td>
                    <td>{{ code.valid_from }} — {{ code.valid_until || 'sans fin' }}</td>
                    <td>
                      <span class="status-badge" :class="code.active ? 'status-ouverte' : 'status-fermee'">
                        {{ code.active ? 'Actif' : 'Inactif' }}
                      </span>
                    </td>
                    <td>
                      <div class="table-actions">
                        <button class="button secondary compact" type="button" @click="editVatCode(code)">
                          Modifier
                        </button>
                        <button class="button secondary compact" type="button" @click="toggleVatCode(code)">
                          {{ code.active ? 'Désactiver' : 'Réactiver' }}
                        </button>
                        <button
                          v-if="!code.used"
                          class="button danger compact"
                          type="button"
                          @click="deleteVatCode(code)"
                        >Supprimer</button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <EmptyState
              v-if="!managedReferences.vat.codes.length"
              title="Aucun code TVA"
              description="Les taux légaux existent, mais aucun code n’est encore affecté au dossier."
            />
          </article>
        </template>

        <template v-else-if="managedReferences && referenceSection === 'payroll'">
          <div class="section-toolbar">
            <div><p class="eyebrow">Genève · valeurs en pourcentage</p><h2>Charges sociales</h2></div>
            <button
              v-if="managedReferences.capabilities.payroll"
              class="button primary"
              type="button"
              @click="payrollRatesDialog?.open()"
            >Taux annuels des charges sociales</button>
          </div>
          <ModalDialog
            ref="payrollRatesDialog"
            title="Taux annuels des charges sociales"
            description="Enregistrez un millésime contrôlé avec sa source et sa date de vérification."
            wide
          >
            <form class="configuration-form" @submit.prevent="savePayrollRates">
            <p class="field-hint">
              Saisissez ici un millésime contrôlé manuellement ou utilisez
              Salaires → Annuels pour prévisualiser les taux OCAS sans écriture.
            </p>
            <div class="csv-transfer">
              <div>
                <strong>Import et export CSV</strong>
                <small>
                  Les pourcentages utilisent jusqu’à quatre décimales. Toutes les
                  lignes sont contrôlées avant l’enregistrement.
                </small>
              </div>
              <div class="button-row">
                <button
                  class="button secondary"
                  type="button"
                  @click="exportPayrollRates"
                >
                  Exporter les taux CSV
                </button>
                <label
                  v-if="managedReferences.capabilities.payroll"
                  class="button secondary file-button"
                >
                  Importer des taux CSV
                  <input
                    aria-label="Fichier CSV des taux annuels"
                    type="file"
                    accept=".csv,text/csv"
                    @change="importPayrollRates"
                  >
                </label>
              </div>
            </div>
            <div class="configuration-grid">
              <label>Année
                <input v-model.number="payrollDraft.year" type="number" min="2000" max="9999" required>
              </label>
              <label>Source
                <input v-model="payrollDraft.source" required>
              </label>
              <label>Vérifié le
                <input v-model="payrollDraft.verified_on" type="date" required>
              </label>
              <label v-for="field in payrollRateFields" :key="field.key">
                {{ field.label }} (%)
                <input v-model="payrollDraft[field.key]" inputmode="decimal" required>
              </label>
            </div>
            <div class="form-actions">
              <button class="button primary" type="submit" :disabled="store.saving">
                Enregistrer les taux annuels
              </button>
              <button class="button secondary" type="button" @click="payrollRatesDialog?.close()">Annuler</button>
            </div>
            </form>
          </ModalDialog>
          <article class="panel">
            <div class="panel-heading">
              <div><p class="eyebrow">Historique immuable après usage</p><h2>Millésimes configurés</h2></div>
              <strong>{{ managedReferences.payroll.rates.length }}</strong>
            </div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Année</th><th>AVS</th><th>AC</th><th>LPP</th><th>Source</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="rate in managedReferences.payroll.rates" :key="Number(rate.id)">
                    <td>{{ rate.year }}</td>
                    <td>{{ percentFromPpm(Number(rate.avs_ppm)) }} %</td>
                    <td>{{ percentFromPpm(Number(rate.ac_ppm)) }} %</td>
                    <td>{{ percentFromPpm(Number(rate.lpp_ppm)) }} %</td>
                    <td>{{ rate.source }}<br><small>Vérifié le {{ rate.verified_on }}</small></td>
                    <td>
                      <button class="button secondary compact" type="button" @click="loadPayrollRates(rate)">
                        Reprendre
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <EmptyState
              v-if="!managedReferences.payroll.rates.length"
              title="Aucun taux salarial annuel"
              description="Saisissez un millésime contrôlé ou importez-le depuis la source OCAS."
            />
          </article>
        </template>

        <template v-else-if="managedReferences && referenceSection === 'journals'">
          <form
            v-if="managedReferences.capabilities.accounting_setup"
            class="panel configuration-form"
            @submit.prevent="saveJournal"
          >
            <div class="panel-heading">
              <div>
                <p class="eyebrow">Comptabilité</p>
                <h2>{{ journalDraft.id ? 'Modifier le journal' : 'Nouveau journal' }}</h2>
              </div>
            </div>
            <div class="configuration-grid">
              <label>Code
                <input v-model="journalDraft.code" maxlength="12" required>
              </label>
              <label>Libellé
                <input v-model="journalDraft.label" required>
              </label>
              <label>Type
                <select v-model="journalDraft.type">
                  <option
                    v-for="type in managedReferences.accounting_setup.journal_types"
                    :key="type"
                    :value="type"
                  >
                    {{ type }}
                  </option>
                </select>
              </label>
            </div>
            <label class="checkbox-field">
              <input v-model="journalDraft.active" type="checkbox">
              Journal actif
            </label>
            <div class="form-actions">
              <button class="button primary" type="submit" :disabled="store.saving">
                Enregistrer le journal
              </button>
              <button
                v-if="journalDraft.id"
                class="button secondary"
                type="button"
                @click="resetJournalDraft"
              >
                Annuler la modification
              </button>
            </div>
          </form>
          <article class="panel">
            <div class="panel-heading">
              <div><p class="eyebrow">Source unique</p><h2>Journaux comptables</h2></div>
              <strong>{{ managedReferences.accounting_setup.journals.length }}</strong>
            </div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Code</th><th>Libellé</th><th>Type</th><th>Statut</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="journal in managedReferences.accounting_setup.journals" :key="journal.id">
                    <td><strong>{{ journal.code }}</strong></td>
                    <td>{{ journal.label }}</td>
                    <td>{{ journal.type }}</td>
                    <td>{{ journal.active ? 'Actif' : 'Inactif' }}</td>
                    <td>
                      <button class="button secondary compact" type="button" @click="editJournal(journal)">
                        Modifier
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </article>
        </template>

        <template v-else-if="managedReferences && referenceSection === 'exercises'">
          <div class="section-toolbar">
            <div><p class="eyebrow">Dossier</p><h2>Exercices comptables</h2></div>
            <div v-if="managedReferences.capabilities.accounting_setup" class="button-row">
              <button class="button primary" type="button" @click="exerciseDialog?.open()">Nouvel exercice comptable</button>
              <button class="button secondary" type="button" @click="periodDialog?.open()">Nouvelle période</button>
            </div>
          </div>
          <p>
            L’exercice délimite l’année de reporting et de clôture. Ses périodes
            découpent cette enveloppe pour ouvrir ou verrouiller les saisies.
          </p>
          <ModalDialog
            ref="exerciseDialog"
            title="Nouvel exercice comptable"
            description="Définissez l’enveloppe annuelle de reporting et de clôture."
          >
            <form class="configuration-form" @submit.prevent="createExercise">
            <div class="configuration-grid">
              <label>Libellé
                <input v-model="exerciseDraft.label" required>
              </label>
              <label>Début
                <input v-model="exerciseDraft.start_date" type="date" required>
              </label>
              <label>Fin
                <input v-model="exerciseDraft.end_date" type="date" required>
              </label>
            </div>
            <div class="form-actions">
              <button class="button primary" type="submit" :disabled="store.saving">
                Créer l’exercice
              </button>
              <button class="button secondary" type="button" @click="exerciseDialog?.close()">Annuler</button>
            </div>
            </form>
          </ModalDialog>
          <article class="panel">
            <div class="panel-heading">
              <div><p class="eyebrow">Dossier</p><h2>Exercices comptables</h2></div>
            </div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Exercice</th><th>Début</th><th>Fin</th><th>Statut</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="exercise in managedReferences.accounting_setup.exercises" :key="exercise.id">
                    <td><strong>{{ exercise.label }}</strong></td>
                    <td>{{ exercise.start_date }}</td>
                    <td>{{ exercise.end_date }}</td>
                    <td>
                      <span
                        class="status-badge"
                        :class="exercise.status === 'ouvert' ? 'status-ouvert' : 'status-ferme'"
                      >{{ exerciseStatusLabel(exercise.status) }}</span>
                    </td>
                    <td>
                      <button
                        class="button secondary compact"
                        type="button"
                        :disabled="store.saving"
                        @click="toggleExercise(exercise)"
                      >
                        {{ exercise.status === 'ouvert' ? 'Fermer' : 'Rouvrir' }}
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </article>
          <ModalDialog
            ref="periodDialog"
            title="Nouvelle période"
            description="Découpez un exercice pour ouvrir ou verrouiller les saisies."
          >
            <form class="configuration-form" @submit.prevent="createPeriod">
            <div class="configuration-grid">
              <label>Exercice
                <select v-model.number="periodDraft.exercise_id" required>
                  <option :value="0">Choisir…</option>
                  <option
                    v-for="exercise in managedReferences.accounting_setup.exercises"
                    :key="exercise.id"
                    :value="exercise.id"
                  >
                    {{ exercise.label }}
                  </option>
                </select>
              </label>
              <label>Libellé
                <input v-model="periodDraft.label" required>
              </label>
              <label>Début
                <input v-model="periodDraft.start_date" type="date" required>
              </label>
              <label>Fin
                <input v-model="periodDraft.end_date" type="date" required>
              </label>
            </div>
            <div class="form-actions">
              <button class="button primary" type="submit" :disabled="store.saving">
                Créer la période
              </button>
              <button class="button secondary" type="button" @click="periodDialog?.close()">Annuler</button>
            </div>
            </form>
          </ModalDialog>
          <article class="panel">
            <div class="panel-heading">
              <div><p class="eyebrow">Contrôle de saisie</p><h2>Périodes comptables</h2></div>
            </div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Période</th><th>Exercice</th><th>Dates</th><th>Statut</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="period in managedReferences.accounting_setup.periods" :key="period.id">
                    <td><strong>{{ period.label }}</strong></td>
                    <td>{{ period.exercise }}</td>
                    <td>{{ period.start_date }} — {{ period.end_date }}</td>
                    <td>
                      <span
                        class="status-badge"
                        :class="period.status === 'ouverte' ? 'status-ouvert' : 'status-fermee'"
                      >{{ periodStatusLabel(period.status) }}</span>
                    </td>
                    <td>
                      <button
                        class="button secondary compact"
                        type="button"
                        :disabled="store.saving"
                        @click="togglePeriod(period)"
                      >
                        {{ period.status === 'ouverte' ? 'Fermer' : 'Rouvrir' }}
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </article>
        </template>
      </section>

      <section
        v-else-if="activeTab === 'salaires' && managedReferences"
        class="configuration-stack"
      >
        <form
          class="panel configuration-form"
          @submit.prevent="savePayrollEmployerSettings"
        >
          <div class="panel-heading">
            <div>
              <p class="eyebrow">Employeur et comptabilisation</p>
              <h2>Paramètres salariaux</h2>
            </div>
            <span
              class="status-badge"
              :class="managedReferences.payroll.employer.configured
                ? 'status-ouverte'
                : 'status-fermee'"
            >
              {{ managedReferences.payroll.employer.configured
                ? 'Configuré'
                : 'À enregistrer' }}
            </span>
          </div>
          <div class="configuration-grid">
            <label>Heures hebdomadaires
              <input
                v-model="payrollSettings.weekly_hours"
                inputmode="decimal"
                required
              >
            </label>
          </div>
          <div class="form-actions">
            <button
              class="button primary"
              type="submit"
              :disabled="store.saving || !managedReferences.capabilities.payroll"
            >
              Enregistrer les paramètres employeur
            </button>
          </div>
        </form>

        <form
          class="panel configuration-form"
          @submit.prevent="savePayrollMappingSettings"
        >
          <div class="panel-heading">
            <div>
              <p class="eyebrow">Grand livre</p>
              <h2>Comptes de salaires</h2>
            </div>
          </div>
          <div class="configuration-grid">
            <label v-for="[key, label] in payrollMappingFields" :key="key">
              {{ label }}
              <AccountCombobox
                v-model="payrollSettings.mapping[key]"
                :options="managedReferences.payroll.accounts"
                placeholder="Choisir…"
                required
              />
            </label>
          </div>
          <div class="form-actions">
            <button
              class="button primary"
              type="submit"
              :disabled="store.saving || !managedReferences.capabilities.payroll"
            >
              Enregistrer les comptes de salaires
            </button>
          </div>
        </form>
      </section>

      <section v-else-if="activeTab === 'audit'" class="panel">
        <div class="panel-heading">
          <div>
            <p class="eyebrow">Traçabilité</p>
            <h2>Dernières modifications sensibles</h2>
          </div>
          <button class="button danger" type="button" @click="clearAuditDialog?.open()">
            Effacer tout l’audit
          </button>
        </div>
        <DataTable
          caption="Vingt derniers événements d’audit du dossier"
          :columns="[
            { key: 'created_at', label: 'Date' },
            { key: 'actor', label: 'Acteur' },
            { key: 'action', label: 'Action' },
            { key: 'target', label: 'Cible' }
          ]"
        :rows="auditRows"
        />
      </section>

      <EmptyState
        v-else
        title="Onglet de configuration inconnu"
        description="Choisissez un onglet de configuration disponible."
      />
    </template>
    <ConfirmDialog
      ref="clearVatDialog"
      title="Effacer toutes les références TVA ?"
      confirm-label="Tout effacer"
      tone="danger"
      @confirm="clearVatReferences"
    >
      <p>Cette action supprime ensemble les codes et le régime TVA technique du dossier. Toute période ou écriture TVA historique bloque l’opération afin de préserver la traçabilité.</p>
    </ConfirmDialog>
    <ConfirmDialog
      ref="removeContactDialog"
      title="Supprimer ou archiver ce contact ?"
      confirm-label="Continuer"
      tone="danger"
      @confirm="deleteContact()"
    >
      <p>
        « {{ pendingContactRemoval ? contactName(pendingContactRemoval) : '' }} »
        sera supprimé s’il n’a aucun historique. S’il possède un document, un
        paiement ou une donnée salariale, il sera archivé et restera consultable.
      </p>
    </ConfirmDialog>
    <ConfirmDialog
      ref="removeTreasuryDialog"
      title="Supprimer ou archiver ce compte ?"
      confirm-label="Continuer"
      tone="danger"
      @confirm="removeTreasuryAccount()"
    >
      <p>
        « {{ pendingTreasuryRemoval?.label || '' }} » sera supprimé s’il n’a
        jamais été utilisé, sinon archivé. Un compte choisi pour la facturation
        doit d’abord être remplacé sous Entité.
      </p>
    </ConfirmDialog>
    <ConfirmDialog
      ref="clearAuditDialog"
      title="Effacer tout l’audit du dossier ?"
      confirm-label="Effacer définitivement"
      tone="danger"
      @confirm="clearAudit"
    >
      <p>Les événements d’audit du dossier ne pourront pas être récupérés.</p>
    </ConfirmDialog>
  </template>
</template>
