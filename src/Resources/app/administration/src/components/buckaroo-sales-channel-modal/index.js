const { Component, Mixin } = Shopware;
import template from './buckaroo-sales-channel-modal.html.twig';
import './style.scss';

/**
 * Lets the merchant choose in which sales channels a Buckaroo payment method is offered at checkout.
 */
Component.register('buckaroo-sales-channel-modal', {
    template,

    mixins: [
        Mixin.getByName('notification')
    ],

    inject: ['syncService'],

    props: {
        paymentMethodId: {
            type: String,
            required: true
        },
        paymentTitle: {
            type: String,
            required: true
        },
        salesChannels: {
            type: Array,
            required: true
        },
        assignedSalesChannelIds: {
            type: Array,
            required: true
        }
    },

    emits: ['close', 'saved'],

    data() {
        return {
            selectedIds: [...this.assignedSalesChannelIds],
            isSaving: false
        };
    },

    computed: {
        toAdd() {
            return this.selectedIds.filter(id => !this.assignedSalesChannelIds.includes(id));
        },

        toRemove() {
            return this.assignedSalesChannelIds.filter(id => !this.selectedIds.includes(id));
        },

        hasChanges() {
            return this.toAdd.length > 0 || this.toRemove.length > 0;
        },

        hasLockedSalesChannel() {
            return this.salesChannels.some(salesChannel => this.isLocked(salesChannel));
        }
    },

    methods: {
        isSelected(id) {
            return this.selectedIds.includes(id);
        },

        /**
         * A sales channel's default payment method must stay assigned to it; Shopware does not
         * check this, so removing it here would leave the sales channel with an unavailable default
         */
        isLocked(salesChannel) {
            return salesChannel.defaultPaymentMethodId === this.paymentMethodId && this.isSelected(salesChannel.id);
        },

        getLabel(salesChannel) {
            return salesChannel.defaultPaymentMethodId === this.paymentMethodId
                ? this.$t('buckaroo-payment.paymentList.availability.defaultPaymentMethodLabel', { name: salesChannel.name })
                : salesChannel.name;
        },

        onToggle(salesChannel, checked) {
            const id = salesChannel.id;

            // sw-checkbox-field emits "update:value" (6.6+) or "change" (6.5)
            if (typeof checked !== 'boolean' || checked === this.isSelected(id) || this.isLocked(salesChannel)) {
                return;
            }

            this.selectedIds = checked
                ? [...this.selectedIds, id]
                : this.selectedIds.filter(selectedId => selectedId !== id);
        },

        async onSave() {
            const mapping = salesChannelId => ({ salesChannelId, paymentMethodId: this.paymentMethodId });
            const operations = {};

            if (this.toAdd.length) {
                operations['buckaroo-assign-sales-channels'] = {
                    entity: 'sales_channel_payment_method',
                    action: 'upsert',
                    payload: this.toAdd.map(mapping)
                };
            }
            if (this.toRemove.length) {
                operations['buckaroo-unassign-sales-channels'] = {
                    entity: 'sales_channel_payment_method',
                    action: 'delete',
                    payload: this.toRemove.map(mapping)
                };
            }

            this.isSaving = true;
            try {
                await this.syncService.sync(operations);
                this.createNotificationSuccess({
                    message: this.$tc('sw-extension-store.component.sw-extension-config.messageSaveSuccess')
                });
                this.$emit('saved');
            } catch (error) {
                this.createNotificationError({
                    message: error?.response?.data?.errors?.[0]?.detail || error?.message || String(error)
                });
            } finally {
                this.isSaving = false;
            }
        }
    }
});
