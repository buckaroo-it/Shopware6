const { Component } = Shopware;
import template from "./buckaroo-test-credentials.twig";

Component.register("buckaroo-test-credentials", {
    template,
    data() {
        return {
            isLoading: false,
        }
    },
    inject: [ 'BuckarooPaymentSettingsService' ],

    props: {
        config: {
            type: Object,
            required: true
        },
        currentSalesChannelId: {
            required: true
        }
    },
    computed: {
        enabled: function() {
            return (this.getConfigValue('websiteKey') || '').length > 0 &&
            (this.getConfigValue('secretKey') || '').length > 0
        }
    },
    methods: {
        getConfigValue: function(name) {
            return this.config["BuckarooPayments.config."+name];
        },
        sendTestApi() {
            this.isLoading = true;
            let websiteKeyId = this.getConfigValue('websiteKey'),
                secretKeyId = this.getConfigValue('secretKey');
            this.BuckarooPaymentSettingsService.getApiTest(websiteKeyId, secretKeyId, this.currentSalesChannelId)
                .then((result) => {
                    this.isLoading = false;

                    if (result.status == 'success') {
                        this.$store.dispatch('notification/createNotification', {
                            variant: 'success',
                            title: this.$tc('buckaroo-payment.settingsForm.titleSuccess'),
                            message: this.$tc(result.message)
                        });
                    } else {
                        this.$store.dispatch('notification/createNotification', {
                            variant: 'error',
                            title: this.$tc('buckaroo-payment.settingsForm.titleError'),
                            message: this.$tc(result.message)
                        });
                    }

                })
                .catch(() => {
                    this.isLoading = false;
                });
        },
    }
})