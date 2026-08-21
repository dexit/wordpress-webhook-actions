import { ref } from 'vue'

/**
 * Loads Cloudflare Turnstile on demand and solves one challenge.
 *
 * Turnstile guards free-trial issuance, which is the only endpoint that hands
 * out credits without a payment. The script is loaded lazily — an admin who
 * never starts a trial should not be making requests to Cloudflare at all.
 *
 * Failure is reported, never swallowed: the API fails closed, so a silent
 * script-load failure would surface as a confusing "could not verify" error
 * later instead of an honest "the challenge could not load" here. That matters
 * most inside WordPress Playground, where an external script may be blocked.
 */
const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'

let scriptPromise = null

function loadScript() {
  if (window.turnstile) return Promise.resolve(window.turnstile)
  if (scriptPromise) return scriptPromise

  scriptPromise = new Promise((resolve, reject) => {
    const el = document.createElement('script')
    el.src = SCRIPT_URL
    el.async = true
    el.defer = true
    el.onload = () =>
      window.turnstile
        ? resolve(window.turnstile)
        : reject(new Error('Turnstile loaded but did not register.'))
    el.onerror = () => {
      // Let a later attempt retry rather than caching the failure forever.
      scriptPromise = null
      reject(new Error('Could not load the Cloudflare challenge.'))
    }
    document.head.appendChild(el)
  })

  return scriptPromise
}

export function useTurnstile(siteKey) {
  const solving = ref(false)
  const error = ref('')

  /**
   * Renders an invisible widget into `container` and resolves with a token.
   * Returns '' when no site key is configured, which the caller sends as-is —
   * the API decides whether an unverified request is acceptable, not the client.
   */
  async function solve(container) {
    if (!siteKey) return ''

    solving.value = true
    error.value = ''

    try {
      const turnstile = await loadScript()

      return await new Promise((resolve, reject) => {
        const widgetId = turnstile.render(container, {
          sitekey: siteKey,
          appearance: 'interaction-only',
          callback: (token) => {
            turnstile.remove(widgetId)
            resolve(token)
          },
          'error-callback': () => {
            turnstile.remove(widgetId)
            reject(new Error('The challenge could not be completed.'))
          },
          'expired-callback': () => {
            turnstile.remove(widgetId)
            reject(new Error('The challenge expired. Please try again.'))
          },
        })
      })
    } catch (e) {
      error.value = e.message
      throw e
    } finally {
      solving.value = false
    }
  }

  return { solve, solving, error }
}
