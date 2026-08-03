<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';

const props = defineProps<{
  payroll: Record<string, unknown> | null;
  canValidate: boolean;
  saving: boolean;
}>();
const emit = defineEmits<{ validate: []; edit: [] }>();
const dialog = ref<HTMLDialogElement | null>(null);
const closeButton = ref<HTMLButtonElement | null>(null);

function n(row: Record<string, unknown>, key: string): number {
  return Number(row[key] ?? 0);
}
function s(row: Record<string, unknown>, key: string): string {
  return String(row[key] ?? '');
}
function rows(key: string): Array<Record<string, unknown>> {
  const value = props.payroll?.[key];
  return Array.isArray(value) ? value as Array<Record<string, unknown>> : [];
}
function snapshot(key: string): Record<string, unknown> {
  try {
    const value = JSON.parse(s(props.payroll || {}, key));
    return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
  } catch {
    return {};
  }
}
function money(cents: number): string {
  return new Intl.NumberFormat('fr-CH', {
    style: 'currency',
    currency: 'CHF'
  }).format(cents / 100);
}
function amount(cents: number): string {
  return new Intl.NumberFormat('fr-CH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(cents / 100);
}
function quantity(milli: number): string {
  return new Intl.NumberFormat('fr-CH', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 3
  }).format(milli / 1000);
}
function rate(ppm: number): string {
  return `${new Intl.NumberFormat('fr-CH', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 4
  }).format(ppm / 10000)} %`;
}
function salaryLabel(value: string): string {
  return value === 'Salaire du travail' ? 'Salaire de base' : value;
}

const employer = computed(() => snapshot('employeur_snapshot_json'));
const employee = computed(() => snapshot('employe_snapshot_json'));
const lines = computed(() => rows('lines'));
const periodElements = computed(() => rows('period_elements'));
const gains = computed(() => rows('components').filter(
  (row) => s(row, 'categorie') === 'gain'
));
const deductions = computed(() => rows('components').filter(
  (row) => s(row, 'categorie') === 'retenue_employe' && n(row, 'montant_centimes') !== 0
));
const employerCharges = computed(() => rows('components').filter(
  (row) => s(row, 'categorie') === 'charge_employeur' && n(row, 'montant_centimes') !== 0
));
const isDraft = computed(() => s(props.payroll || {}, 'statut') === 'brouillon');
const title = computed(() => {
  const payroll = props.payroll || {};
  return `Fiche de salaire ${String(n(payroll, 'mois')).padStart(2, '0')}/${n(payroll, 'annee')}`;
});

async function open(): Promise<void> {
  dialog.value?.showModal();
  await nextTick();
  closeButton.value?.focus();
}
function close(): void {
  dialog.value?.close();
}
function edit(): void {
  close();
  emit('edit');
}

defineExpose({ open, close });
</script>

<template>
  <dialog
    ref="dialog"
    class="payroll-preview-dialog"
    aria-labelledby="payroll-preview-title"
    @cancel="close"
  >
    <article v-if="payroll" class="payroll-slip">
      <header class="payroll-slip-header">
        <div>
          <p class="eyebrow">{{ isDraft ? 'Prévisualisation avant validation' : 'Fiche enregistrée' }}</p>
          <h2 id="payroll-preview-title">{{ title }}</h2>
          <p>{{ s(employer, 'nom') }}</p>
          <p>{{ s(employer, 'rue') }} {{ s(employer, 'npa') }} {{ s(employer, 'localite') }}</p>
        </div>
        <div class="payroll-slip-status">
          <strong :class="{ warning: isDraft }">
            {{ isDraft ? 'BROUILLON — À CONTRÔLER' : s(payroll, 'statut').toUpperCase() }}
          </strong>
          <span>Fiche #{{ n(payroll, 'id') }} · version {{ n(payroll, 'version') }}</span>
        </div>
      </header>

      <p v-if="isDraft" class="notice warning payroll-draft-warning">
        Cette prévisualisation n’est pas une fiche validée. Vérifiez l’identité, la période,
        les éléments variables, les retenues et le salaire net avant de poursuivre.
      </p>

      <section class="payroll-slip-parties">
        <div>
          <small>Employé</small>
          <strong>{{ s(payroll, 'prenom') }} {{ s(payroll, 'nom') }}</strong>
          <span>AVS : {{ s(payroll, 'numero_avs') || s(employee, 'numero_avs') || 'Non renseigné' }}</span>
          <span v-if="s(employee, 'rue')">
            {{ s(employee, 'rue') }}, {{ s(employee, 'npa') }} {{ s(employee, 'localite') }}
          </span>
        </div>
        <div>
          <small>Période salariale</small>
          <strong>{{ String(n(payroll, 'mois')).padStart(2, '0') }}/{{ n(payroll, 'annee') }}</strong>
          <span>{{ quantity(n(payroll, 'nombre_heures_milli')) }} heure(s) de référence</span>
          <span>Taux source : {{ n(payroll, 'taux_source_annee') }}</span>
        </div>
      </section>

      <section class="payroll-slip-section">
        <h3>Base salariale</h3>
        <div class="table-scroll payroll-slip-table-shell">
          <table class="data-table payroll-slip-table">
            <thead>
              <tr><th>Libellé</th><th class="amount">Quantité</th><th>Unité</th><th class="amount">Taux (CHF)</th><th class="amount">Montant (CHF)</th></tr>
            </thead>
            <tbody>
              <tr v-for="row in lines" :key="n(row, 'id')">
                <td><strong>{{ s(row, 'libelle') }}</strong></td>
                <td class="amount">{{ quantity(n(row, 'quantite_milli')) }}</td>
                <td>{{ s(row, 'unite_libelle_snapshot') }}</td>
                <td class="amount">{{ money(n(row, 'taux_horaire_centimes')) }}</td>
                <td class="amount"><strong>{{ money(n(row, 'montant_centimes')) }}</strong></td>
              </tr>
              <tr v-if="!lines.length"><td colspan="5">Aucune ligne salariale.</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="periodElements.length" class="payroll-slip-section">
        <h3>Éléments propres à la période</h3>
        <div class="table-scroll">
          <table>
            <thead><tr><th>Nature</th><th>Libellé</th><th>Quantité</th><th>Montant</th><th>Note</th></tr></thead>
            <tbody>
              <tr v-for="row in periodElements" :key="n(row, 'id')">
                <td>{{ s(row, 'type') }}</td>
                <td>{{ s(row, 'libelle') }}</td>
                <td>{{ n(row, 'quantite_milli') ? quantity(n(row, 'quantite_milli')) : '—' }}</td>
                <td>{{ n(row, 'montant_centimes') ? money(n(row, 'montant_centimes')) : '—' }}</td>
                <td>{{ s(row, 'note') || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="payroll-slip-calculation">
        <div>
          <h3>Salaire</h3>
          <dl>
            <template v-for="row in gains" :key="n(row, 'id')">
              <dt>{{ salaryLabel(s(row, 'libelle')) }}</dt>
              <dd>{{ amount(n(row, 'montant_centimes')) }}</dd>
            </template>
          </dl>
          <div class="payroll-slip-subtotal">
            <strong>Salaire brut</strong>
            <strong>{{ amount(n(payroll, 'brut_centimes')) }}</strong>
          </div>
        </div>
        <div>
          <h3>Retenues employé</h3>
          <dl>
            <template v-for="row in deductions" :key="n(row, 'id')">
              <dt>{{ s(row, 'libelle') }} <small>{{ rate(n(row, 'taux_ppm')) }}</small></dt>
              <dd>{{ amount(n(row, 'montant_centimes')) }}</dd>
            </template>
          </dl>
          <div class="payroll-slip-subtotal">
            <strong>Total des retenues</strong>
            <strong>{{ amount(n(payroll, 'total_deductions_centimes')) }}</strong>
          </div>
        </div>
      </section>

      <section class="payroll-slip-net">
        <span>Salaire net à verser</span>
        <strong>{{ amount(n(payroll, 'net_centimes')) }}</strong>
      </section>

      <details class="payroll-slip-employer">
        <summary>
          <span>Charges employeur — information interne</span>
          <svg class="payroll-details-chevron" viewBox="0 0 20 20" aria-hidden="true">
            <path d="m5 7.5 5 5 5-5" />
          </svg>
        </summary>
        <dl>
          <dt class="payroll-employer-gross"><strong>Salaire brut</strong></dt>
          <dd class="payroll-employer-gross"><strong>{{ amount(n(payroll, 'brut_centimes')) }}</strong></dd>
          <template v-for="row in employerCharges" :key="n(row, 'id')">
            <dt>{{ s(row, 'libelle') }} <small>{{ rate(n(row, 'taux_ppm')) }}</small></dt>
            <dd>{{ amount(n(row, 'montant_centimes')) }}</dd>
          </template>
        </dl>
        <div class="payroll-slip-subtotal">
          <strong>Coût total employeur</strong>
          <strong>{{ amount(n(payroll, 'cout_total_centimes')) }}</strong>
        </div>
      </details>

      <footer class="payroll-preview-actions">
        <button ref="closeButton" type="button" class="button secondary" @click="close">Fermer</button>
        <button v-if="isDraft" type="button" class="button secondary" :disabled="saving" @click="edit">
          Corriger le brouillon
        </button>
        <button
          v-if="isDraft"
          type="button"
          class="button primary"
          :disabled="!canValidate || saving"
          @click="emit('validate')"
        >
          Valider cette fiche
        </button>
      </footer>
    </article>
  </dialog>
</template>
