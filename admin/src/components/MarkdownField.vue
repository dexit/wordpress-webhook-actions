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
    <!-- Header: label (left) + Write/Preview toggle (right, always available) -->
    <div class="flex items-center justify-between gap-2">
      <Label v-if="label" :for="id">{{ label }}</Label>
      <span v-else></span>
      <div class="inline-flex items-center gap-0.5 rounded-md border p-0.5 text-xs">
        <button
          type="button"
          class="px-2 py-0.5 rounded transition-colors"
          :class="!showPreview ? 'bg-background shadow text-foreground font-medium' : 'text-muted-foreground hover:text-foreground'"
          @click="showPreview = false"
        >{{ __('Write') }}</button>
        <button
          type="button"
          class="px-2 py-0.5 rounded transition-colors"
          :class="showPreview ? 'bg-background shadow text-foreground font-medium' : 'text-muted-foreground hover:text-foreground'"
          @click="showPreview = true"
        >{{ __('Preview') }}</button>
      </div>
    </div>

    <MarkdownView
      v-if="showPreview && modelValue"
      :source="modelValue"
      class="rounded-md border border-input bg-background px-3 py-2 min-h-[6rem]"
    />
    <div
      v-else-if="showPreview"
      class="rounded-md border border-dashed border-input bg-muted/20 px-3 py-2 min-h-[6rem] text-sm text-muted-foreground flex items-center"
    >
      {{ __('Nothing to preview yet — switch to Write to add a description.') }}
    </div>
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
