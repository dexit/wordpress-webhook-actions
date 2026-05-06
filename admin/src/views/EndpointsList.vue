<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Plus, Pencil, Trash2, Inbox, Copy, Check } from 'lucide-vue-next'
import { Button, Card, Badge, Switch, Dialog } from '@/components/ui'
import api from '@/lib/api'

const router = useRouter()
const endpoints = ref([])
const loading = ref(true)
const error = ref(null)
const togglingId = ref(null)
const pendingDelete = ref(null)
const copiedId = ref(null)

const loadEndpoints = async () => {
  loading.value = true
  error.value = null
  try {
    endpoints.value = await api.endpoints.list()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

const toggleEndpoint = async (endpoint) => {
  togglingId.value = endpoint.id
  try {
    const updated = await api.endpoints.toggle(endpoint.id)
    const index = endpoints.value.findIndex((e) => e.id === endpoint.id)
    if (index !== -1) endpoints.value[index] = updated
  } catch (e) {
    console.error('Failed to toggle endpoint:', e)
  } finally {
    togglingId.value = null
  }
}

const confirmDelete = async () => {
  const ep = pendingDelete.value
  if (!ep) return
  pendingDelete.value = null
  try {
    await api.endpoints.delete(ep.id)
    endpoints.value = endpoints.value.filter((e) => e.id !== ep.id)
  } catch (e) {
    console.error('Failed to delete endpoint:', e)
  }
}

const copyUrl = async (endpoint) => {
  try {
    await navigator.clipboard.writeText(endpoint.receiver_url)
    copiedId.value = endpoint.id
    setTimeout(() => { copiedId.value = null }, 2000)
  } catch (e) {
    console.error('Failed to copy:', e)
  }
}

onMounted(loadEndpoints)
</script>

<template>
  <div>
    <!-- Delete Confirm Dialog -->
    <Dialog
      :open="!!pendingDelete"
      :title="`Delete &quot;${pendingDelete?.name}&quot;?`"
      description="This will permanently delete the endpoint and all received payloads. This action cannot be undone."
      @close="pendingDelete = null"
    >
      <template #footer>
        <div class="flex gap-2">
          <Button variant="destructive" @click="confirmDelete">Delete</Button>
          <Button variant="outline" @click="pendingDelete = null">Cancel</Button>
        </div>
      </template>
    </Dialog>

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
      <div>
        <h2 class="text-xl font-semibold">Custom Endpoints</h2>
        <p class="text-muted-foreground text-sm">Receive external webhooks and store payloads for processing</p>
      </div>
      <Button @click="router.push('/endpoints/new')" class="self-start sm:self-auto">
        <Plus class="mr-2 h-4 w-4" />
        Add Endpoint
      </Button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-8 text-muted-foreground">
      Loading endpoints...
    </div>

    <!-- Error -->
    <div v-else-if="error" class="text-center py-8 text-destructive">
      {{ error }}
    </div>

    <!-- Empty -->
    <Card v-else-if="endpoints.length === 0" class="p-8 text-center">
      <Inbox class="w-10 h-10 mx-auto mb-3 text-muted-foreground" />
      <p class="text-muted-foreground mb-4">No custom endpoints configured yet</p>
      <Button @click="router.push('/endpoints/new')">
        <Plus class="mr-2 h-4 w-4" />
        Create your first endpoint
      </Button>
    </Card>

    <!-- List -->
    <div v-else class="space-y-3 sm:space-y-4">
      <Card
        v-for="endpoint in endpoints"
        :key="endpoint.id"
        class="p-3 sm:p-4"
      >
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 sm:gap-3 mb-2 flex-wrap">
              <h3 class="font-medium text-sm sm:text-base">{{ endpoint.name }}</h3>
              <Badge :variant="endpoint.is_enabled ? 'success' : 'secondary'" class="text-xs">
                {{ endpoint.is_enabled ? 'Active' : 'Disabled' }}
              </Badge>
              <Badge variant="outline" class="text-xs font-mono">{{ endpoint.slug }}</Badge>
            </div>

            <p v-if="endpoint.description" class="text-xs text-muted-foreground mb-2">
              {{ endpoint.description }}
            </p>

            <!-- Receiver URL -->
            <div class="flex items-center gap-2 mt-1">
              <span class="text-xs text-muted-foreground font-mono truncate">
                {{ endpoint.receiver_url }}
              </span>
              <button
                @click="copyUrl(endpoint)"
                class="shrink-0 text-muted-foreground hover:text-foreground transition-colors"
                title="Copy receiver URL"
              >
                <Check v-if="copiedId === endpoint.id" class="w-3.5 h-3.5 text-green-500" />
                <Copy v-else class="w-3.5 h-3.5" />
              </button>
            </div>

            <!-- Meta: HMAC + response code -->
            <div class="flex flex-wrap gap-1 mt-2">
              <Badge v-if="endpoint.secret_key" variant="outline" class="text-xs">
                HMAC {{ endpoint.hmac_algorithm }}
              </Badge>
              <Badge variant="outline" class="text-xs">
                HTTP {{ endpoint.response_code }}
              </Badge>
            </div>
          </div>

          <div class="flex items-center gap-1 sm:gap-2 pt-2 sm:pt-0 border-t sm:border-t-0 border-border sm:ml-4">
            <Switch
              :model-value="endpoint.is_enabled"
              :loading="togglingId === endpoint.id"
              @update:model-value="toggleEndpoint(endpoint)"
            />
            <Button
              size="icon"
              variant="ghost"
              @click="router.push(`/endpoints/${endpoint.id}/payloads`)"
              title="View payloads"
              class="h-8 w-8 sm:h-9 sm:w-9"
            >
              <Inbox class="h-4 w-4" />
            </Button>
            <Button
              size="icon"
              variant="ghost"
              @click="router.push(`/endpoints/${endpoint.id}`)"
              title="Edit"
              class="h-8 w-8 sm:h-9 sm:w-9"
            >
              <Pencil class="h-4 w-4" />
            </Button>
            <Button
              size="icon"
              variant="ghost"
              @click="pendingDelete = endpoint"
              title="Delete"
              class="h-8 w-8 sm:h-9 sm:w-9"
            >
              <Trash2 class="h-4 w-4" />
            </Button>
          </div>
        </div>
      </Card>
    </div>
  </div>
</template>
