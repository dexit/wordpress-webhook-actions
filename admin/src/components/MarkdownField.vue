<script setup>
import { ref, computed } from 'vue'
import { Label } from '@/components/ui'
import MarkdownView from '@/components/MarkdownView.vue'
import { __, sprintf } from '@/i18n'

const props = defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, default: '' },
  id: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  rows: { type: Number, default: 4 },
  maxlength: { type: Number, default: 4000 },
})

defineEmits(['update:modelValue'])

const showPreview = ref(false)
const remaining = computed(() => props.maxlength - (props.modelValue?.length || 0))
</script>

<template>
  <div class="space-y-2">
    <div v-if="label" class="flex items-center justify-between">
      <Label :for="id">{{ label }}</Label>
      <button
        v-if="modelValue"
        type="button"
        class="text-xs text-muted-foreground hover:text-foreground underline"
        @click="showPreview = !showPreview"
      >
        {{ showPreview ? __('Edit') : __('Preview') }}
      </button>
    </div>

    <MarkdownView
      v-if="showPreview && modelValue"
      :source="modelValue"
      class="rounded-md border border-input bg-background px-3 py-2 min-h-[6rem]"
    />
    <textarea
      v-else
      :id="id"
      :value="modelValue"
      :placeholder="placeholder"
      :rows="rows"
      :maxlength="maxlength"
      class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
      @input="$emit('update:modelValue', $event.target.value)"
    />

    <div class="flex items-center justify-between gap-2">
      <p class="text-xs text-muted-foreground">{{ __('Markdown supported.') }}</p>
      <p
        v-if="!showPreview"
        class="text-xs tabular-nums"
        :class="remaining <= 0 ? 'text-destructive' : (remaining <= maxlength * 0.1 ? 'text-amber-600 dark:text-amber-500' : 'text-muted-foreground')"
      >
        {{ sprintf(__('%d characters left'), remaining) }}
      </p>
    </div>
  </div>
</template>
