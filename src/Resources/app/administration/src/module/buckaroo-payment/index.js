const { Module } = Shopware;

import './page/buckaroo-settings';

import './extension/sw-order';
import './extension/sw-order-detail-base';
import './extension/sw-order-user-card';
import './extension/sw-system-config';
import './page/buckaroo-payment-detail';

import './page/buckaroo-payment-config';

import nlNL from './snippet/nl-NL.json';
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Module.register('buckaroo-payment', {
    type: 'plugin',
    name: 'BuckarooPayment',
    title: 'buckaroo-payment.general.title',
    description: 'buckaroo-payment.general.description',
    version: '1.0.0',
    targetVersion: '1.0.0',
    color: '#000000',
    icon: 'default-action-settings',

    snippets: {
        'nl-NL': nlNL,
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
    },

    routes: {
        config: {
            component: 'buckaroo-payment-config',
            path: ':namespace/payment/:paymentCode',
            name: 'buckaroo.config.payment',
            meta: {
                parentPath:'sw.extension.config'
            },
            props: {
                default(route) {
                    return { namespace: route.params.namespace };
                },
            },
        }
    }
});
