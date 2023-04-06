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
    if (! /^https?:\/\/[^\s$.?#].[^\s]*$/.test(siteUrl)) {
        exec.test.abort('Missing `SITE_URL` environment variable, or invalid URL')
    }

    if (siteUrl.endsWith('/')) {
        exec.test.abort('The `SITE_URL` must not have a trailing slash')
    }
}

export function validateSitemapUrl (sitemapUrl) {
    if (! /^https?:\/\/[^\s$.?#].[^\s]*$/.test(sitemapUrl)) {
        exec.test.abort('The `SITEMAP_URL` environment variable is not a valid URL')
    }

    if (! sitemapUrl.endsWith('.xml')) {
        exec.test.abort('The `SITEMAP_URL` must end with .xml')
    }
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
    const sitemaps = [url]
    const urls = []
    const response = http.get(url, { redirects: 3 })

    if (response.status != 200) {
        fail('sitemap did *not* return 200 status')
    }

    response.html().find('url loc').each((idx, el) => {
        urls.push(el.innerHTML())
    })

    response.html().find('sitemap loc').each((idx, el) => {
        sitemaps.push(el.innerHTML())

        const response = http.get(el.innerHTML())

        response.html().find('url loc').each(function (idx, el) {
            urls.push(el.innerHTML())
        })
    })

    if (! urls.length) {
        fail('sitemap did *not* contain any urls')
    }

    const noun = sitemaps.length > 1 ? 'sitemaps' : 'sitemap'
    console.log(`Found ${urls.length} URLs in ${sitemaps.length} ${noun}`)

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

    // RunCloud
    if (headers['x-runcloud-cache'] === 'hit') {
        return true
    }

    return false
}
