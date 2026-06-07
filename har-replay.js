import http from 'k6/http'
import { check, sleep } from 'k6'
import { isOK } from './lib/checks.js'
import Metrics from './lib/metrics.js'
import { withProfile } from './lib/profiles.js'
import { validateSiteUrl } from './lib/helpers.js'

const metrics = new Metrics()

export const options = {
    vus: 1,
    iterations: 1,
    summaryTimeUnit: 'ms',
    summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)'],
}

export default function () {
    const siteUrl = __ENV.SITE_URL
    validateSiteUrl(siteUrl)

    const jar = new http.CookieJar()

    // withProfile injects OCP/ROC headers when PROFILE env var is set.
    // Example: k6 run har-replay.js --env SITE_URL=https://… --env PROFILE=ocp-relay --env OCP_TOKEN=…
    // Valid profiles: none, ocp-relay, ocp-phpredis, roc-phpredis, roc-relay, corpus-a…h, {php,igbinary}-{lz4,zstd}
    const params = withProfile({ jar })

    // Paste requests converted from HAR below.
    // Run: k6 convert recording.har -O k6/har-replay.js
    // Then replace this default function body with the generated requests,
    // passing `params` to each http.get() / http.post() call.

    const r1 = http.get(`${siteUrl}/`, params)
    check(r1, isOK)
    metrics.addResponseMetrics(r1)

    sleep(1)
}
