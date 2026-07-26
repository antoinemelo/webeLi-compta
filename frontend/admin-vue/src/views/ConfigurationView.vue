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
const canManage = computed(() => context.can('dossier.manage'));
const today = new Date().toISOString().slice(0, 10);

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
      ? ['users', 'contacts'].includes(item.key)
      : !['users'].includes(item.key)
  )
);
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
  if (context.selection && canManage.value) await store.load();
}

function directionLabel(value: string): string {
  return value === 'client' ? 'Clients' : value === 'fournisseur' ? 'Fournisseurs' : 'Tous';
}

function legacyUrl(path: string): string {
  return `${runtimeConfig.baseUrl}${path}`;
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

      <section
        v-else-if="activeTab === 'referentiels' || activeTab === 'acces'"
        class="reference-grid"
      >
        <article v-for="reference in visibleReferenceCards" :key="reference.key" class="panel reference-card">
          <div class="panel-heading">
            <div>
              <p class="eyebrow">Source existante</p>
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
          <p v-else-if="reference.key === 'contacts'">{{ configuration.definitions.contacts }}</p>
          <p v-else>Aucune donnée configurée.</p>
          <a
            v-if="reference.legacy_path"
            class="button secondary compact"
            :href="legacyUrl(reference.legacy_path)"
          >
            Ouvrir la gestion
          </a>
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
