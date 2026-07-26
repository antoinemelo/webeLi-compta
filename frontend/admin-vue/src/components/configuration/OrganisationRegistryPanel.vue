<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import FormField from '@/components/ui/FormField.vue';
import SkeletonBlock from '@/components/ui/SkeletonBlock.vue';
import { useContextStore } from '@/stores/context';
import { useNotificationStore } from '@/stores/notifications';
import { useOrganisationRegistryStore } from '@/stores/organisationRegistry';

const context = useContextStore();
const notifications = useNotificationStore();
const store = useOrganisationRegistryStore();
const canAdminister = computed(() => context.can('installation.admin'));
const canManageDossiers = computed(() =>
  canAdminister.value || context.can('organisation.manage')
);
const creating = ref(false);
const creatingDossier = ref(false);
const accessOpen = ref(false);
const accessScope = ref<'installation' | 'organisation' | 'dossier'>('organisation');
const accessUserId = ref(0);
const accessRoleIds = ref<number[]>([]);
const successorUserId = ref(0);
const copyAccess = ref(false);
const copySourceDossierId = ref(0);
const copyConfirmed = ref(false);
const deleteDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
const deleteDossierDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
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

void store.load();

async function applyFilters(): Promise<void> {
  store.page = 1;
  await store.load();
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
      identity: {
        valid_from: legalDraft.valid_from,
        legal_name: legalDraft.legal_name,
        legal_form: legalDraft.legal_form,
        uid: legalDraft.uid,
        source: legalDraft.source,
        address: {
          line1: legalDraft.line1,
          line2: '',
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
  notifications.push('Matrice d’accès mise à jour et auditée.', 'success');
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
  <section class="registry-stack" aria-labelledby="registry-title">
    <div class="panel registry-heading">
      <div>
        <p class="eyebrow">Structure de l’installation</p>
        <h2 id="registry-title">Organisations et dossiers</h2>
        <p>Le registre conserve les identités juridiques datées et protège les données déjà utilisées.</p>
      </div>
      <button
        v-if="canAdminister"
        type="button"
        class="button"
        @click="creating = !creating"
      >
        {{ creating ? 'Fermer' : 'Créer une organisation' }}
      </button>
    </div>

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
              <input id="registry-create-canton" v-model="createDraft.canton" maxlength="2" :aria-describedby="describedBy">
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
    </form>

    <SkeletonBlock v-if="store.loading && !store.payload" :lines="5" />
    <EmptyState
      v-else-if="store.payload?.items.length === 0"
      title="Aucune organisation"
      description="Aucune organisation ne correspond aux filtres."
    />
    <div v-else-if="store.payload" class="panel table-scroll" tabindex="0" aria-label="Registre des organisations">
      <table class="data-table">
        <caption>{{ store.payload.pagination.total }} organisation(s)</caption>
        <thead>
          <tr><th scope="col">Organisation</th><th scope="col">Nature</th><th scope="col">Dossiers</th><th scope="col">Statut</th><th scope="col">Action</th></tr>
        </thead>
        <tbody>
          <tr v-for="organisation in store.payload.items" :key="organisation.id">
            <td>
              <strong>{{ organisation.nom }}</strong>
              <small>{{ organisation.raison_sociale || 'Sans identité juridique' }}</small>
            </td>
            <td>{{ organisation.nature === 'reelle' ? 'Réelle' : 'Pédagogique' }}</td>
            <td>{{ organisation.dossier_count }} ({{ organisation.active_dossier_count }} actif(s))</td>
            <td>
              <span class="status-badge" :class="organisation.active ? 'status-ouverte' : 'status-fermee'">
                {{ organisation.active ? 'Active' : 'Archivée' }}
              </span>
            </td>
            <td><button type="button" class="button secondary small" @click="store.select(organisation.id)">Gérer</button></td>
          </tr>
        </tbody>
      </table>
    </div>

    <article v-if="store.selected" class="panel registry-detail">
      <div class="panel-heading">
        <div>
          <p class="eyebrow">Organisation #{{ store.selected.id }}</p>
          <h3>{{ store.selected.nom }}</h3>
        </div>
        <span class="status-badge" :class="store.selected.active ? 'status-ouverte' : 'status-fermee'">
          {{ store.selected.active ? 'Active' : 'Archivée' }} · v{{ store.selected.version }}
        </span>
      </div>

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

      <section class="dossier-tree" aria-labelledby="dossier-tree-title">
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
          class="registry-form wizard"
          @submit.prevent="createDossier"
        >
          <div><p class="eyebrow">Assistant transactionnel</p><h3>Initialiser un dossier</h3></div>
          <div class="registry-grid">
            <FormField id="dossier-create-name" label="Nom">
              <template #default="{ describedBy }"><input id="dossier-create-name" v-model="dossierDraft.name" required :aria-describedby="describedBy"></template>
            </FormField>
            <FormField id="dossier-create-slug" label="Slug unique">
              <template #default="{ describedBy }"><input id="dossier-create-slug" v-model="dossierDraft.slug" required pattern="[a-z0-9][a-z0-9-]{1,62}" placeholder="comptabilite-2026" :aria-describedby="describedBy"></template>
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
            <FormField id="dossier-create-plan" label="Variante du plan VEB">
              <template #default="{ describedBy }">
                <select id="dossier-create-plan" v-model="dossierDraft.plan_variant" :aria-describedby="describedBy">
                  <option value="personne_morale">Personne morale</option>
                  <option value="raison_individuelle">Raison individuelle</option>
                  <option value="societe_personnes">Société de personnes</option>
                </select>
              </template>
            </FormField>
            <fieldset class="choice-field">
              <legend>Modules actifs</legend>
              <label v-for="module in ['comptabilite', 'facturation', 'liquidites', 'salaires', 'apprentissage']" :key="module">
                <input v-model="dossierDraft.modules" type="checkbox" :value="module"> {{ module }}
              </label>
            </fieldset>
            <fieldset class="choice-field">
              <legend>Association</legend>
              <label><input v-model="dossierDraft.association" type="checkbox"> Installer l’overlay association</label>
              <label><input v-model="dossierDraft.projects" type="checkbox" :disabled="!dossierDraft.association"> Comptes de projets</label>
              <label><input v-model="dossierDraft.restricted_funds" type="checkbox" :disabled="!dossierDraft.association"> Fonds affectés</label>
            </fieldset>
            <fieldset class="choice-field access-copy-field">
              <legend>Accès initiaux</legend>
              <label>
                <input v-model="copyAccess" type="checkbox">
                Copier explicitement la matrice d’un dossier frère
              </label>
              <template v-if="copyAccess">
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
                  <label>
                    <input v-model="copyConfirmed" type="checkbox">
                    Je confirme exactement cette matrice
                  </label>
                </div>
              </template>
              <small>Aucun droit n’est copié sans aperçu et confirmation.</small>
            </fieldset>
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
              <template #default="{ describedBy }"><input id="dossier-journal-code" v-model="dossierDraft.journal_code" required maxlength="12" :aria-describedby="describedBy"></template>
            </FormField>
            <FormField id="dossier-journal-label" label="Libellé du journal">
              <template #default="{ describedBy }"><input id="dossier-journal-label" v-model="dossierDraft.journal_label" required :aria-describedby="describedBy"></template>
            </FormField>
          </div>
          <p class="help-text">Le dossier, le plan, l’exercice, la période, le journal et les références sont créés dans une seule transaction.</p>
          <button
            type="submit"
            class="button"
            :disabled="store.saving || (copyAccess && (!store.copyPreview || !copyConfirmed))"
          >
            Créer et initialiser
          </button>
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

        <section class="structure-access" aria-labelledby="structure-access-title">
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

          <div v-if="accessOpen && store.accessMatrix" class="access-workspace">
            <div class="access-version">
              <strong>
                Périmètre :
                {{ accessScope === 'installation' ? 'installation' : accessScope === 'organisation' ? 'organisation' : 'dossier' }}
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
                    <td><strong>{{ user.name || user.email }}</strong><small>{{ user.email }}</small></td>
                    <td>{{ user.installation_roles.map((role) => role.label).join(', ') || '—' }}</td>
                    <td>{{ user.organisation_roles.map((role) => role.label).join(', ') || '—' }}</td>
                    <td>{{ user.dossier_roles.map((role) => role.label).join(', ') || '—' }}</td>
                    <td>
                      <button
                        type="button"
                        class="button secondary compact"
                        :disabled="!user.active"
                        @click="accessUserId = user.id"
                      >
                        Modifier
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <form
              v-if="accessUser"
              class="access-editor"
              @submit.prevent="previewAccess"
            >
              <div>
                <p class="eyebrow">Modification contrôlée</p>
                <h4>{{ accessUser.name || accessUser.email }}</h4>
              </div>
              <fieldset class="choice-field">
                <legend>Rôles directs sur ce périmètre</legend>
                <label v-for="role in store.accessMatrix.roles" :key="role.id">
                  <input v-model="accessRoleIds" type="checkbox" :value="role.id">
                  {{ role.label }}
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
              <p v-if="!Array.isArray(store.accessPreview.transfer)">
                Transfert explicite à l’utilisateur #{{ store.accessPreview.transfer.user_id }}.
              </p>
              <button type="button" class="button" :disabled="store.saving" @click="applyAccess">
                Confirmer cette matrice
              </button>
            </div>
          </div>
        </section>
      </section>

      <form class="registry-form" @submit.prevent="saveLegalIdentity">
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
          <FormField id="registry-legal-postal" label="NPA">
            <template #default="{ describedBy }"><input id="registry-legal-postal" v-model="legalDraft.postal_code" :aria-describedby="describedBy"></template>
          </FormField>
          <FormField id="registry-legal-city" label="Localité">
            <template #default="{ describedBy }"><input id="registry-legal-city" v-model="legalDraft.city" :aria-describedby="describedBy"></template>
          </FormField>
          <FormField id="registry-legal-canton" label="Canton">
            <template #default="{ describedBy }"><input id="registry-legal-canton" v-model="legalDraft.canton" maxlength="2" :aria-describedby="describedBy"></template>
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
            <strong>{{ identity.raison_sociale }}</strong>
            <span>{{ identity.date_debut }} → {{ identity.date_fin || 'actuelle' }}</span>
            <small>{{ identity.forme_juridique }} · {{ identity.numero_ide || 'sans IDE' }} · source : {{ identity.source }}</small>
          </li>
        </ol>
      </div>
    </article>

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
  </section>
</template>

<style scoped>
.registry-stack { display: grid; gap: 1rem; }
.registry-heading, .panel-heading, .registry-actions, .inline-editor, .registry-filters {
  display: flex; gap: 1rem; align-items: end; justify-content: space-between;
}
.registry-form, .registry-detail { display: grid; gap: 1rem; }
.registry-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
.registry-filters { justify-content: flex-start; }
.registry-filters > :first-child { flex: 1; }
.data-table td:first-child { display: grid; gap: .25rem; }
.data-table small, .history-list small { color: var(--color-text-muted); }
.history-list { display: grid; gap: .75rem; padding: 0; list-style: none; }
.history-list li { display: grid; gap: .2rem; padding: .75rem; border: 1px solid var(--color-border); border-radius: .5rem; }
.dependency-note { padding: .75rem; border-left: .25rem solid var(--color-warning, #9b6a00); background: var(--color-surface-muted); }
.help-text { color: var(--color-text-muted); }
.dossier-tree, .dossier-detail { display: grid; gap: 1rem; padding-top: 1rem; border-top: 1px solid var(--color-border); }
.structure-access, .access-workspace, .access-editor, .permission-preview {
  display: grid; gap: 1rem;
}
.structure-access { padding-top: 1rem; border-top: 1px solid var(--color-border); }
.access-version { display: flex; justify-content: space-between; gap: 1rem; }
.access-table td:first-child { min-width: 13rem; }
.access-table td:first-child small { display: block; }
.permission-preview, .copy-preview {
  padding: .75rem; border: 1px solid var(--color-border);
  border-radius: .5rem; background: var(--color-surface-muted);
}
.permission-preview > div { display: grid; gap: .25rem; }
.permission-preview p, .copy-preview ul { margin: 0; }
.access-copy-field { align-content: start; }
.dossier-list { display: grid; gap: .5rem; margin: 0; padding: 0; list-style: none; }
.dossier-node { width: 100%; display: flex; gap: 1rem; align-items: center; justify-content: space-between; padding: .75rem; border: 1px solid var(--color-border); border-radius: .5rem; background: var(--color-surface); color: inherit; text-align: left; }
.dossier-node[aria-current="true"] { border-color: var(--color-primary); }
.dossier-node span:first-child { display: grid; gap: .2rem; }
.choice-field { display: grid; gap: .45rem; padding: .75rem; border: 1px solid var(--color-border); border-radius: .5rem; }
.initialization-summary { display: flex; flex-wrap: wrap; gap: .5rem 1rem; padding: .75rem; border-radius: .5rem; background: var(--color-surface-muted); }
@media (max-width: 720px) {
  .registry-grid { grid-template-columns: 1fr; }
  .registry-heading, .panel-heading, .registry-actions, .inline-editor, .registry-filters {
    align-items: stretch; flex-direction: column;
  }
}
</style>
