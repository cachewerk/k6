import exec from 'k6/execution'
import { captureProfiles } from './profiles/capture.js'
import { benchmarkProfiles } from './profiles/benchmark.js'

const token = __ENV.OCP_TOKEN || ''

export const profiles = {
    'none': {
        'X-Dropin': 'none',
    },
    'ocp-relay': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Client': 'relay',
    },
    'ocp-phpredis': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
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
    ...benchmarkProfiles,

    // Corpus capture profiles (A–B)
    // Vary group_flush — the knob that changes the Redis command trace on writes.
    // Use with stubs/k6-capture.php. prefetch and split_alloptions are benched separately
    // (see benchmark.js → prefetch-on/off, split-on/off).
    'corpus-a': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Group-Flush': 'scan',
    },
    'corpus-b': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Group-Flush': 'atomic',
    },
}

export function withProfile (params = {}) {
    const name = __ENV.PROFILE

    if (! name) {
        return params
    }

    if (! (name in profiles)) {
        exec.test.abort(`Unknown benchmark profile "${name}". Valid profiles: ${Object.keys(profiles).join(', ')}`)
    }

    return { ...params, headers: profiles[name] }
}
