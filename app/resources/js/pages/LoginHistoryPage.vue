<script setup>
import { onMounted, ref } from 'vue';
import AppLayout from '../components/AppLayout.vue';
import DataTable from '../components/DataTable.vue';

const rows = ref([]);
const errorMessage = ref('');
const columns = [
    { key: 'created_at', label: 'Waktu' },
    { key: 'peristiwa_label', label: 'Peristiwa' },
    { key: 'ip_address', label: 'Alamat IP' },
    { key: 'user_agent', label: 'Perangkat' },
];
const labels = {
    LOGIN_BERHASIL: 'Berhasil masuk', LOGIN_GAGAL: 'Gagal masuk', LOGOUT: 'Keluar',
    AKUN_TERKUNCI: 'Akun terkunci', GANTI_SANDI: 'Ganti kata sandi', RESET_SANDI: 'Atur ulang kata sandi',
    DUA_FAKTOR_AKTIF: 'Aktifkan autentikasi dua faktor', DUA_FAKTOR_GAGAL: 'Autentikasi dua faktor gagal',
};

async function load() {
    try {
        const response = await fetch('/api/riwayat-login', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const payload = await response.json();
        if (! response.ok) throw new Error(payload.message ?? 'Riwayat masuk tidak dapat dimuat.');
        rows.value = (payload.data?.data ?? []).map((row) => ({
            ...row,
            peristiwa_label: labels[row.peristiwa] ?? 'Aktivitas akun',
        }));
    } catch (error) {
        errorMessage.value = error.message;
    }
}

function formatDate(value) {
    if (! value) return '—';
    const timestamp = /(?:Z|[+-]\d\d:\d\d)$/.test(value) ? value : `${value.replace(' ', 'T')}+07:00`;
    return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(timestamp));
}

onMounted(load);
</script>

<template>
    <AppLayout>
        <div class="page-content">
            <header class="page-heading"><div><p class="eyebrow">KEAMANAN AKUN</p><h1>Riwayat Login Saya</h1></div></header>
            <p v-if="errorMessage" class="management-error" role="alert">{{ errorMessage }}</p>
            <DataTable :columns="columns" :rows="rows" label="Riwayat login saya">
                <template #cell-created_at="{ row }">{{ formatDate(row.created_at) }}</template>
            </DataTable>
        </div>
    </AppLayout>
</template>
