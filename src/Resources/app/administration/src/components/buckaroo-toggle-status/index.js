const { Component, Mixin } = Shopware;
import template from "./buckaroo-toggle-status.html.twig";
import './style.scss'
import { isPaymentEnabled, getPaymentMode, withConfigValues, SHARED_ENVIRONMENT_METHODS } from '../../utils/payment-status';

Component.register("buckaroo-toggle-status", {
    template,

    mixins: [
        Mixin.getByName('notification')
    ],

    props: {
        method: {
            type: String,
            required: true
        },
        label: {
            type: String,
            required: false,
            default: ''
        },
        // Title of the method whose test mode this method follows (e.g. iDEAL for iDEAL QR)
        sharedEnvironmentLabel: {
            type: String,
            required: false,
            default: ''
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
            status: 'off',
            isLoading: false,
        }
    },

    computed: {
        options() {
            return ['live', 'test', 'off'];
        },

        // Methods without their own environment can only be forced to live; test follows the parent method
        isTestUnavailable() {
            return Boolean(SHARED_ENVIRONMENT_METHODS[this.method]);
        }
    },

    watch: {
        value: {
            handler() {
                this.status = this.getStatus();
            },
            deep: true,
            immediate: true
        }
    },

    methods: {
        getStatus() {
            return isPaymentEnabled(this.value, this.method) ? getPaymentMode(this.value, this.method) : 'off';
        },

        isOptionDisabled(option) {
            return this.isLoading || (option === 'test' && this.isTestUnavailable);
        },

        setStatus(status) {
            if (status === this.status || this.isOptionDisabled(status)) {
                return;
            }

            this.saveStatus(status);
        },

        async saveStatus(status) {
            const enabledKey = `BuckarooPayments.config.${this.method}Enabled`;
            const environmentKey = `BuckarooPayments.config.${this.method}Environment`;
            const previousValue = this.value;

            const data = { [enabledKey]: status !== 'off' };
            if (status !== 'off') {
                data[environmentKey] = status;
            }

            this.status = status;
            this.$emit('input', withConfigValues(this.value, data));

            this.isLoading = true;
            try {
                await this.systemConfigApiService.batchSave({
                    [this.currentSalesChannelId]: data
                });
                this.createNotificationSuccess({
                    message: this.$tc('sw-extension-store.component.sw-extension-config.messageSaveSuccess'),
                });
            } catch (error) {
                this.$emit('input', previousValue);
                this.status = this.getStatus();
                this.createNotificationError({
                    message: error?.message || String(error),
                });
            } finally {
                this.isLoading = false;
            }
        }
    }
})
