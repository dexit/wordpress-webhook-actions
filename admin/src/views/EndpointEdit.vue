<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { ArrowLeft, Copy, Check, Inbox, ScrollText, Settings, ShieldCheck, Database, Code2, ChevronDown, ChevronRight, GitMerge, Zap, FlaskConical, Trash2, Plus } from 'lucide-vue-next'
import {
  Button, Card, Alert, Input, Label, Switch, Tabs,
  Select, SelectTrigger, SelectValue, SelectContent, SelectItem,
  CodeEditor,
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

  // DTO/ETL pipeline
  dto_pipeline_id: null,

  // Actions
  actions_config: [],
})

const receiverUrl  = ref('')
const slugDirty    = ref(false)
const errors       = ref({})
const dtoPipelines = ref([])

// Dynamic post statuses / types loaded from WP
const postStatuses = ref([])
const postTypes    = ref([])

// Test payload modal
const testModalOpen    = ref(false)
const testPayloadJson  = ref('')
const testPayloadError = ref('')
const knownFields      = ref([])

// Actions editing state
const editingAction = ref(null) // null = closed, {} = new action, {id,...} = edit action
const actionDraft   = ref({})

// Radix Select wrapper: null → 'none', number → string
const dtoPipelineSelect = computed({
  get: () => form.value.dto_pipeline_id ? String(form.value.dto_pipeline_id) : 'none',
  set: (v) => { form.value.dto_pipeline_id = (v && v !== 'none') ? Number(v) : null },
})

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
// Test payload modal — flatten JSON → auto-populate meta mappings
// ---------------------------------------------------------------------------
const flattenObj = (obj, prefix = '') => {
  const result = []
  for (const [k, v] of Object.entries(obj)) {
    const path = prefix ? `${prefix}.${k}` : k
    if (v !== null && typeof v === 'object' && !Array.isArray(v)) {
      result.push(...flattenObj(v, path))
    } else {
      result.push(path)
    }
  }
  return result
}

const applyTestPayload = () => {
  testPayloadError.value = ''
  let parsed
  try {
    parsed = JSON.parse(testPayloadJson.value)
  } catch {
    testPayloadError.value = 'Invalid JSON — please check your payload.'
    return
  }
  if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
    testPayloadError.value = 'Payload must be a JSON object.'
    return
  }
  const fields = flattenObj(parsed)
  knownFields.value = fields

  // Auto-populate meta mappings for unmapped keys
  if (form.value.cpt_enabled) {
    const existingKeys = new Set(form.value.cpt_config.meta_mappings.map((m) => m.meta_key))
    for (const f of fields) {
      const metaKey = f.replace(/\./g, '_')
      if (!existingKeys.has(metaKey)) {
        form.value.cpt_config.meta_mappings.push({
          meta_key: metaKey,
          template: `{{received.body.${f}}}`,
        })
        existingKeys.add(metaKey)
      }
    }
  }

  testModalOpen.value = false
  testPayloadJson.value = ''
}

// ---------------------------------------------------------------------------
// Actions management
// ---------------------------------------------------------------------------
const ACTION_TYPES = [
  { value: 'http_post',     label: 'HTTP POST (JSON)' },
  { value: 'http_request',  label: 'HTTP Request (any method)' },
  { value: 'send_email',    label: 'Send Email' },
  { value: 'fire_hook',     label: 'Fire WP Hook' },
  { value: 'update_option', label: 'Update Option' },
  { value: 'set_transient', label: 'Set Transient' },
]

const newActionDraft = () => ({
  id:          crypto.randomUUID ? crypto.randomUUID() : String(Date.now()),
  name:        '',
  enabled:     true,
  trigger:     'received',
  condition:   '',
  action_type: 'http_post',
  config: {
    url: '', method: 'POST', body: '', headers: {}, timeout: 30,
    to: '', subject: '', message: '', headers_str: '',
    hook: '',
    option_name: '', option_value: '', autoload: false,
    transient_key: '', transient_value: '', expiry: 3600,
  },
})

const openNewAction = () => {
  actionDraft.value = newActionDraft()
  editingAction.value = 'new'
}

const openEditAction = (action) => {
  actionDraft.value = JSON.parse(JSON.stringify({
    ...newActionDraft(),
    ...action,
    config: { ...newActionDraft().config, ...(action.config || {}) },
  }))
  editingAction.value = action.id
}

const saveAction = () => {
  const draft = { ...actionDraft.value }
  // Trim headers_str → headers object for http_post / http_request
  if (['http_post', 'http_request'].includes(draft.action_type)) {
    const headersObj = {}
    const lines = (draft.config.headers_str || '').split('\n').map((l) => l.trim()).filter(Boolean)
    for (const line of lines) {
      const idx = line.indexOf(':')
      if (idx > 0) {
        headersObj[line.slice(0, idx).trim()] = line.slice(idx + 1).trim()
      }
    }
    draft.config.headers = headersObj
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

const removeAction = (id) => {
  form.value.actions_config = form.value.actions_config.filter((a) => a.id !== id)
}

const toggleAction = (id) => {
  const a = form.value.actions_config.find((x) => x.id === id)
  if (a) a.enabled = !a.enabled
}

// Helper: stringified headers for editing
const headersToStr = (headers) => {
  if (!headers || typeof headers !== 'object') return ''
  return Object.entries(headers).map(([k, v]) => `${k}: ${v}`).join('\n')
}

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
      dto_pipeline_id: ep.dto_pipeline_id  || null,
      actions_config:  Array.isArray(ep.actions_config) ? ep.actions_config : [],
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
  { key: 'actions',  label: 'Actions',    icon: Zap },
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
  {
    id: 'use_dto',
    name: 'Use DTO/ETL pipeline fields',
    code: `// $dto contains the resolved output of the attached DTO pipeline.
// Configure a pipeline in Pipelines → attach it to this endpoint.
$email  = $dto['user_email'] ?? '';
$amount = $dto['order_total'] ?? 0;
$status = $dto['status']      ?? 'pending';

if (empty($email)) {
    return ['success' => false, 'error' => 'user_email missing from DTO'];
}

// Use the clean, type-cast values in your logic
update_user_meta(
    get_user_by('email', $email)?->ID ?? 0,
    '_last_order_total',
    $amount
);

return ['success' => true, 'dto' => $dto];`,
  },
  {
    id: 'woo_order_sync',
    name: 'WooCommerce — update order status',
    code: `// Requires WooCommerce active
$order_id = (int) ($payload['order_id'] ?? 0);
$status   = sanitize_text_field($payload['status'] ?? '');

$allowed_statuses = ['pending','processing','on-hold','completed','cancelled','refunded','failed'];
if (!$order_id || !in_array($status, $allowed_statuses, true)) {
    return ['success' => false, 'error' => 'Invalid order_id or status'];
}

$order = wc_get_order($order_id);
if (!$order) {
    return ['success' => false, 'error' => 'Order not found'];
}

$order->update_status($status, '[FSWA] Status updated via webhook');

return [
    'success'   => true,
    'order_id'  => $order_id,
    'status'    => $order->get_status(),
];`,
  },
  {
    id: 'woo_product_update',
    name: 'WooCommerce — update product stock',
    code: `// Update product stock by SKU
$sku      = sanitize_text_field($payload['sku'] ?? '');
$quantity = (int) ($payload['quantity'] ?? 0);

if (empty($sku)) {
    return ['success' => false, 'error' => 'SKU required'];
}

$product_id = wc_get_product_id_by_sku($sku);
if (!$product_id) {
    return ['success' => false, 'error' => 'Product not found for SKU: ' . $sku];
}

$product = wc_get_product($product_id);
$product->set_stock_quantity($quantity);
$product->set_stock_status($quantity > 0 ? 'instock' : 'outofstock');
$product->save();

return [
    'success'    => true,
    'product_id' => $product_id,
    'sku'        => $sku,
    'quantity'   => $quantity,
];`,
  },
  {
    id: 'acf_update',
    name: 'ACF — update custom fields',
    code: `// Update ACF fields on a post or user
// Requires Advanced Custom Fields (free or Pro)
$post_id = (int) ($payload['post_id'] ?? 0);
$fields  = $payload['fields'] ?? [];

if (!$post_id || empty($fields) || !function_exists('update_field')) {
    return ['success' => false, 'error' => 'post_id + fields required, ACF must be active'];
}

$updated = [];
foreach ($fields as $key => $value) {
    $k = sanitize_key($key);
    update_field($k, $value, $post_id);
    $updated[] = $k;
}

return ['success' => true, 'post_id' => $post_id, 'updated_fields' => $updated];`,
  },
  {
    id: 'slack_notification',
    name: 'Send Slack notification',
    code: `// Send a message to a Slack channel via Incoming Webhook
$slack_url = 'https://hooks.slack.com/services/YOUR/SLACK/WEBHOOK';
$text      = $payload['message'] ?? ('New webhook received: ' . json_encode($payload));
$channel   = $payload['channel'] ?? '#general';

$response = wp_remote_post($slack_url, [
    'headers' => ['Content-Type' => 'application/json'],
    'body'    => wp_json_encode([
        'text'    => $text,
        'channel' => $channel,
    ]),
    'timeout' => 15,
]);

if (is_wp_error($response)) {
    return ['success' => false, 'error' => $response->get_error_message()];
}

return ['success' => true, 'slack_status' => wp_remote_retrieve_response_code($response)];`,
  },
  {
    id: 'taxonomy_sync',
    name: 'Sync taxonomy terms',
    code: `// Create / set taxonomy terms on a post from incoming data
$post_id  = (int) ($payload['post_id'] ?? 0);
$taxonomy = sanitize_key($payload['taxonomy'] ?? 'category');
$terms    = (array) ($payload['terms'] ?? []);  // array of term names or slugs

if (!$post_id || empty($terms)) {
    return ['success' => false, 'error' => 'post_id and terms required'];
}

// Ensure terms exist, create if missing
$term_ids = [];
foreach ($terms as $term_name) {
    $term = term_exists($term_name, $taxonomy);
    if (!$term) {
        $term = wp_insert_term($term_name, $taxonomy);
    }
    if (!is_wp_error($term)) {
        $term_ids[] = (int) ($term['term_id'] ?? $term);
    }
}

wp_set_object_terms($post_id, $term_ids, $taxonomy);

return ['success' => true, 'post_id' => $post_id, 'taxonomy' => $taxonomy, 'term_ids' => $term_ids];`,
  },
  {
    id: 'transient_cache',
    name: 'Store / retrieve transient cache',
    code: `$action = $payload['action'] ?? 'set';  // 'set' | 'get' | 'delete'
$key    = sanitize_key($payload['key'] ?? 'fswa_cache');
$value  = $payload['value'] ?? null;
$expiry = (int) ($payload['expiry'] ?? HOUR_IN_SECONDS);

switch ($action) {
    case 'set':
        set_transient($key, $value, $expiry);
        return ['success' => true, 'action' => 'set', 'key' => $key];

    case 'get':
        $stored = get_transient($key);
        return ['success' => true, 'action' => 'get', 'key' => $key, 'value' => $stored];

    case 'delete':
        delete_transient($key);
        return ['success' => true, 'action' => 'delete', 'key' => $key];

    default:
        return ['success' => false, 'error' => 'Unknown action'];
}`,
  },
  {
    id: 'role_assignment',
    name: 'Role-based user assignment',
    code: `$email = sanitize_email($payload['email'] ?? '');
$role  = sanitize_key($payload['role'] ?? 'subscriber');

// Validate role
$wp_roles = wp_roles();
if (!isset($wp_roles->roles[$role])) {
    return ['success' => false, 'error' => 'Invalid role: ' . $role];
}

$user = get_user_by('email', $email);
if (!$user) {
    return ['success' => false, 'error' => 'User not found: ' . $email];
}

$user->set_role($role);

return [
    'success' => true,
    'user_id' => $user->ID,
    'email'   => $email,
    'role'    => $role,
];`,
  },
  {
    id: 'schedule_cron',
    name: 'Schedule a WP-Cron event',
    code: `$hook      = sanitize_key($payload['hook'] ?? 'my_scheduled_task');
$timestamp = isset($payload['run_at']) ? strtotime($payload['run_at']) : (time() + 60);
$args      = $payload['args'] ?? [];

if (!$timestamp) {
    return ['success' => false, 'error' => 'Invalid run_at date'];
}

// Avoid duplicate scheduling
if (!wp_next_scheduled($hook, $args)) {
    wp_schedule_single_event($timestamp, $hook, $args);
    return ['success' => true, 'hook' => $hook, 'scheduled_at' => wp_date('Y-m-d H:i:s', $timestamp)];
}

return ['success' => true, 'hook' => $hook, 'already_scheduled' => true];`,
  },
  {
    id: 'external_api_retry',
    name: 'External API call with retry',
    code: `$api_url  = 'https://api.example.com/sync';
$max_attempts = 3;
$last_error   = null;

for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
    $response = wp_remote_post($api_url, [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . get_option('my_api_token'),
        ],
        'body'    => wp_json_encode($payload),
        'timeout' => 20,
    ]);

    if (!is_wp_error($response)) {
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return [
                'success'  => true,
                'attempts' => $attempt,
                'code'     => $code,
                'body'     => json_decode(wp_remote_retrieve_body($response), true),
            ];
        }
        $last_error = 'HTTP ' . $code;
    } else {
        $last_error = $response->get_error_message();
    }

    if ($attempt < $max_attempts) {
        sleep(2 * $attempt); // exponential back-off
    }
}

return ['success' => false, 'attempts' => $max_attempts, 'error' => $last_error];`,
  },
  {
    id: 'conditional_branch',
    name: 'Conditional multi-step branching',
    code: `$event_type = sanitize_text_field($payload['event'] ?? '');
$data       = $payload['data'] ?? [];

switch ($event_type) {
    case 'user.created':
        // Send welcome email
        wp_mail($data['email'] ?? '', 'Welcome!', 'Your account is ready.');
        return ['handled' => 'user.created', 'email' => $data['email'] ?? ''];

    case 'order.paid':
        // Update order meta + notify
        $order_id = (int) ($data['order_id'] ?? 0);
        if ($order_id && function_exists('wc_get_order')) {
            wc_get_order($order_id)?->update_status('processing');
        }
        return ['handled' => 'order.paid', 'order_id' => $order_id];

    case 'subscription.cancelled':
        $user = get_user_by('email', $data['email'] ?? '');
        if ($user) {
            update_user_meta($user->ID, '_subscription_status', 'cancelled');
        }
        return ['handled' => 'subscription.cancelled'];

    default:
        // Log unknown events to transient
        set_transient('fswa_unknown_event_' . time(), $payload, DAY_IN_SECONDS);
        return ['handled' => 'unknown', 'event' => $event_type];
}`,
  },
  {
    id: 'custom_db_query',
    name: 'Custom database query',
    code: `global $wpdb;

// Example: log incoming events to a custom table
// (creates it if it does not exist)
$table = $wpdb->prefix . 'my_events';

// Auto-create table on first run
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(100),
    payload LONGTEXT,
    received_at DATETIME DEFAULT CURRENT_TIMESTAMP
) " . $wpdb->get_charset_collate());

// Insert event
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$inserted = $wpdb->insert($table, [
    'event_type' => sanitize_text_field($payload['event'] ?? 'unknown'),
    'payload'    => wp_json_encode($payload),
], ['%s', '%s']);

return [
    'success'  => $inserted !== false,
    'row_id'   => $wpdb->insert_id,
];`,
  },
  {
    id: 'jwt_validate',
    name: 'Validate & decode JWT (manual)',
    code: `// Simple HS256 JWT validation without a library.
// For production, install firebase/php-jwt via Composer.
$token  = $headers['authorization'] ?? '';
$token  = str_ireplace('Bearer ', '', $token);
$secret = get_option('my_jwt_secret', 'change_me');

if (empty($token)) {
    return ['success' => false, 'error' => 'No token provided'];
}

$parts = explode('.', $token);
if (count($parts) !== 3) {
    return ['success' => false, 'error' => 'Malformed JWT'];
}

[$header_b64, $payload_b64, $sig_b64] = $parts;

$expected_sig = rtrim(strtr(base64_encode(hash_hmac(
    'sha256',
    $header_b64 . '.' . $payload_b64,
    $secret,
    true
)), '+/', '-_'), '=');

if (!hash_equals($expected_sig, $sig_b64)) {
    return ['success' => false, 'error' => 'Invalid signature'];
}

$claims = json_decode(base64_decode(str_pad(strtr($payload_b64, '-_', '+/'), strlen($payload_b64) % 4, '=', STR_PAD_RIGHT)), true);

if (isset($claims['exp']) && $claims['exp'] < time()) {
    return ['success' => false, 'error' => 'Token expired'];
}

return ['success' => true, 'claims' => $claims];`,
  },
  {
    id: 'fire_outgoing_webhook',
    name: 'Fire outgoing webhook trigger',
    code: `// Trigger an outgoing webhook that listens to a WP action.
// Configure an outgoing webhook with trigger = 'my_incoming_event'.
$event_data = [
    'source'    => $endpoint['slug'] ?? 'endpoint',
    'event'     => $payload['event'] ?? 'received',
    'data'      => $payload,
    'timestamp' => time(),
];

// Fire the action — any outgoing webhook watching this hook will send.
do_action('my_incoming_event', $event_data);

return ['success' => true, 'fired' => 'my_incoming_event'];`,
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

const loadDtoPipelines = async () => {
  try {
    dtoPipelines.value = await api.dto.list()
  } catch { /* non-critical */ }
}

const loadPostMeta = async () => {
  try {
    const [statuses, types] = await Promise.all([
      api.settings.postStatuses(),
      api.settings.postTypes(),
    ])
    postStatuses.value = statuses
    postTypes.value    = types
  } catch { /* non-critical */ }
}

onMounted(() => { loadEndpoint(); loadDtoPipelines(); loadPostMeta() })
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
                  <CodeEditor
                    v-model="form.response_body"
                    language="json"
                    :min-height="80"
                    :max-height="200"
                    :known-fields="knownFields"
                    placeholder='{"received":true,"message":"{{received.body.id}}"}'
                  />
                  <p class="text-xs text-muted-foreground">Supports merge tags: <code class="font-mono">&#123;&#123;received.body.field&#125;&#125;</code></p>
                </div>

                <hr class="border-border" />

                <!-- DTO/ETL Pipeline -->
                <div class="space-y-1.5">
                  <Label class="flex items-center gap-1.5">
                    <GitMerge class="h-3.5 w-3.5 text-muted-foreground" />
                    DTO / ETL Pipeline
                  </Label>
                  <Select v-model="dtoPipelineSelect">
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">— None (no transformation) —</SelectItem>
                      <SelectItem
                        v-for="p in dtoPipelines"
                        :key="p.id"
                        :value="String(p.id)"
                      >{{ p.name }} <span class="text-muted-foreground text-xs">({{ p.pipeline_config?.length ?? 0 }} fields)</span></SelectItem>
                    </SelectContent>
                  </Select>
                  <p class="text-xs text-muted-foreground">
                    Run a pipeline before CPT mapping &amp; custom function.
                    Access results via <code class="font-mono">$dto</code> in PHP or <code class="font-mono">&#123;&#123;dto.field&#125;&#125;</code> in templates.
                    <RouterLink to="/dto/new" class="underline hover:text-foreground ml-1">Create pipeline →</RouterLink>
                  </p>
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
                      <Select v-if="postTypes.length" v-model="form.cpt_config.post_type">
                        <SelectTrigger><SelectValue /></SelectTrigger>
                        <SelectContent>
                          <SelectItem v-for="t in postTypes" :key="t.value" :value="t.value">
                            {{ t.label }} <span class="text-muted-foreground text-xs">({{ t.value }})</span>
                          </SelectItem>
                        </SelectContent>
                      </Select>
                      <Input v-else v-model="form.cpt_config.post_type" placeholder="post" />
                    </div>
                    <div class="space-y-1.5">
                      <Label>Post Status</Label>
                      <Select v-if="postStatuses.length" v-model="form.cpt_config.post_status">
                        <SelectTrigger><SelectValue /></SelectTrigger>
                        <SelectContent>
                          <SelectItem v-for="s in postStatuses" :key="s.value" :value="s.value">{{ s.label }}</SelectItem>
                        </SelectContent>
                      </Select>
                      <Select v-else v-model="form.cpt_config.post_status">
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
                    <CodeEditor
                      v-model="form.cpt_config.content_template"
                      language="template"
                      :min-height="80"
                      :max-height="200"
                      :known-fields="knownFields"
                      placeholder="{{received.body.description}}"
                    />
                  </div>

                  <hr class="border-border" />

                  <!-- Meta mappings -->
                  <div class="space-y-2">
                    <div class="flex items-center justify-between">
                      <Label>Meta Mappings</Label>
                      <div class="flex gap-2">
                        <Button type="button" variant="outline" size="sm" @click="testModalOpen = true; testPayloadJson = ''; testPayloadError = ''">
                          <FlaskConical class="mr-1.5 h-3.5 w-3.5" />Test Payload
                        </Button>
                        <Button type="button" variant="outline" size="sm" @click="addMetaMapping">+ Add</Button>
                      </div>
                    </div>
                    <p class="text-xs text-muted-foreground">Use <code class="font-mono">&#123;&#123;_flatten&#125;&#125;</code> as template to store the entire flattened body as JSON. Paste a test payload to auto-populate mappings.</p>

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

            <!-- ── ACTIONS ────────────────────────────────────────── -->
            <template #actions>
              <div class="space-y-4">
                <div class="p-3 rounded-md bg-muted text-xs text-muted-foreground space-y-1">
                  <p><strong>Actions</strong> run automatically after each successful receive. Use them to forward payloads, send emails, update options, fire hooks, and more — without custom code.</p>
                  <p>Supports merge tags in all config fields: <code class="font-mono">&#123;&#123;received.body.field&#125;&#125;</code></p>
                </div>

                <!-- Action list -->
                <div class="space-y-2">
                  <div v-if="!form.actions_config.length" class="text-center py-8 text-muted-foreground text-sm border border-dashed rounded-md">
                    No actions configured. Add one below.
                  </div>
                  <div
                    v-for="action in form.actions_config"
                    :key="action.id"
                    class="flex items-center gap-3 p-3 rounded-md border border-border hover:bg-muted/30 transition-colors"
                  >
                    <Switch
                      :model-value="action.enabled"
                      @update:model-value="toggleAction(action.id)"
                    />
                    <div class="flex-1 min-w-0">
                      <p class="text-sm font-medium truncate">{{ action.name || 'Unnamed action' }}</p>
                      <p class="text-xs text-muted-foreground">
                        {{ ACTION_TYPES.find(t => t.value === action.action_type)?.label || action.action_type }}
                        <span v-if="action.condition" class="ml-2 opacity-60">· condition set</span>
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
                      <Input v-model="actionDraft.name" placeholder="Forward to Slack" />
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
                  </div>

                  <div class="space-y-1.5">
                    <Label>Condition <span class="text-muted-foreground text-xs font-normal">(optional — leave blank to always run)</span></Label>
                    <CodeEditor
                      v-model="actionDraft.condition"
                      language="template"
                      :min-height="40"
                      :max-height="80"
                      :known-fields="knownFields"
                      placeholder="{{received.body.event}} == order.paid"
                    />
                    <p class="text-xs text-muted-foreground">Truthy values: any non-empty string except false/0/no/off.</p>
                  </div>

                  <!-- http_post config -->
                  <template v-if="actionDraft.action_type === 'http_post'">
                    <div class="space-y-1.5">
                      <Label>URL <span class="text-destructive">*</span></Label>
                      <CodeEditor v-model="actionDraft.config.url" language="template" :min-height="40" :max-height="60" :known-fields="knownFields" placeholder="https://hooks.slack.com/services/..." />
                    </div>
                    <div class="space-y-1.5">
                      <Label>JSON Body <span class="text-muted-foreground text-xs font-normal">(blank = full received context)</span></Label>
                      <CodeEditor v-model="actionDraft.config.body" language="json" :min-height="80" :max-height="200" :known-fields="knownFields" placeholder='{"text":"{{received.body.message}}"}' />
                    </div>
                    <div class="space-y-1.5">
                      <Label>Extra Headers <span class="text-muted-foreground text-xs font-normal">(one per line: Header: value)</span></Label>
                      <CodeEditor v-model="actionDraft.config.headers_str" language="text" :min-height="60" :max-height="100" :known-fields="knownFields" placeholder="Authorization: Bearer {{received.body.token}}" />
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
                        <CodeEditor v-model="actionDraft.config.url" language="template" :min-height="40" :max-height="60" :known-fields="knownFields" placeholder="https://api.example.com/endpoint" />
                      </div>
                    </div>
                    <div class="space-y-1.5">
                      <Label>Body</Label>
                      <CodeEditor v-model="actionDraft.config.body" language="template" :min-height="80" :max-height="160" :known-fields="knownFields" placeholder="{{received.body|json}}" />
                    </div>
                    <div class="space-y-1.5">
                      <Label>Extra Headers <span class="text-muted-foreground text-xs font-normal">(one per line)</span></Label>
                      <CodeEditor v-model="actionDraft.config.headers_str" language="text" :min-height="60" :max-height="100" :known-fields="knownFields" placeholder="Content-Type: application/x-www-form-urlencoded" />
                    </div>
                  </template>

                  <!-- send_email config -->
                  <template v-if="actionDraft.action_type === 'send_email'">
                    <div class="space-y-1.5">
                      <Label>To</Label>
                      <CodeEditor v-model="actionDraft.config.to" language="template" :min-height="40" :max-height="60" :known-fields="knownFields" placeholder="{{received.body.email}}" />
                    </div>
                    <div class="space-y-1.5">
                      <Label>Subject</Label>
                      <CodeEditor v-model="actionDraft.config.subject" language="template" :min-height="40" :max-height="60" :known-fields="knownFields" placeholder="New order: {{received.body.order_id}}" />
                    </div>
                    <div class="space-y-1.5">
                      <Label>Message</Label>
                      <CodeEditor v-model="actionDraft.config.message" language="template" :min-height="100" :max-height="200" :known-fields="knownFields" placeholder="Order {{received.body.order_id}} received from {{received.body.email}}" />
                    </div>
                    <div class="space-y-1.5">
                      <Label>Extra Headers <span class="text-muted-foreground text-xs font-normal">(e.g. Content-Type: text/html)</span></Label>
                      <Input v-model="actionDraft.config.headers_str" placeholder="Content-Type: text/html" />
                    </div>
                  </template>

                  <!-- fire_hook config -->
                  <template v-if="actionDraft.action_type === 'fire_hook'">
                    <div class="space-y-1.5">
                      <Label>Hook Name</Label>
                      <CodeEditor v-model="actionDraft.config.hook" language="template" :min-height="40" :max-height="60" :known-fields="knownFields" placeholder="my_custom_action" />
                      <p class="text-xs text-muted-foreground">Fires <code class="font-mono">do_action(hook, $context)</code>. The full template context is passed as the first argument.</p>
                    </div>
                  </template>

                  <!-- update_option config -->
                  <template v-if="actionDraft.action_type === 'update_option'">
                    <div class="grid grid-cols-2 gap-3">
                      <div class="space-y-1.5">
                        <Label>Option Name</Label>
                        <CodeEditor v-model="actionDraft.config.option_name" language="template" :min-height="40" :max-height="60" :known-fields="knownFields" placeholder="my_last_order_id" />
                      </div>
                      <div class="space-y-1.5">
                        <Label>Option Value</Label>
                        <CodeEditor v-model="actionDraft.config.option_value" language="template" :min-height="40" :max-height="60" :known-fields="knownFields" placeholder="{{received.body.id}}" />
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
                        <CodeEditor v-model="actionDraft.config.transient_key" language="template" :min-height="40" :max-height="60" :known-fields="knownFields" placeholder="last_order_{{received.body.user_id}}" />
                      </div>
                      <div class="space-y-1.5">
                        <Label>Value</Label>
                        <CodeEditor v-model="actionDraft.config.transient_value" language="template" :min-height="40" :max-height="60" :known-fields="knownFields" placeholder="{{received.body.order_id}}" />
                      </div>
                    </div>
                    <div class="space-y-1.5">
                      <Label>Expiry (seconds)</Label>
                      <Input v-model.number="actionDraft.config.expiry" type="number" min="0" class="w-36" />
                      <p class="text-xs text-muted-foreground">3600 = 1 hour, 86400 = 1 day. 0 = no expiry.</p>
                    </div>
                  </template>

                  <div class="flex gap-2 pt-2 border-t border-border">
                    <Button type="button" size="sm" @click="saveAction">Save Action</Button>
                    <Button type="button" size="sm" variant="outline" @click="editingAction = null">Cancel</Button>
                  </div>
                </div>
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
                    <p><strong>Available variables:</strong> <code class="font-mono">$payload</code> (body array), <code class="font-mono">$query</code>, <code class="font-mono">$headers</code>, <code class="font-mono">$endpoint</code>, <code class="font-mono">$context</code></p>
                    <p v-if="form.dto_pipeline_id"><code class="font-mono">$dto</code> — resolved DTO/ETL fields from the attached pipeline (e.g. <code class="font-mono">$dto['user_email']</code>)</p>
                    <p>Return an array for a JSON response. Return <code class="font-mono">null</code> to use the default response body template.</p>
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
                    <CodeEditor
                      v-model="form.function_code"
                      language="php"
                      :min-height="320"
                      placeholder="// $payload, $query, $headers, $dto, $endpoint, $context available"
                    />
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

              <!-- DTO -->
              <div v-if="form.dto_pipeline_id">
                <button type="button" class="flex items-center gap-1 w-full text-left font-medium text-muted-foreground hover:text-foreground" @click="openTagGroup = openTagGroup === 'dto' ? '' : 'dto'">
                  <component :is="openTagGroup === 'dto' ? ChevronDown : ChevronRight" class="h-3 w-3 shrink-0" />
                  DTO pipeline fields
                </button>
                <div v-if="openTagGroup === 'dto'" class="mt-1.5 space-y-0.5 pl-4 font-mono text-muted-foreground">
                  <p>&#123;&#123;dto.<em>output_key</em>&#125;&#125;</p>
                  <p>&#123;&#123;received.dto.<em>output_key</em>&#125;&#125;</p>
                  <p class="text-muted-foreground/60 text-[10px] not-italic pt-1">PHP: <code class="font-mono">$dto['output_key']</code></p>
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

    <!-- Test Payload Modal -->
    <div v-if="testModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="testModalOpen = false">
      <div class="bg-background border border-border rounded-lg shadow-xl w-full max-w-lg p-6 space-y-4 mx-4">
        <div class="flex items-center justify-between">
          <h3 class="font-semibold text-base flex items-center gap-2">
            <FlaskConical class="h-4 w-4 text-muted-foreground" />
            Test Payload
          </h3>
          <button type="button" class="text-muted-foreground hover:text-foreground" @click="testModalOpen = false">✕</button>
        </div>

        <p class="text-sm text-muted-foreground">
          Paste a sample JSON payload. Field paths will be extracted and used to auto-populate meta mappings and enable autocomplete in template fields.
        </p>

        <div class="space-y-1.5">
          <Label>JSON Payload</Label>
          <textarea
            v-model="testPayloadJson"
            rows="10"
            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono resize-y focus:outline-none focus:ring-2 focus:ring-ring"
            placeholder='{"user":{"id":123,"email":"user@example.com"},"order_id":"ORD-001","total":99.99}'
          ></textarea>
          <p v-if="testPayloadError" class="text-xs text-destructive">{{ testPayloadError }}</p>
        </div>

        <div class="flex gap-2">
          <Button type="button" @click="applyTestPayload">
            Apply &amp; Extract Fields
          </Button>
          <Button type="button" variant="outline" @click="testModalOpen = false">Cancel</Button>
        </div>
      </div>
    </div>
  </div>
</template>
