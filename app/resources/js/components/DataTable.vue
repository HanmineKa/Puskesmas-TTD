<script setup>
defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, required: true },
    label: { type: String, required: true },
    showActions: { type: Boolean, default: false },
});
</script>

<template>
    <div class="data-table-scroll" role="region" :aria-label="label" tabindex="0">
        <table class="data-table">
            <thead>
                <tr>
                    <th v-for="column in columns" :key="column.key" scope="col">
                        {{ column.label }}
                    </th>
                    <th v-if="showActions" scope="col">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, rowIndex) in rows" :key="row.id ?? rowIndex">
                    <td v-for="column in columns" :key="column.key">
                        <slot :name="`cell-${column.key}`" :row="row" :value="row[column.key]">
                            {{ row[column.key] ?? '—' }}
                        </slot>
                    </td>
                    <td v-if="showActions"><slot name="actions" :row="row" /></td>
                </tr>
                <tr v-if="rows.length === 0">
                    <td :colspan="columns.length + (showActions ? 1 : 0)" class="data-table__empty">Belum ada data.</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>