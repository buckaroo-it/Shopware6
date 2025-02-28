import HttpClient from 'src/service/http-client.service';
import Plugin from 'src/plugin-system/plugin.class';

export default class IdealFastCheckoutPlugin extends Plugin {

    static options = {
        page: 'unknown',
        merchantId: null,
        websiteKey: null,
        i18n: {
            cancel_error_message: "Payment was cancelled.",
            cannot_create_payment: "Cannot create the payment. Please try again.",
            customer_not_found: "You must be logged in to perform this action.",
            general_error: "An error occurred while processing your payment."
        }
    }

    httpClient = new HttpClient();
    url = '/buckaroo';
    result = null;
    cartToken = null;

    init() {
        const element = document.querySelector('[data-buckaroo-ideal-fast-checkout-plugin-options]');

        if (!element) {
            console.error('Plugin options element not found');
            return;
        }

        const pluginOptionsData = element.getAttribute('data-buckaroo-ideal-fast-checkout-plugin-options');
        if (!pluginOptionsData) {
            console.error('No data found in plugin options');
            return;
        }
        const options = JSON.parse(pluginOptionsData);
        this.options.page = options.page;

        const checkoutButton = document.getElementById('fast-checkout-ideal-btn');
        if (!checkoutButton) {
            console.error('Ideal Fast Checkout button not found');
            return;
        }

        checkoutButton.addEventListener('click', (e) => this.initPayment(e));
    }
    initPayment(event) {
        event.preventDefault();
        const checkoutButton = document.getElementById('fast-checkout-ideal-btn');

        if (checkoutButton) {
            checkoutButton.setAttribute('disabled', 'disabled');
        }

        this.createCart().finally(() => {
            if (checkoutButton) {
                checkoutButton.removeAttribute('disabled');
            }
        });
    }


    createCart() {
        let formObject = {};
        if (this.options.page === 'product') {
            formObject = this.getFormData();
        }

        if (!formObject) {
            console.error('Form data could not be retrieved');
            return;
        }

        return this.sendPostRequest(`${this.url}/idealfastcheckout/pay`, {
            form: formObject,
            page: this.options.page
        }).then(response => {
            if (response.redirect) {
                window.location = response.redirect;
            } else if (response.errorCode) {
                this.handleErrorResponse(response);
            } else {
                const message = response.message || this.options.i18n.cannot_create_payment;
                this.displayErrorMessage(message);
            }
        }).catch(error => {
            console.error('Error creating cart:', error);
            this.displayErrorMessage(this.options.i18n.general_error);
        });
    }

    handleErrorResponse(response) {
        let message = response.message || this.options.i18n.general_error;

        if (response.errorCode === 'CUSTOMER_NOT_FOUND') {
            message = this.options.i18n.customer_not_found;
        }

        this.displayErrorMessage(message);
    }

    getFormData() {
        const formElement = document.getElementById('productDetailPageBuyProductForm');

        if (!formElement) {
            console.error('Product form not found');
            return null;
        }

        const formData = new FormData(formElement);
        const formObject = {};
        formData.forEach((value, key) => {
            formObject[key] = value;
        });
        return formObject;
    }

    sendPostRequest(url, data) {
        return new Promise((resolve, reject) => {
            this.httpClient.post(
                url,
                JSON.stringify(data),
                (response) => {
                    try {
                        const parsedResponse = JSON.parse(response);
                        resolve(parsedResponse);
                    } catch (error) {
                        console.error('Error parsing response:', error);
                        reject(error);
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
            setTimeout(() => errorContainer.remove(), 10000);
        }
    }
}
