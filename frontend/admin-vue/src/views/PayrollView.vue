<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import PayrollSlipPreview from '@/components/payroll/PayrollSlipPreview.vue';
import AccountCombobox from '@/components/ui/AccountCombobox.vue';
import CompactTabs from '@/components/ui/CompactTabs.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import ModalDialog from '@/components/ui/ModalDialog.vue';
import SkeletonBlock from '@/components/ui/SkeletonBlock.vue';
import { runtimeConfig } from '@/config';
import { useToastFeedback } from '@/composables/toastFeedback';
import { subNavigation } from '@/router/navigation';
import { useContextStore } from '@/stores/context';
import { usePayrollStore } from '@/stores/payroll';

const route = useRoute();
const context = useContextStore();
const store = usePayrollStore();
useToastFeedback(store);
const today = new Date().toISOString().slice(0, 10);
const currentYear = new Date().getFullYear();
const year = ref(currentYear);
const tab = computed(() => String(route.params.tab || 'employees'));
const workspace = computed(() => store.workspace);
const employee = reactive({
  id: 0, version: 0,
  first_name: '', last_name: '', avs: '', email: '', birth_date: '',
  address: '', postal_code: '', city: '',
  procedure: 'ordinaire', vacation: '8.33', sourceTax: '0',
  lpp: '', employerLpp: '', active: true
});
const contract = reactive({
  id: 0, version: 0,
  employee_id: 0, type: 'horaire', valid_from: `${currentYear}-01-01`,
  valid_until: '', amount: '', weekly_hours: '40', activity: '100',
  source: 'Contrat signé', active: true
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
const employeeDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const contractDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const contractHistoryDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const contractHistoryEmployeeId = ref(0);
const paymentsDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const posting = reactive({ exercise_id: 0, journal_id: 0, date: today });
const payment = reactive({
  beneficiary_type: 'employe', employee_id: 0, date: today,
  amount: '', account_id: 0, reference: ''
});
const allocation = reactive({ payment_id: 0, liability_id: 0, amount: '' });
const ocasVerifiedOn = ref(today);
const ocasSourceCsv = ref('');
const ocasSourceName = ref('');
const activeEmployees = computed(() => (
  workspace.value?.employees.filter((row) =>
    n(row, 'actif') === 1 && n(row, 'profil_incomplet') !== 1
  ) || []
));
const contractHistory = computed(() => (
  workspace.value?.catalog.contracts.filter(
    (row) => n(row, 'employe_id') === contractHistoryEmployeeId.value
  ) || []
));
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
function percentage(ppmValue: number): string {
  return (ppmValue / 10000).toFixed(4).replace(/\.?0+$/, '');
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
    draft.employee_id = n(activeEmployees.value[0] || {}, 'id');
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
  if (!draft.employee_id) draft.employee_id = n(activeEmployees.value[0] || {}, 'id');
  if (!contract.employee_id) contract.employee_id = draft.employee_id;
  if (!payment.employee_id) payment.employee_id = draft.employee_id;
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
function resetEmployeeEditor(): void {
  Object.assign(employee, {
    id: 0, version: 0,
    first_name: '', last_name: '', avs: '', email: '', birth_date: '',
    address: '', postal_code: '', city: '',
    procedure: 'ordinaire', vacation: '8.33', sourceTax: '0',
    lpp: '', employerLpp: '', active: true
  });
}
function createEmployee(): void {
  resetEmployeeEditor();
  void employeeDialog.value?.open();
}
function editEmployee(row: Record<string, unknown>): void {
  Object.assign(employee, {
    id: n(row, 'id'),
    version: n(row, 'version'),
    first_name: s(row, 'prenom'),
    last_name: s(row, 'nom'),
    avs: s(row, 'numero_avs'),
    email: s(row, 'email'),
    birth_date: s(row, 'date_naissance'),
    address: s(row, 'rue'),
    postal_code: s(row, 'npa'),
    city: s(row, 'localite'),
    procedure: s(row, 'procedure'),
    vacation: percentage(n(row, 'supplement_vacances_ppm')),
    sourceTax: percentage(n(row, 'impot_source_ppm')),
    lpp: row.lpp_ppm === null ? '' : percentage(n(row, 'lpp_ppm')),
    employerLpp: row.emp_lpp_ppm === null ? '' : percentage(n(row, 'emp_lpp_ppm')),
    active: n(row, 'actif') === 1
  });
  void employeeDialog.value?.open();
}
async function saveEmployee(): Promise<void> {
  let vacationPpm: number;
  let sourceTaxPpm: number;
  let lppPpm: number | null;
  let employerLppPpm: number | null;
  try {
    vacationPpm = ppm(employee.vacation);
    sourceTaxPpm = ppm(employee.sourceTax);
    lppPpm = employee.lpp.trim() === '' ? null : ppm(employee.lpp);
    employerLppPpm = employee.employerLpp.trim() === ''
      ? null
      : ppm(employee.employerLpp);
  } catch (error) {
    store.error = error instanceof Error ? error.message : 'Pourcentage invalide.';
    return;
  }
  const editing = employee.id > 0;
  const saved = await mutate('/salaires/employes', {
    id: employee.id,
    version: employee.version,
    first_name: employee.first_name,
    last_name: employee.last_name,
    avs: employee.avs,
    email: employee.email,
    birth_date: employee.birth_date,
    address: employee.address,
    postal_code: employee.postal_code,
    city: employee.city,
    procedure: employee.procedure,
    vacation_ppm: vacationPpm,
    source_tax_ppm: sourceTaxPpm,
    lpp_ppm: lppPpm,
    emp_lpp_ppm: employerLppPpm,
    active: employee.active
  }, editing ? 'Données de l’employé mises à jour.' : 'Employé créé.');
  if (saved) {
    if (!employee.active && draft.employee_id === employee.id) {
      draft.employee_id = n(activeEmployees.value[0] || {}, 'id');
    }
    resetEmployeeEditor();
    employeeDialog.value?.close();
  }
}
async function deleteEmployee(row: Record<string, unknown>): Promise<void> {
  if (!window.confirm(
    `Supprimer l’employé ${employeeName(n(row, 'id'))} et ses contrats non utilisés ?`
  )) return;
  const deleted = await mutate('/salaires/employes/supprimer', {
    id: n(row, 'id'),
    version: n(row, 'version')
  }, 'Employé et contrats non utilisés supprimés.');
  if (deleted) {
    if (employee.id === n(row, 'id')) resetEmployeeEditor();
    if (contract.employee_id === n(row, 'id')) resetContractEditor();
    if (draft.employee_id === n(row, 'id')) {
      draft.employee_id = n(activeEmployees.value[0] || {}, 'id');
    }
    if (payment.employee_id === n(row, 'id')) {
      payment.employee_id = n(workspace.value?.employees[0] || {}, 'id');
    }
  }
}
function resetContractEditor(): void {
  Object.assign(contract, {
    id: 0,
    version: 0,
    employee_id: n(activeEmployees.value[0] || {}, 'id'),
    type: 'horaire',
    valid_from: `${year.value}-01-01`,
    valid_until: '',
    amount: '',
    weekly_hours: '40',
    activity: '100',
    source: 'Contrat signé',
    active: true
  });
}
function createContract(): void {
  resetContractEditor();
  void contractDialog.value?.open();
}
function createContractForEmployee(employeeId: number): void {
  resetContractEditor();
  contract.employee_id = employeeId;
  void contractDialog.value?.open();
}
function openContractHistory(employeeId: number): void {
  contractHistoryEmployeeId.value = employeeId;
  void contractHistoryDialog.value?.open();
}
function editContract(row: Record<string, unknown>): void {
  Object.assign(contract, {
    id: n(row, 'id'),
    version: n(row, 'version'),
    employee_id: n(row, 'employe_id'),
    type: s(row, 'type'),
    valid_from: s(row, 'date_debut'),
    valid_until: s(row, 'date_fin'),
    amount: decimal(n(
      row,
      s(row, 'type') === 'mensuel'
        ? 'salaire_mensuel_centimes'
        : 'taux_horaire_centimes'
    )),
    weekly_hours: String(n(row, 'heures_hebdo_milli') / 1000),
    activity: percentage(n(row, 'taux_activite_ppm')),
    source: s(row, 'source'),
    active: n(row, 'actif') === 1
  });
  void contractDialog.value?.open();
}
async function saveContract(): Promise<void> {
  let amountCents: number;
  let weeklyHoursMilli: number;
  let activityPpm: number;
  try {
    amountCents = cents(contract.amount);
    weeklyHoursMilli = milli(contract.weekly_hours);
    activityPpm = ppm(contract.activity);
  } catch (error) {
    store.error = error instanceof Error ? error.message : 'Contrat invalide.';
    return;
  }
  const editing = contract.id > 0;
  const saved = await mutate('/salaires/contrats', {
    id: contract.id,
    version: contract.version,
    employee_id: contract.employee_id,
    type: contract.type,
    valid_from: contract.valid_from,
    valid_until: contract.valid_until,
    hourly_cents: contract.type === 'horaire' ? amountCents : 0,
    monthly_cents: contract.type === 'mensuel' ? amountCents : 0,
    weekly_hours_milli: weeklyHoursMilli,
    activity_ppm: activityPpm,
    source: contract.source,
    active: contract.active
  }, editing ? 'Contrat mis à jour.' : 'Contrat daté enregistré.');
  if (saved) {
    resetContractEditor();
    contractDialog.value?.close();
  }
}
async function deleteContract(row: Record<string, unknown>): Promise<void> {
  if (!window.confirm(
    `Supprimer le contrat de ${employeeName(n(row, 'employe_id'))} `
    + `débutant le ${s(row, 'date_debut')} ?`
  )) return;
  const deleted = await mutate('/salaires/contrats/supprimer', {
    id: n(row, 'id'),
    version: n(row, 'version')
  }, 'Contrat non utilisé supprimé.');
  if (deleted && contract.id === n(row, 'id')) resetContractEditor();
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
  const saved = await mutate('/salaires/paiements', {
    beneficiary_type: payment.beneficiary_type,
    employee_id: payment.beneficiary_type === 'employe' ? payment.employee_id : null,
    date: payment.date, amount_cents: cents(payment.amount),
    account_id: payment.account_id, reference: payment.reference
  }, 'Paiement salarial saisi.');
  if (saved) {
    payment.amount = '';
    payment.reference = '';
  }
}
async function allocatePayment(): Promise<void> {
  const saved = await mutate('/salaires/allocations', {
    ...allocation, amount_cents: cents(allocation.amount)
  }, 'Paiement alloué.');
  if (saved) allocation.amount = '';
}
async function postPayment(row: Record<string, unknown>): Promise<void> {
  await mutate('/salaires/paiements/comptabiliser', {
    id: n(row, 'id'), version: 0, ...posting
  }, 'Paiement comptabilisé.');
}
async function confirmOcas(): Promise<void> {
  if (!store.ocas) return;
  await mutate('/salaires/taux-ocas/confirmer', {
    year: store.ocas.year, fingerprint: store.ocas.fingerprint,
    verified_on: ocasVerifiedOn.value,
    source_csv: ocasSourceCsv.value
  }, 'Taux OCAS contrôlés et importés.');
}
async function readOcasSource(event: Event): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;
  ocasSourceCsv.value = await file.text();
  ocasSourceName.value = file.name;
  store.ocas = null;
}
async function previewOcas(): Promise<void> {
  await store.previewOcas(year.value, ocasSourceCsv.value);
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
  <header class="page-heading">
    <div>
      <h1>Salaires</h1>
      <p>Contrats datés, calculs au centime, dettes séparées et certificats non transmis.</p>
    </div>
    <label>Année <input v-model.number="year" type="number" min="2000" max="9999"></label>
  </header>
  <CompactTabs :items="subNavigation.payroll" label="Navigation des salaires" />
  <ErrorSummary :message="store.error" />
  <SkeletonBlock v-if="store.loading && !workspace" :lines="8" />

  <template v-if="workspace">
    <section
      v-if="!workspace.configuration.employer_ready"
      class="panel"
      aria-labelledby="payroll-employer-setup"
    >
      <div class="section-heading">
        <div>
          <p class="eyebrow">Prérequis de calcul</p>
          <h2 id="payroll-employer-setup">Paramètres salariaux requis</h2>
        </div>
        <span class="status-chip">Configuration incomplète</span>
      </div>
      <p class="notice warning" role="alert">
        Définissez les heures hebdomadaires et les comptes sous
        <RouterLink to="/configuration/salaires">Configuration → Salaires</RouterLink>.
        L’identité de l’employeur est reprise automatiquement de l’entité légale.
      </p>
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
      Configuration → Salaires avant de valider une fiche.
    </p>

    <template v-if="tab === 'employees'">
      <ModalDialog
        ref="employeeDialog"
        :title="employee.id ? 'Modifier l’employé' : 'Nouvel employé'"
        description="Identité, coordonnées et paramètres salariaux de la personne."
        wide
      >
        <form class="form-grid three" @submit.prevent="saveEmployee">
          <label>Prénom<input v-model="employee.first_name" required></label>
          <label>Nom<input v-model="employee.last_name" required></label>
          <label>AVS<input v-model="employee.avs" placeholder="756.1234.5678.90" required></label>
          <label>E-mail<input v-model="employee.email" type="email"></label>
          <label>Date de naissance<input v-model="employee.birth_date" type="date"></label>
          <label>Adresse<input v-model="employee.address"></label>
          <label>NPA<input v-model="employee.postal_code"></label>
          <label>Localité<input v-model="employee.city"></label>
          <label>Procédure<select v-model="employee.procedure"><option value="ordinaire">Ordinaire</option><option value="simplifiee">Simplifiée</option><option value="ordinaire_impot_source">Impôt à la source</option></select></label>
          <label>Vacances (%)<input v-model="employee.vacation" inputmode="decimal"></label>
          <label>Impôt source (%)<input v-model="employee.sourceTax" inputmode="decimal"></label>
          <label>LPP employé particulière (%)
            <input v-model="employee.lpp" inputmode="decimal" placeholder="Taux annuel par défaut">
          </label>
          <label>LPP employeur particulière (%)
            <input v-model="employee.employerLpp" inputmode="decimal" placeholder="Taux annuel par défaut">
          </label>
          <label class="checkbox-field">
            <input v-model="employee.active" type="checkbox">
            Employé actif
          </label>
          <div class="button-row">
            <button
              v-if="employee.id"
              type="button"
              class="button secondary"
              :disabled="store.saving"
              @click="resetEmployeeEditor(); employeeDialog?.close()"
            >
              Annuler
            </button>
            <button
              class="button primary"
              :disabled="!workspace.capabilities.manage || (employee.id > 0 && !workspace.capabilities.pii)"
            >
              {{ employee.id ? 'Enregistrer les modifications' : 'Créer l’employé' }}
            </button>
          </div>
        </form>
      </ModalDialog>
      <ModalDialog
        ref="contractDialog"
        :title="contract.id ? 'Modifier le contrat' : 'Nouveau contrat'"
        description="Le contrat est daté et sera automatiquement appliqué aux périodes concernées."
        wide
      >
        <form class="form-grid three" @submit.prevent="saveContract">
          <label>Employé<select v-model.number="contract.employee_id" :disabled="contract.id > 0"><option v-for="row in (contract.id ? workspace.employees : activeEmployees)" :key="n(row,'id')" :value="n(row,'id')">{{ employeeName(n(row,'id')) }}</option></select></label>
          <label>Type<select v-model="contract.type"><option value="horaire">Horaire</option><option value="mensuel">Mensuel</option></select></label>
          <label>{{ contract.type === 'horaire' ? 'Taux horaire' : 'Salaire mensuel' }}<input v-model="contract.amount" required></label>
          <label>Début<input v-model="contract.valid_from" type="date" required></label>
          <label>Fin<input v-model="contract.valid_until" type="date"></label>
          <label>Heures hebdomadaires<input v-model="contract.weekly_hours"></label>
          <label>Taux d’activité (%)<input v-model="contract.activity"></label>
          <label>Source<input v-model="contract.source" required></label>
          <label class="checkbox-field">
            <input v-model="contract.active" type="checkbox">
            Contrat actif
          </label>
          <div class="button-row">
            <button
              v-if="contract.id"
              type="button"
              class="button secondary"
              :disabled="store.saving"
              @click="resetContractEditor(); contractDialog?.close()"
            >
              Annuler
            </button>
            <button class="button primary" :disabled="!workspace.capabilities.manage">
              {{ contract.id ? 'Enregistrer les modifications' : 'Enregistrer le contrat' }}
            </button>
          </div>
        </form>
      </ModalDialog>
      <section class="panel">
        <div class="section-heading">
          <h2>Employés</h2>
          <button class="button primary" type="button" :disabled="!workspace.capabilities.manage" @click="createEmployee">Nouvel employé</button>
        </div>
        <div class="table-scroll">
          <table>
            <thead><tr><th>Employé</th><th>AVS</th><th>E-mail</th><th>Procédure</th><th>État</th><th>Actions</th></tr></thead>
            <tbody>
              <tr v-for="row in workspace.employees" :key="n(row,'id')">
                <td>{{ employeeName(n(row,'id')) }}</td>
                <td>{{ n(row,'profil_incomplet') === 1 ? 'À compléter' : s(row,'numero_avs') }}</td>
                <td>{{ s(row,'email') || '—' }}</td>
                <td>{{ s(row,'procedure') }}</td>
                <td>
                  <span
                    :class="['status-chip', n(row,'profil_incomplet') === 1 || n(row,'actif') !== 1 ? 'warning' : 'ok']"
                  >{{ n(row,'profil_incomplet') === 1 ? 'À compléter' : (n(row,'actif') === 1 ? 'Actif' : 'Inactif') }}</span>
                </td>
                <td class="button-row">
                  <button class="button secondary small" type="button" @click="openContractHistory(n(row,'id'))">Contrats</button>
                  <button class="button small" :disabled="!workspace.capabilities.manage || !workspace.capabilities.pii || store.saving" @click="editEmployee(row)">Modifier</button>
                  <button
                    class="button danger small"
                    :disabled="!workspace.capabilities.manage || !workspace.capabilities.pii || store.saving || n(row,'contact_id') > 0"
                    :title="n(row,'contact_id') > 0 ? 'Retirez le rôle employé depuis Configuration → Débiteurs et créanciers.' : ''"
                    @click="deleteEmployee(row)"
                  >Supprimer</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <ModalDialog
        ref="contractHistoryDialog"
        :title="`Historique des contrats · ${employeeName(contractHistoryEmployeeId)}`"
        description="Les contrats utilisés par une fiche restent protégés ; ils peuvent être désactivés mais pas supprimés."
        wide
      >
        <div class="section-heading">
          <h3>Contrats datés</h3>
          <button class="button primary" type="button" :disabled="!workspace.capabilities.manage" @click="createContractForEmployee(contractHistoryEmployeeId)">Nouveau contrat</button>
        </div>
        <p>Les contrats utilisés par une fiche restent protégés ; ils peuvent être désactivés mais pas supprimés.</p>
        <div class="table-scroll">
          <table>
            <thead><tr><th>Type</th><th>Début</th><th>Fin</th><th>Valeur</th><th>Activité</th><th>État</th><th>Actions</th></tr></thead>
            <tbody>
              <tr v-for="row in contractHistory" :key="n(row,'id')">
                <td>{{ s(row,'type') }}</td>
                <td>{{ s(row,'date_debut') }}</td>
                <td>{{ s(row,'date_fin') || 'Sans fin' }}</td>
                <td>{{ money(n(row, s(row,'type') === 'mensuel' ? 'salaire_mensuel_centimes' : 'taux_horaire_centimes')) }}</td>
                <td>{{ percentage(n(row,'taux_activite_ppm')) }} %</td>
                <td><span :class="['status-chip', n(row,'actif') === 1 ? 'ok' : 'warning']">{{ n(row,'actif') === 1 ? 'Actif' : 'Inactif' }}</span></td>
                <td class="button-row">
                  <button class="button small" :disabled="!workspace.capabilities.manage || store.saving" @click="editContract(row)">Modifier</button>
                  <button class="button danger small" :disabled="!workspace.capabilities.manage || store.saving" @click="deleteContract(row)">Supprimer</button>
                </td>
              </tr>
              <tr v-if="!contractHistory.length"><td colspan="7">Aucun contrat pour cet employé.</td></tr>
            </tbody>
          </table>
        </div>
      </ModalDialog>
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
                  <option v-for="row in activeEmployees" :key="n(row,'id')" :value="n(row,'id')">
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
        <div class="section-heading">
          <h2>Fiches de salaire</h2>
          <button class="button primary" type="button" @click="paymentsDialog?.open()">Paiements et lettrage</button>
        </div>
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
      <ModalDialog
        ref="paymentsDialog"
        title="Paiements et lettrage"
        description="Saisissez d’abord le paiement, puis affectez-le à une dette salariale ouverte."
        wide
      >
        <div class="payroll-payment-grid">
          <form class="payroll-payment-step" @submit.prevent="createPayment">
            <header><span>1</span><div><h3>Saisir le paiement</h3><p>Enregistrez le mouvement bancaire ou de caisse.</p></div></header>
            <label>Bénéficiaire<select v-model="payment.beneficiary_type"><option value="employe">Employé</option><option value="organisme">Organisme</option></select></label>
            <label v-if="payment.beneficiary_type === 'employe'">Employé<select v-model.number="payment.employee_id"><option v-for="row in workspace.employees" :key="n(row,'id')" :value="n(row,'id')">{{ employeeName(n(row,'id')) }}</option></select></label>
            <label>Date<input v-model="payment.date" type="date" required></label>
            <label>Montant<input v-model="payment.amount" inputmode="decimal" required></label>
            <label>Compte de trésorerie<AccountCombobox v-model="payment.account_id" :options="workspace.catalog.treasury_accounts" required /></label>
            <label>Référence<input v-model="payment.reference" placeholder="Référence facultative"></label>
            <button class="button primary" :disabled="store.saving">Saisir le paiement</button>
          </form>
          <form class="payroll-payment-step" @submit.prevent="allocatePayment">
            <header><span>2</span><div><h3>Allouer à une dette</h3><p>Choisissez le paiement disponible et la dette à régler.</p></div></header>
            <label>Paiement disponible<select v-model.number="allocation.payment_id" required><option :value="0" disabled>Sélectionner…</option><option v-for="row in workspace.payments.filter((item) => n(item,'non_alloue_centimes') > 0)" :key="n(row,'id')" :value="n(row,'id')">#{{ n(row,'id') }} · {{ money(n(row,'non_alloue_centimes')) }}</option></select></label>
            <label>Dette ouverte<select v-model.number="allocation.liability_id" required><option :value="0" disabled>Sélectionner…</option><option v-for="row in workspace.liabilities.filter((item) => n(item,'solde_centimes') > 0)" :key="n(row,'id')" :value="n(row,'id')">{{ s(row,'type') }} · {{ employeeName(n(row,'employe_id')) }} · {{ money(n(row,'solde_centimes')) }}</option></select></label>
            <label>Montant à allouer<input v-model="allocation.amount" inputmode="decimal" required></label>
            <button class="button primary" :disabled="store.saving">Allouer le paiement</button>
          </form>
        </div>
        <section class="payroll-payment-history">
          <h3>Historique des paiements</h3>
          <div class="table-scroll"><table><thead><tr><th>Date</th><th>Bénéficiaire</th><th>Montant</th><th>Disponible</th><th>Action</th></tr></thead><tbody><tr v-for="row in workspace.payments" :key="n(row,'id')"><td>{{ s(row,'date_paiement') }}</td><td>{{ s(row,'beneficiaire_type') }}</td><td>{{ money(n(row,'montant_centimes')) }}</td><td>{{ money(n(row,'non_alloue_centimes')) }}</td><td><button v-if="n(row,'non_alloue_centimes') === 0 && !row.ecriture_id" class="button small" @click="postPayment(row)">Comptabiliser</button><span v-else>—</span></td></tr><tr v-if="!workspace.payments.length"><td colspan="5">Aucun paiement saisi.</td></tr></tbody></table></div>
        </section>
      </ModalDialog>
    </template>

    <template v-else>
      <section class="metric-strip"><span><small>Brut annuel</small><strong>{{ money(Number(workspace.annual.employer.gross_cents || 0)) }}</strong></span><span><small>Retenues</small><strong>{{ money(Number(workspace.annual.employer.deductions_cents || 0)) }}</strong></span><span><small>Charges employeur</small><strong>{{ money(Number(workspace.annual.employer.employer_charges_cents || 0)) }}</strong></span><span><small>Coût total</small><strong>{{ money(Number(workspace.annual.employer.total_cost_cents || 0)) }}</strong></span></section>
      <section class="panel"><h2>Récapitulatifs et certificats</h2><div class="table-scroll"><table><thead><tr><th>Employé</th><th>Fiches</th><th>Brut</th><th>Net</th><th>Certificat</th></tr></thead><tbody><tr v-for="row in workspace.annual.employees" :key="n(row,'employe_id')"><td>{{ employeeName(n(row,'employe_id')) }}</td><td>{{ n(row,'fiches') }}</td><td>{{ money(n(row,'brut_centimes')) }}</td><td>{{ money(n(row,'net_centimes')) }}</td><td class="button-row"><button class="button small" :disabled="!workspace.capabilities.export || !workspace.capabilities.pii || n(row,'fiches') === 0" @click="certificate('preparer',n(row,'employe_id'))">Préparer</button><button class="button small" :disabled="!workspace.capabilities.export || !workspace.capabilities.pii" @click="certificate('controler',n(row,'employe_id'))">Contrôler</button><a class="button small" :href="certificateUrl(n(row,'employe_id'))">Exporter</a></td></tr></tbody></table></div><p class="notice warning">{{ workspace.definitions.certificate }}</p></section>
      <section class="panel">
        <div class="section-heading">
          <div><p class="eyebrow">Source contrôlée</p><h2>Import annuel OCAS</h2></div>
        </div>
        <p>
          Choisissez un CSV OCAS (<code>cle;valeur</code> ou
          <code>annee;cle;valeur</code>). Sans fichier, la source serveur configurée
          par <code>OCAS_DB_PATH</code> reste utilisée.
        </p>
        <div class="form-grid three">
          <label>Fichier OCAS
            <input type="file" accept=".csv,text/csv" @change="readOcasSource">
            <small>{{ ocasSourceName || 'Source serveur configurée' }}</small>
          </label>
          <label>Contrôlé le<input v-model="ocasVerifiedOn" type="date"></label>
          <button class="button" :disabled="store.saving" @click="previewOcas">
            Prévisualiser sans écrire
          </button>
        </div>
        <div v-if="store.ocas" class="permission-preview">
          <p>{{ store.ocas.message }}</p>
          <p v-if="store.ocas.missing_keys.length">Clés manquantes : {{ store.ocas.missing_keys.join(', ') }}</p>
          <button
            v-if="store.ocas.available"
            class="button primary"
            :disabled="store.ocas.missing_keys.length > 0"
            @click="confirmOcas"
          >Confirmer l’import</button>
        </div>
        <div v-if="store.ocas?.rows.length" class="table-scroll"><table><thead><tr><th>Clé OCAS</th><th>Cible COMPTA</th><th>Valeur</th><th>Décision</th></tr></thead><tbody><tr v-for="row in store.ocas.rows" :key="s(row,'key')"><td>{{ s(row,'key') }}</td><td>{{ s(row,'target') || 'Non applicable' }}</td><td>{{ s(row,'value') }}</td><td>{{ s(row,'status') }} {{ s(row,'reason') }}</td></tr></tbody></table></div>
      </section>
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
