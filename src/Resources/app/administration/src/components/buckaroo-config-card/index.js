import template from './buckaroo-config-card.html.twig'

const { Component } = Shopware;

Component.register("buckaroo-config-card", {
    template,
    props: {
        card: {
            required: true,
        },
        methods: {
            required: true,
        },
        isNotDefaultSalesChannel: {
            required: true,
        },
        value: {
            required: true,
        },
        currentSalesChannelId: {
            required: true,
        },
    },
    methods: {
        getElementBind(element, props) {
            return this.methods.getElementBind(element, props);
        },
        getInheritWrapperBind(element) {
            return this.methods.getInheritWrapperBind(element);
        },
        getFieldError(name) {
            return this.methods.getFieldError(name);
        },
        kebabCase(string) {
            return this.methods.kebabCase(string);
        },
        getInlineSnippet(title) {
            return this.methods.getInlineSnippet(title);
        },
        getInheritedValue(element) {
            return this.methods.getInheritedValue(element);
        },
        canShow(element) {
            const name = element.name.replace("BuckarooPayments.config.", "");

            /**  toggle for advancedConfiguration */
            if (['orderStatus','paymentSuccesStatus', 'sendInvoiceEmail'].indexOf(name) !== -1) {
                return this.getValueForName('advancedConfiguration');
            }

            /** toggle for ideal processing issuer render mode */
            if (name === "idealprocessingRenderMode") {
                return this.getValueForName('idealprocessingShowissuers');
            }

            /** toggle for ideal issuer render mode */
            if (name === "idealRenderMode") {
                return this.getValueForName('idealShowissuers');
            }
            /** toggle for ideal fast checkout settings */
            if (['idealFastCheckoutActivity','idealFastCheckoutVisibility','idealFastCheckoutLogoScheme'].indexOf(name) !== -1) {
                return this.getValueForName('idealFastCheckout');
            }
            /** toggle for afterpay capture only payment */
            if (name === 'afterpayPaymentstatus') {
                return this.getValueForName('afterpayCaptureonshippent');
            }

            /** toggle for afterpay old tax */
            if (name === 'afterpayOldtax') {
                return this.getValueForName('afterpayEnabledold');
            }

            return true;
        },
        getValueForName(name) {
            return this.value[`BuckarooPayments.config.${name}`];
        },
        canShowCredentialTester(element) {
            if (this.getValueForName('advancedConfiguration')) {
                return element.name === 'BuckarooPayments.config.orderStatus'
            }
            return element.name === 'BuckarooPayments.config.advancedConfiguration'
        }
    }
})