const { Component } = Shopware;

import template from "./buckaroo-main-config.html.twig";

Component.register("buckaroo-main-config", {
    template,
    props: {
        configSettings: {
            type: Array,
            required: false,
            default: () => []
        },
        value: {
            type: Object,
            required: false,
            default: () => ({})
        },
        elementMethods: {
            type: Object,
            required: false,
            default: () => ({})
        },
        isNotDefaultSalesChannel: {
            type: Boolean,
            required: false,
            default: false
        },
        currentSalesChannelId: {
            type: String,
            required: false,
            default: null
        }
    },
    emits: ['input'],

    model: {
        prop: 'value',
        event: 'input'
    },


    data() {
        return {
            selectedCard: this.$route.params?.paymentCode || 'general'
        }
    },

    watch: {
        value: {
            handler(newVal, oldVal) {
                this.$nextTick(() => {
                    this.$forceUpdate();
                });
            },
            deep: true,
            immediate: true
        },
        $route(to) {
            if (to.params?.paymentCode) {
                this.selectedCard = to.params.paymentCode;
            }
        }
    },

    computed: {
        mainCard() {
            const card = this.configSettings.filter((card) => card.name === this.selectedCard)?.pop();
            return card;
        }
    },

    methods: {
        onInput(value) {
            this.$emit('input', value);
        }
    }

})