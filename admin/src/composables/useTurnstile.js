import { ref } from 'vue'

/**
 * Loads Cloudflare Turnstile on demand and solves one challenge.
 *
 * Turnstile guards free-trial issuance, which is the only endpoint that hands
 * out credits without a payment. The script is loaded lazily — an admin who
 * never starts a trial should not be making requests to Cloudflare at all.
 *
 * A widget only renders on the hostnames registered against it, and Cloudflare's
 * "Any Hostname" option is Enterprise-only — so on an ordinary customer domain
 * this WILL fail, by design. That is not an error state: the API only requires a
 * solved challenge from Playground, where the hostname is fixed and identities
 * are disposable. Callers should treat a failure as "no token" and let the server
 * decide, rather than blocking the user.
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

/**
 * Development overrides, read from the admin URL.
 *
 * `interaction-only` means a healthy visitor sees nothing at all, which is
 * correct for them and impossible for us: when a challenge misbehaves it fails
 * silently, with no widget, no error callback and nothing in the console. These
 * two knobs are the difference between diagnosing that and guessing at it, and
 * neither weakens anything — appearance is cosmetic, and a token minted against
 * some other sitekey is exactly what siteverify rejects.
 *
 *   ?fswa_turnstile=always            render the widget visibly
 *   ?fswa_turnstile_sitekey=<key>     render a different sitekey, e.g. Cloudflare's
 *                                     3x00000000000000000000FF, which always forces
 *                                     an interactive challenge you can watch. Its
 *                                     token is a dummy and will NOT mint a trial.
 */
function devOverrides() {
  try {
    const q = new URLSearchParams(window.location.search)
    return {
      appearance: q.get('fswa_turnstile') === 'always' ? 'always' : 'interaction-only',
      sitekey: q.get('fswa_turnstile_sitekey') || '',
    }
  } catch {
    return { appearance: 'interaction-only', sitekey: '' }
  }
}

export function useTurnstile(siteKey, action = 'wpwebhooks-ai-trial') {
  const solving = ref(false)
  const error = ref('')

  /**
   * Renders the widget into `container` and resolves with a token. Invisible in
   * normal use. Returns '' when no site key is configured, which the caller
   * sends as-is — the API decides whether an unverified request is acceptable,
   * not the client.
   */
  async function solve(container) {
    const dev = devOverrides()
    const key = dev.sitekey || siteKey

    if (!key) return ''

    solving.value = true
    error.value = ''

    try {
      const turnstile = await loadScript()

      return await new Promise((resolve, reject) => {
        const widgetId = turnstile.render(container, {
          sitekey: key,
          action,
          appearance: dev.appearance,
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
