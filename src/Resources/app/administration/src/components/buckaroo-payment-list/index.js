const { Component, Filter, Mixin } = Shopware;
import template from "./buckaroo-payment-list.html.twig";
import "./style.scss";

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
        Mixin.getByName('sw-inline-snippet'),
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
                    code: "knaken",
                    logo: "knaken.svg"
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
        };
    },
    methods: {
        getPaymentTitle(code) {
            const card = this.configSettings.find((card) => card.name === code);

            if(card) {
                return this.getInlineSnippet(card.title)
            }

            const payment = this.payments.find(payment => payment.code === code);
            return payment ? payment.code : 'Unknown Payment';
        },
        assetFilter(path) {
            return Filter.getByName('asset')(path);
        }
    }
});