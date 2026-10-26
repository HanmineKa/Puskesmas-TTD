<script setup>
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();
const drawerOpen = ref(false);
const expandedGroups = ref({});
const roleLabels = {
    ADMINISTRATOR: 'Administrator',
    ADMIN_PUSTU: 'Admin Pustu',
    GURU: 'Guru',
    SISWI: 'Siswi',
};
const roleLabel = computed(() => roleLabels[auth.user?.role] ?? 'Pengguna');
const menuItems = computed(() => {
    const home = { label: 'Beranda', to: '/dashboard' };

    if (auth.user?.role === 'ADMINISTRATOR') {
        return [
            home,
            {
                label: 'Puskesmas Pembantu',
                children: [
                    { label: 'Data Pustu', to: '/pustu' },
                    { label: 'Petugas Pustu', to: '/admin-pustu' },
                ],
            },
            {
                label: 'Sekolah',
                children: [
                    { label: 'Data Sekolah', to: '/sekolah' },
                    { label: 'Verifikasi & Validasi Guru', to: '/program/verifikasi-guru' },
                    { label: 'Tahun Ajaran', to: '/sekolah/tahun-ajaran' },
                ],
            },
            {
                label: 'Guru & Siswi',
                children: [
                    { label: 'Data Guru', to: '/program/data-guru' },
                    { label: 'Data Siswi', to: '/program/data-siswi' },
                ],
            },
            {
                label: 'Wilayah',
                children: [
                    { label: 'Kecamatan', to: '/wilayah/kecamatan' },
                    { label: 'Kelurahan', to: '/wilayah/kelurahan' },
                ],
            },
            { label: 'Laporan & Pengaturan', to: '/pelaporan' },
            { label: 'Catatan Aktivitas', to: '/log-aktivitas' },
        ];
    } else if (auth.user?.role === 'ADMIN_PUSTU') {
        return [
            home,
            { label: 'Pustu Saya', to: '/pustu' },
            {
                label: 'Sekolah Binaan',
                children: [
                    { label: 'Data Sekolah', to: '/sekolah' },
                    { label: 'Verifikasi & Validasi Guru', to: '/program/verifikasi-guru' },
                ],
            },
            {
                label: 'Guru',
                children: [
                    { label: 'Data Guru', to: '/program/data-guru' },
                ],
            },
            {
                label: 'Siswi',
                children: [
                    { label: 'Data Siswi', to: '/program/data-siswi' },
                    { label: 'Mutasi', to: '/program/mutasi' },
                ],
            },
            { label: 'Riwayat Tindakan', to: '/log-aktivitas' },
        ];
    } else if (auth.user?.role === 'GURU') {
        return [
            home,
            { label: 'Kelas', to: '/program/kelas' },
            {
                label: 'Siswi',
                children: [
                    { label: 'Data Siswi', to: '/program/data-siswi' },
                    { label: 'Mutasi', to: '/program/mutasi' },
                ],
            },
            { label: 'Token Sekolah', to: '/program/token' },
            { label: 'Pemantauan', to: '/pelaporan' },
        ];
    } else if (auth.user?.role === 'SISWI') {
        return [
            home,
            { label: 'Data Saya', to: '/program/data-saya' },
            { label: 'Lapor Minum TTD', to: '/pelaporan' },
            { label: 'Riwayat Laporan', to: '/pelaporan/riwayat' },
        ];
    }

    return [home];
});

watch(() => [router.currentRoute.value.path, auth.user?.role], () => {
    const activeGroup = menuItems.value.find((item) => item.children?.some((child) => (
        router.currentRoute.value.path === child.to || router.currentRoute.value.path.startsWith(`${child.to}/`)
    )));
    if (activeGroup) expandedGroups.value[activeGroup.label] = true;
}, { immediate: true });

function toggleGroup(label) {
    expandedGroups.value[label] = ! expandedGroups.value[label];
}

function isGroupOpen(label) {
    return Boolean(expandedGroups.value[label]);
}

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
                <span class="brand__text">Program TTD<span>Sistem Informasi Puskesmas</span></span>
            </a>

            <nav class="sidebar__nav" aria-label="Navigasi utama">
                <template v-for="item in menuItems" :key="item.label">
                    <div v-if="item.children" class="sidebar__group">
                        <button
                            class="sidebar__group-toggle"
                            :class="{ 'sidebar__link--active': item.children.some((child) => router.currentRoute.value.path === child.to) }"
                            type="button"
                            :aria-expanded="isGroupOpen(item.label)"
                            @click="toggleGroup(item.label)"
                        >
                            <span class="sidebar__indicator" aria-hidden="true"></span>
                            <span>{{ item.label }}</span>
                            <span class="sidebar__chevron" :class="{ 'sidebar__chevron--open': isGroupOpen(item.label) }" aria-hidden="true">›</span>
                        </button>
                        <div v-if="isGroupOpen(item.label)" class="sidebar__subnav">
                            <RouterLink
                                v-for="child in item.children"
                                :key="child.to"
                                class="sidebar__link sidebar__link--nested"
                                active-class="sidebar__link--active"
                                :to="child.to"
                                @click="drawerOpen = false"
                            >
                                {{ child.label }}
                            </RouterLink>
                        </div>
                    </div>
                    <RouterLink
                        v-else
                        class="sidebar__link"
                        active-class="sidebar__link--active"
                        :to="item.to"
                        @click="drawerOpen = false"
                    >
                        <span class="sidebar__indicator" aria-hidden="true"></span>
                        {{ item.label }}
                    </RouterLink>
                </template>
            </nav>

            <div class="sidebar__bottom">
                <div class="account-chip">
                    <span class="account-chip__avatar" aria-hidden="true">{{ roleLabel.charAt(0) }}</span>
                    <span class="account-chip__identity">
                        <strong>{{ auth.user?.nama_pengguna ?? auth.user?.username ?? 'Akun aktif' }}</strong>
                        <small>{{ roleLabel }}</small>
                    </span>
                </div>
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
            <header class="app-topbar">
                <p class="app-topbar__context">SISTEM INFORMASI PROGRAM TTD SISWI</p>
                <details class="account-menu">
                    <summary class="account-menu__trigger" aria-label="Buka menu profil pengguna">
                        <span class="account-menu__avatar" aria-hidden="true">{{ roleLabel.charAt(0) }}</span>
                        <span class="account-menu__label">Akun</span>
                        <span class="account-menu__chevron" aria-hidden="true">⌄</span>
                    </summary>
                    <div class="account-menu__panel">
                        <p><strong>{{ auth.user?.nama_pengguna ?? auth.user?.username ?? 'Akun aktif' }}</strong><span>{{ roleLabel }}</span></p>
                        <RouterLink to="/riwayat-login">Riwayat Masuk Akun</RouterLink>
                        <button type="button" @click="logout">Keluar</button>
                    </div>
                </details>
            </header>
            <slot />
        </main>
    </div>
</template>