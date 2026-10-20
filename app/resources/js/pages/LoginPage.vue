<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import BaseButton from '../components/BaseButton.vue';
import BaseField from '../components/BaseField.vue';
import { useAuthStore } from '../stores/auth';

const router = useRouter();
const auth = useAuthStore();
const identitas = ref('');
const password = ref('');
const errorMessage = ref('');

async function submit() {
    errorMessage.value = '';

    try {
        const payload = await auth.login({
            identitas: identitas.value,
            password: password.value,
        });
        await router.replace(payload.requires_account_setup ? '/setup-akun' : '/dashboard');
    } catch (error) {
        errorMessage.value = error.message;
    }
}
</script>

<template>
    <main class="login-page">
        <section class="login-aside" aria-label="Program Tablet Tambah Darah">
            <div class="login-aside__brand">
                <span class="brand__mark" aria-hidden="true">T</span>
                <span>Program TTD</span>
            </div>
            <div class="login-aside__message">
                <p class="eyebrow">PUSKESMAS PESANGGRAHAN</p>
                <h1>Pemantauan Tablet Tambah Darah</h1>
                <p>Aplikasi pemantauan distribusi dan kepatuhan konsumsi TTD siswi SMP/SMA/SMK.</p>
            </div>
            <div class="login-aside__rule" aria-hidden="true"></div>
        </section>

        <section class="login-panel" aria-labelledby="login-title">
            <div class="login-panel__content">
                <p class="eyebrow">SELAMAT DATANG</p>
                <h2 id="login-title">Masuk ke akun</h2>
                <p class="login-panel__intro">Gunakan identitas akun yang terdaftar.</p>

                <form class="login-form" @submit.prevent="submit">
                    <BaseField
                        id="identitas"
                        v-model="identitas"
                        label="NIP, NIK, NUPTK, atau NISN"
                        autocomplete="username"
                        required
                    />
                    <BaseField
                        id="password"
                        v-model="password"
                        label="Kata sandi"
                        type="password"
                        autocomplete="current-password"
                        :min-length="8"
                        :max-length="15"
                        required
                    />
                    <p v-if="errorMessage" class="login-form__error" role="alert">{{ errorMessage }}</p>
                    <BaseButton type="submit" :loading="auth.loading" class="login-form__submit">
                        Masuk
                    </BaseButton>
                </form>

                <nav class="login-registration-links" aria-label="Pendaftaran akun">
                    <RouterLink to="/registrasi/guru">Daftar sebagai Guru</RouterLink>
                    <RouterLink to="/registrasi/siswi">Daftar sebagai Siswi</RouterLink>
                </nav>

            </div>
        </section>
    </main>
</template>