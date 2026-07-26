<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import PayrollSlipPreview from '@/components/payroll/PayrollSlipPreview.vue';
import CompactTabs from '@/components/ui/CompactTabs.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import SkeletonBlock from '@/components/ui/SkeletonBlock.vue';
import { runtimeConfig } from '@/config';
import { subNavigation } from '@/router/navigation';
import { useContextStore } from '@/stores/context';
import { usePayrollStore } from '@/stores/payroll';

const route = useRoute();
const context = useContextStore();
const store = usePayrollStore();
const today = new Date().toISOString().slice(0, 10);
const currentYear = new Date().getFullYear();
const year = ref(currentYear);
const tab = computed(() => String(route.params.tab || 'employees'));
const workspace = computed(() => store.workspace);
const employee = reactive({
  first_name: '', last_name: '', avs: '', email: '', birth_date: '',
  procedure: 'ordinaire', vacation: '8.33', sourceTax: '0'
});
const contract = reactive({
  employee_id: 0, type: 'horaire', valid_from: `${currentYear}-01-01`,
  valid_until: '', amount: '', weekly_hours: '40', activity: '100', source: 'Contrat signé'
});
const draft = reactive({
  id: 0, version: 0, employee_id: 0,
  year: currentYear, month: new Date().getMonth() + 1
});
type DraftElementType = 'heures' | 'absence' | 'prime' | 'indemnite' | 'ajustement';
type DraftElementForm = {
  key: number;
  type: DraftElementType;
  label: string;
  quantity: string;
  amount: string;
  note: string;
};
let draftElementKey = 0;
const draftElements = ref<DraftElementForm[]>([]);
const payrollPreview = ref<{
  open: () => Promise<void>;
  close: () => void;
} | null>(null);
const posting = reactive({ exercise_id: 0, journal_id: 0, date: today });
const payment = reactive({
  beneficiary_type: 'employe', employee_id: 0, date: today,
  amount: '', account_id: 0, reference: ''
});
const allocation = reactive({ payment_id: 0, liability_id: 0, amount: '' });
const employer = reactive({
  name: '', address: '', postal_code: '', city: '', email: '', phone: '', weekly_hours: '40'
});
const mapping = reactive<Record<string, number>>({
  charge_salaires_id: 0, charge_ocas_id: 0, charge_laa_id: 0, charge_lpp_id: 0,
  dette_net_id: 0, dette_ocas_id: 0, dette_laa_id: 0, dette_lpp_id: 0,
  dette_impot_id: 0
});
const ocasVerifiedOn = ref(today);

const mappingFields: Array<[string, string]> = [
  ['charge_salaires_id', 'Charge salaires'], ['charge_ocas_id', 'Charge OCAS'],
  ['charge_laa_id', 'Charge LAA'], ['charge_lpp_id', 'Charge LPP'],
  ['dette_net_id', 'Dette salaires nets'], ['dette_ocas_id', 'Dette OCAS'],
  ['dette_laa_id', 'Dette LAA'], ['dette_lpp_id', 'Dette LPP'],
  ['dette_impot_id', 'Dette impôt source']
];
const periodPayrolls = computed(() => (
  workspace.value?.payrolls.filter((row) => n(row, 'annee') === year.value) || []
));
const selectedContract = computed<Record<string, unknown> | null>(() => {
  if (!workspace.value || draft.employee_id < 1) return null;
  const start = `${draft.year}-${String(draft.month).padStart(2, '0')}-01`;
  const end = new Date(Date.UTC(draft.year, draft.month, 0)).toISOString().slice(0, 10);
  return workspace.value.catalog.contracts.find((row) => (
    n(row, 'employe_id') === draft.employee_id
    && n(row, 'actif') === 1
    && s(row, 'date_debut') <= end
    && (s(row, 'date_fin') === '' || s(row, 'date_fin') >= start)
  )) || null;
});
const hourlyContract = computed(() => s(selectedContract.value || {}, 'type') === 'horaire');
const periodLabel = computed(() => (
  `${String(draft.month).padStart(2, '0')}/${draft.year}`
));
const draftReady = computed(() => {
  if (!selectedContract.value || draft.employee_id < 1) return false;
  if (
    hourlyContract.value
    && !draftElements.value.some((element) => (
      element.type === 'heures' && element.quantity.trim() !== ''
    ))
  ) return false;
  return draftElements.value.every((element) => (
    element.label.trim() !== ''
    && (
      element.type === 'heures'
        ? element.quantity.trim() !== ''
        : element.amount.trim() !== ''
    )
  ));
});
const draftElementLabels: Record<DraftElementType, string> = {
  heures: 'Heures travaillées',
  absence: 'Absence non rémunérée',
  prime: 'Prime',
  indemnite: 'Indemnité',
  ajustement: 'Ajustement'
};

function n(row: Record<string, unknown>, key: string): number {
  return Number(row[key] ?? 0);
}
function s(row: Record<string, unknown>, key: string): string {
  return String(row[key] ?? '');
}
function money(cents: number): string {
  return new Intl.NumberFormat('fr-CH', { style: 'currency', currency: 'CHF' })
    .format(cents / 100);
}
function cents(value: string): number {
  const normalized = value.trim().replace(',', '.');
  if (!/^-?\d+(?:\.\d{1,2})?$/.test(normalized)) throw new Error('Montant invalide.');
  const negative = normalized.startsWith('-');
  const [whole, decimals = ''] = normalized.replace('-', '').split('.');
  const result = Number(whole) * 100 + Number(decimals.padEnd(2, '0'));
  return negative ? -result : result;
}
function ppm(value: string): number {
  const normalized = value.trim().replace(',', '.');
  if (!/^\d+(?:\.\d{1,4})?$/.test(normalized)) throw new Error('Pourcentage invalide.');
  const [whole, decimals = ''] = normalized.split('.');
  return Number(whole) * 10000 + Number(decimals.padEnd(4, '0'));
}
function milli(value: string): number {
  const normalized = value.trim().replace(',', '.');
  if (!/^\d+(?:\.\d{1,3})?$/.test(normalized)) throw new Error('Quantité invalide.');
  const [whole, decimals = ''] = normalized.split('.');
  return Number(whole) * 1000 + Number(decimals.padEnd(3, '0'));
}
function employeeName(id: number): string {
  const item = workspace.value?.employees.find((row) => n(row, 'id') === id);
  return item ? `${s(item, 'prenom')} ${s(item, 'nom')}` : `#${id}`;
}
function decimal(centsValue: number): string {
  return (centsValue / 100).toFixed(2);
}
function defaultElement(type: DraftElementType): DraftElementForm {
  return {
    key: ++draftElementKey,
    type,
    label: draftElementLabels[type],
    quantity: '',
    amount: '',
    note: ''
  };
}
function resetDraftElements(): void {
  draftElements.value = hourlyContract.value ? [defaultElement('heures')] : [];
}
function addDraftElement(): void {
  const type: DraftElementType = hourlyContract.value
    && !draftElements.value.some((row) => row.type === 'heures')
    ? 'heures'
    : 'prime';
  draftElements.value.push(defaultElement(type));
}
function removeDraftElement(index: number): void {
  draftElements.value.splice(index, 1);
}
function changeDraftElement(element: DraftElementForm): void {
  element.label = draftElementLabels[element.type];
  element.quantity = '';
  element.amount = '';
}
function resetDraftEditor(): void {
  draft.id = 0;
  draft.version = 0;
  draft.year = year.value;
  if (!draft.employee_id) {
    draft.employee_id = n(workspace.value?.employees[0] || {}, 'id');
  }
  resetDraftElements();
}
function parseDraftElements(row: Record<string, unknown>): DraftElementForm[] {
  try {
    const parsed = JSON.parse(s(row, 'variables_snapshot_json'));
    if (!Array.isArray(parsed)) return [];
    return parsed.map((element: Record<string, unknown>) => ({
      key: ++draftElementKey,
      type: String(element.type) as DraftElementType,
      label: String(element.libelle || ''),
      quantity: Number(element.quantite_milli || 0) > 0
        ? String(Number(element.quantite_milli) / 1000)
        : '',
      amount: Number(element.montant_centimes || 0) !== 0
        ? decimal(
          String(element.type) === 'absence'
            ? Math.abs(Number(element.montant_centimes))
            : Number(element.montant_centimes)
        )
        : '',
      note: String(element.note || '')
    }));
  } catch {
    return [];
  }
}
function draftVariables(row: Record<string, unknown>): string {
  try {
    const elements = JSON.parse(s(row, 'variables_snapshot_json'));
    if (!Array.isArray(elements) || !elements.length) {
      return 'Salaire contractuel, sans variable';
    }
    return elements.map((element: Record<string, unknown>) => {
      if (String(element.type) === 'heures') {
        return `${Number(element.quantite_milli || 0) / 1000} h`;
      }
      const amount = Math.abs(Number(element.montant_centimes || 0));
      return `${String(element.libelle || '')} ${decimal(amount)} CHF`;
    }).join(' · ');
  } catch {
    return 'Variables non lisibles';
  }
}
async function reload(payrollId?: number): Promise<void> {
  await store.load(year.value, payrollId);
  if (!workspace.value) return;
  if (!posting.exercise_id) posting.exercise_id = n(workspace.value.catalog.exercises[0] || {}, 'id');
  if (!posting.journal_id) posting.journal_id = n(workspace.value.catalog.journals[0] || {}, 'id');
  if (!payment.account_id) payment.account_id = n(workspace.value.catalog.treasury_accounts[0] || {}, 'id');
  if (!draft.employee_id) draft.employee_id = n(workspace.value.employees[0] || {}, 'id');
  if (!contract.employee_id) contract.employee_id = draft.employee_id;
  if (!payment.employee_id) payment.employee_id = draft.employee_id;
  const employerSource = workspace.value.employer || workspace.value.employer_suggestion;
  if (employerSource) {
    employer.name = String(employerSource.nom || '');
    employer.address = String(employerSource.rue || '');
    employer.postal_code = String(employerSource.npa || '');
    employer.city = String(employerSource.localite || '');
    employer.email = String(employerSource.email || '');
    employer.phone = String(employerSource.telephone || '');
    employer.weekly_hours = String(Number(employerSource.heures_hebdo_milli || 40000) / 1000);
  }
  if (workspace.value.mapping) Object.assign(mapping, workspace.value.mapping);
  if (!draft.id && draftElements.value.length === 0) resetDraftElements();
}
async function mutate(
  path: string,
  data: Record<string, unknown>,
  notice: string
): Promise<boolean> {
  try {
    await store.mutate(path, data, notice);
    await reload();
    return true;
  } catch {
    // Le store rend l’erreur structurée.
    return false;
  }
}
async function createEmployee(): Promise<void> {
  await mutate('/salaires/employes', {
    first_name: employee.first_name, last_name: employee.last_name, avs: employee.avs,
    email: employee.email, birth_date: employee.birth_date,
    procedure: employee.procedure, vacation_ppm: ppm(employee.vacation),
    source_tax_ppm: ppm(employee.sourceTax)
  }, 'Employé créé.');
}
async function saveContract(): Promise<void> {
  await mutate('/salaires/contrats', {
    employee_id: contract.employee_id, type: contract.type,
    valid_from: contract.valid_from, valid_until: contract.valid_until,
    hourly_cents: contract.type === 'horaire' ? cents(contract.amount) : 0,
    monthly_cents: contract.type === 'mensuel' ? cents(contract.amount) : 0,
    weekly_hours_milli: milli(contract.weekly_hours),
    activity_ppm: ppm(contract.activity), source: contract.source, active: true
  }, 'Contrat daté enregistré.');
}
async function createDraft(): Promise<void> {
  let elements: Array<Record<string, unknown>>;
  try {
    elements = draftElements.value.map((element) => ({
      type: element.type,
      libelle: element.label.trim(),
      quantite_milli: element.type === 'heures' ? milli(element.quantity) : 0,
      montant_unitaire_centimes: 0,
      montant_centimes: element.type === 'heures' ? 0 : cents(element.amount),
      note: element.note.trim()
    }));
  } catch (error) {
    store.error = error instanceof Error
      ? `${error.message} Corrigez les éléments de la période.`
      : 'Les éléments de la période sont invalides.';
    return;
  }
  const editing = draft.id > 0;
  const saved = await mutate('/salaires/fiches', {
    id: draft.id,
    version: draft.version,
    employee_id: draft.employee_id,
    year: draft.year,
    month: draft.month,
    elements
  }, editing ? 'Brouillon recalculé.' : 'Brouillon calculé et enregistré.');
  if (saved) resetDraftEditor();
}
function editDraft(row: Record<string, unknown>): void {
  draft.id = n(row, 'id');
  draft.version = n(row, 'version');
  draft.employee_id = n(row, 'employe_id');
  draft.year = n(row, 'annee');
  draft.month = n(row, 'mois');
  draftElements.value = parseDraftElements(row);
  document.getElementById('payroll-draft-editor')?.scrollIntoView({
    behavior: 'smooth',
    block: 'start'
  });
}
async function deleteDraft(row: Record<string, unknown>): Promise<void> {
  if (!window.confirm(
    `Supprimer le brouillon de ${employeeName(n(row, 'employe_id'))} `
    + `pour ${String(n(row, 'mois')).padStart(2, '0')}/${n(row, 'annee')} ?`
  )) return;
  const deleted = await mutate('/salaires/fiches/brouillon/supprimer', {
    id: n(row, 'id'),
    version: n(row, 'version')
  }, 'Brouillon supprimé.');
  if (deleted && draft.id === n(row, 'id')) resetDraftEditor();
}
async function openPayrollPreview(row: Record<string, unknown>): Promise<void> {
  await reload(n(row, 'id'));
  if (store.error || !workspace.value?.selected) return;
  await nextTick();
  await payrollPreview.value?.open();
}
async function validatePayroll(row: Record<string, unknown>): Promise<boolean> {
  return mutate('/salaires/fiches/valider', { id: n(row, 'id'), version: n(row, 'version') }, 'Fiche validée et figée.');
}
async function validatePreview(): Promise<void> {
  const selected = workspace.value?.selected;
  if (!selected) return;
  if (await validatePayroll(selected)) payrollPreview.value?.close();
}
function editPreview(): void {
  const selected = workspace.value?.selected;
  if (!selected || s(selected, 'statut') !== 'brouillon') return;
  editDraft(selected);
}
async function postPayroll(row: Record<string, unknown>): Promise<void> {
  await mutate('/salaires/fiches/comptabiliser', {
    id: n(row, 'id'), version: n(row, 'version'), ...posting
  }, 'Fiche comptabilisée.');
}
async function cancelPayroll(row: Record<string, unknown>): Promise<void> {
  await mutate('/salaires/fiches/annuler', {
    id: n(row, 'id'), version: n(row, 'version'), ...posting
  }, 'Fiche annulée par contre-passation.');
}
async function createPayment(): Promise<void> {
  await mutate('/salaires/paiements', {
    beneficiary_type: payment.beneficiary_type,
    employee_id: payment.beneficiary_type === 'employe' ? payment.employee_id : null,
    date: payment.date, amount_cents: cents(payment.amount),
    account_id: payment.account_id, reference: payment.reference
  }, 'Paiement salarial saisi.');
}
async function allocatePayment(): Promise<void> {
  await mutate('/salaires/allocations', {
    ...allocation, amount_cents: cents(allocation.amount)
  }, 'Paiement alloué.');
}
async function postPayment(row: Record<string, unknown>): Promise<void> {
  await mutate('/salaires/paiements/comptabiliser', {
    id: n(row, 'id'), version: 0, ...posting
  }, 'Paiement comptabilisé.');
}
async function saveEmployer(): Promise<void> {
  await mutate('/salaires/employeur', {
    ...employer, weekly_hours_milli: milli(employer.weekly_hours)
  }, 'Employeur enregistré.');
}
async function saveMapping(): Promise<void> {
  await mutate('/salaires/mapping', { ...mapping }, 'Mapping salarial enregistré.');
}
async function confirmOcas(): Promise<void> {
  if (!store.ocas) return;
  await mutate('/salaires/taux-ocas/confirmer', {
    year: store.ocas.year, fingerprint: store.ocas.fingerprint,
    verified_on: ocasVerifiedOn.value
  }, 'Taux OCAS contrôlés et importés.');
}
async function certificate(action: 'preparer' | 'controler', employeeId: number): Promise<void> {
  await mutate(`/salaires/certificats/${action}`, {
    employee_id: employeeId, year: year.value
  }, action === 'preparer' ? 'Certificat préparé.' : 'Certificat contrôlé.');
}
function certificateUrl(employeeId: number): string {
  return `${runtimeConfig.apiBaseUrl}/salaires/certificats/exporter?year=${year.value}&employee_id=${employeeId}`;
}

watch(() => context.selection?.dossier.id, () => reload());
watch(year, (value) => {
  if (!draft.id) draft.year = value;
  reload();
});
watch(
  () => [draft.employee_id, draft.year, draft.month],
  () => {
    if (!draft.id) resetDraftElements();
  }
);
onMounted(() => reload());
</script>

<template>
  <header class="page-header">
    <div>
      <p class="eyebrow">Paie genevoise reliée au grand livre</p>
      <h1>Salaires</h1>
      <p>Contrats datés, calculs au centime, dettes séparées et certificats non transmis.</p>
    </div>
    <label>Année <input v-model.number="year" type="number" min="2000" max="9999"></label>
  </header>
  <CompactTabs :items="subNavigation.payroll" label="Navigation des salaires" />
  <ErrorSummary :message="store.error" />
  <p v-if="store.notice" class="notice success" role="status">{{ store.notice }}</p>
  <SkeletonBlock v-if="store.loading && !workspace" :lines="8" />

  <template v-if="workspace">
    <section class="panel">
      <div class="section-heading">
        <div><p class="eyebrow">{{ workspace.definitions.scope }}</p><h2>Paie {{ year }}</h2></div>
        <span class="status-chip">{{ workspace.capabilities.pii ? 'PII autorisées' : 'PII masquées' }}</span>
      </div>
    </section>
    <section
      v-if="!workspace.configuration.employer_ready"
      class="panel"
      aria-labelledby="payroll-employer-setup"
    >
      <div class="section-heading">
        <div>
          <p class="eyebrow">Prérequis de calcul</p>
          <h2 id="payroll-employer-setup">Préparation des salaires</h2>
        </div>
        <span class="status-chip">Employeur à confirmer</span>
      </div>
      <p class="notice warning" role="alert">
        Confirmez l’employeur prérempli depuis l’identité légale. Aucun calcul ne sera lancé
        tant que ce snapshot obligatoire n’est pas disponible.
      </p>
      <form class="form-grid three" @submit.prevent="saveEmployer">
        <label>Employeur<input v-model="employer.name" required></label>
        <label>Adresse<input v-model="employer.address"></label>
        <label>NPA<input v-model="employer.postal_code"></label>
        <label>Localité<input v-model="employer.city"></label>
        <label>E-mail<input v-model="employer.email" type="email"></label>
        <label>Téléphone<input v-model="employer.phone"></label>
        <label>Heures hebdomadaires<input v-model="employer.weekly_hours" required></label>
        <button class="button primary" :disabled="!workspace.capabilities.manage || store.saving">
          Enregistrer et activer les calculs
        </button>
      </form>
    </section>
    <p
      v-if="workspace.configuration.employer_ready && !workspace.configuration.rates_ready"
      class="notice warning"
      role="alert"
    >
      Aucun taux salarial contrôlé n’est disponible pour {{ year }}. Importez et confirmez
      les taux annuels avant de calculer une fiche.
    </p>
    <p
      v-if="workspace.configuration.calculation_ready && !workspace.configuration.mapping_ready"
      class="notice warning"
      role="status"
    >
      Le calcul des brouillons est disponible. Configurez aussi le mapping comptable dans
      l’onglet Annuels avant de valider une fiche.
    </p>

    <template v-if="tab === 'employees'">
      <section class="panel">
        <h2>Nouvel employé</h2>
        <form class="form-grid three" @submit.prevent="createEmployee">
          <label>Prénom<input v-model="employee.first_name" required></label>
          <label>Nom<input v-model="employee.last_name" required></label>
          <label>AVS<input v-model="employee.avs" placeholder="756.1234.5678.90" required></label>
          <label>E-mail<input v-model="employee.email" type="email"></label>
          <label>Date de naissance<input v-model="employee.birth_date" type="date"></label>
          <label>Procédure<select v-model="employee.procedure"><option value="ordinaire">Ordinaire</option><option value="simplifiee">Simplifiée</option><option value="ordinaire_impot_source">Impôt à la source</option></select></label>
          <label>Vacances (%)<input v-model="employee.vacation" inputmode="decimal"></label>
          <label>Impôt source (%)<input v-model="employee.sourceTax" inputmode="decimal"></label>
          <button class="button primary" :disabled="!workspace.capabilities.manage">Créer</button>
        </form>
      </section>
      <section class="panel">
        <h2>Contrat daté</h2>
        <form class="form-grid three" @submit.prevent="saveContract">
          <label>Employé<select v-model.number="contract.employee_id"><option v-for="row in workspace.employees" :key="n(row,'id')" :value="n(row,'id')">{{ employeeName(n(row,'id')) }}</option></select></label>
          <label>Type<select v-model="contract.type"><option value="horaire">Horaire</option><option value="mensuel">Mensuel</option></select></label>
          <label>{{ contract.type === 'horaire' ? 'Taux horaire' : 'Salaire mensuel' }}<input v-model="contract.amount" required></label>
          <label>Début<input v-model="contract.valid_from" type="date" required></label>
          <label>Fin<input v-model="contract.valid_until" type="date"></label>
          <label>Heures hebdomadaires<input v-model="contract.weekly_hours"></label>
          <label>Taux d’activité (%)<input v-model="contract.activity"></label>
          <label>Source<input v-model="contract.source" required></label>
          <button class="button primary" :disabled="!workspace.capabilities.manage">Enregistrer</button>
        </form>
      </section>
      <section class="panel"><h2>Employés et contrats</h2><div class="table-scroll"><table><thead><tr><th>Employé</th><th>AVS</th><th>Contrat</th><th>Effet</th><th>Valeur</th></tr></thead><tbody><tr v-for="row in workspace.employees" :key="n(row,'id')"><td>{{ employeeName(n(row,'id')) }}</td><td>{{ s(row,'numero_avs') }}</td><td>{{ s(workspace.catalog.contracts.find(c => n(c,'employe_id') === n(row,'id')) || {},'type') || 'À définir' }}</td><td>{{ s(workspace.catalog.contracts.find(c => n(c,'employe_id') === n(row,'id')) || {},'date_debut') }}</td><td>{{ money(n(workspace.catalog.contracts.find(c => n(c,'employe_id') === n(row,'id')) || {}, s(workspace.catalog.contracts.find(c => n(c,'employe_id') === n(row,'id')) || {},'type') === 'mensuel' ? 'salaire_mensuel_centimes' : 'taux_horaire_centimes')) }}</td></tr></tbody></table></div></section>
    </template>

    <template v-else-if="tab === 'calculs'">
      <section id="payroll-draft-editor" class="panel">
        <div class="section-heading">
          <div>
            <p class="eyebrow">{{ draft.id ? 'Modification contrôlée' : 'Nouveau calcul' }}</p>
            <h2>{{ draft.id ? 'Reprendre le brouillon' : 'Préparer une fiche de salaire' }}</h2>
          </div>
          <span v-if="draft.id" class="status-chip">Brouillon #{{ draft.id }}</span>
        </div>
        <p>
          Sélectionnez d’abord l’employé et le mois. Le contrat actif fournit automatiquement
          le taux horaire ou le salaire mensuel ; ajoutez uniquement les éléments propres à
          cette période.
        </p>

        <form class="payroll-draft-form" @submit.prevent="createDraft">
          <fieldset class="payroll-step">
            <legend><span>1</span> Employé et période</legend>
            <div class="form-grid three">
              <label>Employé
                <select v-model.number="draft.employee_id" :disabled="draft.id > 0" required>
                  <option :value="0" disabled>Choisir un employé…</option>
                  <option v-for="row in workspace.employees" :key="n(row,'id')" :value="n(row,'id')">
                    {{ employeeName(n(row,'id')) }}
                  </option>
                </select>
              </label>
              <label>Année
                <input v-model.number="draft.year" type="number" min="2000" max="9999" :disabled="draft.id > 0" required>
              </label>
              <label>Mois
                <select v-model.number="draft.month" :disabled="draft.id > 0" required>
                  <option v-for="month in 12" :key="month" :value="month">
                    {{ String(month).padStart(2, '0') }}
                  </option>
                </select>
              </label>
            </div>
          </fieldset>

          <fieldset class="payroll-step">
            <legend><span>2</span> Contrat appliqué automatiquement</legend>
            <div v-if="selectedContract" class="payroll-contract-summary">
              <div>
                <small>Type</small>
                <strong>{{ hourlyContract ? 'Salaire horaire' : 'Salaire mensuel' }}</strong>
              </div>
              <div>
                <small>{{ hourlyContract ? 'Taux contractuel' : 'Base contractuelle' }}</small>
                <strong>{{ money(n(selectedContract, hourlyContract ? 'taux_horaire_centimes' : 'salaire_mensuel_centimes')) }}</strong>
              </div>
              <div>
                <small>Taux d’activité</small>
                <strong>{{ (n(selectedContract,'taux_activite_ppm') / 10000).toFixed(2) }} %</strong>
              </div>
              <div>
                <small>Validité</small>
                <strong>{{ s(selectedContract,'date_debut') }} – {{ s(selectedContract,'date_fin') || 'sans fin' }}</strong>
              </div>
            </div>
            <p v-else class="notice warning" role="alert">
              Aucun contrat actif pour {{ employeeName(draft.employee_id) }} en
              {{ periodLabel }}. Créez ou corrigez d’abord le contrat dans l’onglet Employés.
            </p>
            <p v-if="selectedContract && !hourlyContract" class="field-hint">
              Le salaire mensuel est ajouté automatiquement. Ne saisissez ci-dessous que les
              absences, primes, indemnités ou ajustements du mois.
            </p>
            <p v-else-if="selectedContract" class="field-hint">
              Le taux horaire vient du contrat. Saisissez le nombre total d’heures du mois.
            </p>
          </fieldset>

          <fieldset class="payroll-step">
            <legend><span>3</span> Éléments variables de {{ periodLabel }}</legend>
            <div
              v-for="(element,index) in draftElements"
              :key="element.key"
              class="payroll-variable-row"
            >
              <label>Nature
                <select v-model="element.type" @change="changeDraftElement(element)">
                  <option v-if="hourlyContract" value="heures">Heures travaillées</option>
                  <option value="absence">Absence non rémunérée</option>
                  <option value="prime">Prime</option>
                  <option value="indemnite">Indemnité</option>
                  <option value="ajustement">Ajustement ±</option>
                </select>
              </label>
              <label>Libellé
                <input v-model="element.label" required>
              </label>
              <label v-if="element.type === 'heures'">Nombre d’heures
                <input v-model="element.quantity" inputmode="decimal" placeholder="ex. 42,5" required>
              </label>
              <label v-else>
                {{ element.type === 'absence' ? 'Montant à déduire' : 'Montant CHF' }}
                <input v-model="element.amount" inputmode="decimal" placeholder="ex. 250.00" required>
              </label>
              <label>Note / justification
                <input v-model="element.note" placeholder="Facultatif">
              </label>
              <button
                type="button"
                class="button danger small"
                :disabled="hourlyContract && element.type === 'heures' && draftElements.filter(item => item.type === 'heures').length === 1"
                @click="removeDraftElement(index)"
              >
                Retirer
              </button>
            </div>
            <p v-if="!draftElements.length" class="notice">
              Aucun élément variable : le brouillon reprendra uniquement le salaire mensuel contractuel.
            </p>
            <button type="button" class="button" :disabled="!selectedContract" @click="addDraftElement">
              Ajouter un élément variable
            </button>
          </fieldset>

          <div class="payroll-draft-summary">
            <div>
              <small>Fiche préparée</small>
              <strong>{{ employeeName(draft.employee_id) }} · {{ periodLabel }}</strong>
              <span>
                {{ selectedContract
                  ? (hourlyContract
                    ? 'Heures × taux contractuel, puis charges et retenues'
                    : 'Salaire mensuel + variables, puis charges et retenues')
                  : 'Contrat requis avant calcul' }}
              </span>
            </div>
            <div class="button-row">
              <button
                v-if="draft.id"
                type="button"
                class="button"
                :disabled="store.saving"
                @click="resetDraftEditor"
              >
                Annuler la modification
              </button>
              <button
                class="button primary"
                :disabled="!workspace.capabilities.manage || !workspace.configuration.calculation_ready || !draftReady || store.saving"
              >
                {{ draft.id ? 'Recalculer et enregistrer' : 'Calculer et créer le brouillon' }}
              </button>
            </div>
          </div>
        </form>
      </section>

      <section class="panel">
        <div class="section-heading">
          <div>
            <p class="eyebrow">Fiches de travail</p>
            <h2>Brouillons et calculs {{ year }}</h2>
          </div>
          <span class="status-chip">{{ periodPayrolls.length }} fiche(s)</span>
        </div>
        <div class="table-scroll">
          <table>
            <thead><tr><th>Période</th><th>Employé</th><th>Base et variables</th><th>Brut</th><th>Retenues</th><th>Net</th><th>Statut</th><th>Actions</th></tr></thead>
            <tbody>
              <tr v-for="row in periodPayrolls" :key="n(row,'id')">
                <td>{{ String(n(row,'mois')).padStart(2,'0') }}/{{ n(row,'annee') }}</td>
                <td>{{ employeeName(n(row,'employe_id')) }}</td>
                <td>{{ draftVariables(row) }}</td>
                <td>{{ money(n(row,'brut_centimes')) }}</td>
                <td>{{ money(n(row,'total_deductions_centimes')) }}</td>
                <td><strong>{{ money(n(row,'net_centimes')) }}</strong></td>
                <td><span class="status-chip">{{ s(row,'statut') }}</span></td>
                <td class="button-row">
                  <button
                    class="button small"
                    :disabled="store.loading || store.saving"
                    @click="openPayrollPreview(row)"
                  >
                    Aperçu
                  </button>
                  <button
                    v-if="s(row,'statut') === 'brouillon'"
                    class="button small"
                    :disabled="store.saving"
                    @click="editDraft(row)"
                  >
                    Modifier
                  </button>
                  <button
                    v-if="s(row,'statut') === 'brouillon'"
                    class="button danger small"
                    :disabled="store.saving"
                    @click="deleteDraft(row)"
                  >
                    Supprimer
                  </button>
                  <span v-else>Fiche figée</span>
                </td>
              </tr>
              <tr v-if="!periodPayrolls.length">
                <td colspan="8">Aucune fiche calculée pour {{ year }}.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>

    <template v-else-if="tab === 'fiches'">
      <section class="panel"><div class="form-grid three"><label>Exercice<select v-model.number="posting.exercise_id"><option v-for="row in workspace.catalog.exercises" :key="n(row,'id')" :value="n(row,'id')">{{ s(row,'libelle') }}</option></select></label><label>Journal<select v-model.number="posting.journal_id"><option v-for="row in workspace.catalog.journals" :key="n(row,'id')" :value="n(row,'id')">{{ s(row,'code') }} — {{ s(row,'libelle') }}</option></select></label><label>Date comptable<input v-model="posting.date" type="date"></label></div></section>
      <section class="panel">
        <h2>Fiches de salaire</h2>
        <p>Un brouillon doit être contrôlé dans son aperçu détaillé avant sa validation.</p>
        <div class="table-scroll">
          <table>
            <thead><tr><th>Période</th><th>Employé</th><th>Net</th><th>Dette ouverte</th><th>Statut</th><th>Actions</th></tr></thead>
            <tbody>
              <tr v-for="row in workspace.payrolls" :key="n(row,'id')">
                <td>{{ String(n(row,'mois')).padStart(2,'0') }}/{{ n(row,'annee') }}</td>
                <td>{{ employeeName(n(row,'employe_id')) }}</td>
                <td>{{ money(n(row,'net_centimes')) }}</td>
                <td>{{ money(n(row,'solde_dettes_centimes')) }}</td>
                <td><span class="status-chip">{{ s(row,'statut') }}</span></td>
                <td class="button-row">
                  <button
                    class="button small"
                    :disabled="store.loading || store.saving"
                    @click="openPayrollPreview(row)"
                  >
                    {{ s(row,'statut') === 'brouillon' ? 'Voir et valider' : 'Voir' }}
                  </button>
                  <button v-if="s(row,'statut') === 'validee'" class="button small" :disabled="!workspace.capabilities.post || store.saving" @click="postPayroll(row)">Comptabiliser</button>
                  <button v-if="['validee','comptabilisee'].includes(s(row,'statut'))" class="button danger small" :disabled="!workspace.capabilities.post || store.saving" @click="cancelPayroll(row)">Contre-passer</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <section class="panel"><h2>Paiements et lettrage</h2><form class="form-grid three" @submit.prevent="createPayment"><label>Bénéficiaire<select v-model="payment.beneficiary_type"><option value="employe">Employé</option><option value="organisme">Organisme</option></select></label><label v-if="payment.beneficiary_type === 'employe'">Employé<select v-model.number="payment.employee_id"><option v-for="row in workspace.employees" :key="n(row,'id')" :value="n(row,'id')">{{ employeeName(n(row,'id')) }}</option></select></label><label>Date<input v-model="payment.date" type="date"></label><label>Montant<input v-model="payment.amount" required></label><label>Compte de trésorerie<select v-model.number="payment.account_id"><option v-for="row in workspace.catalog.treasury_accounts" :key="n(row,'id')" :value="n(row,'id')">{{ s(row,'numero') }} — {{ s(row,'libelle') }}</option></select></label><label>Référence<input v-model="payment.reference"></label><button class="button primary">Saisir</button></form><form class="form-grid three" @submit.prevent="allocatePayment"><label>Paiement<select v-model.number="allocation.payment_id"><option v-for="row in workspace.payments" :key="n(row,'id')" :value="n(row,'id')">#{{ n(row,'id') }} · {{ money(n(row,'non_alloue_centimes')) }}</option></select></label><label>Dette<select v-model.number="allocation.liability_id"><option v-for="row in workspace.liabilities" :key="n(row,'id')" :value="n(row,'id')">{{ s(row,'type') }} · {{ employeeName(n(row,'employe_id')) }} · {{ money(n(row,'solde_centimes')) }}</option></select></label><label>Montant<input v-model="allocation.amount"></label><button class="button">Allouer</button></form><div class="table-scroll"><table><thead><tr><th>Date</th><th>Bénéficiaire</th><th>Montant</th><th>Non alloué</th><th></th></tr></thead><tbody><tr v-for="row in workspace.payments" :key="n(row,'id')"><td>{{ s(row,'date_paiement') }}</td><td>{{ s(row,'beneficiaire_type') }}</td><td>{{ money(n(row,'montant_centimes')) }}</td><td>{{ money(n(row,'non_alloue_centimes')) }}</td><td><button v-if="n(row,'non_alloue_centimes') === 0 && !row.ecriture_id" class="button small" @click="postPayment(row)">Comptabiliser</button></td></tr></tbody></table></div></section>
    </template>

    <template v-else>
      <section class="metric-strip"><span><small>Brut annuel</small><strong>{{ money(Number(workspace.annual.employer.gross_cents || 0)) }}</strong></span><span><small>Retenues</small><strong>{{ money(Number(workspace.annual.employer.deductions_cents || 0)) }}</strong></span><span><small>Charges employeur</small><strong>{{ money(Number(workspace.annual.employer.employer_charges_cents || 0)) }}</strong></span><span><small>Coût total</small><strong>{{ money(Number(workspace.annual.employer.total_cost_cents || 0)) }}</strong></span></section>
      <section class="panel"><h2>Récapitulatifs et certificats</h2><div class="table-scroll"><table><thead><tr><th>Employé</th><th>Fiches</th><th>Brut</th><th>Net</th><th>Certificat</th></tr></thead><tbody><tr v-for="row in workspace.annual.employees" :key="n(row,'employe_id')"><td>{{ employeeName(n(row,'employe_id')) }}</td><td>{{ n(row,'fiches') }}</td><td>{{ money(n(row,'brut_centimes')) }}</td><td>{{ money(n(row,'net_centimes')) }}</td><td class="button-row"><button class="button small" :disabled="!workspace.capabilities.export || !workspace.capabilities.pii || n(row,'fiches') === 0" @click="certificate('preparer',n(row,'employe_id'))">Préparer</button><button class="button small" :disabled="!workspace.capabilities.export || !workspace.capabilities.pii" @click="certificate('controler',n(row,'employe_id'))">Contrôler</button><a class="button small" :href="certificateUrl(n(row,'employe_id'))">Exporter</a></td></tr></tbody></table></div><p class="notice warning">{{ workspace.definitions.certificate }}</p></section>
      <section class="panel"><h2>Import annuel OCAS</h2><div class="button-row"><button class="button" :disabled="store.saving" @click="store.previewOcas(year)">Prévisualiser sans écrire</button><label>Contrôlé le <input v-model="ocasVerifiedOn" type="date"></label><button v-if="store.ocas?.available" class="button primary" :disabled="store.ocas.missing_keys.length > 0" @click="confirmOcas">Confirmer l’import</button></div><p v-if="store.ocas" :class="['notice', store.ocas.available ? 'success' : 'warning']">{{ store.ocas.message }}</p><p v-if="store.ocas?.missing_keys.length">Clés manquantes : {{ store.ocas.missing_keys.join(', ') }}</p><div v-if="store.ocas?.rows.length" class="table-scroll"><table><thead><tr><th>Clé OCAS</th><th>Cible COMPTA</th><th>Valeur</th><th>Décision</th></tr></thead><tbody><tr v-for="row in store.ocas.rows" :key="s(row,'key')"><td>{{ s(row,'key') }}</td><td>{{ s(row,'target') || 'Non applicable' }}</td><td>{{ s(row,'value') }}</td><td>{{ s(row,'status') }} {{ s(row,'reason') }}</td></tr></tbody></table></div></section>
      <section class="panel"><h2>Paramétrage employeur et comptes</h2><form class="form-grid three" @submit.prevent="saveEmployer"><label>Employeur<input v-model="employer.name" required></label><label>Adresse<input v-model="employer.address"></label><label>NPA<input v-model="employer.postal_code"></label><label>Localité<input v-model="employer.city"></label><label>E-mail<input v-model="employer.email"></label><label>Téléphone<input v-model="employer.phone"></label><label>Heures hebdomadaires<input v-model="employer.weekly_hours"></label><button class="button">Enregistrer l’employeur</button></form><form class="form-grid three" @submit.prevent="saveMapping"><label v-for="[key,label] in mappingFields" :key="key">{{ label }}<select v-model.number="mapping[key]"><option :value="0">Choisir…</option><option v-for="row in workspace.catalog.accounts" :key="n(row,'id')" :value="n(row,'id')">{{ s(row,'numero') }} — {{ s(row,'libelle') }}</option></select></label><button class="button primary">Enregistrer le mapping</button></form></section>
    </template>
  </template>
  <EmptyState v-else-if="!store.loading" title="Salaires indisponibles" description="Sélectionnez un dossier autorisé." />
  <PayrollSlipPreview
    ref="payrollPreview"
    :payroll="workspace?.selected || null"
    :can-validate="Boolean(
      workspace?.capabilities.validate
      && workspace?.configuration.validation_ready
    )"
    :saving="store.saving"
    @validate="validatePreview"
    @edit="editPreview"
  />
</template>
