<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AppLayout from '../components/AppLayout.vue';
import BaseButton from '../components/BaseButton.vue';
import DataTable from '../components/DataTable.vue';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const options = ref({ tahun_ajaran: [], sekolah: [], kelurahan: [] });
const report = ref(null);
const filters = reactive({ tahun_ajaran_id: '', bulan: '', kelurahan_id: '', sekolah_id: '' });
const optionsLoading = ref(true);
const reportLoading = ref(false);
const exportLoading = ref(false);
const optionsReady = ref(false);
const errorMessage = ref('');
const exportError = ref('');
const confirmIdentity = ref(false);
const identityPassword = ref('');
const isAdministrator = computed(() => auth.user?.role === 'ADMINISTRATOR');
const schoolOptions = computed(() => options.value.sekolah.filter((school) => (
    ! filters.kelurahan_id || String(school.kelurahan_id) === filters.kelurahan_id
)));
const columns = [
    { key: 'nama', label: 'Sekolah' },
    { key: 'kelurahan', label: 'Kelurahan' },
    { key: 'jumlah_siswi', label: 'Siswi' },
    { key: 'jumlah_siswi_melapor', label: 'Siswi Melapor' },
    { key: 'cakupan_penerimaan', label: 'Cakupan (%)' },
    { key: 'jumlah_minggu_patut', label: 'Minggu Patuh' },
    { key: 'tingkat_kepatuhan', label: 'Kepatuhan (%)' },
    { key: 'valid', label: 'Valid' },
    { key: 'menunggu', label: 'Menunggu' },
    { key: 'tidak_valid', label: 'Tidak Valid' },
    { key: 'validasi_tertunda', label: 'Tertunda' },
];
const months = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];

function csrfToken() {
    const cookie = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.split('=').slice(1).join('=')) : '';
}

async function readError(response, fallback) {
    const payload = await response.json().catch(() => ({}));
    return Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? fallback;
}

async function loadOptions() {
    optionsLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await fetch('/api/laporan-rekap/options', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (! response.ok) throw new Error(await readError(response, 'Pilihan filter tidak dapat dimuat.'));
        options.value = (await response.json()).data;
        const activeYear = options.value.tahun_ajaran.find((year) => year.aktif)
            ?? options.value.tahun_ajaran[0];
        filters.tahun_ajaran_id = activeYear ? String(activeYear.id) : '';
        optionsReady.value = true;
        await loadReport();
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        optionsLoading.value = false;
    }
}

async function loadReport() {
    reportLoading.value = true;
    errorMessage.value = '';
    try {
        const params = new URLSearchParams();
        for (const [key, value] of Object.entries(filters)) {
            if (value) params.set(key, value);
        }
        const response = await fetch(`/api/laporan-rekap?${params}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (! response.ok) throw new Error(await readError(response, 'Laporan tidak dapat dimuat.'));
        report.value = await response.json();
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        reportLoading.value = false;
    }
}

function openIdentityConfirmation() {
    confirmIdentity.value = true;
    identityPassword.value = '';
    exportError.value = '';
}

function cancelIdentityExport() {
    confirmIdentity.value = false;
    identityPassword.value = '';
    exportError.value = '';
}

async function exportReport(type) {
    exportLoading.value = true;
    exportError.value = '';
    try {
        const response = await fetch('/api/laporan-rekap/ekspor', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                ...filters,
                jenis: type,
                ...(type === 'IDENTITAS' ? { kata_sandi: identityPassword.value } : {}),
            }),
        });
        if (! response.ok) throw new Error(await readError(response, 'Berkas laporan tidak dapat dibuat.'));
        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'laporan-ttd.xlsx';
        document.body.append(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
        if (type === 'IDENTITAS') cancelIdentityExport();
    } catch (error) {
        exportError.value = error.message;
    } finally {
        exportLoading.value = false;
    }
}

watch(filters, () => {
    if (optionsReady.value) loadReport();
}, { deep: true });

watch(() => filters.kelurahan_id, () => {
    if (filters.sekolah_id && ! schoolOptions.value.some((school) => String(school.id) === filters.sekolah_id)) {
        filters.sekolah_id = '';
    }
});

onMounted(loadOptions);
</script>

<template>
    <AppLayout>
        <div class="page-content reporting-page">
            <header class="page-heading">
                <div><p class="eyebrow">PEMANTAUAN PROGRAM</p><h1>Laporan &amp; Ekspor</h1></div>
                <span class="role-badge">{{ isAdministrator ? 'Administrator' : 'Admin Pustu' }}</span>
            </header>

            <p v-if="errorMessage" class="report-page-error" role="alert">{{ errorMessage }}</p>
            <p v-if="optionsLoading" class="report-dashboard__state" role="status">Memuat pilihan laporan...</p>

            <template v-else>
                <form class="report-page-filters" aria-label="Filter laporan" @submit.prevent>
                    <label for="report-year">Tahun ajaran
                        <select id="report-year" v-model="filters.tahun_ajaran_id">
                            <option value="">Semua tahun ajaran</option>
                            <option v-for="year in options.tahun_ajaran" :key="year.id" :value="String(year.id)">{{ year.label }}</option>
                        </select>
                    </label>
                    <label for="report-month">Bulan / periode
                        <select id="report-month" v-model="filters.bulan">
                            <option value="">Satu tahun ajaran</option>
                            <option v-for="(month, index) in months" :key="month" :value="String(index + 1)">{{ month }}</option>
                        </select>
                    </label>
                    <label for="report-village">Kelurahan
                        <select id="report-village" v-model="filters.kelurahan_id">
                            <option value="">Semua kelurahan</option>
                            <option v-for="village in options.kelurahan" :key="village.id" :value="String(village.id)">{{ village.nama }}</option>
                        </select>
                    </label>
                    <label for="report-school">Sekolah
                        <select id="report-school" v-model="filters.sekolah_id">
                            <option value="">Semua sekolah</option>
                            <option v-for="school in schoolOptions" :key="school.id" :value="String(school.id)">{{ school.nama }}</option>
                        </select>
                    </label>
                </form>

                <p v-if="reportLoading" class="report-dashboard__state" role="status">Memuat hasil laporan...</p>
                <template v-else-if="report">
                    <section class="report-dashboard" aria-labelledby="report-summary-title">
                        <header class="report-dashboard__heading">
                            <div><p class="eyebrow">{{ report.periode }}</p><h2 id="report-summary-title">Ringkasan kepatuhan</h2></div>
                        </header>
                        <div class="report-summary">
                            <div><span>Cakupan Penerimaan</span><strong>{{ report.ringkasan.cakupan_penerimaan }}%</strong><small>{{ report.ringkasan.jumlah_siswi_melapor }} dari {{ report.ringkasan.jumlah_siswi }} siswi melapor</small></div>
                            <div><span>Tingkat Kepatuhan</span><strong>{{ report.ringkasan.tingkat_kepatuhan }}%</strong><small>{{ report.ringkasan.jumlah_minggu_patut }} dari {{ report.ringkasan.jumlah_minggu_diharapkan }} minggu</small></div>
                            <div><span>Valid</span><strong>{{ report.ringkasan.valid }}</strong><small>Laporan diperiksa Guru</small></div>
                            <div class="report-summary__overdue"><span>Validasi Tertunda</span><strong>{{ report.ringkasan.validasi_tertunda }}</strong><small>Melewati batas waktu</small></div>
                        </div>
                        <div class="report-validation-row" aria-label="Rincian status validasi">
                            <span>Valid: {{ report.ringkasan.valid }}</span>
                            <span>Menunggu: {{ report.ringkasan.menunggu }}</span>
                            <span>Tidak valid: {{ report.ringkasan.tidak_valid }}</span>
                        </div>
                        <p class="report-dashboard__note">Laporan Menunggu dihitung sementara sebagai patuh. Data identitas tidak ditampilkan dalam ringkasan ini.</p>
                    </section>

                    <section class="report-dashboard__section" aria-labelledby="report-school-title">
                        <header class="report-dashboard__heading"><div><p class="eyebrow">RINCIAN CAKUPAN</p><h2 id="report-school-title">Perbandingan antar-sekolah</h2></div></header>
                        <DataTable :columns="columns" :rows="report.perbandingan_sekolah" label="Ringkasan laporan per sekolah">
                            <template #cell-cakupan_penerimaan="{ value }">{{ value }}%</template>
                            <template #cell-tingkat_kepatuhan="{ value }">{{ value }}%</template>
                        </DataTable>
                    </section>

                    <section class="report-dashboard__section" aria-labelledby="report-reasons-title">
                        <header class="report-dashboard__heading"><div><p class="eyebrow">RINGKASAN LAPORAN</p><h2 id="report-reasons-title">Alasan tidak minum</h2></div></header>
                        <ul v-if="report.alasan.length" class="report-overdue-list">
                            <li v-for="reason in report.alasan" :key="reason.nama"><strong>{{ reason.nama }}</strong><span>{{ reason.jumlah }} laporan</span></li>
                        </ul>
                        <p v-else class="report-dashboard__muted">Belum ada laporan tidak minum pada periode ini.</p>
                    </section>

                    <div class="report-export-actions">
                        <BaseButton :disabled="exportLoading" :loading="exportLoading" @click="exportReport('AGREGAT')">Unduh Rekap Agregat</BaseButton>
                        <BaseButton v-if="isAdministrator" variant="secondary" :disabled="exportLoading" @click="openIdentityConfirmation">Unduh Data Identitas</BaseButton>
                    </div>
                    <p v-if="exportError && !confirmIdentity" class="report-page-error" role="alert">{{ exportError }}</p>

                    <section v-if="confirmIdentity" class="report-identity-confirm" aria-labelledby="identity-export-title">
                        <h3 id="identity-export-title">Konfirmasi ekspor identitas</h3>
                        <p>Berkas ini memuat nama dan NISN Siswi sesuai filter yang dipilih. Pengunduhan dicatat pada riwayat akses data. Masukkan kata sandi Anda untuk melanjutkan.</p>
                        <label for="identity-export-password">Kata sandi
                            <input id="identity-export-password" v-model="identityPassword" type="password" minlength="8" maxlength="15" autocomplete="current-password" required>
                        </label>
                        <p v-if="exportError" class="report-page-error" role="alert">{{ exportError }}</p>
                        <div class="report-identity-confirm__actions">
                            <BaseButton :disabled="exportLoading || identityPassword.length < 8" :loading="exportLoading" @click="exportReport('IDENTITAS')">Konfirmasi dan Unduh</BaseButton>
                            <BaseButton variant="secondary" :disabled="exportLoading" @click="cancelIdentityExport">Batal</BaseButton>
                        </div>
                    </section>
                </template>
            </template>
        </div>
    </AppLayout>
</template>