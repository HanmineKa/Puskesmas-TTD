import { defineStore } from 'pinia';

function csrfToken() {
    const token = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.split('=')
        .slice(1)
        .join('=');

    return token ? decodeURIComponent(token) : '';
}

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        requiresAccountSetup: false,
        setupRequired: [],
        loading: false,
        initialized: false,
    }),
    actions: {
        async initialize(force = false) {
            if (this.initialized && ! force) return;

            try {
                const response = await fetch('/api/session', {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });
                const payload = response.ok ? await response.json() : null;
                this.user = payload?.user ?? null;
                this.setupRequired = payload?.setup_required ?? [];
                this.requiresAccountSetup = payload?.requires_account_setup ?? this.setupRequired.length > 0;
            } catch {
                this.user = null;
                this.requiresAccountSetup = false;
                this.setupRequired = [];
            } finally {
                this.initialized = true;
            }
        },
        async login(credentials) {
            this.loading = true;

            try {
                await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
                const response = await fetch('/api/login', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify(credentials),
                });
                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.message ?? 'Tidak dapat masuk. Periksa kembali data Anda.');
                }

                this.user = payload.user;
                this.requiresAccountSetup = payload.requires_account_setup ?? false;
                this.setupRequired = payload.setup_required ?? [];
                return payload;
            } finally {
                this.loading = false;
            }
        },
        async changeTemporaryPassword(values) {
            const response = await fetch('/api/account/change-temporary-password', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify(values),
            });
            const payload = await response.json();
            if (! response.ok) throw new Error(payload.message ?? 'Kata sandi tidak dapat diperbarui.');
            this.setupRequired = this.setupRequired.filter((item) => item !== 'GANTI_SANDI');
            this.requiresAccountSetup = this.setupRequired.length > 0;
        },
        async logout() {
            await fetch('/api/logout', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
            });

            this.user = null;
            this.requiresAccountSetup = false;
            this.setupRequired = [];
        },
    },
});