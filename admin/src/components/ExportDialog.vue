<script setup>
import { ref, computed, watch } from 'vue'
import { Dialog, Button, Checkbox, Label } from '@/components/ui'
import api from '@/lib/api'
import { __ } from '@/i18n'

const props = defineProps({
  open: { type: Boolean, default: false },
  webhooks: { type: Array, default: () => [] },
  chains: { type: Array, default: () => [] },
})

const emit = defineEmits(['close'])

const exportAll = ref(true)
const selectedWebhookIds = ref(new Set())
const selectedChainIds = ref(new Set())
const exporting = ref(false)
const error = ref('')

// Reset selection each time the dialog opens.
watch(() => props.open, (open) => {
  if (open) {
    exportAll.value = true
    selectedWebhookIds.value = new Set()
    selectedChainIds.value = new Set()
    error.value = ''
  }
})

const toggleWebhook = (id) => {
  const set = new Set(selectedWebhookIds.value)
  set.has(Number(id)) ? set.delete(Number(id)) : set.add(Number(id))
  selectedWebhookIds.value = set
}

const toggleChain = (id) => {
  const set = new Set(selectedChainIds.value)
  set.has(Number(id)) ? set.delete(Number(id)) : set.add(Number(id))
  selectedChainIds.value = set
}

const canExport = computed(() =>
  exportAll.value || selectedWebhookIds.value.size > 0 || selectedChainIds.value.size > 0
)

const download = (document) => {
  const blob = new Blob([JSON.stringify(document, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = window.document.createElement('a')
  const stamp = new Date().toISOString().slice(0, 10)
  a.href = url
  a.download = `webhook-actions-build-${stamp}.json`
  window.document.body.appendChild(a)
  a.click()
  window.document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

const runExport = async () => {
  if (!canExport.value || exporting.value) return
  exporting.value = true
  error.value = ''
  try {
    const payload = exportAll.value
      ? { all: true }
      : {
          webhook_ids: Array.from(selectedWebhookIds.value),
          chain_ids: Array.from(selectedChainIds.value),
        }
    const document = await api.builds.export(payload)
    download(document)
    emit('close')
  } catch (e) {
    error.value = e?.message || __('Export failed.')
  } finally {
    exporting.value = false
  }
}
</script>

<template>
  <Dialog
    :open="open"
    :title="__('Export webhooks & chains')"
    :description="__('Download a portable JSON build. Secrets are never included — imported auth is re-linked to your vault.')"
    @close="emit('close')"
  >
    <div class="space-y-4">
      <label class="flex items-center gap-2 cursor-pointer">
        <Checkbox :model-value="exportAll" @update:model-value="exportAll = $event" />
        <span class="text-sm font-medium">{{ __('Export everything') }}</span>
      </label>

      <div v-if="!exportAll" class="space-y-4">
        <div v-if="webhooks.length" class="space-y-1.5">
          <Label class="text-xs text-muted-foreground">{{ __('Webhooks') }}</Label>
          <div class="max-h-40 overflow-y-auto rounded-md border p-2 space-y-1">
            <label v-for="w in webhooks" :key="w.id" class="flex items-center gap-2 cursor-pointer rounded px-2 py-1 hover:bg-muted/50">
              <Checkbox :model-value="selectedWebhookIds.has(Number(w.id))" @update:model-value="toggleWebhook(w.id)" />
              <span class="text-sm">{{ w.name }}</span>
            </label>
          </div>
        </div>

        <div v-if="chains.length" class="space-y-1.5">
          <Label class="text-xs text-muted-foreground">{{ __('Chains (member webhooks included automatically)') }}</Label>
          <div class="max-h-40 overflow-y-auto rounded-md border p-2 space-y-1">
            <label v-for="c in chains" :key="c.id" class="flex items-center gap-2 cursor-pointer rounded px-2 py-1 hover:bg-muted/50">
              <Checkbox :model-value="selectedChainIds.has(Number(c.id))" @update:model-value="toggleChain(c.id)" />
              <span class="text-sm">{{ c.name }}</span>
            </label>
          </div>
        </div>
      </div>

      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
    </div>

    <template #footer>
      <div class="flex gap-2">
        <Button :disabled="!canExport || exporting" @click="runExport">
          {{ exporting ? __('Exporting…') : __('Download JSON') }}
        </Button>
        <Button variant="outline" :disabled="exporting" @click="emit('close')">{{ __('Cancel') }}</Button>
      </div>
    </template>
  </Dialog>
</template>
