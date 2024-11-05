import { IdealFastCheckout } from './sdk'; // Importing IdealFastCheckout
import HttpClient from 'src/service/http-client.service';
import Plugin from 'src/plugin-system/plugin.class';
import FormSerializeUtil from 'src/utility/form/form-serialize.util';
export default class IdealFastCheckoutPlugin extends Plugin {

    static options = {
        page: 'unknown',
        merchantId: null,
        websiteKey: null,
        i18n: {
            cancel_error_message: "Payment was cancelled.",
            cannot_create_payment: "Cannot create the payment. Please try again."
        }
    }

    httpClient = new HttpClient();
    url = '/buckaroo';
    result = null;
    cartToken = null;

    init() {
        const element = document.querySelector('[data-buckaroo-ideal-fast-checkout-plugin-options]');

        if (element) {
            const pluginOptionsData = element.getAttribute('data-buckaroo-ideal-fast-checkout-plugin-options');
            if (pluginOptionsData) {
                // Step 3: Parse the JSON-encoded options
                const options = JSON.parse(pluginOptionsData);
                this.options.page = options.page;
                console.log(options.containerSelector)
                let checkoutButton = document.getElementById('fast-checkout-ideal-btn');
                if (!checkoutButton) {
                    console.error('Ideal Fast Checkout button not found');
                    return;
                }

                // Using an arrow function to keep the correct `this` context
                checkoutButton.addEventListener('click', (e) => {
                    this.initPayment(e);
                });
            }
        }
    }

    initPayment(e) {
        e.preventDefault();
        this.createCart().then((data) => {
            this.createOrder(data);
        });
    }

    createCart() {
        let formData = null;

        if (this.options.page === "product") {
            const formElement = document.getElementById('productDetailPageBuyProductForm');
            if (formElement) {
                formData = FormSerializeUtil.serializeJson(formElement);
            } else {
                console.error('Form element not found.');
            }
        }
        return new Promise((resolve, reject) => {
            this.httpClient.post(
                `${this.url}/ideal/cart/get`,
                JSON.stringify({
                    form: formData,
                    page: this.options.page,
                }),
                (response) => {
                    let resp = JSON.parse(response);
                    console.log(resp);
                    if(resp.error) {
                        this.displayErrorMessage(resp.message);
                        reject(resp.message);
                    } else {
                        this.cartToken = resp.cartToken;
                        resolve(resp);
                    }

                }
            );
        });
    }



    /**
     * Create the sw6 order with the payment data
     * @param {*} payment
     */
    createOrder(payment) {
        return new Promise((resolve) => {
            this.httpClient.post(
                `${this.url}/ideal/order/create`,
                JSON.stringify({
                    payment: JSON.stringify(payment),
                    cartToken: this.cartToken,
                    page: this.options.page
                }),
                (response) => {
                    console.log(response)
                    // const resp = JSON.parse(response);
                    // if (resp.redirect) {
                    //     resolve({
                    //         status: ApplePaySession.STATUS_SUCCESS,
                    //         errors: [],
                    //     });
                    //     window.location = resp.redirect;
                    // } else {
                    //
                    //     let message = this.options.i18n.cannot_create_payment;
                    //     if(resp.message) {
                    //         message = resp.message;
                    //     }
                    //     this.displayErrorMessage(message);
                    //     resolve({
                    //         status: ApplePaySession.STATUS_FAILURE,
                    //         errors: [message],
                    //     });
                    // }
                }
            );
        });
    }
    onShippingChangeHandler(data, actions) {
        const formData = FormSerializeUtil.serializeJson(this.el.closest('form'));
        return new Promise((resolve, reject) => {
            this.httpClient.post(
                `${this.url}/idealfastcheckout/updateShipping`,
                JSON.stringify({ form: formData, customer: data }),
                (response) => {
                    const updatedData = JSON.parse(response);
                    if (!updatedData.error) {
                        return actions.order.patch([
                            { op: 'replace', path: '/amount', value: updatedData.newAmount }
                        ]);
                    } else {
                        reject('Failed to update shipping');
                    }
                }
            );
        });
    }

    displayErrorMessage(message) {
        const errorContainer = document.createElement('div');
        errorContainer.className = 'buckaroo-idealfastcheckout-express-error alert alert-warning alert-has-icon';
        errorContainer.innerHTML = `
            <span class="icon icon-warning">!</span>
            <div class="alert-content">
                ${message}
            </div>
        `;

        const flashbags = document.querySelector('.flashbags');
        if (flashbags) {
            flashbags.prepend(errorContainer);
            setTimeout(() => {
                errorContainer.remove();
            }, 10000);
        }
    }
}
