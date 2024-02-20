const { Component } = Shopware;

import template from "./buckaroo-payment-list.html.twig";
import "./style.scss";
const { Mixin } = Shopware;

Component.register("buckaroo-payment-list", {
    template,
    props: {
        configSettings: {
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
                    code: "creditcard",
                    logo: "creditcards.svg"
                },
                {
                    code: "creditcards",
                    logo: "creditcards.svg"
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
                    code: "idealprocessing",
                    logo: "ideal.svg"
                },
                {
                    code: "bancontactmrcash",
                    logo: "bancontact.svg"
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
                    code: "paypal",
                    logo: "paypal.svg"
                },
                {
                    code: "transfer",
                    logo: "transfer.svg"
                },
                {
                    code: "giropay",
                    logo: "giropay.svg"
                },
                {
                    code: "KBCPaymentButton",
                    logo: "kbc.svg"
                },
                {
                    code: "sepadirectdebit",
                    logo: "sepa-directdebit.svg"
                },
                {
                    code: "payconiq",
                    logo: "payconiq.svg"
                },
                {
                    code: "applepay",
                    logo: "applepay.svg"
                },
                {
                    code: "giftcards",
                    logo: "giftcards.svg"
                },
                {
                    code: "capayable",
                    logo: "ideal-in3.svg"
                },
                {
                    code: "eps",
                    logo: "eps.svg"
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
                    code: "Przelewy24",
                    logo: "przelewy.svg"
                },
                {
                    code: "Alipay",
                    logo: "alipay.svg"
                },
                {
                    code: "WeChatPay",
                    logo: "wechatpay.svg"
                },
                {
                    code: "Trustly",
                    logo: "trustly.svg"
                },
                {
                    code: "klarnakp",
                    logo: "klarna.svg"
                },
                {
                    code: "Billink",
                    logo: "billink.svg"
                },
                {
                    code: "belfius",
                    logo: "belfius.svg"
                },
                {
                    code: "payperemail",
                    logo: "payperemail.svg"
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