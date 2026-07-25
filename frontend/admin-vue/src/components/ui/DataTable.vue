<script setup lang="ts">
defineProps<{
  caption: string;
  columns: Array<{ key: string; label: string }>;
  rows: Array<Record<string, unknown>>;
}>();
</script>

<template>
  <div class="table-scroll" tabindex="0" :aria-label="caption">
    <table class="data-table">
      <caption>{{ caption }}</caption>
      <thead>
        <tr>
          <th v-for="column in columns" :key="column.key" scope="col">{{ column.label }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(row, index) in rows" :key="String(row.id ?? index)">
          <td v-for="column in columns" :key="column.key">
            <slot :name="`cell-${column.key}`" :row="row">
              {{ row[column.key] }}
            </slot>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
