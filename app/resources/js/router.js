import { createRouter, createWebHistory } from 'vue-router';
import DashboardPage from './pages/DashboardPage.vue';
import AccountSetupPage from './pages/AccountSetupPage.vue';
import ActivityLogPage from './pages/ActivityLogPage.vue';
import LoginHistoryPage from './pages/LoginHistoryPage.vue';
import ManagementPage from './pages/ManagementPage.vue';
import MasterDataPage from './pages/MasterDataPage.vue';
import LoginPage from './pages/LoginPage.vue';
import NotFoundPage from './pages/NotFoundPage.vue';
import PelaporanPage from './pages/PelaporanPage.vue';
import RegistrationPage from './pages/RegistrationPage.vue';
import TahapDuaPage from './pages/TahapDuaPage.vue';
import { useAuthStore } from './stores/auth';

export function createAppRouter(pinia) {
    const router = createRouter({
        history: createWebHistory(),
        routes: [
            { path: '/', redirect: '/dashboard' },
            { path: '/login', name: 'login', component: LoginPage, meta: { guestOnly: true } },
            { path: '/registrasi/guru', name: 'registrasi-guru', component: RegistrationPage, meta: { guestOnly: true } },
            { path: '/registrasi/siswi', name: 'registrasi-siswi', component: RegistrationPage, meta: { guestOnly: true } },
            { path: '/setup-akun', name: 'account-setup', component: AccountSetupPage, meta: { requiresAuth: true, accountSetup: true } },
            { path: '/dashboard', name: 'dashboard', component: DashboardPage, meta: { requiresAuth: true } },
            { path: '/dashboard/administrator', name: 'dashboard-administrator', component: DashboardPage, meta: { requiresAuth: true, roles: ['ADMINISTRATOR'] } },
            { path: '/dashboard/pustu', name: 'dashboard-pustu', component: DashboardPage, meta: { requiresAuth: true, roles: ['ADMIN_PUSTU'] } },
            { path: '/dashboard/pengguna', name: 'dashboard-pengguna', component: DashboardPage, meta: { requiresAuth: true, roles: ['GURU', 'SISWI'] } },
            { path: '/program', name: 'program', component: TahapDuaPage, props: { view: 'auto' }, meta: { requiresAuth: true, roles: ['ADMINISTRATOR', 'ADMIN_PUSTU', 'GURU', 'SISWI'] } },
            { path: '/program/data-guru', name: 'data-guru', component: TahapDuaPage, props: { view: 'data-guru' }, meta: { requiresAuth: true, roles: ['ADMINISTRATOR', 'ADMIN_PUSTU'] } },
            { path: '/program/verifikasi-guru', name: 'verifikasi-guru', component: TahapDuaPage, props: { view: 'verifikasi-guru' }, meta: { requiresAuth: true, roles: ['ADMINISTRATOR', 'ADMIN_PUSTU'] } },
            { path: '/program/data-siswi', name: 'data-siswi', component: TahapDuaPage, props: { view: 'data-siswi' }, meta: { requiresAuth: true, roles: ['ADMINISTRATOR', 'ADMIN_PUSTU', 'GURU'] } },
            { path: '/program/kelas', name: 'kelas', component: TahapDuaPage, props: { view: 'kelas' }, meta: { requiresAuth: true, roles: ['GURU'] } },
            { path: '/program/token', name: 'token-sekolah', component: TahapDuaPage, props: { view: 'token' }, meta: { requiresAuth: true, roles: ['GURU'] } },
            { path: '/program/mutasi', name: 'mutasi-siswi', component: TahapDuaPage, props: { view: 'mutasi' }, meta: { requiresAuth: true, roles: ['ADMINISTRATOR', 'ADMIN_PUSTU', 'GURU'] } },
            { path: '/program/data-saya', name: 'data-siswi-pribadi', component: TahapDuaPage, props: { view: 'data-saya' }, meta: { requiresAuth: true, roles: ['SISWI'] } },
            { path: '/pelaporan', name: 'pelaporan', component: PelaporanPage, props: { view: 'auto' }, meta: { requiresAuth: true, roles: ['ADMINISTRATOR', 'GURU', 'SISWI'] } },
            { path: '/pelaporan/riwayat', name: 'riwayat-laporan', component: PelaporanPage, props: { view: 'riwayat' }, meta: { requiresAuth: true, roles: ['SISWI'] } },
            { path: '/pustu', name: 'pustu', component: ManagementPage, meta: { requiresAuth: true, resource: 'pustu', roles: ['ADMINISTRATOR', 'ADMIN_PUSTU'] } },
            { path: '/admin-pustu', name: 'admin-pustu', component: ManagementPage, meta: { requiresAuth: true, resource: 'admin-pustu', roles: ['ADMINISTRATOR'] } },
            { path: '/sekolah', name: 'sekolah', component: ManagementPage, meta: { requiresAuth: true, resource: 'sekolah', roles: ['ADMINISTRATOR', 'ADMIN_PUSTU'] } },
            { path: '/data-wilayah', redirect: '/wilayah/kecamatan' },
            { path: '/wilayah/kecamatan', name: 'data-kecamatan', component: MasterDataPage, props: { section: 'kecamatan' }, meta: { requiresAuth: true, roles: ['ADMINISTRATOR'] } },
            { path: '/wilayah/kelurahan', name: 'data-kelurahan', component: MasterDataPage, props: { section: 'kelurahan' }, meta: { requiresAuth: true, roles: ['ADMINISTRATOR'] } },
            { path: '/sekolah/tahun-ajaran', name: 'tahun-ajaran', component: MasterDataPage, props: { section: 'tahun-ajaran' }, meta: { requiresAuth: true, roles: ['ADMINISTRATOR'] } },
            { path: '/log-aktivitas', name: 'log-aktivitas', component: ActivityLogPage, meta: { requiresAuth: true, roles: ['ADMINISTRATOR', 'ADMIN_PUSTU'] } },
            { path: '/riwayat-login', name: 'riwayat-login', component: LoginHistoryPage, meta: { requiresAuth: true } },
            { path: '/:pathMatch(.*)*', component: NotFoundPage },
        ],
    });

    router.beforeEach(async (to) => {
        const auth = useAuthStore(pinia);
        await auth.initialize();

        if (to.meta.requiresAuth && ! auth.user) return { name: 'login' };
        if (auth.requiresAccountSetup && to.name !== 'account-setup') return { name: 'account-setup' };
        if (to.meta.guestOnly && auth.user) return { name: auth.requiresAccountSetup ? 'account-setup' : 'dashboard' };
        if (to.meta.accountSetup && ! auth.requiresAccountSetup) return { name: 'dashboard' };
        if (to.meta.roles && ! to.meta.roles.includes(auth.user?.role)) return { name: 'dashboard' };
        if (to.name === 'dashboard' && auth.user) {
            const dashboardRoutes = {
                ADMINISTRATOR: 'dashboard-administrator',
                ADMIN_PUSTU: 'dashboard-pustu',
                GURU: 'dashboard-pengguna',
                SISWI: 'dashboard-pengguna',
            };
            return { name: dashboardRoutes[auth.user.role] ?? 'login' };
        }

        return true;
    });

    return router;
}