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
            isLoading: false,
            order: false,
            buckarooTransactions: null,
            orderItems: [],
            transactionsToRefund: [],
            relatedResources: []
        };
    },

    computed: {
        orderItemsColumns() {
            return [
                {
                    property: 'name',
                    label: this.$tc('buckaroo-payment.orderItems.types.name'),
                    rawData: true
                },{
                    property: 'quantity',
                    label: this.$tc('buckaroo-payment.orderItems.types.quantity'),
                    rawData: true
                },{
                    property: 'totalAmount',
                    label: this.$tc('buckaroo-payment.orderItems.types.totalAmount'),
                    rawData: true
                }
            ];
        }, 

        transactionsToRefundColumns() {
            return [
                {
                    property: 'transaction_method',
                    rawData: true
                },{
                    property: 'amount',
                    rawData: true
                }
            ];
        },

        relatedResourceColumns() {
            return [
                {
                    property: 'created_at',
                    label: this.$tc('buckaroo-payment.transactionHistory.types.created_at'),
                    rawData: true
                },
                {
                    property: 'total',
                    label: this.$tc('buckaroo-payment.transactionHistory.types.total'),
                    rawData: true
                },{
                    property: 'shipping_costs',
                    label: this.$tc('buckaroo-payment.transactionHistory.types.shipping_costs'),
                    rawData: true
                },{
                    property: 'total_excluding_vat',
                    label: this.$tc('buckaroo-payment.transactionHistory.types.total_excluding_vat'),
                    rawData: true
                },{
                    property: 'vat',
                    label: this.$tc('buckaroo-payment.transactionHistory.types.vat'),
                    rawData: true
                },{
                    property: 'transaction_key',
                    label: this.$tc('buckaroo-payment.transactionHistory.types.transaction_key'),
                    rawData: true
                },{
                    property: 'transaction_method',
                    label: this.$tc('buckaroo-payment.transactionHistory.types.transaction_method'),
                    rawData: true
                },{
                    property: 'statuscode',
                    label: this.$tc('buckaroo-payment.transactionHistory.types.statuscode'),
                    rawData: true
                }
            ];
        }
    },

    created() {
        this.createdComponent();
    },

    methods: {
        recalculateOrderItems() {
            this.buckaroo_refund_amount = 0;
            for (const key in this.orderItems) {
                this.orderItems[key]['totalAmount'] = parseFloat(parseFloat(this.orderItems[key]['unitPrice']) * parseFloat(this.orderItems[key]['quantity'] || 0)).toFixed(2);
                this.buckaroo_refund_amount = parseFloat(parseFloat(this.buckaroo_refund_amount) + parseFloat(this.orderItems[key]['totalAmount'])).toFixed(2);
            }
        },
        
        createdComponent() {
            let that = this;
            const orderId = this.$route.params.id;
            const orderRepository = this.repositoryFactory.create('order');
            const orderCriteria = new Criteria(1, 1);
            
            this.orderId = orderId;
            orderCriteria.addAssociation('transactions');
            orderRepository.get(orderId, Context.api, orderCriteria).then((order) => {
                // that.buckaroo_refund_amount = order.amountTotal;
                // that.order = order;
            });

            this.BuckarooPaymentService.getBuckarooTransaction(orderId)
                .then((response) => {

                    response.orderItems.forEach((element) => {
                        that.orderItems.push({
                            id: element.id,
                            name: element.name,
                            quantity: element.quantity,
                            quantityMax: element.quantity,
                            unitPrice: element.unitPrice.value,
                            totalAmount: element.totalAmount.value
                        });
                    })
                    that.recalculateOrderItems();

                    response.transactionsToRefund.forEach((element) => {
                        that.transactionsToRefund.push({
                            id: element.id,
                            transactions: element.transactions,
                            amount: element.total,
                            amountMax: element.total,
                            currency: element.currency,
                            transaction_method: element.transaction_method,
                            logo: element.transaction_method?element.logo:null
                        });
                        that.currency = element.currency;
                    })

                    response.transactions.forEach((element) => {
                        that.relatedResources.push({
                            id: element.id,
                            transaction_key: element.transaction,
                            total: element.total,
                            total_excluding_vat: element.total_excluding_vat,
                            shipping_costs: element.shipping_costs,
                            vat: element.vat,
                            transaction_method: element.transaction_method,
                            logo: element.transaction_method?element.logo:null,
                            created_at: element.created_at,
                            statuscode: element.statuscode
                        });
                    })
                    
                })
                .catch((errorResponse) => {
                    console.log('errorResponse', errorResponse);
                });

        },

        refundOrder(transaction, amount) {
            let that = this;
            that.isRefundPossible = false;
            this.BuckarooPaymentService.refundPayment(transaction, this.transactionsToRefund, this.orderItems)
                .then((response) => {
                    for (const key in response) {
                        if(response[key].status){
                            this.createNotificationSuccess({
                                title: that.$tc('buckaroo-payment.settingsForm.titleSuccess'),
                                message: response[key].message
                            });
                        }else{
                            this.createNotificationError({
                                title: that.$tc('buckaroo-payment.settingsForm.titleError'),
                                message: response[key].message
                            });
                        }

                    }
                    that.isRefundPossible = true;
                })
                .catch((errorResponse) => {
                    this.createNotificationError({
                        title: this.$tc('buckaroo-payment.settingsForm.titleError'),
                        message: errorResponse.response.data.message
                    });
                    that.isRefundPossible = true;
                });
        }
    }
});
