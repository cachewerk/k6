import http from 'k6/http'
import { SharedArray } from 'k6/data'
import { Rate, Trend } from 'k6/metrics'

import { wpMetrics } from './_helpers.js'

export const options = {
    scenarios: {
        test: {
            executor: 'ramping-vus',
            startVUs: 0,
            gracefulStop: '3s',
            gracefulRampDown: '3s',
            stages: [
                { duration: '1m', target: 100 },
            ],
        },
    },
}

const urls = new SharedArray('product urls', () => JSON.parse(open('./data/products.json')))

const errorRate = new Rate('error_rate')

// metrics provided by Object Cache Pro
const cacheHits = new Trend('cache_hits')
const storeReads = new Trend('store_reads')
const storeWrites = new Trend('store_writes')
const msCache = new Trend('ms_cache', true)
const msCacheRatio = new Trend('ms_cache_ratio')

export default function () {
    const url = urls[Math.floor(Math.random() * urls.length)];
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
