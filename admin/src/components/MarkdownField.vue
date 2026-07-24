<script setup>
import { ref } from 'vue'
import { Label } from '@/components/ui'
import MarkdownView from '@/components/MarkdownView.vue'
import { __ } from '@/i18n'

const props = defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, default: '' },
  id: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  rows: { type: Number, default: 4 },
})

defineEmits(['update:modelValue'])

const showPreview = ref(false)
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
      class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
      @input="$emit('update:modelValue', $event.target.value)"
    />

    <p class="text-xs text-muted-foreground">{{ __('Markdown supported.') }}</p>
  </div>
</template>
