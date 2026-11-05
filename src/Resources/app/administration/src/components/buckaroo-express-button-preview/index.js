import template from './buckaroo-express-button-preview.html.twig';
import './style.scss';
import paypal from './renderers/paypal';

const { Component } = Shopware;

/**
 * Renderers per express method. Each one names the settings its button style comes from,
 * their defaults, and how to load and draw the button. Apple Pay and Google Pay can be
 * added here and used from config.xml with <method>applepay</method> or <method>googlepay</method>.
 */
const RENDERERS = {
    paypal,
};

/**
 * Live preview of an express button, with the current (also unsaved) settings of the card.
 * Used in config.xml as <component name="buckaroo-express-button-preview"> with a <method>.
 */
Component.register('buckaroo-express-button-preview', {
    template,

    props: {
        method: {
            type: String,
            required: true,
        },
        // Settings of the card as the sales channel uses them (own or inherited value), without the config prefix
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
    },

    data() {
        return {
            hasError: false,
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
    },

    watch: {
        buttonStyleKey() {
            this.render();
        },
    },

    created() {
        // Not reactive: PayPal's button instance must stay a plain object
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

            // Show the container again after an error: PayPal sizes the button from it
            this.hasError = false;

            renderer.load()
                .then(() => this.$nextTick())
                .then(() => {
                    if (!isCurrent()) {
                        return null;
                    }

                    this.destroyButton();
                    this.$refs.container.innerHTML = '';
                    this.button = renderer.draw(this.$refs.container, this.buttonStyle);

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
    },
});
