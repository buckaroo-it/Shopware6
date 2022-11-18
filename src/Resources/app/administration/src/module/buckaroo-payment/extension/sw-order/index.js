import template from './sw-order.html.twig';

const { Component, Context } = Shopware;
const Criteria = Shopware.Data.Criteria;

Component.override('sw-order-detail', {
    template,

    data() {
        return {
            isBuckarooPayment: false,
            isPaymentInTestMode: false
        };
    },

    computed: {
        isEditable() {
            return !this.isBuckarooPayment || this.$route.name !== 'buckaroo.payment.detail';
        },

        showTabs() {
            return true;
        }
    },

    watch: {
        orderId: {
            deep: true,
            handler() {
                if (!this.orderId) {
                    this.setIsBuckarooPayment(null);
                    return;
                }

                const orderRepository = this.repositoryFactory.create('order');
                const orderCriteria = new Criteria(1, 1);
                orderCriteria.addAssociation('transactions');

                orderRepository.get(this.orderId, Context.api, orderCriteria).then((order) => {

                    this.setPaymentInTestMode(order);

                    if (order.transactions.length <= 0 ||
                        !order.transactions.last().paymentMethodId
                    ) {
                        this.setIsBuckarooPayment(null);
                        return;
                    }

                    const paymentMethodId = order.transactions.last().paymentMethodId;

                    if (paymentMethodId !== undefined && paymentMethodId !== null) {
                        this.setIsBuckarooPayment(paymentMethodId);
                    }
                });
            },
            immediate: true
        }
    },

    methods: {
        setPaymentInTestMode(order) {
            if (order.customFields && order.customFields.buckaroo_payment_in_test_mode) {
                this.isPaymentInTestMode = order.customFields.buckaroo_payment_in_test_mode === true;
            }
        },
        setIsBuckarooPayment(paymentMethodId) {
            if (!paymentMethodId) {
                return;
            }
            const paymentMethodRepository = this.repositoryFactory.create('payment_method');
            paymentMethodRepository.get(paymentMethodId, Context.api).then(
                (paymentMethod) => {
                    this.isBuckarooPayment = paymentMethod.formattedHandlerIdentifier.indexOf('buckaroo') >= 0;
                }
            );
        }
    }
});