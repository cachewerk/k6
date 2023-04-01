import http from 'k6/http'
import { Rate } from 'k6/metrics'

import Metrics from './lib/metrics.js'
import { sample, validateSiteUrl, validateSitemapUrl, wpSitemap, responseWasCached, bypassPageCacheCookies } from './lib/helpers.js'

export const options = {
    vus: 20,
    duration: '20s',
    summaryTimeUnit: 'ms',
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
const metrics = new Metrics()

export function setup () {
    const siteUrl = __ENV.SITE_URL
    validateSiteUrl(siteUrl)

    const sitemapUrl = __ENV.SITEMAP_URL || `${siteUrl}/wp-sitemap.xml`
    validateSitemapUrl(sitemapUrl)

    return {
        startedAt: Date.now(),
        urls: wpSitemap(sitemapUrl).urls,
    }
}

export function teardown (data) {
    const startedAt = new Date(data.startedAt)
    const endedAt = new Date()

    console.info(`Run started at ${startedAt.toJSON()}`)
    console.info(`Run ended at   ${endedAt.toJSON()}`)
}

export default function (data) {
    let cookies = __ENV.BYPASS_CACHE ? bypassPageCacheCookies() : {}

    const url = sample(data.urls)
    const response = http.get(url, { cookies })

    errorRate.add(response.status >= 400)
    responseCacheRate.add(responseWasCached(response))

    metrics.addResponseMetrics(response)
}
