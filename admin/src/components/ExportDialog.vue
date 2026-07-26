<script setup>
import { ref, computed, watch } from 'vue'
import { Dialog, Button, Checkbox, Label, Input } from '@/components/ui'
import { useBuildExport } from '@/composables/useBuildExport'
import { __ } from '@/i18n'

const props = defineProps({
  open: { type: Boolean, default: false },
  webhooks: { type: Array, default: () => [] },
  chains: { type: Array, default: () => [] },
})

const emit = defineEmits(['close'])

const { exportBuild } = useBuildExport()

const exportAll = ref(false)
const selectedWebhookIds = ref(new Set())
const selectedChainIds = ref(new Set())
const webhookSearch = ref('')
const chainSearch = ref('')
const exporting = ref(false)
const error = ref('')

// Reset selection each time the dialog opens.
watch(() => props.open, (open) => {
  if (open) {
    exportAll.value = false
    selectedWebhookIds.value = new Set()
    selectedChainIds.value = new Set()
    webhookSearch.value = ''
    chainSearch.value = ''
    error.value = ''
  }
})

const filteredWebhooks = computed(() => {
  const q = webhookSearch.value.trim().toLowerCase()
  if (!q) return props.webhooks
  return props.webhooks.filter((w) => (w.name || '').toLowerCase().includes(q))
})

const filteredChains = computed(() => {
  const q = chainSearch.value.trim().toLowerCase()
  if (!q) return props.chains
  return props.chains.filter((c) => (c.name || '').toLowerCase().includes(q))
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

const runExport = async () => {
  if (!canExport.value || exporting.value) return
  exporting.value = true
  error.value = ''
  try {
    const webhookIds = Array.from(selectedWebhookIds.value)
    const chainIds = Array.from(selectedChainIds.value)
    const payload = exportAll.value ? { all: true } : { webhook_ids: webhookIds, chain_ids: chainIds }

    // Name the file after the single selected item when there is exactly one.
    let hint = ''
    if (!exportAll.value) {
      if (webhookIds.length === 1 && chainIds.length === 0) {
        hint = props.webhooks.find((w) => Number(w.id) === webhookIds[0])?.name || ''
      } else if (chainIds.length === 1 && webhookIds.length === 0) {
        hint = props.chains.find((c) => Number(c.id) === chainIds[0])?.name || ''
      }
    }

    await exportBuild(payload, hint)
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
          <Input v-model="webhookSearch" type="search" :placeholder="__('Search webhooks…')" class="h-8 text-sm" />
          <div class="max-h-40 overflow-y-auto rounded-md border p-2 space-y-1">
            <label v-for="w in filteredWebhooks" :key="w.id" class="flex items-center gap-2 cursor-pointer rounded px-2 py-1 hover:bg-muted/50">
              <Checkbox :model-value="selectedWebhookIds.has(Number(w.id))" @update:model-value="toggleWebhook(w.id)" />
              <span class="text-sm">{{ w.name }}</span>
            </label>
            <p v-if="!filteredWebhooks.length" class="px-2 py-1 text-xs text-muted-foreground">{{ __('No webhooks match your search.') }}</p>
          </div>
        </div>

        <div v-if="chains.length" class="space-y-1.5">
          <Label class="text-xs text-muted-foreground">{{ __('Chains (member webhooks included automatically)') }}</Label>
          <Input v-model="chainSearch" type="search" :placeholder="__('Search chains…')" class="h-8 text-sm" />
          <div class="max-h-40 overflow-y-auto rounded-md border p-2 space-y-1">
            <label v-for="c in filteredChains" :key="c.id" class="flex items-center gap-2 cursor-pointer rounded px-2 py-1 hover:bg-muted/50">
              <Checkbox :model-value="selectedChainIds.has(Number(c.id))" @update:model-value="toggleChain(c.id)" />
              <span class="text-sm">{{ c.name }}</span>
            </label>
            <p v-if="!filteredChains.length" class="px-2 py-1 text-xs text-muted-foreground">{{ __('No chains match your search.') }}</p>
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
