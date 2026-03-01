<script setup>
import { SelectValue as SelectValuePrimitive } from 'radix-vue'

defineProps({
  placeholder: String,
  asChild: Boolean,
})
</script>

<template>
  <SelectValuePrimitive v-bind="$props">
    <slot />
  </SelectValuePrimitive>
</template>
