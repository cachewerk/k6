import http from 'k6/http'
import { check, group, fail, sleep } from 'k6'
import { Rate, Trend } from 'k6/metrics'

import { rand, wpMetrics, bypassPageCacheCookies } from './lib/helpers.js'
import { isOK, pageIsNotLogin } from './lib/checks.js'

export const options = {
    throw: true,
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
}

const errorRate = new Rate('errors')

// metrics provided by Object Cache Pro
const cacheHits = new Trend('cache_hits')
const storeReads = new Trend('store_reads')
const storeWrites = new Trend('store_writes')
const msCache = new Trend('ms_cache', true)
const msCacheRatio = new Trend('ms_cache_ratio')

export default function () {
    let metrics

    const jar = new http.CookieJar()
    const siteUrl = __ENV.SITE_URL || 'https://test.cachewerk.com'

    const pause = {
        min: 3,
        max: 8,
    }

    const addResponseMetrics = (response) => {
        if (metrics = wpMetrics(response)) {
            cacheHits.add(metrics.hits)
            storeReads.add(metrics.storeReads)
            storeWrites.add(metrics.storeWrites)
            msCache.add(metrics.msCache)
            msCacheRatio.add(metrics.msCacheRatio)
        }
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

        addResponseMetrics(response)
    })

    sleep(rand(pause.min, pause.max))

    group('Login', function () {
        const response = http.get(`${siteUrl}/my-account/`, { jar })

        check(response, isOK)
            || (errorRate.add(1) && fail('status code was *not* 200'))

        addResponseMetrics(response)

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

        addResponseMetrics(formResponse)
    })

    sleep(rand(pause.min, pause.max))

    group('Load orders', function () {
        const response = http.get(`${siteUrl}/my-account/orders/`, { jar })

        check(response, isOK)
            || (errorRate.add(1) && fail('status code was *not* 200'))

        check(response, pageIsNotLogin)
            || fail('page *has* login form')

        addResponseMetrics(response)
    })

    sleep(rand(pause.min, pause.max))

    group('Load orders', function () {
        const response = http.get(`${siteUrl}/my-account/orders/`, { jar })

        check(response, isOK)
            || (errorRate.add(1) && fail('status code was *not* 200'))

        check(response, pageIsNotLogin)
            || fail('page *has* login form')

        addResponseMetrics(response)
    })
}
