<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import AppLayout from '../components/AppLayout.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseField from '../components/BaseField.vue';
import DataTable from '../components/DataTable.vue';
import ToastMessage from '../components/ToastMessage.vue';
import { useAuthStore } from '../stores/auth';

const props = defineProps({ view: { type: String, required: true }, distributionId: { type: String, default: '' } });
const auth = useAuthStore();
const router = useRouter();
const rows = ref([]);
const options = ref({});
const detail = ref(null);
const loading = ref(false);
const saving = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const showPackageForm = ref(false);
const form = reactive({
    nomor_batch: '', tgl_kadaluwarsa: '', jumlah_box: 1, isi_per_box: 100,
    tgl_terima: localDate(), sumber: '', batch_id: '', pustu_id: '', jumlah: 1,
    tgl_kirim: localDate(), catatan: '', sekolah_id: '', guru_penerima_id: '',
    tgl_serah_terima: localDate(), keterangan: '', items: [{ batch_id: '', jumlah_box: 1, keterangan: '' }],
    siswi_id: '', distribusi_sekolah_id: '', batch_paket_id: '', tgl_diterima: localDate(),
});

const labels = {
    'stok-masuk': 'Stok Masuk',
    'stok-masuk-form': 'Tambah Stok TTD',
    'pengiriman-pustu': 'Pengiriman ke Pustu',
    'pengiriman-pustu-form': 'Buat Pengiriman',
    'rekap-stok': 'Rekap Stok TTD',
    'penerimaan-puskesmas': 'Penerimaan dari Puskesmas',
    'stok-pustu': 'Stok Pustu',
    'serah-terima-sekolah': 'Serah Terima ke Sekolah',
    'serah-terima-form': 'Buat Serah Terima',
    'serah-terima-detail': 'Rincian Serah Terima',
    'penerimaan-pustu': 'Penerimaan dari Pustu',
    'penerimaan-detail': 'Rincian Penerimaan',
    'paket-siswi': 'Pembagian Paket TTD ke Siswi',
};
const title = computed(() => labels[props.view] ?? 'Obat TTD');
const isForm = computed(() => props.view.endsWith('-form'));
const isAdmin = computed(() => auth.user?.role === 'ADMINISTRATOR');
const isPustu = computed(() => auth.user?.role === 'ADMIN_PUSTU');
const isTeacher = computed(() => auth.user?.role === 'GURU');
const columns = computed(() => {
    if (props.view === 'stok-masuk') return [
        { key: 'nomor_batch', label: 'Nomor batch' }, { key: 'tgl_terima', label: 'Tanggal diterima' },
        { key: 'jumlah_box', label: 'Jumlah box' }, { key: 'isi_per_box', label: 'Tablet per box' },
        { key: 'jumlah_masuk', label: 'Jumlah tablet' }, { key: 'tgl_kadaluwarsa', label: 'Tanggal kedaluwarsa' },
    ];
    if (props.view === 'rekap-stok') return [
        { key: 'nama_pustu', label: 'Puskesmas pembantu' }, { key: 'nomor_batch', label: 'Nomor batch' },
        { key: 'jumlah', label: 'Stok (tablet)' }, { key: 'tgl_kadaluwarsa', label: 'Tanggal kedaluwarsa' },
    ];
    if (props.view === 'pengiriman-pustu' || props.view === 'penerimaan-puskesmas') return [
        { key: 'nomor_batch', label: 'Nomor batch' }, { key: 'nama_pustu', label: 'Pustu' },
        { key: 'jumlah', label: 'Jumlah tablet' }, { key: 'tgl_kirim', label: 'Tanggal kirim' },
        { key: 'status_label', label: 'Status' },
    ];
    if (props.view === 'stok-pustu') return [
        { key: 'nomor_batch', label: 'Nomor batch' }, { key: 'jumlah', label: 'Jumlah tablet' },
        { key: 'tgl_kadaluwarsa', label: 'Kedaluwarsa' }, { key: 'status_kadaluwarsa_label', label: 'Peringatan' },
    ];
    if (['serah-terima-sekolah', 'penerimaan-pustu'].includes(props.view)) return [
        { key: 'nomor_dokumen', label: 'Nomor dokumen' }, { key: 'tgl_serah_terima', label: 'Tanggal' },
        { key: 'nama_sekolah', label: 'Sekolah' }, { key: 'nama_guru', label: 'Guru penerima' },
        { key: 'status_label', label: 'Status' },
    ];
    return [
        { key: 'nama_siswi', label: 'Siswi' }, { key: 'nama_kelas', label: 'Kelas' },
        { key: 'nomor_batch', label: 'Nomor batch' }, { key: 'jumlah_tablet', label: 'Jumlah tablet' },
        { key: 'tgl_diterima', label: 'Tanggal diterima' },
    ];
});
const rowsForTable = computed(() => rows.value.map((row) => ({
    ...row,
    status_label: ({ PENDING: 'Menunggu penerimaan', COMPLETED: 'Selesai', DIBATALKAN: 'Dibatalkan' })[row.status] ?? row.status,
    status_kadaluwarsa_label: ({ AMAN: 'Aman', SEGERA_KEDALUWARSA: 'Segera kedaluwarsa', KEDALUWARSA: 'Kedaluwarsa' })[row.status_kadaluwarsa] ?? row.status_kadaluwarsa,
})));
const dateFormatter = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });

function localDate() {
    const date = new Date();
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 10);
}

function csrfToken() {
    const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.split('=').slice(1).join('=')) : '';
}

async function api(path, settings = {}) {
    const response = await fetch(path, {
        credentials: 'same-origin',
        ...settings,
        headers: {
            Accept: 'application/json',
            ...(settings.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
            ...(settings.method && settings.method !== 'GET' ? { 'X-XSRF-TOKEN': csrfToken() } : {}),
            ...settings.headers,
        },
    });
    const payload = await response.json();
    if (!response.ok) throw new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? 'Permintaan tidak dapat diproses.');
    return payload;
}

async function load() {
    loading.value = true;
    errorMessage.value = '';
    try {
        const endpoints = {
            'stok-masuk': '/api/obat-ttd/batch',
            'rekap-stok': '/api/obat-ttd/rekap-stok',
            'pengiriman-pustu': '/api/obat-ttd/pengiriman-pustu',
            'penerimaan-puskesmas': '/api/obat-ttd/pengiriman-pustu',
            'stok-pustu': '/api/obat-ttd/stok-pustu',
            'serah-terima-sekolah': '/api/obat-ttd/serah-terima-sekolah',
            'penerimaan-pustu': '/api/obat-ttd/serah-terima-sekolah',
            'paket-siswi': '/api/obat-ttd/paket-siswi',
        };
        if (props.view === 'serah-terima-detail' || props.view === 'penerimaan-detail') {
            detail.value = (await api(`/api/obat-ttd/serah-terima-sekolah/${props.distributionId}`)).data;
            return;
        }
        if (endpoints[props.view]) rows.value = (await api(endpoints[props.view])).data ?? [];
        if (isForm.value || props.view === 'paket-siswi') {
            options.value = (await api('/api/obat-ttd/pilihan')).data ?? {};
            if (isPustu.value && form.sekolah_id) await loadTeachers();
        }
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        loading.value = false;
    }
}

async function loadTeachers() {
    try {
        const payload = await api(`/api/obat-ttd/pilihan?sekolah_id=${encodeURIComponent(form.sekolah_id)}`);
        options.value.guru = payload.data.guru ?? [];
    } catch (error) {
        errorMessage.value = error.message;
    }
}

function addItem() {
    form.items.push({ batch_id: '', jumlah_box: 1, keterangan: '' });
}

function removeItem(index) {
    if (form.items.length > 1) form.items.splice(index, 1);
}

async function submitForm() {
    saving.value = true;
    errorMessage.value = '';
    successMessage.value = '';
    try {
        let path = '';
        let payload = {};
        if (props.view === 'stok-masuk-form') {
            path = '/api/obat-ttd/batch';
            payload = { ...form, jumlah_box: Number(form.jumlah_box), isi_per_box: Number(form.isi_per_box) };
        } else if (props.view === 'pengiriman-pustu-form') {
            path = '/api/obat-ttd/pengiriman-pustu';
            payload = { ...form, batch_id: Number(form.batch_id), pustu_id: Number(form.pustu_id), jumlah: Number(form.jumlah) };
        } else {
            path = '/api/obat-ttd/serah-terima-sekolah';
            payload = {
                ...form,
                sekolah_id: Number(form.sekolah_id),
                guru_penerima_id: Number(form.guru_penerima_id),
                items: form.items.map((item) => ({ ...item, batch_id: Number(item.batch_id), jumlah_box: Number(item.jumlah_box) })),
            };
        }
        await api(path, { method: 'POST', body: JSON.stringify(payload) });
        successMessage.value = 'Data berhasil disimpan.';
        await router.push({ name: props.view === 'stok-masuk-form' ? 'obat-stok-masuk' : props.view === 'pengiriman-pustu-form' ? 'obat-pengiriman-pustu' : 'obat-serah-terima' });
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        saving.value = false;
    }
}

async function runAction(row, action) {
    errorMessage.value = '';
    successMessage.value = '';
    try {
        if (action === 'konfirmasi-pustu') {
            await api(`/api/obat-ttd/pengiriman-pustu/${row.id}/konfirmasi`, { method: 'PATCH', body: '{}' });
            successMessage.value = 'Penerimaan stok berhasil dikonfirmasi.';
        } else if (action === 'detail') {
            await router.push({ name: isTeacher.value ? 'obat-penerimaan-detail' : 'obat-serah-terima-detail', params: { distributionId: row.id } });
            return;
        } else if (action === 'paket') {
            showPackageForm.value = true;
            form.distribusi_sekolah_id = String(row.id);
            return;
        }
        await load();
    } catch (error) {
        errorMessage.value = error.message;
    }
}

async function confirmSchoolReceipt() {
    saving.value = true;
    try {
        await api(`/api/obat-ttd/serah-terima-sekolah/${props.distributionId}/konfirmasi`, { method: 'PATCH', body: '{}' });
        successMessage.value = 'Penerimaan TTD berhasil dikonfirmasi.';
        await load();
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        saving.value = false;
    }
}

async function cancelSchoolDelivery() {
    saving.value = true;
    try {
        await api(`/api/obat-ttd/serah-terima-sekolah/${props.distributionId}/batalkan`, { method: 'PATCH', body: '{}' });
        successMessage.value = 'Serah-terima dibatalkan dan stok disesuaikan.';
        await load();
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        saving.value = false;
    }
}

async function printDocument() {
    try {
        await api(`/api/obat-ttd/serah-terima-sekolah/${props.distributionId}/cetak`, { method: 'POST', body: '{}' });
        window.print();
    } catch (error) {
        errorMessage.value = error.message;
    }
}

async function submitPackage() {
    saving.value = true;
    try {
        await api('/api/obat-ttd/paket-siswi', {
            method: 'POST',
            body: JSON.stringify({
                siswi_id: Number(form.siswi_id),
                distribusi_sekolah_id: Number(form.distribusi_sekolah_id),
                batch_id: Number(form.batch_paket_id),
                tgl_diterima: form.tgl_diterima,
            }),
        });
        showPackageForm.value = false;
        successMessage.value = 'Pembagian paket 52 tablet berhasil dicatat.';
        form.siswi_id = '';
        form.batch_paket_id = '';
        await load();
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        saving.value = false;
    }
}

function formatDate(value) {
    return value ? dateFormatter.format(new Date(`${value}T00:00:00`)) : '—';
}

watch(() => [props.view, props.distributionId], () => {
    rows.value = [];
    detail.value = null;
    errorMessage.value = '';
    successMessage.value = '';
    showPackageForm.value = false;
    load();
});

watch(() => form.sekolah_id, (schoolId) => {
    if (schoolId && isPustu.value) {
        form.guru_penerima_id = '';
        loadTeachers();
    }
});

onMounted(load);
</script>

<template>
    <AppLayout>
        <div class="page-content supply-page" :class="{ 'supply-page--print': view === 'serah-terima-detail' }">
            <header class="page-heading">
                <div><p class="eyebrow">RANTAI PASOK OBAT TTD</p><h1>{{ title }}</h1></div>
                <div v-if="['stok-masuk', 'pengiriman-pustu', 'serah-terima-sekolah'].includes(view)" class="supply-page__heading-actions">
                    <RouterLink v-if="view === 'stok-masuk'" class="base-button base-button--primary" to="/obat-ttd/stok-masuk/tambah">Tambah Stok</RouterLink>
                    <RouterLink v-if="view === 'pengiriman-pustu'" class="base-button base-button--primary" to="/obat-ttd/pengiriman-pustu/buat">Buat Pengiriman</RouterLink>
                    <RouterLink v-if="view === 'serah-terima-sekolah'" class="base-button base-button--primary" to="/obat-ttd/serah-terima-sekolah/buat">Buat Serah Terima</RouterLink>
                </div>
            </header>

            <ToastMessage v-if="errorMessage" :message="errorMessage" type="error" />
            <ToastMessage v-if="successMessage" :message="successMessage" type="success" />
            <p v-if="loading" class="supply-state" role="status">Memuat data...</p>

            <template v-if="isForm">
                <form v-if="view === 'stok-masuk-form'" class="supply-form" @submit.prevent="submitForm">
                    <div class="supply-form__grid">
                        <BaseField id="batch-number" v-model="form.nomor_batch" label="Nomor batch" required maxlength="50" />
                        <label class="management-field"><span>Tanggal kedaluwarsa</span><input v-model="form.tgl_kadaluwarsa" type="date" required></label>
                        <BaseField id="box-count" v-model="form.jumlah_box" label="Jumlah box" type="number" min="1" required />
                        <BaseField id="box-quantity" v-model="form.isi_per_box" label="Isi setiap box (tablet)" type="number" min="1" required />
                        <label class="management-field"><span>Tanggal diterima</span><input v-model="form.tgl_terima" type="date" required></label>
                        <BaseField id="batch-source" v-model="form.sumber" label="Sumber penerimaan" maxlength="150" />
                    </div>
                    <p class="supply-form__hint">Jumlah masuk dihitung otomatis dalam satuan tablet.</p>
                    <div class="supply-form__actions"><RouterLink class="base-button base-button--secondary" to="/obat-ttd/stok-masuk">Batal</RouterLink><BaseButton type="submit" :loading="saving">Simpan</BaseButton></div>
                </form>

                <form v-else-if="view === 'pengiriman-pustu-form'" class="supply-form" @submit.prevent="submitForm">
                    <div class="supply-form__grid">
                        <label class="management-field"><span>Nomor batch</span><select v-model="form.batch_id" required><option value="" disabled>Pilih batch</option><option v-for="batch in options.batch" :key="batch.id" :value="batch.id">{{ batch.nomor_batch }} · {{ batch.jumlah_masuk }} tablet</option></select></label>
                        <label class="management-field"><span>Puskesmas pembantu</span><select v-model="form.pustu_id" required><option value="" disabled>Pilih Pustu</option><option v-for="item in options.pustu" :key="item.id" :value="item.id">{{ item.nama }}</option></select></label>
                        <BaseField id="shipment-quantity" v-model="form.jumlah" label="Jumlah tablet" type="number" min="1" required />
                        <label class="management-field"><span>Tanggal kirim</span><input v-model="form.tgl_kirim" type="date" required></label>
                        <BaseField id="shipment-note" v-model="form.catatan" label="Keterangan" maxlength="1000" />
                    </div>
                    <p class="supply-form__hint">Stok batch akan dicadangkan saat pengiriman dibuat dan baru dicatat sebagai stok Pustu setelah penerimaan dikonfirmasi.</p>
                    <div class="supply-form__actions"><RouterLink class="base-button base-button--secondary" to="/obat-ttd/pengiriman-pustu">Batal</RouterLink><BaseButton type="submit" :loading="saving">Kirim</BaseButton></div>
                </form>

                <form v-else class="supply-form" @submit.prevent="submitForm">
                    <div class="supply-form__grid">
                        <label class="management-field"><span>Sekolah tujuan</span><select v-model="form.sekolah_id" required><option value="" disabled>Pilih sekolah binaan</option><option v-for="school in options.sekolah" :key="school.id" :value="school.id">{{ school.nama }}</option></select></label>
                        <label class="management-field"><span>Guru penerima</span><select v-model="form.guru_penerima_id" required><option value="" disabled>Pilih guru dari sekolah</option><option v-for="teacher in options.guru" :key="teacher.id" :value="teacher.id">{{ teacher.nama }}</option></select></label>
                        <label class="management-field"><span>Tanggal serah-terima</span><input v-model="form.tgl_serah_terima" type="date" required></label>
                        <BaseField id="delivery-note" v-model="form.keterangan" label="Keterangan" maxlength="2000" />
                    </div>
                    <div class="supply-items">
                        <div class="supply-items__heading"><h2>Barang yang diserahkan</h2><BaseButton type="button" variant="secondary" @click="addItem">Tambah batch</BaseButton></div>
                        <div v-for="(item, index) in form.items" :key="index" class="supply-item-row">
                            <label class="management-field"><span>Nomor batch</span><select v-model="item.batch_id" required><option value="" disabled>Pilih batch</option><option v-for="batch in options.batch" :key="batch.id" :value="batch.id">{{ batch.nomor_batch }} · {{ batch.jumlah }} tablet tersedia</option></select></label>
                            <BaseField :id="`delivery-boxes-${index}`" v-model="item.jumlah_box" label="Jumlah box" type="number" min="1" required />
                            <BaseField :id="`delivery-item-note-${index}`" v-model="item.keterangan" label="Keterangan barang" maxlength="255" />
                            <BaseButton type="button" variant="secondary" :disabled="form.items.length === 1" @click="removeItem(index)">Hapus</BaseButton>
                        </div>
                    </div>
                    <p class="supply-form__hint">Volume tablet dihitung dari jumlah box dan isi per box pada batch. Nomor dokumen dibuat otomatis.</p>
                    <div class="supply-form__actions"><RouterLink class="base-button base-button--secondary" to="/obat-ttd/serah-terima-sekolah">Batal</RouterLink><BaseButton type="submit" :loading="saving">Kirim</BaseButton></div>
                </form>
            </template>

            <section v-else-if="view === 'serah-terima-detail' || view === 'penerimaan-detail'" class="supply-detail">
                <div v-if="detail" class="supply-document">
                    <header class="supply-document__heading"><div><p class="eyebrow">DOKUMEN SERAH TERIMA TTD</p><h2>{{ detail.dokumen.nomor_dokumen }}</h2></div><span class="status-label">{{ detail.dokumen.status === 'PENDING' ? 'Menunggu penerimaan' : detail.dokumen.status === 'COMPLETED' ? 'Selesai' : 'Dibatalkan' }}</span></header>
                    <dl class="supply-document__facts">
                        <div><dt>Tanggal</dt><dd>{{ formatDate(detail.dokumen.tgl_serah_terima) }}</dd></div>
                        <div><dt>Nama petugas</dt><dd>{{ detail.dokumen.nama_petugas }}</dd></div>
                        <div><dt>Nama guru penerima</dt><dd>{{ detail.dokumen.nama_guru }}</dd></div>
                        <div><dt>Asal sekolah</dt><dd>{{ detail.dokumen.nama_sekolah }}</dd></div>
                        <div><dt>Keterangan</dt><dd>{{ detail.dokumen.keterangan || '—' }}</dd></div>
                    </dl>
                    <DataTable :columns="[{ key: 'nama_barang', label: 'Nama barang' }, { key: 'nomor_batch', label: 'Nomor batch' }, { key: 'jumlah_box', label: 'Jumlah box' }, { key: 'isi_per_box', label: 'Isi per box' }, { key: 'jumlah_tablet', label: 'Volume (tablet)' }, { key: 'keterangan', label: 'Keterangan' }]" :rows="detail.items" label="Rincian barang yang diserahkan" />
                    <div class="supply-form__actions">
                        <RouterLink class="base-button base-button--secondary" :to="view === 'penerimaan-detail' ? '/obat-ttd/penerimaan-pustu' : '/obat-ttd/serah-terima-sekolah'">Kembali</RouterLink>
                        <BaseButton v-if="view === 'penerimaan-detail' && isTeacher && detail.dokumen.status === 'PENDING'" :loading="saving" @click="confirmSchoolReceipt">Konfirmasi Penerimaan</BaseButton>
                        <BaseButton v-if="view === 'serah-terima-detail' && isPustu" variant="secondary" @click="printDocument">Cetak / Simpan PDF</BaseButton>
                        <BaseButton v-if="view === 'serah-terima-detail' && isPustu && detail.dokumen.status !== 'DIBATALKAN'" variant="secondary" :loading="saving" @click="cancelSchoolDelivery">Batalkan Dokumen</BaseButton>
                    </div>
                </div>
            </section>

            <section v-else-if="view === 'paket-siswi'" class="supply-list-section">
                <div class="supply-list-section__heading"><p class="supply-list-section__context">Satu paket berisi 52 tablet dan hanya dapat dicatat sekali per Siswi dalam satu tahun ajaran.</p><BaseButton type="button" @click="showPackageForm = !showPackageForm">{{ showPackageForm ? 'Tutup Form' : 'Catat Pembagian' }}</BaseButton></div>
                <form v-if="showPackageForm" class="supply-inline-form" @submit.prevent="submitPackage">
                    <label class="management-field"><span>Siswi</span><select v-model="form.siswi_id" required><option value="" disabled>Pilih Siswi</option><option v-for="student in options.siswi" :key="student.id" :value="student.id">{{ student.nama }} · NISN {{ student.nisn_tersamar }}</option></select></label>
                    <label class="management-field"><span>Dokumen serah-terima</span><select v-model="form.distribusi_sekolah_id" required><option value="" disabled>Pilih dokumen</option><option v-for="delivery in options.serah_terima" :key="`${delivery.id}-${delivery.batch_id}`" :value="delivery.id">{{ delivery.nomor_dokumen }} · {{ delivery.nomor_batch }}</option></select></label>
                    <label class="management-field"><span>Batch</span><select v-model="form.batch_paket_id" required><option value="" disabled>Pilih batch</option><option v-for="delivery in options.serah_terima.filter((item) => String(item.id) === String(form.distribusi_sekolah_id))" :key="delivery.batch_id" :value="delivery.batch_id">{{ delivery.nomor_batch }}</option></select></label>
                    <label class="management-field"><span>Tanggal diterima</span><input v-model="form.tgl_diterima" type="date" required></label>
                    <BaseButton type="submit" :loading="saving">Simpan Pembagian</BaseButton>
                </form>
                <DataTable :columns="columns" :rows="rowsForTable" label="Daftar pembagian paket TTD" />
            </section>

            <section v-else class="supply-list-section">
                <p v-if="!loading && rows.length === 0" class="supply-state">Belum ada data untuk ditampilkan.</p>
                <DataTable v-else :columns="columns" :rows="rowsForTable" :label="`Daftar ${title}`" :show-actions="['penerimaan-puskesmas', 'serah-terima-sekolah', 'penerimaan-pustu'].includes(view)" >
                    <template #cell-tgl_terima="{ value }">{{ formatDate(value) }}</template>
                    <template #cell-tgl_kadaluwarsa="{ value }">{{ formatDate(value) }}</template>
                    <template #cell-tgl_kirim="{ value }">{{ formatDate(value) }}</template>
                    <template #cell-tgl_serah_terima="{ value }">{{ formatDate(value) }}</template>
                    <template #cell-status_label="{ row }"><span class="status-label" :class="row.status === 'COMPLETED' ? 'status-label--success' : row.status === 'DIBATALKAN' ? 'status-label--invalid' : 'status-label--pending'">{{ row.status_label }}</span></template>
                    <template #cell-status_kadaluwarsa_label="{ row }"><span class="status-label" :class="row.status_kadaluwarsa === 'KEDALUWARSA' ? 'status-label--invalid' : row.status_kadaluwarsa === 'SEGERA_KEDALUWARSA' ? 'status-label--pending' : 'status-label--success'">{{ row.status_kadaluwarsa_label }}</span></template>
                    <template #actions="{ row }">
                        <div class="table-actions">
                            <BaseButton v-if="view === 'penerimaan-puskesmas' && row.status === 'PENDING'" type="button" @click="runAction(row, 'konfirmasi-pustu')">Konfirmasi Penerimaan</BaseButton>
                            <BaseButton v-if="['serah-terima-sekolah', 'penerimaan-pustu'].includes(view)" type="button" variant="secondary" @click="runAction(row, 'detail')">Lihat Rincian</BaseButton>
                        </div>
                    </template>
                </DataTable>
            </section>
        </div>
    </AppLayout>
</template>
