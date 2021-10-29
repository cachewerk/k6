import http from 'k6/http'
import { parseHTML } from 'k6/html'

export function sample(array) {
    const length = array.length

    return length
        ? array[~~(Math.random() * length)]
        : undefined
}

export function wpSitemap(url) {
    const urls = []
    const response = http.get(url)
    const sitemaps = parseHTML(response.body)

    sitemaps.find('sitemap loc').each(function (i, el) {
        const response = http.get(el.innerHTML())
        const sitemap = parseHTML(response.body)

        sitemap.find('url loc').each(function (i, el) {
            urls.push(el.innerHTML())
        })
    })

    return { urls }
}

export function wpMetrics(response) {
    if (! response.body) {
        return false
    }

    const comment = response.body.match(/<!-- plugin=object-cache-pro (.+?) -->/g)

    if (! comment) {
        return false
    }

    const toCamelCase = function (str) {
        return str.toLowerCase()
            .replace(/['"]/g, '')
            .replace(/\W+/g, ' ')
            .replace(/ (.)/g, function ($1) { return $1.toUpperCase(); })
            .replace(/ /g, '')
    }

    const metrics = [...comment[0].matchAll(/metric#([\w-]+)=([\d.]+)/g)]
        .reduce(function(map, metric) {
            map[toCamelCase(metric[1])] = metric[2]

            return map
        }, {})

    return metrics
}
