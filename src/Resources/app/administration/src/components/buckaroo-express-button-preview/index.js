import template from './buckaroo-express-button-preview.html.twig';
import './style.scss';
import paypal from './renderers/paypal';
import googlepay from './renderers/googlepay';

const { Component } = Shopware;

/**
 * Renderers per express method. Each one names the settings its button style comes from,
 * their defaults, and how to load and draw the button.
 */
const RENDERERS = {
    paypal,
    googlepay,
};

// Locale code of a sales channel's default language, e.g. "de-DE", per sales channel id
const storefrontLocales = new Map();

/**
 * Live preview of an express button, with the current (also unsaved) settings of the card.
 * Used in config.xml as <component name="buckaroo-express-button-preview"> with a <method>.
 */
Component.register('buckaroo-express-button-preview', {
    template,

    inject: ['repositoryFactory'],

    props: {
        method: {
            type: String,
            required: true,
        },
        // Card settings by name, as the sales channel uses them
        values: {
            type: Object,
            required: false,
            default: () => ({}),
        },
        label: {
            type: String,
            required: false,
            default: '',
        },
        helpText: {
            type: String,
            required: false,
            default: '',
        },
        // Selected sales channel; null for "All Sales Channels"
        salesChannelId: {
            type: String,
            required: false,
            default: null,
        },
    },

    data() {
        return {
            hasError: false,
            storefrontLocale: null,
        };
    },

    computed: {
        renderer() {
            return RENDERERS[this.method] || null;
        },

        buttonStyle() {
            if (!this.renderer) {
                return null;
            }

            const { fields, defaults } = this.renderer;

            return Object.keys(fields).reduce((style, key) => {
                const value = this.values[fields[key]];
                style[key] = typeof value === 'string' && value !== '' ? value : defaults[key];

                return style;
            }, {});
        },

        // The parent passes a new values object on every render; only redraw when the style changes
        buttonStyleKey() {
            return JSON.stringify(this.buttonStyle);
        },

        // Language of the Administration, e.g. "de-DE": a string in Shopware 6.5 (vue-i18n 8),
        // a ref in the composition mode of later versions
        adminLocale() {
            const locale = this.$i18n?.locale;

            return typeof locale === 'string' ? locale : locale?.value;
        },

        previewLocale() {
            return this.storefrontLocale || this.adminLocale;
        },
    },

    watch: {
        buttonStyleKey() {
            this.render();
        },

        salesChannelId: {
            immediate: true,
            handler() {
                this.loadStorefrontLocale();
            },
        },

        previewLocale() {
            this.render();
        },
    },

    created() {
        // Not reactive: a provider's button instance must stay a plain object
        this.button = null;
        this.renderCount = 0;
    },

    mounted() {
        this.render();
    },

    // Vue 2 (Shopware 6.5)
    beforeDestroy() {
        this.destroyButton();
    },

    // Vue 3 (Shopware 6.6+)
    beforeUnmount() {
        this.destroyButton();
    },

    methods: {
        render() {
            const renderCount = ++this.renderCount;
            const renderer = this.renderer;
            const isCurrent = () => renderCount === this.renderCount && !!this.$refs.container;

            if (!renderer) {
                this.showError(`unknown express method "${this.method}"`);
                return;
            }

            // Show the container again after an error: the providers size the button from it
            this.hasError = false;

            renderer.load()
                .then(() => this.$nextTick())
                .then(() => {
                    if (!isCurrent()) {
                        return null;
                    }

                    this.destroyButton();
                    this.$refs.container.innerHTML = '';
                    this.button = renderer.draw(this.$refs.container, this.buttonStyle, { locale: this.previewLocale });

                    return this.button.rendered;
                })
                .catch((error) => {
                    if (isCurrent()) {
                        this.showError(error);
                    }
                });
        },

        destroyButton() {
            if (this.button && this.renderer) {
                this.renderer.destroy(this.button.instance);
            }
            this.button = null;
        },

        showError(reason) {
            this.hasError = true;
            console.warn('Buckaroo express button preview:', reason);
        },

        /**
         * Language of the selected sales channel's storefront: the locale of its default language,
         * which the storefront also puts on <html lang>. Stays null for "All Sales Channels", or
         * when the sales channel cannot be read, so the preview uses the Administration language.
         */
        loadStorefrontLocale() {
            const salesChannelId = this.salesChannelId;

            if (!salesChannelId) {
                this.storefrontLocale = null;
                return;
            }

            if (!storefrontLocales.has(salesChannelId)) {
                const criteria = new Shopware.Data.Criteria(1, 1);
                criteria.addAssociation('language.locale');

                storefrontLocales.set(salesChannelId, this.repositoryFactory.create('sales_channel')
                    .get(salesChannelId, Shopware.Context.api, criteria)
                    .then(salesChannel => salesChannel?.language?.locale?.code || null)
                    .catch(() => {
                        // Read it again next time, e.g. after a network error
                        storefrontLocales.delete(salesChannelId);
                        return null;
                    }));
            }

            storefrontLocales.get(salesChannelId).then((locale) => {
                // Another sales channel may have been selected in the meantime
                if (salesChannelId === this.salesChannelId) {
                    this.storefrontLocale = locale;
                }
            });
        },
    },
});
