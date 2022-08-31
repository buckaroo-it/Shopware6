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
        onInitCallback: this.onInitCallback.bind(this),
        onClickCallback: this.onClickCallback.bind(this),
    }

    loadSdk() {
        return new Promise((resolve) => {
            var script = document.createElement("script");
            script.src = "https://checkout.buckaroo.nl/api/buckaroosdk/script/en-US";
            script.src = "https://testcheckout.buckaroo.nl/api/buckaroosdk/script/en-US";
            script.async = true;
            document.head.appendChild(script);
            script.onload = () => {
                resolve();
            };
        })
    }
    loadJquery() {
        return new Promise((resolve) => {
            var script = document.createElement("script");
            script.src = "https://code.jquery.com/jquery-3.2.1.min.js";
            script.async = true;
            document.head.appendChild(script);
            script.onload = () => {
                resolve();
            };
        });
    }

    form;

    init() {


        if (this.merchantId === null) {
            alert('Merchant id is required');
        }
        

        this.loadJquery().then(() => {
            this.loadSdk().then(() => {
                this.sdk = BuckarooSdk.PayPal;
                this.sdk.initiate(this.sdkOptions);
                this.listen();
            })
        })


    }

    /**
     * Api events
     */
    onShippingChangeHandler(data, actions) {
        let shipping = this.setShipping(data);
        return shipping.then((response) => {
            if (response.error === false) {
                this.sdkOptions.amount = response.data.value.value
                return actions.order.patch([
                    {
                        op: 'replace',
                        path: '/purchase_units/@reference_id==\'default\'/amount',
                        value: response.data.value
                    }
                ]);
            } else {
                actions.reject(response.message);
            }
        })
    }
    createPaymentHandler(data) {
        return this.createTransaction(data.orderID)
    }
    onSuccessCallback() {
        if (this.result.error === true) {
            this.displayErrorMessage(message);
        } else {
            if (this.result.data.redirect) {
                window.location = this.result.data.redirect;
            } else {
                this.displayErrorMessage(this.options.i18n.cannot_create_payment);
            }
        }
        console.log('onSuccessCallback');
    }

    onErrorCallback(reason) {
        // custom error behavior
        this.displayErrorMessage(reason);
    }
    onInitCallback() {
        this.get_cart_total();
    }
    onCancelCallback() {
        this.displayErrorMessage(this.options.i18n.cancel_error_message)
    }

    onClickCallback() {
        //reset any previous payment response;
        this.result = null;
    }

    /**
     * listen to any change in the cart and get total
     */
    listen() {
        // $(".cart .quantity input").on('change', () => {
        //     this.get_cart_total();
        // });

        // $(".variations_form").on("show_variation hide_variation", () => {
        //     this.get_cart_total();
        // });
        // $(document.body).on('wc_fragments_refreshed updated_shipping_method', () => {
        //     this.get_cart_total();
        //     if ($(".buckaroo-paypal-express").length) {
        //         this.sdk.initiate(this.sdkOptions);
        //     }
        // });
    }
    /**
     * Get cart total to output in paypal
     */
    get_cart_total() {
        
        // $.post(this.url, {
        //     action: 'buckaroo_paypal_express_get_cart_total',
        //     order_data: this.getOrderData(),
        //     page: this.page
        // })
        //     .then((response) => {
        //         if (response.data) {
        //             this.sdkOptions.amount = response.data.total
        //         }
        //     })
    }

    /**
     * Create order and do payment
     * @param {string} orderId 
     * @returns Promise
     */
    createTransaction(orderId) {
        return new Promise((resolve) => {
            this.httpClient.post(
                `${this.url}/paypal/pay`,
                JSON.stringify({
                     orderId,
                    _csrf_token: this.options.csrf.pay
                }),
                (response) => {
                    this.result = response;
                    resolve(response);
                })
        })
    }


    /**
     * Set shipping on cart and return new total
     * @param {Object} data 
     * @returns 
     */
    setShipping(data) {
        let formData = null;
        if(this.options.page === 'product') {
            formData = FormSerializeUtil.serializeJson(this.el.closest('form'));
        }
        return new Promise((resolve) => {
            this.httpClient.post(
                `${this.url}/paypal/create`,
                JSON.stringify({
                    _csrf_token: this.options.csrf.create,
                    form: formData,
                    order: data,
                    page: this.options.page
                }), (response) => {
                    resolve(response);
                }
            )
        });
    }

    /**
     * Display any validation errors we receive
     * @param {string} message 
     */
    displayErrorMessage(message) {
        console.log(message);
    //     $('.buckaroo-paypal-express-error').remove();
    //     if (typeof message === 'object') {
    //         console.log(message);
    //         message = buckaroo_paypal_express.i18n.cannot_create_payment;
    //     }
    //     const content = `      
    //     <div class="woocommerce-error buckaroo-paypal-express-error" role="alert">
    //       ${message}
    //     </div>
    //   `;
    //     $('.woocommerce-notices-wrapper').first().prepend(content);
    //     setTimeout(function () {
    //         $('.buckaroo-paypal-express-error').fadeOut(1000);
    //     }, 10000);
    }
}