import http from 'k6/http'
import { group } from 'k6'
import { withProfile } from './lib/profiles.js'

// Replays a recorded Chrome HAR *directly* — no `k6 convert` step. Each recorded
// request goes out through `withProfile`, so the active profile's headers
// (X-K6-Capture to arm capture, X-OCP-* to reshape the cache) are merged on top
// of the request's recorded headers (including its Cookie).
//
//   k6 run --insecure-skip-tls-verify \
//     --env HAR=/path/to/recording.har \
//     --env SITE_URL=https://example.test \
//     --env PROFILE=capture-baseline har-replay.js
//
// SITE_URL is required: it both filters the HAR to that origin (skipping assets
// and any third-party hosts in the recording) and is where each request is
// replayed. vus:1/iterations:1 replays the chain once — one trace per request.

export const options = {
    vus: 1,
    iterations: 1,
    summaryTimeUnit: 'ms',
}

// Loaded once at init — `open()` only works in init context, not the default fn.
const har = JSON.parse(open(__ENV.HAR || './recording.har'))

// HTTP/2 pseudo-headers + hop-by-hop / length headers k6 must set itself.
const dropHeaders = [':authority', ':method', ':path', ':scheme', 'host', 'connection', 'content-length', 'accept-encoding']

const hostOf = (url) => url.replace(/^https?:\/\//, '').split('/')[0]

// SITE_URL drives both the host filter and where requests are replayed.
const siteUrl = (__ENV.SITE_URL || '').replace(/\/$/, '')

if (! siteUrl) {
    throw new Error('SITE_URL is required — har-replay.js filters the HAR to that origin')
}

const siteHost = hostOf(siteUrl)

// Keep only requests whose host matches SITE_URL; skip assets (by resource type)
// and any third-party hosts recorded alongside them.
const entries = har.log.entries.filter((entry) => {
    const type = entry._resourceType

    if (type && ! ['document', 'xhr', 'fetch'].includes(type)) {
        return false
    }

    return hostOf(entry.request.url) === siteHost
})

function headersFor (request) {
    const headers = {}

    for (const header of request.headers || []) {
        if (header.name.charAt(0) === ':' || dropHeaders.includes(header.name.toLowerCase())) {
            continue
        }

        headers[header.name] = header.value
    }

    return headers
}

export default function () {
    for (const entry of entries) {
        const request = entry.request
        const path = request.url.replace(/^https?:\/\/[^/]+/, '')
        const url = siteUrl + path
        const body = request.postData ? (request.postData.text || null) : null

        // Recorded headers + the profile's X-K6-Capture / X-OCP-* (which win).
        const params = withProfile({ headers: headersFor(request) })

        group(`${request.method} ${path}`, function () {
            http.request(request.method, url, body, params)
        })
    }
}
