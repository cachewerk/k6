export const screeningProfiles = {
    'split-off': {
        'X-Dropin': 'ocp',
        'X-OCP-Split-Alloptions': false,
    },
    'split-on': {
        'X-Dropin': 'ocp',
        'X-OCP-Split-Alloptions': true,
    },
    'prefetch-off': {
        'X-Dropin': 'ocp',
        'X-OCP-Prefetch': false,
    },
    'prefetch-on': {
        'X-Dropin': 'ocp',
        'X-OCP-Prefetch': true,
    },
    'client-phpredis': {
        'X-Dropin': 'ocp',
        'X-OCP-Client': 'phpredis',
    },
    'client-relay': {
        'X-Dropin': 'ocp',
        'X-OCP-Client': 'relay',
    },
    'client-relay-adaptive': {
        'X-Dropin': 'ocp',
        'X-OCP-Client': 'relay',
        'X-OCP-Relay-Adaptive': true,
    },
    'php-lz4': {
        'X-Dropin': 'ocp',
        'X-OCP-Compression': 'lz4',
    },
    'php-zstd': {
        'X-Dropin': 'ocp',
        'X-OCP-Compression': 'zstd',
    },
    'igbinary-lz4': {
        'X-Dropin': 'ocp',
        'X-OCP-Serializer': 'igbinary',
        'X-OCP-Compression': 'lz4',
    },
    'igbinary-zstd': {
        'X-Dropin': 'ocp',
        'X-OCP-Serializer': 'igbinary',
        'X-OCP-Compression': 'zstd',
    },
}
