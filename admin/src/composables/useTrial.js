import { api } from '@/lib/api'
import { useTurnstile } from '@/composables/useTurnstile'

/**
 * Mints the anonymous free trial.
 *
 * Deliberately not a button. Build with AI's whole problem was that a new
 * install had to go somewhere else and fetch an API key before it could see
 * anything happen; replacing that with a different thing to click first is the
 * same problem wearing a hat. So the trial is claimed lazily, on the first
 * prompt someone actually sends, and the only person who ever sees a challenge
 * is a Playground visitor — where the widget can render and identities are free.
 *
 * Two legs by design: the first call carries no token and hands back the
 * challenge config, the browser solves it, the second call mints. That keeps the
 * Turnstile site key out of every ordinary status render.
 *
 * @returns {Promise<object>} the fresh transport status
 */
export async function claimTrial(challengeContainer = null) {
  const first = await api.agent.startTrial({})

  // Already had one, or the server minted without a challenge.
  if (!first?.needs_challenge) return first

  // Try the challenge, but never let it block. A widget only renders on the
  // hostnames registered with Cloudflare, so on an ordinary customer domain this
  // fails by design — and the API only *requires* a token from Playground, where
  // the hostname is fixed. Everywhere else the per-IP throttle,
  // one-trial-per-site and the global daily cap carry the load.
  let token = ''

  if (first.site_key && challengeContainer) {
    try {
      token = await useTurnstile(first.site_key).solve(challengeContainer)
    } catch {
      token = ''
    }
  }

  // `challenge` marks the second leg, so an empty token cannot be read as another
  // token-less first call and answered with needs_challenge — which the caller
  // would then hand on as if it were a transport status.
  const second = await api.agent.startTrial({ turnstile_token: token, challenge: 1 })

  if (second?.needs_challenge) {
    throw new Error('challenge_failed')
  }

  return second
}
