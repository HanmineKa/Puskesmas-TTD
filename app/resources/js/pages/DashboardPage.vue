<script setup>
import { computed, onMounted, ref } from 'vue';
import AppLayout from '../components/AppLayout.vue';
import InstallPrompt from '../components/InstallPrompt.vue';
import DataTable from '../components/DataTable.vue';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const notifications = ref([]);
const roleLabels = {
    ADMINISTRATOR: 'Administrator',
    ADMIN_PUSTU: 'Admin Pustu',
    GURU: 'Guru',
    SISWI: 'Siswi',
};
const roleLabel = computed(() => roleLabels[auth.user?.role] ?? 'Pengguna');
const dashboardContent = computed(() => ({
    ADMINISTRATOR: {
        eyebrow: 'PENGELOLAAN PROGRAM',
        title: 'Pantau program dari satu tempat.',
        description: 'Kelola Puskesmas Pembantu, sekolah, Guru dan Siswi, wilayah, pelaporan, serta catatan aktivitas sesuai kewenangan Administrator.',
        actions: [
            { label: 'Kelola sekolah', detail: 'Data dan pembinaan sekolah', to: '/sekolah', tone: 'green' },
            { label: 'Data Guru', detail: 'Pencarian identitas dan sekolah', to: '/program/data-guru', tone: 'mint' },
            { label: 'Verifikasi Guru', detail: 'Tinjau pendaftaran dan dokumen', to: '/program/verifikasi-guru', tone: 'plain' },
            { label: 'Data Siswi', detail: 'Pencarian dan riwayat sekolah', to: '/program/data-siswi', tone: 'plain' },
            { label: 'Buka laporan', detail: 'Pemantauan dan pengaturan pelaporan', to: '/pelaporan', tone: 'plain' },
            { label: 'Catatan aktivitas', detail: 'Riwayat tindakan dan akses', to: '/log-aktivitas', tone: 'plain' },
        ],
    },
    ADMIN_PUSTU: {
        eyebrow: 'WILAYAH PUSKESMAS PEMBANTU',
        title: 'Fokus pada sekolah binaan Anda.',
        description: 'Tinjau pendaftaran Guru, pantau siswi, dan tindak lanjuti aktivitas di wilayah kerja Pustu.',
        actions: [
            { label: 'Sekolah binaan', detail: 'Daftar dan informasi sekolah', to: '/sekolah', tone: 'green' },
            { label: 'Data Guru', detail: 'Guru pada wilayah Pustu', to: '/program/data-guru', tone: 'mint' },
            { label: 'Verifikasi Guru', detail: 'Tinjau pendaftaran Guru', to: '/program/verifikasi-guru', tone: 'plain' },
            { label: 'Data Siswi', detail: 'Siswi pada wilayah Pustu', to: '/program/data-siswi', tone: 'plain' },
            { label: 'Mutasi Siswi', detail: 'Validasi perpindahan sekolah', to: '/program/mutasi', tone: 'plain' },
            { label: 'Riwayat tindakan', detail: 'Aktivitas operasional wilayah', to: '/log-aktivitas', tone: 'plain' },
        ],
    },
    GURU: {
        eyebrow: 'RUANG KERJA SEKOLAH',
        title: 'Pantau kelas dan laporan siswi.',
        description: 'Kelola kelas, lihat data siswi, periksa bukti laporan, dan tindak lanjuti pemantauan mingguan.',
        actions: [
            { label: 'Kelas', detail: 'Kelas dan daftar token sekolah', to: '/program/kelas', tone: 'green' },
            { label: 'Data Siswi', detail: 'Daftar, kelas, dan status pelacakan', to: '/program/data-siswi', tone: 'mint' },
            { label: 'Pemantauan mingguan', detail: 'Periksa laporan dan foto bukti', to: '/pelaporan', tone: 'mint' },
        ],
    },
    SISWI: {
        eyebrow: 'RUANG SISWI',
        title: 'Catat dan pantau laporan TTD Anda.',
        description: 'Kirim laporan mingguan, unggah bukti, dan lihat riwayat serta hasil pemeriksaan Guru.',
        actions: [
            { label: 'Lapor minum TTD', detail: 'Kirim laporan minggu ini', to: '/pelaporan', tone: 'green' },
            { label: 'Data saya', detail: 'Lihat informasi sekolah dan kelas', to: '/program', tone: 'mint' },
        ],
    },
}[auth.user?.role] ?? {
    eyebrow: 'PROGRAM TTD SISWI',
    title: 'Selamat datang.',
    description: 'Pilih ruang kerja untuk melanjutkan.',
    actions: [],
}));
const currentDate = computed(() => new Intl.DateTimeFormat('id-ID', { dateStyle: 'full' }).format(new Date()));

async function loadNotifications() {
    try {
        const response = await fetch('/api/notifikasi', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (response.ok) notifications.value = (await response.json()).data ?? [];
    } catch {
        notifications.value = [];
    }
}

async function markAsRead(notification) {
    const response = await fetch(`/api/notifikasi/${notification.id}/dibaca`, {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=') ?? '') },
    });
    if (response.ok) notification.dibaca_at = new Date().toISOString();
}

onMounted(loadNotifications);
</script>

<template>
    <AppLayout>
        <div class="page-content">
            <header class="page-heading">
                <div>
                    <p class="eyebrow">{{ dashboardContent.eyebrow }}</p>
                    <h1>Beranda</h1>
                </div>
                <div class="page-heading__meta">
                    <span class="role-badge">{{ roleLabel }}</span>
                    <time class="dashboard-date">{{ currentDate }}</time>
                </div>
            </header>

            <InstallPrompt />

            <section v-if="notifications.length" class="dashboard-notifications" aria-labelledby="notifications-title">
                <header>
                    <div><p class="eyebrow">PEMBERITAHUAN</p><h2 id="notifications-title">Informasi untuk Anda</h2></div>
                    <span class="dashboard-notifications__count">{{ notifications.filter((item) => !item.dibaca_at).length }} belum dibaca</span>
                </header>
                <ul>
                    <li v-for="notification in notifications" :key="notification.id" :class="{ 'dashboard-notification--unread': !notification.dibaca_at }">
                        <div><strong>{{ notification.judul }}</strong><p>{{ notification.isi }}</p></div>
                        <button v-if="!notification.dibaca_at" type="button" @click="markAsRead(notification)">Tandai dibaca</button>
                    </li>
                </ul>
            </section>

            <section class="dashboard-welcome" aria-labelledby="dashboard-welcome-title">
                <div class="dashboard-welcome__copy">
                    <p class="eyebrow">{{ currentDate }}</p>
                    <h2 id="dashboard-welcome-title">{{ dashboardContent.title }}</h2>
                    <p>{{ dashboardContent.description }}</p>
                </div>
                <span class="dashboard-welcome__mark" aria-hidden="true">TTD</span>
            </section>

            <section class="dashboard-workspace" aria-labelledby="dashboard-workspace-title">
                <header class="section-heading">
                    <div><p class="eyebrow">AKSES RUANG KERJA</p><h2 id="dashboard-workspace-title">Apa yang ingin Anda kerjakan?</h2></div>
                </header>
                <div class="dashboard-action-grid">
                    <RouterLink
                        v-for="(action, index) in dashboardContent.actions"
                        :key="action.to"
                        class="dashboard-action"
                        :class="[`dashboard-action--${action.tone}`, { 'dashboard-action--primary': index === 0 }]"
                        :to="action.to"
                    >
                        <span class="dashboard-action__index">0{{ index + 1 }}</span>
                        <span class="dashboard-action__content"><strong>{{ action.label }}</strong><small>{{ action.detail }}</small></span>
                        <span class="dashboard-action__arrow" aria-hidden="true">→</span>
                    </RouterLink>
                </div>
            </section>
        </div>
    </AppLayout>
</template>