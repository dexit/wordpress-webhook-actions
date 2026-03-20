<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { ArrowLeft, Copy, Check, Inbox, ScrollText, Settings, ShieldCheck, Database, Code2, ChevronDown, ChevronRight } from 'lucide-vue-next'
import {
  Button, Card, Alert, Input, Label, Switch, Tabs,
  Select, SelectTrigger, SelectValue, SelectContent, SelectItem,
} from '@/components/ui'
import api from '@/lib/api'

const router = useRouter()
const route  = useRoute()

const isEdit    = computed(() => !!route.params.id)
const pageTitle = computed(() => isEdit.value ? 'Edit Endpoint' : 'New Endpoint')

const loading   = ref(false)
const saving    = ref(false)
const error     = ref(null)
const copiedUrl = ref(false)

// ---------------------------------------------------------------------------
// Form state
// ---------------------------------------------------------------------------
const form = ref({
  // General
  name:            '',
  slug:            '',
  description:     '',
  is_enabled:      true,
  allowed_methods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
  response_code:   200,
  response_body:   '',

  // Auth
  auth_mode:   'none',
  auth_config: { username: '', password: '', token: '', key: '', header: 'X-API-Key', param: 'api_key', secret: '', algorithm: 'sha256' },
  // Legacy HMAC fields (still supported)
  secret_key:      '',
  hmac_algorithm:  'sha256',
  hmac_header:     '',

  // CPT mapping
  cpt_enabled: false,
  cpt_config: {
    post_type:          'post',
    operation:          'create',
    post_status:        'publish',
    title_template:     '',
    content_template:   '',
    lookup_meta_key:    '',
    lookup_template:    '',
    meta_mappings:      [],
    flatten_meta:       false,
    flatten_meta_prefix:'fswa_',
  },

  // Function / hooks
  function_enabled: false,
  function_code:    '',
  hooks_to_fire:    '',
})

const receiverUrl = ref('')
const slugDirty   = ref(false)
const errors      = ref({})

const allMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']

// Radix-vue Select computed wrappers (must be non-empty strings)
const responseCodeSelect = computed({
  get: () => String(form.value.response_code),
  set: (v) => { form.value.response_code = Number(v) },
})

// ---------------------------------------------------------------------------
// Slug auto-derive
// ---------------------------------------------------------------------------
const autoSlug = (s) => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')

const onNameInput = () => {
  if (!slugDirty.value && !isEdit.value) form.value.slug = autoSlug(form.value.name)
}
const onSlugInput = () => {
  slugDirty.value = true
  form.value.slug = autoSlug(form.value.slug)
}

// ---------------------------------------------------------------------------
// Method toggles
// ---------------------------------------------------------------------------
const toggleMethod = (m) => {
  const arr = form.value.allowed_methods
  const idx = arr.indexOf(m)
  if (idx === -1) arr.push(m)
  else arr.splice(idx, 1)
}

// ---------------------------------------------------------------------------
// CPT meta mappings
// ---------------------------------------------------------------------------
const addMetaMapping = () => form.value.cpt_config.meta_mappings.push({ meta_key: '', template: '' })
const removeMetaMapping = (i) => form.value.cpt_config.meta_mappings.splice(i, 1)

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------
const validate = () => {
  errors.value = {}
  if (!form.value.name.trim()) errors.value.name = 'Name is required.'
  if (!form.value.slug.trim()) errors.value.slug = 'Slug is required.'
  if (!/^[a-z0-9\-_]+$/.test(form.value.slug)) errors.value.slug = 'Slug may only contain lowercase letters, numbers, hyphens, underscores.'
  return Object.keys(errors.value).length === 0
}

// ---------------------------------------------------------------------------
// Load / Save
// ---------------------------------------------------------------------------
const loadEndpoint = async () => {
  if (!isEdit.value) return
  loading.value = true
  error.value   = null
  try {
    const ep = await api.endpoints.get(route.params.id)
    form.value = {
      name:            ep.name,
      slug:            ep.slug,
      description:     ep.description      || '',
      is_enabled:      ep.is_enabled,
      allowed_methods: Array.isArray(ep.allowed_methods) ? ep.allowed_methods : ['GET','POST','PUT','PATCH','DELETE'],
      response_code:   ep.response_code,
      response_body:   ep.response_body    || '',
      auth_mode:       ep.auth_mode        || 'none',
      auth_config:     ep.auth_config      || { username:'', password:'', token:'', key:'', header:'X-API-Key', param:'api_key', secret:'', algorithm:'sha256' },
      secret_key:      ep.secret_key       || '',
      hmac_algorithm:  ep.hmac_algorithm   || 'sha256',
      hmac_header:     ep.hmac_header      || '',
      cpt_enabled:     ep.cpt_enabled,
      cpt_config:      ep.cpt_config       || { post_type:'post', operation:'create', post_status:'publish', title_template:'', content_template:'', lookup_meta_key:'', lookup_template:'', meta_mappings:[], flatten_meta:false, flatten_meta_prefix:'fswa_' },
      function_enabled:ep.function_enabled,
      function_code:   ep.function_code    || '',
      hooks_to_fire:   ep.hooks_to_fire    || '',
    }
    receiverUrl.value = ep.receiver_url || ''
    slugDirty.value   = true
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

const handleSubmit = async () => {
  if (!validate()) return
  saving.value = true
  error.value  = null
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
  } catch { /* */ }
}

// ---------------------------------------------------------------------------
// Tabs config
// ---------------------------------------------------------------------------
const tabs = [
  { key: 'general',  label: 'General',    icon: Settings },
  { key: 'auth',     label: 'Auth',       icon: ShieldCheck },
  { key: 'cpt',      label: 'CPT Mapping',icon: Database },
  { key: 'function', label: 'Function',   icon: Code2 },
]

// ---------------------------------------------------------------------------
// Tag reference accordion
// ---------------------------------------------------------------------------
const openTagGroup = ref('body')

// ---------------------------------------------------------------------------
// Code snippets
// ---------------------------------------------------------------------------
const selectedSnippet = ref('')

const snippets = [
  {
    id: 'simple_ack',
    name: 'Simple acknowledgment',
    code: `// Available: $payload, $query, $headers, $endpoint, $context
return [
    'success'   => true,
    'message'   => 'Webhook received',
    'timestamp' => current_time('mysql'),
];`,
  },
  {
    id: 'create_post',
    name: 'Create post from payload',
    code: `$title   = $payload['title']   ?? 'Untitled';
$content = $payload['content'] ?? '';
$status  = in_array($payload['status'] ?? '', ['publish','draft','pending']) ? $payload['status'] : 'draft';

$post_id = wp_insert_post([
    'post_title'   => sanitize_text_field($title),
    'post_content' => wp_kses_post($content),
    'post_status'  => $status,
    'post_type'    => 'post',
]);

if (is_wp_error($post_id)) {
    return ['success' => false, 'error' => $post_id->get_error_message()];
}

return ['success' => true, 'post_id' => $post_id, 'url' => get_permalink($post_id)];`,
  },
  {
    id: 'create_user',
    name: 'Create / update user',
    code: `$email = sanitize_email($payload['email'] ?? '');
$name  = sanitize_text_field($payload['name'] ?? '');

if (empty($email)) {
    return ['success' => false, 'error' => 'Email required'];
}

$user_id = email_exists($email);

if (!$user_id) {
    $user_id = wp_insert_user([
        'user_login'   => $email,
        'user_email'   => $email,
        'display_name' => $name,
        'role'         => 'subscriber',
        'user_pass'    => wp_generate_password(),
    ]);
} else {
    wp_update_user(['ID' => $user_id, 'display_name' => $name]);
}

if (is_wp_error($user_id)) {
    return ['success' => false, 'error' => $user_id->get_error_message()];
}

return ['success' => true, 'user_id' => $user_id];`,
  },
  {
    id: 'update_meta',
    name: 'Update post / user meta',
    code: `$type = $payload['type'] ?? 'post'; // 'post' or 'user'
$id   = (int) ($payload['id'] ?? 0);
$meta = $payload['meta'] ?? [];

if (!$id || empty($meta)) {
    return ['success' => false, 'error' => 'id and meta required'];
}

$updated = [];
foreach ($meta as $key => $value) {
    $k = sanitize_key($key);
    if ($type === 'user') {
        update_user_meta($id, $k, $value);
    } else {
        update_post_meta($id, $k, $value);
    }
    $updated[] = $k;
}

return ['success' => true, 'updated' => $updated];`,
  },
  {
    id: 'send_email',
    name: 'Send email notification',
    code: `$to      = sanitize_email($payload['to'] ?? get_option('admin_email'));
$subject = sanitize_text_field($payload['subject'] ?? 'Webhook notification');
$message = wp_kses_post($payload['message'] ?? wp_json_encode($payload));

$sent = wp_mail($to, $subject, $message);

return ['success' => $sent, 'to' => $to];`,
  },
  {
    id: 'forward_webhook',
    name: 'Forward to another URL',
    code: `$forward_url = 'https://api.example.com/webhook';

$response = wp_remote_post($forward_url, [
    'headers' => ['Content-Type' => 'application/json'],
    'body'    => wp_json_encode($payload),
    'timeout' => 30,
]);

if (is_wp_error($response)) {
    return ['success' => false, 'error' => $response->get_error_message()];
}

return [
    'success'     => true,
    'status_code' => wp_remote_retrieve_response_code($response),
    'response'    => json_decode(wp_remote_retrieve_body($response), true),
];`,
  },
  {
    id: 'log_and_store',
    name: 'Log payload to error log',
    code: `// Log incoming payload for debugging
error_log('[FSWA] Endpoint: ' . ($endpoint['name'] ?? '?'));
error_log('[FSWA] Method: ' . ($context['received']['meta']['method'] ?? '?'));
error_log('[FSWA] Payload: ' . json_encode($payload));

// Store a transient for quick inspection
set_transient('fswa_last_payload_' . ($endpoint['slug'] ?? 'unknown'), $payload, HOUR_IN_SECONDS);

return ['received' => true, 'logged' => true];`,
  },
]

const insertSnippet = () => {
  const s = snippets.find((x) => x.id === selectedSnippet.value)
  if (s) {
    form.value.function_code = s.code
    form.value.function_enabled = true
  }
  selectedSnippet.value = ''
}

onMounted(loadEndpoint)
</script>

<template>
  <div>
    <!-- Header -->
    <div class="mb-6">
      <Button variant="ghost" size="sm" class="mb-2" @click="router.push('/endpoints')">
        <ArrowLeft class="mr-2 h-4 w-4" />Back to endpoints
      </Button>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-xl font-semibold">{{ pageTitle }}</h2>
        <div v-if="isEdit" class="flex gap-2">
          <Button variant="outline" size="sm" @click="router.push(`/endpoints/${route.params.id}/payloads`)">
            <Inbox class="mr-2 h-4 w-4" />Payloads
          </Button>
          <Button variant="outline" size="sm" @click="router.push(`/endpoints/${route.params.id}/logs`)">
            <ScrollText class="mr-2 h-4 w-4" />Logs
          </Button>
        </div>
      </div>
    </div>

    <div v-if="loading" class="text-center py-8 text-muted-foreground">Loading...</div>
    <Alert v-else-if="error && !form.name" variant="destructive" class="mb-4">{{ error }}</Alert>

    <form v-else @submit.prevent="handleSubmit">
      <Alert v-if="error" variant="destructive" class="mb-4">{{ error }}</Alert>

      <!-- Receiver URL banner (edit mode) -->
      <Card v-if="isEdit && receiverUrl" class="p-4 mb-6">
        <div class="flex flex-wrap items-center gap-3">
          <span class="text-xs text-muted-foreground">Receiver URL</span>
          <code class="text-xs font-mono flex-1 truncate">{{ receiverUrl }}</code>
          <button type="button" @click="copyUrl" class="text-muted-foreground hover:text-foreground transition-colors shrink-0">
            <Check v-if="copiedUrl" class="w-4 h-4 text-green-500" />
            <Copy v-else class="w-4 h-4" />
          </button>
        </div>
      </Card>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Tabs panel -->
        <Card class="p-6 lg:col-span-2">
          <Tabs :tabs="tabs">

            <!-- ── GENERAL ─────────────────────────────────────────── -->
            <template #general>
              <div class="space-y-4">
                <div class="space-y-1.5">
                  <Label>Name <span class="text-destructive">*</span></Label>
                  <Input v-model="form.name" placeholder="My Webhook Receiver" @input="onNameInput" :class="errors.name ? 'border-destructive':''"/>
                  <p v-if="errors.name" class="text-xs text-destructive">{{ errors.name }}</p>
                </div>

                <div class="space-y-1.5">
                  <Label>Slug <span class="text-destructive">*</span></Label>
                  <Input v-model="form.slug" placeholder="my-webhook-receiver" @input="onSlugInput" :class="errors.slug ? 'border-destructive':''"/>
                  <p class="text-xs text-muted-foreground">Used in receiver URL. Lowercase, numbers, hyphens, underscores only.</p>
                  <p v-if="errors.slug" class="text-xs text-destructive">{{ errors.slug }}</p>
                </div>

                <div class="space-y-1.5">
                  <Label>Description</Label>
                  <Input v-model="form.description" placeholder="Optional" />
                </div>

                <div class="flex items-center gap-3">
                  <Switch :model-value="form.is_enabled" @update:model-value="form.is_enabled = $event" />
                  <Label class="cursor-pointer select-none" @click="form.is_enabled = !form.is_enabled">
                    {{ form.is_enabled ? 'Enabled' : 'Disabled' }}
                  </Label>
                </div>

                <hr class="border-border" />

                <!-- Allowed Methods -->
                <div class="space-y-2">
                  <Label>Allowed HTTP Methods</Label>
                  <div class="flex flex-wrap gap-2">
                    <button
                      v-for="m in allMethods"
                      :key="m"
                      type="button"
                      @click="toggleMethod(m)"
                      :class="[
                        'px-3 py-1 rounded-full text-xs font-medium border transition-colors',
                        form.allowed_methods.includes(m)
                          ? 'bg-primary text-primary-foreground border-primary'
                          : 'border-border text-muted-foreground hover:border-foreground',
                      ]"
                    >{{ m }}</button>
                  </div>
                </div>

                <hr class="border-border" />

                <!-- Response -->
                <div class="space-y-1.5">
                  <Label>Response HTTP Code</Label>
                  <Select v-model="responseCodeSelect">
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="200">200 OK</SelectItem>
                      <SelectItem value="201">201 Created</SelectItem>
                      <SelectItem value="202">202 Accepted</SelectItem>
                      <SelectItem value="204">204 No Content</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div v-if="form.response_code !== 204" class="space-y-1.5">
                  <Label>Response Body (JSON / merge tags)</Label>
                  <Input v-model="form.response_body" placeholder='{"received":true}' />
                  <p class="text-xs text-muted-foreground">Supports merge tags: <code class="font-mono">&#123;&#123;received.body.field&#125;&#125;</code></p>
                </div>
              </div>
            </template>

            <!-- ── AUTH ───────────────────────────────────────────── -->
            <template #auth>
              <div class="space-y-4">
                <div class="space-y-1.5">
                  <Label>Authentication Mode</Label>
                  <Select v-model="form.auth_mode">
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">None (open)</SelectItem>
                      <SelectItem value="hmac">HMAC Signature</SelectItem>
                      <SelectItem value="basic">HTTP Basic Auth</SelectItem>
                      <SelectItem value="bearer">Bearer Token</SelectItem>
                      <SelectItem value="api_key">API Key (header or query param)</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <!-- HMAC -->
                <template v-if="form.auth_mode === 'hmac'">
                  <div class="space-y-1.5">
                    <Label>Secret Key</Label>
                    <Input v-model="form.secret_key" type="password" placeholder="Shared HMAC secret" autocomplete="new-password" />
                  </div>
                  <div class="space-y-1.5">
                    <Label>Algorithm</Label>
                    <Select v-model="form.hmac_algorithm">
                      <SelectTrigger><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="sha256">SHA-256 (recommended)</SelectItem>
                        <SelectItem value="sha1">SHA-1</SelectItem>
                        <SelectItem value="sha512">SHA-512</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div class="space-y-1.5">
                    <Label>Signature Header</Label>
                    <Input v-model="form.hmac_header" placeholder="X-Hub-Signature-256 (auto-detect if blank)" />
                  </div>
                </template>

                <!-- Basic -->
                <template v-if="form.auth_mode === 'basic'">
                  <div class="space-y-1.5">
                    <Label>Username</Label>
                    <Input v-model="form.auth_config.username" autocomplete="off" />
                  </div>
                  <div class="space-y-1.5">
                    <Label>Password</Label>
                    <Input v-model="form.auth_config.password" type="password" autocomplete="new-password" />
                  </div>
                </template>

                <!-- Bearer -->
                <template v-if="form.auth_mode === 'bearer'">
                  <div class="space-y-1.5">
                    <Label>Expected Token</Label>
                    <Input v-model="form.auth_config.token" type="password" placeholder="Bearer token value" autocomplete="new-password" />
                  </div>
                  <div class="space-y-1.5">
                    <Label>Query Param Fallback</Label>
                    <Input v-model="form.auth_config.param" placeholder="access_token" />
                    <p class="text-xs text-muted-foreground">If Authorization header absent, read token from this query param.</p>
                  </div>
                </template>

                <!-- API Key -->
                <template v-if="form.auth_mode === 'api_key'">
                  <div class="space-y-1.5">
                    <Label>API Key Value</Label>
                    <Input v-model="form.auth_config.key" type="password" autocomplete="new-password" />
                  </div>
                  <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1.5">
                      <Label>Accept via Header</Label>
                      <Input v-model="form.auth_config.header" placeholder="X-API-Key" />
                    </div>
                    <div class="space-y-1.5">
                      <Label>Accept via Query Param</Label>
                      <Input v-model="form.auth_config.param" placeholder="api_key" />
                    </div>
                  </div>
                </template>

                <div v-if="form.auth_mode === 'none'" class="p-3 rounded-md bg-muted text-sm text-muted-foreground">
                  This endpoint accepts requests without authentication. Anyone with the URL can send data.
                </div>
              </div>
            </template>

            <!-- ── CPT MAPPING ────────────────────────────────────── -->
            <template #cpt>
              <div class="space-y-4">
                <div class="flex items-center gap-3">
                  <Switch :model-value="form.cpt_enabled" @update:model-value="form.cpt_enabled = $event" />
                  <Label class="cursor-pointer select-none" @click="form.cpt_enabled = !form.cpt_enabled">
                    {{ form.cpt_enabled ? 'CPT mapping enabled' : 'CPT mapping disabled' }}
                  </Label>
                </div>

                <template v-if="form.cpt_enabled">
                  <div class="p-3 text-xs text-muted-foreground rounded-md bg-muted space-y-1">
                    <p><strong>Merge tag syntax:</strong> <code class="font-mono">&#123;&#123;received.body.field&#125;&#125;</code> — <code class="font-mono">&#123;&#123;received.query.param&#125;&#125;</code> — <code class="font-mono">&#123;&#123;received.headers.x-header&#125;&#125;</code></p>
                    <p>Nested paths: <code class="font-mono">&#123;&#123;received.body.user.name&#125;&#125;</code> — Array index: <code class="font-mono">&#123;&#123;received.body.items.0&#125;&#125;</code></p>
                  </div>

                  <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1.5">
                      <Label>Post Type</Label>
                      <Input v-model="form.cpt_config.post_type" placeholder="post" />
                    </div>
                    <div class="space-y-1.5">
                      <Label>Post Status</Label>
                      <Select v-model="form.cpt_config.post_status">
                        <SelectTrigger><SelectValue /></SelectTrigger>
                        <SelectContent>
                          <SelectItem value="publish">Publish</SelectItem>
                          <SelectItem value="draft">Draft</SelectItem>
                          <SelectItem value="pending">Pending</SelectItem>
                          <SelectItem value="private">Private</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                  </div>

                  <div class="space-y-1.5">
                    <Label>Operation</Label>
                    <Select v-model="form.cpt_config.operation">
                      <SelectTrigger><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="create">Create (always new)</SelectItem>
                        <SelectItem value="upsert">Upsert (create or update by lookup)</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>

                  <template v-if="form.cpt_config.operation === 'upsert'">
                    <div class="grid grid-cols-2 gap-3">
                      <div class="space-y-1.5">
                        <Label>Lookup Meta Key</Label>
                        <Input v-model="form.cpt_config.lookup_meta_key" placeholder="_external_id" />
                      </div>
                      <div class="space-y-1.5">
                        <Label>Lookup Value Template</Label>
                        <Input v-model="form.cpt_config.lookup_template" placeholder="{{received.body.id}}" />
                      </div>
                    </div>
                  </template>

                  <div class="space-y-1.5">
                    <Label>Post Title Template</Label>
                    <Input v-model="form.cpt_config.title_template" placeholder="{{received.body.title}}" />
                  </div>

                  <div class="space-y-1.5">
                    <Label>Post Content Template</Label>
                    <textarea
                      v-model="form.cpt_config.content_template"
                      rows="3"
                      placeholder="{{received.body.description}}"
                      class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring resize-y font-mono"
                    />
                  </div>

                  <hr class="border-border" />

                  <!-- Meta mappings -->
                  <div class="space-y-2">
                    <div class="flex items-center justify-between">
                      <Label>Meta Mappings</Label>
                      <Button type="button" variant="outline" size="sm" @click="addMetaMapping">+ Add</Button>
                    </div>
                    <p class="text-xs text-muted-foreground">Use <code class="font-mono">&#123;&#123;_flatten&#125;&#125;</code> as template to store the entire flattened body as JSON.</p>

                    <div v-for="(mapping, i) in form.cpt_config.meta_mappings" :key="i" class="flex gap-2 items-start">
                      <Input v-model="mapping.meta_key" placeholder="_meta_key" class="flex-1 font-mono text-xs" />
                      <Input v-model="mapping.template" placeholder="{{received.body.field}}" class="flex-1 font-mono text-xs" />
                      <Button type="button" variant="ghost" size="icon" class="h-9 w-9 shrink-0 text-destructive" @click="removeMetaMapping(i)">×</Button>
                    </div>
                  </div>

                  <hr class="border-border" />

                  <!-- Auto-flatten -->
                  <div class="flex items-center gap-3">
                    <Switch :model-value="form.cpt_config.flatten_meta" @update:model-value="form.cpt_config.flatten_meta = $event" />
                    <Label class="cursor-pointer select-none" @click="form.cpt_config.flatten_meta = !form.cpt_config.flatten_meta">
                      Auto-flatten entire body to meta keys
                    </Label>
                  </div>

                  <div v-if="form.cpt_config.flatten_meta" class="space-y-1.5">
                    <Label>Meta Key Prefix</Label>
                    <Input v-model="form.cpt_config.flatten_meta_prefix" placeholder="fswa_" />
                    <p class="text-xs text-muted-foreground">e.g. prefix <code class="font-mono">fswa_</code> → <code class="font-mono">fswa_user_name</code> for <code class="font-mono">body.user.name</code></p>
                  </div>
                </template>
              </div>
            </template>

            <!-- ── FUNCTION / HOOKS ───────────────────────────────── -->
            <template #function>
              <div class="space-y-4">
                <div class="flex items-center gap-3">
                  <Switch :model-value="form.function_enabled" @update:model-value="form.function_enabled = $event" />
                  <Label class="cursor-pointer select-none" @click="form.function_enabled = !form.function_enabled">
                    {{ form.function_enabled ? 'Custom function enabled' : 'Custom function disabled' }}
                  </Label>
                </div>

                <template v-if="form.function_enabled">
                  <div class="p-3 text-xs rounded-md bg-yellow-50 dark:bg-yellow-950/30 border border-yellow-200 dark:border-yellow-800 text-yellow-800 dark:text-yellow-300 space-y-1">
                    <p><strong>Available variables:</strong> <code class="font-mono">$payload</code> (body array), <code class="font-mono">$query</code> (URL params), <code class="font-mono">$headers</code>, <code class="font-mono">$endpoint</code>, <code class="font-mono">$context</code></p>
                    <p>Return an array for a JSON response. Return <code class="font-mono">null</code> to use the default response body template.</p>
                    <p>Also available: <code class="font-mono">$context['received']['body']['field']</code> for nested access.</p>
                  </div>

                  <!-- Snippets selector -->
                  <div class="flex gap-2 items-center">
                    <Select v-model="selectedSnippet">
                      <SelectTrigger class="flex-1 text-sm">
                        <SelectValue placeholder="Insert a code snippet…" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem v-for="s in snippets" :key="s.id" :value="s.id">{{ s.name }}</SelectItem>
                      </SelectContent>
                    </Select>
                    <Button type="button" variant="outline" size="sm" :disabled="!selectedSnippet" @click="insertSnippet">
                      Insert
                    </Button>
                  </div>

                  <div class="space-y-1.5">
                    <Label>PHP Code</Label>
                    <div class="relative">
                      <div class="absolute top-2 right-2 text-xs text-muted-foreground bg-zinc-900 px-1.5 py-0.5 rounded select-none">PHP</div>
                      <textarea
                        v-model="form.function_code"
                        rows="20"
                        spellcheck="false"
                        autocomplete="off"
                        placeholder="// $payload, $query, $headers, $endpoint, $context available&#10;// Return a value to override the response&#10;&#10;return ['received' => true];"
                        class="w-full rounded-md border border-input bg-zinc-950 text-green-400 px-4 py-3 text-xs ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring resize-y font-mono leading-relaxed"
                      />
                    </div>
                  </div>
                </template>

                <hr class="border-border" />

                <!-- WP Hooks -->
                <div class="space-y-1.5">
                  <Label>WordPress Hooks to Fire</Label>
                  <Input v-model="form.hooks_to_fire" placeholder="my_hook, another_hook" />
                  <p class="text-xs text-muted-foreground">
                    Comma-separated hook names. Fires <code class="font-mono">do_action('fswa_endpoint_{name}', $context, $endpoint)</code> and <code class="font-mono">do_action('{name}', $context, $endpoint)</code> for each.
                  </p>
                </div>
              </div>
            </template>

          </Tabs>

          <!-- Save / Cancel -->
          <div class="flex gap-2 mt-6 pt-4 border-t border-border">
            <Button type="submit" :loading="saving">
              {{ isEdit ? 'Save Changes' : 'Create Endpoint' }}
            </Button>
            <Button type="button" variant="outline" @click="router.push('/endpoints')">Cancel</Button>
          </div>
        </Card>

        <!-- Info sidebar -->
        <div class="space-y-4">
          <!-- Receiver URL info -->
          <Card v-if="isEdit && receiverUrl" class="p-5">
            <h3 class="font-medium mb-3 text-sm">Receiver URL</h3>
            <div class="flex items-center gap-2 p-3 rounded-md bg-muted">
              <code class="text-xs font-mono flex-1 break-all">{{ receiverUrl }}</code>
              <button type="button" @click="copyUrl" class="shrink-0 text-muted-foreground hover:text-foreground">
                <Check v-if="copiedUrl" class="w-4 h-4 text-green-500" />
                <Copy v-else class="w-4 h-4" />
              </button>
            </div>
            <div class="mt-3 flex flex-wrap gap-1">
              <span
                v-for="m in form.allowed_methods"
                :key="m"
                class="px-2 py-0.5 bg-muted text-muted-foreground text-xs rounded font-mono"
              >{{ m }}</span>
            </div>
          </Card>

          <!-- Merge tag reference (accordion) -->
          <Card class="p-5">
            <h3 class="font-medium mb-3 text-sm">Merge Tag Reference</h3>
            <div class="text-xs space-y-2">

              <!-- Payload -->
              <div>
                <button type="button" class="flex items-center gap-1 w-full text-left font-medium text-muted-foreground hover:text-foreground" @click="openTagGroup = openTagGroup === 'body' ? '' : 'body'">
                  <component :is="openTagGroup === 'body' ? ChevronDown : ChevronRight" class="h-3 w-3 shrink-0" />
                  Payload body
                </button>
                <div v-if="openTagGroup === 'body'" class="mt-1.5 space-y-0.5 pl-4 font-mono text-muted-foreground">
                  <p>&#123;&#123;received.body.<em>field</em>&#125;&#125;</p>
                  <p>&#123;&#123;received.body.user.name&#125;&#125;</p>
                  <p>&#123;&#123;received.body.items.0&#125;&#125;</p>
                  <p>&#123;&#123;received.body.items[0]&#125;&#125;</p>
                  <p class="text-muted-foreground/60 text-[10px] not-italic pt-1">Short alias: &#123;&#123;payload.<em>field</em>&#125;&#125;</p>
                </div>
              </div>

              <!-- Query / Headers / Meta -->
              <div>
                <button type="button" class="flex items-center gap-1 w-full text-left font-medium text-muted-foreground hover:text-foreground" @click="openTagGroup = openTagGroup === 'meta' ? '' : 'meta'">
                  <component :is="openTagGroup === 'meta' ? ChevronDown : ChevronRight" class="h-3 w-3 shrink-0" />
                  Query, headers &amp; meta
                </button>
                <div v-if="openTagGroup === 'meta'" class="mt-1.5 space-y-0.5 pl-4 font-mono text-muted-foreground">
                  <p>&#123;&#123;received.query.<em>param</em>&#125;&#125;</p>
                  <p>&#123;&#123;received.headers.x-event-type&#125;&#125;</p>
                  <p>&#123;&#123;received.meta.method&#125;&#125;</p>
                  <p>&#123;&#123;received.meta.source_ip&#125;&#125;</p>
                  <p>&#123;&#123;received.meta.received_at&#125;&#125;</p>
                  <p>&#123;&#123;received.meta.endpoint_slug&#125;&#125;</p>
                  <p class="text-muted-foreground/60 text-[10px] not-italic pt-1">Aliases: &#123;&#123;query.*&#125;&#125; &#123;&#123;headers.*&#125;&#125;</p>
                </div>
              </div>

              <!-- System vars -->
              <div>
                <button type="button" class="flex items-center gap-1 w-full text-left font-medium text-muted-foreground hover:text-foreground" @click="openTagGroup = openTagGroup === 'sys' ? '' : 'sys'">
                  <component :is="openTagGroup === 'sys' ? ChevronDown : ChevronRight" class="h-3 w-3 shrink-0" />
                  System variables
                </button>
                <div v-if="openTagGroup === 'sys'" class="mt-1.5 space-y-0.5 pl-4 font-mono text-muted-foreground">
                  <p>&#123;&#123;timestamp&#125;&#125; — unix</p>
                  <p>&#123;&#123;datetime&#125;&#125; — Y-m-d H:i:s</p>
                  <p>&#123;&#123;date&#125;&#125; &#123;&#123;time&#125;&#125;</p>
                  <p>&#123;&#123;uuid&#125;&#125;</p>
                  <p>&#123;&#123;site_url&#125;&#125; &#123;&#123;home_url&#125;&#125;</p>
                  <p>&#123;&#123;admin_email&#125;&#125; &#123;&#123;blog_name&#125;&#125;</p>
                </div>
              </div>

              <!-- Modifiers -->
              <div>
                <button type="button" class="flex items-center gap-1 w-full text-left font-medium text-muted-foreground hover:text-foreground" @click="openTagGroup = openTagGroup === 'mod' ? '' : 'mod'">
                  <component :is="openTagGroup === 'mod' ? ChevronDown : ChevronRight" class="h-3 w-3 shrink-0" />
                  Modifiers
                </button>
                <div v-if="openTagGroup === 'mod'" class="mt-1.5 space-y-0.5 pl-4 font-mono text-muted-foreground">
                  <p class="text-[10px] not-italic mb-1">Syntax: &#123;&#123;path|modifier&#125;&#125;</p>
                  <p>|lower &nbsp;|upper &nbsp;|trim</p>
                  <p>|slug &nbsp;|urlencode</p>
                  <p>|base64 &nbsp;|md5 &nbsp;|sha256</p>
                  <p>|json &nbsp;|json_pretty</p>
                  <p>|int &nbsp;|float &nbsp;|round:2</p>
                  <p>|date:Y-m-d</p>
                  <p>|substr:0:10</p>
                  <p>|default:N/A</p>
                  <p>|first &nbsp;|last &nbsp;|count</p>
                  <p>|join:, &nbsp;|reverse</p>
                  <p class="text-[10px] not-italic pt-1 break-all">e.g. &#123;&#123;received.body.email|lower|trim&#125;&#125;</p>
                </div>
              </div>

            </div>
          </Card>

          <!-- Quick links (edit mode) -->
          <Card v-if="isEdit" class="p-5">
            <h3 class="font-medium mb-3 text-sm">Quick Links</h3>
            <div class="space-y-2">
              <Button variant="outline" size="sm" class="w-full justify-start" @click="router.push(`/endpoints/${route.params.id}/payloads`)">
                <Inbox class="mr-2 h-4 w-4" />View Payloads
              </Button>
              <Button variant="outline" size="sm" class="w-full justify-start" @click="router.push(`/endpoints/${route.params.id}/logs`)">
                <ScrollText class="mr-2 h-4 w-4" />View Request Logs
              </Button>
            </div>
          </Card>
        </div>
      </div>
    </form>
  </div>
</template>
