const { Component } = Shopware;

import template from './buckaroo-afterpay-old-tax.html.twig';

Component.register('buckaroo-afterpay-old-tax', {
    template,

    inject: ['BuckarooPaymentSettingsService'],

    data() {
        return {
            taxes: [],
            showTaxes: false,
            afterpayTaxes: [
                { name: this.$tc('buckaroo-payment.afterpay.hightTaxes'), id: 1 },
                { name: this.$tc('buckaroo-payment.afterpay.middleTaxes'), id: 5 },
                { name: this.$tc('buckaroo-payment.afterpay.lowTaxes'), id: 2 },
                { name: this.$tc('buckaroo-payment.afterpay.zeroTaxes'), id: 3 },
                { name: this.$tc('buckaroo-payment.afterpay.noTaxes'), id: 4 },
            ],
            taxAssociation: {}
        };
    },

    model: {
        prop: 'value',
        event: 'change',
    },

    computed: {

    },
    props: {
        name: {
            type: String,
            required: true,
            default: ''
        },
        value: {
            type: Object,
            required: false,
            default() {
                return {}
            }
        }
    },


    created() {
        this.BuckarooPaymentSettingsService.getTaxes()
            .then((result) => {
                this.taxes = result.taxes.map((tax) => {
                    return {
                        id: tax.id,
                        name: tax.name
                    };
                })
            });

    },
    methods: {
        setTaxAssociation(taxId, value) {
            this.taxAssociation[taxId] = value;
            this.$emit('change', {...this.value, ...this.taxAssociation});
        },
        getSelectValue(taxId) {
            if (this.value[taxId]) {
                return this.value[taxId];
            }
            return;
        }
    }
});
