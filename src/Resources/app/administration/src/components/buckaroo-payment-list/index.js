const { Component } = Shopware;

import template from "./buckaroo-payment-list.html.twig";
import "./style.scss";
const { Mixin } = Shopware;

Component.register("buckaroo-payment-list", {
    template,
    props: {
        configSettings: {
            required: true
        },
        value: {
            required: true
        },
        currentSalesChannelId: {
            required: true
        }
    },
    mixins: [
        Mixin.getByName('sw-inline-snippet')
    ],
    data() {
        return {
            payments: [
                {
                    code: "Alipay",
                    logo: "alipay.svg"
                },
                {
                    code: "applepay",
                    logo: "applepay.svg"
                },
                {
                    code: "bancontactmrcash",
                    logo: "bancontact.svg"
                },
                {
                    code: "belfius",
                    logo: "belfius.svg"
                },
                {
                    code: "Billink",
                    logo: "billink.svg"
                },
                {
                    code: "creditcard",
                    logo: "creditcards.svg"
                },
                {
                    code: "creditcards",
                    logo: "creditcards.svg"
                },
                {
                    code: "eps",
                    logo: "eps.svg"
                },
                {
                    code: "giftcards",
                    logo: "giftcards.svg"
                },
                {
                    code: "giropay",
                    logo: "giropay.svg"
                },
                {
                    code: "idealqr",
                    logo: "ideal-qr.svg"
                },
                {
                    code: "ideal",
                    logo: "ideal.svg"
                },
                {
                    code: "capayable",
                    logo: "ideal-in3.svg"
                },
                {
                    code: "KBCPaymentButton",
                    logo: "kbc.svg"
                },
                {
                    code: "klarnakp",
                    logo: "klarna.svg"
                },
                {
                    code: "mbway",
                    logo: "mbway.svg"
                },
                {
                    code: "multibanco",
                    logo: "multibanco.svg"
                },
                {
                    code: "paybybank",
                    logo: "paybybank.gif"
                },
                {
                    code: "payconiq",
                    logo: "payconiq.svg"
                },
                {
                    code: "paypal",
                    logo: "paypal.svg"
                },
                {
                    code: "payperemail",
                    logo: "payperemail.svg"
                },
                {
                    code: "Przelewy24",
                    logo: "przelewy24.svg"
                },
                {
                    code: "afterpay",
                    logo: "afterpay.svg"
                },
                {
                    code: "sofortueberweisung",
                    logo: "sofort.svg"
                },
                {
                    code: "sepadirectdebit",
                    logo: "sepa-directdebit.svg"
                },
                {
                    code: "transfer",
                    logo: "sepa-credittransfer.svg"
                },
                {
                    code: "Trustly",
                    logo: "trustly.svg"
                },
                {
                    code: "WeChatPay",
                    logo: "wechatpay.svg"
                }

            ]
        }
    },
    methods: {
        getPaymentTitle(code) {
            const card = this.configSettings.filter((card) => card.name === code)?.pop();
            if (card.title) {
                return this.getInlineSnippet(card.title);
            }
            return code
        }
    }
})