import http from 'k6/http'
import { check, group, fail, sleep } from 'k6'
import { Rate } from 'k6/metrics'

import faker from 'https://cdn.jsdelivr.net/npm/faker@5.5.3/dist/faker.min.js';

import { rand, sample } from './lib/helpers.js'
import { isOK, itemAddedToCart, cartHasProduct, orderWasPlaced } from './lib/helpers.js'

export const options = {
    throw: true,
    scenarios: {
        test: {
            executor: 'constant-vus',
            vus: 100,
            duration: '1m',
            gracefulStop: '10s',
        },
    },
}

const errorRate = new Rate('errors')

export default function () {
    const siteUrl = __ENV.SITE_URL || 'https://test.cachewerk.com'

    let jar = new http.CookieJar()

    const categories = group('Load homepage', function () {
        const response = http.get(siteUrl, { jar })

        check(response, { isOK })
            || (errorRate.add(1) && fail('status code was *not* 200'))

        return response.html()
            .find('li.product-category > a')
            .map((idx, el) => String(el.attr('href')))
            .filter(href => ! href.includes('/decor/'))
    })

    sleep(rand(2, 5))

    const products = group('Load category', function () {
        const category = sample(categories)
        const response = http.get(category, { jar })

        check(response, { isOK })
            || (errorRate.add(1) && fail('status code was *not* 200'))

        return response.html()
            .find('.products .woocommerce-loop-product__link')
            .map((idx, el) => el.attr('href'))
    })

    sleep(rand(2, 5))

    group('Load and add product to cart', function () {
        const product = sample(products)
        const response = http.get(product, { jar })

        check(response, { isOK })
            || (errorRate.add(1) && fail('status code was *not* 200'))

        const fields = response.html()
            .find('.input-text.qty')
            .map((idx, el) => el.attr('name'))
            .reduce((obj, key) => {
                obj[key] = 1

                return obj
            }, {})

        const formResponse = response.submitForm({
            formSelector: 'form.cart',
            fields,
            params: { jar },
        })

        check(formResponse, { isOK })
            || (errorRate.add(1) && fail('status code was *not* 200'))

        check(formResponse, { itemAddedToCart })
            || fail('items *not* added to cart')
    })

    sleep(rand(2, 5))

    group('Load cart', function () {
        const response = http.get(`${siteUrl}/cart`, { jar })

        check(response, { isOK })
            || (errorRate.add(1) && fail('status code was *not* 200'))

        check(response, { cartHasProduct })
            || fail('cart was empty')
    })

    sleep(rand(2, 5))

    group('Place holder', function () {
        const response = http.get(`${siteUrl}/checkout`, { jar })

        check(response, { isOK })
            || (errorRate.add(1) && fail('status code was *not* 200'))

        const formResponse = response.submitForm({
            formSelector: 'form[name="checkout"]',
            params: { jar },
            fields: {
                billing_first_name: faker.name.firstName(),
                billing_last_name: faker.name.lastName(),
                billing_company: faker.datatype.boolean() ? faker.company.companyName() : null,
                billing_country: 'US',
                billing_state: faker.address.stateAbbr(),
                billing_address_1: faker.address.streetAddress(),
                billing_address_2: faker.datatype.boolean() ? faker.address.secondaryAddress() : null,
                billing_city: faker.address.city(),
                billing_postcode: faker.address.zipCodeByState('DE'),
                billing_phone: faker.phone.phoneNumber(),
                billing_email: faker.internet.exampleEmail(),
                order_comments: faker.datatype.boolean() ? faker.lorem.sentences() : null,
            },
        })

        check(formResponse, { orderWasPlaced })
            || fail('was was *not* placed')
    })
}
