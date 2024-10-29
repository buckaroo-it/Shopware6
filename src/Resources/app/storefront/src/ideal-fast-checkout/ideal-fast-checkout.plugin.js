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
        // Step 1: Get pluginOptionsData from data-ideal-fast-checkout-plugin-options attribute
        const pluginOptionsData = this.el.getAttribute('data-ideal-fast-checkout-plugin-options');

        if (pluginOptionsData) {
            const options = JSON.parse(pluginOptionsData);

            this.setupClickHandler(options);
        } else {
            console.error('Ideal Fast Checkout plugin options not found');
        }
    }

    setupClickHandler(options) {

        IdealFastCheckout.initiate({
            buckarooWebsiteKey: options.websiteKey,
            currency: 'EUR',
            amount: 10.00,
            containerSelector: '.bk-ideal-fast-checkout',
            baseCheckoutUrl: this.url,
            createPaymentHandler: (data) => this.createTransaction(data),
            onSuccessCallback: () => {
                console.log('Payment successful!');
                window.location.href = '/checkout/success';
            },
            onErrorCallback: (error) => {
                console.error('Payment failed', error);
                this.displayErrorMessage(error);
            },
            onCancelCallback: () => {
                console.log('Payment canceled');
                this.displayErrorMessage(options.i18n.cancel_error_message); // Use i18n from pluginOptionsData
            },
            onShippingChangeHandler: (data, actions) => this.onShippingChangeHandler(data, actions),
            onInitCallback: () => {
                console.log('Ideal Fast Checkout initialized');
            },
            onClickCallback: () => {
                console.log('Checkout button clicked');
            }
        });
    }

    createTransaction(orderData) {
        console.log(orderData)
        const data = {
            page: this.options.page,
            orderId: orderData.orderId,
        };

        if (this.cartToken) {
            data.cartToken = this.cartToken;
        }

        return new Promise((resolve, reject) => {
            this.httpClient.post(
                `${this.url}/idealfastcheckout/pay`,
                JSON.stringify(data),
                (response) => {
                    const parsedResponse = JSON.parse(response);
                    if (parsedResponse) {
                        resolve(parsedResponse);
                    } else {
                        reject('Transaction failed');
                    }
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
