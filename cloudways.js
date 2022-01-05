import http from 'k6/http'
import { Rate } from 'k6/metrics'
import Metrics from './lib/metrics.js'
import { validateSiteUrl, wpSitemap, fetchSlowmap } from './lib/helpers.js'

export const options = {
    vus: 20,
    duration: '20s',
    ext: {
        loadimpact: {
            name: 'Random WordPress requests',
            note: 'Hit sitemap/slowmap URLs and collect Redis/cache stats.',
            projectID: __ENV.PROJECT_ID || null,
        },
    },
}

const errorRate = new Rate('errors')
const metrics = new Metrics()

function fetchRedisStats(siteUrl) {
    const bust = Date.now()
    const res = http.get(`${siteUrl}/relay/redis-commands.php?ts=${bust}`)
    if (res.status !== 200) {
        throw new Error(`Failed to fetch Redis stats: ${res.status}`)
    }

    const json = res.json()
    return {
        calls: json.totals.calls,
        usec: json.totals.usec
    }
}

function fetchCacheStats(siteUrl) {
    const bust = Date.now()
    const res = http.get(`${siteUrl}/relay/cachestats.php?ts=${bust}`)
    if (res.status !== 200) {
        throw new Error(`Failed to fetch cache stats: ${res.status}`)
    }

    return res.json()
}

export function setup () {
    const siteUrl = __ENV.SITE_URL
    validateSiteUrl(siteUrl)

    const redisBefore = fetchRedisStats(siteUrl)

    let urls = wpSitemap(`${siteUrl}/wp-sitemap.xml`).urls

    urls.sort()

    return {
        siteUrl,
        urls,
        redisBefore
    }
}

export default function (data) {
    const urls = data.urls
    const i = __ITER
    const vu = __VU
    const len = urls.length

    const mode = __ENV.ACCESS_MODE || 'sync'
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
    const response = http.get(url)
    const duration = Date.now() - start

    errorRate.add(response.status >= 400)
    metrics.addResponseMetrics(response)

    if (__ENV.POST_TIMINGS === '1') {
        const timingPayload = JSON.stringify({ url, ms: duration })
        const headers = { 'Content-Type': 'application/json' }

        http.post('http://localhost:8080/timing.php', timingPayload, { headers })
    }
}

export function teardown(data) {
    const redisAfter = fetchRedisStats(data.siteUrl)
    const deltaCalls = redisAfter.calls - data.redisBefore.calls
    const deltaUsec = redisAfter.usec - data.redisBefore.usec

    metrics.addRedisTotals(deltaCalls, deltaUsec)

    const cacheStats = fetchCacheStats(data.siteUrl)
    metrics.addRedisKeyStats(cacheStats)

    const mem_pct = cacheStats.cached.size / cacheStats.total.size * 100
    const keys_pct = cacheStats.cached.keys / cacheStats.total.keys * 100

    console.log(`Redis Commands during test: ${deltaCalls}`)
    console.log(`Redis Time (usec) during test: ${deltaUsec}`)

    console.log('Cache Stats:')
    console.log(`  Cached: ${cacheStats.cached.keys} / ${cacheStats.total.keys}`)
    console.log(`    Size: ${cacheStats.cached.size} / ${cacheStats.total.size}`)
    console.log(`       %: Keys=${keys_pct.toFixed(2)}%  Size=${mem_pct.toFixed(2)}%`)
}
