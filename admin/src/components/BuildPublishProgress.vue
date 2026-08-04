<script setup>
// What happens after a build is accepted: the API stored it and asked
// wpwebhooks.org — a static site — to rebuild, which takes roughly a minute and
// a half. Until that finishes the URL 404s, so handing the author a live link
// straight away would hand them a broken one.
//
// So the address is shown as text while we wait, the wait is visible rather
// than mysterious, and the link only becomes a link once the page has actually
// answered 200. The check runs server-side (`pro/publish/status`) because a
// cross-origin fetch cannot tell a 404 from a hit.
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { CheckCircle2, ExternalLink, Loader2, Sparkles, Clock } from 'lucide-vue-next'
import api from '@/lib/api'
import { __, sprintf } from '@/i18n'

const props = defineProps({
  published: { type: Object, required: true }, // the API's publish payload
})

// The rebuild takes ~90s; that is when the first check is worth making.
const FIRST_CHECK_MS = 90_000
const RETRY_MS = 10_000
// The API debounces its rebuild dispatch, so a publish that landed inside
// another build's window waits for that one instead. Be patient for longer.
const GIVE_UP_MS = 6 * 60_000
const GIVE_UP_DEBOUNCED_MS = 12 * 60_000

const state = ref('waiting') // waiting | live | slow | held
const elapsed = ref(0)

let ticker = null
let timer = null
let stopped = false

const url = computed(() => props.published?.url || '')
const patience = computed(() =>
  props.published?.rebuild_dispatched === false ? GIVE_UP_DEBOUNCED_MS : GIVE_UP_MS
)

// Fills over the expected build time, then holds just short of full while we
// keep checking — it never claims to be done before the page answers.
const progress = computed(() => Math.min(96, Math.round((elapsed.value / FIRST_CHECK_MS) * 96)))

const remaining = computed(() => Math.max(0, Math.ceil((FIRST_CHECK_MS - elapsed.value) / 1000)))

const check = async () => {
  if (stopped) return

  try {
    const res = await api.builds.publishStatus(url.value)
    if (stopped) return

    if (res?.live) {
      state.value = 'live'
      clearInterval(ticker)
      return
    }
  } catch (e) {
    // Pro is what serves the probe, and it updates separately from this SPA. On
    // an older Pro the route simply is not there, and no amount of waiting will
    // conjure it — hand over the link now instead of spinning for six minutes.
    if (e?.code === 'rest_no_route') {
      state.value = 'slow'
      clearInterval(ticker)
      return
    }
    // Any other failed probe is not a failed publish — the build is stored
    // either way. Keep waiting; the retry below still runs.
  }

  if (elapsed.value >= patience.value) {
    // Out of patience, not out of hope: the build is published, the site just
    // has not caught up. Let them open it and see.
    state.value = 'slow'
    clearInterval(ticker)
    return
  }

  timer = setTimeout(check, RETRY_MS)
}

onMounted(() => {
  // A build held for review has no page to wait for yet — a human has to look
  // at it first, so there is nothing to poll.
  if (props.published?.status !== 'published' || !url.value) {
    state.value = 'held'
    return
  }

  const startedAt = Date.now()
  ticker = setInterval(() => { elapsed.value = Date.now() - startedAt }, 1000)
  timer = setTimeout(check, FIRST_CHECK_MS)
})

onBeforeUnmount(() => {
  stopped = true
  clearInterval(ticker)
  clearTimeout(timer)
})
</script>

<template>
  <div class="space-y-4">
    <!-- Live: the moment the page answers -->
    <div v-if="state === 'live'" class="space-y-3">
      <div class="fswa-tada flex items-start gap-2">
        <CheckCircle2 class="fswa-pop w-5 h-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
        <div class="space-y-1">
          <p class="text-sm font-medium">{{ __('Published — your build is live.') }}</p>
          <a
            :href="url"
            target="_blank"
            rel="noopener"
            class="inline-flex items-center gap-1 text-sm text-primary hover:!text-primary hover:!underline break-all"
          >
            {{ url }} <ExternalLink class="w-3.5 h-3.5 shrink-0" />
          </a>
        </div>
      </div>
    </div>

    <!-- Held for review: no page to wait for -->
    <div v-else-if="state === 'held'" class="flex items-start gap-2">
      <Clock class="w-5 h-5 shrink-0 text-amber-600 dark:text-amber-500" />
      <div class="space-y-1">
        <p class="text-sm font-medium">{{ published.message || __('Your build was received.') }}</p>
        <p v-if="url" class="text-sm text-muted-foreground break-all">{{ url }}</p>
        <p class="text-xs text-muted-foreground">
          {{ __('It will appear at this address once it has been reviewed.') }}
        </p>
      </div>
    </div>

    <!-- Still not there after a long wait -->
    <div v-else-if="state === 'slow'" class="space-y-3">
      <div class="flex items-start gap-2">
        <CheckCircle2 class="w-5 h-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
        <div class="space-y-1">
          <p class="text-sm font-medium">{{ __('Your build is published.') }}</p>
          <a
            :href="url"
            target="_blank"
            rel="noopener"
            class="inline-flex items-center gap-1 text-sm text-primary hover:!text-primary hover:!underline break-all"
          >
            {{ url }} <ExternalLink class="w-3.5 h-3.5 shrink-0" />
          </a>
          <p class="text-xs text-muted-foreground">
            {{ __('The page appears at this address once the website finishes rebuilding — it is safe to close this.') }}
          </p>
        </div>
      </div>
    </div>

    <!-- Waiting for the site to rebuild -->
    <div v-else class="space-y-3">
      <div class="flex items-start gap-2">
        <Loader2 class="w-5 h-5 shrink-0 animate-spin text-primary" />
        <div class="space-y-1 min-w-0">
          <p class="text-sm font-medium">{{ __('Building your page…') }}</p>
          <!-- Deliberately not a link yet: it 404s until the rebuild lands. -->
          <p class="text-sm text-muted-foreground break-all select-all">{{ url }}</p>
        </div>
      </div>

      <div class="h-1 overflow-hidden rounded-full bg-muted">
        <div
          class="h-full rounded-full bg-primary transition-[width] duration-1000 ease-linear"
          :style="{ width: progress + '%' }"
        />
      </div>

      <p class="text-xs text-muted-foreground">
        <template v-if="remaining > 0">
          {{ sprintf(__('wpwebhooks.org rebuilds after every publish — about %d seconds left. The address becomes a link the moment the page is live.'), remaining) }}
        </template>
        <template v-else>
          {{ __('Checking whether the page is live…') }}
        </template>
      </p>
    </div>

    <p v-if="published.credits_awarded" class="flex items-center gap-2 text-sm text-muted-foreground">
      <Sparkles class="w-4 h-4 text-primary" />
      {{ sprintf(__('%d AI credits added to your balance.'), published.credits_awarded) }}
    </p>
  </div>
</template>

<style scoped>
/* The payoff for a minute and a half of waiting. */
@keyframes fswa-tada {
  0%   { transform: scale(0.96); opacity: 0; }
  60%  { transform: scale(1.02); opacity: 1; }
  100% { transform: scale(1); opacity: 1; }
}

@keyframes fswa-pop {
  0%   { transform: scale(0.2) rotate(-25deg); }
  55%  { transform: scale(1.35) rotate(6deg); }
  75%  { transform: scale(0.92) rotate(-3deg); }
  100% { transform: scale(1) rotate(0); }
}

.fswa-tada { animation: fswa-tada 420ms cubic-bezier(0.22, 1, 0.36, 1) both; }
.fswa-pop  { animation: fswa-pop 620ms cubic-bezier(0.34, 1.56, 0.64, 1) 80ms both; }

@media (prefers-reduced-motion: reduce) {
  .fswa-tada,
  .fswa-pop { animation: none; }
}
</style>
