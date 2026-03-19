<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { ArrowLeft, Copy, Check, Inbox } from 'lucide-vue-next'
import { Button, Card, Alert, Input, Label, Switch, Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui'
import api from '@/lib/api'

const router = useRouter()
const route = useRoute()

const isEdit = computed(() => !!route.params.id)
const pageTitle = computed(() => isEdit.value ? 'Edit Endpoint' : 'New Endpoint')

const loading = ref(false)
const saving = ref(false)
const error = ref(null)
const copiedUrl = ref(false)

const form = ref({
  name: '',
  slug: '',
  description: '',
  secret_key: '',
  hmac_algorithm: 'sha256',
  hmac_header: '',
  is_enabled: true,
  response_code: 200,
  response_body: '',
})

const receiverUrl = ref('')
const slugDirty = ref(false)

const autoSlug = (name) => name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')

const onNameInput = () => {
  if (!slugDirty.value && !isEdit.value) {
    form.value.slug = autoSlug(form.value.name)
  }
}

const onSlugInput = () => {
  slugDirty.value = true
  form.value.slug = autoSlug(form.value.slug)
}

// Radix-vue Select requires non-empty string values — store as string, cast on submit
const responseCodeSelect = computed({
  get: () => String(form.value.response_code),
  set: (val) => { form.value.response_code = Number(val) },
})

const errors = ref({})

const validate = () => {
  errors.value = {}
  if (!form.value.name.trim()) errors.value.name = 'Name is required.'
  if (!form.value.slug.trim()) errors.value.slug = 'Slug is required.'
  if (!/^[a-z0-9\-_]+$/.test(form.value.slug)) errors.value.slug = 'Slug may only contain lowercase letters, numbers, hyphens, and underscores.'
  return Object.keys(errors.value).length === 0
}

const loadEndpoint = async () => {
  if (!isEdit.value) return
  loading.value = true
  error.value = null
  try {
    const ep = await api.endpoints.get(route.params.id)
    form.value = {
      name: ep.name,
      slug: ep.slug,
      description: ep.description || '',
      secret_key: ep.secret_key || '',
      hmac_algorithm: ep.hmac_algorithm || 'sha256',
      hmac_header: ep.hmac_header || '',
      is_enabled: ep.is_enabled,
      response_code: ep.response_code,
      response_body: ep.response_body || '',
    }
    receiverUrl.value = ep.receiver_url || ''
    slugDirty.value = true
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

const handleSubmit = async () => {
  if (!validate()) return
  saving.value = true
  error.value = null
  try {
    const payload = {
      ...form.value,
      response_code: Number(form.value.response_code),
    }
    if (isEdit.value) {
      const updated = await api.endpoints.update(route.params.id, payload)
      receiverUrl.value = updated.receiver_url || ''
    } else {
      await api.endpoints.create(payload)
      router.push('/endpoints')
    }
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

const copyUrl = async () => {
  try {
    await navigator.clipboard.writeText(receiverUrl.value)
    copiedUrl.value = true
    setTimeout(() => { copiedUrl.value = false }, 2000)
  } catch (e) {
    console.error('Failed to copy:', e)
  }
}

onMounted(loadEndpoint)
</script>

<template>
  <div>
    <!-- Header -->
    <div class="mb-6">
      <Button variant="ghost" size="sm" class="mb-2" @click="router.push('/endpoints')">
        <ArrowLeft class="mr-2 h-4 w-4" />
        Back to endpoints
      </Button>
      <h2 class="text-xl font-semibold">{{ pageTitle }}</h2>
    </div>

    <div v-if="loading" class="text-center py-8 text-muted-foreground">Loading...</div>

    <Alert v-else-if="error && !form.name" variant="destructive" class="mb-4">{{ error }}</Alert>

    <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Form -->
      <Card class="p-6">
        <Alert v-if="error" variant="destructive" class="mb-4">{{ error }}</Alert>

        <form @submit.prevent="handleSubmit" class="space-y-4">
          <!-- Name -->
          <div class="space-y-1.5">
            <Label for="ep-name">Name <span class="text-destructive">*</span></Label>
            <Input
              id="ep-name"
              v-model="form.name"
              placeholder="My Webhook Receiver"
              @input="onNameInput"
              :class="errors.name ? 'border-destructive' : ''"
            />
            <p v-if="errors.name" class="text-xs text-destructive">{{ errors.name }}</p>
          </div>

          <!-- Slug -->
          <div class="space-y-1.5">
            <Label for="ep-slug">Slug <span class="text-destructive">*</span></Label>
            <Input
              id="ep-slug"
              v-model="form.slug"
              placeholder="my-webhook-receiver"
              @input="onSlugInput"
              :class="errors.slug ? 'border-destructive' : ''"
            />
            <p class="text-xs text-muted-foreground">Used in the receiver URL. Only lowercase letters, numbers, hyphens, underscores.</p>
            <p v-if="errors.slug" class="text-xs text-destructive">{{ errors.slug }}</p>
          </div>

          <!-- Description -->
          <div class="space-y-1.5">
            <Label for="ep-desc">Description</Label>
            <Input id="ep-desc" v-model="form.description" placeholder="Optional description" />
          </div>

          <!-- Enabled toggle -->
          <div class="flex items-center gap-3">
            <Switch :model-value="form.is_enabled" @update:model-value="form.is_enabled = $event" />
            <Label class="cursor-pointer select-none" @click="form.is_enabled = !form.is_enabled">
              {{ form.is_enabled ? 'Enabled' : 'Disabled' }}
            </Label>
          </div>

          <hr class="border-border" />

          <!-- Secret Key -->
          <div class="space-y-1.5">
            <Label for="ep-secret">Secret Key (HMAC)</Label>
            <Input
              id="ep-secret"
              v-model="form.secret_key"
              type="password"
              placeholder="Leave blank to skip signature verification"
              autocomplete="new-password"
            />
            <p class="text-xs text-muted-foreground">When set, incoming requests must include a valid HMAC signature.</p>
          </div>

          <!-- HMAC Algorithm -->
          <div v-if="form.secret_key" class="space-y-1.5">
            <Label>HMAC Algorithm</Label>
            <Select v-model="form.hmac_algorithm">
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="sha256">SHA-256 (recommended)</SelectItem>
                <SelectItem value="sha1">SHA-1</SelectItem>
                <SelectItem value="sha512">SHA-512</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <!-- HMAC Header -->
          <div v-if="form.secret_key" class="space-y-1.5">
            <Label for="ep-hmac-header">Signature Header</Label>
            <Input
              id="ep-hmac-header"
              v-model="form.hmac_header"
              placeholder="e.g. X-Hub-Signature-256 (auto-detected if blank)"
            />
            <p class="text-xs text-muted-foreground">Header containing the HMAC signature. Leave blank to auto-detect GitHub/Stripe patterns.</p>
          </div>

          <hr class="border-border" />

          <!-- Response Code -->
          <div class="space-y-1.5">
            <Label>Response HTTP Code</Label>
            <Select v-model="responseCodeSelect">
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="200">200 OK</SelectItem>
                <SelectItem value="201">201 Created</SelectItem>
                <SelectItem value="202">202 Accepted</SelectItem>
                <SelectItem value="204">204 No Content</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <!-- Response Body -->
          <div v-if="form.response_code !== 204" class="space-y-1.5">
            <Label for="ep-response">Response Body (JSON)</Label>
            <Input
              id="ep-response"
              v-model="form.response_body"
              placeholder='{"received":true}'
            />
            <p class="text-xs text-muted-foreground">Optional. Defaults to <code class="font-mono">{"received":true}</code>.</p>
          </div>

          <!-- Actions -->
          <div class="flex gap-2 pt-2">
            <Button type="submit" :loading="saving">
              {{ isEdit ? 'Save Changes' : 'Create Endpoint' }}
            </Button>
            <Button type="button" variant="outline" @click="router.push('/endpoints')">Cancel</Button>
          </div>
        </form>
      </Card>

      <!-- Receiver URL info (edit mode) -->
      <div v-if="isEdit && receiverUrl" class="space-y-4">
        <Card class="p-6">
          <h3 class="font-medium mb-3">Receiver URL</h3>
          <p class="text-sm text-muted-foreground mb-3">
            Point your external service to POST to this URL. The payload will be stored for processing.
          </p>
          <div class="flex items-center gap-2 p-3 rounded-md bg-muted">
            <code class="text-xs font-mono flex-1 break-all">{{ receiverUrl }}</code>
            <button
              @click="copyUrl"
              class="shrink-0 text-muted-foreground hover:text-foreground transition-colors"
              title="Copy URL"
            >
              <Check v-if="copiedUrl" class="w-4 h-4 text-green-500" />
              <Copy v-else class="w-4 h-4" />
            </button>
          </div>
          <div class="mt-4 space-y-2">
            <p class="text-xs text-muted-foreground font-semibold uppercase tracking-wide">Accepted methods</p>
            <p class="text-xs text-muted-foreground">POST, PUT, PATCH, GET, DELETE</p>
          </div>
          <div v-if="form.secret_key" class="mt-4 p-3 rounded-md bg-yellow-50 dark:bg-yellow-950/30 border border-yellow-200 dark:border-yellow-800">
            <p class="text-xs text-yellow-800 dark:text-yellow-300">
              <strong>Signature verification enabled.</strong>
              Requests without a valid <code class="font-mono">{{ form.hmac_header || 'X-Hub-Signature-256' }}</code> header will be rejected.
            </p>
          </div>
        </Card>

        <Card class="p-6">
          <h3 class="font-medium mb-3">Received Payloads</h3>
          <p class="text-sm text-muted-foreground mb-4">
            Inspect and manage payloads received by this endpoint.
          </p>
          <Button variant="outline" @click="router.push(`/endpoints/${route.params.id}/payloads`)">
            <Inbox class="mr-2 h-4 w-4" />
            View Payloads
          </Button>
        </Card>
      </div>
    </div>
  </div>
</template>
