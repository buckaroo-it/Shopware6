const { Component, Mixin } = Shopware;
import template from "./buckaroo-toggle-status.html.twig";
import './style.scss'
import {
    isPaymentEnabled,
    getConfigValue,
    getPaymentMode,
    overridesInheritedValue,
    withConfigValues,
    SHARED_ENVIRONMENT_METHODS
} from '../../utils/payment-status';

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
        // "All Sales Channels" values when a sales channel is selected, else null
        inheritedValue: {
            type: Object,
            required: false,
            default: null
        },
        currentSalesChannelId: {
            required: true,
        }
    },

    emits: ['input'],

    inject: ['systemConfigApiService'],

    data() {
        return {
            isLoading: false,
        }
    },

    computed: {
        /**
         * Status the storefront uses, from only the settings it depends on: the method's own
         * "Enabled" and "Environment" (and the parent method's environment), each resolved
         * against "All Sales Channels" the way Shopware does
         *
         * @returns {'live'|'test'|'off'}
         */
        status() {
            const names = [`${this.method}Enabled`, `${this.method}Environment`];
            if (SHARED_ENVIRONMENT_METHODS[this.method]) {
                names.push(`${SHARED_ENVIRONMENT_METHODS[this.method]}Environment`);
            }

            const values = {};
            names.forEach((name) => {
                const ownValue = getConfigValue(this.value, name);

                values[`BuckarooPayments.config.${name}`] = this.canInherit && !overridesInheritedValue(ownValue)
                    ? getConfigValue(this.inheritedValue, name)
                    : ownValue;
            });

            return isPaymentEnabled(values, this.method) ? getPaymentMode(values, this.method) : 'off';
        },

        options() {
            return ['live', 'test', 'off'];
        },

        // Methods without their own environment can only be forced to live; test follows the parent method
        isTestUnavailable() {
            return Boolean(SHARED_ENVIRONMENT_METHODS[this.method]);
        },

        // Only a sales channel can inherit; "All Sales Channels" gets no inherited values
        canInherit() {
            return Boolean(this.inheritedValue);
        },

        // The sales channel has no "Enabled" value of its own, so the status comes from "All Sales Channels"
        isInherited() {
            const ownEnabled = getConfigValue(this.value, `${this.method}Enabled`);

            return this.canInherit && !overridesInheritedValue(ownEnabled);
        }
    },

    methods: {
        isOptionDisabled(option) {
            return this.isLoading || (option === 'test' && this.isTestUnavailable);
        },

        setStatus(status) {
            if (status === this.status || this.isOptionDisabled(status)) {
                return;
            }

            this.saveStatus(status);
        },

        saveStatus(status) {
            const data = { [this.enabledKey()]: status !== 'off' };
            if (status !== 'off') {
                data[this.environmentKey()] = status;
            }

            return this.persist(data);
        },

        // Saves the status the sales channel inherits as its own, so it no longer follows "All Sales Channels"
        removeInheritance() {
            return this.saveStatus(this.status);
        },

        // Removes the sales channel's own status (null deletes the values), so it follows "All Sales Channels" again
        restoreInheritance() {
            return this.persist({ [this.enabledKey()]: null, [this.environmentKey()]: null });
        },

        enabledKey() {
            return `BuckarooPayments.config.${this.method}Enabled`;
        },

        environmentKey() {
            return `BuckarooPayments.config.${this.method}Environment`;
        },

        async persist(data) {
            // The settings form updates the value object in place, so keep the previous values of
            // the changed settings themselves to undo the change when saving fails
            const previous = {};
            Object.keys(data).forEach((key) => {
                previous[key] = getConfigValue(this.value, key.replace('BuckarooPayments.config.', '')) ?? null;
            });

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
                this.$emit('input', withConfigValues(this.value, previous));
                this.createNotificationError({
                    message: error?.message || String(error),
                });
            } finally {
                this.isLoading = false;
            }
        }
    }
})
