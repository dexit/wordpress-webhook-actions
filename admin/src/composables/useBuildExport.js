import api from '@/lib/api'

/**
 * Client-side build export: calls the REST export endpoint and streams the
 * returned document to the browser as a downloaded JSON file. Shared by the
 * Export dialog (multi-select / everything) and the per-item quick-export
 * buttons on the webhooks list. Callers own their own loading/error state.
 */
const slugify = (s) =>
  String(s || '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 40)

// Local-time export stamp, filename-safe (no colons): YYYY-MM-DD_HHMMSS.
const timestamp = () => {
  const d = new Date()
  const pad = (n) => String(n).padStart(2, '0')
  return (
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` +
    `_${pad(d.getHours())}${pad(d.getMinutes())}${pad(d.getSeconds())}`
  )
}

export function useBuildExport() {
  const exportBuild = async (payload, filenameHint = '') => {
    const doc = await api.builds.export(payload)
    const blob = new Blob([JSON.stringify(doc, null, 2)], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const a = window.document.createElement('a')
    const hint = slugify(filenameHint)
    a.href = url
    a.download = `webhook-actions-${hint ? `${hint}-` : ''}${timestamp()}.json`
    window.document.body.appendChild(a)
    a.click()
    window.document.body.removeChild(a)
    URL.revokeObjectURL(url)
  }

  return { exportBuild }
}
