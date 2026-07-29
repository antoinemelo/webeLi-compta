<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { referenceNavigation, subNavigation } from '@/router/navigation';

type NavigationModule = {
  key: string;
  label: string;
  path: string;
};

type SearchTarget = {
  id: string;
  kind: 'navigation' | 'panel';
  label: string;
  path: string;
  section: string;
  description: string;
  keywords: string[];
};

const props = defineProps<{
  modules: NavigationModule[];
}>();

const router = useRouter();
const container = ref<HTMLElement | null>(null);
const input = ref<HTMLInputElement | null>(null);
const query = ref('');
const open = ref(false);
const activeIndex = ref(0);

const targets = computed<SearchTarget[]>(() => {
  const result: SearchTarget[] = [];
  const moduleKeys = new Set(props.modules.map((item) => item.key));

  const add = (
    id: string,
    label: string,
    path: string,
    section: string,
    description: string,
    keywords: string[] = [],
    kind: SearchTarget['kind'] = 'navigation'
  ) => result.push({ id, kind, label, path, section, description, keywords });

  props.modules.forEach((module) => {
    add(
      `module:${module.key}`,
      module.label,
      module.path,
      'Menu principal',
      `Ouvrir le module ${module.label}`,
      [module.key]
    );
    (subNavigation[module.key] ?? []).forEach((child) => add(
      `submenu:${module.key}:${child.key}`,
      child.label,
      child.path,
      module.label,
      `${module.label} / ${child.label}`,
      [module.label, module.key, child.key]
    ));
  });

  if (moduleKeys.has('settings')) {
    add(
      'scope:structures',
      'Organisations et dossiers',
      '/organisations-dossiers',
      'Contexte de travail',
      'Gérer les organisations, dossiers et accès',
      ['organisation', 'dossier', 'utilisateur', 'accès']
    );
    referenceNavigation.forEach((item) => add(
      `reference:${item.key}`,
      item.label,
      item.path,
      'Configuration / Référentiels',
      `Référentiel ${item.label}`,
      ['configuration', 'référentiel', item.key]
    ));
  }

  const supplementalTargets: Array<[
    string,
    string,
    string,
    string,
    string,
    string[]
  ]> = [
    ['panel:payment-terms', 'Conditions de paiement', '/configuration/paiements', 'Configuration', 'Défauts clients et fournisseurs, échéances et conditions', ['paiement', 'paiements', 'échéance', 'défaut']],
    ['panel:outgoing-payments', 'Saisir un paiement', '/liquidites/paiements', 'Liquidités', 'Paiements sortants et lots bancaires', ['paiement', 'paiements', 'sortant', 'bancaire']],
    ['panel:payment-matching', 'Lettrage des paiements', '/liquidites/lettrage', 'Liquidités', 'Allouer les encaissements et décaissements', ['paiement', 'paiements', 'allocation', 'lettrage']],
    ['panel:billing-aging', 'Échéancier et paiements', '/facturation', 'Facturation', 'Factures ouvertes, échéances et paiements', ['paiement', 'paiements', 'échéancier', 'facture']],
    ['panel:payroll-payments', 'Paiements salariaux', '/salaires/calculs', 'Salaires', 'Saisir, allouer et comptabiliser les salaires', ['paiement', 'paiements', 'salaire']],
    ['panel:dashboard-payments', 'Paiements à traiter', '/', 'Tableau de bord', 'Vue synthétique des paiements en attente', ['paiement', 'paiements', 'tableau de bord']]
  ];
  supplementalTargets.forEach(([id, label, path, section, description, keywords]) => {
    const requiredModule = path.startsWith('/configuration')
      ? 'settings'
      : path.startsWith('/liquidites')
        ? 'liquidity'
        : path.startsWith('/facturation')
          ? 'billing'
          : path.startsWith('/salaires')
            ? 'payroll'
            : 'dashboard';
    if (moduleKeys.has(requiredModule)) {
      add(id, label, path, section, description, keywords, 'panel');
    }
  });

  return result;
});

const normalizedQuery = computed(() => normalize(query.value.replace(/^\/+/, '')));
const routeMode = computed(() => query.value.trimStart().startsWith('/'));
const results = computed(() => {
  if (normalizedQuery.value.length < 2) return [];
  if (routeMode.value) {
    return targets.value
      .filter((item) => (
        item.kind === 'navigation'
        && normalize(item.label).startsWith(normalizedQuery.value)
      ))
      .sort((a, b) => a.label.localeCompare(b.label, 'fr') || a.section.localeCompare(b.section, 'fr'))
      .slice(0, 10);
  }
  const terms = normalizedQuery.value.split(' ').filter(Boolean);
  return targets.value
    .map((item) => ({ ...item, score: score(item, terms) }))
    .filter((item) => item.score > 0)
    .sort((a, b) => b.score - a.score || a.label.localeCompare(b.label, 'fr'))
    .slice(0, 10);
});

watch(query, () => {
  activeIndex.value = 0;
  open.value = normalizedQuery.value.length >= 2;
});

watch(results, (items) => {
  if (activeIndex.value >= items.length) activeIndex.value = Math.max(0, items.length - 1);
});

onMounted(() => {
  document.addEventListener('pointerdown', closeOnOutside);
  document.addEventListener('keydown', focusShortcut);
});

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', closeOnOutside);
  document.removeEventListener('keydown', focusShortcut);
});

function normalize(value: string): string {
  return value
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, ' ')
    .trim();
}

function score(item: SearchTarget, terms: string[]): number {
  const label = normalize(item.label);
  const path = normalize(item.path);
  const section = normalize(item.section);
  const details = normalize(`${item.description} ${item.keywords.join(' ')}`);
  let value = 0;
  for (const term of terms) {
    if (label === term) value += 120;
    else if (label.startsWith(term)) value += 80;
    else if (label.includes(term)) value += 55;
    if (section.includes(term)) value += 25;
    if (details.includes(term)) value += 20;
    if (path.includes(term)) value += 12;
  }
  return value;
}

function closeOnOutside(event: PointerEvent): void {
  if (!container.value?.contains(event.target as Node)) open.value = false;
}

function focusShortcut(event: KeyboardEvent): void {
  const target = event.target as HTMLElement | null;
  if (
    event.key !== '/'
    || event.ctrlKey
    || event.metaKey
    || event.altKey
    || target?.matches('input, textarea, select, [contenteditable="true"]')
  ) return;
  event.preventDefault();
  query.value = '/';
  nextTick(() => input.value?.focus());
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'ArrowDown') {
    event.preventDefault();
    open.value = true;
    activeIndex.value = Math.min(activeIndex.value + 1, results.value.length - 1);
  } else if (event.key === 'ArrowUp') {
    event.preventDefault();
    activeIndex.value = Math.max(activeIndex.value - 1, 0);
  } else if (event.key === 'Enter' && results.value[activeIndex.value]) {
    event.preventDefault();
    select(results.value[activeIndex.value]);
  } else if (event.key === 'Escape') {
    open.value = false;
    input.value?.blur();
  }
}

function select(item: SearchTarget): void {
  query.value = '';
  open.value = false;
  router.push(item.path);
}
</script>

<template>
  <div ref="container" class="global-navigation-search position-relative">
    <label class="visually-hidden" for="global-navigation-search">
      Rechercher dans la navigation
    </label>
    <span class="global-navigation-search-icon" aria-hidden="true">
      <svg viewBox="0 0 16 16" focusable="false">
        <path d="M11.742 10.344a6.5 6.5 0 1 0-1.398 1.398l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.867-3.834zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z" />
      </svg>
    </span>
    <input
      id="global-navigation-search"
      ref="input"
      v-model="query"
      type="search"
      class="form-control form-control-sm rounded-pill"
      placeholder="Rechercher un menu ou panneau…"
      autocomplete="off"
      role="combobox"
      aria-autocomplete="list"
      aria-controls="global-navigation-results"
      :aria-expanded="open"
      :aria-activedescendant="results[activeIndex] ? `navigation-result-${activeIndex}` : undefined"
      @focus="open = normalizedQuery.length >= 2"
      @keydown="onKeydown"
    >
    <div
      v-if="open"
      id="global-navigation-results"
      class="global-navigation-results dropdown-menu show p-2 shadow"
      role="listbox"
    >
      <button
        v-for="(item, index) in results"
        :id="`navigation-result-${index}`"
        :key="item.id"
        type="button"
        class="dropdown-item rounded-2"
        :class="{ active: index === activeIndex }"
        role="option"
        :aria-selected="index === activeIndex"
        @mouseenter="activeIndex = index"
        @click="select(item)"
      >
        <span class="d-flex justify-content-between gap-3">
          <strong>{{ item.label }}</strong>
          <small>{{ item.section }}</small>
        </span>
        <small class="d-block text-wrap">{{ item.description }}</small>
        <code v-if="routeMode">{{ item.path }}</code>
      </button>
      <p v-if="results.length === 0" class="small text-secondary m-2">
        Aucun menu ou panneau correspondant.
      </p>
      <p class="global-navigation-hint small text-secondary mb-0 px-2 pt-2">
        ↑↓ pour parcourir · Entrée pour ouvrir · <kbd>/</kbd> pour rechercher
      </p>
    </div>
  </div>
</template>
