import { Rate, Trend } from 'k6/metrics'

export default class Metrics {
    constructor () {
        this.metrics = {
            /**
             * WordPress
             */
            'hits': new Trend('wp_hits'),
            // 'misses': new Trend('wp_misses'),
            'hit-ratio': new Trend('wp_hit_ratio'),
            // 'bytes': new Trend('wp_bytes'),
            // 'prefetches': new Trend('wp_prefetches'),
            'store-reads': new Trend('wp_store_reads'),
            'store-writes': new Trend('wp_store_writes'),
            // 'store-hits': new Trend('wp_store_hits'),
            // 'store-misses': new Trend('wp_store_misses'),
            // 'ms-total': new Trend('wp_ms_total', true),
            'ms-cache': new Trend('wp_ms_cache', true),
            'ms-cache-median': new Trend('wp_ms_cache_median', true),
            'ms-cache-ratio': new Trend('wp_ms_cache_ratio'),

            /**
             * Client
             */
            'using-relay': new Rate('x_using_relay'),
            'using-phpredis': new Rate('x_using_phpredis'),

            /**
             * Redis
             */
            'redis-hits': new Trend('redis_hits'),
            // 'redis-misses': new Trend('redis_misses'),
            'redis-hit-ratio': new Trend('redis_hit_ratio'),
            'redis-ops-per-sec': new Trend('redis_ops_per_sec'),
            // 'redis-evicted-keys': new Trend('redis_evicted_keys'),
            // 'redis-used-memory': new Trend('redis_used_memory'),
            // 'redis-used-memory-rss': new Trend('redis_used_memory_rss'),
            'redis-memory-fragmentation-ratio': new Trend('redis_memory_fragmentation_ratio'),
            // 'redis-connected-clients': new Trend('redis_connected_clients'),
            // 'redis-tracking-clients': new Trend('redis_tracking_clients'),
            // 'redis-rejected-connections': new Trend('redis_rejected_connections'),
            'redis-keys': new Trend('redis_keys'),

            /**
             * Relay
             */
            // 'relay-hits': new Trend('relay_hits'),
            // 'relay-misses': new Trend('relay_misses'),
            'relay-hit-ratio': new Trend('relay_hit_ratio'),
            'relay-ops-per-sec': new Trend('relay_ops_per_sec'),
            'relay-keys': new Trend('relay_keys'),
            'relay-memory-active': new Trend('relay_memory_active'),
            // 'relay-memory-total': new Trend('relay_memory_total'),
            'relay-memory-ratio': new Trend('relay_memory_ratio'),
        }
    }

    addResponseMetrics (response) {
        const metrics = this.parseMetricsFromResponse(response)

        if (! metrics) {
            return;
        }

        for (const metric in metrics) {
            if (metric in this.metrics) {
                this.metrics[metric].add(metrics[metric])
            }
        }
    }

    parseMetricsFromResponse (response) {
        if (! response.body) {
            return false
        }

        const comment = response.body.match(/<!-- plugin=object-cache-pro (.+?) -->/g)

        if (! comment.length) {
            return false
        }

        const metrics = [...comment[0].matchAll(/(metric|sample)#([\w-]+)=([\d.]+)/g)]
            .reduce(function (map, metric) {
                map[metric[2]] = metric[3]

                return map
            }, {})

        metrics['using-relay'] = comment[0].includes('client=relay')
        metrics['using-phpredis'] = comment[0].includes('client=phpredis')

        return metrics
    }
}
