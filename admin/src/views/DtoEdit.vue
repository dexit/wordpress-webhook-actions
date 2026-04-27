<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import {
  ArrowLeft, Plus, Trash2, Save, Play, ChevronDown, ChevronRight,
  GripVertical, AlertCircle, CheckCircle2, Info,
} from 'lucide-vue-next'
import { Button, Card, Input, Label, Switch, Badge, Alert, Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui'
import api from '@/lib/api'

const props = defineProps({ id: String })
const router = useRouter()

const isNew = computed(() => !props.id)
const saving = ref(false)
const loading = ref(false)
const error = ref(null)
const testLoading = ref(false)
const testResult = ref(null)
const testError = ref(null)
const testPayloadStr = ref('{\n  "name": "Jane Doe",\n  "email": "jane@example.com",\n  "amount": "42.50",\n  "status": "active"\n}')
const testOpen = ref(false)
const tagRefOpen = ref(false)

const form = reactive({
  name: '',
  slug: '',
  description: '',
  is_enabled: true,
  pipeline_config: [],
})

// Field type options
const typeOptions = [
  { value: 'string', label: 'String' },
  { value: 'int', label: 'Integer' },
  { value: 'float', label: 'Float' },
  { value: 'bool', label: 'Boolean' },
  { value: 'array', label: 'Array' },
  { value: 'json', label: 'JSON' },
]

// Radix-vue requires non-empty string values
const typeSelectVal = (field) => field.type || 'string'
const setFieldType = (field, val) => { field.type = val === 'string' ? '' : val }

const addField = () => {
  form.pipeline_config.push({
    output_key: '',
    source: '',
    type: 'string',
    default: '',
    condition: '',
    label: '',
    _open: true,
  })
}

const removeField = (index) => {
  form.pipeline_config.splice(index, 1)
}

const moveField = (index, direction) => {
  const newIndex = index + direction
  if (newIndex < 0 || newIndex >= form.pipeline_config.length) return
  const arr = form.pipeline_config
  ;[arr[index], arr[newIndex]] = [arr[newIndex], arr[index]]
}

const autoSlug = () => {
  if (isNew.value && form.name) {
    form.slug = form.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
  }
}

const load = async () => {
  if (isNew.value) return
  loading.value = true
  try {
    const p = await api.dto.get(props.id)
    form.name = p.name
    form.slug = p.slug
    form.description = p.description || ''
    form.is_enabled = p.is_enabled
    form.pipeline_config = (p.pipeline_config || []).map((f) => ({ ...f, _open: false }))
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

const save = async () => {
  saving.value = true
  error.value = null
  try {
    // Strip UI-only _open flag before sending
    const payload = {
      ...form,
      pipeline_config: form.pipeline_config.map(({ _open, ...f }) => f),
    }

    if (isNew.value) {
      const created = await api.dto.create(payload)
      router.replace(`/dto/${created.id}`)
    } else {
      await api.dto.update(props.id, payload)
    }
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

const runTest = async () => {
  testLoading.value = true
  testError.value = null
  testResult.value = null
  try {
    let body = {}
    try { body = JSON.parse(testPayloadStr.value) } catch { /* ignore */ }
    const id = isNew.value ? null : props.id
    if (!id) {
      testError.value = 'Save the pipeline first before testing.'
      return
    }
    testResult.value = await api.dto.test(id, { payload: body })
  } catch (e) {
    testError.value = e.message
  } finally {
    testLoading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-4xl">
    <!-- Back + title -->
    <div class="flex items-center gap-3 mb-6">
      <Button variant="ghost" size="icon" @click="router.push('/dto')">
        <ArrowLeft class="h-4 w-4" />
      </Button>
      <div>
        <h2 class="text-xl font-semibold">{{ isNew ? 'New Pipeline' : (form.name || 'Edit Pipeline') }}</h2>
        <p class="text-sm text-muted-foreground">Define field mappings and type transforms</p>
      </div>
    </div>

    <div v-if="loading" class="text-center py-8 text-muted-foreground">Loading…</div>

    <form v-else @submit.prevent="save" class="space-y-6">
      <!-- Error -->
      <Alert v-if="error" variant="destructive">
        <AlertCircle class="h-4 w-4" />
        <span>{{ error }}</span>
      </Alert>

      <!-- Basic info -->
      <Card class="p-4 sm:p-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="space-y-1.5">
            <Label>Name <span class="text-destructive">*</span></Label>
            <Input v-model="form.name" @input="autoSlug" placeholder="My ETL Pipeline" required />
          </div>
          <div class="space-y-1.5">
            <Label>Slug <span class="text-destructive">*</span></Label>
            <Input v-model="form.slug" placeholder="my-etl-pipeline" required class="font-mono" />
          </div>
        </div>

        <div class="space-y-1.5">
          <Label>Description</Label>
          <Input v-model="form.description" placeholder="Optional description" />
        </div>

        <div class="flex items-center gap-2">
          <Switch v-model="form.is_enabled" />
          <Label>Enabled</Label>
        </div>
      </Card>

      <!-- Pipeline fields -->
      <div>
        <div class="flex items-center justify-between mb-3">
          <div>
            <h3 class="font-medium">Field Mappings</h3>
            <p class="text-sm text-muted-foreground">
              Each mapping resolves a source template tag into an output key with optional type casting.
            </p>
          </div>
          <Button type="button" variant="outline" size="sm" @click="addField">
            <Plus class="mr-1.5 h-3.5 w-3.5" />
            Add Field
          </Button>
        </div>

        <div v-if="form.pipeline_config.length === 0" class="text-center py-8 text-sm text-muted-foreground border border-dashed rounded-lg">
          No fields yet. Click "Add Field" to start mapping your payload.
        </div>

        <div class="space-y-2">
          <Card
            v-for="(field, index) in form.pipeline_config"
            :key="index"
            class="overflow-hidden"
          >
            <!-- Field header row -->
            <div
              class="flex items-center gap-2 px-3 py-2 cursor-pointer hover:bg-muted/40 transition-colors"
              @click="field._open = !field._open"
            >
              <GripVertical class="h-4 w-4 text-muted-foreground shrink-0" />
              <ChevronDown v-if="field._open" class="h-4 w-4 text-muted-foreground shrink-0" />
              <ChevronRight v-else class="h-4 w-4 text-muted-foreground shrink-0" />

              <span class="font-mono text-sm font-medium min-w-[120px]">
                <span v-if="field.output_key">{{ field.output_key }}</span>
                <span v-else class="italic text-muted-foreground">unnamed</span>
              </span>
              <span v-if="field.type && field.type !== 'string'" class="text-xs bg-muted px-1.5 py-0.5 rounded text-muted-foreground">
                {{ field.type }}
              </span>
              <span v-if="field.source" class="text-xs text-muted-foreground truncate flex-1 font-mono">
                {{ field.source }}
              </span>

              <div class="ml-auto flex gap-1 shrink-0">
                <Button type="button" size="icon" variant="ghost" class="h-6 w-6" :disabled="index === 0" @click.stop="moveField(index, -1)" title="Move up">
                  <ChevronRight class="h-3 w-3 -rotate-90" />
                </Button>
                <Button type="button" size="icon" variant="ghost" class="h-6 w-6" :disabled="index === form.pipeline_config.length - 1" @click.stop="moveField(index, 1)" title="Move down">
                  <ChevronRight class="h-3 w-3 rotate-90" />
                </Button>
                <Button type="button" size="icon" variant="ghost" class="h-6 w-6 text-destructive hover:text-destructive" @click.stop="removeField(index)" title="Remove field">
                  <Trash2 class="h-3 w-3" />
                </Button>
              </div>
            </div>

            <!-- Field body (expanded) -->
            <div v-if="field._open" class="px-3 pb-3 border-t border-border space-y-3 pt-3">
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="space-y-1">
                  <Label class="text-xs">Output Key <span class="text-destructive">*</span></Label>
                  <Input v-model="field.output_key" placeholder="user_email" class="font-mono text-sm h-8" />
                  <p class="text-xs text-muted-foreground">Variable name in <code>$dto['user_email']</code> / <code>&#123;&#123;dto.user_email&#125;&#125;</code></p>
                </div>
                <div class="space-y-1">
                  <Label class="text-xs">Type Cast</Label>
                  <Select :model-value="typeSelectVal(field)" @update:model-value="setFieldType(field, $event)">
                    <SelectTrigger class="h-8 text-sm">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem v-for="opt in typeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>

              <div class="space-y-1">
                <Label class="text-xs">Source Template <span class="text-destructive">*</span></Label>
                <Input
                  v-model="field.source"
                  placeholder="{{received.body.email|lower|trim}}"
                  class="font-mono text-sm h-8"
                />
                <p class="text-xs text-muted-foreground">Template tag or static value. Use modifiers: <code>|lower</code>, <code>|trim</code>, <code>|int</code>, etc.</p>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="space-y-1">
                  <Label class="text-xs">Default Value</Label>
                  <Input v-model="field.default" placeholder="Fallback when source is empty" class="text-sm h-8" />
                </div>
                <div class="space-y-1">
                  <Label class="text-xs">Label (UI only)</Label>
                  <Input v-model="field.label" placeholder="Friendly display name" class="text-sm h-8" />
                </div>
              </div>

              <div class="space-y-1">
                <Label class="text-xs">Condition (optional)</Label>
                <Input
                  v-model="field.condition"
                  placeholder="{{received.body.type}} == order"
                  class="font-mono text-sm h-8"
                />
                <p class="text-xs text-muted-foreground">Skip this field unless condition is true. Supports <code>==</code>, <code>!=</code>, <code>&gt;</code>, <code>&lt;</code>, <code>contains</code>, <code>not_contains</code>.</p>
              </div>
            </div>
          </Card>
        </div>
      </div>

      <!-- Template tag reference -->
      <Card class="p-4 overflow-hidden">
        <button
          type="button"
          class="flex items-center gap-2 w-full text-left"
          @click="tagRefOpen = !tagRefOpen"
        >
          <Info class="h-4 w-4 text-muted-foreground shrink-0" />
          <span class="text-sm font-medium">Template Tag Reference</span>
          <ChevronDown v-if="tagRefOpen" class="h-4 w-4 text-muted-foreground ml-auto" />
          <ChevronRight v-else class="h-4 w-4 text-muted-foreground ml-auto" />
        </button>
        <div v-if="tagRefOpen" class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs text-muted-foreground">
          <div>
            <p class="font-semibold text-foreground mb-1">Payload Body</p>
            <p class="font-mono">&#123;&#123;received.body.field&#125;&#125;</p>
            <p class="font-mono">&#123;&#123;received.body.user.name&#125;&#125;</p>
            <p class="font-mono">&#123;&#123;payload.field&#125;&#125; <span class="not-italic">(alias)</span></p>
          </div>
          <div>
            <p class="font-semibold text-foreground mb-1">Query / Headers / Meta</p>
            <p class="font-mono">&#123;&#123;received.query.param&#125;&#125;</p>
            <p class="font-mono">&#123;&#123;received.headers.x-event-type&#125;&#125;</p>
            <p class="font-mono">&#123;&#123;received.meta.method&#125;&#125;</p>
          </div>
          <div>
            <p class="font-semibold text-foreground mb-1">System Variables</p>
            <p class="font-mono">&#123;&#123;timestamp&#125;&#125; &#123;&#123;datetime&#125;&#125; &#123;&#123;uuid&#125;&#125;</p>
            <p class="font-mono">&#123;&#123;site_url&#125;&#125; &#123;&#123;blog_name&#125;&#125;</p>
          </div>
          <div>
            <p class="font-semibold text-foreground mb-1">Modifiers</p>
            <p class="font-mono">lower / upper / trim / slug</p>
            <p class="font-mono">int / float / round:2 / abs</p>
            <p class="font-mono">date:Y-m-d / base64 / json</p>
            <p class="font-mono">default:N/A / first / last / count</p>
          </div>
        </div>
      </Card>

      <!-- Test Panel -->
      <Card class="p-4 overflow-hidden">
        <button
          type="button"
          class="flex items-center gap-2 w-full text-left"
          @click="testOpen = !testOpen"
        >
          <Play class="h-4 w-4 text-muted-foreground shrink-0" />
          <span class="text-sm font-medium">Test Pipeline</span>
          <ChevronDown v-if="testOpen" class="h-4 w-4 text-muted-foreground ml-auto" />
          <ChevronRight v-else class="h-4 w-4 text-muted-foreground ml-auto" />
        </button>

        <div v-if="testOpen" class="mt-3 space-y-3">
          <div class="space-y-1">
            <Label class="text-xs">Sample Payload (JSON)</Label>
            <textarea
              v-model="testPayloadStr"
              rows="6"
              class="w-full font-mono text-xs border border-input rounded-md p-2 bg-background text-foreground resize-y focus:outline-none focus:ring-2 focus:ring-ring"
            />
          </div>
          <Button type="button" variant="outline" size="sm" :disabled="testLoading || isNew" @click="runTest">
            <Play class="mr-1.5 h-3.5 w-3.5" />
            {{ testLoading ? 'Running…' : 'Run Test' }}
          </Button>
          <p v-if="isNew" class="text-xs text-muted-foreground">Save the pipeline first to test it.</p>
          <div v-if="testError" class="text-xs text-destructive">{{ testError }}</div>
          <div v-if="testResult" class="space-y-2">
            <div class="flex items-center gap-1.5 text-xs text-green-600 dark:text-green-400 font-medium">
              <CheckCircle2 class="h-3.5 w-3.5" />
              Pipeline ran successfully
            </div>
            <div class="bg-muted rounded-md p-3">
              <p class="text-xs font-semibold text-muted-foreground mb-1">DTO Output:</p>
              <pre class="text-xs font-mono whitespace-pre-wrap break-all">{{ JSON.stringify(testResult.dto, null, 2) }}</pre>
            </div>
          </div>
        </div>
      </Card>

      <!-- Save -->
      <div class="flex items-center gap-3">
        <Button type="submit" :disabled="saving">
          <Save class="mr-2 h-4 w-4" />
          {{ saving ? 'Saving…' : (isNew ? 'Create Pipeline' : 'Save Changes') }}
        </Button>
        <Button type="button" variant="outline" @click="router.push('/dto')">Cancel</Button>
      </div>
    </form>
  </div>
</template>
