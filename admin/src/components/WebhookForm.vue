<script setup>
import { ref, computed, watch } from 'vue';
import { Button, Input, Label, Switch, Select, SelectTrigger, SelectValue, SelectContent, SelectItem, CodeEditor } from '@/components/ui';
import TriggerSelect from '@/components/TriggerSelect.vue';
import { Zap, Settings, Trash2, Plus } from 'lucide-vue-next';

const props = defineProps({
  webhook: {
    type: Object,
    default: null,
  },
  loading: Boolean,
});

const emit = defineEmits(['submit', 'cancel']);

const form = ref({
  name: '',
  endpoint_url: '',
  auth_header: '',
  is_enabled: true,
  triggers: [],
  actions_config: [],
});

const errors = ref({});

// Initialize form with webhook data
watch(
  () => props.webhook,
  (webhook) => {
    if (webhook) {
      form.value = {
        name:           webhook.name           || '',
        endpoint_url:   webhook.endpoint_url   || '',
        auth_header:    webhook.auth_header    || '',
        is_enabled:     webhook.is_enabled     ?? true,
        triggers:       webhook.triggers       || [],
        actions_config: Array.isArray(webhook.actions_config) ? webhook.actions_config : [],
      };
    }
  },
  { immediate: true },
);

const validate = () => {
  errors.value = {};

  if (!form.value.name.trim()) {
    errors.value.name = 'Name is required';
  }

  if (!form.value.endpoint_url.trim()) {
    errors.value.endpoint_url = 'Endpoint URL is required';
  } else {
    try {
      const url = new URL(form.value.endpoint_url);
      if (!['http:', 'https:'].includes(url.protocol)) {
        errors.value.endpoint_url = 'URL must be HTTP or HTTPS';
      }
    } catch {
      errors.value.endpoint_url = 'Invalid URL format';
    }
  }

  if (form.value.triggers.length === 0) {
    errors.value.triggers = 'At least one trigger is required';
  }

  return Object.keys(errors.value).length === 0;
};

const handleSubmit = () => {
  if (validate()) {
    emit('submit', { ...form.value });
  }
};

// ---------------------------------------------------------------------------
// Actions management — outgoing webhook triggers (dispatched_*)
// ---------------------------------------------------------------------------
const ACTION_TYPES = [
  { value: 'http_post',     label: 'HTTP POST (JSON)' },
  { value: 'http_request',  label: 'HTTP Request (any method)' },
  { value: 'send_email',    label: 'Send Email' },
  { value: 'fire_hook',     label: 'Fire WP Hook' },
  { value: 'update_option', label: 'Update Option' },
  { value: 'set_transient', label: 'Set Transient' },
]

const DISPATCH_TRIGGERS = [
  { value: 'dispatched_2xx',   label: '2xx — Delivery success' },
  { value: 'dispatched_4xx',   label: '4xx — Client error response' },
  { value: 'dispatched_5xx',   label: '5xx — Server error response' },
  { value: 'dispatched_error', label: 'Error — Connection / WP_Error' },
  { value: 'dispatched_any',   label: 'Any — After every delivery attempt' },
]

const editingAction = ref(null)
const actionDraft   = ref({})

const newActionDraft = () => ({
  id:          crypto.randomUUID ? crypto.randomUUID() : String(Date.now()),
  name:        '',
  enabled:     true,
  trigger:     'dispatched_2xx',
  condition:   '',
  action_type: 'http_post',
  config: {
    url: '', method: 'POST', body: '', headers: {}, headers_str: '', timeout: 30,
    to: '', subject: '', message: '',
    hook: '',
    option_name: '', option_value: '', autoload: false,
    transient_key: '', transient_value: '', expiry: 3600,
  },
})

const openNewAction = () => {
  actionDraft.value   = newActionDraft()
  editingAction.value = 'new'
}

const openEditAction = (action) => {
  const draft = JSON.parse(JSON.stringify({ ...newActionDraft(), ...action, config: { ...newActionDraft().config, ...(action.config || {}) } }))
  draft.config.headers_str = headersToStr(draft.config.headers)
  actionDraft.value   = draft
  editingAction.value = action.id
}

const saveAction = () => {
  const draft = { ...actionDraft.value }
  if (['http_post', 'http_request'].includes(draft.action_type)) {
    const obj   = {}
    const lines = (draft.config.headers_str || '').split('\n').map((l) => l.trim()).filter(Boolean)
    for (const line of lines) {
      const idx = line.indexOf(':')
      if (idx > 0) obj[line.slice(0, idx).trim()] = line.slice(idx + 1).trim()
    }
    draft.config.headers = obj
  }
  delete draft.config.headers_str

  if (editingAction.value === 'new') {
    form.value.actions_config.push(draft)
  } else {
    const idx = form.value.actions_config.findIndex((a) => a.id === draft.id)
    if (idx >= 0) form.value.actions_config[idx] = draft
    else form.value.actions_config.push(draft)
  }
  editingAction.value = null
}

const removeAction = (id) => { form.value.actions_config = form.value.actions_config.filter((a) => a.id !== id) }
const toggleAction = (id) => { const a = form.value.actions_config.find((x) => x.id === id); if (a) a.enabled = !a.enabled }
const headersToStr = (h) => (!h || typeof h !== 'object') ? '' : Object.entries(h).map(([k, v]) => `${k}: ${v}`).join('\n')
</script>

<template>
  <form class="space-y-6" @submit.prevent="handleSubmit">
    <!-- Name -->
    <div class="space-y-2">
      <Label for="name">Name</Label>
      <Input
        id="name"
        v-model="form.name"
        placeholder="My Webhook"
        :class="{ 'border-destructive': errors.name }"
      />
      <p v-if="errors.name" class="text-sm text-destructive">{{ errors.name }}</p>
    </div>

    <!-- Endpoint URL -->
    <div class="space-y-2">
      <Label for="endpoint_url">Endpoint URL</Label>
      <Input
        id="endpoint_url"
        v-model="form.endpoint_url"
        type="url"
        placeholder="https://example.com/webhook"
        :class="{ 'border-destructive': errors.endpoint_url }"
      />
      <p v-if="errors.endpoint_url" class="text-sm text-destructive">{{ errors.endpoint_url }}</p>
      <p class="text-sm text-muted-foreground">The URL where webhook payloads will be sent</p>
    </div>

    <!-- Auth Header -->
    <div class="space-y-2">
      <Label for="auth_header">Authorization Header (optional)</Label>
      <Input
        id="auth_header"
        v-model="form.auth_header"
        placeholder="Bearer your_token_goes_here"
      />
      <p class="text-sm text-muted-foreground break-all md:break-normal">
        Value for the Authorization header (e.g., "Bearer your_token_goes_here" or "Basic your_encoded_base64(username:password)")
      </p>
    </div>

    <!-- Triggers -->
    <div class="space-y-2">
      <Label>Triggers</Label>
      <TriggerSelect v-model="form.triggers" />
      <p v-if="errors.triggers" class="text-sm text-destructive">{{ errors.triggers }}</p>
      <p class="text-sm text-muted-foreground">WordPress actions that will trigger this webhook</p>
    </div>

    <!-- Enabled -->
    <div class="flex items-center space-x-2">
      <Switch v-model="form.is_enabled" />
      <Label>Enabled</Label>
    </div>

    <hr class="border-border" />

    <!-- ── ACTIONS ─────────────────────────────────────────────── -->
    <div class="space-y-4">
      <div class="flex items-center gap-2">
        <Zap class="h-4 w-4 text-muted-foreground" />
        <h3 class="font-medium text-sm">Actions on Delivery</h3>
      </div>

      <div class="p-3 rounded-md bg-muted text-xs text-muted-foreground space-y-1">
        <p>Actions run after each delivery attempt. Choose a trigger based on the response code: <code class="font-mono">dispatched_2xx</code> / <code class="font-mono">dispatched_4xx</code> / <code class="font-mono">dispatched_5xx</code> / <code class="font-mono">dispatched_error</code> / <code class="font-mono">dispatched_any</code>.</p>
        <p>Template tags: <code class="font-mono">&#123;&#123;dispatched.http_code&#125;&#125;</code> · <code class="font-mono">&#123;&#123;dispatched.response_body&#125;&#125;</code> · <code class="font-mono">&#123;&#123;dispatched.url&#125;&#125;</code></p>
      </div>

      <!-- Action list -->
      <div class="space-y-2">
        <div v-if="!form.actions_config.length" class="text-center py-6 text-muted-foreground text-sm border border-dashed rounded-md">
          No actions configured.
        </div>
        <div
          v-for="action in form.actions_config"
          :key="action.id"
          class="flex items-center gap-3 p-3 rounded-md border border-border hover:bg-muted/30 transition-colors"
        >
          <Switch :model-value="action.enabled" @update:model-value="toggleAction(action.id)" />
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium truncate">{{ action.name || 'Unnamed action' }}</p>
            <p class="text-xs text-muted-foreground">
              {{ DISPATCH_TRIGGERS.find(t => t.value === action.trigger)?.label || action.trigger }}
              · {{ ACTION_TYPES.find(t => t.value === action.action_type)?.label || action.action_type }}
            </p>
          </div>
          <div class="flex gap-1 shrink-0">
            <Button type="button" variant="ghost" size="icon" class="h-8 w-8" @click="openEditAction(action)">
              <Settings class="h-3.5 w-3.5" />
            </Button>
            <Button type="button" variant="ghost" size="icon" class="h-8 w-8 text-destructive" @click="removeAction(action.id)">
              <Trash2 class="h-3.5 w-3.5" />
            </Button>
          </div>
        </div>
      </div>

      <Button type="button" variant="outline" size="sm" @click="openNewAction">
        <Plus class="mr-1.5 h-3.5 w-3.5" />Add Action
      </Button>

      <!-- Inline action editor -->
      <div v-if="editingAction !== null" class="border border-border rounded-lg p-4 space-y-4 bg-muted/20">
        <h4 class="font-medium text-sm">{{ editingAction === 'new' ? 'New Action' : 'Edit Action' }}</h4>

        <div class="grid grid-cols-2 gap-3">
          <div class="space-y-1.5">
            <Label>Name</Label>
            <Input v-model="actionDraft.name" placeholder="Notify on success" />
          </div>
          <div class="space-y-1.5">
            <Label>Fire When</Label>
            <Select v-model="actionDraft.trigger">
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="t in DISPATCH_TRIGGERS" :key="t.value" :value="t.value">{{ t.label }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>

        <div class="space-y-1.5">
          <Label>Action Type</Label>
          <Select v-model="actionDraft.action_type">
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="t in ACTION_TYPES" :key="t.value" :value="t.value">{{ t.label }}</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="space-y-1.5">
          <Label>Condition <span class="text-muted-foreground text-xs font-normal">(optional)</span></Label>
          <CodeEditor
            v-model="actionDraft.condition"
            language="template"
            :min-height="40"
            :max-height="80"
            placeholder="{{dispatched.http_code}} == 200"
          />
        </div>

        <!-- http_post config -->
        <template v-if="actionDraft.action_type === 'http_post'">
          <div class="space-y-1.5">
            <Label>URL <span class="text-destructive">*</span></Label>
            <CodeEditor v-model="actionDraft.config.url" language="template" :min-height="40" :max-height="60" placeholder="https://hooks.slack.com/services/..." />
          </div>
          <div class="space-y-1.5">
            <Label>JSON Body <span class="text-muted-foreground text-xs font-normal">(blank = dispatched context)</span></Label>
            <CodeEditor v-model="actionDraft.config.body" language="json" :min-height="80" :max-height="200" placeholder='{"status":"{{dispatched.http_code}}","url":"{{dispatched.url}}"}' />
          </div>
          <div class="space-y-1.5">
            <Label>Extra Headers <span class="text-muted-foreground text-xs font-normal">(one per line)</span></Label>
            <CodeEditor v-model="actionDraft.config.headers_str" language="text" :min-height="60" :max-height="100" placeholder="Authorization: Bearer mytoken" />
          </div>
          <div class="space-y-1.5">
            <Label>Timeout (seconds)</Label>
            <Input v-model.number="actionDraft.config.timeout" type="number" min="5" max="120" class="w-28" />
          </div>
        </template>

        <!-- http_request config -->
        <template v-if="actionDraft.action_type === 'http_request'">
          <div class="grid grid-cols-4 gap-3">
            <div class="space-y-1.5">
              <Label>Method</Label>
              <Select v-model="actionDraft.config.method">
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="GET">GET</SelectItem>
                  <SelectItem value="POST">POST</SelectItem>
                  <SelectItem value="PUT">PUT</SelectItem>
                  <SelectItem value="PATCH">PATCH</SelectItem>
                  <SelectItem value="DELETE">DELETE</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div class="col-span-3 space-y-1.5">
              <Label>URL <span class="text-destructive">*</span></Label>
              <CodeEditor v-model="actionDraft.config.url" language="template" :min-height="40" :max-height="60" placeholder="https://api.example.com/status" />
            </div>
          </div>
          <div class="space-y-1.5">
            <Label>Body</Label>
            <CodeEditor v-model="actionDraft.config.body" language="template" :min-height="60" :max-height="160" placeholder="{{dispatched.response_body}}" />
          </div>
          <div class="space-y-1.5">
            <Label>Extra Headers</Label>
            <CodeEditor v-model="actionDraft.config.headers_str" language="text" :min-height="60" :max-height="100" placeholder="Content-Type: application/json" />
          </div>
        </template>

        <!-- send_email config -->
        <template v-if="actionDraft.action_type === 'send_email'">
          <div class="space-y-1.5">
            <Label>To</Label>
            <CodeEditor v-model="actionDraft.config.to" language="template" :min-height="40" :max-height="60" placeholder="admin@example.com" />
          </div>
          <div class="space-y-1.5">
            <Label>Subject</Label>
            <CodeEditor v-model="actionDraft.config.subject" language="template" :min-height="40" :max-height="60" placeholder="Webhook delivery failed ({{dispatched.http_code}})" />
          </div>
          <div class="space-y-1.5">
            <Label>Message</Label>
            <CodeEditor v-model="actionDraft.config.message" language="template" :min-height="80" :max-height="200" placeholder="Delivery to {{dispatched.url}} returned HTTP {{dispatched.http_code}}" />
          </div>
        </template>

        <!-- fire_hook config -->
        <template v-if="actionDraft.action_type === 'fire_hook'">
          <div class="space-y-1.5">
            <Label>Hook Name</Label>
            <CodeEditor v-model="actionDraft.config.hook" language="template" :min-height="40" :max-height="60" placeholder="my_webhook_delivered" />
            <p class="text-xs text-muted-foreground">Fires <code class="font-mono">do_action(hook, $context)</code>.</p>
          </div>
        </template>

        <!-- update_option config -->
        <template v-if="actionDraft.action_type === 'update_option'">
          <div class="grid grid-cols-2 gap-3">
            <div class="space-y-1.5">
              <Label>Option Name</Label>
              <CodeEditor v-model="actionDraft.config.option_name" language="template" :min-height="40" :max-height="60" placeholder="last_delivery_code" />
            </div>
            <div class="space-y-1.5">
              <Label>Option Value</Label>
              <CodeEditor v-model="actionDraft.config.option_value" language="template" :min-height="40" :max-height="60" placeholder="{{dispatched.http_code}}" />
            </div>
          </div>
          <div class="flex items-center gap-2">
            <Switch :model-value="actionDraft.config.autoload" @update:model-value="actionDraft.config.autoload = $event" />
            <Label class="cursor-pointer text-sm" @click="actionDraft.config.autoload = !actionDraft.config.autoload">Autoload</Label>
          </div>
        </template>

        <!-- set_transient config -->
        <template v-if="actionDraft.action_type === 'set_transient'">
          <div class="grid grid-cols-2 gap-3">
            <div class="space-y-1.5">
              <Label>Transient Key</Label>
              <CodeEditor v-model="actionDraft.config.transient_key" language="template" :min-height="40" :max-height="60" placeholder="delivery_status_{{dispatched.http_code}}" />
            </div>
            <div class="space-y-1.5">
              <Label>Value</Label>
              <CodeEditor v-model="actionDraft.config.transient_value" language="template" :min-height="40" :max-height="60" placeholder="{{dispatched.response_body}}" />
            </div>
          </div>
          <div class="space-y-1.5">
            <Label>Expiry (seconds)</Label>
            <Input v-model.number="actionDraft.config.expiry" type="number" min="0" class="w-36" />
          </div>
        </template>

        <div class="flex gap-2 pt-2 border-t border-border">
          <Button type="button" size="sm" @click="saveAction">Save Action</Button>
          <Button type="button" size="sm" variant="outline" @click="editingAction = null">Cancel</Button>
        </div>
      </div>
    </div>

    <hr class="border-border" />

    <!-- Form actions -->
    <div class="flex gap-2">
      <Button type="submit" :loading="loading">
        {{ webhook ? 'Save Changes' : 'Create Webhook' }}
      </Button>
      <Button type="button" variant="outline" @click="$emit('cancel')">
        Cancel
      </Button>
    </div>
  </form>
</template>
