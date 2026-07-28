import { watch } from 'vue';
import { useNotificationStore } from '@/stores/notifications';

type FeedbackSource = {
  error: string;
  notice?: string;
};

export function useToastFeedback(
  source: FeedbackSource,
  includeNotice = true
): void {
  const notifications = useNotificationStore();

  watch(
    () => source.error,
    (message) => {
      if (!message) return;
      notifications.push(message, 'error');
      source.error = '';
    }
  );

  if (includeNotice && 'notice' in source) {
    watch(
      () => source.notice,
      (message) => {
        if (!message) return;
        notifications.push(message, 'success');
        source.notice = '';
      }
    );
  }
}
