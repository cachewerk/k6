import exec from 'k6/execution'

/**
 * Supported request headers
 *
 * X-Dropin            — ocp, roc, none
 *
 * X-OCP-Token         — Object Cache Pro license token
 * X-OCP-Client        — relay, phpredis, predis
 * X-OCP-Compression   — zstd, lz4, lzf, none
 * X-OCP-Serializer    — igbinary, msgpack, php
 * X-OCP-Group-Flush   — atomic, scan
 * X-OCP-Prefetch      — true, false
 * X-OCP-Split-Alloptions — true, false
 *
 * X-ROC-Client        — phpredis, predis, relay, credis
 */

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

    // HAR capture profiles (1–3)
    // For read-only (frontend) captures via har-replay.js.
    // Each isolates one variable that changes the Redis command stream.
    // Warm the site before running capture-3 so prefetch has a prior-request key list to load.
    'capture-1': {
        // Baseline: single alloptions GET, no prefetch
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Prefetch': 'false',
        'X-OCP-Split-Alloptions': 'false',
        'X-OCP-Group-Flush': 'scan',
    },
    'capture-2': {
        // Hash alloptions: lazy HGET/HMGET on a hash instead of one GET
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Prefetch': 'false',
        'X-OCP-Split-Alloptions': 'true',
        'X-OCP-Group-Flush': 'scan',
    },
    'capture-3': {
        // Prefetch: batched key preload at request start (warm run required first)
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Prefetch': 'true',
        'X-OCP-Split-Alloptions': 'false',
        'X-OCP-Group-Flush': 'scan',
    },

    // Corpus capture profiles (A–H)
    // Vary prefetch, split_alloptions, group_flush — the knobs that change the Redis command trace.
    // Use with stubs/k6-capture.php. Serializer/compression are omitted (irrelevant at capture time).
    'corpus-a': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Prefetch': 'false',
        'X-OCP-Split-Alloptions': 'false',
        'X-OCP-Group-Flush': 'scan',
    },
    'corpus-b': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Prefetch': 'false',
        'X-OCP-Split-Alloptions': 'false',
        'X-OCP-Group-Flush': 'atomic',
    },
    'corpus-c': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Prefetch': 'false',
        'X-OCP-Split-Alloptions': 'true',
        'X-OCP-Group-Flush': 'scan',
    },
    'corpus-d': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Prefetch': 'false',
        'X-OCP-Split-Alloptions': 'true',
        'X-OCP-Group-Flush': 'atomic',
    },
    'corpus-e': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Prefetch': 'true',
        'X-OCP-Split-Alloptions': 'false',
        'X-OCP-Group-Flush': 'scan',
    },
    'corpus-f': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Prefetch': 'true',
        'X-OCP-Split-Alloptions': 'false',
        'X-OCP-Group-Flush': 'atomic',
    },
    'corpus-g': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Prefetch': 'true',
        'X-OCP-Split-Alloptions': 'true',
        'X-OCP-Group-Flush': 'scan',
    },
    'corpus-h': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Prefetch': 'true',
        'X-OCP-Split-Alloptions': 'true',
        'X-OCP-Group-Flush': 'atomic',
    },

    // Replay test configs (R0–R7)
    // Vary serializer and compression — applied on top of a corpus profile.
    // relay.adaptive is not a request header; set REPLAY_RELAY_ADAPTIVE=on/off separately.
    // R0/R1 differ only in relay.adaptive (off/on); same for R2/R3, R4/R5, R6/R7.
    'replay-r0': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Serializer': 'php',
        'X-OCP-Compression': 'none',
    },
    'replay-r1': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Serializer': 'php',
        'X-OCP-Compression': 'none',
        // relay.adaptive: on — set REPLAY_RELAY_ADAPTIVE=on
    },
    'replay-r2': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Serializer': 'php',
        'X-OCP-Compression': 'lz4',
    },
    'replay-r3': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Serializer': 'php',
        'X-OCP-Compression': 'lz4',
        // relay.adaptive: on — set REPLAY_RELAY_ADAPTIVE=on
    },
    'replay-r4': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Serializer': 'igbinary',
        'X-OCP-Compression': 'none',
    },
    'replay-r5': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Serializer': 'igbinary',
        'X-OCP-Compression': 'none',
        // relay.adaptive: on — set REPLAY_RELAY_ADAPTIVE=on
    },
    'replay-r6': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Serializer': 'igbinary',
        'X-OCP-Compression': 'lz4',
    },
    'replay-r7': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Serializer': 'igbinary',
        'X-OCP-Compression': 'lz4',
        // relay.adaptive: on — set REPLAY_RELAY_ADAPTIVE=on
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
