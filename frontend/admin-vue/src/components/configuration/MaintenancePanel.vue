<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { api, errorMessage } from '@/api/client';
import type {
  MaintenanceApplyResult,
  MaintenanceReleaseStatus
} from '@/api/contracts';
import { useNotificationStore } from '@/stores/notifications';

const notifications = useNotificationStore();
const release = ref<MaintenanceReleaseStatus | null>(null);
const loading = ref(false);
const applying = ref(false);
const error = ref('');
const canApply = computed(() => Boolean(
  release.value?.available
  && release.value.writable
  && release.value.latest
  && release.value.release_fingerprint
));

function checkedAt(timestamp: number): string {
  if (!timestamp) return '—';
  return new Intl.DateTimeFormat('fr-CH', {
    dateStyle: 'short',
    timeStyle: 'short'
  }).format(new Date(timestamp * 1000));
}

async function check(refresh = false): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    release.value = (await api.get<MaintenanceReleaseStatus>(
      '/maintenance/release',
      refresh ? { refresh: 1 } : {}
    )).data;
  } catch (caught) {
    error.value = errorMessage(caught);
  } finally {
    loading.value = false;
  }
}

async function applyRelease(): Promise<void> {
  const current = release.value;
  if (!current?.latest || !current.release_fingerprint || !canApply.value) return;
  if (!window.confirm(
    `Installer la version ${current.latest} depuis GitHub ? `
    + 'Une sauvegarde de la base et du code sera créée avant la migration.'
  )) return;

  applying.value = true;
  error.value = '';
  try {
    const result = (await api.post<MaintenanceApplyResult>(
      '/maintenance/release/apply',
      {
        expected_version: current.latest,
        release_fingerprint: current.release_fingerprint
      }
    )).data;
    notifications.push(
      `Version ${result.version} installée. Rechargement de l’application…`,
      'success'
    );
    window.setTimeout(() => window.location.reload(), 500);
  } catch (caught) {
    error.value = errorMessage(caught);
    await check(true);
  } finally {
    applying.value = false;
  }
}

onMounted(() => check(false));
</script>

<template>
  <section class="panel maintenance-panel" aria-labelledby="maintenance-title">
    <div class="maintenance-heading">
      <div>
        <p class="eyebrow">Maintenance de l’installation</p>
        <h2 id="maintenance-title">Versions et mise à jour Git</h2>
      </div>
      <span
        class="status-badge"
        :class="release?.available ? 'status-attention' : release?.current ? 'status-ouverte' : 'status-fermee'"
      >
        {{ release?.available ? 'Mise à jour disponible' : release?.current ? 'À jour' : 'État inconnu' }}
      </span>
    </div>

    <p v-if="error || release?.error" class="maintenance-error" role="alert">
      {{ error || `Vérification Git impossible : ${release?.error}` }}
    </p>

    <dl class="version-grid">
      <div><dt>Version installée</dt><dd>{{ release?.installed || '—' }}</dd></div>
      <div><dt>Dernière version Git</dt><dd>{{ release?.latest || '—' }}</dd></div>
      <div><dt>Dernière vérification</dt><dd>{{ checkedAt(release?.checked_at || 0) }}</dd></div>
      <div><dt>Branche stable</dt><dd>{{ release?.branch || 'main' }}</dd></div>
    </dl>

    <p v-if="release?.available" class="maintenance-copy">
      L’installation téléchargera la publication depuis GitHub, vérifiera chaque
      empreinte, sauvegardera la base et le code, puis appliquera les migrations.
    </p>
    <p v-else class="maintenance-copy">
      La synchronisation est déclenchée uniquement à votre demande. Les données,
      secrets locaux et fichiers de stockage ne sont jamais remplacés.
    </p>
    <p v-if="release && !release.writable" class="maintenance-error" role="alert">
      Le serveur Web ne peut pas écrire dans le runtime ou le stockage. La mise à
      jour automatique est désactivée jusqu’à correction des permissions.
    </p>

    <div class="maintenance-actions">
      <button
        type="button"
        class="button secondary"
        :disabled="loading || applying"
        @click="check(true)"
      >
        {{ loading ? 'Vérification…' : 'Vérifier maintenant' }}
      </button>
      <button
        v-if="release?.available"
        type="button"
        class="button"
        :disabled="!canApply || loading || applying"
        @click="applyRelease"
      >
        {{ applying ? 'Installation en cours…' : `Installer ${release.latest}` }}
      </button>
      <a
        class="source-link"
        href="https://github.com/antoinemelo/webeLi-compta"
        target="_blank"
        rel="noopener noreferrer"
      >Dépôt source GitHub</a>
    </div>
    <small>Cette fonction est réservée aux administrateurs de l’installation.</small>
  </section>
</template>

<style scoped>
.maintenance-panel { display: grid; gap: 1rem; }
.maintenance-heading, .maintenance-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  align-items: center;
  justify-content: space-between;
}
.maintenance-heading h2, .maintenance-heading p, .maintenance-copy { margin: 0; }
.version-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: .75rem;
  margin: 0;
}
.version-grid > div {
  display: grid;
  gap: .25rem;
  padding: .8rem;
  background: #f8f8fb;
  border: 1px solid var(--border);
  border-radius: .55rem;
}
.version-grid dt { color: var(--muted); font-size: .76rem; font-weight: 750; }
.version-grid dd { margin: 0; font-weight: 800; overflow-wrap: anywhere; }
.maintenance-copy, .maintenance-panel small { color: var(--muted); }
.maintenance-error {
  margin: 0;
  padding: .75rem;
  color: #76251d;
  background: #fff1ef;
  border-left: .25rem solid #b64034;
}
.maintenance-actions { justify-content: flex-start; }
.source-link { margin-left: auto; color: var(--accent); font-weight: 750; }
.status-attention { color: #714900; background: #fff2ce; }
@media (max-width: 820px) {
  .version-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .source-link { width: 100%; margin-left: 0; }
}
@media (max-width: 520px) {
  .version-grid { grid-template-columns: 1fr; }
}
</style>
