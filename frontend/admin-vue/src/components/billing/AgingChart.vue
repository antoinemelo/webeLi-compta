<script setup lang="ts">
import { computed } from 'vue';
import type { BillingAgingSide } from '@/api/contracts';

const props = defineProps<{
  receivables: BillingAgingSide;
  payables: BillingAgingSide;
}>();

const buckets = computed(() => [
  {
    key: 'not_due',
    label: 'Non échu',
    receivables: props.receivables.buckets.not_due,
    payables: props.payables.buckets.not_due
  },
  {
    key: 'days_0_30',
    label: '0–30 jours',
    receivables: props.receivables.buckets.days_0_30,
    payables: props.payables.buckets.days_0_30
  },
  {
    key: 'days_31_60',
    label: '31–60 jours',
    receivables: props.receivables.buckets.days_31_60,
    payables: props.payables.buckets.days_31_60
  },
  {
    key: 'days_61_90',
    label: '61–90 jours',
    receivables: props.receivables.buckets.days_61_90,
    payables: props.payables.buckets.days_61_90
  },
  {
    key: 'days_91_plus',
    label: 'Plus de 90 jours',
    receivables: props.receivables.buckets.days_91_plus,
    payables: props.payables.buckets.days_91_plus
  }
]);

const maximum = computed(() => Math.max(
  1,
  ...buckets.value.flatMap((bucket) => [
    Math.abs(bucket.receivables),
    Math.abs(bucket.payables)
  ])
));

function height(value: number): string {
  return `${Math.abs(value) / maximum.value * 100}%`;
}
</script>

<template>
  <figure
    class="aging-chart"
    role="img"
    aria-label="Répartition graphique des créances et des dettes par ancienneté"
  >
    <div class="aging-chart-plot">
      <div class="aging-chart-body">
        <section v-for="bucket in buckets" :key="bucket.key" class="aging-chart-group">
          <div class="aging-chart-columns">
            <div class="aging-chart-series receivable">
              <div class="aging-chart-track">
                <span
                  :class="['aging-chart-bar', 'receivable', { negative: bucket.receivables < 0 }]"
                  :style="{ height: height(bucket.receivables) }"
                ></span>
              </div>
              <span class="visually-hidden">Créances</span>
            </div>
            <div class="aging-chart-series payable">
              <div class="aging-chart-track">
                <span
                  :class="['aging-chart-bar', 'payable', { negative: bucket.payables < 0 }]"
                  :style="{ height: height(bucket.payables) }"
                ></span>
              </div>
              <span class="visually-hidden">Dettes</span>
            </div>
          </div>
          <span class="aging-chart-axis-label">{{ bucket.label }}</span>
        </section>
      </div>
    </div>

    <figcaption>
      La longueur est comparée au montant absolu le plus élevé. Une trame
      signale les avoirs ou autres soldes négatifs.
    </figcaption>
  </figure>
</template>

<style scoped>
.aging-chart {
  display: grid;
  gap: 1rem;
  margin: 1rem 0 0;
  padding: clamp(1rem, 2vw, 1.5rem);
  border: 1px solid var(--border);
  border-radius: .85rem;
  background: var(--surface);
}

.aging-chart-bar.receivable {
  background: #087f8c;
}

.aging-chart-bar.payable {
  background: #c2413b;
}

.aging-chart-plot {
  overflow-x: auto;
}

.aging-chart-body {
  display: grid;
  grid-template-columns: repeat(5, minmax(8rem, 1fr));
  gap: 1rem;
  min-width: 44rem;
  padding-top: .35rem;
  border-bottom: 1px solid var(--border);
}

.aging-chart-group {
  display: grid;
  grid-template-rows: 12rem auto;
  gap: .7rem;
  min-width: 0;
}

.aging-chart-columns {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: .5rem;
  align-items: end;
}

.aging-chart-series {
  display: flex;
  align-items: stretch;
  height: 100%;
  min-width: 0;
}

.aging-chart-track {
  display: flex;
  align-items: flex-end;
  justify-content: center;
  width: 100%;
  height: 100%;
  overflow: hidden;
  border-radius: .4rem .4rem 0 0;
  background: color-mix(in srgb, var(--surface-soft) 72%, transparent);
}

.aging-chart-bar {
  display: block;
  width: 100%;
  min-height: 0;
  border-radius: .35rem .35rem 0 0;
  transition: height .2s ease;
}

.aging-chart-bar.negative {
  background-image: repeating-linear-gradient(
    135deg,
    rgba(255, 255, 255, .55) 0 4px,
    transparent 4px 8px
  );
}

.aging-chart figcaption {
  color: var(--muted);
  font-size: .82rem;
}

.aging-chart-axis-label {
  min-height: 2.5rem;
  font-size: .85rem;
  font-weight: 700;
  line-height: 1.25;
  text-align: center;
}
</style>
