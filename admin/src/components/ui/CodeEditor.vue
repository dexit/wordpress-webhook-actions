<script setup>
/**
 * CodeEditor — CodeMirror 6 wrapper with PHP / JSON / template language modes.
 *
 * Props:
 *   modelValue        String        (v-model)
 *   language          'php'|'json'|'template'|'text'   default 'php'
 *   minHeight         Number (px)   default 200
 *   maxHeight         Number (px)   default 0 = unlimited
 *   placeholder       String
 *   readonly          Boolean
 *   completionSources Array<{label,type?,detail?,info?}> extra completions for template/JSON
 *   knownFields       Array<string> flat field paths from test payload (e.g. ['user.name'])
 *                     → auto-builds {{received.body.*}} completions
 */
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import {
  EditorView, lineNumbers, highlightActiveLine,
  highlightActiveLineGutter, keymap,
  placeholder as cmPlaceholder,
} from '@codemirror/view'
import { EditorState } from '@codemirror/state'
import { defaultKeymap, indentWithTab, history, historyKeymap } from '@codemirror/commands'
import {
  foldGutter, indentOnInput, syntaxHighlighting,
  defaultHighlightStyle, bracketMatching,
} from '@codemirror/language'
import { autocompletion, closeBrackets, completeFromList } from '@codemirror/autocomplete'
import { php } from '@codemirror/lang-php'
import { json } from '@codemirror/lang-json'
import { oneDark } from '@codemirror/theme-one-dark'

const props = defineProps({
  modelValue:        { type: String,  default: '' },
  language:          { type: String,  default: 'php' },
  minHeight:         { type: Number,  default: 200 },
  maxHeight:         { type: Number,  default: 0 },
  placeholder:       { type: String,  default: '' },
  readonly:          { type: Boolean, default: false },
  completionSources: { type: Array,   default: () => [] },
  knownFields:       { type: Array,   default: () => [] },
})

const emit = defineEmits(['update:modelValue'])
const container = ref(null)
let view     = null
let updating = false

// ── Static completion tables ────────────────────────────────────────────
const SYSTEM_VARS = [
  { label: 'timestamp',    detail: 'Unix timestamp (int)' },
  { label: 'datetime',     detail: 'Y-m-d H:i:s' },
  { label: 'date',         detail: 'Y-m-d' },
  { label: 'time',         detail: 'H:i:s' },
  { label: 'uuid',         detail: 'Random UUID v4' },
  { label: 'site_url',     detail: 'WordPress site URL' },
  { label: 'home_url',     detail: 'WordPress home URL' },
  { label: 'admin_email',  detail: 'Admin email address' },
  { label: 'blog_name',    detail: 'Blog/site name' },
]

const MODIFIERS = [
  'lower','upper','ucfirst','ucwords','trim','ltrim','rtrim',
  'slug','escape','esc','urlencode','base64','md5','sha256',
  'nl2br','strip_tags','length','reverse',
  'int','float','abs','floor','ceil',
  'round:2','number_format:2',
  'date:Y-m-d','date:Y-m-d H:i:s','strtotime',
  'json','json_pretty',
  'default:fallback','substr:0:10',
  'first','last','count','keys','values','join:,',
]

const STATIC_PATHS = [
  { label: 'received.body.',            detail: 'Request body field',        boost: 3 },
  { label: 'received.query.',           detail: 'URL query parameter',       boost: 2 },
  { label: 'received.headers.',         detail: 'Request header value',      boost: 2 },
  { label: 'received.meta.method',      detail: 'HTTP method (GET/POST…)',   boost: 2 },
  { label: 'received.meta.source_ip',   detail: 'Client IP address',         boost: 2 },
  { label: 'received.meta.received_at', detail: 'ISO 8601 received time',    boost: 2 },
  { label: 'received.meta.endpoint_slug', detail: 'Endpoint slug',           boost: 2 },
  { label: 'payload.',                  detail: 'Alias for received.body.*', boost: 2 },
  { label: 'query.',                    detail: 'Alias for received.query.*', boost: 1 },
  { label: 'headers.',                  detail: 'Alias for received.headers.*', boost: 1 },
  { label: 'dto.',                      detail: 'DTO/ETL pipeline output',   boost: 3 },
]

const PHP_VARS = [
  '$payload','$query','$headers','$dto','$endpoint','$context','$wpdb',
]

// ── Template completions ────────────────────────────────────────────────
const buildTemplateCompletion = () => {
  const makeOptions = () => {
    const opts = []

    for (const v of SYSTEM_VARS) {
      opts.push({ label: v.label, type: 'variable', detail: v.detail, boost: 4 })
    }

    for (const p of STATIC_PATHS) {
      opts.push({ label: p.label, type: 'namespace', detail: p.detail, boost: p.boost })
    }

    for (const f of props.knownFields) {
      opts.push({ label: `received.body.${f}`, type: 'property', detail: 'Payload field', boost: 5 })
      opts.push({ label: `payload.${f}`,        type: 'property', detail: 'Alias',         boost: 4 })
    }

    for (const s of props.completionSources) {
      opts.push({ ...s, boost: s.boost ?? 2 })
    }

    return opts
  }

  const modifierOptions = MODIFIERS.map((m) => ({ label: m, type: 'function', detail: 'modifier' }))

  return autocompletion({
    override: [
      (ctx) => {
        const m = ctx.matchBefore(/\{\{[^}]*/)
        if (!m) return null

        const inner   = m.text.slice(2)
        const pipeIdx = inner.lastIndexOf('|')

        if (pipeIdx >= 0) {
          // Complete modifier after |
          return {
            from: m.from + 2 + pipeIdx + 1,
            options: modifierOptions,
            validFor: /^[\w:]*$/,
          }
        }

        return {
          from: m.from + 2,
          options: makeOptions(),
          validFor: /^[\w.*\[\]_:-]*$/,
        }
      },
    ],
  })
}

// ── PHP autocompletion ──────────────────────────────────────────────────
const buildPhpCompletion = () =>
  autocompletion({
    override: [
      (ctx) => {
        const w = ctx.matchBefore(/\$\w*/)
        if (!w || (w.from === w.to && !ctx.explicit)) return null
        return completeFromList(
          PHP_VARS.map((v) => ({ label: v, type: 'variable', detail: 'FSWA' }))
        )(ctx)
      },
    ],
  })

// ── Language extension ──────────────────────────────────────────────────
const getLanguageExt = () => {
  switch (props.language) {
    case 'json':     return [json(), autocompletion()]
    case 'php':      return [php({ plain: true }), buildPhpCompletion()]
    case 'template':
    case 'text':
    default:         return [buildTemplateCompletion()]
  }
}

// ── Build full extension list ───────────────────────────────────────────
const buildExtensions = () => {
  const exts = [
    oneDark,
    lineNumbers(),
    highlightActiveLine(),
    highlightActiveLineGutter(),
    foldGutter(),
    history(),
    indentOnInput(),
    bracketMatching(),
    closeBrackets(),
    syntaxHighlighting(defaultHighlightStyle, { fallback: true }),
    keymap.of([...defaultKeymap, ...historyKeymap, indentWithTab]),
    EditorView.updateListener.of((update) => {
      if (update.docChanged && !updating) {
        emit('update:modelValue', update.state.doc.toString())
      }
    }),
    EditorView.theme({
      '&': {
        fontSize: '13px',
        fontFamily: "'JetBrains Mono','Fira Code',Consolas,Monaco,monospace",
        borderRadius: '0.375rem',
        minHeight: props.minHeight ? `${props.minHeight}px` : undefined,
        ...(props.maxHeight ? { maxHeight: `${props.maxHeight}px` } : {}),
      },
      '.cm-scroller': { overflow: 'auto', lineHeight: '1.65' },
      '.cm-content': { padding: '12px 0' },
      '.cm-tooltip.cm-tooltip-autocomplete': {
        fontSize: '12px',
        borderRadius: '6px',
      },
    }),
    ...getLanguageExt(),
  ]

  if (props.placeholder) exts.push(cmPlaceholder(props.placeholder))
  if (props.readonly)    exts.push(EditorState.readOnly.of(true))

  return exts
}

// ── Lifecycle ───────────────────────────────────────────────────────────
const createEditor = () => {
  if (!container.value) return
  if (view) { view.destroy(); view = null }
  view = new EditorView({
    state: EditorState.create({ doc: props.modelValue || '', extensions: buildExtensions() }),
    parent: container.value,
  })
}

watch(
  () => props.modelValue,
  (newVal) => {
    if (!view) return
    const cur = view.state.doc.toString()
    if (cur !== newVal) {
      updating = true
      view.dispatch({ changes: { from: 0, to: cur.length, insert: newVal ?? '' } })
      updating = false
    }
  },
)

watch(() => props.language,           createEditor)
watch(() => props.knownFields,        createEditor, { deep: true })
watch(() => props.completionSources,  createEditor, { deep: true })

onMounted(createEditor)
onBeforeUnmount(() => { view?.destroy() })
</script>

<template>
  <div class="code-editor-wrapper rounded-md overflow-hidden border border-input" ref="container" />
</template>

<style scoped>
.code-editor-wrapper :deep(.cm-editor) { width: 100%; }
.code-editor-wrapper :deep(.cm-editor.cm-focused) {
  outline: 2px solid hsl(var(--ring));
  outline-offset: -1px;
}
</style>
