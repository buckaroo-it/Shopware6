const { Component } = Shopware;
import template from './sw-system-config.html.twig';

Component.override('sw-system-config', {
    template,
    methods: {
        saveAll() {
            if (this.domain !== 'BuckarooPayments.config') {
                return this.$super('saveAll');
            }
            return this.saveBuckaroo();
        },
        saveBuckaroo() {
            this.isLoading = true;
            return this.systemConfigApiService
                .batchSave(this.getSelectedValues())
                .then(() => {
                    this.createNotificationSuccess({
                        title: this.$tc('global.default.success'),
                        message: this.$tc('sw-settings.system-config.messageSaveSuccess')
                    });
                })
                .catch(() => {
                    this.createNotificationError({
                        title: this.$tc('global.default.error'),
                        message: this.$tc('sw-settings.system-config.messageSaveError')
                    });
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },
        getCurrentConfigCard() {
            const code = this.$route.params?.paymentCode || 'general';
            return this.config.find((card) => card.name === code);
        },
        getSelectedValues() {
            const currentConfigValues = this.actualConfigData[this.currentSalesChannelId];
            const currentPaymentCard = this.getCurrentConfigCard();

            if (currentPaymentCard?.elements) {
                let actualConfigValues = {};
                currentPaymentCard.elements.forEach((element) => {
                    if (element?.name) {
                        actualConfigValues[element.name] = currentConfigValues[element.name];
                    }
                });

                return { [this.currentSalesChannelId]: actualConfigValues };
            }

            return this.actualConfigData;
        }
    }
});
