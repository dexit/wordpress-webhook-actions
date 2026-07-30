<script setup>
import { computed, ref } from 'vue';
import { ChevronRight } from 'lucide-vue-next';
import MarkdownView from '@/components/MarkdownView.vue';
import { __ } from '@/i18n';

// A description on the list can be one line ("Deal create → batch line items")
// or a full page of documentation — headings, tables, blockquotes — especially
// after importing a shared build. Printing the long ones in full turns a list
// row into a wall of text and buries every webhook below it.
//
// So short single-paragraph descriptions render as they always did, and
// anything longer collapses behind its own first line: the row still says what
// the webhook is for, and the full document is one click away.

const props = defineProps({
  source: { type: String, default: '' },
  // Extra classes for the inline (short) rendering, so callers keep their sizing.
  markdownClass: { type: String, default: '' },
});

const open = ref(false);

const text = computed(() => (props.source || '').trim());

// Block-level markdown (a heading, list, table, quote, fence) or a second
// paragraph means this is a document rather than a caption.
const isLong = computed(
  () => text.value.length > 180 || /\n/.test(text.value),
);

// The first line, stripped of markdown, as the collapsed summary.
const summary = computed(() => {
  const first = text.value
    .split('\n')
    .map((line) => line.trim())
    .find(Boolean) || '';

  const plain = first
    .replace(/^#{1,6}\s+/, '')
    .replace(/^>\s*/, '')
    .replace(/^[-*+]\s+/, '')
    .replace(/^\d+\.\s+/, '')
    .replace(/!\[[^\]]*\]\([^)]*\)/g, '')
    .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
    .replace(/[*_`~]/g, '')
    .trim();

  return plain.length > 110 ? `${plain.slice(0, 110)}…` : plain;
});
</script>

<template>
  <MarkdownView v-if="text && !isLong" :source="text" :class="markdownClass" />

  <div v-else-if="text" class="mb-2">
    <button
      type="button"
      @click="open = !open"
      class="flex w-full items-start gap-1 text-left text-xs text-muted-foreground hover:text-foreground transition-colors"
      :aria-expanded="open"
    >
      <ChevronRight
        class="h-3 w-3 shrink-0 mt-[0.15rem] transition-transform"
        :class="open && 'rotate-90'"
      />
      <span :class="open ? 'font-medium' : 'truncate'">
        {{ open ? __('Hide description') : summary }}
      </span>
    </button>

    <MarkdownView v-if="open" :source="text" :class="['mt-1', markdownClass]" />
  </div>
</template>
