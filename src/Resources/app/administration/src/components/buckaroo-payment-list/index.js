const { Component, Filter } = Shopware;
import template from "./buckaroo-payment-list.html.twig";
import "./style.scss";
import { isPaymentEnabled, SHARED_ENVIRONMENT_METHODS } from '../../utils/payment-status';

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

    inject: ['repositoryFactory'],

    data() {
        return {
            // Shopware payment method per Buckaroo code: { id, active, salesChannelIds }
            paymentMethodsByCode: {},
            // Storefront and headless sales channels: [{ id, name }]
            salesChannels: [],
            assignModalPayment: null,
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
                    code: "googlepay",
                    logo: "googlepay.svg"
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
                    logo: "ideal-wero.svg"
                },
                {
                    code: "capayable",
                    logo: "in3.svg"
                },
                {
                    code: "KBCPaymentButton",
                    logo: "kbc.svg"
                },
                {
                    code: "klarna",
                    logo: "klarna.svg"
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
                },
                {
                    code: "swish",
                    logo: "swish.svg"
                },
                {
                    code: "bizum",
                    logo: "bizum.svg"
                },
                {
                    code: "twint",
                    logo: "twint.svg"
                },
                {
                    code: "wero",
                    logo: "wero.svg"
                }
            ]
        };
    },
    computed: {
        // Alphabetical by the title shown to the merchant, not by internal code
        sortedPayments() {
            return this.payments
                .map(payment => ({ ...payment, title: this.getPaymentTitle(payment.code) }))
                .sort((a, b) => a.title.localeCompare(b.title, undefined, { sensitivity: 'base' }));
        },

        activeCount() {
            return this.payments.filter(payment => this.isActive(payment.code)).length;
        }
    },

    created() {
        this.loadAvailability();
    },

    methods: {
        /**
         * The plugin setting only hides a method; customers see it only when the Shopware payment
         * method is also active and assigned to the sales channel. Load that state to warn about it.
         */
        async loadAvailability() {
            const { Criteria } = Shopware.Data;
            const context = Shopware.Context.api;

            const paymentCriteria = new Criteria(1, 100);
            paymentCriteria.addFilter(Criteria.contains('handlerIdentifier', 'Buckaroo'));
            paymentCriteria.addAssociation('salesChannels');

            const salesChannelCriteria = new Criteria(1, 100);
            salesChannelCriteria.addFilter(Criteria.equalsAny('typeId', [
                Shopware.Defaults?.storefrontSalesChannelTypeId || '8a243080f92e4c719546314b577cf82b',
                Shopware.Defaults?.apiSalesChannelTypeId || 'f183ee5650cf4bdb8a774337575067a6'
            ]));
            salesChannelCriteria.addSorting(Criteria.sort('name', 'ASC'));

            try {
                const [paymentMethods, salesChannels] = await Promise.all([
                    this.repositoryFactory.create('payment_method').search(paymentCriteria, context),
                    this.repositoryFactory.create('sales_channel').search(salesChannelCriteria, context)
                ]);

                const byCode = {};
                paymentMethods.forEach(paymentMethod => {
                    const code = paymentMethod.customFields?.buckaroo_key;
                    if (code) {
                        byCode[code] = {
                            id: paymentMethod.id,
                            active: paymentMethod.active,
                            salesChannelIds: paymentMethod.salesChannels.map(salesChannel => salesChannel.id)
                        };
                    }
                });

                this.paymentMethodsByCode = byCode;
                this.salesChannels = salesChannels.map(salesChannel => ({
                    id: salesChannel.id,
                    name: salesChannel.translated?.name || salesChannel.name
                }));
            } catch (error) {
                // Without read access to payment methods or sales channels we simply don't show the warning
                this.paymentMethodsByCode = {};
            }
        },

        /**
         * @returns {null|'inactive'|'notInSalesChannel'|'noSalesChannel'}
         */
        getAvailabilityIssue(code) {
            const paymentMethod = this.paymentMethodsByCode[code];

            if (!paymentMethod || !this.isActive(code)) {
                return null;
            }
            if (!paymentMethod.active) {
                return 'inactive';
            }
            if (this.currentSalesChannelId) {
                return paymentMethod.salesChannelIds.includes(this.currentSalesChannelId) ? null : 'notInSalesChannel';
            }

            return paymentMethod.salesChannelIds.length ? null : 'noSalesChannel';
        },

        paymentSettingsRoute(code) {
            return {
                name: 'sw.settings.payment.detail',
                params: { id: this.paymentMethodsByCode[code]?.id }
            };
        },

        openAssignModal(payment) {
            this.assignModalPayment = payment;
        },

        closeAssignModal() {
            this.assignModalPayment = null;
        },

        onAssignSaved() {
            this.closeAssignModal();
            this.loadAvailability();
        },

        isActive(code) {
            return isPaymentEnabled(this.value, code);
        },

        getSharedEnvironmentTitle(code) {
            const parent = SHARED_ENVIRONMENT_METHODS[code];
            return parent ? this.getPaymentTitle(parent) : '';
        },

        configRoute(code) {
            return {
                name: 'buckaroo.payment.config',
                params: { namespace: 'BuckarooPayments', paymentCode: code }
            };
        },

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