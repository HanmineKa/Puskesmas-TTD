<script setup>
defineProps({
    modelValue: { type: Boolean, default: false },
    title: { type: String, required: true },
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <Teleport to="body">
        <div
            v-if="modelValue"
            class="modal-backdrop"
            @click.self="emit('update:modelValue', false)"
            @keydown.esc="emit('update:modelValue', false)"
        >
            <section class="base-modal" role="dialog" aria-modal="true" :aria-label="title" tabindex="-1">
                <header class="base-modal__header">
                    <h2>{{ title }}</h2>
                    <button
                        type="button"
                        class="icon-button"
                        :aria-label="`Tutup ${title}`"
                        @click="emit('update:modelValue', false)"
                    >
                        <span aria-hidden="true">×</span>
                    </button>
                </header>
                <div class="base-modal__body"><slot /></div>
                <footer v-if="$slots.footer" class="base-modal__footer"><slot name="footer" /></footer>
            </section>
        </div>
    </Teleport>
</template>