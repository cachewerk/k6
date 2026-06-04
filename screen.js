import http from 'k6/http'
import exec from 'k6/execution'

import Metrics from './lib/metrics.js'
import { withProfile, profiles } from './lib/profiles.js'
import { resetSite, validateSiteUrl, validateSitemapUrl, wpSitemap } from './lib/helpers.js'

const metrics = new Metrics()

const profilesToTest = __ENV.PROFILES
    ? __ENV.PROFILES.split(',').map(p => p.trim())
    : Object.keys(profiles)

const iterations  = parseInt(__ENV.ITERATIONS || '50')
const vus         = parseInt(__ENV.VUS        || '10')
const timeoutSecs = parseInt(__ENV.TIMEOUT    || '120')

profilesToTest.forEach(name => {
    if (! (name in profiles)) {
        throw new Error(`Unknown profile "${name}". Valid: ${Object.keys(profiles).join(', ')}`)
    }
})

export const options = {
    summaryTimeUnit: 'ms',
    summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)'],
    thresholds: Object.fromEntries(
        profilesToTest.flatMap(profile => [
            [`http_req_duration{scenario:${profile}}`,  []],
            [`wp_ms_cache_ratio{scenario:${profile}}`,  []],
            [`wp_ms_cache_avg{scenario:${profile}}`,    []],
            [`redis_ops_per_sec{scenario:${profile}}`,  []],
        ])
    ),
    scenarios: Object.fromEntries(
        profilesToTest.map((profile, i) => [
            profile,
            {
                executor: 'per-vu-iterations',
                vus,
                iterations,
                maxDuration: `${timeoutSecs}s`,
                env: { PROFILE: profile },
                tags: { profile },
                startTime: `${i * timeoutSecs}s`,
            }
        ])
    ),
}

export function setup () {
    const siteUrl = __ENV.SITE_URL
    validateSiteUrl(siteUrl)
    resetSite(siteUrl)

    const sitemapUrl = __ENV.SITEMAP_URL || `${siteUrl}/wp-sitemap.xml`
    validateSitemapUrl(sitemapUrl)

    return {
        urls: wpSitemap(sitemapUrl).urls,
    }
}

export default function (data) {
    const url = data.urls[exec.scenario.iterationInTest % data.urls.length]
    const response = http.get(url, withProfile({}))
    metrics.addResponseMetrics(response)
}
