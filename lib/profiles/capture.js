const token = __ENV.OCP_TOKEN || ''

export const captureProfiles = {
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
}
