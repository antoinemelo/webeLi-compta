<script setup lang="ts">
import { computed, ref } from 'vue';
import { formatDate, formatDateTime } from '@/utils/dateFormat';

type TableColumn = {
  key: string;
  label: string;
  sortable?: boolean;
  sortKey?: string;
  type?: 'text' | 'number';
};

const props = withDefaults(defineProps<{
  caption: string;
  columns: TableColumn[];
  rows: Array<Record<string, unknown>>;
  sortable?: boolean;
}>(), {
  sortable: false
});

const sortKey = ref('');
const sortDirection = ref<'asc' | 'desc'>('asc');

function isSortable(column: TableColumn): boolean {
  return column.sortable ?? (props.sortable && column.key !== 'actions');
}

function changeSort(column: TableColumn): void {
  if (!isSortable(column)) return;
  const key = column.sortKey || column.key;
  if (sortKey.value === key) {
    sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    return;
  }
  sortKey.value = key;
  sortDirection.value = 'asc';
}

function displayValue(value: unknown): unknown {
  if (typeof value !== 'string') return value;
  if (!/^\d{4}-\d{2}-\d{2}(?=$|[T\s])/.test(value)) return value;
  return /[T\s]\d{2}:\d{2}/.test(value)
    ? formatDateTime(value)
    : formatDate(value);
}

const sortedRows = computed(() => {
  if (!sortKey.value) return props.rows;
  const column = props.columns.find(
    (item) => (item.sortKey || item.key) === sortKey.value
  );
  if (!column) return props.rows;
  const direction = sortDirection.value === 'asc' ? 1 : -1;
  return [...props.rows].sort((left, right) => {
    const leftValue = left[sortKey.value];
    const rightValue = right[sortKey.value];
    if (leftValue === rightValue) return 0;
    if (leftValue === null || leftValue === undefined || leftValue === '') {
      return 1;
    }
    if (rightValue === null || rightValue === undefined || rightValue === '') {
      return -1;
    }
    if (column.type === 'number') {
      return (Number(leftValue) - Number(rightValue)) * direction;
    }
    return String(leftValue).localeCompare(String(rightValue), 'fr-CH', {
      numeric: true,
      sensitivity: 'base'
    }) * direction;
  });
});
</script>

<template>
  <div class="table-scroll" tabindex="0" :aria-label="caption">
    <table class="data-table">
      <caption>{{ caption }}</caption>
      <thead>
        <tr>
          <th
            v-for="column in columns"
            :key="column.key"
            scope="col"
            :aria-sort="sortKey === (column.sortKey || column.key)
              ? (sortDirection === 'asc' ? 'ascending' : 'descending')
              : undefined"
          >
            <button
              v-if="isSortable(column)"
              class="table-sort-button"
              type="button"
              @click="changeSort(column)"
            >
              {{ column.label }}
              <span aria-hidden="true">
                {{ sortKey === (column.sortKey || column.key)
                  ? (sortDirection === 'asc' ? '▲' : '▼')
                  : '↕' }}
              </span>
            </button>
            <template v-else>{{ column.label }}</template>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(row, index) in sortedRows" :key="String(row._row_key ?? row.id ?? index)">
          <td v-for="column in columns" :key="column.key">
            <slot :name="`cell-${column.key}`" :row="row">
              {{ displayValue(row[column.key]) }}
            </slot>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
