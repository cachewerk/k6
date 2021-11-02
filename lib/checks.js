
export function isOK () {
    return {
        'response code is 200': response => response.status == 200,
    }
}

export function itemAddedToCart () {
    return {
        'item added to cart': response => response.body.includes('has been added to your cart'),
    }
}

export function cartHasProduct () {
    return {
        'cart has product': response => response.html().find('.woocommerce-cart-form').size() === 1,
    }
}

export function orderWasPlaced () {
    return {
        'order was placed': response => response.url.includes('/checkout/order-received/'),
    }
}

export function pageIsNotLogin () {
    return {
        'page is not login': response => {
            return response.html().find('.woocommerce-form-login').size() === 0
        },
    }
}


