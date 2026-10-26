<script setup>
defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, required: true },
    label: { type: String, required: true },
    showActions: { type: Boolean, default: false },
});
</script>

<template>
    <div class="data-table-wrap" :aria-label="label">
        <table class="data-table" :aria-label="label">
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
        <div class="data-card-list" role="list" :aria-label="`${label}, tampilan kartu`">
            <p v-if="rows.length === 0" class="data-table__empty">Belum ada data.</p>
            <article v-for="(row, rowIndex) in rows" :key="row.id ?? rowIndex" class="data-card" role="listitem">
                <dl class="data-card__fields">
                    <div v-for="column in columns" :key="column.key">
                        <dt>{{ column.label }}</dt>
                        <dd><slot :name="`cell-${column.key}`" :row="row" :value="row[column.key]">{{ row[column.key] ?? '—' }}</slot></dd>
                    </div>
                </dl>
                <div v-if="showActions" class="data-card__actions"><slot name="actions" :row="row" /></div>
            </article>
        </div>
    </div>
</template>