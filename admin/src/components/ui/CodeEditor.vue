<script setup>
/**
 * CodeEditor — CodeMirror 6 wrapper with PHP / JSON / template language modes.
 *
 * Props:
 *   modelValue  String       (v-model)
 *   language    'php'|'json'|'template'|'text'   default 'php'
 *   minHeight   Number (px)  default 200
 *   maxHeight   Number (px)  default 0 = unlimited
 *   placeholder String
 *   readonly    Boolean
 */
import { ref, watch, onMounted, onBeforeUnmount, computed } from 'vue'
import { EditorView, lineNumbers, highlightActiveLine, highlightActiveLineGutter, keymap, placeholder as cmPlaceholder } from '@codemirror/view'
import { EditorState } from '@codemirror/state'
import { defaultKeymap, indentWithTab, history, historyKeymap } from '@codemirror/commands'
import { foldGutter, indentOnInput, syntaxHighlighting, defaultHighlightStyle, bracketMatching } from '@codemirror/language'
import { autocompletion, closeBrackets } from '@codemirror/autocomplete'
import { php } from '@codemirror/lang-php'
import { json } from '@codemirror/lang-json'
import { oneDark } from '@codemirror/theme-one-dark'

const props = defineProps({
  modelValue:  { type: String, default: '' },
  language:    { type: String, default: 'php' },   // 'php' | 'json' | 'text' | 'template'
  minHeight:   { type: Number, default: 200 },
  maxHeight:   { type: Number, default: 0 },
  placeholder: { type: String, default: '' },
  readonly:    { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const container = ref(null)
let view = null
let updating = false  // prevents recursive update cycles

// ── Language extension ────────────────────────────────────────────────────
const getLanguageExt = () => {
  switch (props.language) {
    case 'json':     return [json()]
    case 'php':      return [php({ plain: true })]  // plain = no wrapping tags required
    case 'template':
    case 'text':
    default:         return []
  }
}

// ── Editor creation ───────────────────────────────────────────────────────
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
    autocompletion(),
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
        fontFamily: "'JetBrains Mono', 'Fira Code', Consolas, Monaco, monospace",
        borderRadius: '0.375rem',
        minHeight: props.minHeight ? `${props.minHeight}px` : undefined,
        ...(props.maxHeight ? { maxHeight: `${props.maxHeight}px` } : {}),
      },
      '.cm-scroller': {
        overflow: 'auto',
        lineHeight: '1.65',
      },
      '.cm-content': {
        padding: '12px 0',
      },
    }),
    ...getLanguageExt(),
  ]

  if (props.placeholder) {
    exts.push(cmPlaceholder(props.placeholder))
  }

  if (props.readonly) {
    exts.push(EditorState.readOnly.of(true))
  }

  return exts
}

const createEditor = () => {
  if (!container.value) return
  if (view) {
    view.destroy()
    view = null
  }

  view = new EditorView({
    state: EditorState.create({
      doc: props.modelValue || '',
      extensions: buildExtensions(),
    }),
    parent: container.value,
  })
}

// ── Sync external modelValue → editor ────────────────────────────────────
watch(
  () => props.modelValue,
  (newVal) => {
    if (!view) return
    const current = view.state.doc.toString()
    if (current !== newVal) {
      updating = true
      view.dispatch({
        changes: { from: 0, to: current.length, insert: newVal ?? '' },
      })
      updating = false
    }
  },
)

// ── Recreate when language changes ────────────────────────────────────────
watch(() => props.language, createEditor)

onMounted(createEditor)
onBeforeUnmount(() => { view?.destroy() })
</script>

<template>
  <div class="code-editor-wrapper rounded-md overflow-hidden border border-input" ref="container" />
</template>

<style scoped>
.code-editor-wrapper :deep(.cm-editor) {
  width: 100%;
}
.code-editor-wrapper :deep(.cm-editor.cm-focused) {
  outline: 2px solid hsl(var(--ring));
  outline-offset: -1px;
}
</style>
