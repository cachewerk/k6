const token = __ENV.OCP_TOKEN || ''

export const benchmarkProfiles = {
    'split-off': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Split-Alloptions': 'false',
    },
    'split-on': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Split-Alloptions': 'true',
    },
    'prefetch-off': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Prefetch': 'false',
    },
    'prefetch-on': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Prefetch': 'true',
    },
    'client-phpredis': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Client': 'phpredis',
    },
    'client-relay': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Client': 'relay',
    },
    'client-relay-adaptive': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Client': 'relay',
        'X-OCP-Relay-Adaptive': 'on',
    },
    'php-lz4': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Serializer': 'php',
        'X-OCP-Compression': 'lz4',
    },
    'php-zstd': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Serializer': 'php',
        'X-OCP-Compression': 'zstd',
    },
    'igbinary-lz4': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Serializer': 'igbinary',
        'X-OCP-Compression': 'lz4',
    },
    'igbinary-zstd': {
        'X-Dropin': 'ocp',
        'X-OCP-Token': token,
        'X-OCP-Serializer': 'igbinary',
        'X-OCP-Compression': 'zstd',
    },
}
