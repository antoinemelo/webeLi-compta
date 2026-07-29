<script setup lang="ts">
import { useNotificationStore } from '@/stores/notifications';

const notifications = useNotificationStore();
</script>

<template>
  <div class="toast-region" aria-live="polite" aria-atomic="false">
    <article
      v-for="item in notifications.items"
      :key="item.id"
      class="feedback-toast"
      :class="`feedback-toast-${item.tone}`"
    >
      <p>{{ item.message }}</p>
      <button type="button" aria-label="Fermer la notification" @click="notifications.dismiss(item.id)">×</button>
    </article>
  </div>
</template>
