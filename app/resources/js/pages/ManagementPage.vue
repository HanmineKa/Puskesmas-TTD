<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import AppLayout from '../components/AppLayout.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseField from '../components/BaseField.vue';
import BaseModal from '../components/BaseModal.vue';
import DataTable from '../components/DataTable.vue';
import ToastMessage from '../components/ToastMessage.vue';
import { useAuthStore } from '../stores/auth';

const route = useRoute();
const auth = useAuthStore();
const rows = ref([]);
const pustuOptions = ref([]);
const kelurahanOptions = ref([]);
const loading = ref(false);
const modalOpen = ref(false);
const editId = ref(null);
const errorMessage = ref('');
const toastMessage = ref('');
const temporaryPassword = ref('');
const search = ref('');
const schoolFilters = reactive({ pustu_id: '', kelurahan_id: '', jenjang: '' });
const form = reactive({
    nama: '', alamat: '', kontak: '', npsn: '', jenjang: 'SMP', kelurahan_id: '', pustu_id: '',
    wilayah_kelurahan_ids: [], jabatan: '', nomor_hp: '', jenis_identitas: 'NIP', nomor_identitas: '',
});

const resource = computed(() => route.meta.resource);
const isAdministrator = computed(() => auth.user?.role === 'ADMINISTRATOR');
const pageConfig = computed(() => ({
    pustu: { title: isAdministrator.value ? 'Pustu' : 'Pustu Saya', endpoint: '/api/pustu', columns: [
        { key: 'nama', label: 'Nama Pustu' }, { key: 'alamat', label: 'Alamat' }, { key: 'kontak', label: 'Kontak' }, { key: 'sekolah_count', label: 'Sekolah' },
    ] },
    'admin-pustu': { title: 'Petugas Pustu', endpoint: '/api/admin-pustu', columns: [
        { key: 'nama', label: 'Nama Petugas' }, { key: 'jabatan', label: 'Jabatan' }, { key: 'nomor_identitas_tersamar', label: 'NIP/NIK' }, { key: 'pustu', label: 'Pustu' }, { key: 'status', label: 'Status Akun' },
    ] },
    sekolah: { title: isAdministrator.value ? 'Sekolah' : 'Sekolah Binaan', endpoint: '/api/sekolah', columns: [
        { key: 'npsn', label: 'NPSN' }, { key: 'nama', label: 'Nama Sekolah' }, { key: 'jenjang', label: 'Jenjang' }, { key: 'kelurahan', label: 'Kelurahan' }, { key: 'pustu', label: 'Pustu Pembina' }, { key: 'status', label: 'Status' },
    ] },
}[resource.value] ?? { title: 'Pengelolaan Data', endpoint: '', columns: [] }));

const pageTitle = computed(() => pageConfig.value.title);
const columns = computed(() => pageConfig.value.columns);

function csrfToken() {
    const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.split('=').slice(1).join('=')) : '';
}

async function api(path, options = {}) {
    const response = await fetch(path, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
            ...options.headers,
        },
    });
    const payload = await response.json();
    if (! response.ok) {
        const validationMessage = Object.values(payload.errors ?? {}).flat()[0];
        throw new Error(validationMessage ?? payload.message ?? 'Permintaan tidak dapat diproses.');
    }

    return payload;
}

async function load() {
    if (! pageConfig.value.endpoint) return;
    loading.value = true;
    errorMessage.value = '';

    try {
        const query = new URLSearchParams();
        if (resource.value === 'sekolah') {
            if (search.value) query.set('q', search.value);
            Object.entries(schoolFilters).forEach(([key, value]) => { if (value) query.set(key, value); });
        }
        const suffix = query.size ? `?${query}` : '';
        const payload = await api(`${pageConfig.value.endpoint}${suffix}`, { headers: { 'Content-Type': 'application/json' } });
        rows.value = payload.data?.data ?? [];
        if (isAdministrator.value) {
            const [pustuPayload, kelurahanPayload] = await Promise.all([
                api('/api/pustu', { headers: { 'Content-Type': 'application/json' } }),
                api('/api/kelurahan', { headers: { 'Content-Type': 'application/json' } }),
            ]);
            pustuOptions.value = pustuPayload.data?.data ?? [];
            kelurahanOptions.value = kelurahanPayload.data?.data ?? [];
        } else if (resource.value === 'sekolah') {
            const payloadKelurahan = await api('/api/kelurahan', { headers: { 'Content-Type': 'application/json' } });
            kelurahanOptions.value = payloadKelurahan.data?.data ?? [];
        }
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        loading.value = false;
    }
}

function resetForm() {
    Object.assign(form, {
        nama: '', alamat: '', kontak: '', npsn: '', jenjang: 'SMP', kelurahan_id: '', pustu_id: '',
        wilayah_kelurahan_ids: [], jabatan: '', nomor_hp: '', jenis_identitas: 'NIP', nomor_identitas: '',
    });
    editId.value = null;
    temporaryPassword.value = '';
    errorMessage.value = '';
}

function openCreate() {
    resetForm();
    modalOpen.value = true;
}

function openEdit(row) {
    resetForm();
    editId.value = row.id;
    Object.assign(form, {
        ...row,
        pustu_id: row.pustu_id ?? row.pustu?.id ?? '',
        kelurahan_id: row.kelurahan_id ?? row.kelurahan?.id ?? '',
        status: row.user?.status ?? row.status ?? 'AKTIF',
        wilayah_kelurahan_ids: row.kelurahan_wilayah?.map((item) => item.id) ?? [],
    });
    modalOpen.value = true;
}

function payloadForSave() {
    if (resource.value === 'pustu') {
        return { nama: form.nama, alamat: form.alamat, kontak: form.kontak, wilayah_kelurahan_ids: form.wilayah_kelurahan_ids };
    }
    if (resource.value === 'admin-pustu') {
        if (editId.value) {
            return {
                pustu_id: Number(form.pustu_id), nama: form.nama, jabatan: form.jabatan,
                nomor_hp: form.nomor_hp, status: form.status,
            };
        }

        return {
            pustu_id: Number(form.pustu_id), nama: form.nama, jabatan: form.jabatan, nomor_hp: form.nomor_hp,
            jenis_identitas: form.jenis_identitas, nomor_identitas: form.nomor_identitas,
        };
    }

    return {
        npsn: form.npsn, nama: form.nama, jenjang: form.jenjang, alamat: form.alamat,
        kelurahan_id: Number(form.kelurahan_id), ...(isAdministrator.value ? { pustu_id: Number(form.pustu_id) } : {}), kontak: form.kontak,
    };
}

async function save() {
    errorMessage.value = '';

    try {
        const payload = payloadForSave();
        const endpoint = `${pageConfig.value.endpoint}${editId.value ? `/${editId.value}` : ''}`;
        const result = await api(endpoint, {
            method: editId.value ? 'PATCH' : 'POST',
            body: JSON.stringify(payload),
        });
        temporaryPassword.value = result.kata_sandi_sementara ?? '';
        if (! temporaryPassword.value) {
            modalOpen.value = false;
            toastMessage.value = editId.value ? 'Data berhasil diperbarui.' : 'Data berhasil ditambahkan.';
        }
        await load();
    } catch (error) {
        errorMessage.value = error.message;
    }
}

async function deactivate(row) {
    if (! window.confirm(`Nonaktifkan ${row.nama}?`)) return;

    try {
        await api(`${pageConfig.value.endpoint}/${row.id}`, { method: 'DELETE' });
        toastMessage.value = 'Data berhasil dinonaktifkan.';
        await load();
    } catch (error) {
        errorMessage.value = error.message;
    }
}

async function resetStaffPassword(row) {
    if (! window.confirm(`Atur ulang kata sandi ${row.nama}?`)) return;

    try {
        const result = await api(`/api/admin-pustu/${row.id}/reset-password`, { method: 'POST' });
        resetForm();
        temporaryPassword.value = result.kata_sandi_sementara;
        modalOpen.value = true;
        toastMessage.value = 'Kata sandi sementara dibuat.';
        await load();
    } catch (error) {
        errorMessage.value = error.message;
    }
}
function cellValue(row, key) {
    if (key === 'pustu') return row.pustu?.nama ?? '—';
    if (key === 'kelurahan') return row.kelurahan?.nama ?? '—';
    if (key === 'status') return row.user?.status ?? row.status ?? '—';
    if (key === 'sekolah_count') return row.sekolah_count ?? 0;

    return row[key] ?? '—';
}

let schoolFilterTimer;
watch(() => [search.value, schoolFilters.pustu_id, schoolFilters.kelurahan_id, schoolFilters.jenjang], () => {
    if (resource.value !== 'sekolah') return;
    clearTimeout(schoolFilterTimer);
    schoolFilterTimer = setTimeout(load, 250);
});

watch(() => route.meta.resource, load);
onMounted(load);
</script>

<template>
    <AppLayout>
        <div class="page-content">
            <header class="page-heading">
                <div>
                    <p class="eyebrow">PENGELOLAAN DATA</p>
                    <h1>{{ pageTitle }}</h1>
                </div>
                <BaseButton v-if="isAdministrator || resource === 'sekolah'" type="button" @click="openCreate">Tambah data</BaseButton>
            </header>

            <div v-if="resource === 'sekolah'" class="management-search role-filter-row">
                <BaseField id="school-search" v-model="search" label="Cari NPSN" inputmode="numeric" />
                <label class="management-field"><span>Jenjang</span><select v-model="schoolFilters.jenjang"><option value="">Semua jenjang</option><option value="SMP">SMP</option><option value="SMA">SMA</option><option value="SMK">SMK</option></select></label>
                <label v-if="isAdministrator" class="management-field"><span>Pustu</span><select v-model="schoolFilters.pustu_id"><option value="">Semua Pustu</option><option v-for="item in pustuOptions" :key="item.id" :value="item.id">{{ item.nama }}</option></select></label>
                <label class="management-field"><span>Kelurahan</span><select v-model="schoolFilters.kelurahan_id"><option value="">Semua kelurahan</option><option v-for="item in kelurahanOptions" :key="item.id" :value="item.id">{{ item.nama }}</option></select></label>
            </div>

            <ToastMessage v-if="errorMessage" :message="errorMessage" type="error" />
            <div v-if="loading" class="management-state" role="status">Memuat data...</div>
            <DataTable v-else :columns="columns" :rows="rows" :label="`Daftar ${pageTitle}`" show-actions>
                <template v-for="column in columns" #[`cell-${column.key}`]="{ row }" :key="column.key">
                    {{ cellValue(row, column.key) }}
                </template>
                <template #cell-npsn="{ row }"><button class="table-link" type="button" @click="openEdit(row)">{{ row.npsn }}</button></template>
                <template #cell-nama="{ row }">
                    <button v-if="resource !== 'pustu' || isAdministrator" class="table-link" type="button" @click="openEdit(row)">{{ row.nama }}</button>
                    <span v-else>{{ row.nama }}</span>
                </template>
                <template #cell-status="{ row }">
                    <span class="status-label">{{ cellValue(row, 'status') === 'AKTIF' ? 'Aktif' : 'Nonaktif' }}</span>
                </template>
                <template #actions="{ row }">
                    <div class="table-actions">
                        <BaseButton v-if="resource !== 'pustu' || isAdministrator" type="button" variant="secondary" @click="openEdit(row)">Ubah</BaseButton>
                                                <BaseButton v-if="resource === 'admin-pustu' && isAdministrator" type="button" variant="secondary" @click="resetStaffPassword(row)">Atur ulang sandi</BaseButton>
                        <BaseButton v-if="(resource !== 'pustu' || isAdministrator) && row.status !== 'NONAKTIF' && row.user?.status !== 'NONAKTIF'" type="button" variant="secondary" @click="deactivate(row)">Nonaktifkan</BaseButton>
                    </div>
                </template>
            </DataTable>
        </div>

        <BaseModal v-model="modalOpen" :title="`${editId ? 'Ubah' : 'Tambah'} ${pageTitle}`">
            <form class="management-form" @submit.prevent="save">
                <template v-if="resource === 'pustu'">
                    <BaseField id="pustu-name" v-model="form.nama" label="Nama Pustu" required />
                    <BaseField id="pustu-address" v-model="form.alamat" label="Alamat" />
                    <BaseField id="pustu-contact" v-model="form.kontak" label="Kontak" />
                    <label class="management-field">
                        <span>Wilayah kelurahan</span>
                        <select v-model="form.wilayah_kelurahan_ids" multiple size="5">
                            <option v-for="item in kelurahanOptions" :key="item.id" :value="item.id">{{ item.nama }} · {{ item.kecamatan?.nama }}</option>
                        </select>
                    </label>
                </template>
                <template v-else-if="resource === 'admin-pustu'">
                    <label class="management-field"><span>Pustu</span><select v-model="form.pustu_id" required><option value="" disabled>Pilih Pustu</option><option v-for="item in pustuOptions" :key="item.id" :value="item.id">{{ item.nama }}</option></select></label>
                    <BaseField id="staff-name" v-model="form.nama" label="Nama petugas" required />
                    <BaseField id="staff-position" v-model="form.jabatan" label="Jabatan" />
                    <BaseField id="staff-phone" v-model="form.nomor_hp" label="Nomor HP" inputmode="tel" />
                    <label v-if="editId" class="management-field"><span>Status akun</span><select v-model="form.status"><option value="AKTIF">Aktif</option><option value="NONAKTIF">Nonaktif</option></select></label>
                    <template v-else>
                        <label class="management-field"><span>Jenis identitas</span><select v-model="form.jenis_identitas"><option value="NIP">NIP</option><option value="NIK">NIK</option></select></label>
                        <BaseField id="staff-identity" v-model="form.nomor_identitas" :label="`Nomor ${form.jenis_identitas}`" inputmode="numeric" required />
                    </template>
                </template>
                <template v-else>
                    <BaseField id="school-npsn" v-model="form.npsn" label="NPSN (8 angka)" inputmode="numeric" required />
                    <BaseField id="school-name" v-model="form.nama" label="Nama sekolah" required />
                    <label class="management-field"><span>Jenjang</span><select v-model="form.jenjang"><option value="SMP">SMP</option><option value="SMA">SMA</option><option value="SMK">SMK</option></select></label>
                    <label class="management-field"><span>Kelurahan</span><select v-model="form.kelurahan_id" required><option value="" disabled>Pilih kelurahan</option><option v-for="item in kelurahanOptions" :key="item.id" :value="item.id">{{ item.nama }} · {{ item.kecamatan?.nama }}</option></select></label>
                    <label v-if="isAdministrator" class="management-field"><span>Pustu pembina</span><select v-model="form.pustu_id" required><option value="" disabled>Pilih Pustu</option><option v-for="item in pustuOptions" :key="item.id" :value="item.id">{{ item.nama }}</option></select></label>
                    <BaseField id="school-address" v-model="form.alamat" label="Alamat" />
                    <BaseField id="school-contact" v-model="form.kontak" label="Kontak" />
                </template>
                <p v-if="errorMessage" class="management-error" role="alert">{{ errorMessage }}</p>
                <p v-if="temporaryPassword" class="temporary-password" role="status">
                    Kata sandi sementara petugas (tampilkan hanya sekali): <strong>{{ temporaryPassword }}</strong>
                </p>
                <footer class="management-form__actions">
                    <BaseButton type="button" variant="secondary" @click="modalOpen = false">Tutup</BaseButton>
                    <BaseButton v-if="!temporaryPassword" type="submit">{{ editId ? 'Simpan perubahan' : 'Simpan' }}</BaseButton>
                </footer>
            </form>
        </BaseModal>

        <ToastMessage v-if="toastMessage" :message="toastMessage" type="success" />
    </AppLayout>
</template>
