const { Component } = Shopware;

import template from "./buckaroo-main-config.html.twig";

Component.register("buckaroo-main-config", {
    template,
    props: {
        configSettings: {
            required: true,
        },
        value: {
            required: true,
        },
        elementMethods: {
            required: true,
        },
        isNotDefaultSalesChannel: {
            required: true,
        },
        currentSalesChannelId: {
            required: true,
        }
    },
    data() {
        return {
            selectedCard: this.$route.params?.paymentCode || 'general'
        }
    },

    watch: {
        $route(to) {
          if (to.params?.paymentCode) {
              this.selectedCard = to.params.paymentCode
          }
        }
    },

    computed: {
        mainCard() {
            return this.configSettings.filter((card) => card.name === this.selectedCard)?.pop()
        }
    }
}) 