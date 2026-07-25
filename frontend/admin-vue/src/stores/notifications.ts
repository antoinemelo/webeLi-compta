import { defineStore } from 'pinia';

export type Notification = {
  id: number;
  message: string;
  tone: 'success' | 'info' | 'warning';
};

let nextId = 1;

export const useNotificationStore = defineStore('notifications', {
  state: () => ({ items: [] as Notification[] }),
  actions: {
    push(message: string, tone: Notification['tone'] = 'info'): void {
      const id = nextId++;
      this.items.push({ id, message, tone });
      window.setTimeout(() => this.dismiss(id), 5000);
    },
    dismiss(id: number): void {
      this.items = this.items.filter((item) => item.id !== id);
    }
  }
});
