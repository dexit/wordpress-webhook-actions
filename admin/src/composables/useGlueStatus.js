import { ref } from 'vue'
import api from '@/lib/api'

const STORAGE_KEY = 'fswa_glue_notice_dismissed'

// Shared across every view that asks, so the banner and the Settings screen
// never disagree and the answer is fetched once per page load.
const status = ref({ can_write: true, reason: '', message: '', fixable_in_settings: false })
const dismissed = ref(localStorage.getItem(STORAGE_KEY) === '1')
let fetched = false

const refresh = () => {
  fetched = true
  return api.settings.get()
    .then((data) => { if (data?.glue) status.value = data.glue })
    .catch(() => {})
}

const dismiss = () => {
  dismissed.value = true
  try { localStorage.setItem(STORAGE_KEY, '1') } catch { /* private mode */ }
}

export function useGlueStatus() {
  if (!fetched) refresh()
  return { glue: status, dismissed, dismiss, refresh }
}
