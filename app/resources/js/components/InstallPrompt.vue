<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';

const installEvent = ref(null);
const showIosGuide = ref(false);
const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent)
    && ! window.matchMedia('(display-mode: standalone)').matches;
const canInstall = computed(() => Boolean(installEvent.value) || isIos);

function captureInstallEvent(event) {
    event.preventDefault();
    installEvent.value = event;
}

async function install() {
    if (! installEvent.value) {
        showIosGuide.value = isIos;
        return;
    }

    await installEvent.value.prompt();
    await installEvent.value.userChoice;
    installEvent.value = null;
}

onMounted(() => window.addEventListener('beforeinstallprompt', captureInstallEvent));
onUnmounted(() => window.removeEventListener('beforeinstallprompt', captureInstallEvent));
</script>

<template>
    <div v-if="canInstall" class="install-prompt">
        <button class="install-prompt__button" type="button" @click="install">
            <span aria-hidden="true">↓</span>
            Pasang di Layar Utama
        </button>
        <p v-if="showIosGuide" class="install-prompt__guide">
            Di iPhone: pilih Bagikan, lalu Tambahkan ke Layar Utama.
        </p>
    </div>
</template>