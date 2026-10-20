<script setup>
const props = defineProps({
    id: { type: String, required: true },
    label: { type: String, required: true },
    modelValue: { type: String, default: '' },
    type: { type: String, default: 'text' },
    inputmode: { type: String, default: 'text' },
    autocomplete: { type: String, default: 'off' },
    minLength: { type: Number, default: undefined },
    maxLength: { type: Number, default: undefined },
    error: { type: String, default: '' },
    required: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <div class="base-field">
        <label class="base-field__label" :for="id">
            {{ label }}<span v-if="required" aria-hidden="true"> *</span>
        </label>
        <input
            :id="id"
            :type="type"
            :inputmode="inputmode"
            :value="props.modelValue"
            :autocomplete="autocomplete"
            :required="required"
            :minlength="minLength"
            :maxlength="maxLength"
            :aria-invalid="Boolean(error)"
            :aria-describedby="error ? `${id}-error` : undefined"
            class="base-field__input"
            @input="emit('update:modelValue', $event.target.value)"
        >
        <p v-if="error" :id="`${id}-error`" class="base-field__error" role="alert">
            {{ error }}
        </p>
    </div>
</template>