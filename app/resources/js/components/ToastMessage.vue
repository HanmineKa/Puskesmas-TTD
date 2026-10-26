<script setup>
defineProps({
    message: { type: String, required: true },
    type: { type: String, default: 'info' },
});
</script>

<template>
    <div class="toast-message" :class="`toast-message--${type}`" :role="type === 'error' ? 'alert' : 'status'" :aria-live="type === 'error' ? 'assertive' : 'polite'">
        {{ message }}
    </div>
</template>