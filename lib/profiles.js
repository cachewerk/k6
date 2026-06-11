import exec from 'k6/execution'
import { captureProfiles } from './profiles/capture.js'
import { screeningProfiles } from './profiles/screening.js'

export const profiles = {
    'none': {
        'X-Dropin': 'none',
    },
    'ocp-relay': {
        'X-Dropin': 'ocp',
        'X-OCP-Client': 'relay',
    },
    'ocp-phpredis': {
        'X-Dropin': 'ocp',
    },
    'roc-phpredis': {
        'X-Dropin': 'roc',
        'X-ROC-Client': 'phpredis',
    },
    'roc-relay': {
        'X-Dropin': 'roc',
        'X-ROC-Client': 'relay',
    },

    ...captureProfiles,
    ...screeningProfiles,
}

export function withProfile (params = {}) {
    const name = __ENV.PROFILE

    if (! name) {
        return params
    }

    if (! (name in profiles)) {
        exec.test.abort(`Unknown benchmark profile "${name}". Valid profiles: ${Object.keys(profiles).join(', ')}`)
    }

    const headers = {}

    for (const [key, value] of Object.entries(profiles[name])) {
        headers[key] = String(value)
    }

    // Merge over any caller-supplied headers (e.g. a HAR entry's recorded
    // headers, including its Cookie); profile headers win on conflict.
    return { ...params, headers: { ...(params.headers || {}), ...headers } }
}
