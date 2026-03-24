<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeft, Trash2, CheckCheck, XCircle, ChevronLeft, ChevronRight, RefreshCw, Loader2 } from 'lucide-vue-next'
import { Button, Card, Badge, Alert, Select, SelectTrigger, SelectValue, SelectContent, SelectItem, Dialog, DateTimePicker } from '@/components/ui'
import { pickerLocalToUtcDb, formatUtcDate } from '@/lib/dates'
import api from '@/lib/api'

const props = defineProps({ id: { type: [String, Number], required: true } })

const router = useRouter()

const endpoint = ref(null)
const payloads = ref([])
const stats    = ref({ received: 0, processed: 0, failed: 0, total: 0 })
const loading  = ref(true)
const error    = ref(null)
const total    = ref(0)
const page     = ref(1)
const perPage  = 20

// Filters
const statusFilter   = ref('')
const dateFromFilter = ref('')
const dateToFilter   = ref('')

// Radix-vue Select requires non-empty string values
const statusFilterSelect = computed({
  get: () => statusFilter.value || 'all',
  set: (val) => { statusFilter.value = val === 'all' ? '' : val },
})

const totalPages = computed(() => Math.ceil(total.value / perPage))

// Expanded payload detail
const expandedId = ref(null)

// Confirm dialogs
const pendingDeleteAll   = ref(false)
const pendingDeletePayload = ref(null)
const pendingPurge       = ref(false)
const purgeDays          = ref(30)

const statusVariant = (status) => {
  if (status === 'processed') return 'success'
  if (status === 'failed') return 'destructive'
  return 'secondary'
}

const prettyJson = (str) => {
  try { return JSON.stringify(JSON.parse(str), null, 2) } catch { return str || '—' }
}

// ---------------------------------------------------------------------------
// Data loading
// ---------------------------------------------------------------------------

const loadEndpoint = async () => {
  try { endpoint.value = await api.endpoints.get(props.id) } catch { /* silent */ }
}

const loadPayloads = async () => {
  loading.value = true
  error.value   = null
  try {
    const params = { page: page.value, per_page: perPage }
    if (statusFilter.value)   params.status    = statusFilter.value
    if (dateFromFilter.value) params.date_from = pickerLocalToUtcDb(dateFromFilter.value)
    if (dateToFilter.value)   params.date_to   = pickerLocalToUtcDb(dateToFilter.value)

    const res    = await api.endpoints.payloads(props.id, params)
    payloads.value = res.items || []
    total.value    = res.total || 0
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

const loadStats = async () => {
  try { stats.value = await api.endpoints.stats(props.id) } catch { /* silent */ }
}

const resetPage = () => {
  if (page.value === 1) loadPayloads()
  else page.value = 1  // watch(page) triggers loadPayloads
}

watch(page, loadPayloads)
watch(statusFilter,   resetPage)
watch(dateFromFilter, resetPage)
watch(dateToFilter,   resetPage)

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------

const markProcessed = async (payload) => {
  try {
    const updated = await api.endpoints.markProcessed(props.id, payload.id)
    updateLocal(payload.id, updated)
    loadStats()
  } catch (e) { console.error(e) }
}

const markFailed = async (payload) => {
  try {
    const updated = await api.endpoints.markFailed(props.id, payload.id)
    updateLocal(payload.id, updated)
    loadStats()
  } catch (e) { console.error(e) }
}

const confirmDeletePayload = async () => {
  const p = pendingDeletePayload.value
  if (!p) return
  pendingDeletePayload.value = null
  try {
    await api.endpoints.deletePayload(props.id, p.id)
    payloads.value = payloads.value.filter((x) => x.id !== p.id)
    total.value    = Math.max(0, total.value - 1)
    loadStats()
  } catch (e) { console.error(e) }
}

const confirmDeleteAll = async () => {
  pendingDeleteAll.value = false
  try {
    await api.endpoints.deletePayloads(props.id)
    payloads.value = []
    total.value    = 0
    loadStats()
  } catch (e) { console.error(e) }
}

const confirmPurge = async () => {
  pendingPurge.value = false
  try {
    await api.endpoints.purge(props.id, purgeDays.value)
    await Promise.all([loadPayloads(), loadStats()])
  } catch (e) { console.error(e) }
}

const updateLocal = (id, updated) => {
  const idx = payloads.value.findIndex((p) => p.id === id)
  if (idx !== -1) payloads.value[idx] = updated
}

// ---------------------------------------------------------------------------

onMounted(() => Promise.all([loadEndpoint(), loadPayloads(), loadStats()]))
</script>

<template>
  <div>
    <!-- Delete single payload -->
    <Dialog
      :open="!!pendingDeletePayload"
      title="Delete payload?"
      description="This will permanently delete this payload record."
      @close="pendingDeletePayload = null"
    >
      <template #footer>
        <div class="flex gap-2">
          <Button variant="destructive" @click="confirmDeletePayload">Delete</Button>
          <Button variant="outline" @click="pendingDeletePayload = null">Cancel</Button>
        </div>
      </template>
    </Dialog>

    <!-- Delete all payloads -->
    <Dialog
      :open="pendingDeleteAll"
      title="Delete all payloads?"
      description="This will permanently delete every payload for this endpoint. This cannot be undone."
      @close="pendingDeleteAll = false"
    >
      <template #footer>
        <div class="flex gap-2">
          <Button variant="destructive" @click="confirmDeleteAll">Delete All</Button>
          <Button variant="outline" @click="pendingDeleteAll = false">Cancel</Button>
        </div>
      </template>
    </Dialog>

    <!-- Purge old payloads -->
    <Dialog
      :open="pendingPurge"
      title="Purge old payloads"
      :description="`Delete payloads received more than ${purgeDays} day(s) ago. This cannot be undone.`"
      @close="pendingPurge = false"
    >
      <template #footer>
        <div class="flex gap-2">
          <Button variant="destructive" @click="confirmPurge">Purge</Button>
          <Button variant="outline" @click="pendingPurge = false">Cancel</Button>
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
        Received Payloads
        <span v-if="endpoint" class="text-muted-foreground font-normal text-base ml-1">— {{ endpoint.name }}</span>
      </h2>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
      <Card class="p-4 text-center">
        <p class="text-2xl font-semibold tabular-nums">{{ stats.total }}</p>
        <p class="text-xs text-muted-foreground mt-1">Total</p>
      </Card>
      <Card class="p-4 text-center">
        <p class="text-2xl font-semibold tabular-nums text-blue-500">{{ stats.received }}</p>
        <p class="text-xs text-muted-foreground mt-1">Pending</p>
      </Card>
      <Card class="p-4 text-center">
        <p class="text-2xl font-semibold tabular-nums text-green-500">{{ stats.processed }}</p>
        <p class="text-xs text-muted-foreground mt-1">Processed</p>
      </Card>
      <Card class="p-4 text-center">
        <p class="text-2xl font-semibold tabular-nums text-destructive">{{ stats.failed }}</p>
        <p class="text-xs text-muted-foreground mt-1">Failed</p>
      </Card>
    </div>

    <!-- Filters -->
    <div class="flex flex-wrap items-center gap-3 mb-4">
      <Select v-model="statusFilterSelect">
        <SelectTrigger class="w-full sm:w-40">
          <SelectValue placeholder="All statuses" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All statuses</SelectItem>
          <SelectItem value="received">Pending</SelectItem>
          <SelectItem value="processed">Processed</SelectItem>
          <SelectItem value="failed">Failed</SelectItem>
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

      <!-- Toolbar actions -->
      <div class="flex gap-2 ml-auto">
        <Button variant="outline" size="sm" @click="() => Promise.all([loadPayloads(), loadStats()])">
          <RefreshCw class="h-4 w-4 mr-1.5" />Refresh
        </Button>
        <Button
          variant="outline"
          size="sm"
          class="text-muted-foreground"
          @click="pendingPurge = true"
          title="Delete payloads older than N days"
        >
          <Trash2 class="h-4 w-4 mr-1.5" />Purge Old
        </Button>
        <Button
          v-if="payloads.length > 0"
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

    <!-- Initial loading (no data yet) -->
    <Card v-if="loading && payloads.length === 0" class="p-8 text-center">
      <Loader2 class="w-8 h-8 mx-auto text-muted-foreground mb-4 animate-spin" />
      <p class="text-muted-foreground">Loading payloads...</p>
    </Card>

    <!-- Empty -->
    <Card v-else-if="!loading && payloads.length === 0" class="p-8 text-center">
      <p class="text-muted-foreground">No payloads found.</p>
      <p v-if="endpoint" class="text-xs text-muted-foreground font-mono mt-2">{{ endpoint.receiver_url }}</p>
    </Card>

    <!-- List -->
    <div v-else class="relative space-y-3">
      <!-- Overlay spinner when reloading -->
      <div v-if="loading" class="absolute inset-0 z-10 flex items-center justify-center rounded-md bg-background/60 backdrop-blur-[1px]">
        <Loader2 class="h-6 w-6 animate-spin text-muted-foreground" />
      </div>

      <Card
        v-for="payload in payloads"
        :key="payload.id"
        class="overflow-hidden"
      >
        <!-- Summary row -->
        <div
          class="flex items-center gap-3 p-3 sm:p-4 cursor-pointer hover:bg-muted/40 transition-colors"
          @click="expandedId = expandedId === payload.id ? null : payload.id"
        >
          <Badge :variant="statusVariant(payload.status)" class="text-xs shrink-0 capitalize">
            {{ payload.status === 'received' ? 'pending' : payload.status }}
          </Badge>
          <span class="text-xs text-muted-foreground font-mono shrink-0">{{ payload.method }}</span>
          <span class="text-xs text-muted-foreground font-mono truncate flex-1">
            {{ payload.source_ip || '—' }}
          </span>
          <span class="text-xs text-muted-foreground shrink-0 hidden sm:inline">
            {{ formatUtcDate(payload.received_at) }}
          </span>

          <div class="flex items-center gap-1 ml-2 shrink-0" @click.stop>
            <!-- Mark processed (only when received/failed) -->
            <Button
              v-if="payload.status !== 'processed'"
              size="icon"
              variant="ghost"
              class="h-7 w-7"
              title="Mark as processed"
              @click="markProcessed(payload)"
            >
              <CheckCheck class="h-3.5 w-3.5 text-green-500" />
            </Button>
            <!-- Mark failed (only when received) -->
            <Button
              v-if="payload.status === 'received'"
              size="icon"
              variant="ghost"
              class="h-7 w-7"
              title="Mark as failed"
              @click="markFailed(payload)"
            >
              <XCircle class="h-3.5 w-3.5 text-destructive" />
            </Button>
            <!-- Delete -->
            <Button
              size="icon"
              variant="ghost"
              class="h-7 w-7"
              title="Delete"
              @click="pendingDeletePayload = payload"
            >
              <Trash2 class="h-3.5 w-3.5" />
            </Button>
          </div>
        </div>

        <!-- Expanded detail -->
        <div v-if="expandedId === payload.id" class="border-t border-border">
          <div class="p-3 sm:p-4 space-y-3">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
              <div>
                <p class="text-muted-foreground mb-0.5">ID</p>
                <p class="font-mono">{{ payload.id }}</p>
              </div>
              <div>
                <p class="text-muted-foreground mb-0.5">Received</p>
                <p>{{ formatUtcDate(payload.received_at) }}</p>
              </div>
              <div v-if="payload.processed_at">
                <p class="text-muted-foreground mb-0.5">Processed at</p>
                <p>{{ formatUtcDate(payload.processed_at) }}</p>
              </div>
              <div>
                <p class="text-muted-foreground mb-0.5">Content-Type</p>
                <p class="font-mono truncate">{{ payload.content_type || '—' }}</p>
              </div>
            </div>

            <div v-if="payload.processing_notes" class="text-xs p-2 rounded bg-muted text-muted-foreground">
              <span class="font-semibold">Notes:</span> {{ payload.processing_notes }}
            </div>

            <div>
              <p class="text-xs text-muted-foreground font-semibold uppercase tracking-wide mb-1.5">Payload</p>
              <pre class="text-xs font-mono bg-muted rounded p-3 overflow-x-auto whitespace-pre-wrap break-all max-h-60 overflow-y-auto">{{ prettyJson(payload.payload) }}</pre>
            </div>

            <div v-if="payload.headers">
              <p class="text-xs text-muted-foreground font-semibold uppercase tracking-wide mb-1.5">Headers</p>
              <pre class="text-xs font-mono bg-muted rounded p-3 overflow-x-auto whitespace-pre-wrap break-all max-h-40 overflow-y-auto">{{ prettyJson(payload.headers) }}</pre>
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
