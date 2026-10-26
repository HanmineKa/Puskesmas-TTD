<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AppLayout from '../components/AppLayout.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseField from '../components/BaseField.vue';
import DataTable from '../components/DataTable.vue';
import ToastMessage from '../components/ToastMessage.vue';

const props = defineProps({ section: { type: String, default: 'kecamatan' } });
const activeTab = ref(props.section);
const kecamatanRows = ref([]);
const kelurahanRows = ref([]);
const yearRows = ref([]);
const message = ref('');
const errorMessage = ref('');
const editId = ref(null);
const form = reactive({ kecamatan_id: '', nama: '', kode: '', label: '', tgl_mulai: '', tgl_selesai: '', aktif: false });
const pageTitle = computed(() => ({
    kecamatan: 'Kecamatan',
    kelurahan: 'Kelurahan',
    'tahun-ajaran': 'Tahun Ajaran',
}[activeTab.value] ?? 'Wilayah'));
const columns = computed(() => activeTab.value === 'kecamatan'
    ? [{ key: 'nama', label: 'Kecamatan' }, { key: 'kode', label: 'Kode' }, { key: 'kelurahan_count', label: 'Kelurahan' }]
    : activeTab.value === 'kelurahan'
        ? [{ key: 'nama', label: 'Kelurahan' }, { key: 'kecamatan', label: 'Kecamatan' }, { key: 'kode', label: 'Kode' }]
        : [{ key: 'label', label: 'Tahun Ajaran' }, { key: 'tgl_mulai', label: 'Mulai' }, { key: 'tgl_selesai', label: 'Selesai' }, { key: 'status_label', label: 'Status' }]);
const rows = computed(() => activeTab.value === 'kecamatan'
    ? kecamatanRows.value
    : activeTab.value === 'kelurahan' ? kelurahanRows.value : yearRows.value);

function csrfToken() {
    const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.split('=').slice(1).join('=')) : '';
}

async function api(path, options = {}) {
    const response = await fetch(path, {
        credentials: 'same-origin',
        ...options,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken(), ...options.headers },
    });
    const payload = await response.json();
    if (! response.ok) throw new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? 'Permintaan tidak dapat diproses.');
    return payload;
}

async function load() {
    errorMessage.value = '';
    try {
        const [kecamatan, kelurahan, tahunAjaran] = await Promise.all([
            api('/api/kecamatan'), api('/api/kelurahan'), api('/api/tahun-ajaran'),
        ]);
        kecamatanRows.value = kecamatan.data.data;
        kelurahanRows.value = kelurahan.data.data;
        yearRows.value = tahunAjaran.data.data.map((item) => ({ ...item, status_label: item.aktif ? 'Aktif' : 'Tidak aktif' }));
    } catch (error) {
        errorMessage.value = error.message;
    }
}

function resetForm() {
    Object.assign(form, { kecamatan_id: '', nama: '', kode: '', label: '', tgl_mulai: '', tgl_selesai: '', aktif: false });
    editId.value = null;
    message.value = '';
}

function editRow(row) {
    resetForm();
    editId.value = row.id;
    Object.assign(form, {
        kecamatan_id: row.kecamatan_id ?? '',
        nama: row.nama ?? '',
        kode: row.kode ?? '',
        label: row.label ?? '',
        tgl_mulai: row.tgl_mulai ?? '',
        tgl_selesai: row.tgl_selesai ?? '',
        aktif: Boolean(row.aktif),
    });
}

async function save() {
    errorMessage.value = '';
    try {
        if (activeTab.value === 'kecamatan') {
            await api(`/api/kecamatan${editId.value ? `/${editId.value}` : ''}`, {
                method: editId.value ? 'PATCH' : 'POST',
                body: JSON.stringify({ nama: form.nama, kode: form.kode || null }),
            });
        } else if (activeTab.value === 'kelurahan') {
            await api(`/api/kelurahan${editId.value ? `/${editId.value}` : ''}`, {
                method: editId.value ? 'PATCH' : 'POST',
                body: JSON.stringify({ kecamatan_id: Number(form.kecamatan_id), nama: form.nama, kode: form.kode || null }),
            });
        } else {
            await api(`/api/tahun-ajaran${editId.value ? `/${editId.value}` : ''}`, {
                method: editId.value ? 'PATCH' : 'POST',
                body: JSON.stringify({ label: form.label, tgl_mulai: form.tgl_mulai, tgl_selesai: form.tgl_selesai, aktif: form.aktif }),
            });
        }
        message.value = 'Data berhasil disimpan.';
        resetForm();
        await load();
    } catch (error) {
        errorMessage.value = error.message;
    }
}

async function activateYear(year) {
    errorMessage.value = '';
    try {
        await api(`/api/tahun-ajaran/${year.id}`, {
            method: 'PATCH',
            body: JSON.stringify({ aktif: true }),
        });
        message.value = `${year.label} menjadi tahun ajaran aktif.`;
        await load();
    } catch (error) {
        errorMessage.value = error.message;
    }
}

onMounted(load);
watch(() => props.section, (section) => {
    activeTab.value = section;
    resetForm();
});
</script>

<template>
    <AppLayout>
        <div class="page-content">
            <header class="page-heading">
                <div><p class="eyebrow">DATA DASAR</p><h1>{{ pageTitle }}</h1></div>
            </header>
            <ToastMessage v-if="errorMessage" :message="errorMessage" type="error" />
            <ToastMessage v-if="message" :message="message" type="success" />
            <div class="master-data-grid">
                <section class="master-data-form" aria-label="Tambah data">
                    <h2>{{ editId ? (activeTab === 'tahun-ajaran' ? 'Ubah tahun ajaran' : `Ubah ${activeTab}`) : (activeTab === 'tahun-ajaran' ? 'Tambah tahun ajaran' : `Tambah ${activeTab}`) }}</h2>
                    <form class="management-form" @submit.prevent="save">
                        <template v-if="activeTab === 'kecamatan'">
                            <BaseField id="district-name" v-model="form.nama" label="Nama kecamatan" required />
                            <BaseField id="district-code" v-model="form.kode" label="Kode (opsional)" />
                        </template>
                        <template v-else-if="activeTab === 'kelurahan'">
                            <label class="management-field"><span>Kecamatan</span><select v-model="form.kecamatan_id" required><option value="" disabled>Pilih kecamatan</option><option v-for="item in kecamatanRows" :key="item.id" :value="item.id">{{ item.nama }}</option></select></label>
                            <BaseField id="subdistrict-name" v-model="form.nama" label="Nama kelurahan" required />
                            <BaseField id="subdistrict-code" v-model="form.kode" label="Kode (opsional)" />
                        </template>
                        <template v-else>
                            <BaseField id="school-year-label" v-model="form.label" label="Label (mis. 2026/2027)" required />
                            <label class="management-field"><span>Tanggal mulai</span><input v-model="form.tgl_mulai" type="date" required></label>
                            <label class="management-field"><span>Tanggal selesai</span><input v-model="form.tgl_selesai" type="date" required></label>
                            <label class="management-checkbox"><input v-model="form.aktif" type="checkbox"><span>Jadikan tahun ajaran aktif</span></label>
                        </template>
                        <BaseButton type="submit">{{ editId ? 'Simpan perubahan' : 'Simpan' }}</BaseButton>
                    </form>
                </section>
                <section class="master-data-table" aria-label="Daftar data">
                    <DataTable :columns="columns" :rows="rows" :label="`Daftar ${pageTitle}`" show-actions>
                        <template #cell-kecamatan="{ row }">{{ row.kecamatan?.nama ?? '—' }}</template>
                        <template #cell-status_label="{ row }"><span class="status-label">{{ row.status_label }}</span></template>
                        <template #actions="{ row }">
                            <div class="table-actions">
                                <BaseButton type="button" variant="secondary" @click="editRow(row)">Ubah</BaseButton>
                                <BaseButton v-if="activeTab === 'tahun-ajaran' && !row.aktif" type="button" variant="secondary" @click="activateYear(row)">Jadikan aktif</BaseButton>
                            </div>
                        </template>
                    </DataTable>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
