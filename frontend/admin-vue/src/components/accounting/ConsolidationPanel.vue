<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import AccountCombobox from '@/components/ui/AccountCombobox.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import SkeletonBlock from '@/components/ui/SkeletonBlock.vue';
import { runtimeConfig } from '@/config';
import { useConsolidationStore } from '@/stores/consolidation';
import { useContextStore } from '@/stores/context';

const consolidation = useConsolidationStore();
const context = useContextStore();
const section = ref<'balance' | 'setup' | 'reconciliation' | 'eliminations' | 'legal'>('balance');
const selectedGroupId = ref(0);
const selectedPeriodId = ref(0);
const wizardStep = ref<1 | 2 | 3 | 4>(1);
const groupDraft = reactive({
  mode: 'agregation_interne' as 'agregation_interne' | 'consolidation_legale',
  code: '',
  label: '',
  currency: 'CHF',
  valid_from: new Date().toISOString().slice(0, 10)
});
const groupUpdateDraft = reactive({
  label: '',
  currency: 'CHF',
  mode: 'agregation_interne' as 'agregation_interne' | 'consolidation_legale'
});
const memberDraft = reactive({
  scope: '',
  valid_from: new Date().toISOString().slice(0, 10),
  valid_until: ''
});
const periodDraft = reactive({
  label: '',
  start: '',
  end: ''
});
const conversionDrafts = reactive<Record<number, {
  numerator: number;
  denominator: number;
  rate_date: string;
  source: string;
}>>({});
const mappingDraft = reactive({
  member_id: 0,
  source_account_id: 0,
  target_account: '',
  target_label: '',
  target_type: 'actif',
  version: 0,
  effective_from: ''
});
const pairDraft = reactive({
  label: '',
  left_member_id: 0,
  left_account_id: 0,
  right_member_id: 0,
  right_account_id: 0
});
const eliminationDraft = reactive({
  reference: '',
  label: '',
  justification: '',
  lines: [
    { target_account: '', label: '', debit: '', credit: '' },
    { target_account: '', label: '', debit: '', credit: '' }
  ]
});
const legalDraft = reactive({
  valid_from: new Date().toISOString().slice(0, 10),
  legal_name: '',
  legal_form: '',
  uid: '',
  source: '',
  line1: '',
  line2: '',
  postal_code: '',
  city: '',
  canton: '',
  country: 'CH'
});

const workspace = computed(() => consolidation.workspace);
const selectedGroup = computed(() => workspace.value?.selected_group ?? null);
const selectedPeriod = computed(() => workspace.value?.selected_period ?? null);
const selectedMappingMember = computed(() =>
  workspace.value?.members.find((member) => member.id === mappingDraft.member_id) ?? null
);
const leftMember = computed(() =>
  workspace.value?.members.find((member) => member.id === pairDraft.left_member_id) ?? null
);
const rightMember = computed(() =>
  workspace.value?.members.find((member) => member.id === pairDraft.right_member_id) ?? null
);
const targetAccounts = computed(() => {
  const targets = new Map<string, string>();
  workspace.value?.mappings.forEach((mapping) => {
    targets.set(mapping.target_account, mapping.target_label);
  });
  return [...targets.entries()].map(([account, label]) => ({ account, label }));
});
const availableMembers = computed(() => {
  const used = new Set((workspace.value?.members ?? []).map((member) => member.dossier_id));
  return (workspace.value?.available_members ?? []).filter((candidate) => {
    if (used.has(candidate.dossier_id)) return false;
    return selectedGroup.value?.mode !== 'agregation_interne'
      || candidate.organisation_id === context.selection?.organization.id;
  });
});
const modeLabel = computed(() =>
  selectedGroup.value?.mode === 'agregation_interne'
    ? 'Agrégation interne'
    : 'Consolidation légale'
);

watch(
  () => context.selection?.dossier.id ?? 0,
  (dossierId) => {
    selectedGroupId.value = 0;
    selectedPeriodId.value = 0;
    if (dossierId > 0) void load();
    else consolidation.clear();
  },
  { immediate: true }
);

watch(
  () => consolidation.workspace,
  (value) => {
    if (!value) return;
    selectedGroupId.value = value.selected_group?.id ?? 0;
    selectedPeriodId.value = value.selected_period?.id ?? 0;
    groupUpdateDraft.label = value.selected_group?.label ?? '';
    groupUpdateDraft.currency = value.selected_group?.currency ?? 'CHF';
    groupUpdateDraft.mode = value.selected_group?.mode ?? 'agregation_interne';
    if (value.selected_group?.status !== 'brouillon') wizardStep.value = 4;
    value.members.forEach((member) => {
      conversionDrafts[member.id] ??= {
        numerator: 1,
        denominator: 1,
        rate_date: periodDraft.end || new Date().toISOString().slice(0, 10),
        source: member.currency === value.selected_group?.currency
          ? 'Devise de consolidation'
          : ''
      };
    });
    if (!mappingDraft.member_id && value.members.length) {
      mappingDraft.member_id = value.members[0].id;
    }
    if (!pairDraft.left_member_id && value.members.length) {
      pairDraft.left_member_id = value.members[0].id;
    }
    if (!pairDraft.right_member_id && value.members.length > 1) {
      pairDraft.right_member_id = value.members[1].id;
    }
  },
  { deep: true }
);

watch(
  () => periodDraft.end,
  (end) => {
    if (!end) return;
    Object.values(conversionDrafts).forEach((conversion) => {
      conversion.rate_date = end;
    });
  }
);

async function load(groupId = selectedGroupId.value || undefined, periodId = selectedPeriodId.value || undefined): Promise<void> {
  await consolidation.load(groupId, periodId);
}

async function chooseGroup(): Promise<void> {
  selectedPeriodId.value = 0;
  await load(selectedGroupId.value);
}

async function choosePeriod(): Promise<void> {
  await load(selectedGroupId.value, selectedPeriodId.value);
}

async function mutate(path: string, data: Record<string, unknown>, notice: string): Promise<void> {
  try {
    await consolidation.mutate(path, data, notice);
    await load();
    consolidation.notice = notice;
  } catch {
    // Le store expose l’erreur structurée.
  }
}

async function createGroup(): Promise<void> {
  try {
    const result = await consolidation.mutate<{ id: number }>(
      '/consolidation/groups',
      { ...groupDraft },
      groupDraft.mode === 'agregation_interne'
        ? 'Agrégation interne créée en brouillon.'
        : 'Consolidation légale créée en brouillon.'
    );
    selectedGroupId.value = result.id;
    selectedPeriodId.value = 0;
    wizardStep.value = 2;
    await load(result.id);
  } catch {
    // Le store expose l’erreur structurée.
  }
}

async function addMember(): Promise<void> {
  const [organisationId, dossierId] = memberDraft.scope.split(':').map(Number);
  await mutate('/consolidation/groups/members', {
    group_id: selectedGroupId.value,
    organisation_id: organisationId,
    dossier_id: dossierId,
    valid_from: memberDraft.valid_from,
    valid_until: memberDraft.valid_until || null
  }, 'Entité ajoutée au groupe.');
  memberDraft.scope = '';
}

async function updateGroup(): Promise<void> {
  if (!selectedGroup.value) return;
  await mutate('/consolidation/groups/update', {
    group_id: selectedGroup.value.id,
    version: selectedGroup.value.version,
    ...groupUpdateDraft
  }, 'Paramètres du groupe versionnés.');
}

async function groupAction(action: 'activate' | 'archive' | 'reactivate'): Promise<void> {
  if (!selectedGroup.value) return;
  const messages = {
    activate: 'Groupe activé après prévisualisation.',
    archive: 'Groupe archivé.',
    reactivate: 'Groupe réactivé.'
  };
  await mutate(`/consolidation/groups/${action}`, {
    group_id: selectedGroup.value.id,
    version: selectedGroup.value.version
  }, messages[action]);
}

async function removeMember(memberId: number, version: number): Promise<void> {
  const validUntil = workspace.value?.periods.length
    ? window.prompt('Date de sortie du membre (AAAA-MM-JJ) :', new Date().toISOString().slice(0, 10))
    : null;
  if (workspace.value?.periods.length && !validUntil) return;
  await mutate('/consolidation/groups/members/remove', {
    group_id: selectedGroupId.value,
    member_id: memberId,
    version,
    valid_until: validUntil
  }, validUntil ? 'Sortie datée du membre enregistrée.' : 'Membre sans donnée supprimé.');
}

async function createPeriod(): Promise<void> {
  await mutate('/consolidation/periods', {
    group_id: selectedGroupId.value,
    label: periodDraft.label,
    start: periodDraft.start,
    end: periodDraft.end,
    conversions: (workspace.value?.members ?? []).map((member) => ({
      member_id: member.id,
      ...conversionDrafts[member.id]
    }))
  }, 'Période et taux de consolidation figés.');
  periodDraft.label = '';
}

async function saveMapping(): Promise<void> {
  await mutate('/consolidation/mappings', {
    group_id: selectedGroupId.value,
    ...mappingDraft,
    effective_from: mappingDraft.effective_from || null
  }, 'Mapping de compte enregistré.');
  mappingDraft.source_account_id = 0;
  mappingDraft.target_account = '';
  mappingDraft.target_label = '';
  mappingDraft.version = 0;
  mappingDraft.effective_from = '';
}

function editMapping(mapping: NonNullable<typeof workspace.value>['mappings'][number]): void {
  mappingDraft.member_id = mapping.member_id;
  mappingDraft.source_account_id = mapping.source_account_id;
  mappingDraft.target_account = mapping.target_account;
  mappingDraft.target_label = mapping.target_label;
  mappingDraft.target_type = mapping.target_type;
  mappingDraft.version = mapping.version;
  mappingDraft.effective_from = '';
}

async function savePair(): Promise<void> {
  await mutate('/consolidation/intercompany-pairs', {
    group_id: selectedGroupId.value,
    ...pairDraft,
    effective_from: null
  }, 'Paire inter-entités enregistrée.');
  pairDraft.label = '';
}

async function disableMapping(mappingId: number, version: number): Promise<void> {
  const effectiveFrom = workspace.value?.periods.some((period) => period.status === 'cloturee')
    ? window.prompt('Prise d’effet de la nouvelle version (AAAA-MM-JJ) :')
    : null;
  if (workspace.value?.periods.some((period) => period.status === 'cloturee') && !effectiveFrom) return;
  await mutate('/consolidation/mappings/disable', {
    group_id: selectedGroupId.value,
    mapping_id: mappingId,
    version,
    effective_from: effectiveFrom
  }, 'Mapping désactivé et versionné.');
}

async function disablePair(pairId: number, version: number): Promise<void> {
  const effectiveFrom = workspace.value?.periods.some((period) => period.status === 'cloturee')
    ? window.prompt('Prise d’effet de la nouvelle version (AAAA-MM-JJ) :')
    : null;
  if (workspace.value?.periods.some((period) => period.status === 'cloturee') && !effectiveFrom) return;
  await mutate('/consolidation/intercompany-pairs/disable', {
    group_id: selectedGroupId.value,
    pair_id: pairId,
    version,
    effective_from: effectiveFrom
  }, 'Paire inter-entités désactivée et versionnée.');
}

function parseCents(value: string): number {
  const normalized = value.trim().replace(/[’'\s]/g, '').replace(',', '.');
  const match = normalized.match(/^(\d+)(?:\.(\d{1,2}))?$/);
  if (!match) throw new Error(`Montant invalide : ${value}`);
  const cents = Number(match[1]) * 100 + Number((match[2] || '').padEnd(2, '0'));
  if (!Number.isSafeInteger(cents)) throw new Error('Montant trop élevé.');
  return cents;
}

async function createElimination(): Promise<void> {
  await mutate('/consolidation/eliminations', {
    group_id: selectedGroupId.value,
    period_id: selectedPeriodId.value,
    reference: eliminationDraft.reference,
    label: eliminationDraft.label,
    justification: eliminationDraft.justification,
    lines: eliminationDraft.lines.map((line) => ({
      target_account: line.target_account,
      label: line.label,
      debit_cents: parseCents(line.debit || '0'),
      credit_cents: parseCents(line.credit || '0')
    }))
  }, 'Élimination équilibrée validée hors des grands livres.');
  eliminationDraft.reference = '';
  eliminationDraft.label = '';
  eliminationDraft.justification = '';
}

async function closePeriod(): Promise<void> {
  if (!window.confirm('Clôturer cette période de consolidation ?')) return;
  await mutate('/consolidation/periods/close', {
    group_id: selectedGroupId.value,
    period_id: selectedPeriodId.value
  }, 'Période de consolidation clôturée.');
}

async function saveLegalAttributes(): Promise<void> {
  await mutate('/consolidation/legal-attributes', {
    valid_from: legalDraft.valid_from,
    legal_name: legalDraft.legal_name,
    legal_form: legalDraft.legal_form,
    uid: legalDraft.uid,
    source: legalDraft.source,
    address: {
      line1: legalDraft.line1,
      line2: legalDraft.line2,
      postal_code: legalDraft.postal_code,
      city: legalDraft.city,
      canton: legalDraft.canton,
      country: legalDraft.country
    }
  }, 'Nouvelle version juridique datée enregistrée.');
}

function exportTrail(): void {
  if (!selectedGroupId.value || !selectedPeriodId.value) return;
  window.location.assign(
    `${runtimeConfig.apiBaseUrl}/consolidation/export`
    + `?group_id=${selectedGroupId.value}&period_id=${selectedPeriodId.value}`
  );
}

function formatMoney(cents: number): string {
  const currency = selectedGroup.value?.currency || 'CHF';
  const sign = cents < 0 ? '−' : '';
  const absolute = Math.abs(cents);
  return `${sign}${currency} ${Math.floor(absolute / 100).toLocaleString('fr-CH')}.${String(absolute % 100).padStart(2, '0')}`;
}

function organisationLabel(organisationId: number): string {
  return workspace.value?.members.find(
    (member) => member.organisation_id === organisationId
  )?.organisation ?? `Organisation #${organisationId}`;
}
</script>

<template>
  <section class="stack">
    <ErrorSummary :message="consolidation.error" />
    <p v-if="consolidation.notice" class="notice success" role="status">{{ consolidation.notice }}</p>
    <SkeletonBlock v-if="consolidation.loading && !workspace" :lines="7" />
    <template v-else-if="workspace">
      <section class="panel">
        <div class="section-heading">
          <div>
            <p class="eyebrow">Réunion de dossiers sans déplacement d’écriture</p>
            <h2>{{ selectedGroup ? modeLabel : 'Agrégation et consolidation' }}</h2>
            <p v-if="selectedGroup">
              {{ selectedGroup.code }} ·
              <span :class="['status-chip', selectedGroup.status === 'actif' ? 'ok' : 'warning']">
                {{ selectedGroup.status }}
              </span>
            </p>
          </div>
          <div class="button-row">
            <label v-if="workspace.groups.length" class="compact-control">Groupe
              <select v-model.number="selectedGroupId" @change="chooseGroup">
                <option v-for="group in workspace.groups" :key="group.id" :value="group.id">
                  {{ group.code }} — {{ group.label }}
                </option>
              </select>
            </label>
            <label v-if="workspace.periods.length" class="compact-control">Période
              <select v-model.number="selectedPeriodId" @change="choosePeriod">
                <option v-for="period in workspace.periods" :key="period.id" :value="period.id">
                  {{ period.label }} · {{ period.status }}
                </option>
              </select>
            </label>
          </div>
        </div>
        <p class="notice warning">
          Un groupe ne donne aucun droit sur ses membres. La lecture exige un accès
          explicite à chaque dossier et les éliminations ne touchent jamais les livres statutaires.
        </p>
        <details>
          <summary>Aide : agrégation, consolidation et livres statutaires</summary>
          <p>
            L’agrégation interne réunit plusieurs dossiers d’une même organisation.
            La consolidation légale réunit au moins deux organisations juridiques.
            Dans les deux cas, les livres statutaires restent indépendants et inchangés :
            mappings, conversions et éliminations appartiennent uniquement au groupe.
          </p>
        </details>
        <nav class="subtabs secondary-tabs" aria-label="Sections de consolidation">
          <button :class="{ active: section === 'balance' }" @click="section = 'balance'">Balance</button>
          <button :class="{ active: section === 'setup' }" @click="section = 'setup'">Groupe et mappings</button>
          <button :class="{ active: section === 'reconciliation' }" @click="section = 'reconciliation'">Inter-entités</button>
          <button :class="{ active: section === 'eliminations' }" @click="section = 'eliminations'">Éliminations</button>
          <button :class="{ active: section === 'legal' }" @click="section = 'legal'">Identités juridiques</button>
        </nav>
      </section>

      <section v-if="section === 'balance'" class="stack">
        <EmptyState
          v-if="!selectedGroup"
          title="Aucun groupe d’agrégation ou de consolidation"
          description="Créez le premier groupe avec l’assistant."
        />
        <EmptyState
          v-else-if="!workspace.balance"
          title="Aucune période"
          description="Créez une période avec un ratio documenté pour chaque membre."
        />
        <template v-else>
          <section class="panel">
            <div class="section-heading">
              <div>
                <h3>{{ selectedGroup?.mode === 'agregation_interne' ? 'Balance agrégée' : 'Balance consolidée' }}</h3>
                <p>{{ selectedPeriod?.date_debut }} – {{ selectedPeriod?.date_fin }} · {{ workspace.balance.currency }}</p>
              </div>
              <div class="button-row">
                <span :class="['status-chip', workspace.balance.formula_verified ? 'ok' : 'warning']">
                  {{ workspace.balance.formula_verified ? 'Formule vérifiée' : 'Écart' }}
                </span>
                <button class="button secondary" :disabled="!workspace.capabilities.export" @click="exportTrail">
                  Exporter {{ selectedGroup?.mode === 'agregation_interne' ? 'l’agrégation' : 'la consolidation' }}
                </button>
              </div>
            </div>
            <div class="metric-strip">
              <span><small>Balances sources</small><strong>{{ formatMoney(workspace.balance.source_total_cents) }}</strong></span>
              <span><small>Éliminations</small><strong>{{ formatMoney(workspace.balance.elimination_total_cents) }}</strong></span>
              <span><small>{{ selectedGroup?.mode === 'agregation_interne' ? 'Agrégé' : 'Consolidé' }}</small><strong>{{ formatMoney(workspace.balance.consolidated_total_cents) }}</strong></span>
            </div>
            <div class="table-scroll">
              <table>
                <thead><tr><th>Compte cible</th><th>Type</th><th>Sources</th><th>Éliminations</th><th>Consolidé</th><th>Traçabilité</th></tr></thead>
                <tbody>
                  <tr v-for="row in workspace.balance.rows" :key="row.account">
                    <td>{{ row.account }} — {{ row.label }}</td>
                    <td>{{ row.type }}</td>
                    <td>{{ formatMoney(row.source_cents) }}</td>
                    <td>{{ formatMoney(row.elimination_cents) }}</td>
                    <td><strong>{{ formatMoney(row.consolidated_cents) }}</strong></td>
                    <td>
                      <details>
                        <summary>{{ row.sources.length }} balance(s) source(s)</summary>
                        <p v-for="source in row.sources" :key="`${source.member_id}-${source.source_account_id}`">
                          {{ source.organisation }} / {{ source.dossier }} ·
                          {{ source.source_account }} — {{ source.source_label }} ·
                          {{ formatMoney(source.converted_cents) }} ·
                          taux {{ source.conversion.numerator }}/{{ source.conversion.denominator }}
                          ({{ source.conversion.source }}, {{ source.conversion.rate_date }})
                        </p>
                      </details>
                    </td>
                  </tr>
                  <tr v-if="!workspace.balance.rows.length"><td colspan="6">Aucun mouvement mappé.</td></tr>
                </tbody>
              </table>
            </div>
          </section>
          <section v-if="workspace.balance.unmapped_accounts.length" class="panel">
            <h3>Comptes mouvementés non mappés</h3>
            <p class="notice warning">La clôture est bloquée tant que cette liste n’est pas vide.</p>
            <ul>
              <li v-for="account in workspace.balance.unmapped_accounts" :key="`${account.member_id}-${account.account_id}`">
                {{ account.member_label }} · {{ account.account }} — {{ account.label }}
              </li>
            </ul>
          </section>
        </template>
      </section>

      <section v-else-if="section === 'setup'" class="stack">
        <section class="panel">
          <p class="eyebrow">Assistant · étape 1 sur 4</p>
          <h3>Mode, groupe pilote, devise et période d’appartenance</h3>
          <form class="form-grid three" @submit.prevent="createGroup">
            <label>Usage
              <select v-model="groupDraft.mode" required>
                <option value="agregation_interne">Agrégation interne</option>
                <option value="consolidation_legale">Consolidation légale</option>
              </select>
            </label>
            <label>Code<input v-model="groupDraft.code" required></label>
            <label>Libellé<input v-model="groupDraft.label" required></label>
            <label>Devise<input v-model="groupDraft.currency" maxlength="3" required></label>
            <label>Valable dès le<input v-model="groupDraft.valid_from" type="date" required></label>
            <button class="button primary" :disabled="!workspace.capabilities.create_group || consolidation.saving">
              Créer le brouillon
            </button>
          </form>
          <p>
            Le dossier actuellement sélectionné devient le dossier pilote.
            Le choix du mode et de la devise sera figé dès la première période.
          </p>
        </section>
        <template v-if="selectedGroup">
          <nav class="subtabs tertiary-tabs" aria-label="Étapes de l’assistant de groupe">
            <button :class="{ active: wizardStep === 1 }" @click="wizardStep = 1">1. Mode</button>
            <button :class="{ active: wizardStep === 2 }" @click="wizardStep = 2">2. Dossiers membres</button>
            <button :class="{ active: wizardStep === 3 }" @click="wizardStep = 3">3. Ratios et mappings</button>
            <button :class="{ active: wizardStep === 4 }" @click="wizardStep = 4">4. Prévisualisation</button>
          </nav>

          <section v-if="wizardStep === 1" class="panel">
            <h3>Paramètres versionnés du groupe sélectionné</h3>
            <form class="form-grid three" @submit.prevent="updateGroup">
              <label>Usage
                <select v-model="groupUpdateDraft.mode" :disabled="workspace.periods.length > 0">
                  <option value="agregation_interne">Agrégation interne</option>
                  <option value="consolidation_legale">Consolidation légale</option>
                </select>
              </label>
              <label>Libellé<input v-model="groupUpdateDraft.label" required></label>
              <label>Devise<input v-model="groupUpdateDraft.currency" maxlength="3" :disabled="workspace.periods.length > 0" required></label>
              <button class="button primary" :disabled="!workspace.capabilities.setup">Enregistrer la version</button>
            </form>
            <div class="button-row">
              <button class="button" @click="wizardStep = 2">Continuer vers les membres</button>
              <button v-if="selectedGroup.status === 'actif'" class="button danger" @click="groupAction('archive')">Archiver</button>
              <button v-if="selectedGroup.status === 'archive'" class="button" @click="groupAction('reactivate')">Réactiver</button>
            </div>
          </section>

          <section v-else-if="wizardStep === 2" class="panel">
            <p class="eyebrow">Assistant · étape 2 sur 4</p>
            <h3>{{ selectedGroup.mode === 'agregation_interne' ? 'Dossiers membres' : 'Entités juridiques et dossiers membres' }}</h3>
            <div class="table-scroll"><table><thead><tr><th>Membre</th><th>Devise</th><th>Appartenance</th><th>Action</th></tr></thead><tbody>
              <tr v-for="member in workspace.members" :key="member.id">
                <td>{{ member.label }}</td><td>{{ member.currency }}</td>
                <td>{{ member.valid_from }} – {{ member.valid_until || 'ouverte' }}</td>
                <td><button class="button danger" :disabled="!workspace.capabilities.setup" @click="removeMember(member.id, member.version)">Retirer</button></td>
              </tr>
            </tbody></table></div>
            <form class="form-grid three" @submit.prevent="addMember">
              <label>Dossier visible
                <select v-model="memberDraft.scope" required>
                  <option value="" disabled>Choisir…</option>
                  <option v-for="candidate in availableMembers" :key="candidate.dossier_id" :value="`${candidate.organisation_id}:${candidate.dossier_id}`">
                    {{ candidate.label }}
                  </option>
                </select>
              </label>
              <label>Début<input v-model="memberDraft.valid_from" type="date" required></label>
              <label>Fin facultative<input v-model="memberDraft.valid_until" type="date"></label>
              <button class="button" :disabled="!workspace.capabilities.setup">Ajouter le membre</button>
            </form>
            <p v-if="selectedGroup.mode === 'consolidation_legale'" class="notice warning">
              La première période sera refusée tant que deux organisations juridiques distinctes ne sont pas présentes.
            </p>
            <button class="button primary" :disabled="workspace.members.length < 2" @click="wizardStep = 3">
              Continuer vers les ratios
            </button>
          </section>

          <section v-else-if="wizardStep === 3" class="stack">
          <section class="panel">
            <p class="eyebrow">Assistant · étape 3 sur 4</p>
            <h3>Période et ratios figés</h3>
            <p class="notice warning">
              Politique de conversion à valider : le moteur applique le ratio sourcé saisi
              pour chaque membre et période, sans déduire de taux moyen ou historique par
              classe de comptes.
            </p>
            <form class="stack" @submit.prevent="createPeriod">
              <div class="form-grid three">
                <label>Libellé<input v-model="periodDraft.label" required></label>
                <label>Début<input v-model="periodDraft.start" type="date" required></label>
                <label>Fin<input v-model="periodDraft.end" type="date" required></label>
              </div>
              <div v-for="member in workspace.members" :key="member.id" class="form-grid four">
                <strong>{{ member.label }} · {{ member.currency }} → {{ selectedGroup.currency }}</strong>
                <label>Numérateur<input v-model.number="conversionDrafts[member.id].numerator" type="number" min="1" required></label>
                <label>Dénominateur<input v-model.number="conversionDrafts[member.id].denominator" type="number" min="1" required></label>
                <label>Source<input v-model="conversionDrafts[member.id].source" required></label>
              </div>
              <button class="button primary" :disabled="!workspace.capabilities.setup">Créer et figer</button>
            </form>
          </section>
          <section class="panel">
            <h3>Mapping des comptes</h3>
            <form class="form-grid three" @submit.prevent="saveMapping">
              <label>Membre
                <select v-model.number="mappingDraft.member_id" required @change="mappingDraft.source_account_id = 0">
                  <option v-for="member in workspace.members" :key="member.id" :value="member.id">{{ member.label }}</option>
                </select>
              </label>
              <label>Compte source
                <AccountCombobox
                  v-model="mappingDraft.source_account_id"
                  :options="selectedMappingMember?.accounts || []"
                  placeholder="Choisir…"
                  required
                />
              </label>
              <label>Compte cible<input v-model="mappingDraft.target_account" required></label>
              <label>Libellé cible<input v-model="mappingDraft.target_label" required></label>
              <label>Type
                <select v-model="mappingDraft.target_type">
                  <option v-for="type in ['actif', 'passif', 'fonds_propres', 'produit', 'charge', 'hors_bilan']" :key="type">{{ type }}</option>
                </select>
              </label>
              <label v-if="mappingDraft.version > 0">Prise d’effet après clôture
                <input v-model="mappingDraft.effective_from" type="date">
              </label>
              <button class="button primary" :disabled="!workspace.capabilities.setup">
                {{ mappingDraft.version > 0 ? 'Enregistrer la nouvelle version' : 'Mapper' }}
              </button>
            </form>
            <div class="table-scroll"><table><thead><tr><th>Membre</th><th>Source</th><th>Cible</th><th>Type</th><th>Version</th><th>Action</th></tr></thead><tbody>
              <tr v-for="mapping in workspace.mappings" :key="mapping.id">
                <td>{{ mapping.member_label }}</td>
                <td>{{ mapping.source_account }} — {{ mapping.source_label }}</td>
                <td>{{ mapping.target_account }} — {{ mapping.target_label }}</td>
                <td>{{ mapping.target_type }}</td>
                <td>v{{ mapping.version }} · {{ mapping.active ? 'actif' : 'inactif' }}</td>
                <td>
                  <div class="button-row">
                    <button v-if="mapping.active" class="button" @click="editMapping(mapping)">Modifier</button>
                    <button v-if="mapping.active" class="button danger" @click="disableMapping(mapping.id, mapping.version)">Désactiver</button>
                  </div>
                </td>
              </tr>
            </tbody></table></div>
            <p v-if="workspace.balance?.unmapped_accounts.length" class="notice warning">
              {{ workspace.balance.unmapped_accounts.length }} compte(s) mouvementé(s) restent à mapper.
            </p>
            <button class="button primary" :disabled="!workspace.periods.length" @click="wizardStep = 4">
              Prévisualiser le résultat
            </button>
          </section>
          </section>

          <section v-else class="panel">
            <p class="eyebrow">Assistant · étape 4 sur 4</p>
            <h3>Prévisualisation et activation</h3>
            <p><strong>{{ modeLabel }}</strong> · {{ workspace.members.length }} dossiers membres · {{ selectedGroup.currency }}</p>
            <template v-if="workspace.activation_preview">
              <div class="metric-strip">
                <span><small>Sources converties</small><strong>{{ formatMoney(workspace.activation_preview.source_total_cents) }}</strong></span>
                <span><small>Éliminations</small><strong>{{ formatMoney(workspace.activation_preview.elimination_total_cents) }}</strong></span>
                <span><small>Résultat</small><strong>{{ formatMoney(workspace.activation_preview.result_total_cents) }}</strong></span>
              </div>
              <p>{{ workspace.activation_preview.formula }}</p>
              <ul v-if="workspace.activation_preview.issues.length">
                <li v-for="issue in workspace.activation_preview.issues" :key="issue">{{ issue }}</li>
              </ul>
              <button
                class="button primary"
                :disabled="!workspace.activation_preview.ready || !workspace.capabilities.setup"
                @click="groupAction('activate')"
              >
                Confirmer et activer
              </button>
            </template>
            <p v-else class="notice success">Le groupe est {{ selectedGroup.status }} et reste réconciliable depuis sa balance.</p>
          </section>
        </template>
      </section>

      <section v-else-if="section === 'reconciliation'" class="stack">
        <section class="panel">
          <h3>Réconciliation inter-entités</h3>
          <form v-if="workspace.members.length > 1" class="form-grid three" @submit.prevent="savePair">
            <label>Libellé<input v-model="pairDraft.label" required></label>
            <label>Membre gauche<select v-model.number="pairDraft.left_member_id" @change="pairDraft.left_account_id = 0"><option v-for="member in workspace.members" :key="member.id" :value="member.id">{{ member.label }}</option></select></label>
            <label>Compte gauche<AccountCombobox v-model="pairDraft.left_account_id" :options="leftMember?.accounts || []" placeholder="Choisir…" required /></label>
            <label>Membre droite<select v-model.number="pairDraft.right_member_id" @change="pairDraft.right_account_id = 0"><option v-for="member in workspace.members" :key="member.id" :value="member.id">{{ member.label }}</option></select></label>
            <label>Compte droite<AccountCombobox v-model="pairDraft.right_account_id" :options="rightMember?.accounts || []" placeholder="Choisir…" required /></label>
            <button class="button primary" :disabled="!workspace.capabilities.setup">Créer la paire</button>
          </form>
          <div class="table-scroll"><table><thead><tr><th>Paire</th><th>Gauche</th><th>Droite</th><th>Écart</th><th>État</th></tr></thead><tbody>
            <tr v-for="pair in workspace.reconciliation" :key="Number(pair.id)">
              <td>{{ pair.label }}</td>
              <td>{{ pair.left_member_label }} · {{ formatMoney(Number(pair.left_cents)) }}</td>
              <td>{{ pair.right_member_label }} · {{ formatMoney(Number(pair.right_cents)) }}</td>
              <td>{{ formatMoney(Number(pair.difference_cents)) }}</td>
              <td><span :class="['status-chip', pair.reconciled ? 'ok' : 'warning']">{{ pair.reconciled ? 'Réconcilié' : 'Écart' }}</span></td>
            </tr>
            <tr v-if="!workspace.reconciliation.length"><td colspan="5">Aucune paire configurée pour cette période.</td></tr>
          </tbody></table></div>
          <h4>Paires configurées</h4>
          <div class="table-scroll"><table><thead><tr><th>Libellé</th><th>Gauche</th><th>Droite</th><th>Version</th><th>Action</th></tr></thead><tbody>
            <tr v-for="pair in workspace.intercompany_pairs" :key="pair.id">
              <td>{{ pair.label }}</td>
              <td>{{ pair.left_member_label }} · {{ pair.left_account }}</td>
              <td>{{ pair.right_member_label }} · {{ pair.right_account }}</td>
              <td>v{{ pair.version }} · {{ pair.active ? 'active' : 'inactive' }}</td>
              <td><button v-if="pair.active" class="button danger" @click="disablePair(pair.id, pair.version)">Désactiver</button></td>
            </tr>
          </tbody></table></div>
        </section>
      </section>

      <section v-else-if="section === 'eliminations'" class="stack">
        <section class="panel">
          <div class="section-heading"><div><h3>Écritures d’élimination séparées</h3><p>Validées, documentées et immuables.</p></div>
            <button v-if="selectedPeriod?.statut === 'ouverte'" class="button danger" :disabled="!workspace.capabilities.validate" @click="closePeriod">Clôturer la période</button>
          </div>
          <form v-if="selectedPeriod?.statut === 'ouverte'" class="stack" @submit.prevent="createElimination">
            <div class="form-grid three">
              <label>Référence<input v-model="eliminationDraft.reference" required></label>
              <label>Libellé<input v-model="eliminationDraft.label" required></label>
              <label>Justification<input v-model="eliminationDraft.justification" required></label>
            </div>
            <div v-for="(line, index) in eliminationDraft.lines" :key="index" class="form-grid four">
              <label>Compte cible<AccountCombobox v-model="line.target_account" :options="targetAccounts" value-key="account" number-key="account" :empty-value="''" placeholder="Choisir…" required /></label>
              <label>Libellé<input v-model="line.label"></label>
              <label>Débit<input v-model="line.debit" inputmode="decimal" placeholder="0.00"></label>
              <label>Crédit<input v-model="line.credit" inputmode="decimal" placeholder="0.00"></label>
            </div>
            <button class="button primary" :disabled="!workspace.capabilities.validate">Valider l’élimination</button>
          </form>
        </section>
        <section v-for="elimination in workspace.eliminations" :key="elimination.id" class="panel">
          <div class="section-heading"><div><h3>{{ elimination.reference }} — {{ elimination.label }}</h3><p>{{ elimination.justification }}</p></div><span class="status-chip ok">{{ elimination.status }}</span></div>
          <p>Dossiers membres : {{ workspace.members.map((member) => member.label).join(', ') }}</p>
          <div class="table-scroll"><table><thead><tr><th>Compte</th><th>Libellé</th><th>Débit</th><th>Crédit</th></tr></thead><tbody>
            <tr v-for="line in elimination.lines" :key="line.position"><td>{{ line.target_account }}</td><td>{{ line.label }}</td><td>{{ formatMoney(line.debit_cents) }}</td><td>{{ formatMoney(line.credit_cents) }}</td></tr>
          </tbody></table></div>
          <small>Validée le {{ elimination.validated_at }} · aucune écriture statutaire créée.</small>
        </section>
      </section>

      <section v-else class="stack">
        <section class="panel">
          <h3>Nouvelle version juridique de l’entité courante</h3>
          <p>Une nouvelle version ferme automatiquement la précédente à la veille. L’historique reste immuable.</p>
          <form class="form-grid three" @submit.prevent="saveLegalAttributes">
            <label>Valable dès le<input v-model="legalDraft.valid_from" type="date" required></label>
            <label>Raison sociale<input v-model="legalDraft.legal_name" required></label>
            <label>Forme juridique<input v-model="legalDraft.legal_form"></label>
            <label>IDE<input v-model="legalDraft.uid"></label>
            <label>Source<input v-model="legalDraft.source" required></label>
            <label>Adresse<input v-model="legalDraft.line1"></label>
            <label>Complément<input v-model="legalDraft.line2"></label>
            <label>NPA<input v-model="legalDraft.postal_code"></label>
            <label>Localité<input v-model="legalDraft.city"></label>
            <label>Canton<input v-model="legalDraft.canton"></label>
            <label>Pays<input v-model="legalDraft.country" maxlength="2"></label>
            <button class="button primary" :disabled="!context.can('compta.setup')">Versionner</button>
          </form>
        </section>
        <section class="panel">
          <h3>Historique des membres</h3>
          <div class="table-scroll"><table><thead><tr><th>Entité</th><th>Validité</th><th>Raison sociale</th><th>Forme / IDE</th><th>Source</th></tr></thead><tbody>
            <tr v-for="legal in workspace.legal_histories" :key="legal.id">
              <td>{{ organisationLabel(legal.organisation_id) }}</td><td>{{ legal.valid_from }} – {{ legal.valid_until || 'ouverte' }}</td>
              <td>{{ legal.legal_name }}</td><td>{{ legal.legal_form }} · {{ legal.uid }}</td><td>{{ legal.source }}</td>
            </tr>
            <tr v-if="!workspace.legal_histories.length"><td colspan="5">Aucun attribut juridique daté.</td></tr>
          </tbody></table></div>
        </section>
      </section>
    </template>
  </section>
</template>
