const { Component } = Shopware;

import template from "./buckaroo-config-card.html.twig";

Component.register("buckaroo-config-card", {
    template,
    props: {
        card: {
            required: true,
        },
        methods: {
            required: true,
        },
        isNotDefaultSalesChannel: {
            required: true,
        },
        value: {
            required: true,
        },
        isLoading: {
            required: true,
        }
    },
    created() {
        console.log(this.card);
    },
    data() {
        return {
            index: 1,
            isLoading: false,
            hasCssFields: false,
        }
    },
    methods: {
        getElementBind(element, props) {
            return this.methods.getElementBind(element, props);
        },
        getInheritWrapperBind(element) {
            return this.methods.getInheritWrapperBind(element);
        },
        getFieldError(name) {
            return this.methods.getFieldError(name);
        },
        kebabCase(string) {
            return this.methods.kebabCase(string);
        },
        getInlineSnippet(title) {
            return this.methods.getInlineSnippet(title);
        },
        getInheritedValue(element) {
            return this.methods.getInheritedValue(element);
        }
    }
})