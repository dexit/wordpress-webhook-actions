<script setup>
import { ref, computed, watch } from 'vue'
import { Dialog, Button, Input, Label, Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui'
import { UploadCloud, AlertTriangle, ChevronDown, KeyRound, Check, Loader2, Pencil, Network, CornerDownRight } from 'lucide-vue-next'
import api from '@/lib/api'
import { __, sprintf, _n } from '@/i18n'

const props = defineProps({
  open: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'imported', 'edit-webhook', 'focus'])

const step = ref('select')       // select | resolve | done
const busy = ref(false)
const error = ref('')
const doc = ref(null)
const analysis = ref(null)
const existingCredentials = ref([])
const resolutions = ref({})      // ref => { mode, existing_id, create:{...} }
const onCollision = ref('copy')
const fileInput = ref(null)
const dragging = ref(false)
const expandedGlue = ref(new Set())
const result = ref(null)         // import summary once complete

const importedWebhooks = computed(() => result.value?.webhook_items || [])
const importedChains = computed(() => result.value?.chain_items || [])
const problems = computed(() => result.value?.problems || [])
const singleWebhook = computed(() => importedWebhooks.value.length === 1 && importedChains.value.length === 0)
const singleChain = computed(() => importedChains.value.length === 1 && importedWebhooks.value.length === 0)

const focusItem = (type, id) => emit('focus', { type, id })
const editWebhook = (id) => emit('edit-webhook', id)

// Code Glue snippets carried in the build — custom PHP that will run on this site
// when the imported webhooks fire. Surfaced for review before importing.
const codeGlueSnippets = computed(() => {
  const out = []
  for (const w of doc.value?.webhooks || []) {
    for (const t of w.triggers || []) {
      for (const stage of ['pre', 'post']) {
        const s = t.code_glue?.[stage]
        if (s && typeof s.code === 'string' && s.code.trim() !== '') {
          out.push({
            key: `${w.uuid}-${t.name}-${stage}`,
            webhook: w.name || w.uuid,
            trigger: t.name,
            stage,
            name: s.name || '',
            code: s.code,
          })
        }
      }
    }
  }
  return out
})

// A Build-with-AI transcript may ride along as provenance; it is shown for
// reference only and never recreated on import.
const hasAiTranscript = computed(() => !!doc.value?.ai_build)

const toggleGlue = (key) => {
  const set = new Set(expandedGlue.value)
  set.has(key) ? set.delete(key) : set.add(key)
  expandedGlue.value = set
}

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
  expandedGlue.value = new Set()
  result.value = null
}

const pickFile = () => fileInput.value?.click()

const onFileChange = (e) => processFile(e.target.files?.[0])

const onDrop = (e) => {
  dragging.value = false
  if (busy.value) return
  processFile(e.dataTransfer?.files?.[0])
}

const processFile = async (file) => {
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

    // Seed a resolution per needed credential. An internal "WP REST API" auth
    // (a WP Application Password) can't be pasted, so default it to reusing a
    // matching vault credential, else to provisioning one via a single confirm.
    const seeded = {}
    for (const cred of result.credentials_needed || []) {
      const internal = isInternalWpRest(cred)
      const match = internal ? findExistingInternal() : null
      seeded[cred.ref] = {
        internal,
        mode: internal ? (match ? 'existing' : 'provision') : 'create',
        existing_id: match
          ? String(match.id)
          : (existingCredentials.value[0]?.id != null ? String(existingCredentials.value[0].id) : ''),
        provisioned_id: 0,
        provisioned_name: '',
        provisioning: false,
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
    error.value = err?.message || __('Could not read this file. Make sure it is a build JSON exported from Webhook Actions.')
  } finally {
    busy.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}

// The site's own WP REST API auth is a "basic" WP Application Password named
// "WP REST API (internal) — <user>". Detect it so import can reuse/provision it
// instead of asking for a secret nobody has.
const INTERNAL_PREFIX = 'WP REST API (internal)'
const isInternalWpRest = (cred) =>
  cred?.type === 'basic' && String(cred?.name || '').startsWith(INTERNAL_PREFIX)
const findExistingInternal = () =>
  existingCredentials.value.find((c) => c?.type === 'basic' && String(c?.name || '').startsWith(INTERNAL_PREFIX))

// Mint a WP Application Password and store it as a vault credential (single
// confirm), mirroring the AI Builder. Records the id on the resolution.
const provision = async (ref) => {
  const res = resolutions.value[ref]
  if (!res || res.provisioning) return
  res.provisioning = true
  error.value = ''
  try {
    const created = await api.credentials.provisionAppPassword()
    res.provisioned_id = Number(created?.id) || 0
    res.provisioned_name = created?.name || ''
    if (created?.id != null && !existingCredentials.value.some((c) => c.id === created.id)) {
      existingCredentials.value = [created, ...existingCredentials.value]
    }
  } catch (err) {
    error.value = err?.message || __('Could not create the Application Password.')
  } finally {
    res.provisioning = false
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
      } else if (res.mode === 'provision') {
        let id = Number(res.provisioned_id) || 0
        if (!id) {
          const created = await api.credentials.provisionAppPassword()
          id = Number(created?.id) || 0
        }
        credentialMap[ref] = id
      } else if (res.mode === 'create') {
        const created = await api.credentials.create(buildCredentialPayload(res.create))
        credentialMap[ref] = Number(created?.id) || 0
      } else {
        credentialMap[ref] = 0
      }
    }

    const res = await api.builds.import({
      document: doc.value,
      credential_map: credentialMap,
      options: { on_collision: onCollision.value },
    })
    result.value = res
    step.value = 'done'
    // Let the parent reload the list so the imported items exist to scroll to.
    emit('imported', res)
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
        class="w-full flex flex-col items-center gap-2 rounded-md border border-dashed px-4 py-8 transition-colors"
        :class="dragging ? 'border-primary bg-primary/5 text-foreground' : 'border-input text-muted-foreground hover:bg-muted/40'"
        :disabled="busy"
        @click="pickFile"
        @dragenter.prevent="dragging = true"
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop.prevent="onDrop"
      >
        <UploadCloud class="h-6 w-6 pointer-events-none" />
        <span class="text-sm pointer-events-none">
          {{ busy ? __('Reading…') : (dragging ? __('Drop to import') : __('Drop a build JSON here, or click to browse')) }}
        </span>
      </button>
      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
    </div>

    <!-- Step 2: resolve credentials + collisions -->
    <div v-else-if="step === 'resolve'" class="space-y-4 min-w-0">
      <p class="text-sm text-muted-foreground">
        {{ sprintf(__('%1$d webhook(s) and %2$d chain(s) to import.'), analysis.counts.webhooks, analysis.counts.chains) }}
      </p>
      <p v-if="hasAiTranscript" class="text-xs text-muted-foreground">
        {{ __('This build includes a Build-with-AI transcript, shown for reference on the source — it is not imported.') }}
      </p>

      <!-- Code Glue review: custom PHP that will run on this site -->
      <div v-if="codeGlueSnippets.length" class="space-y-2 rounded-md border border-amber-500/50 bg-amber-500/5 p-3">
        <div class="flex items-start gap-2">
          <AlertTriangle class="h-4 w-4 mt-0.5 shrink-0 text-amber-600 dark:text-amber-500" />
          <div class="text-sm">
            <p class="font-medium text-amber-700 dark:text-amber-400">
              {{ sprintf(_n('This build contains %d Code Glue snippet', 'This build contains %d Code Glue snippets', codeGlueSnippets.length), codeGlueSnippets.length) }}
            </p>
            <p class="text-muted-foreground">
              {{ __('Code Glue is custom PHP that runs on your site when these webhooks fire. Only import builds from a source you trust — review each snippet below.') }}
            </p>
          </div>
        </div>

        <div class="space-y-1.5">
          <div v-for="s in codeGlueSnippets" :key="s.key" class="rounded border bg-background min-w-0 overflow-hidden">
            <button
              type="button"
              class="w-full flex items-center gap-2 px-2 py-1.5 text-left text-xs hover:bg-muted/50"
              @click="toggleGlue(s.key)"
            >
              <ChevronDown class="h-3.5 w-3.5 shrink-0 transition-transform" :class="expandedGlue.has(s.key) ? 'rotate-0' : '-rotate-90'" />
              <span class="font-mono truncate min-w-0">{{ s.webhook }} › {{ s.trigger }}</span>
              <span class="ml-auto shrink-0 rounded bg-muted px-1.5 py-0.5 uppercase tracking-wide">{{ s.stage }}</span>
            </button>
            <pre v-if="expandedGlue.has(s.key)" class="max-h-64 w-full overflow-auto border-t bg-muted/40 p-2 text-xs"><code>{{ s.code }}</code></pre>
          </div>
        </div>
      </div>

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

          <p v-if="resolutions[cred.ref].internal" class="flex items-start gap-1.5 text-xs text-muted-foreground">
            <KeyRound class="h-3.5 w-3.5 mt-0.5 shrink-0" />
            <span>{{ __('This build calls this site’s own WP REST API. Reuse or create a WordPress Application Password — there is no secret to paste.') }}</span>
          </p>

          <Select v-model="resolutions[cred.ref].mode">
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem v-if="resolutions[cred.ref].internal" value="provision">{{ __('Create a WP Application Password') }}</SelectItem>
              <SelectItem v-else value="create">{{ __('Create a new vault credential') }}</SelectItem>
              <SelectItem value="existing" :disabled="existingCredentials.length === 0">{{ __('Use an existing vault credential') }}</SelectItem>
              <SelectItem value="skip">{{ __('No authorization') }}</SelectItem>
            </SelectContent>
          </Select>

          <!-- Provision a WP Application Password (internal WP REST) -->
          <div v-if="resolutions[cred.ref].mode === 'provision'">
            <div v-if="resolutions[cred.ref].provisioned_id" class="flex items-center gap-1.5 text-sm text-green-600 dark:text-green-500">
              <Check class="h-4 w-4 shrink-0" />
              <span>{{ sprintf(__('Created “%s”'), resolutions[cred.ref].provisioned_name) }}</span>
            </div>
            <Button v-else size="sm" variant="secondary" :disabled="resolutions[cred.ref].provisioning" @click="provision(cred.ref)">
              <Loader2 v-if="resolutions[cred.ref].provisioning" class="mr-2 h-4 w-4 animate-spin" />
              <KeyRound v-else class="mr-2 h-4 w-4" />
              {{ resolutions[cred.ref].provisioning ? __('Creating…') : __('Create a WP Application Password') }}
            </Button>
          </div>

          <!-- Existing credential picker -->
          <Select v-else-if="resolutions[cred.ref].mode === 'existing'" v-model="resolutions[cred.ref].existing_id">
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

    <!-- Step 3: result -->
    <div v-else-if="step === 'done'" class="space-y-4">
      <div class="flex items-start gap-2 rounded-md border border-green-500/40 bg-green-500/5 p-3">
        <Check class="h-4 w-4 mt-0.5 shrink-0 text-green-600 dark:text-green-500" />
        <div class="text-sm">
          <p class="font-medium text-green-700 dark:text-green-400">{{ __('Import complete') }}</p>
          <p class="text-muted-foreground">
            {{ sprintf(__('Imported %1$d webhook(s) and %2$d chain(s).'), result.webhooks, result.chains) }}
            <span v-if="result.skipped">{{ sprintf(__('%d duplicate(s) skipped.'), result.skipped) }}</span>
          </p>
        </div>
      </div>

      <!-- Anything the importer could not bring across. Silence here used to
           mean a chain arrived with no hops and nobody was told. -->
      <div
        v-if="problems.length"
        class="flex items-start gap-2 rounded-md border border-amber-400/40 bg-amber-50/50 p-3 dark:bg-amber-950/20"
      >
        <AlertTriangle class="h-4 w-4 mt-0.5 shrink-0 text-amber-600 dark:text-amber-400" />
        <div class="text-sm">
          <p class="font-medium text-amber-700 dark:text-amber-300">{{ __('Some parts were left out') }}</p>
          <ul class="mt-1 list-disc space-y-0.5 pl-4 text-xs text-muted-foreground">
            <li v-for="(problem, i) in problems" :key="i">{{ problem }}</li>
          </ul>
        </div>
      </div>

      <!-- Single webhook: edit + jump -->
      <div v-if="singleWebhook" class="flex flex-wrap gap-2">
        <Button size="sm" @click="editWebhook(importedWebhooks[0].id)">
          <Pencil class="mr-2 h-4 w-4" />
          {{ sprintf(__('Edit “%s”'), importedWebhooks[0].name) }}
        </Button>
        <Button size="sm" variant="outline" @click="focusItem('webhook', importedWebhooks[0].id)">
          {{ __('Show in list') }}
        </Button>
      </div>

      <!-- Single chain: jump -->
      <div v-else-if="singleChain">
        <Button size="sm" variant="outline" @click="focusItem('chain', importedChains[0].id)">
          {{ sprintf(__('Show “%s” in list'), importedChains[0].name) }}
        </Button>
      </div>

      <!-- Multiple: links to jump/scroll to each -->
      <div v-else-if="importedWebhooks.length || importedChains.length" class="space-y-1.5">
        <Label class="text-xs text-muted-foreground">{{ __('Jump to an imported item') }}</Label>
        <div class="rounded-md border divide-y">
          <button
            v-for="w in importedWebhooks"
            :key="`w-${w.id}`"
            type="button"
            class="w-full flex items-center gap-2 px-2 py-1.5 text-left text-sm hover:bg-muted/50"
            @click="focusItem('webhook', w.id)"
          >
            <CornerDownRight class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
            <span class="truncate">{{ w.name }}</span>
          </button>
          <button
            v-for="c in importedChains"
            :key="`c-${c.id}`"
            type="button"
            class="w-full flex items-center gap-2 px-2 py-1.5 text-left text-sm hover:bg-muted/50"
            @click="focusItem('chain', c.id)"
          >
            <Network class="h-3.5 w-3.5 shrink-0 text-accent" />
            <span class="truncate">{{ c.name }}</span>
          </button>
        </div>
      </div>
    </div>

    <template #footer>
      <div class="flex gap-2">
        <Button v-if="step === 'resolve'" :disabled="busy" @click="runImport">
          <Loader2 v-if="busy" class="mr-2 h-4 w-4 animate-spin" />
          {{ busy ? __('Importing…') : __('Import') }}
        </Button>
        <Button v-if="step === 'done'" @click="emit('close')">{{ __('Done') }}</Button>
        <Button v-else variant="outline" :disabled="busy" @click="emit('close')">{{ __('Cancel') }}</Button>
      </div>
    </template>
  </Dialog>
</template>
