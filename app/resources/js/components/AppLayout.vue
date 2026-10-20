<script setup>
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();
const drawerOpen = ref(false);
const menuItems = computed(() => {
    const items = [{ label: 'Ringkasan', to: '/dashboard' }];

    if (auth.user?.role === 'ADMINISTRATOR') {
        items.push(
            { label: 'Pustu', to: '/pustu' },
            { label: 'Petugas Pustu', to: '/admin-pustu' },
            { label: 'Sekolah', to: '/sekolah' },
            { label: 'Guru & Siswi', to: '/program' },
            { label: 'Laporan & Pengaturan', to: '/pelaporan' },
            { label: 'Data Wilayah', to: '/data-wilayah' },
            { label: 'Log Aktivitas', to: '/log-aktivitas' },
        );
    } else if (auth.user?.role === 'ADMIN_PUSTU') {
        items.push(
            { label: 'Pustu Saya', to: '/pustu' },
            { label: 'Sekolah Binaan', to: '/sekolah' },
            { label: 'Guru & Siswi', to: '/program' },
            { label: 'Riwayat Tindakan', to: '/log-aktivitas' },
        );
    } else if (auth.user?.role === 'GURU') {
        items.push(
            { label: 'Guru & Siswi', to: '/program' },
            { label: 'Laporan Mingguan', to: '/pelaporan' },
        );
    } else if (auth.user?.role === 'SISWI') {
        items.push(
            { label: 'Data Saya', to: '/program' },
            { label: 'Laporan Mingguan', to: '/pelaporan' },
        );
    }

    items.push({ label: 'Riwayat Login Saya', to: '/riwayat-login' });

    return items;
});

async function logout() {
    await auth.logout();
    await router.replace('/login');
}
</script>

<template>
    <div class="app-shell">
        <button
            v-if="drawerOpen"
            class="drawer-scrim"
            type="button"
            aria-label="Tutup menu"
            @click="drawerOpen = false"
        ></button>

        <aside class="sidebar" :class="{ 'sidebar--open': drawerOpen }">
            <a class="brand" href="/dashboard" aria-label="Aplikasi TTD Puskesmas, ringkasan">
                <span class="brand__mark" aria-hidden="true">T</span>
                <span class="brand__text">Program TTD<span>Puskesmas</span></span>
            </a>

            <nav class="sidebar__nav" aria-label="Navigasi utama">
                <RouterLink
                    v-for="item in menuItems"
                    :key="item.to"
                    class="sidebar__link"
                    active-class="sidebar__link--active"
                    :to="item.to"
                    @click="drawerOpen = false"
                >
                    <span class="sidebar__indicator" aria-hidden="true"></span>
                    {{ item.label }}
                </RouterLink>
            </nav>

            <div class="sidebar__bottom">
                <div class="account-chip">
                    <span class="account-chip__avatar" aria-hidden="true">{{ auth.user?.role?.charAt(0) ?? 'A' }}</span>
                    <span class="account-chip__identity">
                        <strong>{{ auth.user?.username ?? 'Akun aktif' }}</strong>
                        <small>{{ auth.user?.role ?? 'Pengguna' }}</small>
                    </span>
                </div>
                <button class="sidebar__logout" type="button" @click="logout">Keluar</button>
            </div>
        </aside>

        <main class="app-main">
            <header class="mobile-header">
                <button
                    class="icon-button mobile-header__menu"
                    type="button"
                    aria-label="Buka menu"
                    :aria-expanded="drawerOpen"
                    @click="drawerOpen = true"
                >
                    <span aria-hidden="true">☰</span>
                </button>
                <span class="mobile-header__title">Program TTD</span>
            </header>
            <slot />
        </main>
    </div>
</template>