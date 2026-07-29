<script setup lang="ts">
import { computed, ref } from 'vue';
import { api, errorMessage } from '@/api/client';
import { runtimeConfig } from '@/config';
import { useNotificationStore } from '@/stores/notifications';
import ModalDialog from '@/components/ui/ModalDialog.vue';

type SecurityProfile = {
  email: string;
  mode: 'password' | 'email' | 'totp';
  mfa_active_at: string | null;
  recovery_codes_remaining: number;
  totp_available: boolean;
  email_available: boolean;
};

type TotpPreparation = {
  secret: string;
  otpauth_uri: string;
  qr_data_uri: string;
};

const dialog = ref<InstanceType<typeof ModalDialog> | null>(null);
const profile = ref<SecurityProfile | null>(null);
const loading = ref(false);
const error = ref('');
const action = ref<'home' | 'totp' | 'email' | 'disable' | 'password'>('home');
const password = ref('');
const newPassword = ref('');
const passwordConfirmation = ref('');
const code = ref('');
const totp = ref<TotpPreparation | null>(null);
const emailPrepared = ref(false);
const recoveryCodes = ref<string[]>([]);
const notifications = useNotificationStore();

const modeLabel = computed(() => ({
  password: 'Mot de passe uniquement',
  email: 'Mot de passe + code par e-mail',
  totp: 'Mot de passe + application TOTP'
})[profile.value?.mode || 'password']);

async function open(): Promise<void> {
  reset();
  dialog.value?.open();
  await load();
}

function reset(): void {
  profile.value = null;
  error.value = '';
  action.value = 'home';
  password.value = '';
  newPassword.value = '';
  passwordConfirmation.value = '';
  code.value = '';
  totp.value = null;
  emailPrepared.value = false;
  recoveryCodes.value = [];
}

async function load(): Promise<void> {
  loading.value = true;
  try {
    profile.value = (await api.get<SecurityProfile>('/security')).data;
  } catch (caught) {
    error.value = errorMessage(caught);
  } finally {
    loading.value = false;
  }
}

async function prepareTotp(): Promise<void> {
  await perform(async () => {
    totp.value = (await api.post<TotpPreparation>('/security/totp/prepare', {
      current_password: password.value
    })).data;
    password.value = '';
  });
}

async function confirmTotp(): Promise<void> {
  await perform(async () => {
    const response = await api.post<{
      recovery_codes: string[];
      reauthenticate: boolean;
    }>('/security/totp/confirm', { code: code.value });
    recoveryCodes.value = response.data.recovery_codes;
    code.value = '';
    notifications.push('Le second facteur TOTP est activé.', 'success');
  });
}

async function prepareEmail(): Promise<void> {
  await perform(async () => {
    await api.post('/security/email/prepare', {
      current_password: password.value
    });
    password.value = '';
    emailPrepared.value = true;
    notifications.push('Un code de vérification vous a été envoyé.', 'info');
  });
}

async function confirmEmail(): Promise<void> {
  await perform(async () => {
    await api.post('/security/email/confirm', { code: code.value });
    notifications.push('Le second facteur par e-mail est activé.', 'success');
    reconnect();
  });
}

async function disable(): Promise<void> {
  await perform(async () => {
    await api.post('/security/disable', { current_password: password.value });
    notifications.push('Le second facteur est désactivé.', 'success');
    reconnect();
  });
}

async function changePassword(): Promise<void> {
  await perform(async () => {
    await api.post('/security/password', {
      current_password: password.value,
      new_password: newPassword.value,
      new_password_confirmation: passwordConfirmation.value
    });
    notifications.push('Le mot de passe est modifié.', 'success');
    reconnect();
  });
}

async function perform(operation: () => Promise<void>): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    await operation();
  } catch (caught) {
    error.value = errorMessage(caught);
  } finally {
    loading.value = false;
  }
}

async function copyRecoveryCodes(): Promise<void> {
  await navigator.clipboard.writeText(recoveryCodes.value.join('\n'));
  notifications.push('Codes de récupération copiés.', 'success');
}

function downloadRecoveryCodes(): void {
  const blob = new Blob([
    `Codes de récupération Compta — ${profile.value?.email || ''}\n\n`,
    recoveryCodes.value.join('\n'),
    '\n'
  ], { type: 'text/plain;charset=utf-8' });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = 'compta-codes-recuperation.txt';
  link.click();
  URL.revokeObjectURL(link.href);
}

function reconnect(): void {
  window.location.assign(runtimeConfig.loginUrl);
}

defineExpose({ open });
</script>

<template>
  <ModalDialog
    ref="dialog"
    title="Sécurité du compte"
    description="Protégez l’accès à toutes les organisations et tous les dossiers liés à ce compte."
    wide
  >
    <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>
    <div v-if="loading && !profile" class="d-flex align-items-center gap-2 py-4">
      <span class="spinner-border spinner-border-sm" aria-hidden="true" />
      Chargement…
    </div>

    <template v-else-if="profile">
      <div v-if="recoveryCodes.length" class="security-recovery">
        <div class="alert alert-warning">
          Ces codes ne seront plus affichés. Conservez-les hors de cet appareil,
          puis reconnectez-vous avec TOTP.
        </div>
        <div class="row row-cols-2 g-2 mb-3 font-monospace">
          <div v-for="recoveryCode in recoveryCodes" :key="recoveryCode" class="col">
            <code class="d-block border rounded p-2 text-center">{{ recoveryCode }}</code>
          </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <button class="btn btn-outline-secondary" type="button" @click="copyRecoveryCodes">
            Copier
          </button>
          <button class="btn btn-outline-secondary" type="button" @click="downloadRecoveryCodes">
            Télécharger
          </button>
          <button class="btn btn-primary ms-auto" type="button" @click="reconnect">
            J’ai sauvegardé — me reconnecter
          </button>
        </div>
      </div>

      <template v-else>
        <div class="d-flex flex-wrap justify-content-between gap-3 border rounded p-3 mb-4">
          <div>
            <small class="text-body-secondary d-block">Protection actuelle</small>
            <strong>{{ modeLabel }}</strong>
          </div>
          <div v-if="profile.mode === 'totp'" class="text-end">
            <small class="text-body-secondary d-block">Codes de récupération disponibles</small>
            <strong>{{ profile.recovery_codes_remaining }}</strong>
          </div>
        </div>

        <div v-if="action === 'home'">
          <div class="alert alert-info">
            Le mot de passe reste obligatoire. Le code constitue une seconde
            vérification indépendante.
          </div>
          <div v-if="profile.mode === 'password'" class="d-grid gap-2">
            <button
              class="btn btn-primary"
              type="button"
              :disabled="!profile.totp_available"
              @click="action = 'totp'"
            >
              Activer une application d’authentification
            </button>
            <small v-if="!profile.totp_available" class="text-danger">
              L’administrateur doit d’abord définir APP_MFA_KEY.
            </small>
            <button
              class="btn btn-outline-primary"
              type="button"
              :disabled="!profile.email_available"
              @click="action = 'email'"
            >
              Activer les codes par e-mail
            </button>
          </div>
          <button
            v-else
            class="btn btn-outline-danger"
            type="button"
            @click="action = 'disable'"
          >
            Désactiver le second facteur
          </button>
          <hr>
          <button class="btn btn-outline-secondary" type="button" @click="action = 'password'">
            Modifier le mot de passe
          </button>
        </div>

        <form v-else-if="action === 'totp'" @submit.prevent="totp ? confirmTotp() : prepareTotp()">
          <template v-if="!totp">
            <label class="form-label" for="security-totp-password">Mot de passe actuel</label>
            <input
              id="security-totp-password"
              v-model="password"
              class="form-control"
              type="password"
              autocomplete="current-password"
              required
            >
          </template>
          <template v-else>
            <div class="row align-items-center g-4">
              <div class="col-md-auto text-center">
                <img
                  class="security-totp-qr border rounded"
                  :src="totp.qr_data_uri"
                  alt="QR code de configuration TOTP"
                >
              </div>
              <div class="col">
                <p>Scannez ce QR code dans votre application, puis confirmez le premier code.</p>
                <label class="form-label" for="security-totp-secret">Clé manuelle</label>
                <input
                  id="security-totp-secret"
                  class="form-control font-monospace mb-3"
                  :value="totp.secret"
                  readonly
                >
                <label class="form-label" for="security-totp-code">Code à six chiffres</label>
                <input
                  id="security-totp-code"
                  v-model="code"
                  class="form-control"
                  inputmode="numeric"
                  autocomplete="one-time-code"
                  pattern="[0-9]{6}"
                  maxlength="6"
                  required
                >
              </div>
            </div>
          </template>
          <div class="d-flex gap-2 mt-4">
            <button class="btn btn-link" type="button" @click="action = 'home'; totp = null">
              Annuler
            </button>
            <button class="btn btn-primary ms-auto" type="submit" :disabled="loading">
              {{ totp ? 'Activer TOTP' : 'Continuer' }}
            </button>
          </div>
        </form>

        <form v-else-if="action === 'email'" @submit.prevent="emailPrepared ? confirmEmail() : prepareEmail()">
          <template v-if="!emailPrepared">
            <p>Le code sera envoyé à <strong>{{ profile.email }}</strong>.</p>
            <label class="form-label" for="security-email-password">Mot de passe actuel</label>
            <input
              id="security-email-password"
              v-model="password"
              class="form-control"
              type="password"
              autocomplete="current-password"
              required
            >
          </template>
          <template v-else>
            <label class="form-label" for="security-email-code">Code reçu par e-mail</label>
            <input
              id="security-email-code"
              v-model="code"
              class="form-control"
              inputmode="numeric"
              autocomplete="one-time-code"
              pattern="[0-9]{6}"
              maxlength="6"
              required
            >
          </template>
          <div class="d-flex gap-2 mt-4">
            <button class="btn btn-link" type="button" @click="action = 'home'; password = ''; code = ''; emailPrepared = false">
              Annuler
            </button>
            <button class="btn btn-primary ms-auto" type="submit" :disabled="loading">
              {{ emailPrepared ? 'Activer' : 'Envoyer le code' }}
            </button>
          </div>
        </form>

        <form v-else-if="action === 'disable'" @submit.prevent="disable">
          <div class="alert alert-warning">
            Cette action réduit la protection du compte au seul mot de passe.
          </div>
          <label class="form-label" for="security-disable-password">Mot de passe actuel</label>
          <input
            id="security-disable-password"
            v-model="password"
            class="form-control"
            type="password"
            autocomplete="current-password"
            required
          >
          <div class="d-flex gap-2 mt-4">
            <button class="btn btn-link" type="button" @click="action = 'home'; password = ''">
              Annuler
            </button>
            <button class="btn btn-danger ms-auto" type="submit" :disabled="loading">
              Désactiver
            </button>
          </div>
        </form>

        <form v-else @submit.prevent="changePassword">
          <div class="alert alert-info">
            Utilisez une phrase secrète unique d’au moins 12 caractères.
            Toutes les autres sessions seront révoquées.
          </div>
          <div class="mb-3">
            <label class="form-label" for="security-current-password">Mot de passe actuel</label>
            <input
              id="security-current-password"
              v-model="password"
              class="form-control"
              type="password"
              autocomplete="current-password"
              required
            >
          </div>
          <div class="mb-3">
            <label class="form-label" for="security-new-password">Nouveau mot de passe</label>
            <input
              id="security-new-password"
              v-model="newPassword"
              class="form-control"
              type="password"
              autocomplete="new-password"
              minlength="12"
              required
            >
          </div>
          <div>
            <label class="form-label" for="security-confirm-password">Confirmation</label>
            <input
              id="security-confirm-password"
              v-model="passwordConfirmation"
              class="form-control"
              type="password"
              autocomplete="new-password"
              minlength="12"
              required
            >
          </div>
          <div class="d-flex gap-2 mt-4">
            <button
              class="btn btn-link"
              type="button"
              @click="action = 'home'; password = ''; newPassword = ''; passwordConfirmation = ''"
            >
              Annuler
            </button>
            <button class="btn btn-primary ms-auto" type="submit" :disabled="loading">
              Modifier et me reconnecter
            </button>
          </div>
        </form>
      </template>
    </template>
  </ModalDialog>
</template>
