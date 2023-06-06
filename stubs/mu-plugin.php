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

        if (method_exists($class, 'connect_using_relay')) {
            self::$cache = self::RedisObjectCache;

            $client = class_exists('Redis') ? 'phpredis' : 'predis';
            self::$client = defined('WP_REDIS_CLIENT') ? str_replace('pecl', 'phpredis', WP_REDIS_CLIENT) : $client;
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
        return ! ((defined('\WP_CLI') && constant('\WP_CLI')) ||
                  (defined('\REST_REQUEST') && constant('\REST_REQUEST')) ||
                  (defined('\XMLRPC_REQUEST') && constant('\XMLRPC_REQUEST')) ||
                  (defined('\DOING_AJAX') && constant('\DOING_AJAX')) ||
                  (defined('\DOING_CRON') && constant('\DOING_CRON')) ||
                  (defined('\DOING_AUTOSAVE') && constant('\DOING_AUTOSAVE')) ||
                  (function_exists('wp_is_json_request') && wp_is_json_request()) ||
                  (function_exists('wp_is_jsonp_request') && wp_is_jsonp_request()));
    }

    protected static function getMetrics(): string
    {
        global $wp_object_cache;

        switch (self::$cache) {
            case self::ObjectCachePro:
                return $wp_object_cache->requestMeasurement();
            case self::RedisObjectCache:
                $info = $wp_object_cache->info();

                return self::buildMetrics(
                    $info->hits,
                    $info->misses,
                    $info->ratio,
                    $info->bytes
                );
            case self::WpRedis:
                return self::buildMetrics(
                    $wp_object_cache->cache_hits,
                    $wp_object_cache->misses,
                    99, // TODO: Calculate WP Redis ratio
                    9999 // TODO: Calculate WP Redis bytes
                );
            case self::LiteSpeedCache:
                return self::buildMetrics(
                    $wp_object_cache->count_hit,
                    $wp_object_cache->count_miss,
                    99, // TODO: Calculate LiteSpeed Cache ratio
                    9999 // TODO: Calculate LiteSpeed Cache bytes
                );
        }

        return '';
    }

    protected static function buildMetrics($hits, $misses, $ratio, $bytes): string
    {
        return sprintf(
            'metric#hits=%d metric#misses=%d metric#hit-ratio=%s metric#bytes=%d metric#sql-queries=%d',
            $hits,
            $misses,
            $ratio,
            $bytes,
            function_exists('\get_num_queries') ? get_num_queries() : null
            // TODO: Additional WP metrics
        );
    }
}