<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeft, Trash2, CheckCheck, ChevronLeft, ChevronRight, RefreshCw } from 'lucide-vue-next'
import { Button, Card, Badge, Select, SelectTrigger, SelectValue, SelectContent, SelectItem, Dialog } from '@/components/ui'
import api from '@/lib/api'

const props = defineProps({ id: { type: [String, Number], required: true } })

const router = useRouter()

const endpoint = ref(null)
const payloads = ref([])
const stats = ref({ received: 0, processed: 0, failed: 0, total: 0 })
const loading = ref(true)
const error = ref(null)
const statusFilter = ref('')
const page = ref(1)
const perPage = 20
const total = ref(0)
const totalPages = ref(0)

const expandedId = ref(null)
const pendingDeleteAll = ref(false)
const pendingDeletePayload = ref(null)

const totalPages_ = computed(() => totalPages.value)

const statusVariant = (status) => {
  if (status === 'processed') return 'success'
  if (status === 'failed') return 'destructive'
  return 'secondary'
}

const formatDate = (str) => {
  if (!str) return '—'
  return new Date(str).toLocaleString()
}

const prettyJson = (str) => {
  try { return JSON.stringify(JSON.parse(str), null, 2) } catch { return str }
}

const loadEndpoint = async () => {
  try {
    endpoint.value = await api.endpoints.get(props.id)
  } catch (e) {
    console.error('Failed to load endpoint:', e)
  }
}

const loadPayloads = async () => {
  loading.value = true
  error.value = null
  try {
    const params = { page: page.value, per_page: perPage }
    if (statusFilter.value) params.status = statusFilter.value
    const res = await api.endpoints.payloads(props.id, params)
    // Backend returns { items, total, pages }
    payloads.value = res.items || []
    total.value = res.total || 0
    totalPages.value = res.pages || 0
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

const loadStats = async () => {
  try {
    stats.value = await api.endpoints.stats(props.id)
  } catch (e) {
    console.error('Failed to load stats:', e)
  }
}

const refresh = () => {
  loadPayloads()
  loadStats()
}

const applyFilter = () => {
  page.value = 1
  loadPayloads()
}

const markProcessed = async (payload) => {
  try {
    const updated = await api.endpoints.markProcessed(props.id, payload.id)
    const idx = payloads.value.findIndex((p) => p.id === payload.id)
    if (idx !== -1) payloads.value[idx] = updated
    loadStats()
  } catch (e) {
    console.error('Failed to mark processed:', e)
  }
}

const confirmDeletePayload = async () => {
  const p = pendingDeletePayload.value
  if (!p) return
  pendingDeletePayload.value = null
  try {
    await api.endpoints.deletePayload(props.id, p.id)
    payloads.value = payloads.value.filter((x) => x.id !== p.id)
    total.value = Math.max(0, total.value - 1)
    loadStats()
  } catch (e) {
    console.error('Failed to delete payload:', e)
  }
}

const confirmDeleteAll = async () => {
  pendingDeleteAll.value = false
  try {
    await api.endpoints.deletePayloads(props.id)
    payloads.value = []
    total.value = 0
    totalPages.value = 0
    loadStats()
  } catch (e) {
    console.error('Failed to delete all payloads:', e)
  }
}

onMounted(async () => {
  await Promise.all([loadEndpoint(), loadPayloads(), loadStats()])
})
</script>

<template>
  <div>
    <!-- Delete all confirm -->
    <Dialog
      :open="pendingDeleteAll"
      title="Delete all payloads?"
      description="This will permanently delete all received payloads for this endpoint. This cannot be undone."
      @close="pendingDeleteAll = false"
    >
      <template #footer>
        <div class="flex gap-2">
          <Button variant="destructive" @click="confirmDeleteAll">Delete All</Button>
          <Button variant="outline" @click="pendingDeleteAll = false">Cancel</Button>
        </div>
      </template>
    </Dialog>

    <!-- Delete single confirm -->
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

    <!-- Stats row -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
      <Card class="p-4 text-center">
        <p class="text-2xl font-semibold">{{ stats.total }}</p>
        <p class="text-xs text-muted-foreground mt-1">Total</p>
      </Card>
      <Card class="p-4 text-center">
        <p class="text-2xl font-semibold text-blue-500">{{ stats.received }}</p>
        <p class="text-xs text-muted-foreground mt-1">Pending</p>
      </Card>
      <Card class="p-4 text-center">
        <p class="text-2xl font-semibold text-green-500">{{ stats.processed }}</p>
        <p class="text-xs text-muted-foreground mt-1">Processed</p>
      </Card>
      <Card class="p-4 text-center">
        <p class="text-2xl font-semibold text-destructive">{{ stats.failed }}</p>
        <p class="text-xs text-muted-foreground mt-1">Failed</p>
      </Card>
    </div>

    <!-- Toolbar -->
    <div class="flex flex-col sm:flex-row gap-3 mb-4">
      <Select v-model="statusFilter" @update:model-value="applyFilter">
        <SelectTrigger class="w-full sm:w-40">
          <SelectValue placeholder="All statuses" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="">All statuses</SelectItem>
          <SelectItem value="received">Pending</SelectItem>
          <SelectItem value="processed">Processed</SelectItem>
          <SelectItem value="failed">Failed</SelectItem>
        </SelectContent>
      </Select>

      <div class="flex gap-2 ml-auto">
        <Button variant="outline" size="sm" @click="refresh">
          <RefreshCw class="h-4 w-4 mr-1.5" /> Refresh
        </Button>
        <Button
          v-if="payloads.length > 0"
          variant="outline"
          size="sm"
          class="text-destructive hover:text-destructive"
          @click="pendingDeleteAll = true"
        >
          <Trash2 class="h-4 w-4 mr-1.5" /> Delete All
        </Button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-8 text-muted-foreground">Loading payloads...</div>

    <!-- Error -->
    <div v-else-if="error" class="text-center py-8 text-destructive">{{ error }}</div>

    <!-- Empty -->
    <Card v-else-if="payloads.length === 0" class="p-8 text-center">
      <p class="text-muted-foreground">No payloads received yet.</p>
      <p v-if="endpoint" class="text-xs text-muted-foreground mt-2 font-mono">{{ endpoint.receiver_url }}</p>
    </Card>

    <!-- Payload list -->
    <div v-else class="space-y-3">
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
          <Badge :variant="statusVariant(payload.status)" class="text-xs shrink-0">
            {{ payload.status }}
          </Badge>
          <span class="text-xs text-muted-foreground shrink-0">{{ payload.method }}</span>
          <span class="text-xs text-muted-foreground font-mono truncate flex-1">
            {{ payload.source_ip || '—' }}
          </span>
          <span class="text-xs text-muted-foreground shrink-0 hidden sm:inline">
            {{ formatDate(payload.received_at) }}
          </span>
          <div class="flex items-center gap-1 ml-2 shrink-0">
            <Button
              v-if="payload.status === 'received'"
              size="icon"
              variant="ghost"
              class="h-7 w-7"
              title="Mark as processed"
              @click.stop="markProcessed(payload)"
            >
              <CheckCheck class="h-3.5 w-3.5" />
            </Button>
            <Button
              size="icon"
              variant="ghost"
              class="h-7 w-7"
              title="Delete"
              @click.stop="pendingDeletePayload = payload"
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
                <p class="text-muted-foreground mb-0.5">Received</p>
                <p>{{ formatDate(payload.received_at) }}</p>
              </div>
              <div v-if="payload.processed_at">
                <p class="text-muted-foreground mb-0.5">Processed</p>
                <p>{{ formatDate(payload.processed_at) }}</p>
              </div>
              <div>
                <p class="text-muted-foreground mb-0.5">Content-Type</p>
                <p class="font-mono truncate">{{ payload.content_type || '—' }}</p>
              </div>
              <div>
                <p class="text-muted-foreground mb-0.5">ID</p>
                <p class="font-mono">{{ payload.id }}</p>
              </div>
            </div>

            <div v-if="payload.processing_notes" class="text-xs p-2 rounded bg-muted text-muted-foreground">
              <span class="font-semibold">Notes:</span> {{ payload.processing_notes }}
            </div>

            <!-- Payload body -->
            <div>
              <p class="text-xs text-muted-foreground font-semibold uppercase tracking-wide mb-1.5">Payload</p>
              <pre class="text-xs font-mono bg-muted rounded p-3 overflow-x-auto whitespace-pre-wrap break-all max-h-60 overflow-y-auto">{{ prettyJson(payload.payload) }}</pre>
            </div>

            <!-- Headers -->
            <div v-if="payload.headers">
              <p class="text-xs text-muted-foreground font-semibold uppercase tracking-wide mb-1.5">Headers</p>
              <pre class="text-xs font-mono bg-muted rounded p-3 overflow-x-auto whitespace-pre-wrap break-all max-h-40 overflow-y-auto">{{ prettyJson(payload.headers) }}</pre>
            </div>
          </div>
        </div>
      </Card>
    </div>

    <!-- Pagination -->
    <div v-if="totalPages_ > 1" class="flex items-center justify-between mt-4">
      <p class="text-sm text-muted-foreground">{{ total }} total</p>
      <div class="flex items-center gap-2">
        <Button
          variant="outline"
          size="icon"
          class="h-8 w-8"
          :disabled="page <= 1"
          @click="page--; loadPayloads()"
        >
          <ChevronLeft class="h-4 w-4" />
        </Button>
        <span class="text-sm">{{ page }} / {{ totalPages_ }}</span>
        <Button
          variant="outline"
          size="icon"
          class="h-8 w-8"
          :disabled="page >= totalPages_"
          @click="page++; loadPayloads()"
        >
          <ChevronRight class="h-4 w-4" />
        </Button>
      </div>
    </div>
  </div>
</template>
