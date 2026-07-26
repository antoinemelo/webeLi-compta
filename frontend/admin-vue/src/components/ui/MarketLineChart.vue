<script setup lang="ts">
import { computed } from 'vue';

type ChartSeries = {
  key: string;
  label: string;
  values: Array<string | null>;
};

const props = defineProps<{
  labels: string[];
  series: ChartSeries[];
  valueSuffix?: string;
  description: string;
}>();

const width = 920;
const height = 320;
const padding = { top: 24, right: 24, bottom: 48, left: 64 };
const colors = ['#006699', '#c24f2f', '#427a3f', '#7d4b9e', '#a87500', '#2f7580'];

const numericValues = computed(() => props.series.flatMap(
  (item) => item.values
    .filter((value): value is string => value !== null)
    .map((value) => Number(value))
    .filter(Number.isFinite)
));

const range = computed(() => {
  const values = numericValues.value;
  if (!values.length) return { min: 0, max: 1 };
  let min = Math.min(...values);
  let max = Math.max(...values);
  if (min === max) {
    const margin = Math.abs(min || 1) * .05;
    min -= margin;
    max += margin;
  }
  const margin = (max - min) * .08;
  return { min: min - margin, max: max + margin };
});

const ticks = computed(() => Array.from({ length: 5 }, (_, index) => {
  const value = range.value.max - ((range.value.max - range.value.min) * index / 4);
  return {
    value,
    y: padding.top + ((height - padding.top - padding.bottom) * index / 4)
  };
}));

function x(index: number): number {
  const denominator = Math.max(props.labels.length - 1, 1);
  return padding.left
    + ((width - padding.left - padding.right) * index / denominator);
}

function y(value: number): number {
  return padding.top
    + ((range.value.max - value) / (range.value.max - range.value.min))
      * (height - padding.top - padding.bottom);
}

function path(values: Array<string | null>): string {
  let open = false;
  return values.map((value, index) => {
    const numeric = value === null ? Number.NaN : Number(value);
    if (!Number.isFinite(numeric)) {
      open = false;
      return '';
    }
    const command = open ? 'L' : 'M';
    open = true;
    return `${command}${x(index).toFixed(2)},${y(numeric).toFixed(2)}`;
  }).filter(Boolean).join(' ');
}

function showLabel(index: number): boolean {
  const step = Math.max(1, Math.ceil(props.labels.length / 8));
  return index % step === 0 || index === props.labels.length - 1;
}

function formatted(value: number): string {
  return new Intl.NumberFormat('fr-CH', {
    maximumFractionDigits: 4,
    minimumFractionDigits: 0
  }).format(value);
}
</script>

<template>
  <figure class="market-chart">
    <svg
      :viewBox="`0 0 ${width} ${height}`"
      role="img"
      :aria-label="description"
      preserveAspectRatio="xMidYMid meet"
    >
      <g v-for="tick in ticks" :key="tick.y">
        <line
          :x1="padding.left"
          :x2="width - padding.right"
          :y1="tick.y"
          :y2="tick.y"
          class="grid-line"
        />
        <text :x="padding.left - 10" :y="tick.y + 4" text-anchor="end">
          {{ formatted(tick.value) }}
        </text>
      </g>
      <g v-for="(label, index) in labels" :key="label">
        <text
          v-if="showLabel(index)"
          :x="x(index)"
          :y="height - 18"
          text-anchor="middle"
        >{{ label }}</text>
      </g>
      <path
        v-for="(item, index) in series"
        :key="item.key"
        :d="path(item.values)"
        :stroke="colors[index % colors.length]"
        class="series-line"
      />
    </svg>
    <figcaption>
      <span v-for="(item, index) in series" :key="item.key">
        <i :style="{ backgroundColor: colors[index % colors.length] }"></i>
        {{ item.label }}
      </span>
      <small v-if="valueSuffix">{{ valueSuffix }}</small>
    </figcaption>
  </figure>
</template>

<style scoped>
.market-chart { margin: 0; padding: 1rem; border: 1px solid var(--border); border-radius: .75rem; background: var(--surface); }
.market-chart svg { display: block; width: 100%; min-height: 260px; }
.market-chart text { fill: var(--muted); font-size: 12px; }
.grid-line { stroke: var(--border); stroke-width: 1; }
.series-line { fill: none; stroke-width: 2.5; stroke-linejoin: round; stroke-linecap: round; }
.market-chart figcaption { display: flex; flex-wrap: wrap; gap: .8rem 1.2rem; align-items: center; margin-top: .5rem; color: var(--muted); }
.market-chart figcaption span { display: inline-flex; align-items: center; gap: .35rem; }
.market-chart figcaption i { width: .8rem; height: .8rem; border-radius: 50%; }
.market-chart figcaption small { margin-left: auto; }
</style>
