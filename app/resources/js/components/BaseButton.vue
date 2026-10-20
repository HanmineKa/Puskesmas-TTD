<script setup>
defineProps({
    type: { type: String, default: 'button' },
    variant: { type: String, default: 'primary' },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
});
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        class="base-button"
        :class="`base-button--${variant}`"
    >
        <span v-if="loading" class="base-button__spinner" aria-hidden="true"></span>
        <slot v-else />
    </button>
</template>