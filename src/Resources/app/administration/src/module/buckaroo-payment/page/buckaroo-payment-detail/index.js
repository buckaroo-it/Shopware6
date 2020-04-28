import template from './buckaroo-payment-detail.html.twig';
import './buckaroo-payment-detail.scss';

const { Component, Mixin, Filter, Context } = Shopware;
const Criteria = Shopware.Data.Criteria;

Component.register('buckaroo-payment-detail', {
    template,

    inject: [
        'repositoryFactory',
        'BuckarooPaymentService'
    ],
    
    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            buckaroo_refund_amount: '0',
            currency: 'EUR',
            isRefundPossible: true,
            isLoading: false
        };
    },

    computed: {
        dateFilter() {
            return Filter.getByName('date');
        }
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            let that = this;
            const orderId = this.$route.params.id;
            const orderRepository = this.repositoryFactory.create('order');
            const orderCriteria = new Criteria(1, 1);
            
            this.orderId = orderId;
            orderCriteria.addAssociation('transactions');
            orderRepository.get(orderId, Context.api, orderCriteria).then((order) => {
                that.buckaroo_refund_amount = order.amountTotal.toFixed(2);
            });
        },

        refundOrder(transaction, amount) {
            let that = this;
            that.isRefundPossible = false;
            this.BuckarooPaymentService.refundPayment(transaction, amount)
                .then(() => {
                    this.createNotificationSuccess({
                        title: this.$tc('buckaroo-payment.refund.successTitle'),
                        message: this.$tc('buckaroo-payment.refund.successMessage') + this.buckaroo_refund_amount + ' ' + this.currency
                    });
                    that.isRefundPossible = true;
                    that.reloadEntityData();
                })
                .catch((errorResponse) => {
                    this.createNotificationError({
                        title: this.$tc('buckaroo-payment.refund.errorTitle'),
                        message: errorResponse.response.data.message
                    });
                    that.isRefundPossible = true;
                });
        }
    }
});
