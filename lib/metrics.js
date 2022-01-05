import { Rate, Trend, Gauge } from 'k6/metrics'

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
            'sql-queries': new Trend('wp_sql_queries'),
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
            'redis-commands-total': new Trend('redis_commands_total'),
            'redis-usec-total': new Trend('redis_usec_total'),
            //'redis-hits': new Trend('redis_hits'),
            //'redis-hit-ratio': new Trend('redis_hit_ratio'),
            'redis-ops-per-sec': new Trend('redis_ops_per_sec'),
            //'redis-memory-fragmentation-ratio': new Trend('redis_memory_fragmentation_ratio'),
            'redis-keys': new Gauge('redis_keys'),
            'redis-key-size': new Gauge('redis_key_size'),
            'cached-keys': new Gauge('cached_keys'),
            'cached-key-size': new Gauge('cached_key_size'),

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

    addRedisTotals(calls, usec) {
        this.metrics['redis-commands-total'].add(calls)
        this.metrics['redis-usec-total'].add(usec)
    }

    addRedisKeyStats(stats) {
        if (!stats.total || !stats.cached) return;

        this.metrics['redis-keys'].add(stats.total.keys)
        this.metrics['redis-key-size'].add(stats.total.size)
        this.metrics['cached-keys'].add(stats.cached.keys)
        this.metrics['cached-key-size'].add(stats.cached.size)
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

        if (! comment || ! comment.length) {
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
