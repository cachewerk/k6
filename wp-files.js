import http from 'k6/http'
import { Rate, Trend } from 'k6/metrics'

import { sample, wpMetrics, responseWasCached, bypassPageCacheCookies } from './lib/helpers.js'

export const options = {
    vus: 20,
    duration: '20s',
}

const errorRate = new Rate('errors')
const responseCacheRate = new Rate('response_cached')

// metrics provided by Object Cache Pro
const cacheHits = new Trend('cache_hits')
const storeReads = new Trend('store_reads')
const storeWrites = new Trend('store_writes')
const msCache = new Trend('ms_cache', true)
const msCacheRatio = new Trend('ms_cache_ratio')

const file = __ENV.FILENAME
const verbose = __ENV.VERBOSE
const stream = open(file)
const urls = JSON.parse(open(file))

export function setup() {
    const flush = __ENV.FLUSH
    if (flush)
        http.get('http://192.168.0.148/relay/flush.php')

}

export default function (data) {
    let cookies = __ENV.BYPASS_CACHE ? bypassPageCacheCookies() : {}

    const url = sample(urls)
    if (verbose)
        console.log(url)
    let t1 = Math.floor(new Date().getTime())
    const response = http.get(url, { cookies })
    let t2 = Math.floor(new Date().getTime())

    let td = t2 - t1;
    if (t2 - t1 >= 8000)
        console.log("SLOW: " + url + " (" + td + " ms)");

    errorRate.add(response.status >= 400)
    responseCacheRate.add(responseWasCached(response))

    const metrics = wpMetrics(response)

    if (metrics) {
        cacheHits.add(metrics.hits)
        storeReads.add(metrics.storeReads)
        storeWrites.add(metrics.storeWrites)
        msCache.add(metrics.msCache)
        msCacheRatio.add(metrics.msCacheRatio)
    }
}
