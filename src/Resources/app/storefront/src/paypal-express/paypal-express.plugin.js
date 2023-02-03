import HttpClient from 'src/service/http-client.service';
import Plugin from 'src/plugin-system/plugin.class';
import FormSerializeUtil from 'src/utility/form/form-serialize.util';

export default class PaypalExpressPlugin extends Plugin {

    static options = {
        page: 'unknown',
        merchantId: null
    }
    httpClient = new HttpClient();


    url = '/buckaroo';
    /**
     * buckaroo sdk
     */
    sdk;

    result = null;

    cartToken;

    sdkOptions = {
        containerSelector: ".buckaroo-paypal-express",
        buckarooWebsiteKey: this.options.websiteKey,
        paypalMerchantId: this.options.merchantId,
        currency: "EUR",
        amount: 0.1,
        createPaymentHandler: this.createPaymentHandler.bind(this),
        onShippingChangeHandler: this.onShippingChangeHandler.bind(this),
        onSuccessCallback: this.onSuccessCallback.bind(this),
        onErrorCallback: this.onErrorCallback.bind(this),
        onCancelCallback: this.onCancelCallback.bind(this),
        onClickCallback: this.onClickCallback.bind(this),
    }


    form;

    init()
    {
        if (this.merchantId === null) {
            alert('Merchant id is required');
        }
        document.$emitter.subscribe('buckaroo_scripts_loaded', () => {
            this.sdk = BuckarooSdk.PayPal;
            this.sdk.initiate(this.sdkOptions);
        })
    }

    /**
     * Api events
     */
    onShippingChangeHandler(data, actions)
    {
        let shipping = this.setShipping(data);
        return shipping.then((response) => {
            if (response.error === false) {
                this.cartToken = response.token;
                this.sdkOptions.amount = response.cart.value
                return actions.order.patch([
                    {
                        op: 'replace',
                        path: '/purchase_units/@reference_id==\'default\'/amount',
                        value: response.cart
                    }
                ]);
            } else {
                this.displayErrorMessage(response.message);
                actions.reject(response.message);
            }
        })
    }
    createPaymentHandler(data)
    {
        return this.createTransaction(data.orderID)
    }
    onSuccessCallback()
    {
        if (this.result.error === true) {
            this.displayErrorMessage(message);
        } else {
            if (this.result.redirect) {
                window.location = this.result.redirect;
            } else {
                this.displayErrorMessage(this.options.i18n.cannot_create_payment);
            }
        }
    }

    onErrorCallback(reason)
    {
        // custom error behavior
        this.displayErrorMessage(reason);
    }
    onCancelCallback()
    {
        this.displayErrorMessage(this.options.i18n.cancel_error_message)
    }

    onClickCallback()
    {
        //reset any previous payment response;
        this.result = null;
    }

    /**
     * Create order and do payment
     * @param {string} orderId
     * @returns Promise
     */
    createTransaction(orderId)
    {

        let data = {
            orderId,
            _csrf_token: this.options.csrf.pay
        };

        if (this.cartToken) {
            data.cartToken = this.cartToken;
        }
        return new Promise((resolve) => {
            this.httpClient.post(
                `${this.url} / paypal / pay`,
                JSON.stringify(data),
                (response) => {
                    this.result = JSON.parse(response);
                    resolve(JSON.parse(response));
                }
            )
        })
    }


    /**
     * Set shipping on cart and return new total
     * @param {Object} data
     * @returns
     */
    setShipping(data)
    {
        let formData = null;

        if (this.options.page === 'product') {
            formData = FormSerializeUtil.serializeJson(this.el.closest('form'));
        }
        return new Promise((resolve) => {
            this.httpClient.post(
                `${this.url} / paypal / create`,
                JSON.stringify({
                    _csrf_token: this.options.csrf.create,
                    form: formData,
                    customer: data,
                    page: this.options.page
                }),
                (response) => {
                    resolve(JSON.parse(response));
                }
            )
        });
    }

    /**
     * Display any validation errors we receive
     * @param {string} message
     */
    displayErrorMessage(message)
    {
        $('.buckaroo-paypal-express-error').remove();
        if (typeof message === 'object') {
            message = this.options.i18n.cannot_create_payment;
        }
        const content = `
        < div role = "alert" class = "alert alert-warning alert-has-icon buckaroo-paypal-express-error" >
            < span class = "icon icon-warning" >
                < svg xmlns = "http://www.w3.org/2000/svg" xmlns:xlink = "http://www.w3.org/1999/xlink" width = "24" height = "24" viewBox = "0 0 24 24" > < defs > < path d = "m13.7744 1.4124 9.7058 18.6649c.5096.98.1283 2.1875-.8517 2.6971a2 2 0 0 1-.9227.2256H2.2942c-1.1045 0-2-.8954-2-2a2 2 0 0 1 .2256-.9227l9.7058-18.665c.5096-.98 1.7171-1.3613 2.6971-.8517a2 2 0 0 1 .8517.8518zM2.2942 21h19.4116L12 2.335 2.2942 21zM12 17c.5523 0 1 .4477 1 1s-.4477 1-1 1-1-.4477-1-1 .4477-1 1-1zm1-2c0 .5523-.4477 1-1 1s-1-.4477-1-1v-5c0-.5523.4477-1 1-1s1 .4477 1 1v5z" id = "icons-default-warning" > < / path > < / defs > < use xlink:href = "#icons-default-warning" fill = "#758CA3" fill - rule = "evenodd" > < / use > < / svg >
            <  / span >
            < div class = "alert-content-container" >
                < div class = "alert-content" >
                    ${message}
                <  / div >

            <  / div >
        <  / div >

        `;
        $('.flashbags').first().prepend(content);
        setTimeout(function () {
            $('.buckaroo-paypal-express-error').fadeOut(1000);
        }, 10000);
    }
}