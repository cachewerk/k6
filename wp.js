import http from 'k6/http'
import { Rate } from 'k6/metrics'
import { Trend } from 'k6/metrics'
import Metrics from './lib/metrics.js'
import {
    validateSiteUrl,
    wpSitemap,
    responseWasCached,
    bypassPageCacheCookies
} from './lib/helpers.js'

export const options = {
    vus: 20,
    duration: '20s',
    setupTimeout: '2m',
    ext: {
        loadimpact: {
            name: 'Random WordPress requests',
            note: 'Fetch URLs from slowmap or sitemap and optionally log timings.',
            projectID: __ENV.PROJECT_ID || null,
        },
    },
}

const errorRate = new Rate('errors')
const relayMemoryUsed = new Trend('relay_memory_used', true)
const responseCacheRate = new Rate('response_cached')
const metrics = new Metrics()

let lastRelayFetch = 0

function getWithCacheBust(path) {
    const sep = path.includes('?') ? '&' : '?';
    const url = `${path}${sep}cb=${Date.now()}`;
    return http.get(url);
}

function fetchRedisStats(siteUrl) {
    try {
        const res = getWithCacheBust(`${siteUrl}/relay/redis-commands.php`)
        if (res.status !== 200) {
            return {
                calls: 0,
                usec: 0
            }
        }

        const json = res.json()

        return {
            calls: json.totals.calls,
            usec: json.totals.usec
        }
    } catch (e) {
        console.error(`Failed to fetch Redis stats: ${e.message}`)
        return {
            calls: 0,
            usec: 0
        }
    }
}

function fetchRelayInfo(siteUrl) {
    const res = getWithCacheBust(`${siteUrl}/relay/relay-info.php`)
    if (res.status !== 200) {
        throw new Error(`Failed to fetch Relay info: ${res.status}`)
    }

    const json = res.json()
    return {
        ocp_client: json['ocp.client'],
        relay_invalidations: json['relay.invalidations'],
        fpm_workers: json['fpm.workers'],
        maxmemory: json['relay.maxmemory'],
        maxmemory_pct: json['relay.maxmemory_pct'],
        locks_allocator: json['relay.locks.allocator'],
        locks_cache: json['relay.locks.cache'],
        adaptive_min_ratio: json['relay.adaptive_cache.min_ratio'],
        adaptive_min_events: json['relay.adaptive_cache.min_events'],
        adaptive_width: json['relay.adaptive_cache.width'],
        adaptive_depth: json['relay.adaptive_cache.depth'],
        max_endpoint_dbs: json['relay.max_endpoint_dbs'],
        relay_memory: json['relay.memory']
    }
}

function fetchSlowmap(siteUrl, limit) {
    let url = `${siteUrl}/slowmap.php`
    if (limit) url += `?limit=${limit}`

    const res = http.get(url)
    if (res.status !== 200) {
        throw new Error(`Failed to fetch slowmap: ${res.status}`)
    }

    const urls = res.json()
    if (!Array.isArray(urls)) {
        throw new Error('Invalid response from slowmap: expected array')
    }

    return urls
}

export function setup () {
    const siteUrl = __ENV.SITE_URL
    const fakeUrl = __ENV.FAKE_URL

    validateSiteUrl(siteUrl)

    const apiUrl = fakeUrl || siteUrl

    const redisBefore = fetchRedisStats(apiUrl)
    const relayInfo = fetchRelayInfo(apiUrl)

    const used = relayInfo.relay_memory && relayInfo.relay_memory.used;
    console.log(`Relay Memory Used: ${used ? used : 'N/A'}`);

    let urls
    if (__ENV.SLOWMAP === '1') {
        const limit = __ENV.LIMIT
        urls = fetchSlowmap(siteUrl, limit)
    } else {
        urls = wpSitemap(`${siteUrl}/wp-sitemap.xml`).urls
    }

    urls.sort()

    return {
        siteUrl,
        urls,
        redisBefore,
        relayInfo
    }
}

function responseWasRateLimited(res) {
    return (
        res.status === 429 ||
        (res.status === 403 &&
         res.body &&
         res.body.includes('<title>Attention Required!') &&
         res.body.includes('cloudflare'))
    );
}

export default function (data) {
    let cookies = __ENV.BYPASS_CACHE ? bypassPageCacheCookies() : {}

    //const INFO_INTERVAL = 100
    const now = Date.now()
    const intervalMs = 1000


    const mode = __ENV.ACCESS_MODE || 'sync'
    const urls = data.urls
    const i = __ITER
    const vu = __VU
    const len = urls.length

    let index

    if (mode === 'sync') {
        index = i % len
    } else if (mode === 'staggered') {
        index = (i + vu - 1) % len
    } else {
        index = Math.floor(Math.random() * len)
    }

    const url = urls[index]

    const start = Date.now()
    const response = http.get(url, { cookies })
    const duration = Date.now() - start

    //if (now - lastRelayFetch >= intervalMs) {
    //    try {
    //        const info = fetchRelayInfo(data.siteUrl)
    //        const used = info.relay_memory && info.relay_memory.used
    //        if (typeof used === 'number') {
    //            relayMemoryUsed.add(used)
    //        }
    //    } catch (err) {
    //        console.warn(`Failed to fetch relay info: ${err.message}`)
    //    }

    //    lastRelayFetch = now
    //}

    if (responseWasRateLimited(response)) {
        console.warn(`Rate limited: ${url} (status ${response.status})`);
        return;
    }

    errorRate.add(response.status >= 400)
    responseCacheRate.add(responseWasCached(response))
    metrics.addResponseMetrics(response)

    if (__ENV.POST_TIMINGS === '1') {
        const timingPayload = JSON.stringify({ url, ms: duration })
        const headers = { 'Content-Type': 'application/json' }

        http.post('http://localhost:8080/timing.php', timingPayload, { headers })
    }
}

function fetchCacheStats(siteUrl) {
    try {
        const res = getWithCacheBust(`${siteUrl}/relay/cachestats.php`)
        if (res.status !== 200) {
            console.warn(`fetchCacheStats failed: HTTP ${res.status}`);
            return {
                timestamp: Date.now() / 1000,
                db: 0,
                total: { keys: 0, size: 0 },
                cached: { keys: 0, size: 0 },
            }
        }

        return res.json()
    } catch (err) {
        return {
            timestamp: Date.now() / 1000,
            db: 0,
            total: { keys: 0, size: 0 },
            cached: { keys: 0, size: 0 },
        }
    }
}

//function fetchCacheStats(siteUrl) {
//    try {
//        const res = getWithCacheBust(`${siteUrl}/relay/cachestats.php`);
//        if (res.status !== 200) {
//            throw new Error(`HTTP ${res.status}`);
//        }
//        return res.json();
//    } catch (err) {
//        console.warn(`fetchCacheStats failed: ${err}`);
//        return {
//            timestamp: Date.now() / 1000,
//            db: 0,
//            total: { keys: 0, size: 0 },
//            cached: { keys: 0, size: 0 },
//        }
//    }
//}

export function teardown(data) {
    const redisAfter = fetchRedisStats(data.siteUrl)
    const deltaCalls = redisAfter.calls - data.redisBefore.calls
    const deltaUsec = redisAfter.usec - data.redisBefore.usec

    metrics.addRedisTotals(deltaCalls, deltaUsec)

    const fakeUrl = __ENV.FAKE_URL
    const apiUrl = fakeUrl || data.siteUrl

    const cacheStats = fetchCacheStats(apiUrl)
    metrics.addRedisKeyStats(cacheStats)

    const mem_pct = cacheStats.cached.size / cacheStats.total.size * 100
    const keys_pct = cacheStats.cached.keys / cacheStats.total.keys * 100

    console.log(`Redis Commands during test: ${deltaCalls}`)
    console.log(`Redis Time (usec) during test: ${deltaUsec}`)

    console.log('Cache Stats:')
    console.log(`  Cached: ${cacheStats.cached.keys} / ${cacheStats.total.keys}`)
    console.log(`    Size: ${cacheStats.cached.size} / ${cacheStats.total.size}`)
    console.log(`       %: Keys=${keys_pct.toFixed(2)}%  Size=${mem_pct.toFixed(2)}%`)

    console.log('Relay Info:')
    console.log(`  Client: ${data.relayInfo.ocp_client}`)
    console.log(`  FPM Workers: ${data.relayInfo.fpm_workers}`)
    console.log(`  Max Memory: ${data.relayInfo.maxmemory}`)
    console.log(`  Max Memory %: ${data.relayInfo.maxmemory_pct}`)
    console.log(`  Lock (Allocator): ${data.relayInfo.locks_allocator}`)
    console.log(`  Lock (Cache): ${data.relayInfo.locks_cache}`)
    console.log(`  Relay max_endpoint_dbs: ${data.relayInfo.max_endpoint_dbs}`)
    console.log(`  Adaptive Cache Min Ratio: ${data.relayInfo.adaptive_min_ratio}`)
    console.log(`  Adaptive Cache Min Events: ${data.relayInfo.adaptive_min_events}`)
    console.log(`  Adaptive Cache Width: ${data.relayInfo.adaptive_width}`)
    console.log(`  Adaptive Cache Depth: ${data.relayInfo.adaptive_depth}`)
}
