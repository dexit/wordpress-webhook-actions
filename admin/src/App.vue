<script setup>
import { RouterView, RouterLink, useRoute } from 'vue-router';
import {
  Webhook,
  ScrollText,
  Settings,
  Moon,
  Sun,
  Clock,
  KeyRound,
  ShieldCheck,
  Sparkles,
  Timer,
  History,
  BrainCircuit,
  X,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { useTheme } from './composables/useTheme';
import { usePro } from './composables/usePro';
import { useGlueStatus } from './composables/useGlueStatus';
import { __ } from '@/i18n';
import HealthStatusBar from './components/HealthStatusBar.vue';

const route = useRoute();
const { theme, toggleTheme } = useTheme();
const { proActive } = usePro();

// Code Glue can be switched off by the server (DISALLOW_FILE_EDIT) or by the
// user's role, and neither is visible from the editor itself — the sections
// simply refuse when used. Say so once, on every screen, until dismissed.
const { glue, dismissed: glueNoticeDismissed, dismiss: dismissGlueNotice } = useGlueStatus();
const showGlueNotice = computed(() => !glue.value.can_write && !glueNoticeDismissed.value);

const navItems = [
  { path: '/ai-builder', label: __('Build with AI'), icon: BrainCircuit },
  { path: '/webhooks', label: __('Webhooks'), icon: Webhook },
  { path: '/logs', label: __('Logs'), icon: ScrollText },
  { path: '/queue', label: __('Queue'), icon: Clock },
  { path: '/tokens', label: __('API Tokens'), icon: KeyRound },
  { path: '/vault', label: __('Credentials Vault'), icon: ShieldCheck },
  { path: '/external-cron', label: __('External Cron'), icon: Timer },
  { path: '/activity', label: __('Activity'), icon: History },
  { path: '/settings', label: __('Settings'), icon: Settings },
  { path: '/pro', label: __('Pro'), icon: Sparkles },
];

const isActive = (path) => {
  return route.path.startsWith(path);
};
</script>

<template>
  <div class="min-h-[500px] flex flex-col">
    <!-- Header -->
    <div
      class="flex items-center justify-between mb-6 pb-4 border-b border-border"
    >
      <div class="flex items-center gap-2">
        <h1 class="text-2xl font-semibold text-foreground">Webhook Actions</h1>
        <span
          v-if="proActive"
          class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-semibold bg-primary/15 text-primary border border-primary/30"
        >
          <Sparkles class="w-3.5 h-3.5" />
          Pro
        </span>
      </div>
      <button
        @click="toggleTheme"
        class="p-2 rounded-md hover:bg-muted text-muted-foreground hover:text-foreground transition-colors"
        :title="
          theme === 'dark' ? __('Switch to light mode') : __('Switch to dark mode')
        "
      >
        <Sun v-if="theme === 'dark'" class="w-5 h-5" />
        <Moon v-else class="w-5 h-5" />
      </button>
    </div>

    <!-- Health Status Bar -->
    <HealthStatusBar />

    <!-- Navigation -->
    <nav class="flex flex-wrap gap-1 mb-6 border-b border-border">
      <RouterLink
        v-for="item in navItems"
        :key="item.path"
        :to="item.path"
        :class="[
          'flex items-center gap-1.5 sm:gap-2 px-2.5 sm:px-4 py-2 text-xs sm:text-sm font-medium transition-colors border-b-2 -mb-px whitespace-nowrap',
          isActive(item.path)
            ? '!border-primary !text-primary'
            : 'border-transparent text-muted-foreground hover:!text-foreground hover:!border-muted',
        ]"
      >
        <component :is="item.icon" class="w-4 h-4" />
        {{ item.label }}
      </RouterLink>
    </nav>

    <!-- Code Glue unavailable: explain once rather than let the editor fail silently -->
    <div
      v-if="showGlueNotice"
      class="mb-5 flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300"
    >
      <div class="flex-1 min-w-0 space-y-1">
        <p class="font-medium">{{ glue.headline }}</p>
        <p class="text-xs opacity-90">{{ glue.detail }}</p>
        <p v-if="glue.fixable_in_settings" class="text-xs">
          <RouterLink to="/settings" class="underline">{{ __('Open Settings') }}</RouterLink>
        </p>
      </div>
      <button
        class="shrink-0 rounded p-1 hover:bg-amber-100 dark:hover:bg-amber-900"
        :title="__('Dismiss')"
        @click="dismissGlueNotice"
      >
        <X class="w-4 h-4" />
      </button>
    </div>

    <!-- Content -->
    <main class="flex-1">
      <RouterView />
    </main>

    <!-- Footer -->
    <footer class="mt-8 pt-4 border-t border-border">
      <div
        class="flex flex-wrap items-center justify-between text-sm text-muted-foreground"
      >
        <a
          href="https://wpwebhooks.org"
          target="_blank"
          rel="noopener noreferrer"
          class="flow-logo hover:text-foreground transition-colors"
        >
          WP_Webhooks<span class="cursor">█</span>
        </a>
        <span class="text-muted-foreground">⭐ {{ __('Love the plugin?') }} <a
          href="https://wordpress.org/support/plugin/flowsystems-webhook-actions/reviews/#new-post"
          target="_blank"
          rel="noopener noreferrer"
          class="underline underline-offset-2 hover:text-foreground transition-colors"
        >{{ __('Leave a review on WordPress.org') }}</a></span>
      </div>
    </footer>
  </div>
</template>
