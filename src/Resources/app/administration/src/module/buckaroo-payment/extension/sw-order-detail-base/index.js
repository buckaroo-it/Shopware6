import template from './sw-order-detail-base.html.twig';

const { Component, Context } = Shopware;
const Criteria = Shopware.Data.Criteria;

Component.override('sw-order-detail-base', {
    template
});
