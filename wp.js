import http from 'k6/http'
import { Rate, Trend } from 'k6/metrics'

import { sample, wpMetrics, wpSitemap } from './lib/helpers.js'

export const options = {
    vus: 20,
    duration: '20s',
}

const errorRate = new Rate('errors')

// metrics provided by Object Cache Pro
const cacheHits = new Trend('cache_hits')
const storeReads = new Trend('store_reads')
const storeWrites = new Trend('store_writes')
const msCache = new Trend('ms_cache', true)
const msCacheRatio = new Trend('ms_cache_ratio')

export function setup () {
    const siteUrl = __ENV.SITE_URL || 'https://test.cachewerk.com'
    const sitemap = wpSitemap(`${siteUrl}/wp-sitemap.xml`)

    return { urls: sitemap.urls }
}

export default function (data) {
    const url = sample(data.urls)
    const response = http.get(url)

    errorRate.add(response.status >= 400)

    const metrics = wpMetrics(response)

    if (metrics) {
        cacheHits.add(metrics.hits)
        storeReads.add(metrics.storeReads)
        storeWrites.add(metrics.storeWrites)
        msCache.add(metrics.msCache)
        msCacheRatio.add(metrics.msCacheRatio)
    }
}
