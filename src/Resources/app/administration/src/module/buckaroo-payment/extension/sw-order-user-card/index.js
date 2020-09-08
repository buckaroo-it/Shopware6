import template from './sw-order-user-card.html.twig';

const { Component } = Shopware;

Component.override('sw-order-user-card', {
    template,

    inject: [ 'systemConfigApiService' ],

    data() {
        return {
            config: {}
        };
    },

    created() {
        this.systemConfigApiService.getValues('BuckarooPayment.config', null)
            .then(values => {
                this.config = values;
            })
            .finally(() => {
            });
    }

});
