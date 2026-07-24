<script setup>
import { computed } from 'vue'
import { marked } from 'marked'
import DOMPurify from 'dompurify'

const props = defineProps({
  source: { type: String, default: '' },
})

// Render markdown, then sanitize the resulting HTML. The parse-then-sanitize
// order mirrors the escape-first XSS posture used elsewhere in the SPA
// (ChatMarkdown.vue): nothing reaches v-html without passing through DOMPurify.
const html = computed(() => {
  const src = (props.source || '').trim()
  if (!src) return ''
  const rendered = marked.parse(src, { breaks: true, gfm: true })
  return DOMPurify.sanitize(rendered, { USE_PROFILES: { html: true } })
})
</script>

<template>
  <div v-if="html" class="fswa-markdown text-sm text-muted-foreground" v-html="html" />
</template>

<style scoped>
.fswa-markdown :deep(h1),
.fswa-markdown :deep(h2),
.fswa-markdown :deep(h3) {
  font-weight: 600;
  color: hsl(var(--foreground));
  margin: 0.5em 0 0.25em;
}
.fswa-markdown :deep(h1) { font-size: 1.1rem; }
.fswa-markdown :deep(h2) { font-size: 1.05rem; }
.fswa-markdown :deep(h3) { font-size: 1rem; }
.fswa-markdown :deep(p) { margin: 0.35em 0; }
.fswa-markdown :deep(ul),
.fswa-markdown :deep(ol) { margin: 0.35em 0; padding-left: 1.25em; }
.fswa-markdown :deep(ul) { list-style: disc; }
.fswa-markdown :deep(ol) { list-style: decimal; }
.fswa-markdown :deep(a) { color: hsl(var(--primary)); text-decoration: underline; }
.fswa-markdown :deep(code) {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.85em;
  background: hsl(var(--muted));
  padding: 0.1em 0.3em;
  border-radius: 3px;
}
.fswa-markdown :deep(pre) {
  background: hsl(var(--muted));
  padding: 0.6em 0.75em;
  border-radius: 6px;
  overflow-x: auto;
  margin: 0.5em 0;
}
.fswa-markdown :deep(pre code) { background: none; padding: 0; }
.fswa-markdown :deep(blockquote) {
  border-left: 3px solid hsl(var(--border));
  padding-left: 0.75em;
  margin: 0.5em 0;
  color: hsl(var(--muted-foreground));
}
.fswa-markdown :deep(table) { border-collapse: collapse; margin: 0.5em 0; display: block; overflow-x: auto; }
.fswa-markdown :deep(th),
.fswa-markdown :deep(td) { border: 1px solid hsl(var(--border)); padding: 0.3em 0.5em; text-align: left; }
</style>
