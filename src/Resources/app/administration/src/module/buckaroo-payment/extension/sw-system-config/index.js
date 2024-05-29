import template from './sw-system-config.html.twig';

const { Component } = Shopware;

Component.override('sw-system-config', {
    template,
    methods: {
        saveAll() {
            console.log("saveAll called, domain:", this.domain);
            if (this.domain !== 'BuckarooPayments.config') {
                return this.$super('saveAll');
            }
            return this.saveBuckaroo();
        },
        saveBuckaroo() {
            console.log("saveBuckaroo called");
            this.isLoading = true;
            return this.systemConfigApiService
                .batchSave(this.getSelectedValues())
                .finally(() => {
                    this.isLoading = false;
                });
        },
        getCurrentConfigCard() {
            const code = this.$route.params?.paymentCode || 'general';
            return this.config.filter((card) => card.name === code)?.pop();
        },
        getSelectedValues() {
            const currentConfigValues = this.actualConfigData[this.currentSalesChannelId];
            console.log("currentConfigValues at sw-system-config:", currentConfigValues);

            const currentPaymentCard = this.getCurrentConfigCard();
            console.log("currentPaymentCard:", currentPaymentCard);

            if (currentPaymentCard?.elements) {
                let actualConfigValues = {};
                currentPaymentCard?.elements.forEach((element) => {
                    console.log("Element name:", element.name);
                    console.log("Current value for element:", currentConfigValues[element.name]);
                    if (element?.name) {
                        actualConfigValues[element.name] = currentConfigValues[element.name];
                    }
                });
                console.log("Actual config values:", actualConfigValues);

                return { [this.currentSalesChannelId]: actualConfigValues };
            }

            return this.actualConfigData;
        }
    }
});
