import './extension/sw-order';
import './page/buckaroo-payment-detail';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

const { Module } = Shopware;

Module.register('buckaroo-payment', {
    type: 'plugin',
    name: 'BuckarooPayment',
    title: 'buckaroo.general.title',
    description: 'buckaroo.general.description',
    version: '1.0.0',
    targetVersion: '1.0.0',
    color: '#000000',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB
    },

    routeMiddleware(next, currentRoute) {
        if (currentRoute.name === 'sw.order.detail') {
            currentRoute.children.push({
                component: 'buckaroo-payment-detail',
                name: 'buckaroo.payment.detail',
                isChildren: true,
                path: '/sw/order/buckaroo/detail/:id'
            });
        }
        next(currentRoute);
    }
});
