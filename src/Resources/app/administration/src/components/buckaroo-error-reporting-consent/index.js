const { Component } = Shopware;
import template from './buckaroo-error-reporting-consent.html.twig';

const CONFIG_KEY = 'BuckarooPayments.config.errorReportingConsent';

/**
 * Asks the merchant once whether error reports may be shared with Buckaroo.
 * Shown on the Buckaroo settings page while the (global) consent is still
 * "not_asked"; the answer can be changed later in the general settings card.
 */
Component.register('buckaroo-error-reporting-consent', {
    template,

    inject: ['acl', 'systemConfigApiService'],

    mixins: [
        Shopware.Mixin.getByName('notification')
    ],

    emits: ['answered'],

    data() {
        return {
            showModal: false,
            isSaving: false
        };
    },

    created() {
        this.loadConsent();
    },

    methods: {
        canAnswer() {
            return !this.acl || this.acl.can('system_config:update');
        },

        loadConsent() {
            if (!this.canAnswer()) {
                return;
            }

            this.systemConfigApiService.getValues('BuckarooPayments.config', null)
                .then((values) => {
                    let value = values ? values[CONFIG_KEY] : undefined;
                    if (value && typeof value === 'object' && Object.prototype.hasOwnProperty.call(value, '_value')) {
                        value = value._value;
                    }
                    this.showModal = value !== 'yes' && value !== 'no';
                })
                .catch(() => {
                    this.showModal = false;
                });
        },

        answer(value) {
            this.isSaving = true;

            this.systemConfigApiService.saveValues({ [CONFIG_KEY]: value }, null)
                .then(() => {
                    this.showModal = false;
                    this.$emit('answered', value);
                })
                .catch(() => {
                    this.createNotificationError({
                        title: this.$tc('buckaroo-payment.settingsForm.titleError'),
                        message: this.$tc('buckaroo-payment.errorReporting.saveError')
                    });
                })
                .finally(() => {
                    this.isSaving = false;
                });
        }
    }
});
