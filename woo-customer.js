import http from 'k6/http'
import { Rate, Trend } from 'k6/metrics'
import { check, group, fail, sleep } from 'k6'

import Metrics from './lib/metrics.js';
import { isOK, pageIsNotLogin } from './lib/checks.js'
import { rand, validateSiteUrl, responseWasCached, bypassPageCacheCookies } from './lib/helpers.js'

export const options = {
    throw: true,
    summaryTimeUnit: 'ms',
    scenarios: {
        ramping: {
            executor: 'ramping-vus',
            startVUs: 1,
            gracefulStop: '10s',
            gracefulRampDown: '10s',
            stages: [
                { duration: '1m', target: 100 },
            ],
        },
        // constant: {
        //     executor: 'constant-vus',
        //     vus: 100,
        //     duration: '1m',
        //     gracefulStop: '10s',
        // },
    },
    ext: {
        loadimpact: {
            name: 'WooCommerce account flow',
            note: 'Loads the homepage, signs in, views at orders and then their account details.',
            projectID: __ENV.PROJECT_ID || null
        },
    },
}

const errorRate = new Rate('errors')
const responseCacheRate = new Rate('response_cached')

// These metrics are provided by Object Cache Pro when `analytics.footnote` is enabled
const metrics = new Metrics();

export function setup () {
    return {
        startedAt: Date.now(),
    }
}

export function teardown (data) {
    const startedAt = new Date(data.startedAt)
    const endedAt = new Date()

    console.info(`Run started at ${startedAt.toJSON()}`)
    console.info(`Run ended at   ${endedAt.toJSON()}`)
}

export default function () {
    const jar = new http.CookieJar()
    const siteUrl = __ENV.SITE_URL

    validateSiteUrl(siteUrl);

    const pause = {
        min: 3,
        max: 8,
    }

    if (__ENV.BYPASS_CACHE) {
        Object.entries(bypassPageCacheCookies()).forEach(([key, value]) => {
            jar.set(siteUrl, key, value, { path: '/' })
        })
    }

    group('Load homepage', function () {
        const response = http.get(siteUrl, { jar })

        check(response, isOK)
            || (errorRate.add(1) && fail('status code was *not* 200'))

        metrics.addResponseMetrics(response)
        responseCacheRate.add(responseWasCached(response))
    })

    sleep(rand(pause.min, pause.max))

    group('Login', function () {
        const response = http.get(`${siteUrl}/my-account/`, { jar })

        check(response, isOK)
            || (errorRate.add(1) && fail('status code was *not* 200'))

        metrics.addResponseMetrics(response)
        responseCacheRate.add(responseWasCached(response))

        const formResponse = response.submitForm({
            formSelector: 'form.woocommerce-form-login',
            params: { jar },
            fields: {
                username: `test${rand(1, 100)}`,
                password: '3405691582',
                rememberme: 'forever',
            },
        })

        check(formResponse, isOK)
            || (errorRate.add(1) && fail('status code was *not* 200'))

        check(formResponse, pageIsNotLogin)
            || fail('page *has* login form')

        metrics.addResponseMetrics(formResponse)
        responseCacheRate.add(responseWasCached(formResponse))
    })

    sleep(rand(pause.min, pause.max))

    group('Load orders', function () {
        const response = http.get(`${siteUrl}/my-account/orders/`, { jar })

        check(response, isOK)
            || (errorRate.add(1) && fail('status code was *not* 200'))

        check(response, pageIsNotLogin)
            || fail('page *has* login form')

        metrics.addResponseMetrics(response)
        responseCacheRate.add(responseWasCached(response))
    })

    sleep(rand(pause.min, pause.max))

    group('Load orders', function () {
        const response = http.get(`${siteUrl}/my-account/orders/`, { jar })

        check(response, isOK)
            || (errorRate.add(1) && fail('status code was *not* 200'))

        check(response, pageIsNotLogin)
            || fail('page *has* login form')

        metrics.addResponseMetrics(response)
        responseCacheRate.add(responseWasCached(response))
    })
}
