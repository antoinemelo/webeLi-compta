<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import CompactTabs from '@/components/ui/CompactTabs.vue';
import DataTable from '@/components/ui/DataTable.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import FormField from '@/components/ui/FormField.vue';
import SkeletonBlock from '@/components/ui/SkeletonBlock.vue';
import { runtimeConfig } from '@/config';
import { markUnsavedChanges } from '@/composables/unsavedChanges';
import { subNavigation } from '@/router/navigation';
import { useConfigurationStore } from '@/stores/configuration';
import { useContextStore } from '@/stores/context';
import { useNotificationStore } from '@/stores/notifications';

const route = useRoute();
const context = useContextStore();
const store = useConfigurationStore();
const notifications = useNotificationStore();
const tabs = subNavigation.settings;
const activeTab = computed(() => String(route.params.tab || 'entity'));
const configuration = computed(() => store.configuration);
const managedReferences = computed(() => store.managedReferences);
const canManage = computed(() => context.can('dossier.manage'));
const today = new Date().toISOString().slice(0, 10);
const requestedReferenceSection = String(route.query.section || 'overview');
const referenceSection = ref<'overview' | 'contacts' | 'vat' | 'payroll'>(
  ['contacts', 'vat', 'payroll'].includes(requestedReferenceSection)
    ? requestedReferenceSection as 'contacts' | 'vat' | 'payroll'
    : 'overview'
);

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
  language: 'fr',
  roles: ['client'] as string[],
  address_line1: '',
  address_line2: '',
  postal_code: '',
  city: '',
  country: 'CH'
});
const vatDraft = reactive({
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

const referenceLabels: Record<string, string> = {
  bank_accounts: 'Comptes bancaires et de trésorerie',
  vat_codes: 'Codes et taux TVA',
  payroll_rates: 'Taux de charges sociales',
  chart_of_accounts: 'Plan comptable',
  journals: 'Journaux',
  exercises: 'Exercices comptables',
  periods: 'Périodes',
  contacts: 'Débiteurs et créanciers',
  users: 'Utilisateurs'
};
const referenceCards = computed(() =>
  Object.entries(configuration.value?.references ?? {}).map(([key, value]) => ({
    key,
    label: referenceLabels[key] || key,
    ...value
  }))
);
const visibleReferenceCards = computed(() =>
  referenceCards.value.filter((item) =>
    activeTab.value === 'acces'
      ? item.key === 'users'
      : !['users'].includes(item.key)
  )
);
const managedReferenceSections: Record<string, 'contacts' | 'vat' | 'payroll'> = {
  contacts: 'contacts',
  vat_codes: 'vat',
  payroll_rates: 'payroll'
};
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
      base_currency: value.dossier.base_currency
    });
    markUnsavedChanges(false);
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

function legacyUrl(path: string): string {
  return `${runtimeConfig.baseUrl}${path}`;
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
    language: contact.language,
    roles: [...contact.roles],
    address_line1: contact.address_line1,
    address_line2: contact.address_line2,
    postal_code: contact.postal_code,
    city: contact.city,
    country: contact.country
  });
}

async function createVatCode(): Promise<void> {
  try {
    await store.createVatCode({
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
    vatDraft.code = '';
    vatDraft.label = '';
    notifications.push('Code TVA daté créé.', 'success');
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

async function saveIdentity(): Promise<void> {
  await store.saveIdentity({ ...identity });
  markUnsavedChanges(false);
  await context.load();
  notifications.push('Identité légale et devise enregistrées.', 'success');
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
  <header class="page-header">
    <div>
      <p class="eyebrow">Référentiels du dossier</p>
      <h1>Configuration</h1>
      <p>Une source unique par domaine, des valeurs datées et un historique auditable.</p>
    </div>
  </header>

  <CompactTabs v-if="context.selection && canManage" :items="tabs" label="Navigation Configuration" />

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
        <div class="configuration-grid">
          <FormField id="organization-name" label="Nom usuel">
            <template #default="{ describedBy }">
              <input id="organization-name" v-model="identity.name" :aria-describedby="describedBy" required>
            </template>
          </FormField>
          <FormField id="legal-name" label="Raison sociale">
            <template #default="{ describedBy }">
              <input id="legal-name" v-model="identity.legal_name" :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="legal-form" label="Forme juridique">
            <template #default="{ describedBy }">
              <input id="legal-form" v-model="identity.legal_form" :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="uid" label="Numéro IDE / UID">
            <template #default="{ describedBy }">
              <input id="uid" v-model="identity.uid" :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="address-line1" label="Adresse">
            <template #default="{ describedBy }">
              <input id="address-line1" v-model="identity.address_line1" :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="address-line2" label="Complément">
            <template #default="{ describedBy }">
              <input id="address-line2" v-model="identity.address_line2" :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="postal-code" label="NPA">
            <template #default="{ describedBy }">
              <input id="postal-code" v-model="identity.postal_code" :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="city" label="Localité">
            <template #default="{ describedBy }">
              <input id="city" v-model="identity.city" :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="canton" label="Canton">
            <template #default="{ describedBy }">
              <input id="canton" v-model="identity.canton" maxlength="2" :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="country" label="Pays ISO">
            <template #default="{ describedBy }">
              <input id="country" v-model="identity.country" maxlength="2" :aria-describedby="describedBy">
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
        <nav class="subtabs" aria-label="Référentiels gérés">
          <button
            type="button"
            :class="{ active: referenceSection === 'overview' }"
            @click="referenceSection = 'overview'"
          >
            Vue d’ensemble
          </button>
          <button
            type="button"
            :class="{ active: referenceSection === 'contacts' }"
            @click="referenceSection = 'contacts'"
          >
            Débiteurs et créanciers
          </button>
          <button
            type="button"
            :class="{ active: referenceSection === 'vat' }"
            @click="referenceSection = 'vat'"
          >
            TVA
          </button>
          <button
            type="button"
            :class="{ active: referenceSection === 'payroll' }"
            @click="referenceSection = 'payroll'"
          >
            Charges sociales
          </button>
        </nav>

        <div v-if="referenceSection === 'overview'" class="reference-grid">
          <article v-for="reference in visibleReferenceCards" :key="reference.key" class="panel reference-card">
            <div class="panel-heading">
              <div>
                <p class="eyebrow">Source métier unique</p>
                <h2>{{ reference.label }}</h2>
              </div>
              <strong>{{ reference.count }}</strong>
            </div>
            <ul v-if="reference.items.length" class="compact-list">
              <li v-for="item in reference.items.slice(0, 8)" :key="item.id">
                <span>
                  <strong>{{ item.label }}</strong>
                  <small>{{ item.type }} · {{ item.detail }}</small>
                </span>
                <span class="status-badge" :class="item.active ? 'status-ouverte' : 'status-fermee'">
                  {{ item.active ? 'Actif' : 'Inactif' }}
                </span>
              </li>
            </ul>
            <p v-else>Aucune donnée configurée.</p>
            <button
              v-if="managedReferenceSections[reference.key]"
              class="button secondary compact"
              type="button"
              @click="referenceSection = managedReferenceSections[reference.key]"
            >
              Gérer dans Configuration
            </button>
            <a
              v-else-if="reference.legacy_path"
              class="button secondary compact"
              :href="legacyUrl(reference.legacy_path)"
            >
              Ouvrir dans Vue
            </a>
          </article>
        </div>

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
            @submit.prevent="createVatCode"
          >
            <div class="panel-heading">
              <div><p class="eyebrow">Valeur datée</p><h2>Nouveau code TVA</h2></div>
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
                <select v-model.number="vatDraft.account_id">
                  <option :value="0">Aucun</option>
                  <option v-for="account in managedReferences.vat.accounts" :key="account.id" :value="account.id">
                    {{ account.number }} — {{ account.label }}
                  </option>
                </select>
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
            <div class="form-actions">
              <button class="button primary" type="submit" :disabled="store.saving">
                Créer le code TVA
              </button>
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
                <thead><tr><th>Code</th><th>Traitement</th><th>Taux</th><th>Compte</th><th>Validité</th></tr></thead>
                <tbody>
                  <tr v-for="code in managedReferences.vat.codes" :key="code.id">
                    <td><strong>{{ code.code }}</strong><br><small>{{ code.label }}</small></td>
                    <td>{{ code.treatment }} · {{ code.nature }}</td>
                    <td>{{ code.rate_bp === null ? '—' : `${percentFromBasisPoints(code.rate_bp)} %` }}</td>
                    <td>{{ code.account || '—' }}</td>
                    <td>{{ code.valid_from }} — {{ code.valid_until || 'sans fin' }}</td>
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
              <button
                class="button secondary compact"
                type="button"
                @click="loadPayrollRates(managedReferences.payroll.suggested_rates)"
              >
                Charger les valeurs Lasso 2026
              </button>
            </div>
            <p class="field-hint">
              Les valeurs proposées reprennent `TAUX_DEFAUT` de Lasso. Vérifiez-les
              avec l’OCAS et les caisses LAA/LPP avant utilisation réelle.
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
              description="Chargez les valeurs issues de Lasso, vérifiez-les, puis enregistrez le millésime."
            />
          </article>
        </template>
      </section>

      <section v-else-if="activeTab === 'acces'" class="reference-grid">
        <article v-for="reference in visibleReferenceCards" :key="reference.key" class="panel reference-card">
          <div class="panel-heading">
            <div><p class="eyebrow">Accès</p><h2>{{ reference.label }}</h2></div>
            <strong>{{ reference.count }}</strong>
          </div>
          <ul v-if="reference.items.length" class="compact-list">
            <li v-for="item in reference.items.slice(0, 8)" :key="item.id">
              <span><strong>{{ item.label }}</strong><small>{{ item.type }} · {{ item.detail }}</small></span>
              <span class="status-badge" :class="item.active ? 'status-ouverte' : 'status-fermee'">
                {{ item.active ? 'Actif' : 'Inactif' }}
              </span>
            </li>
          </ul>
          <p v-else>Aucun utilisateur configuré.</p>
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
