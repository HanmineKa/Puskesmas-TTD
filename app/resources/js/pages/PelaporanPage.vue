<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AppLayout from '../components/AppLayout.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseField from '../components/BaseField.vue';
import BaseModal from '../components/BaseModal.vue';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const loading = ref(false);
const saving = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const dashboard = ref({ ringkasan: {}, data: [] });
const reasons = ref([]);
const history = ref([]);
const selectedReport = ref(null);
const selectedStudent = ref(null);
const reviewNote = ref('');
const reviewFilter = ref('SEMUA');
const isAdministrator = computed(() => auth.user?.role === 'ADMINISTRATOR');
const isTeacher = computed(() => auth.user?.role === 'GURU');
const roleLabel = computed(() => isAdministrator.value ? 'Administrator' : isTeacher.value ? 'Guru' : 'Siswi');
const reportSettings = reactive({ batas_hari_validasi: 7, jeda_pengingat_hari: 7 });
const mode = ref('SUDAH');
const evidenceFile = ref(null);
const retryFile = ref(null);
const reportForm = reactive({ waktu_minum: localDateTime(), alasan_id: '', catatan: '' });
const exceptionForm = reactive({ detail: '', tgl_mulai: new Date().toISOString().slice(0, 10), tgl_selesai: '' });

const filteredRows = computed(() => dashboard.value.data.filter((row) => (
    reviewFilter.value === 'SEMUA' || row.status_validasi === reviewFilter.value
)));

function localDateTime() {
    const date = new Date();
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 16);
}

function csrfToken() {
    const cookie = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.split('=').slice(1).join('=')) : '';
}

async function api(path, options = {}) {
    const response = await fetch(path, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
            ...(options.method && options.method !== 'GET' ? { 'X-XSRF-TOKEN': csrfToken() } : {}),
            ...options.headers,
        },
    });
    const payload = await response.json();
    if (! response.ok) {
        throw new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? 'Permintaan tidak dapat diproses.');
    }
    return payload;
}

async function load() {
    loading.value = true;
    errorMessage.value = '';
    try {
        if (isAdministrator.value) {
            const settings = await api('/api/pengaturan/pelaporan');
            Object.assign(reportSettings, settings.data);
        } else if (isTeacher.value) {
            dashboard.value = await api('/api/laporan-mingguan');
        } else {
            const [reasonPayload, historyPayload] = await Promise.all([
                api('/api/alasan-tidak-minum'),
                api('/api/laporan-mingguan/saya'),
            ]);
            reasons.value = reasonPayload.data ?? [];
            history.value = historyPayload.data ?? [];
        }
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        loading.value = false;
    }
}

async function saveSettings() {
    errorMessage.value = '';
    successMessage.value = '';
    saving.value = true;
    try {
        const result = await api('/api/pengaturan/pelaporan', {
            method: 'PUT',
            body: JSON.stringify(reportSettings),
        });
        Object.assign(reportSettings, result.data);
        successMessage.value = 'Pengaturan pelaporan berhasil disimpan.';
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        saving.value = false;
    }
}

function chooseEvidence(event) {
    evidenceFile.value = event.target.files?.[0] ?? null;
}

function chooseRetryEvidence(event) {
    retryFile.value = event.target.files?.[0] ?? null;
}

async function submitReport() {
    errorMessage.value = '';
    successMessage.value = '';
    saving.value = true;
    try {
        const formData = new FormData();
        formData.append('status_minum', mode.value);
        formData.append('catatan', reportForm.catatan);
        if (mode.value === 'SUDAH') {
            formData.append('waktu_minum', reportForm.waktu_minum.replace('T', ' ') + ':00');
            if (evidenceFile.value) formData.append('foto', evidenceFile.value);
        } else if (reportForm.alasan_id) {
            formData.append('alasan_id', reportForm.alasan_id);
        }
        await api('/api/laporan-mingguan', { method: 'POST', body: formData });
        successMessage.value = 'Laporan mingguan berhasil dikirim.';
        reportForm.catatan = '';
        reportForm.alasan_id = '';
        reportForm.waktu_minum = localDateTime();
        evidenceFile.value = null;
        await load();
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        saving.value = false;
    }
}

async function resubmit(report) {
    errorMessage.value = '';
    successMessage.value = '';
    if (! retryFile.value) {
        errorMessage.value = 'Pilih foto bukti yang baru untuk diunggah ulang.';
        return;
    }
    saving.value = true;
    try {
        const formData = new FormData();
        formData.append('foto', retryFile.value);
        await api(`/api/laporan-mingguan/${report.id}/unggah-ulang`, { method: 'POST', body: formData });
        successMessage.value = 'Foto bukti berhasil diunggah ulang.';
        retryFile.value = null;
        await load();
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        saving.value = false;
    }
}

function openReport(row) {
    selectedReport.value = row;
    reviewNote.value = '';
}

async function validateReport(status) {
    errorMessage.value = '';
    successMessage.value = '';
    if (status === 'TIDAK_VALID' && ! reviewNote.value.trim()) {
        errorMessage.value = 'Catatan diperlukan jika foto tidak valid.';
        return;
    }
    saving.value = true;
    try {
        await api(`/api/laporan-mingguan/${selectedReport.value.laporan_id}/validasi`, {
            method: 'PATCH',
            body: JSON.stringify({
                status_validasi: status,
                ...(status === 'TIDAK_VALID' ? { catatan_validasi: reviewNote.value } : {}),
            }),
        });
        successMessage.value = status === 'VALID' ? 'Foto laporan dinyatakan valid.' : 'Foto ditolak dan Siswi diberi pemberitahuan.';
        selectedReport.value = null;
        await load();
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        saving.value = false;
    }
}

async function remindStudent(row) {
    errorMessage.value = '';
    successMessage.value = '';
    try {
        await api(`/api/siswi/${row.siswi_id}/pengingat`, { method: 'POST', body: JSON.stringify({}) });
        successMessage.value = 'Pengingat berhasil dikirim kepada Siswi.';
    } catch (error) {
        errorMessage.value = error.message;
    }
}

function openException(row) {
    selectedStudent.value = row;
    exceptionForm.detail = '';
    exceptionForm.tgl_mulai = new Date().toISOString().slice(0, 10);
    exceptionForm.tgl_selesai = '';
}

async function markException() {
    errorMessage.value = '';
    successMessage.value = '';
    saving.value = true;
    try {
        await api('/api/pengecualian-medis', {
            method: 'POST',
            body: JSON.stringify({
                siswi_id: selectedStudent.value.siswi_id,
                detail: exceptionForm.detail,
                tgl_mulai: exceptionForm.tgl_mulai,
                ...(exceptionForm.tgl_selesai ? { tgl_selesai: exceptionForm.tgl_selesai } : {}),
            }),
        });
        successMessage.value = 'Pengecualian medis berhasil dicatat.';
        selectedStudent.value = null;
        await load();
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        saving.value = false;
    }
}

function statusLabel(status) {
    return ({ MENUNGGU: 'Menunggu validasi', VALID: 'Valid', TIDAK_VALID: 'Tidak valid' })[status] ?? status ?? 'Belum melapor';
}

function reportValidationLabel(report) {
    return report.status_minum === 'TIDAK' ? 'Tidak perlu validasi' : statusLabel(report.status_validasi);
}

function formatDate(value) {
    if (! value) return 'Belum tersedia';
    return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(value));
}

onMounted(load);
</script>

<template>
    <AppLayout>
        <div class="page-content reporting-page">
            <header class="page-heading">
                <div>
                    <p class="eyebrow">PROGRAM TTD</p>
                    <h1>{{ isTeacher ? 'Pemantauan Mingguan' : 'Laporan Mingguan TTD' }}</h1>
                </div>
                    <span class="role-badge">{{ roleLabel }}</span>
            </header>

            <p v-if="errorMessage" class="management-error" role="alert">{{ errorMessage }}</p>
            <p v-if="successMessage" class="management-success" role="status">{{ successMessage }}</p>
            <p v-if="loading" class="management-state" role="status">Memuat laporan...</p>

            <template v-if="isAdministrator">
                <section class="stage-section report-settings-section">
                    <div class="stage-section__heading"><div><p class="eyebrow">PENGINGAT VALIDASI</p><h2>Pengaturan pelaporan</h2></div></div>
                    <form class="management-form" @submit.prevent="saveSettings">
                        <label class="management-field"><span>Batas hari sebelum validasi tertunda</span><input v-model.number="reportSettings.batas_hari_validasi" type="number" min="1" max="30" required></label>
                        <label class="management-field"><span>Jeda pengingat ulang (hari)</span><input v-model.number="reportSettings.jeda_pengingat_hari" type="number" min="1" max="30" required></label>
                        <BaseButton type="submit" :disabled="saving">Simpan pengaturan</BaseButton>
                    </form>
                </section>
            </template>

            <template v-else-if="isTeacher">
                <section class="report-summary" aria-label="Ringkasan minggu berjalan">
                    <div><span>Minggu ke-{{ dashboard.ringkasan.minggu_ke ?? '—' }}</span><strong>{{ dashboard.ringkasan.jumlah_siswi ?? 0 }}</strong><small>Siswi aktif</small></div>
                    <div><span>Kepatuhan sementara</span><strong>{{ dashboard.ringkasan.persentase_kepatuhan ?? 0 }}%</strong><small>{{ dashboard.ringkasan.jumlah_melapor ?? 0 }} laporan minggu ini</small></div>
                    <div><span>Menunggu validasi</span><strong>{{ dashboard.ringkasan.menunggu ?? 0 }}</strong><small>{{ dashboard.ringkasan.valid ?? 0 }} valid · {{ dashboard.ringkasan.tidak_valid ?? 0 }} tidak valid</small></div>
                    <div class="report-summary__overdue"><span>Validasi tertunda</span><strong>{{ dashboard.ringkasan.validasi_tertunda ?? 0 }}</strong><small>Lebih dari batas hari</small></div>
                </section>

                <section class="stage-section">
                    <div class="stage-section__heading">
                        <div><p class="eyebrow">MINGGU BERJALAN</p><h2>Pemantauan Siswi</h2></div>
                        <label class="report-filter"><span>Validasi</span><select v-model="reviewFilter"><option value="SEMUA">Semua</option><option value="MENUNGGU">Menunggu</option><option value="VALID">Valid</option><option value="TIDAK_VALID">Tidak valid</option></select></label>
                    </div>
                    <div class="data-table-scroll" role="region" aria-label="Pemantauan laporan Siswi" tabindex="0">
                        <table class="data-table reporting-table">
                            <thead><tr><th>Siswi</th><th>NISN</th><th>Kelas</th><th>Laporan</th><th>Validasi</th><th>Alasan</th><th>Aksi</th></tr></thead>
                            <tbody>
                                <tr v-for="row in filteredRows" :key="row.enrollment_id">
                                    <td>{{ row.nama }}</td><td>{{ row.nisn_tersamar }}</td><td>{{ row.kelas }}</td>
                                    <td>{{ row.status_minum === 'SUDAH' ? 'Sudah minum' : row.status_minum === 'TIDAK' ? 'Belum minum' : 'Belum melapor' }}</td>
                                    <td><span class="status-label" :class="{ 'status-label--pending': row.status_validasi === 'MENUNGGU', 'status-label--invalid': row.status_validasi === 'TIDAK_VALID' }">{{ statusLabel(row.status_validasi) }}</span></td>
                                    <td>{{ row.alasan ?? '—' }}</td>
                                    <td><div class="table-actions"><BaseButton v-if="row.foto_url" type="button" variant="secondary" @click="openReport(row)">Periksa foto</BaseButton><BaseButton v-if="!row.status_minum" type="button" variant="secondary" @click="remindStudent(row)">Kirim pengingat</BaseButton><BaseButton type="button" variant="secondary" @click="openException(row)">Pengecualian medis</BaseButton></div></td>
                                </tr>
                                <tr v-if="!filteredRows.length"><td colspan="7" class="data-table__empty">Tidak ada laporan untuk filter ini.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <BaseModal v-if="selectedReport" :model-value="true" title="Validasi foto bukti" @update:model-value="selectedReport = null">
                    <div class="report-review">
                        <img :src="selectedReport.foto_url" alt="Foto bukti tablet atau kemasan TTD">
                        <dl><div><dt>Siswi</dt><dd>{{ selectedReport.nama }}</dd></div><div><dt>NISN</dt><dd>{{ selectedReport.nisn_tersamar }}</dd></div><div><dt>Kelas</dt><dd>{{ selectedReport.kelas }}</dd></div></dl>
                        <label class="management-field"><span>Catatan jika foto tidak valid</span><textarea v-model="reviewNote" rows="3" maxlength="500" placeholder="Jelaskan foto yang perlu diperbaiki"></textarea></label>
                    </div>
                    <template #footer><BaseButton variant="secondary" @click="selectedReport = null">Tutup</BaseButton><BaseButton variant="secondary" :disabled="saving" @click="validateReport('TIDAK_VALID')">Tolak foto</BaseButton><BaseButton :disabled="saving" @click="validateReport('VALID')">Tandai valid</BaseButton></template>
                </BaseModal>

                <BaseModal v-if="selectedStudent" :model-value="true" title="Pengecualian medis" @update:model-value="selectedStudent = null">
                    <form class="management-form" @submit.prevent="markException">
                        <p>Catatan ini disimpan terenkripsi dan hanya digunakan untuk tindak lanjut kesehatan.</p>
                        <label class="management-field"><span>Detail kondisi</span><textarea v-model="exceptionForm.detail" rows="4" maxlength="1000" required></textarea></label>
                        <label class="management-field"><span>Tanggal mulai</span><input v-model="exceptionForm.tgl_mulai" type="date" required></label>
                        <label class="management-field"><span>Tanggal selesai (opsional)</span><input v-model="exceptionForm.tgl_selesai" type="date"></label>
                        <BaseButton type="submit" :disabled="saving">Simpan pengecualian</BaseButton>
                    </form>
                </BaseModal>
            </template>

            <template v-else>
                <section class="stage-section report-form-section">
                    <div class="stage-section__heading"><div><p class="eyebrow">LAPORAN MINGGU INI</p><h2>Catat konsumsi TTD</h2></div></div>
                    <form class="report-form" @submit.prevent="submitReport">
                        <fieldset class="report-mode">
                            <legend>Status konsumsi</legend>
                            <label><input v-model="mode" type="radio" value="SUDAH"><span>Sudah minum</span></label>
                            <label><input v-model="mode" type="radio" value="TIDAK"><span>Belum minum</span></label>
                        </fieldset>
                        <template v-if="mode === 'SUDAH'">
                            <BaseField id="report-time" v-model="reportForm.waktu_minum" type="datetime-local" label="Tanggal dan jam minum" required />
                            <label class="management-field"><span>Foto bukti tablet atau kemasan TTD</span><input type="file" accept="image/jpeg,image/png,image/webp" required @change="chooseEvidence"><small>Foto tablet atau kemasan saja, bukan wajah. Maksimal 5 MB.</small></label>
                        </template>
                        <template v-else>
                            <label class="management-field"><span>Alasan</span><select v-model="reportForm.alasan_id" required><option value="" disabled>Pilih alasan</option><option v-for="reason in reasons" :key="reason.id" :value="reason.id">{{ reason.nama }}</option></select></label>
                        </template>
                        <BaseField id="report-note" v-model="reportForm.catatan" label="Catatan (opsional)" :max-length="1000" />
                        <BaseButton type="submit" :disabled="saving">Kirim laporan</BaseButton>
                    </form>
                </section>

                <section class="stage-section">
                    <div class="stage-section__heading"><div><p class="eyebrow">RIWAYAT PRIBADI</p><h2>Laporan sebelumnya</h2></div></div>
                    <ol class="report-history">
                        <li v-for="report in history" :key="report.id">
                            <div><strong>Minggu ke-{{ report.minggu_ke }}</strong><span>{{ report.status_minum === 'SUDAH' ? 'Sudah minum' : `Belum minum · ${report.alasan ?? 'Alasan tercatat'}` }}</span><small>{{ formatDate(report.waktu_minum) }}</small></div>
                            <div class="report-history__status"><span class="status-label" :class="{ 'status-label--pending': report.status_validasi === 'MENUNGGU', 'status-label--invalid': report.status_validasi === 'TIDAK_VALID' }">{{ reportValidationLabel(report) }}</span><p v-if="report.catatan_validasi">{{ report.catatan_validasi }}</p><label v-if="report.dapat_unggah_ulang" class="report-retry"><span>Foto baru</span><input type="file" accept="image/jpeg,image/png,image/webp" @change="chooseRetryEvidence"><BaseButton type="button" variant="secondary" :disabled="saving" @click="resubmit(report)">Unggah ulang</BaseButton></label></div>
                        </li>
                        <li v-if="!history.length" class="report-history__empty">Belum ada laporan mingguan.</li>
                    </ol>
                </section>
            </template>
        </div>
    </AppLayout>
</template>
