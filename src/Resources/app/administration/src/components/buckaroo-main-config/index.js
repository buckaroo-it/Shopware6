const { Component } = Shopware;

import template from "./buckaroo-main-config.html.twig";

Component.register("buckaroo-main-config", {
    template,
    props: {
        configSettings: {
            type: Array,
            required: true,
        },
        modelValue: {
            type: Object,
            required: true,
        },
        elementMethods: {
            type: Object,
            required: true,
        },
        isNotDefaultSalesChannel: {
            type: Boolean,
            required: true,
        },
        currentSalesChannelId: {
            type: String,
            required: true,
        }
    },
    data() {
        return {
            selectedCard: this.$route.params?.paymentCode || 'general',
            localConfigData: { ...this.modelValue }
        };
    },
    watch: {
        $route(to) {
            if (to.params?.paymentCode) {
                this.selectedCard = to.params.paymentCode;
            }
        },
        modelValue: {
            handler(newValue) {
                this.localConfigData = { ...newValue };
                console.log('modelValue changed:', newValue);
            },
            deep: true
        },
        localConfigData: {
            handler(newValue) {
                this.$emit('update:modelValue', newValue);
                console.log('localConfigData changed:', newValue);
            },
            deep: true
        }
    },
    computed: {
        mainCard() {
            return this.configSettings.filter((card) => card.name === this.selectedCard)?.pop();
        }
    },
    created() {
        console.log('Initial localConfigData:', this.localConfigData);
    }
});
