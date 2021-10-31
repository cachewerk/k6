import http from 'k6/http'

export function isOK() {
    return {
        'response code is 200': response => response.status == 200,
    }
}

export function itemAddedToCart() {
    return {
        'item added to cart': response => response.body.includes('has been added to your cart'),
    }
}

export function cartHasProduct() {
    return {
        'cart has product': response => response.html().find('.woocommerce-cart-form').size() === 1,
    }
}

export function orderWasPlaced() {
    return {
        'order was placed': response => response.html().find('.entry-title').text().includes('Order received'),
    }
}

export function rand(min, max) {
    return Math.floor(Math.random() * (max - min + 1) + min)
}

export function sample(array) {
    const length = array.length

    return length
        ? array[~~(Math.random() * length)]
        : undefined
}

export function wpSitemap(url) {
    const urls = []
    const response = http.get(url)

    response.html().find('sitemap loc').each(function (idx, el) {
        const response = http.get(el.innerHTML())

        response.html().find('url loc').each(function (idx, el) {
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
            .replace(/ (.)/g, ($1) => $1.toUpperCase())
            .replace(/ /g, '')
    }

    const metrics = [...comment[0].matchAll(/metric#([\w-]+)=([\d.]+)/g)]
        .reduce(function (map, metric) {
            map[toCamelCase(metric[1])] = metric[2]

            return map
        }, {})

    return metrics
}
