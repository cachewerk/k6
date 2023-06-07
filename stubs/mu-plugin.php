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
add_action('shutdown', ['k6ObjectCacheMetrics', 'print'], PHP_INT_MAX);

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

        if (method_exists($class, '_connect_redis')) {
            self::$cache = self::WpRedis;
            self::$client = 'phpredis';
        }

        if (property_exists($class, '_object_cache')) {
            self::$cache = self::LiteSpeedCache;
            self::$client = 'phpredis';
        }
    }

    public static function print(): void
    {
        if (! self::$cache) {
            return;
        }

        if (! self::shouldPrint()) {
            return;
        }

        printf(
            "\n<!-- plugin=%s client=%s %s -->\n",
            self::$cache,
            self::$client,
            self::getMetrics()
        );
    }

    protected static function shouldPrint(): bool
    {
        if (is_robots() || is_trackback()) {
            return false;
        }

        if (self::incompatibleContentType()) {
            return false;
        }

        return ! ((defined('\WP_CLI') && constant('\WP_CLI')) ||
                  (defined('\REST_REQUEST') && constant('\REST_REQUEST')) ||
                  (defined('\XMLRPC_REQUEST') && constant('\XMLRPC_REQUEST')) ||
                  (defined('\DOING_AJAX') && constant('\DOING_AJAX')) ||
                  (defined('\DOING_CRON') && constant('\DOING_CRON')) ||
                  (defined('\DOING_AUTOSAVE') && constant('\DOING_AUTOSAVE')) ||
                  (function_exists('wp_is_json_request') && wp_is_json_request()) ||
                  (function_exists('wp_is_jsonp_request') && wp_is_jsonp_request()));
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

        return self::buildMetrics(
            $info->hits,
            $info->misses,
            $info->ratio,
            $info->bytes
        );
    }

    protected static function getWpRedisMetrics(): string
    {
        global $wp_object_cache;

        return self::buildMetrics(
            $wp_object_cache->cache_hits,
            $wp_object_cache->cache_misses,
            self::calculateHitRatio($wp_object_cache->cache_hits, $wp_object_cache->cache_misses),
            self::calculateBytes($wp_object_cache->cache)
        );
    }

    protected static function getLiteSpeedCacheMetrics(): string
    {
        global $wp_object_cache;

        $hits = $wp_object_cache->count_hit_incall + $wp_object_cache->count_hit;
        $misses = $wp_object_cache->count_miss_incall + $wp_object_cache->count_miss;

        return self::buildMetrics(
            $hits,
            $misses,
            self::calculateHitRatio($hits, $misses),
            self::calculateBytes($wp_object_cache->_cache)
        );
    }

    protected static function buildMetrics(int $hits, int $misses, float $ratio, $bytes): string
    {
        global $timestart;

        $requestStart = $_SERVER['REQUEST_TIME_FLOAT'] ?? $timestart;

        return sprintf(
            'metric#hits=%d metric#misses=%d metric#hit-ratio=%s metric#bytes=%d metric#sql-queries=%d metric#ms-total=%s',
            $hits,
            $misses,
            $ratio,
            $bytes,
            function_exists('\get_num_queries') ? \get_num_queries() : null,
            $requestStart ? round((microtime(true) - $requestStart) * 1000, 2) : null
        );
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
}
