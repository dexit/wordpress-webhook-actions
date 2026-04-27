<script setup>
import { ref, provide } from 'vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  tabs: { type: Array, required: true }, // [{ key, label }]
})
const emit = defineEmits(['update:modelValue'])

const active = ref(props.modelValue || props.tabs[0]?.key || '')

const setTab = (key) => {
  active.value = key
  emit('update:modelValue', key)
}

provide('activeTab', active)
</script>

<template>
  <div>
    <!-- Tab bar -->
    <div class="flex flex-wrap gap-0 border-b border-border mb-6">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        type="button"
        @click="setTab(tab.key)"
        :class="[
          'flex items-center gap-1.5 px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap',
          active === tab.key
            ? 'border-primary text-primary'
            : 'border-transparent text-muted-foreground hover:text-foreground hover:border-muted',
        ]"
      >
        <component v-if="tab.icon" :is="tab.icon" class="w-4 h-4" />
        {{ tab.label }}
      </button>
    </div>
    <!-- Active slot -->
    <template v-for="tab in tabs" :key="tab.key">
      <div v-show="active === tab.key">
        <slot :name="tab.key" />
      </div>
    </template>
  </div>
</template>
