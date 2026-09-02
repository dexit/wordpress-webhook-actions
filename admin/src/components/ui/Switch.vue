<script setup>
import { cn } from '@/lib/utils'

const props = defineProps({
  modelValue: Boolean,
  disabled: Boolean,
  loading: Boolean,
})

const emit = defineEmits(['update:modelValue'])

const toggle = () => {
  emit('update:modelValue', !props.modelValue)
}
</script>

<template>
  <button
    type="button"
    role="switch"
    :aria-checked="modelValue"
    :disabled="disabled || loading"
    :class="cn(
      'peer inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full border-2 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background '
      // Opacity alone does not read as disabled on a switch that is already
      // OFF — an off switch is grey to begin with. Desaturate it, flatten the
      // thumb and show the border so the control looks inert either way.
      + 'disabled:cursor-not-allowed disabled:opacity-60 disabled:grayscale disabled:border-dashed disabled:border-muted-foreground/40',
      modelValue ? 'bg-primary' : 'bg-input'
    )"
    @click="toggle"
  >
    <span
      :class="cn(
        'pointer-events-none flex items-center justify-center h-5 w-5 rounded-full bg-background shadow-lg ring-0 transition-transform',
        disabled && 'shadow-none bg-muted',
        modelValue ? 'translate-x-5' : 'translate-x-0'
      )"
    >
      <svg
        v-if="loading"
        class="animate-spin h-3 w-3 text-muted-foreground"
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        viewBox="0 0 24 24"
      >
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
      </svg>
    </span>
  </button>
</template>
