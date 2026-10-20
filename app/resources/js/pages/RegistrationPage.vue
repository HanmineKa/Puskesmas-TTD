<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import BaseButton from '../components/BaseButton.vue';
import BaseField from '../components/BaseField.vue';

const route = useRoute();
const router = useRouter();
const kind = computed(() => route.name === 'registrasi-guru' ? 'GURU' : 'SISWI');
const isTeacher = computed(() => kind.value === 'GURU');
const title = computed(() => isTeacher.value ? 'Pendaftaran Guru' : 'Pendaftaran Siswi');
const schools = ref([]);
const classes = ref([]);
const selectedFile = ref(null);
const loading = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const schoolInfo = ref(null);
const form = reactive({
    sekolah_id: '',
    nama: '',
    identity: '',
    password: '',
    password_confirmation: '',
    kode_token: '',
    kelas_id: '',
    persetujuan_data: false,
});

function csrfToken() {
    const cookie = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.split('=').slice(1).join('=')) : '';
}

async function loadSchools() {
    if (! isTeacher.value) return;
    try {
        const response = await fetch('/api/sekolah-terdaftar', { headers: { Accept: 'application/json' } });
        const payload = await response.json();
        if (! response.ok) throw new Error(payload.message ?? 'Daftar sekolah tidak dapat dimuat.');
        schools.value = payload.data ?? [];
    } catch (error) {
        errorMessage.value = error.message;
    }
}

async function checkToken() {
    errorMessage.value = '';
    classes.value = [];
    schoolInfo.value = null;
    form.kelas_id = '';
    if (! form.kode_token.trim()) return;

    try {
        const response = await fetch(`/api/token-sekolah/${encodeURIComponent(form.kode_token.trim())}/kelas`, {
            headers: { Accept: 'application/json' },
        });
        const payload = await response.json();
        if (! response.ok) throw new Error(payload.errors?.kode_token?.[0] ?? payload.message ?? 'Token tidak dapat diperiksa.');
        schoolInfo.value = { ...payload.sekolah, tahun_ajaran: payload.tahun_ajaran };
        classes.value = payload.kelas ?? [];
    } catch (error) {
        errorMessage.value = error.message;
    }
}

async function submit() {
    loading.value = true;
    errorMessage.value = '';
    successMessage.value = '';

    try {
        await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
        const body = isTeacher.value ? new FormData() : {
            nama: form.nama,
            nisn: form.identity,
            password: form.password,
            password_confirmation: form.password_confirmation,
            kode_token: form.kode_token.trim(),
            kelas_id: Number(form.kelas_id),
            persetujuan_data: form.persetujuan_data,
            versi_persetujuan: 'v1',
        };
        if (isTeacher.value) {
            body.append('sekolah_id', form.sekolah_id);
            body.append('nama', form.nama);
            body.append('nuptk', form.identity);
            body.append('password', form.password);
            body.append('password_confirmation', form.password_confirmation);
            body.append('dokumen_sk', selectedFile.value);
        }
        const response = await fetch(isTeacher.value ? '/api/guru/registrasi' : '/api/siswi/registrasi', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
                ...(isTeacher.value ? {} : { 'Content-Type': 'application/json' }),
            },
            body: isTeacher.value ? body : JSON.stringify(body),
        });
        const payload = await response.json();
        if (! response.ok) throw new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? 'Pendaftaran tidak dapat diproses.');
        successMessage.value = payload.message;
        if (isTeacher.value) {
            form.password = '';
            form.password_confirmation = '';
            form.identity = '';
            selectedFile.value = null;
        } else if (response.status === 201) {
            await router.replace('/login');
        } else {
            form.password = '';
            form.password_confirmation = '';
            form.identity = '';
        }
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        loading.value = false;
    }
}

onMounted(loadSchools);
</script>

<template>
    <main class="registration-page">
        <header class="registration-header">
            <a class="brand" href="/login" aria-label="Kembali ke masuk">
                <span class="brand__mark" aria-hidden="true">T</span>
                <span class="brand__text">Program TTD<span>Puskesmas Pesanggrahan</span></span>
            </a>
            <RouterLink class="registration-login-link" to="/login">Masuk</RouterLink>
        </header>

        <div class="registration-layout">
            <section class="registration-intro">
                <p class="eyebrow">AKSES PROGRAM</p>
                <h1>{{ title }}</h1>
                <p v-if="isTeacher">Pilih sekolah tempat bertugas. Akun akan aktif setelah diverifikasi petugas.</p>
                <p v-else>Gunakan token dari sekolah untuk membuat akun dan memilih kelas.</p>
            </section>

            <form class="registration-form" @submit.prevent="submit">
                <template v-if="isTeacher">
                    <label class="management-field">
                        <span>Sekolah</span>
                        <select v-model="form.sekolah_id" required>
                            <option value="" disabled>Pilih sekolah</option>
                            <option v-for="school in schools" :key="school.id" :value="school.id">{{ school.nama }} · {{ school.jenjang }} · {{ school.npsn }}</option>
                        </select>
                    </label>
                    <BaseField id="registration-name" v-model="form.nama" label="Nama lengkap" :max-length="150" required />
                    <BaseField id="registration-identity" v-model="form.identity" label="NUPTK (16 angka)" inputmode="numeric" :min-length="16" :max-length="16" required />
                    <label class="management-field">
                        <span>SK atau Surat Tugas (PDF, JPG, PNG; maks. 5 MB)</span>
                        <input type="file" accept="application/pdf,image/jpeg,image/png" required @change="selectedFile = $event.target.files?.[0] ?? null">
                    </label>
                </template>
                <template v-else>
                    <BaseField id="registration-name" v-model="form.nama" label="Nama lengkap" :max-length="150" required />
                    <BaseField id="registration-identity" v-model="form.identity" label="NISN (10 angka)" inputmode="numeric" :min-length="10" :max-length="10" required />
                    <div class="registration-token-row">
                        <BaseField id="registration-token" v-model="form.kode_token" label="Token sekolah" :max-length="30" required />
                        <BaseButton type="button" variant="secondary" @click="checkToken">Periksa</BaseButton>
                    </div>
                    <p v-if="schoolInfo" class="registration-school" role="status">
                        {{ schoolInfo.nama }} · {{ schoolInfo.jenjang }} · Tahun ajaran {{ schoolInfo.tahun_ajaran }}
                    </p>
                    <label class="management-field">
                        <span>Kelas</span>
                        <select v-model="form.kelas_id" :disabled="classes.length === 0" required>
                            <option value="" disabled>Pilih kelas</option>
                            <option v-for="classItem in classes" :key="classItem.id" :value="classItem.id">{{ classItem.nama }}</option>
                        </select>
                    </label>
                    <label class="registration-consent">
                        <input v-model="form.persetujuan_data" type="checkbox" required>
                        <span>Saya menyatakan telah memperoleh persetujuan orang tua/wali untuk penggunaan data dalam program ini.</span>
                    </label>
                </template>

                <BaseField id="registration-password" v-model="form.password" label="Kata sandi (8–15 karakter)" type="password" autocomplete="new-password" :min-length="8" :max-length="15" required />
                <BaseField id="registration-password-confirmation" v-model="form.password_confirmation" label="Ulangi kata sandi" type="password" autocomplete="new-password" :min-length="8" :max-length="15" required />
                <p v-if="errorMessage" class="management-error" role="alert">{{ errorMessage }}</p>
                <p v-if="successMessage" class="management-success" role="status">{{ successMessage }}</p>
                <BaseButton type="submit" :loading="loading">Daftar</BaseButton>
            </form>
        </div>
    </main>
</template>