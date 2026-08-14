<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import FormField from '@/components/ui/FormField.vue';
import ModalDialog from '@/components/ui/ModalDialog.vue';
import SkeletonBlock from '@/components/ui/SkeletonBlock.vue';
import { api } from '@/api/client';
import type {
  OrganisationLegalIdentity,
  StructureAccessMatrix
} from '@/api/contracts';
import { runtimeConfig } from '@/config';
import { useToastFeedback } from '@/composables/toastFeedback';
import { swissCantons } from '@/data/swissCantons';
import { useContextStore } from '@/stores/context';
import { useNotificationStore } from '@/stores/notifications';
import { useOrganisationRegistryStore } from '@/stores/organisationRegistry';
import { formatDate } from '@/utils/dateFormat';

const context = useContextStore();
const route = useRoute();
const notifications = useNotificationStore();
const store = useOrganisationRegistryStore();
useToastFeedback(store, false);
const canAdminister = computed(() => context.can('installation.admin'));
const canManageDossiers = computed(() =>
  canAdminister.value || context.can('organisation.manage')
);
const creating = ref(false);
const creatingDossier = ref(false);
const detailSection = ref<'dossiers' | 'information' | 'access'>('dossiers');
const accessOpen = ref(false);
const accessScope = ref<'installation' | 'organisation' | 'dossier'>('organisation');
const accessUserId = ref(0);
const accessRoleIds = ref<number[]>([]);
const structureAccessOverview = ref<Array<{
  id: number;
  name: string;
  roles: string[];
  dossiers: Array<{
    id: number;
    name: string;
    roles: string[];
  }>;
}>>([]);
const successorUserId = ref(0);
const copyAccess = ref(false);
const copySourceDossierId = ref(0);
const copyConfirmed = ref(false);
const usersCsv = ref('');
const accessCsv = ref('');
const usersCsvName = ref('');
const accessCsvName = ref('');
const deleteDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
const deleteDossierDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
const accessDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const importExportDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const legalHistoryDialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const legalForm = ref<HTMLFormElement | null>(null);
const selectedLegalIdentity = ref<OrganisationLegalIdentity | null>(null);
const today = new Date().toISOString().slice(0, 10);
const currentYear = new Date().getFullYear();
const createDraft = reactive({
  name: '',
  nature: 'reelle' as 'reelle' | 'pedagogique',
  valid_from: today,
  legal_name: '',
  legal_form: '',
  uid: '',
  source: '',
  line1: '',
  postal_code: '',
  city: '',
  canton: '',
  country: 'CH'
});
const editName = ref('');
const legalDraft = reactive({
  valid_from: today,
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
const dossierDraft = reactive({
  name: '',
  slug: '',
  type: 'reel' as 'reel' | 'demo' | 'exercice',
  currency: 'CHF',
  modules: ['comptabilite', 'facturation'] as string[],
  plan_variant: 'personne_morale',
  association: false,
  projects: false,
  restricted_funds: false,
  exercise_label: `Exercice ${currentYear}`,
  exercise_start: `${currentYear}-01-01`,
  exercise_end: `${currentYear}-12-31`,
  journal_code: 'OD',
  journal_label: 'Opérations diverses'
});
const dossierModuleChoices = [
  {
    code: 'comptabilite',
    label: 'Comptabilité',
    description: 'Plan comptable, journaux et états financiers.'
  },
  {
    code: 'facturation',
    label: 'Facturation',
    description: 'Factures de vente, d’achat et contacts.'
  },
  {
    code: 'liquidites',
    label: 'Liquidités',
    description: 'Trésorerie, paiements et lettrage.'
  },
  {
    code: 'salaires',
    label: 'Salaires',
    description: 'Employés, calculs et fiches de salaire.'
  },
  {
    code: 'apprentissage',
    label: 'Apprentissage',
    description: 'Fonctions pédagogiques du dossier.'
  }
] as const;
const dossierEdit = reactive({
  name: '',
  type: 'reel' as 'reel' | 'demo' | 'exercice',
  currency: 'CHF'
});
const dependencies = computed(() => Object.entries(
  store.selected?.deletion_dependencies ?? {}
));
const accessUser = computed(() => store.accessMatrix?.users.find(
  (user) => user.id === accessUserId.value
) ?? null);

watch(
  () => store.selected,
  (selected) => {
    if (!selected) return;
    editName.value = selected.nom;
    legalDraft.legal_name = selected.raison_sociale;
    legalDraft.legal_form = selected.forme_juridique;
    legalDraft.uid = selected.numero_ide;
    legalDraft.line1 = selected.adresse_ligne1;
    legalDraft.line2 = selected.adresse_ligne2;
    legalDraft.postal_code = selected.code_postal;
    legalDraft.city = selected.localite;
    legalDraft.canton = selected.canton;
    legalDraft.country = selected.pays || 'CH';
    legalDraft.valid_from = today;
    legalDraft.source = '';
  },
  { immediate: true }
);

watch(copySourceDossierId, () => {
  store.copyPreview = null;
  copyConfirmed.value = false;
});

watch(accessUserId, () => {
  accessRoleIds.value = [...(accessUser.value?.direct_role_ids ?? [])];
  successorUserId.value = 0;
  store.accessPreview = null;
});

async function editAccessUser(userId: number): Promise<void> {
  accessUserId.value = userId;
  const organizations = new Map<number, {
    id: number;
    name: string;
    dossiers: Array<{ id: number; name: string }>;
  }>();
  context.dossiers.forEach((dossier) => {
    const organization = organizations.get(dossier.organization_id) ?? {
      id: dossier.organization_id,
      name: dossier.organization_name,
      dossiers: []
    };
    organization.dossiers.push({ id: dossier.id, name: dossier.name });
    organizations.set(dossier.organization_id, organization);
  });
  structureAccessOverview.value = await Promise.all(
    [...organizations.values()].map(async (organization) => {
      const [organizationMatrix, ...dossierMatrices] = await Promise.all([
        api.get<StructureAccessMatrix>('/structures/access', {
          scope: 'organisation',
          organisation_id: organization.id
        }),
        ...organization.dossiers.map((dossier) => api.get<StructureAccessMatrix>(
          '/structures/access',
          {
            scope: 'dossier',
            organisation_id: organization.id,
            dossier_id: dossier.id
          }
        ))
      ]);
      const organizationUser = organizationMatrix.data.users.find(
        (user) => user.id === userId
      );
      return {
        id: organization.id,
        name: organization.name,
        roles: organizationUser?.organisation_roles.map((role) => role.label) || [],
        dossiers: organization.dossiers.map((dossier, index) => ({
          id: dossier.id,
          name: dossier.name,
          roles: dossierMatrices[index].data.users.find((user) => user.id === userId)
            ?.dossier_roles.map((role) => role.label) || []
        }))
      };
    })
  );
  await accessDialog.value?.open();
}

async function selectAccessOrganization(organizationId: number): Promise<void> {
  if (store.selected?.id === organizationId) return;
  await store.select(organizationId);
}

async function switchUserAccessScope(
  scope: 'installation' | 'organisation' | 'dossier',
  organizationId?: number,
  dossierId?: number
): Promise<void> {
  const userId = accessUserId.value;
  if (organizationId) await selectAccessOrganization(organizationId);
  if (scope === 'dossier' && dossierId) await store.selectDossier(dossierId);
  accessScope.value = scope;
  await store.loadAccess(scope);
  accessUserId.value = userId;
  accessRoleIds.value = [
    ...(store.accessMatrix?.users.find((user) => user.id === userId)
      ?.direct_role_ids || [])
  ];
  store.accessPreview = null;
}

watch(
  () => store.selectedDossier,
  (selected) => {
    if (!selected) return;
    dossierEdit.name = selected.nom;
    dossierEdit.type = selected.type;
    dossierEdit.currency = selected.monnaie;
  },
  { immediate: true }
);

watch(
  () => [route.query.organisation, route.query.section],
  async ([organisation, section]) => {
    if (!store.payload) await store.load();
    const organisationId = Number(organisation || 0);
    if (organisationId > 0 && store.selected?.id !== organisationId) {
      await store.select(organisationId);
    }
    if (
      section === 'information'
      && store.selected
    ) {
      detailSection.value = 'information';
    }
  },
  { immediate: true }
);

async function applyFilters(): Promise<void> {
  store.page = 1;
  await store.load();
}

async function selectOrganisation(id: number): Promise<void> {
  detailSection.value = 'dossiers';
  creatingDossier.value = false;
  await store.select(id);
}

async function createOrganisation(): Promise<void> {
  const identity = createDraft.nature === 'reelle' ? {
    valid_from: createDraft.valid_from,
    legal_name: createDraft.legal_name,
    legal_form: createDraft.legal_form,
    uid: createDraft.uid,
    source: createDraft.source,
    address: {
      line1: createDraft.line1,
      line2: '',
      postal_code: createDraft.postal_code,
      city: createDraft.city,
      canton: createDraft.canton,
      country: createDraft.country
    }
  } : undefined;
  try {
    await store.create({
      name: createDraft.name,
      nature: createDraft.nature,
      identity
    });
    detailSection.value = 'dossiers';
    creating.value = false;
    notifications.push(
      'Organisation créée sans attribution automatique de droits.',
      'success'
    );
  } catch {
    // Le résumé d’erreur du registre reste visible.
  }
}

async function updateName(): Promise<void> {
  if (!store.selected) return;
  try {
    await store.update({
      id: store.selected.id,
      version: store.selected.version,
      name: editName.value
    });
    notifications.push('Nom usuel mis à jour.', 'success');
  } catch {
    // Le résumé d’erreur du registre reste visible.
  }
}

async function saveLegalIdentity(): Promise<void> {
  if (!store.selected) return;
  try {
    await store.saveLegalIdentity({
      id: store.selected.id,
      version: store.selected.version,
      expected_legal_identity_id: store.selected.legal_history[0]?.id ?? 0,
      identity: {
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
      }
    });
    notifications.push('Nouvelle identité juridique datée enregistrée.', 'success');
  } catch {
    // Le résumé d’erreur du registre reste visible.
  }
}

async function viewLegalIdentity(identity: OrganisationLegalIdentity): Promise<void> {
  selectedLegalIdentity.value = identity;
  await legalHistoryDialog.value?.open();
}

function historicalAddress(key: string): string {
  return String(selectedLegalIdentity.value?.adresse?.[key] || '');
}

async function reuseLegalIdentity(identity: OrganisationLegalIdentity): Promise<void> {
  const latestStart = store.selected?.legal_history[0]?.date_debut || '';
  const nextStart = new Date(`${latestStart}T12:00:00`);
  nextStart.setDate(nextStart.getDate() + 1);
  const validFrom = latestStart && today <= latestStart
    ? nextStart.toISOString().slice(0, 10)
    : today;
  Object.assign(legalDraft, {
    valid_from: validFrom,
    legal_name: identity.raison_sociale,
    legal_form: identity.forme_juridique,
    uid: identity.numero_ide,
    source: identity.source,
    line1: String(identity.adresse.line1 || ''),
    line2: String(identity.adresse.line2 || ''),
    postal_code: String(identity.adresse.postal_code || ''),
    city: String(identity.adresse.city || ''),
    canton: String(identity.adresse.canton || ''),
    country: String(identity.adresse.country || 'CH')
  });
  legalHistoryDialog.value?.close();
  await nextTick();
  legalForm.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  legalForm.value?.querySelector<HTMLInputElement>('#registry-legal-name')?.focus();
  notifications.push(
    'Informations historiques reprises. Ajustez la date et la source avant l’enregistrement.',
    'success'
  );
}

async function toggleStatus(): Promise<void> {
  if (!store.selected) return;
  try {
    if (store.selected.active) await store.archive();
    else await store.reactivate();
    notifications.push(
      store.selected?.active ? 'Organisation réactivée.' : 'Organisation archivée.',
      'success'
    );
  } catch {
    // Le résumé d’erreur du registre reste visible.
  }
}

async function removeOrganisation(): Promise<void> {
  try {
    await store.remove();
    notifications.push(
      'Organisation vide supprimée. Son audit est conservé.',
      'success'
    );
  } catch {
    // Le résumé d’erreur du registre reste visible.
  }
}

async function createDossier(): Promise<void> {
  if (!store.selected) return;
  try {
    await store.createDossier({
      organisation_id: store.selected.id,
      name: dossierDraft.name,
      slug: dossierDraft.slug,
      type: dossierDraft.type,
      currency: dossierDraft.currency,
      modules: dossierDraft.modules,
      plan_variant: dossierDraft.plan_variant,
      association: {
        enabled: dossierDraft.association,
        projects: dossierDraft.projects,
        restricted_funds: dossierDraft.restricted_funds
      },
      exercise: {
        label: dossierDraft.exercise_label,
        start: dossierDraft.exercise_start,
        end: dossierDraft.exercise_end
      },
      journal: {
        code: dossierDraft.journal_code,
        label: dossierDraft.journal_label
      },
      access_copy: copyAccess.value && store.copyPreview && copyConfirmed.value
        ? {
          source_dossier_id: store.copyPreview.source_dossier_id,
          preview_hash: store.copyPreview.preview_hash
        }
        : null
    });
    creatingDossier.value = false;
    await context.load();
    notifications.push('Dossier créé et initialisé atomiquement.', 'success');
  } catch {
    // Le résumé d’erreur du registre reste visible.
  }
}

async function openAccess(
  scope: 'installation' | 'organisation' | 'dossier'
): Promise<void> {
  accessScope.value = scope;
  accessOpen.value = true;
  accessUserId.value = 0;
  accessRoleIds.value = [];
  await store.loadAccess(scope);
}

async function previewAccess(): Promise<void> {
  if (!accessUserId.value) return;
  await store.previewAccess(
    accessUserId.value,
    [...accessRoleIds.value],
    successorUserId.value || undefined
  );
}

async function applyAccess(): Promise<void> {
  await store.applyAccess(successorUserId.value || undefined);
  await context.load();
  accessDialog.value?.close();
  notifications.push('Matrice d’accès mise à jour et auditée.', 'success');
}

async function readCsv(
  event: Event,
  target: 'users' | 'access'
): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;
  const contents = await file.text();
  if (target === 'users') {
    usersCsv.value = contents;
    usersCsvName.value = file.name;
  } else {
    accessCsv.value = contents;
    accessCsvName.value = file.name;
  }
  store.accessCsvPreview = null;
}

async function previewAccessCsv(): Promise<void> {
  await store.previewAccessCsv(usersCsv.value, accessCsv.value);
}

async function importAccessCsv(): Promise<void> {
  await store.importAccessCsv(usersCsv.value, accessCsv.value);
  await context.load();
  notifications.push('Utilisateurs, rôles et accès importés.', 'success');
}

async function previewCopy(): Promise<void> {
  if (!copySourceDossierId.value) return;
  copyConfirmed.value = false;
  await store.previewAccessCopy(copySourceDossierId.value);
}

async function updateDossier(): Promise<void> {
  if (!store.selectedDossier) return;
  try {
    await store.updateDossier({
      id: store.selectedDossier.id,
      version: store.selectedDossier.version,
      name: dossierEdit.name,
      type: dossierEdit.type,
      currency: dossierEdit.currency
    });
    await context.load();
    notifications.push('Dossier mis à jour.', 'success');
  } catch {
    // Le résumé d’erreur du registre reste visible.
  }
}

async function toggleDossierStatus(): Promise<void> {
  if (!store.selectedDossier) return;
  try {
    const wasActive = store.selectedDossier.active;
    if (wasActive) await store.archiveDossier();
    else await store.reactivateDossier();
    await context.load();
    notifications.push(
      wasActive ? 'Dossier archivé.' : 'Dossier réactivé.',
      'success'
    );
  } catch {
    // Le résumé d’erreur du registre reste visible.
  }
}

async function removeDossier(): Promise<void> {
  try {
    await store.removeDossier();
    await context.load();
    notifications.push('Dossier vide supprimé.', 'success');
  } catch {
    // Le résumé d’erreur du registre reste visible.
  }
}
</script>

<template>
  <section class="registry-stack" aria-label="Gestion des organisations et dossiers">
    <ErrorSummary :message="store.error" />

    <form
      v-if="creating && canAdminister"
      class="panel registry-form"
      @submit.prevent="createOrganisation"
    >
      <div class="panel-heading">
        <div><p class="eyebrow">Nouveau registre</p><h3>Créer une organisation</h3></div>
      </div>
      <div class="registry-grid">
        <FormField id="registry-create-name" label="Nom usuel">
          <template #default="{ describedBy }">
            <input id="registry-create-name" v-model="createDraft.name" required :aria-describedby="describedBy">
          </template>
        </FormField>
        <FormField id="registry-create-nature" label="Nature">
          <template #default="{ describedBy }">
            <select id="registry-create-nature" v-model="createDraft.nature" :aria-describedby="describedBy">
              <option value="reelle">Réelle</option>
              <option value="pedagogique">Pédagogique</option>
            </select>
          </template>
        </FormField>
        <template v-if="createDraft.nature === 'reelle'">
          <FormField id="registry-create-legal" label="Raison sociale">
            <template #default="{ describedBy }">
              <input id="registry-create-legal" v-model="createDraft.legal_name" required :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="registry-create-from" label="Valable dès le">
            <template #default="{ describedBy }">
              <input id="registry-create-from" v-model="createDraft.valid_from" type="date" required :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="registry-create-source" label="Source">
            <template #default="{ describedBy }">
              <input id="registry-create-source" v-model="createDraft.source" required placeholder="Extrait RC, statuts…" :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="registry-create-form" label="Forme juridique">
            <template #default="{ describedBy }">
              <input id="registry-create-form" v-model="createDraft.legal_form" :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="registry-create-uid" label="IDE / UID">
            <template #default="{ describedBy }">
              <input id="registry-create-uid" v-model="createDraft.uid" :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="registry-create-address" label="Adresse">
            <template #default="{ describedBy }">
              <input id="registry-create-address" v-model="createDraft.line1" :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="registry-create-postal" label="NPA">
            <template #default="{ describedBy }">
              <input id="registry-create-postal" v-model="createDraft.postal_code" :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="registry-create-city" label="Localité">
            <template #default="{ describedBy }">
              <input id="registry-create-city" v-model="createDraft.city" :aria-describedby="describedBy">
            </template>
          </FormField>
          <FormField id="registry-create-canton" label="Canton">
            <template #default="{ describedBy }">
              <select id="registry-create-canton" v-model="createDraft.canton" :aria-describedby="describedBy">
                <option value="">Choisir…</option>
                <option v-for="canton in swissCantons" :key="canton.code" :value="canton.code">
                  {{ canton.code }} — {{ canton.label }}
                </option>
              </select>
            </template>
          </FormField>
          <FormField id="registry-create-country" label="Pays ISO">
            <template #default="{ describedBy }">
              <input id="registry-create-country" v-model="createDraft.country" maxlength="2" :aria-describedby="describedBy">
            </template>
          </FormField>
        </template>
      </div>
      <button type="submit" class="button" :disabled="store.saving">Créer</button>
    </form>

    <form class="panel registry-filters" role="search" @submit.prevent="applyFilters">
      <FormField id="registry-search" label="Rechercher">
        <template #default="{ describedBy }">
          <input id="registry-search" v-model="store.search" type="search" placeholder="Nom, raison sociale ou IDE" :aria-describedby="describedBy">
        </template>
      </FormField>
      <FormField id="registry-status" label="Statut">
        <template #default="{ describedBy }">
          <select id="registry-status" v-model="store.status" :aria-describedby="describedBy">
            <option value="all">Toutes</option>
            <option value="active">Actives</option>
            <option value="archived">Archivées</option>
          </select>
        </template>
      </FormField>
      <button type="submit" class="button secondary">Appliquer</button>
      <button
        v-if="canAdminister"
        type="button"
        class="button"
        @click="creating = !creating"
      >
        {{ creating ? 'Fermer' : 'Créer une organisation' }}
      </button>
    </form>

    <div class="registry-browser">
      <aside class="panel registry-directory" aria-label="Liste des organisations">
        <div class="directory-heading">
          <h2>Organisations</h2>
          <span v-if="store.payload">{{ store.payload.pagination.total }}</span>
        </div>
        <SkeletonBlock v-if="store.loading && !store.payload" :lines="5" />
        <EmptyState
          v-else-if="store.payload?.items.length === 0"
          title="Aucune organisation"
          description="Aucune organisation ne correspond aux filtres."
        />
        <ul v-else-if="store.payload" class="organisation-list">
          <li v-for="organisation in store.payload.items" :key="organisation.id">
            <button
              type="button"
              class="organisation-card"
              :aria-current="store.selected?.id === organisation.id ? 'true' : undefined"
              @click="selectOrganisation(organisation.id)"
            >
              <span class="organisation-card-heading">
                <strong>{{ organisation.nom }}</strong>
                <span class="status-badge" :class="organisation.active ? 'status-ouverte' : 'status-fermee'">
                  {{ organisation.active ? 'Active' : 'Archivée' }}
                </span>
              </span>
              <small>{{ organisation.raison_sociale || 'Sans identité juridique' }}</small>
              <span>
                {{ organisation.dossier_count }} dossier(s) ·
                {{ organisation.active_dossier_count }} actif(s)
              </span>
            </button>
          </li>
        </ul>
      </aside>

      <EmptyState
        v-if="!store.selected"
        title="Sélectionnez une organisation"
        description="Ses dossiers et ses paramètres apparaîtront ici."
      />
      <article v-else class="panel registry-detail">
      <div class="panel-heading">
        <div>
          <p class="eyebrow">Organisation #{{ store.selected.id }}</p>
          <h3>{{ store.selected.nom }}</h3>
        </div>
        <span class="status-badge" :class="store.selected.active ? 'status-ouverte' : 'status-fermee'">
          {{ store.selected.active ? 'Active' : 'Archivée' }} · v{{ store.selected.version }}
        </span>
      </div>

      <nav class="registry-section-nav" aria-label="Gestion de l’organisation">
        <button
          type="button"
          :class="{ active: detailSection === 'dossiers' }"
          @click="detailSection = 'dossiers'"
        >
          Dossiers
        </button>
        <button
          type="button"
          :class="{ active: detailSection === 'information' }"
          @click="detailSection = 'information'"
        >
          Informations
        </button>
        <button
          type="button"
          :class="{ active: detailSection === 'access' }"
          @click="detailSection = 'access'"
        >
          Accès
        </button>
      </nav>

      <section v-if="detailSection === 'information'" class="registry-section">
        <h3>Informations générales</h3>
        <form class="inline-editor" @submit.prevent="updateName">
        <FormField id="registry-edit-name" label="Nom usuel">
          <template #default="{ describedBy }">
            <input id="registry-edit-name" v-model="editName" required :aria-describedby="describedBy">
          </template>
        </FormField>
        <button type="submit" class="button secondary" :disabled="store.saving">Enregistrer</button>
        </form>

        <div class="registry-actions">
        <button type="button" class="button secondary" :disabled="store.saving" @click="toggleStatus">
          {{ store.selected.active ? 'Archiver' : 'Réactiver' }}
        </button>
        <button
          v-if="canAdminister"
          type="button"
          class="button danger"
          :disabled="dependencies.length > 0 || store.saving"
          @click="deleteDialog?.open()"
        >
          Supprimer définitivement
        </button>
        </div>
        <p v-if="store.selected.active_dossier_count > 0" class="help-text">
          L’archivage sera possible après archivage des dossiers actifs.
        </p>
        <div v-if="dependencies.length" class="dependency-note" role="status">
          <strong>Suppression protégée</strong>
          <p>Dépendances détectées :</p>
          <ul><li v-for="[table, count] in dependencies" :key="table">{{ table }} : {{ count }}</li></ul>
        </div>
      </section>

      <section
        v-if="detailSection === 'dossiers'"
        class="dossier-tree"
        aria-labelledby="dossier-tree-title"
      >
        <div class="panel-heading">
          <div>
            <p class="eyebrow">Arborescence</p>
            <h3 id="dossier-tree-title">Dossiers de {{ store.selected.nom }}</h3>
          </div>
          <button
            v-if="canManageDossiers && store.selected.active"
            type="button"
            class="button"
            @click="creatingDossier = !creatingDossier"
          >
            {{ creatingDossier ? 'Fermer l’assistant' : 'Créer un dossier' }}
          </button>
        </div>

        <EmptyState
          v-if="store.dossiers.length === 0"
          title="Aucun dossier"
          description="Lancez l’assistant pour créer le premier dossier exploitable."
        />
        <ul v-else class="dossier-list">
          <li v-for="dossier in store.dossiers" :key="dossier.id">
            <button
              type="button"
              class="dossier-node"
              :aria-current="store.selectedDossier?.id === dossier.id ? 'true' : undefined"
              @click="store.selectDossier(dossier.id)"
            >
              <span>
                <strong>{{ dossier.nom }}</strong>
                <small>{{ dossier.slug }} · {{ dossier.type }} · {{ dossier.monnaie }}</small>
              </span>
              <span class="status-badge" :class="dossier.active ? 'status-ouverte' : 'status-fermee'">
                {{ dossier.active ? 'Actif' : 'Archivé' }}
              </span>
            </button>
          </li>
        </ul>

        <form
          v-if="creatingDossier && canManageDossiers"
          class="registry-form wizard dossier-wizard"
          @submit.prevent="createDossier"
        >
          <header class="dossier-wizard-header">
            <span class="dossier-wizard-number" aria-hidden="true">01</span>
            <div>
              <p class="eyebrow">Assistant transactionnel</p>
              <h3>Initialiser un dossier</h3>
              <p>Définissez sa structure, ses modules et son premier exercice.</p>
            </div>
          </header>

          <section class="dossier-wizard-section">
            <div class="dossier-wizard-section-heading">
              <h4>Identité et structure</h4>
              <p>Les paramètres fondamentaux du nouveau dossier comptable.</p>
            </div>
            <div class="dossier-wizard-fields dossier-identity-fields">
              <FormField id="dossier-create-name" label="Nom">
                <template #default="{ describedBy }"><input id="dossier-create-name" v-model="dossierDraft.name" required :aria-describedby="describedBy"></template>
              </FormField>
              <FormField id="dossier-create-slug" label="Slug unique">
                <template #default="{ describedBy }">
                  <input
                    id="dossier-create-slug"
                    v-model="dossierDraft.slug"
                    required
                    pattern="[a-z0-9][a-z0-9_-]{1,62}"
                    placeholder="comptabilite_2026"
                    title="Lettres minuscules, chiffres, tirets ou tirets bas uniquement"
                    :aria-describedby="describedBy"
                  >
                </template>
              </FormField>
              <FormField id="dossier-create-type" label="Type">
                <template #default="{ describedBy }">
                  <select id="dossier-create-type" v-model="dossierDraft.type" :aria-describedby="describedBy">
                    <option value="reel">Réel</option><option value="demo">Démonstration</option><option value="exercice">Exercice pédagogique</option>
                  </select>
                </template>
              </FormField>
              <FormField id="dossier-create-currency" label="Devise de base">
                <template #default="{ describedBy }"><input id="dossier-create-currency" v-model="dossierDraft.currency" required maxlength="3" pattern="[A-Za-z]{3}" :aria-describedby="describedBy"></template>
              </FormField>
              <FormField id="dossier-create-plan" label="Variante du plan comptable" class="dossier-plan-field">
                <template #default="{ describedBy }">
                  <select id="dossier-create-plan" v-model="dossierDraft.plan_variant" :aria-describedby="describedBy">
                    <option value="personne_morale">Personne morale</option>
                    <option value="raison_individuelle">Raison individuelle</option>
                    <option value="societe_personnes">Société de personnes</option>
                  </select>
                </template>
              </FormField>
            </div>
          </section>

          <section class="dossier-wizard-section">
            <div class="dossier-wizard-section-heading">
              <h4>Périmètre fonctionnel</h4>
              <p>Activez uniquement les fonctions utiles à ce dossier.</p>
            </div>
            <div class="dossier-wizard-choices">
              <fieldset class="choice-field">
                <legend>Modules actifs</legend>
                <label v-for="module in dossierModuleChoices" :key="module.code" class="choice-option">
                  <input v-model="dossierDraft.modules" type="checkbox" :value="module.code">
                  <span>
                    <strong>{{ module.label }}</strong>
                    <small>{{ module.description }}</small>
                  </span>
                </label>
              </fieldset>
              <fieldset class="choice-field">
                <legend>Fonctionnalités associatives</legend>
                <label class="choice-option">
                  <input v-model="dossierDraft.association" type="checkbox">
                  <span><strong>Overlay association</strong><small>Active la structure propre aux associations.</small></span>
                </label>
                <label class="choice-option">
                  <input v-model="dossierDraft.projects" type="checkbox" :disabled="!dossierDraft.association">
                  <span><strong>Comptes de projets</strong><small>Suit les activités par projet.</small></span>
                </label>
                <label class="choice-option">
                  <input v-model="dossierDraft.restricted_funds" type="checkbox" :disabled="!dossierDraft.association">
                  <span><strong>Fonds affectés</strong><small>Distingue les ressources à affectation limitée.</small></span>
                </label>
              </fieldset>
            </div>
          </section>

          <section class="dossier-wizard-section">
            <div class="dossier-wizard-section-heading">
              <h4>Accès initiaux</h4>
              <p>Le nouveau dossier reste sans attribution automatique par défaut.</p>
            </div>
            <fieldset class="choice-field access-copy-field">
              <legend>Reprendre des accès existants</legend>
              <label class="choice-option">
                <input v-model="copyAccess" type="checkbox">
                <span>
                  <strong>Copier la matrice d’un dossier frère</strong>
                  <small>La copie nécessite toujours un aperçu et une confirmation explicite.</small>
                </span>
              </label>
              <div v-if="copyAccess" class="access-copy-controls">
                <select v-model.number="copySourceDossierId" aria-label="Dossier source des accès">
                  <option :value="0">Choisir le dossier source…</option>
                  <option
                    v-for="dossier in store.dossiers.filter((item) => item.active)"
                    :key="dossier.id"
                    :value="dossier.id"
                  >
                    {{ dossier.nom }}
                  </option>
                </select>
                <button
                  type="button"
                  class="button secondary compact"
                  :disabled="!copySourceDossierId || store.saving"
                  @click="previewCopy"
                >
                  Prévisualiser la copie
                </button>
              </div>
              <div v-if="store.copyPreview" class="copy-preview" role="status">
                <strong>{{ store.copyPreview.assignment_count }} attribution(s) directe(s)</strong>
                <ul>
                  <li
                    v-for="assignment in store.copyPreview.assignments"
                    :key="`${assignment.user_id}-${assignment.role_id}`"
                  >
                    {{ assignment.user_name || assignment.user_email }}
                    · {{ assignment.role_label }}
                  </li>
                </ul>
                <label class="choice-option copy-confirmation">
                  <input v-model="copyConfirmed" type="checkbox">
                  <span><strong>Je confirme exactement cette matrice</strong></span>
                </label>
              </div>
            </fieldset>
          </section>

          <section class="dossier-wizard-section">
            <div class="dossier-wizard-section-heading">
              <h4>Premier exercice et journal</h4>
              <p>Ces éléments seront immédiatement disponibles après l’initialisation.</p>
            </div>
            <div class="dossier-wizard-fields dossier-period-fields">
              <FormField id="dossier-exercise-label" label="Premier exercice">
                <template #default="{ describedBy }"><input id="dossier-exercise-label" v-model="dossierDraft.exercise_label" required :aria-describedby="describedBy"></template>
              </FormField>
              <FormField id="dossier-exercise-start" label="Début">
                <template #default="{ describedBy }"><input id="dossier-exercise-start" v-model="dossierDraft.exercise_start" type="date" required :aria-describedby="describedBy"></template>
              </FormField>
              <FormField id="dossier-exercise-end" label="Fin">
                <template #default="{ describedBy }"><input id="dossier-exercise-end" v-model="dossierDraft.exercise_end" type="date" required :aria-describedby="describedBy"></template>
              </FormField>
              <FormField id="dossier-journal-code" label="Code du journal général">
                <template #default="{ describedBy }">
                  <input
                    id="dossier-journal-code"
                    v-model="dossierDraft.journal_code"
                    required
                    maxlength="12"
                    pattern="[A-Za-z0-9_-]{1,12}"
                    title="Lettres, chiffres, tirets ou tirets bas uniquement"
                    :aria-describedby="describedBy"
                  >
                </template>
              </FormField>
              <FormField id="dossier-journal-label" label="Libellé du journal" class="dossier-journal-label-field">
                <template #default="{ describedBy }"><input id="dossier-journal-label" v-model="dossierDraft.journal_label" required :aria-describedby="describedBy"></template>
              </FormField>
            </div>
          </section>

          <footer class="dossier-wizard-footer">
            <p>Le dossier, le plan, l’exercice, la période, le journal et les références seront créés dans une seule transaction.</p>
            <button
              type="submit"
              class="button"
              :disabled="store.saving || (copyAccess && (!store.copyPreview || !copyConfirmed))"
            >
              Créer et initialiser
            </button>
          </footer>
        </form>

        <div v-if="store.creationSummary" class="initialization-summary" role="status">
          <strong>Initialisation terminée</strong>
          <span>{{ store.creationSummary.account_count }} comptes</span>
          <span>{{ store.creationSummary.exercise_count }} exercice</span>
          <span>{{ store.creationSummary.period_count }} période</span>
          <span>{{ store.creationSummary.journal_count }} journal</span>
          <span>{{ store.creationSummary.currency }}</span>
          <span>{{ store.creationSummary.modules.join(', ') }}</span>
        </div>

        <article v-if="store.selectedDossier" class="dossier-detail">
          <div class="panel-heading">
            <div><p class="eyebrow">Dossier #{{ store.selectedDossier.id }}</p><h3>{{ store.selectedDossier.nom }}</h3></div>
            <span class="status-badge" :class="store.selectedDossier.active ? 'status-ouverte' : 'status-fermee'">
              {{ store.selectedDossier.active ? 'Actif' : 'Archivé' }} · v{{ store.selectedDossier.version }}
            </span>
          </div>
          <form class="registry-grid" @submit.prevent="updateDossier">
            <FormField id="dossier-edit-name" label="Nom">
              <template #default="{ describedBy }"><input id="dossier-edit-name" v-model="dossierEdit.name" required :aria-describedby="describedBy"></template>
            </FormField>
            <FormField id="dossier-edit-type" label="Type">
              <template #default="{ describedBy }">
                <select id="dossier-edit-type" v-model="dossierEdit.type" :disabled="store.selectedDossier.historical_data" :aria-describedby="describedBy">
                  <option value="reel">Réel</option><option value="demo">Démonstration</option><option value="exercice">Exercice pédagogique</option>
                </select>
              </template>
            </FormField>
            <FormField id="dossier-edit-currency" label="Devise">
              <template #default="{ describedBy }"><input id="dossier-edit-currency" v-model="dossierEdit.currency" maxlength="3" :disabled="store.selectedDossier.historical_data" :aria-describedby="describedBy"></template>
            </FormField>
            <button type="submit" class="button secondary" :disabled="store.saving">Enregistrer</button>
          </form>
          <p v-if="store.selectedDossier.historical_data" class="help-text">Le type et la devise sont verrouillés par les données historiques ; le nom reste modifiable.</p>
          <div class="initialization-summary">
            <span>{{ store.selectedDossier.summary.account_count }} comptes</span>
            <span>{{ store.selectedDossier.summary.exercise_count }} exercice(s)</span>
            <span>{{ store.selectedDossier.summary.period_count }} période(s)</span>
            <span>{{ store.selectedDossier.summary.journal_count }} journal(aux)</span>
          </div>
          <div class="registry-actions">
            <button type="button" class="button secondary" :disabled="store.saving" @click="toggleDossierStatus">
              {{ store.selectedDossier.active ? 'Archiver le dossier' : 'Réactiver le dossier' }}
            </button>
            <button
              type="button"
              class="button danger"
              :disabled="store.selectedDossier.historical_data || store.saving"
              @click="deleteDossierDialog?.open()"
            >
              Supprimer le dossier vide
            </button>
          </div>
        </article>

      </section>

      <section
        v-if="detailSection === 'access'"
        class="structure-access"
        aria-labelledby="structure-access-title"
      >
          <div class="panel-heading">
            <div>
              <p class="eyebrow">Gouvernance</p>
              <h3 id="structure-access-title">Accès aux structures</h3>
            </div>
            <button
              v-if="accessOpen"
              type="button"
              class="button secondary compact"
              @click="accessOpen = false"
            >
              Fermer
            </button>
          </div>
          <p>
            Consultez séparément les rôles d’installation, hérités de
            l’organisation et directs du dossier.
          </p>
          <div class="registry-actions access-scope-actions">
            <button type="button" class="button secondary" @click="openAccess('organisation')">
              Accès de l’organisation
            </button>
            <button
              type="button"
              class="button secondary"
              :disabled="!store.selectedDossier"
              @click="openAccess('dossier')"
            >
              Accès du dossier sélectionné
            </button>
            <button
              v-if="canAdminister"
              type="button"
              class="button secondary"
              @click="openAccess('installation')"
            >
              Rôles d’installation
            </button>
          </div>

          <div v-if="canAdminister" class="registry-actions import-export-action">
            <button
              type="button"
              class="button secondary"
              @click="importExportDialog?.open()"
            >
              Importer / exporter les accès
            </button>
          </div>

          <ModalDialog
            v-if="canAdminister"
            ref="importExportDialog"
            title="Importer ou exporter les accès"
            description="Utilisateurs, rôles et périmètres d’accès"
            wide
          >
            <section class="csv-access-panel" aria-labelledby="csv-access-title">
              <div>
                <h3 id="csv-access-title">Fichiers d’accès</h3>
                <p class="help-text">
                  Le mot de passe n’est jamais exporté. À l’import, laissez-le vide
                  pour conserver celui d’un utilisateur existant.
                </p>
              </div>
              <div class="registry-actions">
                <a class="button secondary" :href="`${runtimeConfig.apiBaseUrl}/structures/users/export`">
                  Exporter utilisateurs.csv
                </a>
                <a class="button secondary" :href="`${runtimeConfig.apiBaseUrl}/structures/access/export`">
                  Exporter roles_acces.csv
                </a>
              </div>
              <div class="csv-file-grid">
                <label>
                  <strong>CSV utilisateurs</strong>
                  <input type="file" accept=".csv,text/csv" @change="readCsv($event, 'users')">
                  <small>{{ usersCsvName || 'Aucun fichier sélectionné' }}</small>
                </label>
                <label>
                  <strong>CSV rôles et accès</strong>
                  <input type="file" accept=".csv,text/csv" @change="readCsv($event, 'access')">
                  <small>{{ accessCsvName || 'Aucun fichier sélectionné' }}</small>
                </label>
              </div>
              <button
                type="button"
                class="button secondary"
                :disabled="!usersCsv || !accessCsv || store.saving"
                @click="previewAccessCsv"
              >
                Vérifier les deux CSV
              </button>
              <div v-if="store.accessCsvPreview" class="permission-preview">
                <p>
                  Utilisateurs : {{ store.accessCsvPreview.users.created }} à créer,
                  {{ store.accessCsvPreview.users.updated }} à modifier,
                  {{ store.accessCsvPreview.users.unchanged }} inchangé(s).
                </p>
                <p>
                  Affectations : {{ store.accessCsvPreview.access.added }} à ajouter,
                  {{ store.accessCsvPreview.access.removed }} à retirer.
                </p>
                <p class="help-text">
                  Les affectations sont remplacées uniquement pour les utilisateurs
                  présents dans utilisateurs.csv.
                </p>
                <button
                  v-if="!store.accessCsvPreview.applied"
                  type="button"
                  class="button"
                  :disabled="store.saving"
                  @click="importAccessCsv"
                >
                  Confirmer l’import
                </button>
              </div>
            </section>
          </ModalDialog>

          <div v-if="accessOpen && store.accessMatrix" class="access-workspace">
            <div class="access-version">
              <strong>
                Périmètre :
                {{ accessScope === 'installation'
                  ? 'installation'
                  : accessScope === 'organisation'
                    ? `organisation · ${store.selected?.nom || ''}`
                    : `dossier · ${store.selectedDossier?.nom || ''}` }}
              </strong>
              <small>Version {{ store.accessMatrix.version.slice(0, 12) }}</small>
            </div>
            <div class="table-scroll" tabindex="0">
              <table class="data-table access-table">
                <thead>
                  <tr>
                    <th>Utilisateur</th>
                    <th>Installation</th>
                    <th>Organisation (hérité)</th>
                    <th>Dossier (direct)</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="user in store.accessMatrix.users" :key="user.id">
                    <td>
                      <button
                        type="button"
                        class="access-user-link"
                        :disabled="!user.active"
                        @click="editAccessUser(user.id)"
                      >
                        <strong>{{ user.name || user.email }}</strong>
                        <small>{{ user.email }}</small>
                      </button>
                    </td>
                    <td>{{ user.installation_roles.map((role) => role.label).join(', ') || '—' }}</td>
                    <td>{{ user.organisation_roles.map((role) => role.label).join(', ') || '—' }}</td>
                    <td>{{ user.dossier_roles.map((role) => role.label).join(', ') || '—' }}</td>
                    <td>
                      <button
                        type="button"
                        class="button secondary compact"
                        :disabled="!user.active"
                        @click="editAccessUser(user.id)"
                      >
                        Modifier
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <ModalDialog
              ref="accessDialog"
              :title="`Accès de ${accessUser?.name || accessUser?.email || 'l’utilisateur'}`"
              :description="`Périmètre : ${accessScope === 'installation'
                ? 'installation'
                : accessScope === 'organisation'
                  ? `organisation · ${store.selected?.nom || ''}`
                  : `dossier · ${store.selectedDossier?.nom || ''}`}`"
              wide
              @closed="accessUserId = 0"
            >
              <form v-if="accessUser" class="access-editor-modal" @submit.prevent="previewAccess">
                <section class="effective-access-summary">
                  <h3>Accès effectifs</h3>
                  <p>
                    Les rôles d’installation s’appliquent à toutes les organisations
                    et à tous les dossiers. Ils sont donc affichés comme hérités et
                    ne sont pas recochés parmi les rôles directs.
                  </p>
                  <dl>
                    <div>
                      <dt>Installation</dt>
                      <dd class="effective-role-list">
                        <label v-for="role in accessUser.installation_roles" :key="role.id">
                          <input type="checkbox" checked disabled>
                          {{ role.label }} <small>hérité partout</small>
                        </label>
                        <span v-if="!accessUser.installation_roles.length">Aucun</span>
                      </dd>
                    </div>
                    <div>
                      <dt>Organisation</dt>
                      <dd class="effective-role-list">
                        <label v-for="role in accessUser.organisation_roles" :key="role.id">
                          <input type="checkbox" checked disabled>
                          {{ role.label }} <small>sur l’organisation</small>
                        </label>
                        <span v-if="!accessUser.organisation_roles.length">Aucun rôle direct</span>
                      </dd>
                    </div>
                    <div>
                      <dt>Dossier</dt>
                      <dd class="effective-role-list">
                        <label v-for="role in accessUser.dossier_roles" :key="role.id">
                          <input type="checkbox" checked disabled>
                          {{ role.label }} <small>sur le dossier</small>
                        </label>
                        <span v-if="!accessUser.dossier_roles.length">Aucun rôle direct</span>
                      </dd>
                    </div>
                  </dl>
                  <div class="access-overview-list">
                    <section v-for="organization in structureAccessOverview" :key="organization.id">
                      <div class="access-overview-organization">
                        <span>
                          <strong>{{ organization.name }}</strong>
                          <small>Organisation · {{ organization.roles.join(', ') || 'aucun rôle direct' }}</small>
                        </span>
                        <button
                          class="button secondary compact"
                          type="button"
                          @click="switchUserAccessScope('organisation', organization.id)"
                        >Modifier</button>
                      </div>
                      <div v-for="dossier in organization.dossiers" :key="dossier.id">
                        <span>
                          <strong>{{ dossier.name }}</strong>
                          <small>Dossier · {{ dossier.roles.join(', ') || 'aucun rôle direct' }}</small>
                        </span>
                        <button
                          class="button secondary compact"
                          type="button"
                          @click="switchUserAccessScope('dossier', organization.id, dossier.id)"
                        >Modifier</button>
                      </div>
                    </section>
                  </div>
                </section>
                <fieldset class="choice-field access-role-grid">
                  <legend>Rôles directs à attribuer sur ce périmètre</legend>
                  <label v-for="role in store.accessMatrix.roles" :key="role.id">
                    <input v-model="accessRoleIds" type="checkbox" :value="role.id">
                    <span><strong>{{ role.label }}</strong><small>{{ role.permissions.join(', ') }}</small></span>
                  </label>
                </fieldset>
                <FormField id="access-successor" label="Successeur (si dernier administrateur)">
                  <template #default="{ describedBy }">
                    <select id="access-successor" v-model.number="successorUserId" :aria-describedby="describedBy">
                      <option :value="0">Aucun transfert</option>
                      <option
                        v-for="user in store.accessMatrix.users.filter((item) => item.id !== accessUserId && item.active)"
                        :key="user.id"
                        :value="user.id"
                      >
                        {{ user.name || user.email }}
                      </option>
                    </select>
                  </template>
                </FormField>
                <button type="submit" class="button secondary" :disabled="store.saving">
                  Prévisualiser les permissions
                </button>
              </form>

              <div v-if="store.accessPreview" class="permission-preview">
                <div>
                  <strong>Avant</strong>
                  <p>{{ store.accessPreview.before_permissions.join(', ') || 'Aucune permission' }}</p>
                </div>
                <div>
                  <strong>Après</strong>
                  <p>{{ store.accessPreview.after_permissions.join(', ') || 'Aucune permission' }}</p>
                </div>
                <p v-if="store.accessPreview.added_permissions.length">
                  Ajoutées : {{ store.accessPreview.added_permissions.join(', ') }}
                </p>
                <p v-if="store.accessPreview.removed_permissions.length">
                  Retirées : {{ store.accessPreview.removed_permissions.join(', ') }}
                </p>
                <button type="button" class="button" :disabled="store.saving" @click="applyAccess">
                  Confirmer cette matrice
                </button>
              </div>
            </ModalDialog>
          </div>
      </section>

      <section v-if="detailSection === 'information'" class="registry-section legal-section">
        <form ref="legalForm" class="registry-form" @submit.prevent="saveLegalIdentity">
        <div class="panel-heading">
          <div><p class="eyebrow">Historique immuable</p><h3>Ajouter une identité juridique datée</h3></div>
        </div>
        <div class="registry-grid">
          <FormField id="registry-legal-name" label="Raison sociale">
            <template #default="{ describedBy }"><input id="registry-legal-name" v-model="legalDraft.legal_name" required :aria-describedby="describedBy"></template>
          </FormField>
          <FormField id="registry-legal-from" label="Valable dès le">
            <template #default="{ describedBy }"><input id="registry-legal-from" v-model="legalDraft.valid_from" type="date" required :aria-describedby="describedBy"></template>
          </FormField>
          <FormField id="registry-legal-source" label="Source">
            <template #default="{ describedBy }"><input id="registry-legal-source" v-model="legalDraft.source" required :aria-describedby="describedBy"></template>
          </FormField>
          <FormField id="registry-legal-form" label="Forme juridique">
            <template #default="{ describedBy }"><input id="registry-legal-form" v-model="legalDraft.legal_form" :aria-describedby="describedBy"></template>
          </FormField>
          <FormField id="registry-legal-uid" label="IDE / UID">
            <template #default="{ describedBy }"><input id="registry-legal-uid" v-model="legalDraft.uid" :aria-describedby="describedBy"></template>
          </FormField>
          <FormField id="registry-legal-address" label="Adresse">
            <template #default="{ describedBy }"><input id="registry-legal-address" v-model="legalDraft.line1" :aria-describedby="describedBy"></template>
          </FormField>
          <FormField id="registry-legal-address-line2" label="Complément d’adresse">
            <template #default="{ describedBy }"><input id="registry-legal-address-line2" v-model="legalDraft.line2" :aria-describedby="describedBy"></template>
          </FormField>
          <FormField id="registry-legal-postal" label="NPA">
            <template #default="{ describedBy }"><input id="registry-legal-postal" v-model="legalDraft.postal_code" :aria-describedby="describedBy"></template>
          </FormField>
          <FormField id="registry-legal-city" label="Localité">
            <template #default="{ describedBy }"><input id="registry-legal-city" v-model="legalDraft.city" :aria-describedby="describedBy"></template>
          </FormField>
          <FormField id="registry-legal-canton" label="Canton">
            <template #default="{ describedBy }">
              <select id="registry-legal-canton" v-model="legalDraft.canton" :aria-describedby="describedBy">
                <option value="">Choisir…</option>
                <option v-for="canton in swissCantons" :key="canton.code" :value="canton.code">
                  {{ canton.code }} — {{ canton.label }}
                </option>
              </select>
            </template>
          </FormField>
          <FormField id="registry-legal-country" label="Pays ISO">
            <template #default="{ describedBy }"><input id="registry-legal-country" v-model="legalDraft.country" maxlength="2" :aria-describedby="describedBy"></template>
          </FormField>
        </div>
        <button type="submit" class="button secondary" :disabled="store.saving">Ajouter à l’historique</button>
        </form>

        <div>
          <h3>Historique juridique</h3>
          <EmptyState
            v-if="store.selected.legal_history.length === 0"
            title="Aucune identité datée"
            description="Ajoutez la première identité juridique documentée."
          />
          <ol v-else class="history-list">
            <li v-for="identity in store.selected.legal_history" :key="identity.id">
              <button
                type="button"
                class="history-entry-button"
                @click="viewLegalIdentity(identity)"
              >
                <span>
                  <strong>{{ identity.raison_sociale }}</strong>
                  <small>{{ identity.forme_juridique }} · {{ identity.numero_ide || 'sans IDE' }}</small>
                </span>
                <span>
                  <strong>{{ formatDate(identity.date_debut) }} → {{ formatDate(identity.date_fin, 'actuelle') }}</strong>
                  <small>Consulter les informations</small>
                </span>
              </button>
            </li>
          </ol>
        </div>
      </section>
      </article>
    </div>

    <ConfirmDialog
      ref="deleteDialog"
      title="Supprimer définitivement cette organisation ?"
      confirm-label="Supprimer"
      tone="danger"
      @confirm="removeOrganisation"
    >
      Cette action est réservée aux organisations vides. L’événement d’audit restera conservé.
    </ConfirmDialog>
    <ConfirmDialog
      ref="deleteDossierDialog"
      title="Supprimer définitivement ce dossier vide ?"
      confirm-label="Supprimer"
      tone="danger"
      @confirm="removeDossier"
    >
      Seules les données techniques produites par l’assistant seront retirées. Toute donnée métier bloque cette action.
    </ConfirmDialog>
    <ModalDialog
      ref="legalHistoryDialog"
      :title="selectedLegalIdentity?.raison_sociale || 'Identité juridique historique'"
      description="Version historique immuable de l’organisation"
      wide
      @closed="selectedLegalIdentity = null"
    >
      <div v-if="selectedLegalIdentity" class="legal-history-detail">
        <dl>
          <div><dt>Période de validité</dt><dd>{{ formatDate(selectedLegalIdentity.date_debut) }} → {{ formatDate(selectedLegalIdentity.date_fin, 'actuelle') }}</dd></div>
          <div><dt>Forme juridique</dt><dd>{{ selectedLegalIdentity.forme_juridique || '—' }}</dd></div>
          <div><dt>IDE / UID</dt><dd>{{ selectedLegalIdentity.numero_ide || '—' }}</dd></div>
          <div><dt>Source</dt><dd>{{ selectedLegalIdentity.source }}</dd></div>
          <div>
            <dt>Adresse</dt>
            <dd>
              {{ historicalAddress('line1') || '—' }}
              <span v-if="historicalAddress('line2')"><br>{{ historicalAddress('line2') }}</span>
              <br>{{ historicalAddress('postal_code') }} {{ historicalAddress('city') }}
              <br>{{ historicalAddress('canton') }}<span v-if="historicalAddress('canton') && historicalAddress('country')"> · </span>{{ historicalAddress('country') }}
            </dd>
          </div>
          <div><dt>Enregistrée le</dt><dd>{{ selectedLegalIdentity.cree_le }}</dd></div>
        </dl>
        <div class="registry-actions">
          <button
            type="button"
            class="button"
            @click="reuseLegalIdentity(selectedLegalIdentity)"
          >
            Reprendre pour une nouvelle modification
          </button>
        </div>
      </div>
    </ModalDialog>
  </section>
</template>

<style scoped>
.registry-stack { display: grid; gap: 1rem; }
.registry-heading, .panel-heading, .registry-actions, .inline-editor, .registry-filters {
  display: flex; gap: 1rem; align-items: end; justify-content: space-between;
}
.directory-heading h2, .registry-section h3 { margin: 0; }
.registry-form, .registry-detail { display: grid; gap: 1rem; }
.registry-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
.registry-filters { justify-content: flex-start; }
.registry-filters > :first-child { flex: 1; }
.registry-browser {
  display: grid;
  grid-template-columns: minmax(16rem, 22rem) minmax(0, 1fr);
  gap: 1rem;
  align-items: start;
}
.registry-directory {
  position: sticky;
  top: 5.25rem;
  display: grid;
  gap: .85rem;
  max-height: calc(100vh - 6.5rem);
  overflow-y: auto;
}
.directory-heading, .organisation-card-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .75rem;
}
.directory-heading > span {
  min-width: 2rem;
  padding: .2rem .5rem;
  color: white;
  background: var(--ink);
  border-radius: 999px;
  text-align: center;
}
.organisation-list {
  display: grid;
  gap: .55rem;
  margin: 0;
  padding: 0;
  list-style: none;
}
.organisation-card {
  display: grid;
  width: 100%;
  gap: .35rem;
  padding: .85rem;
  color: inherit;
  background: #f8f8fb;
  border: 1px solid var(--border);
  border-radius: .65rem;
  text-align: left;
  cursor: pointer;
}
.organisation-card:hover { border-color: var(--ink-soft); }
.organisation-card[aria-current="true"] {
  background: #f0f0f8;
  border-color: var(--accent);
  box-shadow: inset .25rem 0 0 var(--accent);
}
.organisation-card small, .organisation-card > span:last-child {
  color: var(--muted);
  font-size: .78rem;
}
.registry-section-nav {
  display: flex;
  flex-wrap: wrap;
  gap: .35rem;
  padding: .35rem;
  background: #f0f1f6;
  border-radius: .7rem;
}
.registry-section-nav button {
  flex: 1 1 8rem;
  min-height: 2.6rem;
  padding: .55rem .85rem;
  color: var(--ink);
  background: transparent;
  border: 0;
  border-radius: .5rem;
  font-weight: 750;
  cursor: pointer;
}
.registry-section-nav button.active {
  color: white;
  background: var(--ink);
  box-shadow: 0 4px 12px rgba(32, 33, 78, .18);
}
.registry-section { display: grid; gap: 1rem; }
.legal-section {
  padding-top: 1rem;
  border-top: 1px solid var(--border);
}
.data-table td:first-child { display: grid; gap: .25rem; }
.data-table small, .history-list small { color: var(--muted); }
.history-list { display: grid; gap: .75rem; padding: 0; list-style: none; }
.history-list li { border: 1px solid var(--border); border-radius: .5rem; overflow: hidden; }
.history-entry-button {
  display: flex;
  width: 100%;
  gap: 1rem;
  align-items: center;
  justify-content: space-between;
  padding: .85rem;
  color: inherit;
  background: var(--surface);
  border: 0;
  text-align: left;
  cursor: pointer;
}
.history-entry-button:hover { background: #f8f8fb; }
.history-entry-button > span { display: grid; gap: .2rem; }
.history-entry-button > span:last-child { text-align: right; }
.legal-history-detail { display: grid; gap: 1rem; }
.legal-history-detail dl {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: .75rem;
  margin: 0;
}
.legal-history-detail dl > div {
  display: grid;
  gap: .2rem;
  padding: .8rem;
  background: #f8f8fb;
  border-radius: .5rem;
}
.legal-history-detail dt { color: var(--muted); font-size: .78rem; font-weight: 750; }
.legal-history-detail dd { margin: 0; }
.dependency-note { padding: .75rem; border-left: .25rem solid #9b6a00; background: #f8f8fb; }
.help-text { color: var(--muted); }
.dossier-tree, .dossier-detail { display: grid; gap: 1rem; padding-top: 1rem; border-top: 1px solid var(--border); }
.structure-access, .access-workspace, .access-editor, .permission-preview {
  display: grid; gap: 1rem;
}
.structure-access { padding-top: 1rem; border-top: 1px solid var(--border); }
.csv-access-panel {
  display: grid;
  gap: 1rem;
  padding: 1rem;
  border: 1px solid var(--border);
  border-radius: .65rem;
  background: #f8f8fb;
}
.csv-access-panel h4, .csv-access-panel p { margin: 0; }
.csv-file-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
}
.csv-file-grid label { display: grid; gap: .45rem; }
.csv-file-grid input {
  width: 100%;
  padding: .55rem;
  background: white;
  border: 1px solid var(--border);
  border-radius: .45rem;
}
.csv-file-grid small { color: var(--muted); }
.access-version { display: flex; justify-content: space-between; gap: 1rem; }
.access-table td:first-child { min-width: 13rem; }
.access-table td:first-child small { display: block; }
.permission-preview, .copy-preview {
  padding: .75rem; border: 1px solid var(--border);
  border-radius: .5rem; background: #f8f8fb;
}
.permission-preview > div { display: grid; gap: .25rem; }
.permission-preview p, .copy-preview ul { margin: 0; }
.dossier-list { display: grid; gap: .5rem; margin: 0; padding: 0; list-style: none; }
.dossier-node { width: 100%; display: flex; gap: 1rem; align-items: center; justify-content: space-between; padding: .75rem; border: 1px solid var(--border); border-radius: .5rem; background: var(--surface); color: inherit; text-align: left; }
.dossier-node[aria-current="true"] { border-color: var(--accent); box-shadow: inset .25rem 0 0 var(--accent); }
.dossier-node span:first-child { display: grid; gap: .2rem; }
.dossier-wizard {
  gap: 0;
  overflow: hidden;
  background: white;
  border: 1px solid var(--border);
  border-radius: .85rem;
  box-shadow: 0 14px 34px rgba(32, 33, 78, .08);
}
.dossier-wizard-header {
  display: flex;
  gap: 1rem;
  align-items: flex-start;
  padding: 1.35rem;
  background: linear-gradient(135deg, #f0f1f8 0%, #fafafd 70%);
}
.dossier-wizard-header h3,
.dossier-wizard-header p,
.dossier-wizard-section-heading h4,
.dossier-wizard-section-heading p,
.dossier-wizard-footer p { margin: 0; }
.dossier-wizard-header > div { display: grid; gap: .25rem; }
.dossier-wizard-header > div > p:last-child,
.dossier-wizard-section-heading p,
.dossier-wizard-footer p { color: var(--muted); }
.dossier-wizard-number {
  display: grid;
  flex: 0 0 2.7rem;
  width: 2.7rem;
  height: 2.7rem;
  place-items: center;
  color: white;
  background: var(--ink);
  border-radius: .75rem;
  font-size: .82rem;
  font-weight: 850;
  letter-spacing: .05em;
  box-shadow: 0 7px 16px rgba(32, 33, 78, .18);
}
.dossier-wizard-section {
  padding: 1.35rem;
  border-top: 1px solid var(--border);
}
.dossier-wizard-section-heading {
  display: grid;
  grid-template-columns: minmax(11rem, .45fr) minmax(0, 1fr);
  gap: 1rem;
  align-items: baseline;
  margin-bottom: 1rem;
}
.dossier-wizard-section-heading h4 { font-size: .95rem; }
.dossier-wizard-section-heading p { font-size: .82rem; }
.dossier-wizard-fields {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 1rem;
}
.dossier-identity-fields > * { grid-column: span 3; }
.dossier-identity-fields > :nth-child(n + 3) { grid-column: span 2; }
.dossier-period-fields > :nth-child(-n + 3),
.dossier-period-fields > :nth-child(4) { grid-column: span 2; }
.dossier-period-fields > :nth-child(5) { grid-column: span 4; }
.dossier-wizard :deep(.form-field input),
.dossier-wizard :deep(.form-field select) { width: 100%; }
.dossier-wizard-choices {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
  align-items: start;
}
.choice-field {
  display: grid;
  min-width: 0;
  gap: .55rem;
  padding: 1rem;
  background: #f8f8fb;
  border: 1px solid var(--border);
  border-radius: .7rem;
}
.choice-field legend {
  padding: 0 .35rem;
  color: var(--ink);
  font-size: .82rem;
  font-weight: 800;
}
.choice-option {
  display: flex;
  gap: .7rem;
  align-items: flex-start;
  min-width: 0;
  padding: .7rem .75rem;
  background: white;
  border: 1px solid #d9dce7;
  border-radius: .55rem;
  cursor: pointer;
  transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
}
.choice-option:hover { border-color: #9da2b4; }
.choice-option:has(input:checked) {
  background: #f3f2ff;
  border-color: var(--accent);
  box-shadow: inset .2rem 0 0 var(--accent);
}
.choice-option:has(input:disabled) {
  opacity: .52;
  cursor: not-allowed;
}
.choice-option input {
  flex: 0 0 auto;
  width: 1.05rem;
  height: 1.05rem;
  min-height: 1.05rem;
  margin: .12rem 0 0;
  padding: 0;
  accent-color: var(--accent);
}
.choice-option > span {
  display: grid;
  min-width: 0;
  gap: .12rem;
  line-height: 1.25;
}
.choice-option strong { font-size: .83rem; }
.choice-option small { color: var(--muted); font-size: .75rem; }
.access-copy-field { align-content: start; }
.access-copy-controls {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: .65rem;
  align-items: center;
  padding-top: .25rem;
}
.access-copy-controls select { width: 100%; }
.copy-preview { display: grid; gap: .65rem; }
.copy-confirmation { margin-top: .15rem; }
.dossier-wizard-footer {
  display: flex;
  gap: 1.25rem;
  align-items: center;
  justify-content: space-between;
  padding: 1.1rem 1.35rem;
  background: #f8f8fb;
  border-top: 1px solid var(--border);
}
.dossier-wizard-footer p {
  max-width: 42rem;
  font-size: .78rem;
}
.dossier-wizard-footer .button { flex: 0 0 auto; }
.initialization-summary { display: flex; flex-wrap: wrap; gap: .5rem 1rem; padding: .75rem; border-radius: .5rem; background: #f8f8fb; }
@media (max-width: 1050px) {
  .registry-browser { grid-template-columns: 1fr; }
  .registry-directory {
    position: static;
    max-height: none;
  }
  .organisation-list { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 720px) {
  .registry-grid, .organisation-list, .csv-file-grid, .legal-history-detail dl { grid-template-columns: 1fr; }
  .registry-heading, .panel-heading, .registry-actions, .inline-editor, .registry-filters {
    align-items: stretch; flex-direction: column;
  }
  .history-entry-button { align-items: flex-start; flex-direction: column; }
  .history-entry-button > span:last-child { text-align: left; }
  .registry-section-nav button { flex-basis: auto; }
  .dossier-wizard-header,
  .dossier-wizard-section,
  .dossier-wizard-footer { padding: 1rem; }
  .dossier-wizard-section-heading,
  .dossier-wizard-fields,
  .dossier-wizard-choices,
  .access-copy-controls { grid-template-columns: 1fr; }
  .dossier-identity-fields > *,
  .dossier-identity-fields > :nth-child(n + 3),
  .dossier-period-fields > :nth-child(-n + 3),
  .dossier-period-fields > :nth-child(4),
  .dossier-period-fields > :nth-child(5) { grid-column: auto; }
  .dossier-wizard-footer { align-items: stretch; flex-direction: column; }
}
</style>
