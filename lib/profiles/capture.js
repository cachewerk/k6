export const captureProfiles = {
    'capture-baseline': {
        'X-Dropin': 'ocp',
        'X-OCP-Client': 'phpredis',
        'X-OCP-Group-Flush': 'scan',
        'X-OCP-Prefetch': false,
    },
    'capture-hfe': {
        'X-Dropin': 'ocp',
        'X-OCP-Client': 'phpredis',
        'X-OCP-Group-Flush': 'atomic',
        'X-OCP-Prefetch': false,
    },
    // use warm cache for capture
    'capture-prefetch': {
        'X-Dropin': 'ocp',
        'X-OCP-Client': 'phpredis',
        'X-OCP-Group-Flush': 'scan',
        'X-OCP-Split-Alloptions': false,
        'X-OCP-Prefetch': true,
    },
}
