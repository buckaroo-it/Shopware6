const { Component, Filter } = Shopware;
import template from "./buckaroo-payment-list.html.twig";
import "./style.scss";

Component.register("buckaroo-payment-list", {
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
        currentSalesChannelId: {
            type: String,
            required: true
        }
    },

    emits: ['input'],

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
                    code: "blik",
                    logo: "blik.svg"
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
                    logo: "gosettle.svg"
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
                    logo: "paybybank.svg"
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
            if (this.configSettings && Array.isArray(this.configSettings)) {
                const card = this.configSettings.find((card) => card.name === code);
                if (card && card.title) {
                    try {
                        if (typeof card.title === 'object' && card.title !== null) {
                            const locale = this.$i18n?.locale || 'en-GB';

                            if (card.title[locale]) {
                                return card.title[locale];
                            }
                            if (card.title['en-GB']) {
                                return card.title['en-GB'];
                            }
                            
                            const firstKey = Object.keys(card.title)[0];
                            if (firstKey && card.title[firstKey]) {
                                return card.title[firstKey];
                            }
                            
                            return JSON.stringify(card.title);
                        }
                        
                        if (typeof card.title === 'string') {
                            if (this.$t && typeof this.$t === 'function') {
                                return this.$t(card.title);
                            }
                            return card.title;
                        }
                        
                        return String(card.title);
                        
                    } catch (error) {
                        console.warn('Translation error for:', card.title, error);
                        return typeof card.title === 'object' ? JSON.stringify(card.title) : String(card.title);
                    }
                }
            }

            const payment = this.payments.find(payment => payment.code === code);
            return payment ? payment.code : 'Unknown Payment';
        },
        assetFilter(path) {
            return Filter.getByName('asset')(path);
        }
    }
});