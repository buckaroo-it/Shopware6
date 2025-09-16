 import template from './sw-system-config.html.twig';

const { Component } = Shopware;

Component.override('sw-system-config', {
    template,
    
    createdComponent() {
        this.$super('createdComponent');
        this.loadBuckarooConfigData();
    },

    methods: {
        loadBuckarooConfigData() {
            
            this.systemConfigApiService.getValues('BuckarooPayments.config', this.currentSalesChannelId)
                .then(response => {
                    
                    if (!this.actualConfigData[this.currentSalesChannelId]) {
                        this.actualConfigData[this.currentSalesChannelId] = {};
                    }

                    const processedData = {};
                    
                    if (response && typeof response === 'object') {
                        Object.keys(response).forEach(key => {
                            const value = response[key];

                            if (value && typeof value === 'object' && value.hasOwnProperty('_value')) {
                                processedData[key] = value._value;
                            } else {
                                processedData[key] = value;
                            }

                            const shortKey = key.replace('BuckarooPayments.config.', '');
                            if (shortKey !== key) {
                                processedData[shortKey] = processedData[key];
                            }
                        });
                    }

                    this.actualConfigData[this.currentSalesChannelId] = {};
                    Object.keys(processedData).forEach(key => {
                        this.actualConfigData[this.currentSalesChannelId][key] = processedData[key];
                    });

                    this.$nextTick(() => {
                        this.$forceUpdate();
                    });
                })
                .catch(error => {
                    console.error('Error fetching system config:', error);
                });
        },

        onConfigDataUpdate(newValue) {
            if (!this.actualConfigData[this.currentSalesChannelId]) {
                this.actualConfigData[this.currentSalesChannelId] = {};
            }
            Object.keys(newValue).forEach(key => {
                this.actualConfigData[this.currentSalesChannelId][key] = newValue[key];
                if (!key.startsWith('BuckarooPayments.config.')) {
                    const fullFieldName = `BuckarooPayments.config.${key}`;
                    this.actualConfigData[this.currentSalesChannelId][fullFieldName] = newValue[key];
                }
            });
        },

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
            const currentPaymentCard = this.getCurrentConfigCard();

            if (currentPaymentCard?.elements) {
                let actualConfigValues = {};
                currentPaymentCard?.elements.forEach((element) => {
                    if (element?.name) {
                        let value = currentConfigValues[element.name];

                        if (value === undefined) {
                            const cleanFieldName = element.name.replace('BuckarooPayments.config.', '');
                            value = currentConfigValues[cleanFieldName];
                        }
                        
                        actualConfigValues[element.name] = value;
                    }
                });
                return { [this.currentSalesChannelId]: actualConfigValues };
            }

            return this.actualConfigData;
        }
    }
});
