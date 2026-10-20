<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AppLayout from '../components/AppLayout.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseField from '../components/BaseField.vue';
import DataTable from '../components/DataTable.vue';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const loading = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const activeTab = ref('utama');
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
const teacherStatus = ref('MENUNGGU_VERIFIKASI');
const studentIdentity = ref('');
const teacherFilters = reactive({ sekolah_id: '', kelurahan_id: '' });
const studentFilters = reactive({ sekolah_id: '', kelurahan_id: '', status: '' });
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
const isStaff = computed(() => ['ADMINISTRATOR', 'ADMIN_PUSTU'].includes(role.value));
const destinationSchool = computed(() => destinations.value.find((school) => Number(school.id) === Number(mutationForm.sekolah_tujuan_id)) ?? null);
const areaOptions = computed(() => [...new Map(schoolOptions.value
    .filter((school) => school.kelurahan)
    .map((school) => [school.kelurahan.id, school.kelurahan])).values()]);

function statusLabel(value) {
    return labels.status[value] ?? value ?? '—';
}

function mutationLabel(value) {
    return labels.mutation[value] ?? value ?? '—';
}
const tabs = computed(() => {
    if (role.value === 'ADMINISTRATOR') return [
        { id: 'guru', label: 'Verifikasi Guru' }, { id: 'siswi', label: 'Data Siswi' }, { id: 'mutasi', label: 'Mutasi' },
    ];
    if (role.value === 'ADMIN_PUSTU') return [
        { id: 'guru', label: 'Verifikasi Guru' }, { id: 'mutasi', label: 'Mutasi' },
    ];
    if (role.value === 'GURU') return [
        { id: 'kelas', label: 'Kelas & Token' }, { id: 'siswi', label: 'Siswi' }, { id: 'mutasi', label: 'Mutasi' },
    ];
    return [{ id: 'profil', label: 'Data Saya' }];
});

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
        if (role.value === 'ADMINISTRATOR' || role.value === 'ADMIN_PUSTU') {
            const teacherQuery = new URLSearchParams();
            if (teacherIdentity.value) teacherQuery.set('nuptk', teacherIdentity.value);
            if (teacherStatus.value) teacherQuery.set('status', teacherStatus.value);
            const [teacherPayload, mutationPayload, studentPayload, destinationPayload, schoolPayload] = await Promise.all([
                api(`/api/guru${teacherQuery.size ? `?${teacherQuery}` : ''}`),
                api('/api/mutasi-siswi'),
                api('/api/siswi'),
                api('/api/mutasi-siswi/sekolah-tujuan'),
                api('/api/sekolah'),
            ]);
            teachers.value = teacherPayload.data?.data ?? [];
            mutations.value = mutationPayload.data?.data ?? [];
            students.value = studentPayload.data?.data ?? [];
            destinations.value = destinationPayload.data ?? [];
            schoolOptions.value = schoolPayload.data?.data ?? [];
        } else if (role.value === 'GURU') {
            const [classPayload, tokenPayload, yearPayload, studentPayload, mutationPayload, destinationPayload] = await Promise.all([
                api('/api/guru/kelas'), api('/api/guru/token-sekolah'), api('/api/tahun-ajaran'), api('/api/siswi'),
                api('/api/mutasi-siswi'), api('/api/mutasi-siswi/sekolah-tujuan'),
            ]);
            classes.value = classPayload.data ?? [];
            tokens.value = tokenPayload.data ?? [];
            years.value = yearPayload.data?.data ?? [];
            students.value = studentPayload.data?.data ?? [];
            mutations.value = mutationPayload.data?.data ?? [];
            destinations.value = destinationPayload.data ?? [];
            form.yearId = years.value.find((year) => year.aktif)?.id ?? '';
        } else if (role.value === 'SISWI') {
            profile.value = (await api('/api/siswi/saya')).data;
        }
        activeTab.value = tabs.value[0]?.id ?? 'utama';
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        loading.value = false;
    }
}

async function loadStudents() {
    const query = new URLSearchParams();
    if (studentIdentity.value) query.set('nisn', studentIdentity.value);
    Object.entries(studentFilters).forEach(([key, value]) => { if (value) query.set(key, value); });
    const payload = await api(`/api/siswi${query.size ? `?${query}` : ''}`);
    students.value = payload.data?.data ?? [];
}

async function searchTeachers() {
    errorMessage.value = '';
    try {
        const query = new URLSearchParams();
        if (teacherIdentity.value) query.set('nuptk', teacherIdentity.value);
        if (teacherStatus.value) query.set('status', teacherStatus.value);
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
        activeTab.value = 'kelas';
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
        activeTab.value = 'kelas';
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
        activeTab.value = 'kelas';
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
        activeTab.value = 'siswi';
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
        activeTab.value = 'mutasi';
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
        activeTab.value = 'mutasi';
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

onMounted(load);
</script>

<template>
    <AppLayout>
        <div class="page-content tahap-dua-page">
            <header class="page-heading">
                <div><p class="eyebrow">PROGRAM TTD</p><h1>Guru, Siswi & Mutasi</h1></div>
                <span class="role-badge">{{ role }}</span>
            </header>

            <nav class="master-tabs tahap-dua-tabs" role="tablist" aria-label="Fitur Tahap 2">
                <button v-for="tab in tabs" :key="tab.id" type="button" role="tab" :aria-selected="activeTab === tab.id" :class="{ 'master-tab--active': activeTab === tab.id }" @click="activeTab = tab.id">{{ tab.label }}</button>
            </nav>

            <p v-if="errorMessage" class="management-error" role="alert">{{ errorMessage }}</p>
            <p v-if="successMessage" class="management-success" role="status">{{ successMessage }}</p>
            <p v-if="loading" class="management-state" role="status">Memuat data...</p>

            <template v-if="activeTab === 'guru' && isStaff">
                <form class="management-search" @submit.prevent="searchTeachers">
                    <BaseField id="teacher-search" v-model="teacherIdentity" label="Cari NUPTK lengkap" inputmode="numeric" :min-length="16" :max-length="16" />
                    <label class="management-field"><span>Status</span><select v-model="teacherStatus"><option value="">Semua status</option><option value="MENUNGGU_VERIFIKASI">Menunggu verifikasi</option><option value="AKTIF">Aktif</option><option value="DITOLAK">Ditolak</option><option value="NONAKTIF">Nonaktif</option></select></label>
                    <label class="management-field"><span>Sekolah</span><select v-model="teacherFilters.sekolah_id"><option value="">Semua sekolah</option><option v-for="school in schoolOptions" :key="school.id" :value="school.id">{{ school.nama }}</option></select></label>
                    <label class="management-field"><span>Kelurahan</span><select v-model="teacherFilters.kelurahan_id"><option value="">Semua kelurahan</option><option v-for="area in areaOptions" :key="area.id" :value="area.id">{{ area.nama }}</option></select></label>
                    <BaseButton type="submit" variant="secondary">Cari Guru</BaseButton>
                </form>
                <section class="stage-section">
                    <div class="stage-section__heading"><div><p class="eyebrow">MENUNGGU TINDAKAN</p><h2>Pendaftaran Guru</h2></div><span class="role-badge">{{ teachers.length }} menunggu</span></div>
                    <div class="data-table-scroll" role="region" aria-label="Pendaftaran Guru" tabindex="0">
                        <table class="data-table"><thead><tr><th>Nama Guru</th><th>NUPTK</th><th>Sekolah</th><th>Kelurahan</th><th>Status</th><th>Aksi</th></tr></thead>
                            <tbody><tr v-for="teacher in teachers" :key="teacher.id"><td>{{ teacher.nama }}</td><td>{{ teacher.nuptk }}</td><td>{{ teacher.sekolah?.nama }}</td><td>{{ teacher.sekolah?.kelurahan }}</td><td>{{ statusLabel(teacher.user?.status) }}</td><td><div class="table-actions"><BaseButton type="button" variant="secondary" @click="showTeacher(teacher)">Buka detail</BaseButton><template v-if="teacher.user?.status === 'MENUNGGU_VERIFIKASI'"><BaseButton type="button" variant="secondary" @click="viewTeacherDocument(teacher)">Lihat SK</BaseButton><BaseButton type="button" @click="decideTeacher(teacher, 'DISETUJUI')">Setujui</BaseButton><BaseButton type="button" variant="secondary" @click="decideTeacher(teacher, 'DITOLAK')">Tolak</BaseButton></template></div></td></tr><tr v-if="!teachers.length"><td colspan="6" class="data-table__empty">Tidak ada data Guru untuk filter ini.</td></tr></tbody>
                        </table>
                    </div>
                </section>
                <section v-if="selectedTeacher" class="stage-section student-detail"><div class="stage-section__heading"><div><p class="eyebrow">AKSES TERCATAT</p><h2>{{ selectedTeacher.nama }}</h2></div><BaseButton variant="secondary" @click="selectedTeacher = null">Tutup</BaseButton></div><p>NUPTK: <strong>{{ selectedTeacher.nuptk }}</strong></p><p>Sekolah: {{ selectedTeacher.sekolah?.nama }}</p><p>Status: {{ statusLabel(selectedTeacher.user?.status) }}</p><p v-if="selectedTeacher.alasan_penolakan">Catatan: {{ selectedTeacher.alasan_penolakan }}</p></section>
            </template>

            <template v-else-if="activeTab === 'kelas' && role === 'GURU'">
                <div class="stage-work-grid">
                    <section class="stage-section"><p class="eyebrow">TAHUN AJARAN AKTIF</p><h2>Kelas</h2>
                        <form class="management-form" @submit.prevent="createClass"><BaseField id="class-name" v-model="form.className" label="Nama kelas" required/><label class="management-field"><span>Tingkat</span><select v-model="form.grade"><option v-for="grade in [7,8,9,10,11,12,13]" :key="grade" :value="grade">{{ grade }}</option></select></label><label class="registration-consent"><input v-model="form.finalClass" type="checkbox"><span>Kelas akhir</span></label><BaseButton type="submit">Tambah kelas</BaseButton></form>
                        <ul class="stage-list"><li v-for="item in classes" :key="item.id"><strong>{{ item.nama }}</strong><span>Tingkat {{ item.tingkat }}<template v-if="item.kelas_akhir"> · Kelas akhir</template></span></li><li v-if="!classes.length" class="stage-list__empty">Belum ada kelas.</li></ul>
                    </section>
                    <section class="stage-section"><p class="eyebrow">REGISTRASI SISWI</p><h2>Token Sekolah</h2>
                        <label class="management-field"><span>Tahun ajaran</span><select v-model="form.yearId" required><option value="" disabled>Pilih tahun ajaran</option><option v-for="year in years" :key="year.id" :value="year.id">{{ year.label }}</option></select></label><BaseButton type="button" :disabled="!form.yearId" @click="createToken">Buat token baru</BaseButton>
                        <ul class="stage-list"><li v-for="token in tokens" :key="token.id"><strong>{{ token.kode }}</strong><span>{{ token.tahun_ajaran?.label }} · {{ token.aktif ? 'Aktif' : 'Tidak aktif' }}</span><BaseButton v-if="token.aktif" type="button" variant="secondary" @click="deactivateToken(token)">Nonaktifkan</BaseButton></li><li v-if="!tokens.length" class="stage-list__empty">Belum ada token.</li></ul>
                    </section>
                </div>
            </template>

            <template v-else-if="activeTab === 'siswi' && role !== 'SISWI'">
                <form class="management-search tahap-student-filters" @submit.prevent="loadStudents">
                    <BaseField v-if="role === 'ADMINISTRATOR'" id="student-search" v-model="studentIdentity" label="Cari NISN lengkap" inputmode="numeric" :min-length="10" :max-length="10" />
                    <label class="management-field"><span>Status</span><select v-model="studentFilters.status"><option value="">Semua status</option><option value="AKTIF">Aktif</option><option value="DALAM_MUTASI">Dalam mutasi</option><option value="LULUS">Lulus</option><option value="KELUAR_WILAYAH">Keluar wilayah</option><option value="PUTUS_SEKOLAH">Putus sekolah</option></select></label>
                    <label class="management-field"><span>Sekolah</span><select v-model="studentFilters.sekolah_id"><option value="">Semua sekolah</option><option v-for="school in schoolOptions" :key="school.id" :value="school.id">{{ school.nama }}</option></select></label>
                    <label class="management-field"><span>Kelurahan</span><select v-model="studentFilters.kelurahan_id"><option value="">Semua kelurahan</option><option v-for="area in areaOptions" :key="area.id" :value="area.id">{{ area.nama }}</option></select></label>
                    <BaseButton type="submit" variant="secondary">Terapkan filter</BaseButton>
                </form>
                <section class="stage-section"><div class="stage-section__heading"><div><p class="eyebrow">DATA PELACAKAN</p><h2>Daftar Siswi</h2></div></div>
                    <div class="data-table-scroll" role="region" aria-label="Daftar Siswi" tabindex="0"><table class="data-table"><thead><tr><th>Nama</th><th>NISN</th><th>Status</th><th>Sekolah/Kelas</th><th>Aksi</th></tr></thead><tbody>
                        <tr v-for="student in students" :key="student.id"><td>{{ student.nama }}</td><td>{{ student.nisn }}</td><td>{{ statusLabel(student.status_pelacakan) }}</td><td><template v-for="enrollment in student.enrollments" :key="enrollment.id"><span v-if="enrollment.status === 'AKTIF'">{{ enrollment.sekolah }} · {{ enrollment.kelas }}</span></template></td><td><div class="table-actions"><BaseButton type="button" variant="secondary" @click="showStudent(student)">Buka detail</BaseButton><label v-if="role === 'GURU' && activeForSchool(student)" class="stage-inline-select"><span class="sr-only">Pindahkan kelas</span><select v-model="activeForSchool(student).nextClassId" @change="moveStudent(activeForSchool(student))"><option value="" disabled>Ubah kelas</option><option v-for="classItem in classes" :key="classItem.id" :value="classItem.id">{{ classItem.nama }}</option></select></label></div></td></tr>
                        <tr v-if="!students.length"><td colspan="5" class="data-table__empty">Belum ada data Siswi.</td></tr>
                    </tbody></table></div>
                </section>
                <section v-if="selectedStudent" class="stage-section student-detail"><div class="stage-section__heading"><div><p class="eyebrow">AKSES TERCATAT</p><h2>{{ selectedStudent.nama }}</h2></div><BaseButton variant="secondary" @click="selectedStudent = null">Tutup</BaseButton></div><p>NISN: <strong>{{ selectedStudent.nisn }}</strong></p><p>Status: {{ statusLabel(selectedStudent.status_pelacakan) }}</p><ul class="stage-list"><li v-for="enrollment in selectedStudent.enrollments" :key="enrollment.id"><strong>{{ enrollment.sekolah }}</strong><span>{{ enrollment.kelas }} · {{ statusLabel(enrollment.status) }}</span></li></ul></section>
            </template>

            <template v-else-if="activeTab === 'mutasi' && role !== 'SISWI'">
                <section class="stage-section stage-mutation-form">
                    <p class="eyebrow">PENGAJUAN BARU</p><h2>Mutasi Siswi</h2>
                    <form class="management-form" @submit.prevent="submitMutation">
                        <label class="management-field"><span>Siswi dari enrollment aktif</span><select v-model="mutationForm.enrollment_id" required><option value="" disabled>Pilih Siswi</option><template v-for="student in students" :key="student.id"><option v-for="enrollment in student.enrollments.filter((item) => item.status === 'AKTIF')" :key="enrollment.id" :value="enrollment.id">{{ student.nama }} · {{ enrollment.sekolah }} · {{ enrollment.kelas }}</option></template></select></label>
                        <label class="management-field"><span>Jenis mutasi</span><select v-model="mutationForm.jenis" @change="mutationForm.kelas_tujuan_id = ''"><option value="PINDAH_SEKOLAH_SATU_PUSTU">Pindah sekolah, Pustu sama</option><option value="PINDAH_ANTAR_PUSTU">Pindah antar-Pustu</option><option value="NAIK_JENJANG">Naik jenjang</option><option value="KELUAR_WILAYAH">Keluar wilayah</option><option value="LULUS">Lulus</option><option value="PUTUS_SEKOLAH">Putus sekolah</option></select></label>
                        <template v-if="['PINDAH_SEKOLAH_SATU_PUSTU', 'PINDAH_ANTAR_PUSTU', 'NAIK_JENJANG'].includes(mutationForm.jenis)">
                            <label class="management-field"><span>Sekolah tujuan</span><select v-model="mutationForm.sekolah_tujuan_id" required @change="mutationForm.kelas_tujuan_id = ''"><option value="" disabled>Pilih sekolah</option><option v-for="school in destinations" :key="school.id" :value="school.id">{{ school.nama }} · {{ school.jenjang }}</option></select></label>
                            <label class="management-field"><span>Kelas tujuan</span><select v-model="mutationForm.kelas_tujuan_id" required :disabled="!destinationSchool?.kelas?.length"><option value="" disabled>Pilih kelas</option><option v-for="classItem in destinationSchool?.kelas ?? []" :key="classItem.id" :value="classItem.id">{{ classItem.nama }}</option></select></label>
                        </template>
                        <label class="management-field"><span>Tanggal efektif</span><input v-model="mutationForm.tgl_efektif" type="date" required></label>
                        <BaseField id="mutation-reason" v-model="mutationForm.alasan" label="Alasan (opsional)" :max-length="1000" />
                        <BaseButton type="submit" :disabled="!students.some((student) => student.enrollments.some((enrollment) => enrollment.id === Number(mutationForm.enrollment_id) && enrollment.status === 'AKTIF'))">Kirim pengajuan</BaseButton>
                    </form>
                </section>
                <section class="stage-section"><div class="stage-section__heading"><div><p class="eyebrow">MENUNGGU TINDAKAN</p><h2>Pengajuan Mutasi</h2></div><span class="role-badge">{{ mutations.length }} menunggu</span></div>
                    <div class="data-table-scroll" role="region" aria-label="Pengajuan Mutasi" tabindex="0"><table class="data-table"><thead><tr><th>NISN</th><th>Jenis</th><th>Asal</th><th>Tujuan</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
                        <tr v-for="mutation in mutations" :key="mutation.id"><td>{{ mutation.nisn_tersamar }}</td><td>{{ mutationLabel(mutation.jenis) }}</td><td>{{ mutation.sekolah_asal }}</td><td>{{ mutation.sekolah_tujuan ?? 'Keluar wilayah' }}</td><td>{{ statusLabel(mutation.status) }}</td><td><div class="table-actions"><template v-if="role === 'GURU'">Menunggu keputusan petugas</template><template v-else-if="mutation.perlu_konfirmasi"><select v-model="decisionState(mutation).kelas_tujuan_id"><option value="" disabled>Pilih kelas</option><option v-for="classItem in mutation.kelas_tujuan_opsi" :key="classItem.id" :value="classItem.id">{{ classItem.nama }}</option></select><BaseButton type="button" @click="reviewMutation(mutation, 'KONFIRMASI')">Konfirmasi</BaseButton></template><template v-else-if="mutation.dapat_diputuskan"><label v-if="['PINDAH_SEKOLAH_SATU_PUSTU', 'NAIK_JENJANG'].includes(mutation.jenis) && !mutation.kelas_tujuan_id" class="stage-inline-select"><span class="sr-only">Kelas tujuan</span><select v-model="decisionState(mutation).kelas_tujuan_id"><option value="" disabled>Pilih kelas</option><option v-for="classItem in mutation.kelas_tujuan_opsi" :key="classItem.id" :value="classItem.id">{{ classItem.nama }}</option></select></label><BaseButton type="button" @click="reviewMutation(mutation, 'DISETUJUI')">Setujui</BaseButton><BaseButton type="button" variant="secondary" @click="reviewMutation(mutation, 'DITOLAK')">Tolak</BaseButton></template><span v-else>Menunggu Pustu tujuan</span></div></td></tr>
                        <tr v-if="!mutations.length"><td colspan="5" class="data-table__empty">Tidak ada pengajuan mutasi yang menunggu.</td></tr>
                    </tbody></table></div>
                </section>
            </template>

            <template v-else-if="activeTab === 'profil' && role === 'SISWI' && profile">
                <section class="stage-section student-profile"><p class="eyebrow">DATA SAYA</p><h2>{{ profile.nama }}</h2><dl><div><dt>NISN</dt><dd>{{ profile.nisn }}</dd></div><div><dt>Status pelacakan</dt><dd>{{ profile.status_pelacakan }}</dd></div></dl><h3>Riwayat sekolah</h3><ul class="stage-list"><li v-for="enrollment in profile.enrollments" :key="enrollment.id"><strong>{{ enrollment.sekolah }}</strong><span>{{ enrollment.kelas }} · {{ enrollment.status }}</span></li></ul></section>
            </template>
        </div>
    </AppLayout>
</template>
