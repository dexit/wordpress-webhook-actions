<script setup>
// "Share this build" from the AI Builder: exports exactly what this conversation
// created (resolved server-side from the run's applied steps) as the same
// portable JSON the Export dialog produces.
//
// A build that leaves this site is documentation as much as configuration, so
// the dialog first surfaces anything still undescribed and lets the user write
// a description in place — saved to the webhook/chain itself, not just to the
// exported copy. Two privacy controls sit below it: attaching the AI
// conversation (Pro, off by default) and anonymizing the site address.
//
// The same dialog runs in `publish` mode (Pro): identical document, but instead
// of downloading it we send it to wpwebhooks.org, where it becomes a public page
// and pays the author in AI credits. Publishing adds the listing fields and ends
// on a live URL rather than a file.
import { ref, computed, watch } from 'vue'
import { AlertCircle, CheckCircle2, Pencil, ExternalLink, Sparkles } from 'lucide-vue-next'
import { Dialog, Button, Checkbox } from '@/components/ui'
import MarkdownField from '@/components/MarkdownField.vue'
import BuildPublishFields from '@/components/BuildPublishFields.vue'
import { useBuildExport } from '@/composables/useBuildExport'
import { usePro } from '@/composables/usePro'
import api from '@/lib/api'
import { __, sprintf } from '@/i18n'

const props = defineProps({
  open: { type: Boolean, default: false },
  conversationId: { type: [Number, String], default: null },
  title: { type: String, default: '' },
  mode: { type: String, default: 'export' }, // 'export' | 'publish'
})

const emit = defineEmits(['close'])

const { exportBuild } = useBuildExport()
const { proActive } = usePro()

const includeTranscript = ref(false)
const anonymizeSiteUrl = ref(false)
const exporting = ref(false)
const loading = ref(false)
const error = ref('')
const rejectedCategories = ref([])
const published = ref(null)     // API payload once the build is live

const items = ref([])          // [{ kind, id, name, description }]
const drafts = ref({})         // key -> edited description
const expanded = ref(new Set()) // keys whose editor is open

const AUTHOR_STORAGE_KEY = 'fswa_build_author'
const emptyForm = () => ({ title: '', collection: '', summary: '', author_name: '', author_url: '', author_linkedin: '' })
const form = ref(emptyForm())

const publishing = computed(() => props.mode === 'publish')

const keyOf = (item) => `${item.kind}-${item.id}`

const missingCount = computed(() =>
  items.value.filter((i) => !(drafts.value[keyOf(i)] || '').trim()).length
)

const loadItems = async () => {
  if (!props.conversationId) return
  loading.value = true
  try {
    const res = await api.builds.resolve({ conversation_id: Number(props.conversationId) })
    const webhooks = (res.webhooks || []).map((w) => ({ ...w, kind: 'webhook' }))
    const chains = (res.chains || []).map((c) => ({ ...c, kind: 'chain' }))
    items.value = [...webhooks, ...chains]

    const next = {}
    const open = new Set()
    for (const item of items.value) {
      next[keyOf(item)] = item.description || ''
      // Undescribed items open straight into the editor — that is the nudge.
      if (!item.description) open.add(keyOf(item))
    }
    drafts.value = next
    expanded.value = open
  } catch (e) {
    error.value = e?.message || __('Could not read this build.')
  } finally {
    loading.value = false
  }
}

// The author block is the one thing worth remembering between builds.
const loadAuthor = () => {
  try {
    return JSON.parse(localStorage.getItem(AUTHOR_STORAGE_KEY) || '{}')
  } catch {
    return {}
  }
}

const rememberAuthor = () => {
  try {
    localStorage.setItem(AUTHOR_STORAGE_KEY, JSON.stringify({
      author_name: form.value.author_name,
      author_url: form.value.author_url,
      author_linkedin: form.value.author_linkedin,
    }))
  } catch { /* private mode — publishing still works */ }
}

watch(() => props.open, (open) => {
  if (!open) return
  includeTranscript.value = false
  anonymizeSiteUrl.value = false
  error.value = ''
  rejectedCategories.value = []
  published.value = null
  items.value = []
  drafts.value = {}
  expanded.value = new Set()
  form.value = { ...emptyForm(), ...loadAuthor(), title: props.title || '' }
  loadItems()
})

const toggleEditor = (item) => {
  const set = new Set(expanded.value)
  const key = keyOf(item)
  set.has(key) ? set.delete(key) : set.add(key)
  expanded.value = set
}

// Persist edited descriptions to the objects themselves before exporting, so
// the site and the shared build stay in agreement.
const saveDescriptions = async () => {
  const changed = items.value.filter((i) => (drafts.value[keyOf(i)] || '') !== (i.description || ''))
  if (!changed.length) return

  await Promise.all(changed.map((item) => {
    const description = drafts.value[keyOf(item)] || ''
    return item.kind === 'chain'
      ? api.chains.update(item.id, { description })
      : api.webhooks.update(item.id, { description })
  }))

  for (const item of changed) item.description = drafts.value[keyOf(item)] || ''
}

const runExport = async () => {
  if (exporting.value || !props.conversationId) return
  exporting.value = true
  error.value = ''
  try {
    await saveDescriptions()
    await exportBuild({
      options: {
        conversation_id: Number(props.conversationId),
        include_ai_transcript: proActive.value && includeTranscript.value,
        anonymize_site_url: anonymizeSiteUrl.value,
      },
    }, props.title)
    emit('close')
  } catch (e) {
    error.value = e?.message || __('Export failed.')
  } finally {
    exporting.value = false
  }
}

const canPublish = computed(() => !!form.value.title.trim() && !!form.value.collection)

const runPublish = async () => {
  if (exporting.value || !props.conversationId || !canPublish.value) return
  exporting.value = true
  error.value = ''
  rejectedCategories.value = []
  try {
    // Descriptions become the body of the published page, so they are saved to
    // the objects first — exactly as in export mode.
    await saveDescriptions()
    const res = await api.builds.publish({
      conversation_id: Number(props.conversationId),
      title: form.value.title.trim(),
      summary: form.value.summary,
      author_name: form.value.author_name,
      author_url: form.value.author_url,
      author_linkedin: form.value.author_linkedin,
      collection: form.value.collection,
      include_ai_transcript: includeTranscript.value,
      anonymize_site_url: anonymizeSiteUrl.value,
    })
    rememberAuthor()
    published.value = res
  } catch (e) {
    error.value = e?.message || __('Publishing failed.')
    rejectedCategories.value = e?.data?.data?.categories || []
  } finally {
    exporting.value = false
  }
}
</script>

<template>
  <Dialog
    :open="open"
    :title="publishing ? __('Publish your build') : __('Share this build')"
    :description="publishing
      ? __('Publish this build on wpwebhooks.org so anyone can import it. Secrets are never included, and you keep 50 AI credits for publishing plus 20 every time someone likes it.')
      : __('Download everything this build created as a portable JSON file. Secrets are never included — whoever imports it re-links auth to their own vault.')"
    @close="emit('close')"
  >
    <!-- Published: the only thing left to do is look at it -->
    <div v-if="published" class="space-y-4">
      <div class="flex items-start gap-2">
        <CheckCircle2 class="w-5 h-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
        <div class="space-y-1">
          <p class="text-sm font-medium">{{ published.message || __('Your build is published.') }}</p>
          <a
            v-if="published.url"
            :href="published.url"
            target="_blank"
            rel="noopener"
            class="inline-flex items-center gap-1 text-sm text-primary hover:underline break-all"
          >
            {{ published.url }} <ExternalLink class="w-3.5 h-3.5 shrink-0" />
          </a>
        </div>
      </div>

      <p v-if="published.credits_awarded" class="flex items-center gap-2 text-sm text-muted-foreground">
        <Sparkles class="w-4 h-4 text-primary" />
        {{ sprintf(__('%d AI credits added to your balance.'), published.credits_awarded) }}
      </p>
    </div>

    <div v-else class="space-y-5">
      <!-- Listing details (publish only) -->
      <BuildPublishFields
        v-if="publishing"
        :model-value="form"
        :disabled="exporting"
        @update:model-value="form = $event"
      />

      <!-- Describe what travels with the build -->
      <div v-if="loading" class="text-sm text-muted-foreground">{{ __('Reading this build…') }}</div>

      <div v-else-if="items.length" class="space-y-3">
        <div class="flex items-center gap-2">
          <p class="text-sm font-medium">{{ __('What this build contains') }}</p>
          <span v-if="missingCount" class="inline-flex items-center gap-1 text-xs text-amber-600 dark:text-amber-500">
            <AlertCircle class="w-3.5 h-3.5" />
            {{ sprintf(__('%d without a description'), missingCount) }}
          </span>
        </div>
        <p class="text-xs text-muted-foreground">
          {{ __('Descriptions travel with the build and are saved here too — they are what makes a shared build understandable to someone else.') }}
        </p>

        <div class="space-y-2">
          <div v-for="item in items" :key="keyOf(item)" class="rounded-md border p-3 space-y-2">
            <div class="flex items-center justify-between gap-2">
              <div class="flex items-center gap-2 min-w-0">
                <span class="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-muted-foreground">
                  {{ item.kind === 'chain' ? __('Chain') : __('Webhook') }}
                </span>
                <span class="text-sm font-medium truncate">{{ item.name }}</span>
              </div>
              <button
                v-if="!expanded.has(keyOf(item))"
                type="button"
                class="inline-flex items-center gap-1 shrink-0 text-xs text-muted-foreground hover:text-foreground"
                @click="toggleEditor(item)"
              >
                <CheckCircle2 class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400" />
                {{ __('Described') }}
                <Pencil class="w-3 h-3" />
              </button>
            </div>

            <MarkdownField
              v-if="expanded.has(keyOf(item))"
              :model-value="drafts[keyOf(item)]"
              :rows="3"
              :placeholder="__('What does this do, and what does someone need before importing it?')"
              @update:model-value="drafts[keyOf(item)] = $event"
            />
          </div>
        </div>
      </div>

      <!-- Provenance + privacy -->
      <div class="space-y-4 border-t pt-4">
        <div v-if="proActive" class="space-y-2">
          <label class="flex items-start gap-2 cursor-pointer">
            <Checkbox class="mt-0.5" :model-value="includeTranscript" @update:model-value="includeTranscript = $event" />
            <span class="text-sm font-medium">{{ __('Include the AI conversation') }}</span>
          </label>
          <p class="text-xs text-muted-foreground pl-6">
            {{ __('Adds your prompts and the assistant\'s replies so the recipient can see how this build was made. Read results are never included and obvious secrets are masked, but the conversation may still mention details about your site — review before sharing publicly.') }}
          </p>
        </div>
        <p v-else class="text-xs text-muted-foreground">
          {{ __('Sharing the AI conversation alongside a build is a Pro feature.') }}
        </p>

        <div class="space-y-2">
          <label class="flex items-start gap-2 cursor-pointer">
            <Checkbox class="mt-0.5" :model-value="anonymizeSiteUrl" @update:model-value="anonymizeSiteUrl = $event" />
            <span class="text-sm font-medium">{{ __('Anonymize my site address') }}</span>
          </label>
          <p class="text-xs text-muted-foreground pl-6">
            {{ __('Replaces your site URL with example.com everywhere it appears — the export header, endpoint URLs, Code Glue and the AI conversation. Whoever imports the build re-points those endpoints at their own site.') }}
          </p>
        </div>
      </div>

      <p v-if="publishing" class="text-xs text-muted-foreground">
        {{ __('Published builds are checked automatically before they go live, and can be taken down later from your account.') }}
      </p>

      <div v-if="error" class="space-y-1">
        <p class="text-sm text-destructive">{{ error }}</p>
        <p v-if="rejectedCategories.length" class="text-xs text-destructive/80">
          {{ sprintf(__('Flagged: %s'), rejectedCategories.join(', ')) }}
        </p>
      </div>
    </div>

    <template #footer>
      <div class="flex gap-2">
        <template v-if="published">
          <Button @click="emit('close')">{{ __('Done') }}</Button>
        </template>
        <template v-else-if="publishing">
          <Button :disabled="exporting || loading || !conversationId || !canPublish" @click="runPublish">
            {{ exporting ? __('Publishing…') : __('Publish build') }}
          </Button>
          <Button variant="outline" :disabled="exporting" @click="emit('close')">{{ __('Cancel') }}</Button>
        </template>
        <template v-else>
          <Button :disabled="exporting || loading || !conversationId" @click="runExport">
            {{ exporting ? __('Exporting…') : __('Download JSON') }}
          </Button>
          <Button variant="outline" :disabled="exporting" @click="emit('close')">{{ __('Cancel') }}</Button>
        </template>
      </div>
    </template>
  </Dialog>
</template>
