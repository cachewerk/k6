import http from 'k6/http'
import exec from 'k6/execution'
import { fail } from 'k6'

export function rand (min, max) {
    return Math.floor(Math.random() * (max - min + 1) + min)
}

export function sample (array) {
    const length = array.length

    return length
        ? array[~~(Math.random() * length)]
        : undefined
}

export function validateSiteUrl (siteUrl) {
    if (/^https?:\/\/[^\s$.?#].[^\s]*$/.test(siteUrl)) {
        return;
    }

    exec.test.abort('Missing `SITE_URL` environment variable, or invalid URL')
}

export function bypassPageCacheCookies () {
    return {
        comment_author_D00D2BAD: 'FEEDFACE',
        wordpress_logged_in_DEADFA11: 'FADEDEAD',
        woocommerce_cart_hash: 3405691582,
        wp_woocommerce_session_BADC0FFEE0DDF00D: 'DEADBEEF',
    }
}

export function wpSitemap (url) {
    const urls = []
    const response = http.get(url)

    if (response.status != 200) {
        fail('sitemap did *not* return 200 status')
    }

    response.html().find('sitemap loc').each(function (idx, el) {
        const response = http.get(el.innerHTML())

        response.html().find('url loc').each(function (idx, el) {
            urls.push(el.innerHTML())
        })
    })

    if (! urls.length) {
        fail('sitemap did *not* contain any urls')
    }

    return { urls }
}

export function responseWasCached (response) {
    const headers = Object.keys(response.headers).reduce(
        (previous, header) => (previous[header.toLowerCase()] = response.headers[header].toLowerCase(), previous), {}
    )

    // Generic cache
    if (headers['x-cache'] === 'hit') {
        return true
    }

    // Generic proxy
    if (headers['x-proxy-cache'] === 'hit') {
        return true
    }

    // Cloudflare
    if (headers['cf-cache-status'] === 'hit') {
        return true
    }

    // Litespeed
    if (headers['x-lsadc-cache'] === 'hit') {
        return true
    }

    // Pagely
    if (headers['x-gateway-cache-status'] === 'hit') {
        return true
    }

    return false
}
