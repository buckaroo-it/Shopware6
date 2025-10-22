const { Component } = Shopware;
import template from "./buckaroo-toggle-status.html.twig";
import './style.scss'

Component.register("buckaroo-toggle-status", {
    template,
    props: {
        method: {
            type: String,
            required: true
        },
        value: {
            required: true
        },
        currentSalesChannelId: {
            required: true,
        }
    },

    emits: ['input'],

    inject: ['systemConfigApiService'],
    data() {
        return {
            status: 'disabled',
            isLoading: false,
        }
    },

    mounted() {
        this.status = this.getStatus();
    },

    watch: {
        value: {
            handler(newVal) {
                this.status = this.getStatus();
            },
            deep: true,
            immediate: true
        }
    },
    methods: {
        getStatus() {
            const isActive = this.isActive();
            const environment = this.getEnvironment();
            return isActive ? environment : 'disabled';
        },
        isActive() {
            const enabled = this.getValueForName(`${this.method}Enabled`);
            if (typeof enabled === 'string') {
                return enabled.toLowerCase() === 'true';
            }
            return Boolean(enabled);
        },
        getEnvironment() {
            const env = this.getValueForName(`${this.method}Environment`);
            
            if (env === undefined || env === null || env === '') {
                return 'test';
            }
            const validEnvs = ['test', 'live'];
            return validEnvs.includes(env) ? env : 'test';
        },
        getValueForName(name) {
            const key = `BuckarooPayments.config.${name}`;
            if (!this.value || typeof this.value !== 'object') {
                return null;
            }

            let val = undefined;

            if (this.value[key] !== undefined) {
                val = this.value[key];
            }
            else if (this.value[name] !== undefined) {
                val = this.value[name];
            }
            else if (this.value['BuckarooPayments.config'] && typeof this.value['BuckarooPayments.config'] === 'object') {
                if (this.value['BuckarooPayments.config'][name] !== undefined) {
                    val = this.value['BuckarooPayments.config'][name];
                }
            }
            else {
                const variations = [
                    name,
                    name.toLowerCase(),
                    name.charAt(0).toLowerCase() + name.slice(1),
                    name.charAt(0).toUpperCase() + name.slice(1)
                ];
                
                for (const variation of variations) {
                    const variationKey = `BuckarooPayments.config.${variation}`;
                    if (this.value[variationKey] !== undefined) {
                        val = this.value[variationKey];
                        break;
                    }
                    if (this.value[variation] !== undefined) {
                        val = this.value[variation];
                        break;
                    }
                }
            }

            if (val && typeof val === 'object' && val.hasOwnProperty('_value')) {
                val = val._value;
            }
            
            return val;
        },
        setStatus(status) {
            this.status = status;
            this.saveStatus();
        },
        getClass(buttonStatus) {
            return this.status === buttonStatus ? 'active' : '';
        },
        async saveStatus() {
            const enabledKey = `BuckarooPayments.config.${this.method}Enabled`;
            const environmentKey = `BuckarooPayments.config.${this.method}Environment`;

            let data = {[enabledKey]: false};
            const updatedValue = { ...this.value };
            updatedValue[enabledKey] = false;

            if (['live', 'test'].indexOf(this.status) !== -1) {
                data = {
                    [enabledKey]: true,
                    [environmentKey]: this.status
                }
                updatedValue[enabledKey] = true;
                updatedValue[environmentKey] = this.status;
            }

            this.$emit('input', updatedValue);

            this.isLoading = true;
            try {
                await this.systemConfigApiService
                .batchSave({[this.currentSalesChannelId]: data})
                .finally(() => {
                    this.isLoading = false;
                });
                this.renderSuccess();
            } catch (error) {
                this.renderError(error);
            }
           
        },
        renderSuccess() {
            this.$store.dispatch('notification/createNotification', {
                variant: 'success',
                message: this.$tc('sw-extension-store.component.sw-extension-config.messageSaveSuccess'),
            });
        },

        renderError(err) {
            this.$store.dispatch('notification/createNotification', {
                variant: 'error',
                message: err,
            });
        }
    }
})