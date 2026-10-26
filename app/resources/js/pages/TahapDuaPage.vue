<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AppLayout from '../components/AppLayout.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseField from '../components/BaseField.vue';
import BaseModal from '../components/BaseModal.vue';
import DataTable from '../components/DataTable.vue';
import ToastMessage from '../components/ToastMessage.vue';
import { useAuthStore } from '../stores/auth';

const props = defineProps({ view: { type: String, default: 'auto' } });
const auth = useAuthStore();
const loading = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const teachers = ref([]);
const students = ref([]);
const mutations = ref([]);
const destinations = ref([]);
const schoolOptions = ref([]);
const classes = ref([]);
const tokens = ref([]);
const years = ref([]);
const profile = ref(null);
const selectedStudent = ref(null);
const selectedTeacher = ref(null);
const teacherIdentity = ref('');
const teacherStatus = ref('');
const studentIdentity = ref('');
const teacherFilters = reactive({ sekolah_id: '', kelurahan_id: '' });
const studentFilters = reactive({ kelas_id: '', status: '' });
const form = reactive({ className: '', grade: 7, finalClass: false, yearId: '', classId: '' });
const decisions = reactive({});
const mutationForm = reactive({ enrollment_id: '', jenis: 'PINDAH_SEKOLAH_SATU_PUSTU', sekolah_tujuan_id: '', kelas_tujuan_id: '', tgl_efektif: new Date().toISOString().slice(0, 10), alasan: '' });
const labels = {
    status: {
        AKTIF: 'Aktif', MENUNGGU_VERIFIKASI: 'Menunggu verifikasi', DITOLAK: 'Ditolak', NONAKTIF: 'Nonaktif',
        DALAM_MUTASI: 'Dalam proses mutasi', LULUS: 'Lulus', KELUAR_WILAYAH: 'Keluar wilayah', PUTUS_SEKOLAH: 'Putus sekolah',
        PENDING: 'Menunggu keputusan', DISETUJUI: 'Disetujui', SELESAI: 'Selesai',
    },
    mutation: {
        PINDAH_SEKOLAH_SATU_PUSTU: 'Pindah sekolah satu Pustu', PINDAH_ANTAR_PUSTU: 'Pindah antar-Pustu',
        KELUAR_WILAYAH: 'Keluar wilayah', NAIK_JENJANG: 'Naik jenjang', LULUS: 'Lulus', PUTUS_SEKOLAH: 'Putus sekolah',
    },
};

const role = computed(() => auth.user?.role ?? '');
const roleLabels = {
    ADMINISTRATOR: 'Administrator',
    ADMIN_PUSTU: 'Admin Pustu',
    GURU: 'Guru',
    SISWI: 'Siswi',
};
const teacherColumns = [
    { key: 'nama', label: 'Nama Guru' },
    { key: 'nuptk', label: 'NUPTK' },
    { key: 'sekolah', label: 'Sekolah' },
    { key: 'kelurahan', label: 'Kelurahan' },
    { key: 'status', label: 'Status' },
];
const studentColumns = [
    { key: 'nama', label: 'Nama Siswi' },
    { key: 'nisn', label: 'NISN' },
    { key: 'status_pelacakan', label: 'Status' },
    { key: 'kelas', label: 'Kelas' },
];
const mutationColumns = [
    { key: 'nisn_tersamar', label: 'NISN' },
    { key: 'jenis', label: 'Jenis' },
    { key: 'sekolah_asal', label: 'Asal' },
    { key: 'sekolah_tujuan', label: 'Tujuan' },
    { key: 'status', label: 'Status' },
];
const currentView = computed(() => props.view === 'auto'
    ? role.value === 'ADMINISTRATOR' || role.value === 'ADMIN_PUSTU' ? 'data-guru' : role.value === 'GURU' ? 'kelas' : 'data-saya'
    : props.view);
const isStaff = computed(() => ['ADMINISTRATOR', 'ADMIN_PUSTU'].includes(role.value));
const pageTitle = computed(() => ({
    'data-guru': 'Data Guru',
    'verifikasi-guru': 'Verifikasi & Validasi Guru',
    'data-siswi': role.value === 'GURU' ? 'Siswi' : 'Data Siswi',
    kelas: 'Kelas',
    token: 'Token Sekolah',
    mutasi: 'Mutasi Siswi',
    'data-saya': 'Data Saya',
}[currentView.value] ?? 'Ruang Kerja'));
const destinationSchool = computed(() => destinations.value.find((school) => Number(school.id) === Number(mutationForm.sekolah_tujuan_id)) ?? null);
const areaOptions = computed(() => [...new Map(schoolOptions.value
    .filter((school) => school.kelurahan)
    .map((school) => [school.kelurahan.id, school.kelurahan])).values()]);

function statusLabel(value) {
    return labels.status[value] ?? value ?? '—';
}

function statusClass(value) {
    if (['AKTIF', 'VALID', 'DISETUJUI', 'SELESAI'].includes(value)) return 'status-label--success';
    if (['MENUNGGU_VERIFIKASI', 'MENUNGGU', 'PENDING'].includes(value)) return 'status-label--pending';

    return 'status-label--invalid';
}

function mutationLabel(value) {
    return labels.mutation[value] ?? value ?? '—';
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
        if (['data-guru', 'verifikasi-guru'].includes(currentView.value) && isStaff.value) {
            const [schoolPayload] = await Promise.all([api('/api/sekolah')]);
            schoolOptions.value = schoolPayload.data?.data ?? [];
            await searchTeachers();
        } else if (currentView.value === 'data-siswi' && role.value !== 'SISWI') {
            const [studentPayload, classPayload] = await Promise.all([api('/api/siswi'), api('/api/kelas')]);
            students.value = studentPayload.data?.data ?? [];
            classes.value = classPayload.data ?? [];
        } else if (currentView.value === 'kelas' && role.value === 'GURU') {
            const [classPayload, yearPayload] = await Promise.all([api('/api/guru/kelas'), api('/api/tahun-ajaran')]);
            classes.value = classPayload.data ?? [];
            years.value = yearPayload.data?.data ?? [];
            form.yearId = years.value.find((year) => year.aktif)?.id ?? '';
        } else if (currentView.value === 'token' && role.value === 'GURU') {
            const [tokenPayload, yearPayload] = await Promise.all([api('/api/guru/token-sekolah'), api('/api/tahun-ajaran')]);
            tokens.value = tokenPayload.data ?? [];
            years.value = yearPayload.data?.data ?? [];
            form.yearId = years.value.find((year) => year.aktif)?.id ?? '';
        } else if (currentView.value === 'mutasi' && role.value !== 'SISWI') {
            const [mutationPayload, studentPayload, destinationPayload] = await Promise.all([
                api('/api/mutasi-siswi'), api('/api/siswi'), api('/api/mutasi-siswi/sekolah-tujuan'),
            ]);
            mutations.value = mutationPayload.data?.data ?? [];
            students.value = studentPayload.data?.data ?? [];
            destinations.value = destinationPayload.data ?? [];
        } else if (currentView.value === 'data-saya' && role.value === 'SISWI') {
            profile.value = (await api('/api/siswi/saya')).data;
        }
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        loading.value = false;
    }
}

async function loadStudents() {
    errorMessage.value = '';
    const query = new URLSearchParams();
    if (studentIdentity.value && studentIdentity.value.length !== 10) return;
    if (studentIdentity.value) query.set('nisn', studentIdentity.value);
    Object.entries(studentFilters).forEach(([key, value]) => { if (value) query.set(key, value); });
    try {
        const payload = await api(`/api/siswi${query.size ? `?${query}` : ''}`);
        students.value = payload.data?.data ?? [];
    } catch (error) {
        errorMessage.value = error.message;
    }
}

async function searchTeachers() {
    errorMessage.value = '';
    try {
        const query = new URLSearchParams();
        if (teacherIdentity.value && teacherIdentity.value.length !== 16) return;
        if (teacherIdentity.value) query.set('nuptk', teacherIdentity.value);
        const status = currentView.value === 'verifikasi-guru' ? 'MENUNGGU_VERIFIKASI' : teacherStatus.value;
        if (status) query.set('status', status);
        Object.entries(teacherFilters).forEach(([key, value]) => { if (value) query.set(key, value); });
        const payload = await api(`/api/guru${query.size ? `?${query}` : ''}`);
        teachers.value = payload.data?.data ?? [];
    } catch (error) {
        errorMessage.value = error.message;
    }
}

async function showTeacher(teacher) {
    errorMessage.value = '';
    try {
        selectedTeacher.value = (await api(`/api/guru/${teacher.id}`)).data;
    } catch (error) {
        errorMessage.value = error.message;
    }
}

async function decideTeacher(teacher, decision) {
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const values = decisions[teacher.id] ?? {};
        const rejectionReason = decision === 'DITOLAK' ? window.prompt('Tuliskan alasan penolakan pendaftaran Guru:') : null;
        if (decision === 'DITOLAK' && rejectionReason === null) return;
        await api(`/api/guru/${teacher.id}/verifikasi`, {
            method: 'PATCH',
            body: JSON.stringify({ keputusan: decision, ...(decision === 'DITOLAK' ? { alasan_penolakan: rejectionReason } : {}) }),
        });
        successMessage.value = decision === 'DISETUJUI' ? 'Akun Guru disetujui.' : 'Pendaftaran Guru ditolak.';
        await load();
    } catch (error) {
        errorMessage.value = error.message;
    }
}

async function createClass() {
    errorMessage.value = '';
    successMessage.value = '';
    try {
        await api('/api/guru/kelas', {
            method: 'POST',
            body: JSON.stringify({ nama: form.className, tingkat: Number(form.grade), kelas_akhir: form.finalClass }),
        });
        form.className = '';
        form.finalClass = false;
        successMessage.value = 'Kelas berhasil ditambahkan.';
        await load();
    } catch (error) {
        errorMessage.value = error.message;
    }
}

async function createToken() {
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const result = await api('/api/guru/token-sekolah', {
            method: 'POST',
            body: JSON.stringify({ tahun_ajaran_id: Number(form.yearId) }),
        });
        successMessage.value = `Token baru: ${result.data.kode}`;
        await load();
    } catch (error) {
        errorMessage.value = error.message;
    }
}

async function deactivateToken(token) {
    errorMessage.value = '';
    successMessage.value = '';
    try {
        await api(`/api/guru/token-sekolah/${token.id}/nonaktif`, { method: 'PATCH' });
        successMessage.value = 'Token sekolah dinonaktifkan.';
        await load();
    } catch (error) {
        errorMessage.value = error.message;
    }
}

function viewTeacherDocument(teacher) {
    window.open(`/api/guru/${teacher.id}/dokumen-sk`, '_blank', 'noopener');
}

async function moveStudent(enrollment) {
    errorMessage.value = '';
    successMessage.value = '';
    try {
        await api(`/api/enrollment/${enrollment.id}/kelas`, {
            method: 'PATCH',
            body: JSON.stringify({ kelas_id: Number(enrollment.nextClassId) }),
        });
        successMessage.value = 'Kelas Siswi diperbarui.';
        await load();
    } catch (error) {
        errorMessage.value = error.message;
    }
}

async function reviewMutation(mutation, decision) {
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const values = decisions[mutation.id] ?? {};
        const rejectionReason = decision === 'DITOLAK' ? window.prompt('Tuliskan catatan penolakan mutasi:') : null;
        if (decision === 'DITOLAK' && rejectionReason === null) return;
        if (decision === 'KONFIRMASI') {
            await api(`/api/mutasi-siswi/${mutation.id}/konfirmasi`, {
                method: 'POST',
                body: JSON.stringify({ kelas_tujuan_id: Number(values.kelas_tujuan_id ?? mutation.kelas_tujuan_id) }),
            });
        } else {
            await api(`/api/mutasi-siswi/${mutation.id}/keputusan`, {
                method: 'PATCH',
                body: JSON.stringify({
                    keputusan: decision,
                    ...(decision === 'DISETUJUI' && values.kelas_tujuan_id ? { kelas_tujuan_id: Number(values.kelas_tujuan_id) } : {}),
                    ...(decision === 'DITOLAK' ? { catatan_penolakan: rejectionReason } : {}),
                }),
            });
        }
        successMessage.value = decision === 'DITOLAK' ? 'Mutasi ditolak.' : 'Keputusan mutasi tersimpan.';
        await load();
    } catch (error) {
        errorMessage.value = error.message;
    }
}

async function submitMutation() {
    errorMessage.value = '';
    successMessage.value = '';
    try {
        await api('/api/mutasi-siswi', {
            method: 'POST',
            body: JSON.stringify({
                ...mutationForm,
                enrollment_id: Number(mutationForm.enrollment_id),
                sekolah_tujuan_id: mutationForm.sekolah_tujuan_id ? Number(mutationForm.sekolah_tujuan_id) : null,
                kelas_tujuan_id: mutationForm.kelas_tujuan_id ? Number(mutationForm.kelas_tujuan_id) : null,
            }),
        });
        successMessage.value = 'Pengajuan mutasi berhasil dikirim.';
        mutationForm.enrollment_id = '';
        mutationForm.sekolah_tujuan_id = '';
        mutationForm.alasan = '';
        await load();
    } catch (error) {
        errorMessage.value = error.message;
    }
}

async function showStudent(student) {
    errorMessage.value = '';
    try {
        selectedStudent.value = (await api(`/api/siswi/${student.id}`)).data;
    } catch (error) {
        errorMessage.value = error.message;
    }
}

function activeForSchool(student) {
    return student.enrollments?.find((enrollment) => enrollment.status === 'AKTIF');
}

function decisionState(mutation) {
    decisions[mutation.id] ??= { kelas_tujuan_id: mutation.kelas_tujuan_id ?? '' };
    return decisions[mutation.id];
}

let studentFilterTimer;
let teacherFilterTimer;

watch(() => [studentIdentity.value, studentFilters.kelas_id, studentFilters.status], () => {
    if (currentView.value !== 'data-siswi') return;
    if (studentIdentity.value && studentIdentity.value.length !== 10) {
        students.value = [];
        return;
    }
    clearTimeout(studentFilterTimer);
    studentFilterTimer = setTimeout(loadStudents, 250);
});

watch(() => [teacherIdentity.value, teacherStatus.value, teacherFilters.sekolah_id, teacherFilters.kelurahan_id], () => {
    if (currentView.value !== 'data-guru') return;
    if (teacherIdentity.value && teacherIdentity.value.length !== 16) {
        teachers.value = [];
        return;
    }
    clearTimeout(teacherFilterTimer);
    teacherFilterTimer = setTimeout(searchTeachers, 250);
});

onMounted(load);
watch(currentView, load);
</script>

<template>
    <AppLayout>
        <div class="page-content tahap-dua-page">
            <header class="page-heading">
                <div><p class="eyebrow">RUANG KERJA {{ roleLabels?.[role] ?? role }}</p><h1>{{ pageTitle }}</h1></div>
                <span class="role-badge">{{ auth.user?.nama_pengguna ?? 'Akun aktif' }}</span>
            </header>

            <ToastMessage v-if="errorMessage" :message="errorMessage" type="error" />
            <ToastMessage v-if="successMessage" :message="successMessage" type="success" />
            <p v-if="loading" class="management-state" role="status">Memuat data...</p>

            <template v-if="['data-guru', 'verifikasi-guru'].includes(currentView) && isStaff">
                <div v-if="currentView === 'data-guru'" class="management-search role-filter-row">
                    <BaseField id="teacher-search" v-model="teacherIdentity" label="NUPTK (16 angka)" inputmode="numeric" :min-length="16" :max-length="16" />
                    <label class="management-field"><span>Status</span><select v-model="teacherStatus"><option value="">Semua status</option><option value="MENUNGGU_VERIFIKASI">Menunggu verifikasi</option><option value="AKTIF">Aktif</option><option value="DITOLAK">Ditolak</option><option value="NONAKTIF">Nonaktif</option></select></label>
                    <label class="management-field"><span>Sekolah</span><select v-model="teacherFilters.sekolah_id"><option value="">Semua sekolah</option><option v-for="school in schoolOptions" :key="school.id" :value="school.id">{{ school.nama }}</option></select></label>
                    <label class="management-field"><span>Kelurahan</span><select v-model="teacherFilters.kelurahan_id"><option value="">Semua kelurahan</option><option v-for="area in areaOptions" :key="area.id" :value="area.id">{{ area.nama }}</option></select></label>
                </div>
                <section class="stage-section">
                    <div class="stage-section__heading"><div><p class="eyebrow">{{ currentView === 'verifikasi-guru' ? 'TINDAKAN PETUGAS' : 'DATA WILAYAH' }}</p><h2>{{ currentView === 'verifikasi-guru' ? 'Guru menunggu verifikasi' : 'Daftar Guru' }}</h2></div><span class="role-badge">{{ teachers.length }} Guru</span></div>
                    <DataTable :columns="teacherColumns" :rows="teachers" label="Daftar Guru" show-actions>
                        <template #cell-nuptk="{ row }"><span>{{ row.nuptk }}</span></template>
                        <template #cell-sekolah="{ row }">{{ row.sekolah?.nama ?? '—' }}</template>
                        <template #cell-kelurahan="{ row }">{{ row.sekolah?.kelurahan ?? '—' }}</template>
                        <template #cell-status="{ row }"><span class="status-label" :class="statusClass(row.user?.status)">{{ statusLabel(row.user?.status) }}</span></template>
                        <template #actions="{ row }"><div class="table-actions"><BaseButton type="button" variant="secondary" @click="showTeacher(row)">Lihat detail</BaseButton><template v-if="currentView === 'verifikasi-guru' && row.user?.status === 'MENUNGGU_VERIFIKASI'"><BaseButton type="button" variant="secondary" @click="viewTeacherDocument(row)">Lihat SK</BaseButton><BaseButton type="button" @click="decideTeacher(row, 'DISETUJUI')">Setujui</BaseButton><BaseButton type="button" variant="secondary" @click="decideTeacher(row, 'DITOLAK')">Tolak</BaseButton></template></div></template>
                    </DataTable>
                </section>
            </template>

            <template v-else-if="currentView === 'kelas' && role === 'GURU'">
                <section class="stage-section stage-class-page">
                    <div class="stage-section__heading"><div><p class="eyebrow">TAHUN AJARAN AKTIF</p><h2>Daftar kelas</h2></div><span class="role-badge">{{ classes.length }} kelas</span></div>
                    <form class="management-form stage-class-form" @submit.prevent="createClass"><BaseField id="class-name" v-model="form.className" label="Nama kelas" required/><label class="management-field"><span>Tingkat</span><select v-model="form.grade"><option v-for="grade in [7,8,9,10,11,12,13]" :key="grade" :value="grade">{{ grade }}</option></select></label><label class="management-checkbox"><input v-model="form.finalClass" type="checkbox"><span>Kelas akhir</span></label><BaseButton type="submit">Tambah</BaseButton></form>
                    <ul class="stage-list stage-class-list"><li v-for="item in classes" :key="item.id"><strong>{{ item.nama }}</strong><span>Tingkat {{ item.tingkat }}<template v-if="item.kelas_akhir"> · Kelas akhir</template></span></li><li v-if="!classes.length" class="stage-list__empty">Belum ada kelas.</li></ul>
                </section>
            </template>

            <template v-else-if="currentView === 'token' && role === 'GURU'">
                <section class="stage-section">
                    <div class="stage-section__heading"><div><p class="eyebrow">PENDAFTARAN SISWI</p><h2>Token sekolah</h2></div></div>
                    <div class="token-create-row"><label class="management-field"><span>Tahun ajaran</span><select v-model="form.yearId" required><option value="" disabled>Pilih tahun ajaran</option><option v-for="year in years" :key="year.id" :value="year.id">{{ year.label }}</option></select></label><BaseButton type="button" :disabled="!form.yearId" @click="createToken">Buat token baru</BaseButton></div>
                    <ul class="stage-list"><li v-for="token in tokens" :key="token.id"><strong>{{ token.kode }}</strong><span>{{ token.tahun_ajaran?.label }} · {{ token.aktif ? 'Aktif' : 'Tidak aktif' }}</span><BaseButton v-if="token.aktif" type="button" variant="secondary" @click="deactivateToken(token)">Nonaktifkan</BaseButton></li><li v-if="!tokens.length" class="stage-list__empty">Belum ada token.</li></ul>
                </section>
            </template>

            <template v-else-if="currentView === 'data-siswi' && role !== 'SISWI'">
                <div class="management-search role-filter-row tahap-student-filters">
                    <BaseField id="student-search" v-model="studentIdentity" label="NISN (10 angka)" inputmode="numeric" :min-length="10" :max-length="10" />
                    <label class="management-field"><span>Kelas</span><select v-model="studentFilters.kelas_id"><option value="">Semua kelas</option><option v-for="classItem in classes" :key="classItem.id" :value="classItem.id">{{ classItem.sekolah?.nama ? `${classItem.sekolah.nama} · ` : '' }}{{ classItem.nama }}</option></select></label>
                    <label class="management-field"><span>Status</span><select v-model="studentFilters.status"><option value="">Semua status</option><option value="AKTIF">Aktif</option><option value="DALAM_MUTASI">Dalam mutasi</option><option value="LULUS">Lulus</option><option value="KELUAR_WILAYAH">Keluar wilayah</option><option value="PUTUS_SEKOLAH">Putus sekolah</option></select></label>
                </div>
                <section class="stage-section"><div class="stage-section__heading"><div><p class="eyebrow">DATA PELACAKAN</p><h2>{{ pageTitle }}</h2></div><span class="role-badge">{{ students.length }} Siswi</span></div>
                    <DataTable :columns="studentColumns" :rows="students" :label="pageTitle" show-actions>
                        <template #cell-nisn="{ row }">{{ row.nisn }}</template>
                        <template #cell-status_pelacakan="{ row }"><span class="status-label" :class="statusClass(row.status_pelacakan)">{{ statusLabel(row.status_pelacakan) }}</span></template>
                        <template #cell-kelas="{ row }"><template v-for="enrollment in row.enrollments" :key="enrollment.id"><span v-if="enrollment.status === 'AKTIF'">{{ enrollment.sekolah }} · {{ enrollment.kelas }}</span></template></template>
                        <template #actions="{ row }"><div class="table-actions"><BaseButton type="button" variant="secondary" @click="showStudent(row)">Lihat detail</BaseButton><label v-if="role === 'GURU' && activeForSchool(row)" class="stage-inline-select"><span class="sr-only">Pindahkan kelas</span><select v-model="activeForSchool(row).nextClassId" @change="moveStudent(activeForSchool(row))"><option value="" disabled>Ubah kelas</option><option v-for="classItem in classes" :key="classItem.id" :value="classItem.id">{{ classItem.nama }}</option></select></label></div></template>
                    </DataTable>
                </section>
            </template>

            <template v-else-if="currentView === 'mutasi' && role !== 'SISWI'">
                <section v-if="role === 'GURU'" class="stage-section stage-mutation-form">
                    <p class="eyebrow">PERPINDAHAN SISWI</p><h2>Ajukan mutasi</h2>
                    <form class="management-form" @submit.prevent="submitMutation">
                        <label class="management-field"><span>Siswi dari enrollment aktif</span><select v-model="mutationForm.enrollment_id" required><option value="" disabled>Pilih Siswi</option><template v-for="student in students" :key="student.id"><option v-for="enrollment in student.enrollments.filter((item) => item.status === 'AKTIF')" :key="enrollment.id" :value="enrollment.id">{{ student.nama }} · {{ enrollment.sekolah }} · {{ enrollment.kelas }}</option></template></select></label>
                        <label class="management-field"><span>Jenis mutasi</span><select v-model="mutationForm.jenis" @change="mutationForm.kelas_tujuan_id = ''"><option value="PINDAH_SEKOLAH_SATU_PUSTU">Pindah sekolah</option><option value="PINDAH_ANTAR_PUSTU">Pindah antar-Pustu</option><option value="NAIK_JENJANG">Naik jenjang</option><option value="KELUAR_WILAYAH">Keluar wilayah</option><option value="LULUS">Lulus</option><option value="PUTUS_SEKOLAH">Putus sekolah</option></select></label>
                        <template v-if="['PINDAH_SEKOLAH_SATU_PUSTU', 'PINDAH_ANTAR_PUSTU', 'NAIK_JENJANG'].includes(mutationForm.jenis)">
                            <label class="management-field"><span>Sekolah tujuan</span><select v-model="mutationForm.sekolah_tujuan_id" required @change="mutationForm.kelas_tujuan_id = ''"><option value="" disabled>Pilih sekolah</option><option v-for="school in destinations" :key="school.id" :value="school.id">{{ school.nama }} · {{ school.jenjang }}</option></select></label>
                            <label class="management-field"><span>Kelas tujuan</span><select v-model="mutationForm.kelas_tujuan_id" required :disabled="!destinationSchool?.kelas?.length"><option value="" disabled>Pilih kelas</option><option v-for="classItem in destinationSchool?.kelas ?? []" :key="classItem.id" :value="classItem.id">{{ classItem.nama }}</option></select></label>
                        </template>
                        <label class="management-field"><span>Tanggal efektif</span><input v-model="mutationForm.tgl_efektif" type="date" required></label>
                        <BaseField id="mutation-reason" v-model="mutationForm.alasan" label="Alasan (opsional)" :max-length="1000" />
                        <BaseButton type="submit" :disabled="!students.some((student) => student.enrollments.some((enrollment) => enrollment.id === Number(mutationForm.enrollment_id) && enrollment.status === 'AKTIF'))">Kirim</BaseButton>
                    </form>
                </section>
                <section class="stage-section"><div class="stage-section__heading"><div><p class="eyebrow">{{ role === 'GURU' ? 'STATUS PERPINDAHAN' : 'PEMERIKSAAN WILAYAH' }}</p><h2>{{ role === 'GURU' ? 'Riwayat mutasi' : 'Validasi mutasi Siswi' }}</h2></div><span class="role-badge">{{ mutations.length }} data</span></div>
                    <DataTable :columns="mutationColumns" :rows="mutations" label="Validasi mutasi Siswi" show-actions>
                        <template #cell-jenis="{ row }">{{ mutationLabel(row.jenis) }}</template>
                        <template #cell-sekolah_tujuan="{ row }">{{ row.sekolah_tujuan ?? 'Keluar wilayah' }}</template>
                        <template #cell-status="{ row }"><span class="status-label" :class="statusClass(row.status)">{{ statusLabel(row.status) }}</span></template>
                        <template #actions="{ row }"><div class="table-actions"><template v-if="role === 'GURU'">Menunggu keputusan petugas</template><template v-else-if="row.perlu_konfirmasi"><label class="stage-inline-select"><span class="sr-only">Kelas tujuan</span><select v-model="decisionState(row).kelas_tujuan_id"><option value="" disabled>Pilih kelas</option><option v-for="classItem in row.kelas_tujuan_opsi" :key="classItem.id" :value="classItem.id">{{ classItem.nama }}</option></select></label><BaseButton type="button" @click="reviewMutation(row, 'KONFIRMASI')">Konfirmasi</BaseButton></template><template v-else-if="row.dapat_diputuskan"><label v-if="['PINDAH_SEKOLAH_SATU_PUSTU', 'NAIK_JENJANG'].includes(row.jenis) && !row.kelas_tujuan_id" class="stage-inline-select"><span class="sr-only">Kelas tujuan</span><select v-model="decisionState(row).kelas_tujuan_id"><option value="" disabled>Pilih kelas</option><option v-for="classItem in row.kelas_tujuan_opsi" :key="classItem.id" :value="classItem.id">{{ classItem.nama }}</option></select></label><BaseButton type="button" @click="reviewMutation(row, 'DISETUJUI')">Setujui</BaseButton><BaseButton type="button" variant="secondary" @click="reviewMutation(row, 'DITOLAK')">Tolak</BaseButton></template><span v-else>Menunggu Pustu tujuan</span></div></template>
                    </DataTable>
                </section>
            </template>

            <template v-else-if="currentView === 'data-saya' && role === 'SISWI' && profile">
                <section class="stage-section student-profile"><p class="eyebrow">DATA SAYA</p><h2>{{ profile.nama }}</h2><dl><div><dt>NISN</dt><dd>{{ profile.nisn }}</dd></div><div><dt>Status pelacakan</dt><dd>{{ profile.status_pelacakan }}</dd></div></dl><h3>Riwayat sekolah</h3><ul class="stage-list"><li v-for="enrollment in profile.enrollments" :key="enrollment.id"><strong>{{ enrollment.sekolah }}</strong><span>{{ enrollment.kelas }} · {{ enrollment.status }}</span></li></ul></section>
            </template>
        </div>

        <BaseModal v-if="selectedTeacher" :model-value="true" :title="`Detail Guru: ${selectedTeacher.nama}`" @update:model-value="selectedTeacher = null">
            <dl class="detail-list"><div><dt>NUPTK</dt><dd>{{ selectedTeacher.nuptk }}</dd></div><div><dt>Sekolah</dt><dd>{{ selectedTeacher.sekolah?.nama ?? selectedTeacher.sekolah }}</dd></div><div><dt>Status</dt><dd>{{ statusLabel(selectedTeacher.user?.status) }}</dd></div><div v-if="selectedTeacher.alasan_penolakan"><dt>Catatan</dt><dd>{{ selectedTeacher.alasan_penolakan }}</dd></div></dl>
        </BaseModal>
        <BaseModal v-if="selectedStudent" :model-value="true" :title="`Detail Siswi: ${selectedStudent.nama}`" @update:model-value="selectedStudent = null">
            <dl class="detail-list"><div><dt>NISN</dt><dd>{{ selectedStudent.nisn }}</dd></div><div><dt>Status</dt><dd>{{ statusLabel(selectedStudent.status_pelacakan) }}</dd></div></dl>
            <ul class="stage-list"><li v-for="enrollment in selectedStudent.enrollments" :key="enrollment.id"><strong>{{ enrollment.sekolah }}</strong><span>{{ enrollment.kelas }} · {{ statusLabel(enrollment.status) }}</span></li></ul>
        </BaseModal>
    </AppLayout>
</template>
