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
const creating = ref(false);
const deleteDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
const today = new Date().toISOString().slice(0, 10);
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
const dependencies = computed(() => Object.entries(
  store.selected?.deletion_dependencies ?? {}
));

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
@media (max-width: 720px) {
  .registry-grid { grid-template-columns: 1fr; }
  .registry-heading, .panel-heading, .registry-actions, .inline-editor, .registry-filters {
    align-items: stretch; flex-direction: column;
  }
}
</style>
