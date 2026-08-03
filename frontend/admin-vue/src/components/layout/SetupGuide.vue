<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, errorMessage } from '@/api/client';
import type { SetupGuide, SetupGuideStep } from '@/api/contracts';
import { useContextStore } from '@/stores/context';
import { useNotificationStore } from '@/stores/notifications';

const context = useContextStore();
const notifications = useNotificationStore();
const route = useRoute();
const router = useRouter();
const guide = ref<SetupGuide | null>(null);
const currentCode = ref<SetupGuideStep['code'] | ''>('');
const collapsed = ref(false);
const loading = ref(false);
let reloadRequested = false;
let preserveExpandedOnNextRoute = false;

const eligible = computed(() => (
  context.selection?.organization.nature === 'reelle'
  && context.selection?.dossier.type === 'reel'
  && context.can('dossier.manage')
));
const steps = computed(() => (
  guide.value?.steps.filter((step) => step.applicable) ?? []
));
const currentIndex = computed(() => {
  const index = steps.value.findIndex((step) => step.code === currentCode.value);
  return index >= 0 ? index : 0;
});
const current = computed(() => steps.value[currentIndex.value] ?? null);
const firstRequiredIncomplete = computed(() => (
  steps.value.find((step) => step.required && !step.completed) ?? null
));
const visible = computed(() => (
  eligible.value
  && Boolean(guide.value?.visible)
  && steps.value.length > 0
));
const progressWidth = computed(() => {
  if (!steps.value.length) return '0%';
  return `${Math.round(((currentIndex.value + 1) / steps.value.length) * 100)}%`;
});

watch(
  guide,
  (value) => {
    window.dispatchEvent(new CustomEvent('compta:setup-guide-state', {
      detail: {
        cancelled: Boolean(value?.cancelled),
        finished: Boolean(value?.finished)
      }
    }));
  }
);

onMounted(() => {
  window.addEventListener('compta:configuration-changed', refreshAfterMutation);
  window.addEventListener('compta:setup-guide-resume', resumeGuide);
});
onBeforeUnmount(() => {
  window.removeEventListener('compta:configuration-changed', refreshAfterMutation);
  window.removeEventListener('compta:setup-guide-resume', resumeGuide);
});

watch(
  [
    () => context.selection?.dossier.id ?? 0,
    () => eligible.value
  ],
  async ([dossierId, canUseGuide]) => {
    guide.value = null;
    currentCode.value = '';
    collapsed.value = route.path !== '/';
    if (dossierId > 0 && canUseGuide) await load();
  },
  { immediate: true }
);

watch(
  () => route.fullPath,
  async () => {
    if (route.path !== '/' && !preserveExpandedOnNextRoute) {
      collapsed.value = true;
    }
    preserveExpandedOnNextRoute = false;
    if (eligible.value && guide.value) await load(true);
  }
);

async function load(keepCurrent = false): Promise<void> {
  if (!eligible.value) return;
  if (loading.value) {
    reloadRequested = true;
    return;
  }
  loading.value = true;
  const previous = keepCurrent ? currentCode.value : '';
  try {
    do {
      reloadRequested = false;
      guide.value = (await api.get<SetupGuide>(
        '/configuration/setup-guide'
      )).data;
      const applicable = guide.value.steps.filter((step) => step.applicable);
      const retained = applicable.find((step) => step.code === previous);
      currentCode.value = retained?.code
        ?? applicable.find((step) => !step.completed)?.code
        ?? applicable.at(-1)?.code
        ?? '';
    } while (reloadRequested && eligible.value);
  } catch (error) {
    guide.value = null;
    notifications.push(errorMessage(error), 'error');
  } finally {
    loading.value = false;
  }
}

function refreshAfterMutation(): void {
  void load(true);
}

async function cancelGuide(): Promise<void> {
  if (loading.value) return;
  loading.value = true;
  try {
    guide.value = (await api.post<SetupGuide>(
      '/configuration/setup-guide/status',
      { action: 'cancel' }
    )).data;
    collapsed.value = true;
    notifications.push(
      'Configuration initiale annulée. Vous pourrez la reprendre depuis le menu du contexte de travail.',
      'success'
    );
  } catch (error) {
    notifications.push(errorMessage(error), 'error');
  } finally {
    loading.value = false;
  }
}

async function resumeGuide(): Promise<void> {
  if (!eligible.value || loading.value) return;
  loading.value = true;
  try {
    guide.value = (await api.post<SetupGuide>(
      '/configuration/setup-guide/status',
      { action: 'resume' }
    )).data;
    const applicable = guide.value.steps.filter((step) => step.applicable);
    currentCode.value = applicable.find((step) => !step.completed)?.code
      ?? applicable.at(-1)?.code
      ?? '';
    collapsed.value = false;
    notifications.push(
      guide.value.finished
        ? 'La configuration initiale est déjà terminée.'
        : 'Configuration initiale reprise.',
      'success'
    );
  } catch (error) {
    notifications.push(errorMessage(error), 'error');
  } finally {
    loading.value = false;
  }
}

async function openStep(
  step: SetupGuideStep,
  collapseAfterOpening = true
): Promise<void> {
  collapsed.value = collapseAfterOpening;
  preserveExpandedOnNextRoute = !collapseAfterOpening;
  if (step.code === 'identity') {
    await router.push({
      path: '/organisations-dossiers',
      query: {
        organisation: String(context.selection?.organization.id ?? ''),
        section: 'information'
      }
    });
    return;
  }
  await router.push(step.path);
}

async function move(offset: -1 | 1): Promise<void> {
  await load(true);
  const target = steps.value[currentIndex.value + offset];
  if (!target) return;
  currentCode.value = target.code;
  await openStep(target, false);
}

async function returnToRequiredStep(): Promise<void> {
  if (!firstRequiredIncomplete.value) return;
  currentCode.value = firstRequiredIncomplete.value.code;
  await openStep(firstRequiredIncomplete.value);
}

async function confirmCurrent(): Promise<void> {
  if (!current.value?.confirmable || loading.value) return;
  loading.value = true;
  try {
    guide.value = (await api.post<SetupGuide>(
      '/configuration/setup-guide/confirm',
      { step: current.value.code }
    )).data;
    notifications.push(
      current.value.code === 'accounting'
        ? 'Configuration initiale terminée.'
        : `Étape « ${current.value.title} » validée.`,
      'success'
    );
    if (current.value.code === 'accounting') {
      collapsed.value = true;
      await router.push('/compta');
    }
  } catch (error) {
    notifications.push(errorMessage(error), 'error');
  } finally {
    loading.value = false;
  }
}

function confirmationLabel(step: SetupGuideStep): string {
  if (step.code === 'accounting') {
    return 'Terminer et ouvrir la comptabilité';
  }
  if (step.code === 'opening') return 'Confirmer l’ouverture à zéro';
  return 'Valider cette étape';
}
</script>

<template>
  <aside
    v-if="visible"
    class="setup-guide-container"
    aria-label="Parcours de configuration initiale"
  >
    <button
      v-if="collapsed"
      type="button"
      class="btn btn-primary shadow setup-guide-reopen"
      @click="collapsed = false"
    >
      Configuration initiale
      <span class="badge text-bg-light ms-1">
        {{ currentIndex + 1 }}/{{ steps.length }}
      </span>
    </button>

    <section v-else-if="current" class="card border-0 shadow-lg setup-guide-card">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-3">
          <div>
            <p class="text-uppercase small fw-semibold text-primary mb-1">
              Configuration initiale
            </p>
            <p class="small text-body-secondary mb-0">
              Étape {{ currentIndex + 1 }} sur {{ steps.length }}
            </p>
          </div>
          <div class="d-flex align-items-center gap-2">
            <button
              type="button"
              class="btn btn-sm btn-link text-secondary p-0"
              aria-label="Annuler le parcours pour l’instant"
              title="Annuler le parcours"
              :disabled="loading"
              @click="cancelGuide"
            >
              Annuler
            </button>
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center justify-content-center p-1"
              aria-label="Réduire la configuration initiale"
              title="Réduire"
              :disabled="loading"
              @click="collapsed = true"
            >
              <svg
                width="16"
                height="16"
                viewBox="0 0 16 16"
                fill="currentColor"
                aria-hidden="true"
                focusable="false"
              >
                <path d="M3 7.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5Z" />
              </svg>
            </button>
          </div>
        </div>

        <div
          class="progress my-3"
          role="progressbar"
          :aria-valuenow="currentIndex + 1"
          aria-valuemin="1"
          :aria-valuemax="steps.length"
          aria-label="Progression de la configuration"
        >
          <div class="progress-bar" :style="{ width: progressWidth }" />
        </div>

        <div class="d-flex align-items-center gap-2 mb-2">
          <span
            class="badge"
            :class="current.required ? 'text-bg-primary' : 'text-bg-secondary'"
          >
            {{ current.required ? 'Obligatoire' : 'Facultatif' }}
          </span>
          <span v-if="current.completed" class="badge text-bg-success">Terminé</span>
          <span v-else-if="current.ready" class="badge text-bg-info">Prêt à valider</span>
        </div>
        <h2 class="h5 mb-2">{{ current.title }}</h2>
        <p class="small text-body-secondary">{{ current.description }}</p>

        <div class="d-grid gap-2">
          <button
            v-if="current.code !== 'accounting'"
            type="button"
            class="btn btn-outline-primary"
            :disabled="loading"
            @click="openStep(current)"
          >
            {{ current.action_label }}
          </button>
          <button
            v-if="current.confirmable"
            type="button"
            class="btn btn-primary"
            :disabled="loading"
            @click="confirmCurrent"
          >
            {{ confirmationLabel(current) }}
          </button>
          <button
            v-else-if="current.code === 'accounting' && firstRequiredIncomplete"
            type="button"
            class="btn btn-outline-primary"
            :disabled="loading"
            @click="returnToRequiredStep"
          >
            Reprendre l’étape obligatoire « {{ firstRequiredIncomplete.title }} »
          </button>
        </div>
      </div>

      <div class="card-footer bg-body-tertiary d-flex justify-content-between gap-2">
        <button
          type="button"
          class="btn btn-sm btn-outline-secondary"
          :disabled="currentIndex === 0 || loading"
          @click="move(-1)"
        >
          Précédent
        </button>
        <button
          type="button"
          class="btn btn-sm btn-outline-primary"
          :disabled="currentIndex >= steps.length - 1 || loading"
          @click="move(1)"
        >
          Suivant
        </button>
      </div>
    </section>
  </aside>
</template>
