<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import CompactTabs from '@/components/ui/CompactTabs.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import SkeletonBlock from '@/components/ui/SkeletonBlock.vue';
import { runtimeConfig } from '@/config';
import { subNavigation } from '@/router/navigation';
import { useContextStore } from '@/stores/context';
import { usePedagogyStore } from '@/stores/pedagogy';
import type { PedagogyAssignment, PedagogyCatalogItem } from '@/api/contracts';

const route = useRoute();
const context = useContextStore();
const pedagogy = usePedagogyStore();
const entryByStep = reactive<Record<number, number>>({});
const tab = computed(() => String(route.params.tab || 'catalogue'));
const allowed = computed(() =>
  context.moduleEnabled('apprentissage') && context.can('pedagogie.view')
);
const workspace = computed(() => pedagogy.workspace);
const groupedCatalog = computed(() => {
  const groups: Record<string, PedagogyCatalogItem[]> = {};
  Object.keys(workspace.value?.competences || {}).forEach((code) => {
    groups[code] = [];
  });
  (workspace.value?.catalog || []).forEach((model) => {
    (groups[model.competence] ||= []).push(model);
  });
  return groups;
});
const model = reactive({
  title: '',
  description: '',
  competence: 'debit_credit',
  level: 'debutant',
  duration_minutes: 30,
  instructions: '',
  step_title: '',
  step_instruction: '',
  points: 100,
  rule: 'ecriture_equivalente',
  configuration: '{"lignes":[]}',
  success: 'La réponse comptable est correcte.',
  failure: 'La réponse comptable doit être revue.',
  hints: 'Identifiez les comptes.\nContrôlez l’équilibre débit/crédit.',
  solution: ''
});
const group = reactive({ name: '', group_id: 0, user_id: 0 });
const assignment = reactive({
  version_id: 0,
  target_type: 'user',
  target_id: 0,
  name: ''
});
const localError = ref('');

function asNumber(row: Record<string, unknown>, field: string): number {
  return Number(row[field] ?? 0);
}
function asString(row: Record<string, unknown>, field: string): string {
  return String(row[field] ?? '');
}
function score(obtained: number, total: number): string {
  return total > 0 ? `${Math.round((obtained / total) * 100)} %` : '—';
}
function levelLabel(level: string): string {
  return {
    debutant: 'Débutant',
    intermediaire: 'Intermédiaire',
    avance: 'Avancé'
  }[level] || level;
}
function syncDefaults(): void {
  const data = workspace.value;
  if (!data) return;
  if (!assignment.version_id) {
    assignment.version_id = Number(data.catalog.find((row) => row.version_id)?.version_id || 0);
  }
  if (!assignment.target_id) assignment.target_id = Number(data.learners[0]?.id || 0);
  if (!group.group_id) group.group_id = asNumber(data.groups[0] || {}, 'id');
  if (!group.user_id) group.user_id = Number(data.learners[0]?.id || 0);
}
async function reload(): Promise<void> {
  pedagogy.clearFeedback();
  await pedagogy.load();
  syncDefaults();
}
async function openAssignment(item: PedagogyAssignment): Promise<void> {
  const dossier = context.dossiers.find((row) => row.id === Number(item.dossier_id));
  if (!dossier) {
    pedagogy.error = 'La copie de cet exercice n’est pas accessible dans votre contexte.';
    return;
  }
  await context.selectDossier(dossier);
  await reload();
}
async function installCatalog(): Promise<void> {
  await pedagogy.mutate(
    '/pedagogie/catalogue/installer',
    {},
    'Le catalogue ciblé est installé.'
  );
  syncDefaults();
}
async function submitAttempt(stepId: number): Promise<void> {
  const result = await pedagogy.mutate(
    '/pedagogie/tentatives',
    {
      step_id: stepId,
      entry_id: entryByStep[stepId] || null
    },
    'La tentative a été analysée.'
  );
  if (result) {
    const messages = Array.isArray(result.messages)
      ? result.messages.map(String).join(' ')
      : '';
    pedagogy.notice = `${result.reussie ? 'Étape validée.' : 'À reprendre.'} ${messages}`;
  }
}
async function createModel(): Promise<void> {
  localError.value = '';
  try {
    const configuration = JSON.parse(model.configuration);
    const result = await pedagogy.mutate(
      '/pedagogie/modeles',
      {
        title: model.title,
        description: model.description,
        competence: model.competence,
        level: model.level,
        duration_minutes: Number(model.duration_minutes),
        instructions: model.instructions,
        steps: [{
          code: 'E1',
          titre: model.step_title,
          consigne: model.step_instruction,
          points: Number(model.points),
          indices: model.hints.split('\n').map((hint) => hint.trim()).filter(Boolean),
          regles: [{
            type: model.rule,
            configuration,
            message_succes: model.success,
            message_echec: model.failure
          }]
        }],
        opening: [],
        initial: [],
        solution: { explication: model.solution },
        correction_rule: 'apres_tentatives',
        correction_value: '2'
      },
      'Le scénario versionné est publié.'
    );
    if (result) {
      model.title = '';
      model.description = '';
      model.instructions = '';
      model.step_title = '';
      model.step_instruction = '';
      model.solution = '';
    }
  } catch {
    localError.value = 'La configuration de la règle doit être un objet JSON valide.';
  }
}
async function createGroup(): Promise<void> {
  if (await pedagogy.mutate(
    '/pedagogie/groupes',
    { name: group.name },
    'Le groupe est créé.'
  )) {
    group.name = '';
    syncDefaults();
  }
}
async function addMember(): Promise<void> {
  await pedagogy.mutate(
    '/pedagogie/groupes/membres',
    { group_id: Number(group.group_id), user_id: Number(group.user_id) },
    'L’apprenant est ajouté au groupe.'
  );
}
async function assignExercise(): Promise<void> {
  if (await pedagogy.mutate(
    '/pedagogie/assignations',
    {
      version_id: Number(assignment.version_id),
      target_type: assignment.target_type,
      target_id: Number(assignment.target_id),
      name: assignment.name
    },
    'Une copie isolée a été créée et assignée.'
  )) {
    assignment.name = '';
  }
}
async function authorizeCorrection(id: number): Promise<void> {
  await pedagogy.mutate(
    '/pedagogie/correction/autoriser',
    { assignment_id: id },
    'La correction est désormais autorisée.'
  );
}
async function resetAssignment(id: number): Promise<void> {
  if (!window.confirm('Réinitialiser cette copie et perdre son travail en cours ?')) return;
  const result = await pedagogy.mutate(
    '/pedagogie/reinitialiser',
    { assignment_id: id },
    'Une nouvelle copie vierge a remplacé la précédente.',
    false
  );
  if (result) {
    await context.load();
    await reload();
  }
}
function assignmentTargets(): Array<{ id: number; label: string }> {
  if (assignment.target_type === 'group') {
    return (workspace.value?.groups || []).map((row) => ({
      id: asNumber(row, 'id'),
      label: asString(row, 'nom')
    }));
  }
  return (workspace.value?.learners || []).map((row) => ({
    id: row.id,
    label: `${row.prenom} ${row.nom} · ${row.email}`.trim()
  }));
}
function updateTarget(): void {
  assignment.target_id = assignmentTargets()[0]?.id || 0;
}

watch(() => context.selection?.dossier.id, () => {
  if (allowed.value) void reload();
});
onMounted(() => {
  if (allowed.value) void reload();
});
</script>

<template>
  <header class="page-header">
    <div>
      <p class="eyebrow">Apprendre avec le vrai moteur comptable</p>
      <h1>Apprentissage</h1>
      <p>Scénarios ciblés, copies isolées, retours explicatifs et suivi des acquis.</p>
    </div>
  </header>

  <CompactTabs
    v-if="allowed"
    :items="subNavigation.learning"
    label="Navigation Apprentissage"
  />

  <section v-if="!context.selection" class="access-message" role="status">
    <strong>Contexte requis</strong>
    <p>Sélectionnez un dossier pédagogique autorisé.</p>
  </section>
  <section v-else-if="!allowed" class="access-message denied" role="alert">
    <strong>{{ context.moduleEnabled('apprentissage') ? 'Accès refusé' : 'Module désactivé' }}</strong>
    <p>L’espace d’apprentissage n’est pas disponible pour ce dossier.</p>
  </section>
  <template v-else>
    <ErrorSummary :message="localError || pedagogy.error" />
    <p v-if="pedagogy.notice" class="notice success" role="status">{{ pedagogy.notice }}</p>
    <SkeletonBlock v-if="pedagogy.loading && !workspace" :lines="6" />
    <EmptyState
      v-else-if="workspace && !workspace.available"
      title="Aucune donnée réelle n’est exposée"
      description="Les exercices ne sont disponibles que dans une organisation pédagogique et dans une copie de démonstration ou d’exercice."
    />

    <template v-else-if="workspace">
      <section v-if="tab === 'catalogue'" class="workspace-grid">
        <div class="section-heading">
          <div>
            <h2>Catalogue par compétence</h2>
            <p>Sept parcours couvrent les gestes essentiels, avec un barème explicite.</p>
          </div>
          <button
            v-if="workspace.capabilities.manage"
            class="button"
            type="button"
            :disabled="pedagogy.saving"
            @click="installCatalog"
          >
            Installer les parcours ciblés
          </button>
        </div>
        <div class="learning-catalog">
          <article
            v-for="(items, code) in groupedCatalog"
            :key="code"
            class="panel learning-competence"
          >
            <p class="eyebrow">{{ workspace.competences[code] }}</p>
            <template v-if="items.length">
              <div v-for="item in items" :key="item.id" class="learning-model">
                <div>
                  <h3>{{ item.titre }}</h3>
                  <p>{{ item.description }}</p>
                </div>
                <ul>
                  <li>{{ levelLabel(item.niveau) }}</li>
                  <li>{{ item.duree_minutes }} min</li>
                  <li>{{ item.nombre_etapes }} étape(s)</li>
                  <li>{{ item.points_total }} points</li>
                  <li>Version {{ item.numero_version }}</li>
                </ul>
              </div>
            </template>
            <p v-else class="muted">Aucun scénario publié dans cette compétence.</p>
          </article>
        </div>

        <details v-if="workspace.capabilities.manage" class="panel">
          <summary>Créer un scénario versionné</summary>
          <form class="stack learning-form" @submit.prevent="createModel">
            <div class="form-grid three">
              <label class="form-field">Titre
                <input v-model="model.title" required>
              </label>
              <label class="form-field">Compétence
                <select v-model="model.competence">
                  <option v-for="(label, code) in workspace.competences" :key="code" :value="code">
                    {{ label }}
                  </option>
                </select>
              </label>
              <label class="form-field">Niveau
                <select v-model="model.level">
                  <option value="debutant">Débutant</option>
                  <option value="intermediaire">Intermédiaire</option>
                  <option value="avance">Avancé</option>
                </select>
              </label>
              <label class="form-field">Durée estimée
                <input v-model.number="model.duration_minutes" type="number" min="5" max="480">
              </label>
              <label class="form-field">Titre de l’étape
                <input v-model="model.step_title" required>
              </label>
              <label class="form-field">Points
                <input v-model.number="model.points" type="number" min="1" required>
              </label>
            </div>
            <label class="form-field">Description
              <textarea v-model="model.description" rows="2"></textarea>
            </label>
            <label class="form-field">Consignes générales
              <textarea v-model="model.instructions" rows="3" required></textarea>
            </label>
            <label class="form-field">Consigne de l’étape
              <textarea v-model="model.step_instruction" rows="3" required></textarea>
            </label>
            <div class="form-grid three">
              <label class="form-field">Validation
                <select v-model="model.rule">
                  <option value="ecriture_equivalente">Écriture équivalente</option>
                  <option value="comptes">Comptes utilisés</option>
                  <option value="sens">Sens débit/crédit</option>
                  <option value="montants">Montants</option>
                  <option value="soldes">Soldes</option>
                  <option value="rapport">Rapport</option>
                </select>
              </label>
              <label class="form-field">Message de réussite
                <input v-model="model.success" required>
              </label>
              <label class="form-field">Message pédagogique d’erreur
                <input v-model="model.failure" required>
              </label>
            </div>
            <label class="form-field">Configuration de validation (JSON)
              <textarea v-model="model.configuration" rows="4" spellcheck="false"></textarea>
            </label>
            <label class="form-field">Indices, un par ligne
              <textarea v-model="model.hints" rows="3"></textarea>
            </label>
            <label class="form-field">Correction protégée
              <textarea v-model="model.solution" rows="3" required></textarea>
              <small class="field-hint">Invisible avant deux tentatives ou l’autorisation du formateur.</small>
            </label>
            <button class="button" type="submit" :disabled="pedagogy.saving">Publier la version</button>
          </form>
        </details>
      </section>

      <section v-else-if="tab === 'exercices'" class="workspace-grid">
        <div class="panel">
          <div class="panel-heading">
            <div>
              <h2>Mes exercices</h2>
              <p>Chaque exercice utilise sa propre copie comptable, séparée du réel et des autres élèves.</p>
            </div>
          </div>
          <EmptyState
            v-if="workspace.assignments.length === 0"
            title="Aucun exercice assigné"
            description="Un formateur doit assigner une version publiée à votre compte ou à votre groupe."
          />
          <div v-else class="learning-assignment-list">
            <article v-for="item in workspace.assignments" :key="item.id" class="action-card">
              <span>{{ workspace.competences[item.competence] }} · {{ levelLabel(item.niveau) }}</span>
              <h3>{{ item.modele_titre }}</h3>
              <small>{{ item.dossier_nom }} · génération {{ item.generation }} · {{ item.duree_minutes }} min</small>
              <strong>{{ score(item.points_obtenus, item.points_total) }}</strong>
              <button
                class="button secondary"
                type="button"
                @click="openAssignment(item)"
              >
                {{ Number(context.selection?.dossier.id) === Number(item.dossier_id) ? 'Copie ouverte' : 'Ouvrir la copie' }}
              </button>
            </article>
          </div>
        </div>

        <article v-if="workspace.selected" class="panel">
          <div class="panel-heading">
            <div>
              <p class="eyebrow">{{ workspace.competences[workspace.selected.competence] }}</p>
              <h2>{{ workspace.selected.modele_titre }}</h2>
              <p>{{ workspace.selected.consignes }}</p>
            </div>
            <a class="button secondary" :href="`${runtimeConfig.appBaseUrl}/compta`">
              Ouvrir la journalisation
            </a>
          </div>
          <div class="metric-strip">
            <span><small>Progression</small><strong>{{ workspace.selected.etapes_validees }}/{{ workspace.selected.nombre_etapes }}</strong></span>
            <span><small>Score</small><strong>{{ workspace.selected.points_obtenus }}/{{ workspace.selected.points_total }}</strong></span>
            <span><small>Copie</small><strong>Gén. {{ workspace.selected.generation }}</strong></span>
          </div>
          <div class="learning-steps">
            <fieldset v-for="step in workspace.selected.steps" :key="step.id" class="payroll-step">
              <legend>{{ step.code }} · {{ step.titre }} · {{ step.points }} points</legend>
              <p>{{ step.consigne }}</p>
              <p>
                <span class="status-badge" :class="step.statut === 'validee' ? 'status-ouvert' : 'status-ferme'">
                  {{ step.statut === 'validee' ? 'Validée' : 'À faire' }}
                </span>
                {{ step.tentatives }} tentative(s) · {{ step.indices_consultes }}/{{ step.nombre_indices }} indice(s)
              </p>
              <ul v-if="step.messages.length" class="learning-feedback">
                <li v-for="message in step.messages" :key="message">{{ message }}</li>
              </ul>
              <label class="form-field">Écriture à vérifier
                <select v-model.number="entryByStep[step.id]">
                  <option :value="0">Aucune écriture — tester le retour pédagogique</option>
                  <option v-for="entry in workspace.selected.entries" :key="entry.id" :value="entry.id">
                    {{ entry.numero || `#${entry.id}` }} · {{ entry.date_comptable }} · {{ entry.libelle }} ({{ entry.statut }})
                  </option>
                </select>
              </label>
              <div class="button-row">
                <button class="button" type="button" :disabled="pedagogy.saving" @click="submitAttempt(step.id)">
                  Vérifier ma réponse
                </button>
                <button
                  v-if="step.indices_consultes < step.nombre_indices"
                  class="button secondary"
                  type="button"
                  :disabled="pedagogy.saving"
                  @click="pedagogy.requestHint(step.id)"
                >
                  Demander l’indice suivant
                </button>
              </div>
            </fieldset>
          </div>
          <p v-if="pedagogy.hint" class="notice warning" role="status">{{ pedagogy.hint }}</p>
          <div class="button-row">
            <button
              v-if="workspace.selected.correction_available"
              class="button secondary"
              type="button"
              @click="pedagogy.requestCorrection"
            >
              Afficher la correction
            </button>
            <button
              v-if="workspace.capabilities.reset"
              class="button danger"
              type="button"
              @click="resetAssignment(workspace.selected.id)"
            >
              Repartir d’une copie vierge
            </button>
          </div>
          <section v-if="pedagogy.correction" class="learning-solution" role="status">
            <h3>Correction autorisée</h3>
            <p>{{ String(pedagogy.correction.explication || '') }}</p>
          </section>
        </article>
      </section>

      <section v-else-if="tab === 'suivi'" class="workspace-grid">
        <EmptyState
          v-if="!workspace.capabilities.manage"
          title="Suivi réservé aux formateurs"
          description="Votre rôle permet de travailler dans les exercices, mais pas de gérer les groupes et assignations."
        />
        <template v-else>
          <div class="section-heading">
            <div>
              <h2>Tableau de suivi</h2>
              <p>Progression, barème, tentatives et attribution des contributions.</p>
            </div>
            <a
              v-if="workspace.capabilities.export"
              class="button secondary"
              :href="`${runtimeConfig.apiBaseUrl}/pedagogie/export`"
            >
              Exporter en CSV
            </a>
          </div>
          <div class="panel table-scroll">
            <table class="data-table">
              <thead><tr>
                <th>Compétence</th><th>Exercice</th><th>Cible</th><th>Score</th>
                <th>Tentatives</th><th>Contributeurs</th><th>Actions</th>
              </tr></thead>
              <tbody>
                <tr v-for="row in workspace.tracking" :key="asNumber(row, 'id')">
                  <td>{{ workspace.competences[asString(row, 'competence')] }}</td>
                  <td>{{ asString(row, 'titre') }}<small>{{ asString(row, 'dossier_nom') }}</small></td>
                  <td>{{ asString(row, 'groupe_nom') || asString(row, 'apprenant') }}</td>
                  <td>{{ asNumber(row, 'points_obtenus') }}/{{ asNumber(row, 'points_total') }}</td>
                  <td>{{ asNumber(row, 'tentatives') }}</td>
                  <td>{{ asNumber(row, 'contributeurs') }}</td>
                  <td>
                    <div class="button-row">
                      <button
                        v-if="workspace.capabilities.correct"
                        class="button small secondary"
                        type="button"
                        @click="authorizeCorrection(asNumber(row, 'id'))"
                      >Autoriser la correction</button>
                      <button
                        v-if="workspace.capabilities.reset"
                        class="button small danger"
                        type="button"
                        @click="resetAssignment(asNumber(row, 'id'))"
                      >Réinitialiser</button>
                    </div>
                  </td>
                </tr>
                <tr v-if="workspace.tracking.length === 0">
                  <td colspan="7">Aucune assignation.</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="dashboard-two-columns">
            <form class="panel stack" @submit.prevent="createGroup">
              <h2>Groupes</h2>
              <label class="form-field">Nouveau groupe
                <input v-model="group.name" required>
              </label>
              <button class="button" type="submit">Créer</button>
              <label v-if="workspace.groups.length" class="form-field">Groupe
                <select v-model.number="group.group_id">
                  <option v-for="row in workspace.groups" :key="asNumber(row, 'id')" :value="asNumber(row, 'id')">
                    {{ asString(row, 'nom') }} · {{ asNumber(row, 'membres') }} membre(s)
                  </option>
                </select>
              </label>
              <label v-if="workspace.learners.length" class="form-field">Apprenant
                <select v-model.number="group.user_id">
                  <option v-for="row in workspace.learners" :key="row.id" :value="row.id">
                    {{ row.prenom }} {{ row.nom }} · {{ row.email }}
                  </option>
                </select>
              </label>
              <button
                v-if="workspace.groups.length && workspace.learners.length"
                class="button secondary"
                type="button"
                @click="addMember"
              >Ajouter au groupe</button>
            </form>

            <form class="panel stack" @submit.prevent="assignExercise">
              <h2>Assigner une copie isolée</h2>
              <label class="form-field">Scénario publié
                <select v-model.number="assignment.version_id" required>
                  <option v-for="row in workspace.catalog" :key="row.id" :value="Number(row.version_id)">
                    {{ workspace.competences[row.competence] }} · {{ row.titre }} · v{{ row.numero_version }}
                  </option>
                </select>
              </label>
              <label class="form-field">Type de cible
                <select v-model="assignment.target_type" @change="updateTarget">
                  <option value="user">Apprenant</option>
                  <option value="group">Groupe</option>
                </select>
              </label>
              <label class="form-field">Cible
                <select v-model.number="assignment.target_id" required>
                  <option v-for="target in assignmentTargets()" :key="target.id" :value="target.id">
                    {{ target.label }}
                  </option>
                </select>
              </label>
              <label class="form-field">Nom de la copie
                <input v-model="assignment.name" placeholder="Atelier TVA — groupe A" required>
              </label>
              <button class="button" type="submit" :disabled="assignmentTargets().length === 0">
                Créer et assigner
              </button>
            </form>
          </div>
        </template>
      </section>
    </template>
  </template>
</template>
