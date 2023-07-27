<?php
/*
 * Plugin Name: CacheWerk k6 (MU)
 * Plugin URI: https://github.com/cachewerk/k6
 * Description: ...
 * Version: 1.0.0
 * Author: Rhubarb Group
 * Author URI: https://rhubarb.group
 * Requires PHP: 7.2
 */

defined('ABSPATH') || exit;

add_action('muplugins_loaded', ['k6ObjectCacheMetrics', 'init']);

add_action('wp_footer', ['k6ObjectCacheMetrics', 'shouldPrint']);
add_action('wp_body_open', ['k6ObjectCacheMetrics', 'shouldPrint']);
add_action('login_head', ['k6ObjectCacheMetrics', 'shouldPrint']);
add_action('in_admin_header', ['k6ObjectCacheMetrics', 'shouldPrint']);
add_action('rss_tag_pre', ['k6ObjectCacheMetrics', 'shouldPrint']);

add_action('shutdown', ['k6ObjectCacheMetrics', 'maybePrint'], PHP_INT_MAX);

class k6ObjectCacheMetrics
{
    const None = 'none';

    const ObjectCachePro = 'object-cache-pro';

    const RedisObjectCache = 'redis-cache';

    const WpRedis = 'wp-redis';

    const LiteSpeedCache = 'litespeed-cache';

    protected static $cache;

    protected static $client;

    public static function init(): void
    {
        global $wp_object_cache;

        if (! wp_using_ext_object_cache()) {
            self::$cache = self::None;
            self::$client = 'array';
        }

        $class = get_class($wp_object_cache);

        if (strpos($class, 'RedisCachePro') === 0) {
            self::$cache = self::ObjectCachePro;
            self::$client = strtolower($wp_object_cache->clientName());
        }

        if (method_exists($class, 'connect_using_relay') && method_exists($class, 'redis_instance')) {
            self::$cache = self::RedisObjectCache;

            $instance = $wp_object_cache->redis_instance();

            $client = 'predis';

            if ($instance instanceof \Redis) {
                $client = 'phpredis';
            }

            if ($instance instanceof \Relay\Relay) {
                $client = 'relay';
            }

            self::$client = $client;
        }

        if (method_exists($class, '_connect_redis') && method_exists($class, '_call_redis')) {
            self::$cache = self::WpRedis;
            self::$client = 'phpredis';
        }

        if (property_exists($wp_object_cache, '_object_cache') && $wp_object_cache->_object_cache instanceof \LiteSpeed\Object_Cache) {
            self::$cache = self::LiteSpeedCache;
            self::$client = 'phpredis';
        }
    }

    public static function maybePrint(): void
    {
        if (! self::$cache) {
            return;
        }

        if (! self::shouldPrint()) {
            return;
        }

        if (is_robots() || is_trackback()) {
            return;
        }

        if (
            (defined('\WP_CLI') && constant('\WP_CLI')) ||
            (defined('\REST_REQUEST') && constant('\REST_REQUEST')) ||
            (defined('\XMLRPC_REQUEST') && constant('\XMLRPC_REQUEST')) ||
            (defined('\DOING_AJAX') && constant('\DOING_AJAX')) ||
            (defined('\DOING_CRON') && constant('\DOING_CRON')) ||
            (defined('\DOING_AUTOSAVE') && constant('\DOING_AUTOSAVE')) ||
            (function_exists('wp_is_json_request') && wp_is_json_request()) ||
            (function_exists('wp_is_jsonp_request') && wp_is_jsonp_request())
        ) {
            return;
        }

        if (self::incompatibleContentType()) {
            return;
        }

        printf(
            "\n<!-- plugin=%s client=%s %s -->\n",
            self::$cache,
            self::$client,
            self::getMetrics()
        );
    }

    public static function shouldPrint()
    {
        static $shouldPrint;

        if (doing_action('shutdown')) {
            return $shouldPrint;
        }

        $shouldPrint = true;
    }

    protected static function incompatibleContentType(): bool
    {
        $jsonContentType = static function ($headers) {
            foreach ($headers as $header => $value) {
                if (stripos((string)$header, 'content-type') === false) {
                    continue;
                }

                if (stripos((string)$value, '/json') === false) {
                    continue;
                }

                return true;
            }

            return false;
        };

        if (function_exists('headers_list')) {
            $headers = [];

            foreach (headers_list() as $header) {
                [$name, $value] = explode(':', $header);
                $headers[$name] = $value;
            }

            if ($jsonContentType($headers)) {
                return true;
            }
        }

        if (function_exists('apache_response_headers')) {
            if ($headers = apache_response_headers()) {
                return $jsonContentType($headers);
            }
        }

        return false;
    }

    protected static function getMetrics(): string
    {
        switch (self::$cache) {
            case self::ObjectCachePro:
                return self::getObjectCacheProMetrics();
            case self::RedisObjectCache:
                return self::getRedisObjectCacheMetrics();
            case self::WpRedis:
                return self::getWpRedisMetrics();
            case self::LiteSpeedCache:
                return self::getLiteSpeedCacheMetrics();
            default:
                return '';
        }
    }

    protected static function getObjectCacheProMetrics(): string
    {
        global $wp_object_cache;

        return $wp_object_cache->requestMeasurement();
    }

    protected static function getRedisObjectCacheMetrics(): string
    {
        global $wp_object_cache;

        $info = $wp_object_cache->info();

        $stats = $wp_object_cache->redis_instance() instanceof \Relay\Relay
            ? $wp_object_cache->redis_instance()->stats()
            : [];

        return self::buildMetrics(
            $info->hits,
            $info->misses,
            $info->ratio,
            $info->bytes,
            self::mapRedisInfo(
                $wp_object_cache->redis_instance()->info(),
                $wp_object_cache->diagnostics['database'] ?? 0
            ),
            self::mapRelayStats(
                $stats,
                $wp_object_cache->diagnostics['database'] ?? 0
            )
        );
    }

    protected static function getWpRedisMetrics(): string
    {
        global $wp_object_cache;

        return self::buildMetrics(
            $wp_object_cache->cache_hits,
            $wp_object_cache->cache_misses,
            self::calculateHitRatio($wp_object_cache->cache_hits, $wp_object_cache->cache_misses),
            self::calculateBytes($wp_object_cache->cache),
            self::mapRedisInfo(
                $wp_object_cache->redis->info(),
                $wp_object_cache->redis->getDBNum() ?? 0
            )
        );
    }

    protected static function getLiteSpeedCacheMetrics(): string
    {
        global $wp_object_cache;

        $hits = $wp_object_cache->count_hit_incall + $wp_object_cache->count_hit;
        $misses = $wp_object_cache->count_miss_incall + $wp_object_cache->count_miss;

        $accessConnProperty = Closure::bind(function ($object) {
            return $object->_conn;
        }, null, $wp_object_cache->_object_cache);

        $redis = $accessConnProperty($wp_object_cache->_object_cache);

        return self::buildMetrics(
            $hits,
            $misses,
            self::calculateHitRatio($hits, $misses),
            self::calculateBytes($wp_object_cache->_cache),
            self::mapRedisInfo(
                $redis->info(),
                $redis->getDBNum() ?? 0
            )
        );
    }

    protected static function buildMetrics(int $hits, int $misses, float $ratio, $bytes, array $redisSample = [], array $relaySample = []): string
    {
        global $timestart;

        $requestStart = $_SERVER['REQUEST_TIME_FLOAT'] ?? $timestart;

        $metrics = sprintf(
            'metric#hits=%d metric#misses=%d metric#hit-ratio=%s metric#bytes=%d metric#sql-queries=%d metric#ms-total=%s',
            $hits,
            $misses,
            $ratio,
            $bytes,
            function_exists('\get_num_queries') ? \get_num_queries() : null,
            $requestStart ? round((microtime(true) - $requestStart) * 1000, 2) : null
        );

        if ($redisSample) {
            $metrics .= ' ' . implode(' ', array_map(static function ($metric, $value) {
                return "sample#{$metric}={$value}";
            }, array_keys($redisSample), $redisSample));
        }

        if ($relaySample) {
            $metrics .= ' ' . implode(' ', array_map(static function ($metric, $value) {
                return "sample#{$metric}={$value}";
            }, array_keys($relaySample), $relaySample));
        }

        return $metrics;
    }

    protected static function calculateHitRatio(int $hits, int $misses): float
    {
        $total = $hits + $misses;

        return $total > 0 ? round($hits / ($total / 100), 1) : 100;
    }

    protected static function calculateBytes(array $cache)
    {
        $bytes = array_map(function ($keys) {
            return strlen(serialize($keys));
        }, $cache);

        return array_sum($bytes);
    }

    protected static function mapRedisInfo(array $info, int $database): array
    {
        $total = intval($info['keyspace_hits'] + $info['keyspace_misses']);

        $dbKey = "db{$database}";

        if (isset($info[$dbKey])) {
            $keyspace = array_column(array_map(static function ($value) {
                return explode('=', $value);
            }, explode(',', $info[$dbKey])), 1, 0);

            $keys = intval($keyspace['keys']);
        }

        return [
            'redis-hits' => $info['keyspace_hits'],
            'redis-misses' => $info['keyspace_misses'],
            'redis-hit-ratio' => $total > 0 ? round($info['keyspace_hits'] / ($total / 100), 2) : 100,
            'redis-ops-per-sec' => $info['instantaneous_ops_per_sec'],
            'redis-evicted-keys' => $info['evicted_keys'],
            'redis-used-memory' => $info['used_memory'],
            'redis-used-memory-rss' => $info['used_memory_rss'],
            'redis-memory-fragmentation-ratio' => $info['mem_fragmentation_ratio'] ?? 0,
            'redis-connected-clients' => $info['connected_clients'],
            'redis-tracking-clients' => $info['tracking_clients'] ?? 0,
            'redis-rejected-connections' => $info['rejected_connections'],
            'redis-keys' => $keys ?? 00,
        ];
    }

    protected static function mapRelayStats(array $stats, int $database): array
    {
        $total = intval($stats['stats']['hits'] + $stats['stats']['misses']);

        return [
            'relay-hits' => $stats['stats']['hits'],
            'relay-misses' => $stats['stats']['misses'],
            'relay-hit-ratio' => $total > 0 ? round($stats['stats']['hits'] / ($total / 100), 2) : 100,
            'relay-ops-per-sec' => $stats['stats']['ops_per_sec'],
            'relay-keys' => '', // TODO: Calculate Relay keys
            'relay-memory-used' => $stats['memory']['used'],
            'relay-memory-total' => $stats['memory']['total'],
            'relay-memory-ratio' => round(($stats['memory']['used'] / $stats['memory']['total']) * 100, 2),
        ];
    }
}
