<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AppLayout from '../components/AppLayout.vue';
import BaseButton from '../components/BaseButton.vue';
import DataTable from '../components/DataTable.vue';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const auditRows = ref([]);
const loginRows = ref([]);
const accessRows = ref([]);
const errorMessage = ref('');
const filters = reactive({ mulai: '', selesai: '', aksi: '', pengguna_id: '', pustu_id: '', kategori: '', objek_tipe: '' });
const columns = [
    { key: 'created_at', label: 'Waktu' }, { key: 'user_id', label: 'ID Pengguna' },
    { key: 'role', label: 'Peran' }, { key: 'aksi', label: 'Tindakan' },
    { key: 'kategori', label: 'Kategori' }, { key: 'pustu_id', label: 'ID Pustu' },
];
const isAdministrator = computed(() => auth.user?.role === 'ADMINISTRATOR');

async function load() {
    errorMessage.value = '';
    try {
        const query = new URLSearchParams();
        Object.entries(filters).forEach(([key, value]) => { if (value) query.set(key, value); });
        const response = await fetch(`/api/log-aktivitas?${query}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const payload = await response.json();
        if (! response.ok) throw new Error(payload.message ?? 'Log aktivitas tidak dapat dimuat.');
        auditRows.value = payload.audit?.data ?? [];
        loginRows.value = payload.login?.data ?? [];
        accessRows.value = payload.akses_data?.data ?? [];
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
            <header class="page-heading"><div><p class="eyebrow">PENCATATAN AKTIVITAS</p><h1>{{ isAdministrator ? 'Log Aktivitas' : 'Riwayat Tindakan' }}</h1></div></header>
            <form class="log-filters" @submit.prevent="load">
                <label class="management-field"><span>Dari tanggal</span><input v-model="filters.mulai" type="date"></label>
                <label class="management-field"><span>Sampai tanggal</span><input v-model="filters.selesai" type="date"></label>
                <label class="management-field"><span>Jenis tindakan</span><input v-model="filters.aksi" type="text" maxlength="60" placeholder="Contoh: SEKOLAH_DIBUAT"></label>
                <label v-if="isAdministrator" class="management-field"><span>ID pengguna</span><input v-model="filters.pengguna_id" type="number" min="1"></label>
                <label v-if="isAdministrator" class="management-field"><span>ID Pustu</span><input v-model="filters.pustu_id" type="number" min="1"></label>
                <label class="management-field"><span>Kategori</span><select v-model="filters.kategori"><option value="">Semua kategori</option><option value="OPERASIONAL">Operasional</option><option v-if="isAdministrator" value="KEAMANAN">Keamanan</option></select></label>
                <label v-if="isAdministrator" class="management-field"><span>Jenis objek</span><input v-model="filters.objek_tipe" type="text" maxlength="50" placeholder="Contoh: Sekolah"></label>
                <BaseButton type="submit" variant="secondary">Terapkan filter</BaseButton>
            </form>
            <p v-if="errorMessage" class="management-error" role="alert">{{ errorMessage }}</p>
            <section class="log-section"><h2>Audit</h2><DataTable :columns="columns" :rows="auditRows" label="Daftar audit"><template #cell-created_at="{ row }">{{ formatDate(row.created_at) }}</template></DataTable></section>
            <template v-if="isAdministrator">
                <section class="log-section"><h2>Riwayat Masuk</h2><DataTable :columns="[
                    { key: 'created_at', label: 'Waktu' }, { key: 'user_id', label: 'ID Pengguna' },
                    { key: 'peristiwa', label: 'Peristiwa' }, { key: 'ip_address', label: 'Alamat IP' }, { key: 'user_agent', label: 'Perangkat' },
                ]" :rows="loginRows" label="Daftar riwayat masuk"><template #cell-created_at="{ row }">{{ formatDate(row.created_at) }}</template></DataTable></section>
                <section class="log-section"><h2>Akses Data</h2><DataTable :columns="[
                    { key: 'created_at', label: 'Waktu' }, { key: 'user_id', label: 'ID Pengguna' },
                    { key: 'jenis_akses', label: 'Jenis Akses' }, { key: 'objek_tipe', label: 'Objek' }, { key: 'jumlah_baris', label: 'Jumlah Baris' },
                ]" :rows="accessRows" label="Daftar akses data"><template #cell-created_at="{ row }">{{ formatDate(row.created_at) }}</template></DataTable></section>
            </template>
        </div>
    </AppLayout>
</template>
