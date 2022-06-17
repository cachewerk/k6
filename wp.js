import http from 'k6/http'
import { Rate, Trend } from 'k6/metrics'

import { sample, validateSiteUrl, wpSitemap, responseWasCached, bypassPageCacheCookies, parseMetricsFromResponse } from './lib/helpers.js'

export const options = {
    vus: 20,
    duration: '20s',
    ext: {
        loadimpact: {
            name: 'Random WordPress requests',
            note: 'Fetch all WordPress sitemaps and request random URLs.',
            projectID: __ENV.PROJECT_ID || null
        },
    },
}

const errorRate = new Rate('errors')
const responseCacheRate = new Rate('response_cached')

// These metrics are provided by Object Cache Pro when `analytics.footnote` is enabled
const cacheHits = new Trend('cache_hits')
const cacheHitRatio = new Trend('cache_hit_ratio')
const storeReads = new Trend('store_reads')
const storeWrites = new Trend('store_writes')
const msCache = new Trend('ms_cache', true)
const msCacheMedian = new Trend('ms_cache_median', true)
const msCacheRatio = new Trend('ms_cache_ratio')
const relayRate = new Rate('using_relay')
const phpRedisRate = new Rate('using_phpredis')

export function setup () {
    const siteUrl = __ENV.SITE_URL
    validateSiteUrl(siteUrl);

    return { urls: wpSitemap(`${siteUrl}/wp-sitemap.xml`).urls }
}

export default function (data) {
    let cookies = __ENV.BYPASS_CACHE ? bypassPageCacheCookies() : {}

    const url = sample(data.urls)
    const response = http.get(url, { cookies })

    errorRate.add(response.status >= 400)
    responseCacheRate.add(responseWasCached(response))

    const metrics = parseMetricsFromResponse(response)

    if (metrics) {
        cacheHits.add(metrics.hits)
        cacheHitRatio.add(metrics.hitRatio)
        storeReads.add(metrics.storeReads)
        storeWrites.add(metrics.storeWrites)
        msCache.add(metrics.msCache)
        msCacheMedian.add(metrics.msCacheMedian)
        msCacheRatio.add(metrics.msCacheRatio)
        relayRate.add(metrics.usingRelay)
        phpRedisRate.add(metrics.usingPhpRedis)
    }
}
