<script setup>
defineProps({
    message: { type: String, required: true },
    type: { type: String, default: 'info' },
});
</script>

<template>
    <div class="toast-message" :class="`toast-message--${type}`" role="status" aria-live="polite">
        {{ message }}
    </div>
</template>