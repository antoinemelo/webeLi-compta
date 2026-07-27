<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

type AccountOption = {
  id?: string | number | null;
  number?: string | number | null;
  numero?: string | number | null;
  account?: string | number | null;
  label?: string | null;
  libelle?: string | null;
  [key: string]: unknown;
};

const props = withDefaults(defineProps<{
  modelValue: string | number | null;
  options: AccountOption[];
  id?: string;
  ariaLabel?: string;
  ariaDescribedby?: string;
  placeholder?: string;
  valueKey?: string;
  numberKey?: string;
  labelKey?: string;
  emptyValue?: string | number | null;
  required?: boolean;
  disabled?: boolean;
}>(), {
  id: undefined,
  ariaLabel: undefined,
  ariaDescribedby: undefined,
  placeholder: 'Sélectionner un compte…',
  valueKey: 'id',
  numberKey: 'number',
  labelKey: 'label',
  emptyValue: 0,
  required: false,
  disabled: false
});

const emit = defineEmits<{
  'update:modelValue': [value: string | number | null];
  change: [value: string | number | null];
}>();

const input = ref<HTMLInputElement | null>(null);
const query = ref('');
const open = ref(false);
const editing = ref(false);
const activeIndex = ref(0);
const dropdownStyle = ref<Record<string, string>>({});
const teleportTarget = ref<HTMLElement | string>('body');
const generatedId = `account-combobox-${Math.random().toString(36).slice(2, 10)}`;
const inputId = computed(() => props.id || generatedId);
const listboxId = computed(() => `${inputId.value}-listbox`);

function optionValue(option: AccountOption): string | number | null {
  const value = option[props.valueKey];
  return typeof value === 'string' || typeof value === 'number' || value === null
    ? value
    : null;
}

function optionNumber(option: AccountOption): string {
  const preferred = option[props.numberKey];
  const value = preferred
    ?? option.numero
    ?? option.account
    ?? option.ledger_number
    ?? option.ledger_account_number;
  return value === null || value === undefined ? '' : String(value);
}

function optionLabel(option: AccountOption): string {
  const preferred = option[props.labelKey];
  const value = preferred ?? option.libelle;
  return value === null || value === undefined ? '' : String(value);
}

function displayLabel(option: AccountOption): string {
  const number = optionNumber(option);
  const label = optionLabel(option);
  return number && label ? `${number} ${label}` : number || label;
}

function comparable(value: string | number | null | undefined): string {
  return value === null || value === undefined ? '' : String(value);
}

function normalize(value: unknown): string {
  return String(value ?? '')
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLocaleLowerCase('fr-CH');
}

const selectedOption = computed(() =>
  props.options.find((option) =>
    comparable(optionValue(option)) === comparable(props.modelValue)
  )
);

const filteredOptions = computed(() => {
  const needle = normalize(query.value.trim());
  if (!needle) return props.options;
  return props.options.filter((option) =>
    normalize([
      displayLabel(option),
      ...Object.values(option).filter((value) =>
        ['string', 'number'].includes(typeof value)
      )
    ].join(' ')).includes(needle)
  );
});

const inputValue = computed(() =>
  editing.value ? query.value : selectedOption.value
    ? displayLabel(selectedOption.value)
    : ''
);

watch(
  () => [props.modelValue, props.options] as const,
  () => {
    if (!editing.value) query.value = '';
  },
  { deep: true }
);

function updateDropdownPosition(): void {
  if (!open.value || !input.value) return;
  const rect = input.value.getBoundingClientRect();
  const viewportPadding = 8;
  const gap = 4;
  const viewportWidth = document.documentElement.clientWidth;
  const viewportHeight = document.documentElement.clientHeight;
  const width = Math.min(
    Math.max(rect.width, 480),
    viewportWidth - viewportPadding * 2
  );
  const left = Math.min(
    Math.max(viewportPadding, rect.left),
    viewportWidth - width - viewportPadding
  );
  const spaceBelow = viewportHeight - rect.bottom - gap - viewportPadding;
  const spaceAbove = rect.top - gap - viewportPadding;
  const opensAbove = spaceBelow < 180 && spaceAbove > spaceBelow;
  const maxHeight = Math.max(
    96,
    Math.min(288, opensAbove ? spaceAbove : spaceBelow)
  );
  const top = opensAbove
    ? Math.max(viewportPadding, rect.top - gap - maxHeight)
    : rect.bottom + gap;

  dropdownStyle.value = {
    top: `${Math.round(top)}px`,
    left: `${Math.round(left)}px`,
    width: `${Math.round(width)}px`,
    maxHeight: `${Math.round(maxHeight)}px`
  };
}

function removePositionListeners(): void {
  window.removeEventListener('resize', updateDropdownPosition);
  window.removeEventListener('scroll', updateDropdownPosition, true);
}

watch(open, (isOpen) => {
  removePositionListeners();
  if (!isOpen) return;
  window.addEventListener('resize', updateDropdownPosition);
  window.addEventListener('scroll', updateDropdownPosition, true);
  void nextTick(updateDropdownPosition);
});

onBeforeUnmount(removePositionListeners);

function focusInput(): void {
  teleportTarget.value = input.value?.closest('dialog') ?? 'body';
  open.value = true;
  editing.value = true;
  query.value = selectedOption.value ? displayLabel(selectedOption.value) : '';
  activeIndex.value = Math.max(
    0,
    filteredOptions.value.findIndex((option) =>
      comparable(optionValue(option)) === comparable(props.modelValue)
    )
  );
  void nextTick(() => {
    input.value?.select();
    updateDropdownPosition();
  });
}

function filter(event: Event): void {
  editing.value = true;
  query.value = (event.target as HTMLInputElement).value;
  open.value = true;
  activeIndex.value = 0;
  void nextTick(updateDropdownPosition);
}

function clearSelection(): void {
  emit('update:modelValue', props.emptyValue);
  emit('change', props.emptyValue);
  query.value = '';
  editing.value = false;
  open.value = false;
  activeIndex.value = 0;
}

function commit(option?: AccountOption): void {
  if (!option && !props.required && !query.value.trim()) {
    emit('update:modelValue', props.emptyValue);
    emit('change', props.emptyValue);
    editing.value = false;
    open.value = false;
    return;
  }
  const selected = option ?? filteredOptions.value[activeIndex.value]
    ?? filteredOptions.value[0];
  if (selected) {
    const value = optionValue(selected);
    emit('update:modelValue', value);
    emit('change', value);
  }
  editing.value = false;
  open.value = false;
  query.value = '';
}

function move(direction: 1 | -1): void {
  if (!open.value) open.value = true;
  const length = filteredOptions.value.length;
  if (!length) return;
  activeIndex.value = (activeIndex.value + direction + length) % length;
  void nextTick(() => {
    document.getElementById(`${listboxId.value}-${activeIndex.value}`)
      ?.scrollIntoView({ block: 'nearest' });
  });
}

function onKeydown(event: KeyboardEvent): void {
  const target = event.currentTarget as HTMLInputElement;
  const isDeletionKey = event.key === 'Backspace' || event.key === 'Delete';
  const selectsEntireValue = (
    isDeletionKey
    && target.value.length > 0
    && target.selectionStart === 0
    && target.selectionEnd === target.value.length
  );
  if (event.key === 'Escape' || selectsEntireValue) {
    event.preventDefault();
    event.stopPropagation();
    clearSelection();
  } else if (isDeletionKey) {
    if (!editing.value) {
      editing.value = true;
      query.value = target.value;
      open.value = true;
      void nextTick(updateDropdownPosition);
    }
  } else if (event.key === 'ArrowDown') {
    event.preventDefault();
    move(1);
  } else if (event.key === 'ArrowUp') {
    event.preventDefault();
    move(-1);
  } else if (event.key === 'Enter') {
    event.preventDefault();
    commit();
  } else if (event.key === 'Tab') {
    commit();
  }
}

function onBlur(): void {
  window.setTimeout(() => {
    if (editing.value) commit();
  });
}
</script>

<template>
  <div class="account-combobox">
    <input
      :id="inputId"
      ref="input"
      class="account-combobox-input"
      type="text"
      role="combobox"
      autocomplete="off"
      :value="inputValue"
      :placeholder="placeholder"
      :required="required"
      :disabled="disabled"
      :aria-label="ariaLabel"
      :aria-describedby="ariaDescribedby"
      :aria-expanded="open"
      :aria-controls="listboxId"
      aria-autocomplete="list"
      :aria-activedescendant="open && filteredOptions[activeIndex] ? `${listboxId}-${activeIndex}` : undefined"
      @focus="focusInput"
      @input="filter"
      @keydown="onKeydown"
      @blur="onBlur"
    >
    <Teleport :to="teleportTarget">
      <ul
        v-if="open"
        :id="listboxId"
        class="account-combobox-list"
        role="listbox"
        :style="dropdownStyle"
      >
        <li
          v-for="(option, index) in filteredOptions"
          :id="`${listboxId}-${index}`"
          :key="`${String(optionValue(option))}-${index}`"
          role="option"
          :aria-selected="index === activeIndex"
          :class="{ active: index === activeIndex }"
          @mousedown.prevent="commit(option)"
          @mousemove="activeIndex = index"
        >
          {{ displayLabel(option) }}
        </li>
        <li v-if="!filteredOptions.length" class="empty" aria-disabled="true">
          Aucun compte correspondant
        </li>
      </ul>
    </Teleport>
  </div>
</template>
