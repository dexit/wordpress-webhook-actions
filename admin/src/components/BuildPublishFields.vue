<script setup>
// The publish-only half of ShareBuildDialog: what the listing page needs that a
// downloadable JSON file does not — a title, the one collection the build belongs
// in, a short pitch, and who to credit.
//
// The author block is remembered in localStorage: someone who publishes a second
// build should not retype their own name and links.
import { computed } from 'vue'
import { Input, Label, RadioGroup, RadioGroupItem } from '@/components/ui'
import { __, sprintf } from '@/i18n'

const props = defineProps({
  modelValue: { type: Object, required: true }, // { title, collection, summary, author_name, author_url, author_linkedin }
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const SUMMARY_MAX = 400

const set = (key, value) => emit('update:modelValue', { ...props.modelValue, [key]: value })

const summaryLeft = computed(() => SUMMARY_MAX - (props.modelValue.summary || '').length)
</script>

<template>
  <div class="space-y-4">
    <div class="space-y-1.5">
      <Label for="publish-title">{{ __('Build title') }}</Label>
      <Input
        id="publish-title"
        :model-value="modelValue.title"
        :disabled="disabled"
        :placeholder="__('Create a HubSpot deal when a WooCommerce order is placed')"
        maxlength="255"
        @update:model-value="set('title', $event)"
      />
      <p class="text-xs text-muted-foreground">{{ __('This becomes the page title and headline.') }}</p>
    </div>

    <div class="space-y-2">
      <Label>{{ __('Where does this belong?') }}</Label>
      <RadioGroup
        :model-value="modelValue.collection"
        :disabled="disabled"
        @update:model-value="set('collection', $event)"
      >
        <label class="flex items-start gap-2 rounded-md border p-3 cursor-pointer"
          :class="modelValue.collection === 'integrations' ? 'border-primary' : ''">
          <RadioGroupItem id="publish-integrations" value="integrations" class="mt-0.5" />
          <span class="space-y-0.5">
            <span class="block text-sm font-medium">{{ __('Integration') }}</span>
            <span class="block text-xs text-muted-foreground">{{ __('Connects WordPress to a named service — a CRM, a spreadsheet, a chat app.') }}</span>
          </span>
        </label>
        <label class="flex items-start gap-2 rounded-md border p-3 cursor-pointer"
          :class="modelValue.collection === 'automations' ? 'border-primary' : ''">
          <RadioGroupItem id="publish-automations" value="automations" class="mt-0.5" />
          <span class="space-y-0.5">
            <span class="block text-sm font-medium">{{ __('Automation') }}</span>
            <span class="block text-xs text-muted-foreground">{{ __('Runs a process end to end — a chain of steps, a scheduled job, a workflow.') }}</span>
          </span>
        </label>
      </RadioGroup>
      <p class="text-xs text-muted-foreground">{{ __('Pick the one that fits best — a build is listed in a single collection.') }}</p>
    </div>

    <div class="space-y-1.5">
      <Label for="publish-summary">{{ __('Short summary') }}</Label>
      <textarea
        id="publish-summary"
        :value="modelValue.summary"
        :disabled="disabled"
        :placeholder="__('One or two sentences — this is what people read on the listing before they open the build.')"
        rows="3"
        :maxlength="SUMMARY_MAX"
        class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
        @input="set('summary', $event.target.value)"
      />
      <p class="text-xs text-muted-foreground tabular-nums">{{ sprintf(__('%d characters left'), summaryLeft) }}</p>
    </div>

    <div class="space-y-3 rounded-md border p-3">
      <p class="text-sm font-medium">{{ __('Credit yourself (optional)') }}</p>
      <div class="space-y-1.5">
        <Label for="publish-author">{{ __('Your name') }}</Label>
        <Input id="publish-author" :model-value="modelValue.author_name" :disabled="disabled" maxlength="120"
          @update:model-value="set('author_name', $event)" />
      </div>
      <div class="grid gap-3 sm:grid-cols-2">
        <div class="space-y-1.5">
          <Label for="publish-website">{{ __('Website') }}</Label>
          <Input id="publish-website" type="url" :model-value="modelValue.author_url" :disabled="disabled"
            placeholder="https://" maxlength="255" @update:model-value="set('author_url', $event)" />
        </div>
        <div class="space-y-1.5">
          <Label for="publish-linkedin">{{ __('LinkedIn') }}</Label>
          <Input id="publish-linkedin" type="url" :model-value="modelValue.author_linkedin" :disabled="disabled"
            placeholder="https://www.linkedin.com/in/…" maxlength="255"
            @update:model-value="set('author_linkedin', $event)" />
        </div>
      </div>
      <p class="text-xs text-muted-foreground">{{ __('Your links appear on the build page. They are published as nofollow.') }}</p>
    </div>
  </div>
</template>
