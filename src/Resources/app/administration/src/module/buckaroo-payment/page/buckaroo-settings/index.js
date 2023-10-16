const { Component, Mixin } = Shopware;

import template from './buckaroo-settings.html.twig';
import './style.scss';

Component.register('buckaroo-settings', {
    template,

    mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('sw-inline-snippet')
    ],

    inject: [ 'BuckarooPaymentSettingsService' ],

    data() {
        return {
            isLoading: false,
            isSaveSuccessful: false,
            config: {},
            websiteKeyIdFilled: false,
            secretKeyIdFilled: false,
            showValidationErrors: false,
            phpversion: false,
            supportMessage: false,
            isSupportModalOpen: false,
            isSupportMessageModalOpen: false,
            isPhpVersionSupport: false,
            collapsibleState: {
                'websiteKey': true,
                'secretKey': true,
                'guid': true,
                'transactionLabel': true,
                'refundLabel': true,
                'creditcardEnabled': true,
                'creditcardsEnabled': true,
                'idealEnabled': true,
                'idealqrEnabled': true,
                'idealprocessingEnabled': true,
                'belfiusEnabled': true,
                'bancontactmrcashEnabled': true,
                'afterpayEnabled': true,
                'BillinkEnabled': true,
                'sofortueberweisungEnabled': true,
                'paypalEnabled': true,
                'transferEnabled': true,
                'giropayEnabled': true,
                'KBCPaymentButtonEnabled': true,
                'sepadirectdebitEnabled': true,
                'payconiqEnabled': true,
                'applepayEnabled': true,
                'giftcardsEnabled': true,
                'capayableEnabled': true,
                'epsEnabled': true,
                'mbwayEnabled': true,
                'multibancoEnabled': true,
                'Przelewy24Enabled': true,
                'AlipayEnabled': true,
                'WeChatPayEnabled': true,
                'TrustlyEnabled': true,
                'klarnaEnabled': true,
                'klarnainEnabled': true,
                'klarnakpEnabled': true,
                'advancedConfiguration': true,
                'BillinkCreateInvoiceAfterShipment': true,
                'payperemailEnabled': true,
                'paybybankEnabled': true,
            },
            collapsibleAdvancedState: {
                'paylinkEnabled': true,
                'sendInvoiceEmail': true,
                'paymentSuccesStatus': true,
                'orderStatus': true,
            }
        };
    },

    created() {
        var that = this;
        this.createdComponent();

        this.BuckarooPaymentSettingsService.getSupportVersion()
        .then((result) => {
            that.phpversion = result.phpversion;
            that.isPhpVersionSupport = result.isPhpVersionSupport;
        });
    },

    computed: {
        credentialsMissing: function () {
            return !this.websiteKeyIdFilled || !this.secretKeyIdFilled;
        }
    },

    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },

    methods: {
        sendTestApi() {
            var that = this,
                websiteKeyId = this.getConfigValue('websiteKey'),
                secretKeyId = this.getConfigValue('secretKey'),
                currentSalesChannelId = this.$refs.systemConfig.currentSalesChannelId;
            this.BuckarooPaymentSettingsService.getApiTest(websiteKeyId, secretKeyId, currentSalesChannelId)
                .then((result) => {

                    if (result.status == 'success') {
                        this.createNotificationSuccess({
                            title: that.$tc('buckaroo-payment.settingsForm.titleSuccess'),
                            message: that.$tc(result.message)
                        });
                    } else {
                        this.createNotificationError({
                            title: that.$tc('buckaroo-payment.settingsForm.titleError'),
                            message: that.$tc(result.message)
                        });
                    }

                });
        },

        getCardConfig(element, card) {
            card.elements.forEach(el => {
                if (el.name == element.name) {
                    return el.config;
                }
            });
            return false;
        },

        showLabel(element, card) {
            if (element.type == 'single-select') {
                return true;
            }
            if (element.type == 'multi-select') {
                return true;
            }
            return false;
        },

        showHelpText(element, card) {
            if (this.showLabel(element, card)) {
                if (this.getCardInfo(element, card, 'helpText')) {
                    return true;
                }
            }
            return false;
        },

        showButtonAfter(element, config) {
            let name = 'advancedConfiguration';
            if (config["BuckarooPayments.config.advancedConfiguration"] != undefined && config["BuckarooPayments.config.advancedConfiguration"]) {
                name = 'orderStatus'
            }
            if (element.name.includes(name)) {
                return true;
            }
            return false;
        },

        getLocale(config) {
            let adminLocale = window.localStorage.getItem('sw-admin-locale');
            if (adminLocale == null) {
                adminLocale = 'en-GB';
            }
            if (config[adminLocale] != undefined) {
                return config[adminLocale];
            }
            return config['en-GB'];
        },

        getCardInfo(element, card, type) {
            let text = '';
            card.elements.forEach(el => {
                if (el.name == element.name) {
                    if (el.config != undefined) {
                        switch (type) {
                            case 'label':
                                text = (el.config.label != undefined) ? this.getLocale(el.config.label) : '';
                            break;
                            case 'helpText':
                                text = (el.config.helpText != undefined) ? this.getLocale(el.config.helpText) : '';
                            break;
                        }
                    }
                }

            });
            return text;
        },

        createdComponent() {
            var me = this;
        },

        saveFinish() {
            this.isSaveSuccessful = false;
        },

        onConfigChange(config) {
            this.config = config;

            this.checkCredentialsFilled();

            this.showValidationErrors = false;
        },

        checkCredentialsFilled() {
            this.websiteKeyIdFilled = !!this.getConfigValue('websiteKey');
            this.secretKeyIdFilled = !!this.getConfigValue('secretKey');
        },

        validateWebsiteKey() {
            return ((this.getConfigValue('websiteKey').length < 10) || (this.getConfigValue('websiteKey').length > 10)) ? false : true ;
        },

        validateSecretKey() {
            return ((this.getConfigValue('secretKey').length < 5) || (this.getConfigValue('secretKey').length > 50)) ? false : true ;
        },

        getConfigValue(field) {
            const defaultConfig = this.$refs.systemConfig.actualConfigData.null;
            const salesChannelId = this.$refs.systemConfig.currentSalesChannelId;

            if (salesChannelId === null) {
                return this.config[`BuckarooPayments.config.${field}`];
            }
            return this.config[`BuckarooPayments.config.${field}`]
                    || defaultConfig[`BuckarooPayments.config.${field}`];
        },

        getPaymentConfigValue(field, prefix) {
            let uppercasedField = field.charAt(0).toUpperCase() + field.slice(1);

            return this.getConfigValue(prefix + uppercasedField)
                || this.getConfigValue(field);
        },

        onSave() {
            if (this.credentialsMissing) {
                this.showValidationErrors = true;
                return;
            }

            if (!this.validateWebsiteKey()) {
                this.showValidationErrors = true;
                return;
            }

            if (!this.validateSecretKey()) {
                this.showValidationErrors = true;
                return;
            }

            this.isSaveSuccessful = false;
            this.isLoading = true;
            this.$refs.systemConfig.saveAll().then(() => {
                this.isLoading = false;
                this.isSaveSuccessful = true;
            }).catch(() => {
                this.isLoading = false;
            });
        },

        displayField(element, config) {
            let id = element.name.replace("BuckarooPayments.config.", "");
            if (id in this.collapsibleState) {
                return true;
            }

            if (id in this.collapsibleAdvancedState) {
                if (config["BuckarooPayments.config.advancedConfiguration"] != undefined && config["BuckarooPayments.config.advancedConfiguration"]) {
                    return true;
                }
            }

            let fid = id;
            id = id.split(/([A-Z][a-z]+)/).filter(function (e) {
                return e});
            id.pop();
            id = id.join("");

        if (config["BuckarooPayments.config." + id + "Enabled"] != undefined && config["BuckarooPayments.config." + id + "Enabled"]) {
            if (fid === "capayableLogo") {
                if (
                    config["BuckarooPayments.config.capayableVersion"] === undefined ||
                    config["BuckarooPayments.config.capayableVersion"] ==='v3') {
                    return true;
                }
                return false;
            }
            return true;
        }

        if (fid == 'allowedcreditcard') {
            if (config["BuckarooPayments.config.creditcardEnabled"] != undefined && config["BuckarooPayments.config.creditcardEnabled"]) {
                return true;
            }
        }

        if (fid == 'allowedcreditcards') {
            if (config["BuckarooPayments.config.creditcardsEnabled"] != undefined && config["BuckarooPayments.config.creditcardsEnabled"]) {
                return true;
            }
        }

        if (fid == 'allowedgiftcards') {
            if (config["BuckarooPayments.config.giftcardsEnabled"] != undefined && config["BuckarooPayments.config.giftcardsEnabled"]) {
                return true;
            }
        }

        if ((fid == 'applepayShowProduct') || (fid == 'applepayShowCart')) {
            if (config["BuckarooPayments.config.applepayEnabled"] != undefined && config["BuckarooPayments.config.applepayEnabled"]) {
                return true;
            }
        }

        if ((fid == 'idealRenderMode')) {
            if (config["BuckarooPayments.config.idealRenderMode"] != undefined && config["BuckarooPayments.config.idealEnabled"]) {
                return true;
            }
        }

        if ((fid == 'transferSendEmail') || (fid == 'transferDateDue')) {
            if (config["BuckarooPayments.config.transferEnabled"] != undefined && config["BuckarooPayments.config.transferEnabled"]) {
                return true;
            }
        }


        if ((fid == 'afterpayCustomerType')) {
            if (config["BuckarooPayments.config.afterpayEnabled"] != undefined && config["BuckarooPayments.config.afterpayEnabled"]) {
                return true;
            }
        }


        if ((fid == 'afterpayB2bMinAmount') || (fid == 'afterpayB2bMaxAmount')) {
            if (config["BuckarooPayments.config.afterpayEnabled"] && config["BuckarooPayments.config.afterpayCustomerType"] != undefined && config["BuckarooPayments.config.afterpayCustomerType"] != 'b2c') {
                return true;
            }
        }


            return false;
        },

        getBind(element, config) {
            if (config !== this.config) {
                this.onConfigChange(config);
            }
            if (this.showValidationErrors) {
                if (element.name === 'BuckarooPayments.config.websiteKey' && !this.websiteKeyIdFilled) {
                    element.config.error = {
                        code: 1,
                        detail: this.$tc('buckaroo-payment.messageNotBlank')
                    };
                }
                if (element.name === 'BuckarooPayments.config.secretKey' && !this.secretKeyIdFilled) {
                    element.config.error = {
                        code: 1,
                        detail: this.$tc('buckaroo-payment.messageNotBlank')
                    };
                }
                if (element.name === 'BuckarooPayments.config.websiteKey' && !this.validateWebsiteKey()) {
                    element.config.error = {
                        code: 1,
                        detail: this.$tc('buckaroo-payment.messageNotValid')
                    };
                }
                if (element.name === 'BuckarooPayments.config.secretKey' && !this.validateSecretKey()) {
                    element.config.error = {
                        code: 1,
                        detail: this.$tc('buckaroo-payment.messageNotValid')
                    };
                }
            }

            return element;
        }
    }
});
