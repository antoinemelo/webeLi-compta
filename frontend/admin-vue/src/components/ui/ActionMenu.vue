<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

withDefaults(defineProps<{
  label?: string;
}>(), {
  label: 'Actions'
});

const open = ref(false);
const trigger = ref<HTMLButtonElement | null>(null);
const menu = ref<HTMLElement | null>(null);
const position = reactive({ top: '0px', left: '0px' });

onMounted(() => {
  document.addEventListener('pointerdown', closeOutside);
  document.addEventListener('keydown', closeOnEscape);
  window.addEventListener('resize', close);
  window.addEventListener('scroll', placeMenu, true);
});

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', closeOutside);
  document.removeEventListener('keydown', closeOnEscape);
  window.removeEventListener('resize', close);
  window.removeEventListener('scroll', placeMenu, true);
});

async function toggle(): Promise<void> {
  open.value = !open.value;
  if (!open.value) return;
  await nextTick();
  placeMenu();
}

function placeMenu(): void {
  if (!trigger.value || !menu.value) return;
  const anchor = trigger.value.getBoundingClientRect();
  const popup = menu.value.getBoundingClientRect();
  const gap = 6;
  const top = anchor.bottom + popup.height + gap <= window.innerHeight
    ? anchor.bottom + gap
    : Math.max(gap, anchor.top - popup.height - gap);
  const left = Math.min(
    window.innerWidth - popup.width - gap,
    Math.max(gap, anchor.right - popup.width)
  );
  position.top = `${top}px`;
  position.left = `${left}px`;
}

function close(): void {
  open.value = false;
}

function closeOutside(event: PointerEvent): void {
  const target = event.target as Node;
  if (!trigger.value?.contains(target) && !menu.value?.contains(target)) close();
}

function closeOnEscape(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    close();
    trigger.value?.focus();
  }
}
</script>

<template>
  <span class="action-menu">
    <button
      ref="trigger"
      class="action-menu-trigger"
      type="button"
      :aria-label="label"
      :title="label"
      :aria-expanded="open"
      @click="toggle"
    >
      <span aria-hidden="true">⋮</span>
    </button>
    <Teleport to="body">
      <div
        v-if="open"
        ref="menu"
        class="action-menu-popover"
        role="menu"
        :aria-label="label"
        :style="position"
        @click="close"
      >
        <slot />
      </div>
    </Teleport>
  </span>
</template>
