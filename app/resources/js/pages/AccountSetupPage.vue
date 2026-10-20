<script setup>
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import BaseButton from '../components/BaseButton.vue';
import BaseField from '../components/BaseField.vue';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();
const currentPassword = ref('');
const password = ref('');
const passwordConfirmation = ref('');
const errorMessage = ref('');
const successMessage = ref('');
const needsPassword = computed(() => auth.setupRequired.includes('GANTI_SANDI'));

async function savePassword() {
    errorMessage.value = '';
    successMessage.value = '';

    try {
        await auth.changeTemporaryPassword({
            current_password: currentPassword.value,
            password: password.value,
            password_confirmation: passwordConfirmation.value,
        });
        currentPassword.value = '';
        password.value = '';
        passwordConfirmation.value = '';
        successMessage.value = 'Kata sandi berhasil diperbarui.';
        await router.replace('/dashboard');
    } catch (error) {
        errorMessage.value = error.message;
    }
}

</script>

<template>
    <main class="account-setup">
        <header class="account-setup__header">
            <p class="eyebrow">KEAMANAN AKUN</p>
            <h1>Siapkan akun petugas</h1>
            <p>Selesaikan langkah keamanan ini sebelum membuka ruang kerja.</p>
        </header>

        <section v-if="needsPassword" class="account-setup__step" aria-labelledby="password-step-title">
            <p class="account-setup__step-number">01</p>
            <div class="account-setup__step-content">
                <h2 id="password-step-title">Ganti kata sandi sementara</h2>
                <form class="account-setup__form" @submit.prevent="savePassword">
                    <BaseField id="current-password" v-model="currentPassword" label="Kata sandi sementara" type="password" autocomplete="current-password" required />
                    <BaseField id="new-password" v-model="password" label="Kata sandi baru" type="password" autocomplete="new-password" :min-length="8" :max-length="15" required />
                    <BaseField id="password-confirmation" v-model="passwordConfirmation" label="Ulangi kata sandi baru" type="password" autocomplete="new-password" :min-length="8" :max-length="15" required />
                    <BaseButton type="submit">Simpan kata sandi</BaseButton>
                </form>
            </div>
        </section>

        <p v-if="errorMessage" class="account-setup__error" role="alert">{{ errorMessage }}</p>
        <p v-if="successMessage" class="account-setup__success" role="status">{{ successMessage }}</p>
    </main>
</template>
