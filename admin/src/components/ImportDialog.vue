<script setup>
import { ref, watch } from 'vue'
import { Dialog, Button, Input, Label, Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui'
import { UploadCloud } from 'lucide-vue-next'
import api from '@/lib/api'
import { __, sprintf } from '@/i18n'

const props = defineProps({
  open: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'imported'])

const step = ref('select')       // select | resolve
const busy = ref(false)
const error = ref('')
const doc = ref(null)
const analysis = ref(null)
const existingCredentials = ref([])
const resolutions = ref({})      // ref => { mode, existing_id, create:{...} }
const onCollision = ref('copy')
const fileInput = ref(null)

const TYPE_OPTIONS = [
  { value: 'bearer', label: 'Bearer token' },
  { value: 'basic', label: 'Basic auth' },
  { value: 'api_key', label: 'API key header' },
  { value: 'custom', label: 'Custom header' },
]

watch(() => props.open, (open) => {
  if (open) reset()
})

const reset = () => {
  step.value = 'select'
  busy.value = false
  error.value = ''
  doc.value = null
  analysis.value = null
  resolutions.value = {}
  onCollision.value = 'copy'
}

const pickFile = () => fileInput.value?.click()

const onFileChange = async (e) => {
  const file = e.target.files?.[0]
  if (!file) return
  error.value = ''
  busy.value = true
  try {
    const text = await file.text()
    const parsed = JSON.parse(text)
    doc.value = parsed
    const result = await api.builds.analyze(parsed)
    analysis.value = result
    existingCredentials.value = await api.credentials.list()

    // Seed a resolution per needed credential (default: create a new one,
    // prefilled from the exported metadata).
    const seeded = {}
    for (const cred of result.credentials_needed || []) {
      seeded[cred.ref] = {
        mode: 'create',
        existing_id: existingCredentials.value[0]?.id != null ? String(existingCredentials.value[0].id) : '',
        create: {
          name: cred.name || '',
          type: cred.type || 'bearer',
          header_name: cred.header_name || 'Authorization',
          secret: '',
          username: '',
          password: '',
        },
      }
    }
    resolutions.value = seeded
    step.value = 'resolve'
  } catch (err) {
    error.value = err?.message || __('Could not read this file.')
  } finally {
    busy.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}

const buildCredentialPayload = (create) => {
  const payload = { name: create.name, type: create.type }
  if (create.type === 'basic') {
    payload.username = create.username
    payload.password = create.password
  } else {
    payload.secret = create.secret
    if (create.type === 'api_key' || create.type === 'custom') {
      payload.header_name = create.header_name || 'Authorization'
    }
  }
  return payload
}

const runImport = async () => {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    const credentialMap = {}
    for (const [ref, res] of Object.entries(resolutions.value)) {
      if (res.mode === 'existing') {
        credentialMap[ref] = Number(res.existing_id) || 0
      } else if (res.mode === 'create') {
        const created = await api.credentials.create(buildCredentialPayload(res.create))
        credentialMap[ref] = Number(created?.id) || 0
      } else {
        credentialMap[ref] = 0
      }
    }

    await api.builds.import({
      document: doc.value,
      credential_map: credentialMap,
      options: { on_collision: onCollision.value },
    })
    emit('imported')
  } catch (err) {
    error.value = err?.message || __('Import failed.')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Dialog
    :open="open"
    :title="__('Import webhooks & chains')"
    :description="__('Load a build JSON exported from Webhook Actions.')"
    @close="emit('close')"
  >
    <input ref="fileInput" type="file" accept="application/json,.json" class="hidden" @change="onFileChange" />

    <!-- Step 1: pick file -->
    <div v-if="step === 'select'" class="space-y-4">
      <button
        type="button"
        class="w-full flex flex-col items-center gap-2 rounded-md border border-dashed border-input px-4 py-8 text-muted-foreground hover:bg-muted/40"
        :disabled="busy"
        @click="pickFile"
      >
        <UploadCloud class="h-6 w-6" />
        <span class="text-sm">{{ busy ? __('Reading…') : __('Choose a build JSON file') }}</span>
      </button>
      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
    </div>

    <!-- Step 2: resolve credentials + collisions -->
    <div v-else class="space-y-4">
      <p class="text-sm text-muted-foreground">
        {{ sprintf(__('%1$d webhook(s) and %2$d chain(s) to import.'), analysis.counts.webhooks, analysis.counts.chains) }}
      </p>

      <div v-if="analysis.collisions.length" class="space-y-2 rounded-md border p-3">
        <p class="text-sm">
          {{ sprintf(__('%d webhook(s) already exist here (same UUID).'), analysis.collisions.length) }}
        </p>
        <Select v-model="onCollision">
          <SelectTrigger><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="copy">{{ __('Import as new copies') }}</SelectItem>
            <SelectItem value="skip">{{ __('Skip the duplicates') }}</SelectItem>
          </SelectContent>
        </Select>
      </div>

      <div v-if="Object.keys(resolutions).length" class="space-y-3">
        <Label>{{ __('This build needs authorization credentials') }}</Label>
        <div
          v-for="cred in analysis.credentials_needed"
          :key="cred.ref"
          class="space-y-2 rounded-md border p-3"
        >
          <p class="text-sm font-medium">
            {{ cred.name }}
            <span v-if="cred.hint" class="text-xs text-muted-foreground">({{ cred.hint }})</span>
          </p>

          <Select v-model="resolutions[cred.ref].mode">
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="create">{{ __('Create a new vault credential') }}</SelectItem>
              <SelectItem value="existing" :disabled="existingCredentials.length === 0">{{ __('Use an existing vault credential') }}</SelectItem>
              <SelectItem value="skip">{{ __('No authorization') }}</SelectItem>
            </SelectContent>
          </Select>

          <!-- Existing credential picker -->
          <Select v-if="resolutions[cred.ref].mode === 'existing'" v-model="resolutions[cred.ref].existing_id">
            <SelectTrigger><SelectValue :placeholder="__('Select credential')" /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="c in existingCredentials" :key="c.id" :value="String(c.id)">
                {{ c.name }}<span v-if="c.hint"> — {{ c.hint }}</span>
              </SelectItem>
            </SelectContent>
          </Select>

          <!-- Create new credential -->
          <div v-else-if="resolutions[cred.ref].mode === 'create'" class="space-y-2">
            <Input v-model="resolutions[cred.ref].create.name" :placeholder="__('Credential name')" />
            <Select v-model="resolutions[cred.ref].create.type">
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="t in TYPE_OPTIONS" :key="t.value" :value="t.value">{{ t.label }}</SelectItem>
              </SelectContent>
            </Select>
            <Input
              v-if="['api_key', 'custom'].includes(resolutions[cred.ref].create.type)"
              v-model="resolutions[cred.ref].create.header_name"
              :placeholder="__('Header name (e.g. X-API-Key)')"
            />
            <template v-if="resolutions[cred.ref].create.type === 'basic'">
              <Input v-model="resolutions[cred.ref].create.username" :placeholder="__('Username')" />
              <Input v-model="resolutions[cred.ref].create.password" type="password" :placeholder="__('Password')" />
            </template>
            <Input v-else v-model="resolutions[cred.ref].create.secret" type="password" :placeholder="__('Secret / token')" />
          </div>
        </div>
      </div>

      <p v-else class="text-sm text-muted-foreground">{{ __('No authorization needed for this build.') }}</p>

      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
    </div>

    <template #footer>
      <div class="flex gap-2">
        <Button v-if="step === 'resolve'" :disabled="busy" @click="runImport">
          {{ busy ? __('Importing…') : __('Import') }}
        </Button>
        <Button variant="outline" :disabled="busy" @click="emit('close')">{{ __('Cancel') }}</Button>
      </div>
    </template>
  </Dialog>
</template>
