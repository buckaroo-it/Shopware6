const { Component } = Shopware;

Component.override('sw-context-menu-item', {
    mounted () {
        // override the default configuration page with our page
        if(this.routerLink.params.namespace == 'BuckarooPayments') {
            this.routerLink = { name: 'buckaroo.payment.index' };
        }
    }
});
