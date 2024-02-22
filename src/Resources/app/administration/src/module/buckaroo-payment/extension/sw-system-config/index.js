import template from './sw-system-config.html.twig';

const { Component } = Shopware;

Component.override('sw-system-config', {
    template,
    methods: {
        saveAll() {
            if (this.domain !== 'BuckarooPayments.config') {
                return this.$super('saveAll');
            }
            return this.saveBuckaroo();
        },
        /** save only the current values */
        saveBuckaroo() {
            this.isLoading = true;
            return this.systemConfigApiService
                .batchSave(this.getSelectedValues())
                .finally(() => {
                    this.isLoading = false;
                });
        },
        getCurrentConfigCard() {
            const code = this.$route.params?.paymentCode || 'general';
            return this.config.filter((card) => card.name === code)?.pop()
        },
        getSelectedValues() {
            const currentConfigValues = this.actualConfigData[this.currentSalesChannelId];
            const currentPaymentCard = this.getCurrentConfigCard();

            if (currentPaymentCard?.elements) {
                let actualConfigValues = {};
                currentPaymentCard?.elements.forEach((element) => {
                    if (element?.name) {
                        actualConfigValues[element.name] = currentConfigValues[element.name];
                    }
                })
                console.log({[this.currentSalesChannelId]: actualConfigValues});

                return {[this.currentSalesChannelId]: actualConfigValues}
            }

            return this.actualConfigData;

        }
    }
})