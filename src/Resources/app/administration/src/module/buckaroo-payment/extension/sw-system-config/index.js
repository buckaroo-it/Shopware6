import template from './sw-system-config.html.twig';

const { Component } = Shopware;

Component.override('sw-system-config', {
    template,
})