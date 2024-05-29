const { Component } = Shopware;

import template from "./buckaroo-config-card.html.twig";

Component.register("buckaroo-config-card", {
    template,
    inject: ['systemConfigApiService'],
    props: {
        card: {
            type: Object,
            required: true,
        },
        methods: {
            type: Object,
            required: true,
        },
        isNotDefaultSalesChannel: {
            type: Boolean,
            required: true,
        },
        modelValue: {
            type: Object,
            required: true,
        },
        currentSalesChannelId: {
            type: String,
            required: true,
        },
    },
    data() {
        const localConfigData = { ...this.modelValue };

        this.card.elements.forEach(element => {
            if (!localConfigData[element.name]) {
                localConfigData[element.name] = null;
            }
        });

        return {
            localConfigData,
            isLoading: false
        };
    },
    watch: {
        modelValue: {
            handler(newValue) {
                const updatedConfigData = { ...newValue };
                this.card.elements.forEach(element => {
                    if (!updatedConfigData[element.name]) {
                        updatedConfigData[element.name] = null;
                    }
                });
                this.localConfigData = updatedConfigData;
                console.log('modelValue changed:', newValue);
            },
            deep: true
        },
        localConfigData: {
            handler(newValue) {
                this.$emit('update:modelValue', newValue);
                console.log('localConfigData changed:', newValue);
            },
            deep: true
        }
    },
    methods: {
        getElementBind(element, props) {
            console.log('Element:', element);
            console.log('Props:', props);
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
            if (['orderStatus', 'paymentSuccesStatus', 'sendInvoiceEmail'].indexOf(name) !== -1) {
                return this.getValueForName('advancedConfiguration');
            }
            if (name === "idealprocessingRenderMode") {
                return this.getValueForName('idealprocessingShowissuers');
            }
            if (name === "idealRenderMode") {
                return this.getValueForName('idealShowissuers');
            }
            if (name === 'afterpayPaymentstatus') {
                return this.getValueForName('afterpayCaptureonshippent');
            }
            if (name === 'afterpayOldtax') {
                return this.getValueForName('afterpayEnabledold');
            }
            return true;
        },
        getValueForName(name) {
            return  this.localConfigData[`BuckarooPayments.config.${name}`];
        },
        canShowCredentialTester(element) {
            if (this.getValueForName('advancedConfiguration')) {
                return element.name === 'BuckarooPayments.config.orderStatus';
            }
            return element.name === 'BuckarooPayments.config.advancedConfiguration';
        }
    },
    created() {
        console.log('Initial localConfigData:', this.localConfigData);
    }
});
