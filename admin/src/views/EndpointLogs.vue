<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeft, Trash2, ChevronLeft, ChevronRight, RefreshCw, Loader2, CheckCircle, XCircle, AlertCircle } from 'lucide-vue-next'
import { Button, Card, Badge, Alert, Select, SelectTrigger, SelectValue, SelectContent, SelectItem, Dialog, DateTimePicker } from '@/components/ui'
import { pickerLocalToUtcDb, formatUtcDate } from '@/lib/dates'
import api from '@/lib/api'

const props = defineProps({ id: { type: [String, Number], required: true } })

const router = useRouter()

const endpoint  = ref(null)
const logs      = ref([])
const loading   = ref(true)
const error     = ref(null)
const total     = ref(0)
const page      = ref(1)
const perPage   = 20

// Filters
const authFilter     = ref('')
const methodFilter   = ref('')
const dateFromFilter = ref('')
const dateToFilter   = ref('')

// Radix-vue Select — non-empty string values required
const authFilterSelect = computed({
  get: () => authFilter.value || 'all',
  set: (val) => { authFilter.value = val === 'all' ? '' : val },
})
const methodFilterSelect = computed({
  get: () => methodFilter.value || 'all',
  set: (val) => { methodFilter.value = val === 'all' ? '' : val },
})

const totalPages = computed(() => Math.ceil(total.value / perPage))

// Expanded row
const expandedId = ref(null)

// Confirm dialogs
const pendingDeleteAll = ref(false)
const pendingDeleteLog = ref(null)

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const authVariant = (result) => {
  if (result === 'pass') return 'success'
  if (result === 'fail') return 'destructive'
  return 'secondary'
}

const codeVariant = (code) => {
  if (!code) return 'secondary'
  if (code >= 200 && code < 300) return 'success'
  if (code >= 400) return 'destructive'
  return 'secondary'
}

const prettyJson = (val) => {
  if (!val) return '—'
  if (typeof val === 'object') return JSON.stringify(val, null, 2)
  try { return JSON.stringify(JSON.parse(val), null, 2) } catch { return val }
}

// ---------------------------------------------------------------------------
// Data loading
// ---------------------------------------------------------------------------

const loadEndpoint = async () => {
  try { endpoint.value = await api.endpoints.get(props.id) } catch { /* silent */ }
}

const loadLogs = async () => {
  loading.value = true
  error.value   = null
  try {
    const params = { page: page.value, per_page: perPage }
    if (authFilter.value)     params.auth_result = authFilter.value
    if (methodFilter.value)   params.method      = methodFilter.value
    if (dateFromFilter.value) params.date_from   = pickerLocalToUtcDb(dateFromFilter.value)
    if (dateToFilter.value)   params.date_to     = pickerLocalToUtcDb(dateToFilter.value)

    const res    = await api.endpoints.logs(props.id, params)
    logs.value   = res.items || []
    total.value  = res.total || 0
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

const resetPage = () => {
  if (page.value === 1) loadLogs()
  else page.value = 1
}

watch(page, loadLogs)
watch(authFilter,     resetPage)
watch(methodFilter,   resetPage)
watch(dateFromFilter, resetPage)
watch(dateToFilter,   resetPage)

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------

const confirmDeleteLog = async () => {
  const l = pendingDeleteLog.value
  if (!l) return
  pendingDeleteLog.value = null
  try {
    await api.endpoints.deleteLog(props.id, l.id)
    logs.value  = logs.value.filter((x) => x.id !== l.id)
    total.value = Math.max(0, total.value - 1)
  } catch (e) { console.error(e) }
}

const confirmDeleteAll = async () => {
  pendingDeleteAll.value = false
  try {
    await api.endpoints.deleteLogs(props.id)
    logs.value  = []
    total.value = 0
  } catch (e) { console.error(e) }
}

// ---------------------------------------------------------------------------

onMounted(() => Promise.all([loadEndpoint(), loadLogs()]))
</script>

<template>
  <div>
    <!-- Delete single log -->
    <Dialog
      :open="!!pendingDeleteLog"
      title="Delete log entry?"
      description="This will permanently delete this log entry."
      @close="pendingDeleteLog = null"
    >
      <template #footer>
        <div class="flex gap-2">
          <Button variant="destructive" @click="confirmDeleteLog">Delete</Button>
          <Button variant="outline" @click="pendingDeleteLog = null">Cancel</Button>
        </div>
      </template>
    </Dialog>

    <!-- Delete all logs -->
    <Dialog
      :open="pendingDeleteAll"
      title="Delete all logs?"
      description="This will permanently delete every log entry for this endpoint. This cannot be undone."
      @close="pendingDeleteAll = false"
    >
      <template #footer>
        <div class="flex gap-2">
          <Button variant="destructive" @click="confirmDeleteAll">Delete All</Button>
          <Button variant="outline" @click="pendingDeleteAll = false">Cancel</Button>
        </div>
      </template>
    </Dialog>

    <!-- Header -->
    <div class="mb-6">
      <Button variant="ghost" size="sm" class="mb-2" @click="router.push('/endpoints')">
        <ArrowLeft class="mr-2 h-4 w-4" />
        Back to endpoints
      </Button>
      <h2 class="text-xl font-semibold">
        Endpoint Logs
        <span v-if="endpoint" class="text-muted-foreground font-normal text-base ml-1">— {{ endpoint.name }}</span>
      </h2>
    </div>

    <!-- Filters -->
    <div class="flex flex-wrap items-center gap-3 mb-4">
      <Select v-model="authFilterSelect">
        <SelectTrigger class="w-full sm:w-36">
          <SelectValue placeholder="All auth" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All auth</SelectItem>
          <SelectItem value="pass">Pass</SelectItem>
          <SelectItem value="fail">Fail</SelectItem>
          <SelectItem value="none">None</SelectItem>
        </SelectContent>
      </Select>

      <Select v-model="methodFilterSelect">
        <SelectTrigger class="w-full sm:w-32">
          <SelectValue placeholder="All methods" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All methods</SelectItem>
          <SelectItem value="GET">GET</SelectItem>
          <SelectItem value="POST">POST</SelectItem>
          <SelectItem value="PUT">PUT</SelectItem>
          <SelectItem value="PATCH">PATCH</SelectItem>
          <SelectItem value="DELETE">DELETE</SelectItem>
        </SelectContent>
      </Select>

      <DateTimePicker
        v-model="dateFromFilter"
        placeholder="From date &amp; time"
        class="w-full sm:w-52"
      />
      <DateTimePicker
        v-model="dateToFilter"
        placeholder="To date &amp; time"
        class="w-full sm:w-52"
      />

      <Loader2 v-if="loading" class="h-4 w-4 animate-spin text-muted-foreground shrink-0" />

      <div class="flex gap-2 ml-auto">
        <Button variant="outline" size="sm" @click="loadLogs">
          <RefreshCw class="h-4 w-4 mr-1.5" />Refresh
        </Button>
        <Button
          v-if="logs.length > 0"
          variant="outline"
          size="sm"
          class="text-destructive hover:text-destructive"
          @click="pendingDeleteAll = true"
        >
          <Trash2 class="h-4 w-4 mr-1.5" />Delete All
        </Button>
      </div>
    </div>

    <!-- Error -->
    <Alert v-if="error" variant="destructive" class="mb-4">{{ error }}</Alert>

    <!-- Initial loading -->
    <Card v-if="loading && logs.length === 0" class="p-8 text-center">
      <Loader2 class="w-8 h-8 mx-auto text-muted-foreground mb-4 animate-spin" />
      <p class="text-muted-foreground">Loading logs...</p>
    </Card>

    <!-- Empty -->
    <Card v-else-if="!loading && logs.length === 0" class="p-8 text-center">
      <p class="text-muted-foreground">No log entries found.</p>
    </Card>

    <!-- List -->
    <div v-else class="relative space-y-2">
      <!-- Reload overlay -->
      <div v-if="loading" class="absolute inset-0 z-10 flex items-center justify-center rounded-md bg-background/60 backdrop-blur-[1px]">
        <Loader2 class="h-6 w-6 animate-spin text-muted-foreground" />
      </div>

      <Card v-for="log in logs" :key="log.id" class="overflow-hidden">
        <!-- Summary row -->
        <div
          class="flex items-center gap-3 p-3 sm:p-4 cursor-pointer hover:bg-muted/40 transition-colors"
          @click="expandedId = expandedId === log.id ? null : log.id"
        >
          <!-- Auth badge -->
          <component
            :is="log.auth_result === 'pass' ? CheckCircle : log.auth_result === 'fail' ? XCircle : AlertCircle"
            :class="[
              'h-4 w-4 shrink-0',
              log.auth_result === 'pass' ? 'text-green-500' : log.auth_result === 'fail' ? 'text-destructive' : 'text-muted-foreground',
            ]"
            :title="`Auth: ${log.auth_result || 'none'}`"
          />

          <!-- Method -->
          <Badge variant="outline" class="text-xs font-mono shrink-0">{{ log.method }}</Badge>

          <!-- Response code -->
          <Badge :variant="codeVariant(log.response_code)" class="text-xs shrink-0">
            {{ log.response_code || '—' }}
          </Badge>

          <!-- Source IP -->
          <span class="text-xs text-muted-foreground font-mono truncate flex-1">
            {{ log.source_ip || '—' }}
          </span>

          <!-- Duration -->
          <span class="text-xs text-muted-foreground shrink-0 hidden sm:inline tabular-nums">
            {{ log.duration_ms != null ? `${log.duration_ms}ms` : '' }}
          </span>

          <!-- Timestamp -->
          <span class="text-xs text-muted-foreground shrink-0 hidden md:inline">
            {{ formatUtcDate(log.received_at) }}
          </span>

          <!-- Delete -->
          <div class="ml-2 shrink-0" @click.stop>
            <Button
              size="icon"
              variant="ghost"
              class="h-7 w-7"
              title="Delete log"
              @click="pendingDeleteLog = log"
            >
              <Trash2 class="h-3.5 w-3.5" />
            </Button>
          </div>
        </div>

        <!-- Expanded detail -->
        <div v-if="expandedId === log.id" class="border-t border-border">
          <div class="p-3 sm:p-4 space-y-3">
            <!-- Meta grid -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
              <div>
                <p class="text-muted-foreground mb-0.5">Log ID</p>
                <p class="font-mono">{{ log.id }}</p>
              </div>
              <div v-if="log.payload_id">
                <p class="text-muted-foreground mb-0.5">Payload ID</p>
                <p class="font-mono">{{ log.payload_id }}</p>
              </div>
              <div>
                <p class="text-muted-foreground mb-0.5">Received</p>
                <p>{{ formatUtcDate(log.received_at) }}</p>
              </div>
              <div>
                <p class="text-muted-foreground mb-0.5">Duration</p>
                <p class="tabular-nums">{{ log.duration_ms != null ? `${log.duration_ms} ms` : '—' }}</p>
              </div>
              <div>
                <p class="text-muted-foreground mb-0.5">Auth result</p>
                <p class="capitalize">{{ log.auth_result || 'none' }}</p>
              </div>
              <div v-if="log.cpt_post_id">
                <p class="text-muted-foreground mb-0.5">CPT post ID</p>
                <p class="font-mono">{{ log.cpt_post_id }}</p>
              </div>
              <div>
                <p class="text-muted-foreground mb-0.5">Function executed</p>
                <p>{{ log.function_executed ? 'Yes' : 'No' }}</p>
              </div>
            </div>

            <!-- Error message -->
            <div v-if="log.error_message" class="text-xs p-2 rounded bg-destructive/10 text-destructive">
              <span class="font-semibold">Error:</span> {{ log.error_message }}
            </div>

            <!-- Query params -->
            <div v-if="log.query_params && Object.keys(log.query_params).length > 0">
              <p class="text-xs text-muted-foreground font-semibold uppercase tracking-wide mb-1.5">Query Params</p>
              <pre class="text-xs font-mono bg-muted rounded p-3 overflow-x-auto whitespace-pre-wrap break-all">{{ prettyJson(log.query_params) }}</pre>
            </div>

            <!-- Function output -->
            <div v-if="log.function_output">
              <p class="text-xs text-muted-foreground font-semibold uppercase tracking-wide mb-1.5">Function Output</p>
              <pre class="text-xs font-mono bg-muted rounded p-3 overflow-x-auto whitespace-pre-wrap break-all max-h-40 overflow-y-auto">{{ log.function_output }}</pre>
            </div>
          </div>
        </div>
      </Card>
    </div>

    <!-- Pagination -->
    <div v-if="totalPages > 1" class="flex items-center justify-between mt-4">
      <p class="text-sm text-muted-foreground">
        Showing {{ (page - 1) * perPage + 1 }}–{{ Math.min(page * perPage, total) }} of {{ total }}
      </p>
      <div class="flex items-center gap-2">
        <Button variant="outline" size="icon" class="h-8 w-8" :disabled="page <= 1" @click="page--">
          <ChevronLeft class="h-4 w-4" />
        </Button>
        <span class="text-sm tabular-nums">{{ page }} / {{ totalPages }}</span>
        <Button variant="outline" size="icon" class="h-8 w-8" :disabled="page >= totalPages" @click="page++">
          <ChevronRight class="h-4 w-4" />
        </Button>
      </div>
    </div>
  </div>
</template>
