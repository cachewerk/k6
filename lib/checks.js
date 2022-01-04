
export const isOK = {
    'response code is 200': response => response.status == 200
}

export const itemAddedToCart = {
    'item added to cart': response => response.body.includes('has been added to your cart')
}

export const cartHasProduct = {
    'cart has product': response => response.html().find('.woocommerce-cart-form').size() === 1
}

export const orderWasPlaced = {
    'order was placed': response => response.url.includes('/checkout/order-received/'),
}

export const pageIsNotLogin = {
    'page is not login': response => {
        return response.html().find('button[name="login"]').size() === 0
            && response.html().find('input[name="password"]').size() === 0
    }
}
