<script setup>
import { SelectRoot } from 'radix-vue'

const props = defineProps({
  modelValue: String,
  defaultValue: String,
  open: { type: Boolean, default: undefined },
  defaultOpen: Boolean,
  dir: String,
  name: String,
  autocomplete: String,
  disabled: Boolean,
  required: Boolean,
})

const emit = defineEmits(['update:modelValue', 'update:open'])
</script>

<template>
  <SelectRoot
    :model-value="modelValue"
    :default-value="defaultValue"
    :open="open"
    :default-open="defaultOpen"
    :dir="dir"
    :name="name"
    :autocomplete="autocomplete"
    :disabled="disabled"
    :required="required"
    @update:model-value="emit('update:modelValue', $event)"
    @update:open="emit('update:open', $event)"
  >
    <slot />
  </SelectRoot>
</template>
