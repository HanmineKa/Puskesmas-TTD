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
const workspaceMessage = computed(() => ({
    ADMINISTRATOR: 'Kelola verifikasi Guru, data Siswi, dan pengajuan mutasi dari ruang kerja Tahap 2.',
    ADMIN_PUSTU: 'Tinjau pendaftaran Guru dan proses mutasi Siswi di wilayah Pustu Anda.',
    GURU: 'Pantau laporan mingguan, validasi foto bukti, dan tindak lanjuti Siswi di sekolah Anda.',
    SISWI: 'Kirim laporan mingguan TTD dan lihat status validasi foto bukti Anda.',
}[auth.user?.role] ?? 'Data pemantauan program TTD.'));
const workspaceTarget = computed(() => ['GURU', 'SISWI'].includes(auth.user?.role) ? '/pelaporan' : '/program');
const workspaceLinkLabel = computed(() => workspaceTarget.value === '/pelaporan' ? 'Buka Laporan Mingguan' : 'Buka fitur Tahap 2');

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
                    <p class="eyebrow">RUANG KERJA</p>
                    <h1>Ringkasan</h1>
                </div>
                <span class="role-badge">{{ roleLabel }}</span>
            </header>

            <InstallPrompt />

            <section v-if="notifications.length" class="dashboard-notifications" aria-labelledby="notifications-title">
                <header><h2 id="notifications-title">Pemberitahuan</h2><span>{{ notifications.filter((item) => !item.dibaca_at).length }} belum dibaca</span></header>
                <ul>
                    <li v-for="notification in notifications" :key="notification.id" :class="{ 'dashboard-notification--unread': !notification.dibaca_at }">
                        <div><strong>{{ notification.judul }}</strong><p>{{ notification.isi }}</p></div>
                        <button v-if="!notification.dibaca_at" type="button" @click="markAsRead(notification)">Tandai dibaca</button>
                    </li>
                </ul>
            </section>

            <section class="dashboard-empty" aria-labelledby="dashboard-empty-title">
                <span class="dashboard-empty__line" aria-hidden="true"></span>
                <div>
                    <h2 id="dashboard-empty-title">Ruang kerja {{ roleLabel }}</h2>
                    <p>{{ workspaceMessage }}</p>
                    <RouterLink v-if="['ADMINISTRATOR', 'ADMIN_PUSTU', 'GURU', 'SISWI'].includes(auth.user?.role)" class="table-link dashboard-workspace-link" :to="workspaceTarget">{{ workspaceLinkLabel }}</RouterLink>
                </div>
            </section>
        </div>
    </AppLayout>
</template>