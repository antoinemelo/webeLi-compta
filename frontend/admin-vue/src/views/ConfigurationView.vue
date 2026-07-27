<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import AccountCombobox from '@/components/ui/AccountCombobox.vue';
import CompactTabs from '@/components/ui/CompactTabs.vue';
import DataTable from '@/components/ui/DataTable.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import FormField from '@/components/ui/FormField.vue';
import SkeletonBlock from '@/components/ui/SkeletonBlock.vue';
import { markUnsavedChanges } from '@/composables/unsavedChanges';
import { referenceNavigation, subNavigation } from '@/router/navigation';
import { useConfigurationStore } from '@/stores/configuration';
import { useContextStore } from '@/stores/context';
import { useNotificationStore } from '@/stores/notifications';

const route = useRoute();
const context = useContextStore();
const store = useConfigurationStore();
const notifications = useNotificationStore();
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
  billing_iban: '',
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
const contactDraft = reactive({
  id: 0,
  version: 0,
  type: 'entreprise' as 'entreprise' | 'personne',
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
    id: item.id,
    code: item.code,
    label: item.label,
    direction: directionLabel(item.direction),
    calculation: `${item.days} jour(s)${item.end_of_month ? ', fin de mois' : ''}`,
    validity: `${item.valid_from} — ${item.valid_until || 'sans fin'}`,
    default: item.is_default ? 'Oui' : 'Non'
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
      billing_iban: value.organization.billing_iban,
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

function syncVatRate(): void {
  if (!vatTreatmentsWithRate.includes(vatDraft.treatment)) {
    vatDraft.legal_rate_id = 0;
  }
}

async function createContact(): Promise<void> {
  await store.createContact({ ...contactDraft, roles: [...contactDraft.roles] });
  Object.assign(contactDraft, {
    id: 0,
    version: 0,
    type: 'entreprise',
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
  notifications.push('Contact enregistré dans le registre unique.', 'success');
}

function editContact(
  contact: NonNullable<typeof managedReferences.value>['contacts'][number]
): void {
  Object.assign(contactDraft, {
    id: contact.id,
    version: contact.version,
    type: contact.type,
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
  } catch (error) {
    if (error instanceof Error && !store.error) store.error = error.message;
  }
}

function loadPayrollRates(source: Record<string, string | number | null>): void {
  payrollDraft.year = Number(source.year);
  payrollDraft.source = String(source.source || '');
  payrollDraft.verified_on = String(source.verified_on || today);
  payrollRateFields.forEach(({ key }) => {
    payrollDraft[key] = percentFromPpm(Number(source[key] || 0));
  });
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
}

async function saveTreasuryAccount(): Promise<void> {
  await store.saveTreasuryAccount({ ...treasuryDraft });
  resetTreasuryDraft();
  notifications.push('Compte de trésorerie enregistré.', 'success');
}

async function saveCurrency(): Promise<void> {
  await store.saveCurrency({ ...currencyDraft });
  notifications.push('Devise du dossier enregistrée.', 'success');
}

async function saveExchangeRate(): Promise<void> {
  await store.saveExchangeRate({ ...exchangeRateDraft });
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
  Object.assign(exerciseDraft, {
    id: 0,
    version: 0,
    label: '',
    status: 'ouvert'
  });
  notifications.push('Exercice comptable créé.', 'success');
}

async function toggleExercise(
  exercise: NonNullable<typeof managedReferences.value>['accounting_setup']['exercises'][number]
): Promise<void> {
  await store.saveExercise({
    ...exercise,
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
  notifications.push('Période comptable créée.', 'success');
}

async function togglePeriod(
  period: NonNullable<typeof managedReferences.value>['accounting_setup']['periods'][number]
): Promise<void> {
  await store.savePeriod({
    ...period,
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
  notifications.push('Condition de paiement datée créée.', 'success');
}

async function saveDefault(direction: 'client' | 'fournisseur'): Promise<void> {
  const conditionId = direction === 'client' ? clientDefault.value : supplierDefault.value;
  const validFrom = direction === 'client' ? clientDefaultFrom.value : supplierDefaultFrom.value;
  if (!conditionId) return;
  await store.savePaymentDefault(direction, conditionId, validFrom);
  notifications.push('Nouveau défaut daté enregistré sans effet rétroactif.', 'success');
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
        <div class="configuration-grid">
          <FormField id="organization-name" label="Nom usuel">
            <template #default="{ describedBy }">
              <input id="organization-name" v-model="identity.name" :aria-describedby="describedBy" required>
            </template>
          </FormField>
          <FormField id="legal-name" label="Raison sociale">
            <template #default="{ describedBy }">
              <input id="legal-name" v-model="identity.legal_name" readonly :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="legal-form" label="Forme juridique">
            <template #default="{ describedBy }">
              <input id="legal-form" v-model="identity.legal_form" readonly :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="uid" label="Numéro IDE / UID">
            <template #default="{ describedBy }">
              <input id="uid" v-model="identity.uid" readonly :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="address-line1" label="Adresse">
            <template #default="{ describedBy }">
              <input id="address-line1" v-model="identity.address_line1" readonly :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="address-line2" label="Complément">
            <template #default="{ describedBy }">
              <input id="address-line2" v-model="identity.address_line2" readonly :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="postal-code" label="NPA">
            <template #default="{ describedBy }">
              <input id="postal-code" v-model="identity.postal_code" readonly :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="city" label="Localité">
            <template #default="{ describedBy }">
              <input id="city" v-model="identity.city" readonly :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="canton" label="Canton">
            <template #default="{ describedBy }">
              <input id="canton" v-model="identity.canton" maxlength="2" readonly :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="country" label="Pays ISO">
            <template #default="{ describedBy }">
              <input id="country" v-model="identity.country" maxlength="2" readonly :aria-describedby="describedBy">
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
            id="billing-iban"
            label="IBAN de facturation"
            hint="IBAN CH ou LI utilisé dans la section Swiss QR des factures clients."
          >
            <template #default="{ describedBy }">
              <input
                id="billing-iban"
                v-model="identity.billing_iban"
                autocomplete="off"
                placeholder="CH…"
                :aria-describedby="describedBy"
              >
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
        <form class="panel configuration-form" @submit.prevent="createTerm">
          <div class="panel-heading">
            <div>
              <p class="eyebrow">Valeurs datées</p>
              <h2>Nouvelle condition de paiement</h2>
            </div>
          </div>
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
            <label class="checkbox-field">
              <input v-model="paymentTerm.end_of_month" type="checkbox">
              Reporter au dernier jour du mois obtenu
            </label>
          </div>
          <p class="field-hint">{{ configuration.definitions.payment_due_date }}</p>
          <div class="form-actions">
            <button class="button primary" type="submit" :disabled="store.saving">Créer la condition</button>
          </div>
        </form>

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
              { key: 'default', label: 'Défaut actuel' }
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
          <form
            v-if="managedReferences.capabilities.treasury"
            class="panel configuration-form"
            @submit.prevent="saveTreasuryAccount"
          >
            <div class="panel-heading">
              <div>
                <p class="eyebrow">Grand livre lié</p>
                <h2>{{ treasuryDraft.id ? 'Modifier le compte' : 'Nouveau compte de trésorerie' }}</h2>
              </div>
            </div>
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
            </div>
          </form>
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
                    <td>{{ account.active ? 'Actif' : 'Inactif' }}</td>
                    <td>
                      <button class="button secondary compact" type="button" @click="editTreasuryAccount(account)">
                        Modifier
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
            <form v-if="managedReferences.capabilities.currencies" class="configuration-grid" @submit.prevent="saveCurrency">
              <label>Code ISO
                <input v-model="currencyDraft.currency" maxlength="3" required>
              </label>
              <label class="checkbox-field">
                <input v-model="currencyDraft.active" type="checkbox"> Devise active
              </label>
              <button class="button primary" type="submit" :disabled="store.saving">Enregistrer</button>
            </form>
            <ul class="summary-list">
              <li v-for="item in managedReferences.currencies.currencies" :key="item.code">
                <strong>{{ item.code }}</strong>
                <span>{{ item.is_base ? 'Devise de base' : item.active ? 'Active' : 'Inactive' }}</span>
              </li>
            </ul>
          </article>

          <form v-if="managedReferences.capabilities.currencies" class="panel configuration-form" @submit.prevent="saveExchangeRate">
            <div class="panel-heading">
              <div><p class="eyebrow">Sans flottants</p><h2>Nouveau taux daté</h2></div>
            </div>
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
            <button class="button primary" type="submit" :disabled="store.saving">Ajouter le taux</button>
          </form>

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
          <form
            v-if="managedReferences.capabilities.contacts"
            class="panel configuration-form"
            @submit.prevent="createContact"
          >
            <div class="panel-heading">
              <div>
                <p class="eyebrow">Registre partagé</p>
                <h2>{{ contactDraft.id ? 'Modifier le contact' : 'Nouveau débiteur ou créancier' }}</h2>
              </div>
            </div>
            <div class="configuration-grid">
              <label>Type
                <select v-model="contactDraft.type">
                  <option value="entreprise">Entreprise</option>
                  <option value="personne">Personne</option>
                </select>
              </label>
              <label>Raison sociale
                <input v-model="contactDraft.company" :required="contactDraft.type === 'entreprise'">
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
            </div>
          </form>
          <article class="panel">
            <div class="panel-heading">
              <div><p class="eyebrow">Facturation</p><h2>Débiteurs et créanciers</h2></div>
              <strong>{{ managedReferences.contacts.length }}</strong>
            </div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Contact</th><th>Rôles</th><th>Adresse</th><th>E-mail</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="contact in managedReferences.contacts" :key="contact.id">
                    <td>{{ contactName(contact) }}</td>
                    <td>{{ contact.roles.join(', ') }}</td>
                    <td>{{ contact.address_line1 }}, {{ contact.postal_code }} {{ contact.city }}</td>
                    <td>{{ contact.email || '—' }}</td>
                    <td>
                      <button class="button secondary compact" type="button" @click="editContact(contact)">
                        Modifier
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <EmptyState
              v-if="!managedReferences.contacts.length"
              title="Aucun contact"
              description="Créez le premier contact multi-rôles du dossier."
            />
          </article>
        </template>

        <template v-else-if="managedReferences && referenceSection === 'vat'">
          <form
            v-if="managedReferences.capabilities.vat"
            class="panel configuration-form"
            @submit.prevent="saveVatCode"
          >
            <div class="panel-heading">
              <div>
                <p class="eyebrow">Valeur datée</p>
                <h2>{{ vatDraft.id ? 'Modifier le code TVA' : 'Nouveau code TVA' }}</h2>
              </div>
            </div>
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
                v-if="vatDraft.id"
                class="button secondary"
                type="button"
                :disabled="store.saving"
                @click="resetVatCode"
              >Annuler</button>
            </div>
          </form>
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
          <form
            v-if="managedReferences.capabilities.payroll"
            class="panel configuration-form"
            @submit.prevent="savePayrollRates"
          >
            <div class="panel-heading">
              <div>
                <p class="eyebrow">Genève · valeurs en pourcentage</p>
                <h2>Taux annuels des charges sociales</h2>
              </div>
            </div>
            <p class="field-hint">
              Saisissez ici un millésime contrôlé manuellement ou utilisez
              Salaires → Annuels pour prévisualiser les taux OCAS sans écriture.
            </p>
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
            </div>
          </form>
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
          <article class="panel">
            <div class="panel-heading">
              <div><p class="eyebrow">Organisation temporelle</p><h2>Exercices et périodes</h2></div>
            </div>
            <p>
              L’exercice délimite l’année de reporting et de clôture. Ses périodes
              découpent cette enveloppe pour ouvrir ou verrouiller les saisies.
            </p>
          </article>
          <form
            v-if="managedReferences.capabilities.accounting_setup"
            class="panel configuration-form"
            @submit.prevent="createExercise"
          >
            <div class="panel-heading">
              <div><p class="eyebrow">Périmètre temporel</p><h2>Nouvel exercice comptable</h2></div>
            </div>
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
            </div>
          </form>
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
                    <td>{{ exercise.status }}</td>
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
          <form
            v-if="managedReferences.capabilities.accounting_setup"
            class="panel configuration-form"
            @submit.prevent="createPeriod"
          >
            <div class="panel-heading">
              <div><p class="eyebrow">Verrouillage comptable</p><h2>Nouvelle période</h2></div>
            </div>
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
            </div>
          </form>
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
                    <td>{{ period.status }}</td>
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
          <p class="field-hint">
            L’identité et les coordonnées ci-dessous proviennent de
            <RouterLink to="/configuration">Configuration → Entité</RouterLink>.
            Elles sont reprises automatiquement dans les calculs et les futures fiches.
          </p>
          <div class="configuration-grid">
            <label>Employeur
              <input
                :value="managedReferences.payroll.employer.name"
                readonly
                aria-readonly="true"
              >
            </label>
            <label>Adresse
              <input
                :value="managedReferences.payroll.employer.address"
                readonly
                aria-readonly="true"
              >
            </label>
            <label>NPA
              <input
                :value="managedReferences.payroll.employer.postal_code"
                readonly
                aria-readonly="true"
              >
            </label>
            <label>Localité
              <input
                :value="managedReferences.payroll.employer.city"
                readonly
                aria-readonly="true"
              >
            </label>
            <label>E-mail
              <input
                :value="managedReferences.payroll.employer.email"
                readonly
                aria-readonly="true"
              >
            </label>
            <label>Téléphone
              <input
                :value="managedReferences.payroll.employer.phone"
                readonly
                aria-readonly="true"
              >
            </label>
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

      <section v-else-if="activeTab === 'acces' && managedReferences" class="configuration-stack">
        <article class="panel">
          <div class="panel-heading">
            <div><p class="eyebrow">Gouvernance centralisée</p><h2>Accès aux structures</h2></div>
          </div>
          <p>
            La gestion des rôles se fait désormais dans l’arborescence
            <RouterLink to="/organisations-dossiers">Organisations et dossiers</RouterLink>.
            Cette gestion impose une
            prévisualisation, un contrôle de version et protège le dernier
            administrateur.
          </p>
        </article>
      </section>

      <section v-else-if="activeTab === 'audit'" class="panel">
        <div class="panel-heading">
          <div>
            <p class="eyebrow">Traçabilité</p>
            <h2>Dernières modifications sensibles</h2>
          </div>
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
  </template>
</template>
