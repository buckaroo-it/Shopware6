 import template from './sw-system-config.html.twig';

const { Component } = Shopware;

Component.override('sw-system-config', {
    template,
    
    watch: {
        currentSalesChannelId: {
            handler(newVal) {
                if (newVal && this.domain === 'BuckarooPayments.config') {
                    this.loadBuckarooConfigData();
                }
            },
            immediate: true
        },
        domain: {
            handler(newVal) {
                if (newVal === 'BuckarooPayments.config' && this.currentSalesChannelId) {
                    this.loadBuckarooConfigData();
                }
            },
            immediate: true
        }
    },

    methods: {
        loadBuckarooConfigData() {
            const salesChannelId = this.currentSalesChannelId;

            this.systemConfigApiService.getValues('BuckarooPayments.config', salesChannelId)
                .then(response => {
                    const processedData = {};
                    
                    if (response && typeof response === 'object') {
                        Object.keys(response).forEach(key => {
                            const value = response[key];

                            if (value && typeof value === 'object' && Object.prototype.hasOwnProperty.call(value, '_value')) {
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

                    this.setBuckarooConfigValues(salesChannelId, processedData);
                })
                .catch(error => {
                    console.error('Error fetching system config:', error);
                });
        },

        onConfigDataUpdate(newValue) {
            const values = { ...this.actualConfigData[this.currentSalesChannelId] };

            Object.keys(newValue).forEach(key => {
                values[key] = newValue[key];
                if (!key.startsWith('BuckarooPayments.config.')) {
                    values[`BuckarooPayments.config.${key}`] = newValue[key];
                }
            });

            this.setBuckarooConfigValues(this.currentSalesChannelId, values);
        },

        /**
         * Replaces the values of a sales channel with a new object instead of adding keys one by
         * one: Vue 2 (Shopware 6.5) does not notice keys added to an existing object, so fields
         * that depend on a switch that was never saved would not appear. A new object is noticed
         * by Vue 2 and Vue 3, without Vue.set, which Shopware 6.7 does not have.
         */
        setBuckarooConfigValues(salesChannelId, values) {
            this.actualConfigData = {
                ...this.actualConfigData,
                [salesChannelId]: values,
            };
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
                .catch((error) => {
                    throw this.getBuckarooSaveError(error);
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        // Order amount limits are checked when they are saved; show that check's message
        // translated in the save error notification. Other errors are passed on as they are.
        getBuckarooSaveError(error) {
            const apiError = error?.response?.data?.errors?.[0];
            const salesChannelId = apiError?.meta?.parameters?.salesChannelId ?? null;

            if (apiError?.code === 'BUCKAROO__ORDER_AMOUNT_LIMIT_INVALID') {
                return this.$tc('buckaroo-payment.paymentSettings.orderAmountLimitInvalid');
            }

            if (apiError?.code === 'BUCKAROO__ORDER_AMOUNT_LIMIT_RANGE') {
                return salesChannelId && salesChannelId !== this.currentSalesChannelId
                    ? this.$tc('buckaroo-payment.paymentSettings.orderAmountLimitRangeSalesChannel')
                    : this.$tc('buckaroo-payment.paymentSettings.orderAmountLimitRange');
            }

            return error;
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
