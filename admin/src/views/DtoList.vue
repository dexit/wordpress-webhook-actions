<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Plus, Pencil, Trash2, GitMerge } from 'lucide-vue-next'
import { Button, Card, Badge, Dialog } from '@/components/ui'
import api from '@/lib/api'

const router = useRouter()
const pipelines = ref([])
const loading = ref(true)
const error = ref(null)
const pendingDelete = ref(null)

const loadPipelines = async () => {
  loading.value = true
  error.value = null
  try {
    pipelines.value = await api.dto.list()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

const confirmDelete = async () => {
  const p = pendingDelete.value
  if (!p) return
  pendingDelete.value = null
  try {
    await api.dto.delete(p.id)
    pipelines.value = pipelines.value.filter((x) => x.id !== p.id)
  } catch (e) {
    console.error('Failed to delete pipeline:', e)
  }
}

onMounted(loadPipelines)
</script>

<template>
  <div>
    <!-- Delete Confirm Dialog -->
    <Dialog
      :open="!!pendingDelete"
      :title="`Delete &quot;${pendingDelete?.name}&quot;?`"
      description="This will permanently delete the pipeline. Endpoints using it will no longer run DTO transforms."
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
        <h2 class="text-xl font-semibold">DTO / ETL Pipelines</h2>
        <p class="text-muted-foreground text-sm">
          Define reusable data transformation pipelines. Attach a pipeline to an endpoint to extract,
          rename, type-cast, and normalize fields before they reach your code, CPT mapper, or outgoing webhooks.
        </p>
      </div>
      <Button @click="router.push('/dto/new')" class="self-start sm:self-auto">
        <Plus class="mr-2 h-4 w-4" />
        New Pipeline
      </Button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-8 text-muted-foreground">
      Loading pipelines...
    </div>

    <!-- Error -->
    <div v-else-if="error" class="text-center py-8 text-destructive">
      {{ error }}
    </div>

    <!-- Empty -->
    <Card v-else-if="pipelines.length === 0" class="p-8 text-center">
      <GitMerge class="w-10 h-10 mx-auto mb-3 text-muted-foreground" />
      <p class="text-muted-foreground mb-4">No pipelines defined yet</p>
      <p class="text-sm text-muted-foreground mb-4 max-w-md mx-auto">
        Pipelines let you map incoming payload fields to clean, typed output variables using
        <code class="text-xs bg-muted px-1 rounded">&#123;&#123;received.body.field&#125;&#125;</code> template tags.
        Attach a pipeline to any endpoint and access the result via
        <code class="text-xs bg-muted px-1 rounded">$dto</code> in PHP or
        <code class="text-xs bg-muted px-1 rounded">&#123;&#123;dto.field&#125;&#125;</code> in templates.
      </p>
      <Button @click="router.push('/dto/new')">
        <Plus class="mr-2 h-4 w-4" />
        Create your first pipeline
      </Button>
    </Card>

    <!-- List -->
    <div v-else class="space-y-3 sm:space-y-4">
      <Card
        v-for="pipeline in pipelines"
        :key="pipeline.id"
        class="p-3 sm:p-4"
      >
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 sm:gap-3 mb-2 flex-wrap">
              <h3 class="font-medium text-sm sm:text-base">{{ pipeline.name }}</h3>
              <Badge :variant="pipeline.is_enabled ? 'success' : 'secondary'" class="text-xs">
                {{ pipeline.is_enabled ? 'Active' : 'Disabled' }}
              </Badge>
              <Badge variant="outline" class="text-xs font-mono">{{ pipeline.slug }}</Badge>
              <Badge variant="outline" class="text-xs">
                {{ pipeline.pipeline_config?.length ?? 0 }} fields
              </Badge>
            </div>
            <p v-if="pipeline.description" class="text-xs text-muted-foreground mb-2">
              {{ pipeline.description }}
            </p>
            <!-- Field preview -->
            <div v-if="pipeline.pipeline_config?.length" class="flex flex-wrap gap-1 mt-1">
              <span
                v-for="field in pipeline.pipeline_config.slice(0, 6)"
                :key="field.output_key"
                class="text-xs bg-muted text-muted-foreground px-1.5 py-0.5 rounded font-mono"
              >
                {{ field.output_key }}
                <span class="opacity-60">{{ field.type ? `:${field.type}` : '' }}</span>
              </span>
              <span v-if="pipeline.pipeline_config.length > 6" class="text-xs text-muted-foreground">
                +{{ pipeline.pipeline_config.length - 6 }} more
              </span>
            </div>
          </div>

          <div class="flex items-center gap-1 sm:gap-2 pt-2 sm:pt-0 border-t sm:border-t-0 border-border sm:ml-4">
            <Button
              size="icon"
              variant="ghost"
              @click="router.push(`/dto/${pipeline.id}`)"
              title="Edit"
              class="h-8 w-8 sm:h-9 sm:w-9"
            >
              <Pencil class="h-4 w-4" />
            </Button>
            <Button
              size="icon"
              variant="ghost"
              @click="pendingDelete = pipeline"
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
