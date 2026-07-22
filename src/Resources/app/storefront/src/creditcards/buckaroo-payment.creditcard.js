import Plugin from 'src/plugin-system/plugin.class';

const SDK_SCRIPT_ID = 'buckaroo-sdk';
const SDK_SCRIPT_URL = 'https://hostedfields-externalapi.prod-pci.buckaroo.io/v1/sdk';

/**
 * OAuth token cache shared between plugin instances (module scope).
 *
 * Shopware replaces the checkout DOM on payment method switches, address or
 * shipping updates and AJAX section refreshes. Every replacement creates a new
 * plugin instance (see lifecycle note on the class below). Keeping the token
 * at module scope prevents a new token request on every checkout re-render.
 */
let tokenCache = null;

/**
 * Buckaroo Credit and Debit Card (Hosted Fields) storefront plugin.
 *
 * LIFECYCLE / WHY THIS PLUGIN IS ELEMENT-BOUND:
 * This plugin is registered on the hosted fields container
 * ('[data-buckaroo-hosted-fields]', see main.js) and NOT on `document`.
 *
 * Shopware's checkout (6.5+) replaces parts of the confirm page DOM after
 * payment method selection, address updates, shipping method changes and any
 * AJAX refresh, and afterwards calls `window.PluginManager.initializePlugins()`.
 * A document-bound plugin initializes only once per full page load, so any
 * container rendered *after* that point was never mounted by the SDK: the
 * styled wrapper <div>s were visible (they look like inputs), but contained no
 * SDK iframes and could therefore not receive focus or input.
 *
 * Binding the plugin to the container element fixes this by design:
 * - every newly rendered container gets a fresh instance (fields are
 *   re-mounted after each checkout re-render), and
 * - PluginManager guarantees a single instance per element, which prevents
 *   duplicate Hosted Fields initialization.
 */
export default class BuckarooCreditCards extends Plugin {
    init() {
        /**
         * Extra safety guard against manual `init()` re-invocations
         * (PluginManager already ensures one instance per element).
         */
        this._initialized = false;

        this._initialize();
    }

    async _initialize() {
        if (this._initialized) {
            return;
        }
        this._initialized = true;

        try {
            await this._loadHostedFieldsScript();
            await this._initializeHostedFields();
            this._listenToSubmit();
        } catch (error) {
            console.error('Error initializing Buckaroo Hosted Fields:', error);
        }
    }

    /**
     * Loads the Hosted Fields SDK once and resolves only when the SDK global
     * (`window.BuckarooHostedFieldsSdk`) is actually available.
     *
     * WHY: the previous implementation ran the callback synchronously whenever
     * the <script id="buckaroo-sdk"> tag already existed in <head>. When the
     * checkout DOM was re-rendered while the SDK was still downloading (the
     * script tag survives in <head>, the checkout DOM does not), the callback
     * fired before the SDK existed, `new BuckarooHostedFieldsSdk.HFClient(...)`
     * threw a ReferenceError that was swallowed by the surrounding try/catch,
     * and the fields were silently never mounted — visible container, no
     * editable iframes.
     */
    _loadHostedFieldsScript() {
        if (window.BuckarooHostedFieldsSdk) {
            return Promise.resolve();
        }

        return new Promise((resolve, reject) => {
            let script = document.getElementById(SDK_SCRIPT_ID);

            if (!script) {
                script = document.createElement('script');
                script.id = SDK_SCRIPT_ID;
                script.src = SDK_SCRIPT_URL;
                script.async = true;
                document.head.appendChild(script);
            }

            script.addEventListener('load', () => resolve(), { once: true });
            script.addEventListener(
                'error',
                () => reject(new Error('Failed to load the Buckaroo Hosted Fields SDK.')),
                { once: true }
            );

            if (window.BuckarooHostedFieldsSdk) {
                resolve();
            }
        });
    }

    async _initializeHostedFields() {
        // The SDK mounts into these wrappers. They must exist inside the
        // container this instance is bound to; if Shopware rendered a partial
        // DOM (e.g. mid-refresh) we bail out instead of mounting into nothing.
        const requiredWrappers = [
            '#cc-name-wrapper',
            '#cc-number-wrapper',
            '#cc-expiry-wrapper',
            '#cc-cvc-wrapper',
        ];
        if (!requiredWrappers.every((selector) => this.el.querySelector(selector))) {
            console.error('Buckaroo Hosted Fields wrappers are missing from the checkout DOM.');
            return;
        }

        const tokenData = await this._getOrRefreshToken();

        if (!tokenData || !tokenData.access_token) {
            console.error('Failed to retrieve Buckaroo OAuth token.');
            return;
        }

        this.sdkClient = new BuckarooHostedFieldsSdk.HFClient(tokenData.access_token);
        this.sdkClient.setLanguage('en');

        await this.sdkClient.setSupportedServices(tokenData.issuers);

        await this.sdkClient.startSession((event) => {
            this.sdkClient.handleValidation(
                event,
                'cc-name-error',
                'cc-number-error',
                'cc-expiry-error',
                'cc-cvc-error'
            );

            this._updateButtonState();

            // Elements are re-queried instead of captured once: after a
            // checkout re-render a captured reference would point to a
            // detached element of the previous render.
            const issuerField = document.getElementById('selected-issuer');
            if (issuerField) {
                issuerField.value = this.sdkClient.getService();
            }
        });

        const cardLogoStyling = {
            height: '80%',
            position: 'absolute',
            border: '1px solid gray',
            radius: '5px',
            opacity: '1',
            transition: 'all 0.3s ease',
            right: '5px',
            backgroundColor: 'inherit',
        };

        const styling = {
            fontSize: '14px',
            fontFamily: 'Consolas, Liberation Mono, Menlo, Courier, monospace',
            textAlign: 'left',
            background: 'inherit',
            color: 'black',
            placeholderColor: 'grey',
            cardLogoStyling,
        };

        // NOTE: no auto-focus after mounting. Focusing an iframe right after a
        // checkout re-render steals focus / scroll-jacks the page while the
        // customer is interacting with another part of the checkout.
        await this.sdkClient.mountCardHolderName('#cc-name-wrapper', {
            id: 'ccname',
            placeHolder: 'John Doe',
            labelSelector: '#cc-name-label',
            baseStyling: styling,
        });

        await this.sdkClient.mountCardNumber('#cc-number-wrapper', {
            id: 'cc',
            placeHolder: '555x xxxx xxxx xxxx',
            labelSelector: '#cc-number-label',
            baseStyling: styling,
            cardLogoStyling,
        });

        await this.sdkClient.mountCvc('#cc-cvc-wrapper', {
            id: 'cvc',
            placeHolder: '1234',
            labelSelector: '#cc-cvc-label',
            baseStyling: styling,
        });

        await this.sdkClient.mountExpiryDate('#cc-expiry-wrapper', {
            id: 'expiry',
            placeHolder: 'MM / YY',
            labelSelector: '#cc-expiry-label',
            baseStyling: styling,
        });
    }

    async _handleSubmit(event) {
        event.preventDefault();

        try {
            const paymentToken = await this.sdkClient.submitSession();

            if (!paymentToken) {
                console.error('Failed to retrieve Hosted Fields token.');
                return;
            }

            const tokenField = document.getElementById('buckaroo-token');
            if (tokenField) {
                tokenField.value = paymentToken;
            }

            // Submits Shopware's own order form. The Buckaroo template no
            // longer renders a competing <form id="confirmOrderForm"> (invalid
            // nested form that the parser dropped anyway), so this reliably
            // targets the core checkout form.
            const orderForm = document.getElementById('confirmOrderForm');
            if (orderForm) {
                orderForm.requestSubmit();
            } else {
                console.error('Shopware confirm order form (#confirmOrderForm) not found.');
            }
        } catch (error) {
            console.error('Error processing Buckaroo payment:', error);
        }
    }

    _listenToSubmit() {
        // The pay button is part of this.el, so it is replaced together with
        // the container on every re-render — listeners cannot stack.
        const submitButton = this.el.querySelector('#pay');
        if (submitButton) {
            submitButton.addEventListener('click', this._handleSubmit.bind(this));
        }

        // The TOS checkbox belongs to the surrounding confirm page and may
        // survive a partial re-render. _updateButtonState() re-queries the
        // current DOM, so even a listener registered by a previous instance
        // stays harmless.
        const tosCheckbox =
            document.getElementById('tos') ||
            document.querySelector('.checkout-confirm-tos-checkbox');
        if (tosCheckbox) {
            tosCheckbox.addEventListener('change', () => this._updateButtonState());
        }

        // Reflect the initial state (fields empty => button disabled).
        this._updateButtonState();
    }

    _updateButtonState() {
        // Always re-query instead of caching: after a checkout re-render a
        // cached reference would point to a detached element.
        const payButton = document.getElementById('pay');
        if (!payButton) {
            return;
        }

        // Check if hosted fields form is valid
        const formIsValid = this.sdkClient && this.sdkClient.formIsValid();

        // Check if TOS checkbox is checked
        const tosCheckbox =
            document.getElementById('tos') ||
            document.querySelector('.checkout-confirm-tos-checkbox');
        const tosIsChecked = tosCheckbox ? tosCheckbox.checked : true; // If no TOS checkbox, don't block

        // Button should only be enabled if both conditions are met
        const disabled = !formIsValid || !tosIsChecked;

        payButton.disabled = disabled;
        payButton.style.backgroundColor = disabled ? '#ff5555' : '';
        payButton.style.cursor = disabled ? 'not-allowed' : '';
        payButton.style.opacity = disabled ? '0.5' : '';
    }

    /**
     * Returns the storefront base URL including language prefix (e.g. /en) when present.
     * Fixes requests going to wrong URL when using shop.com/en-style domains.
     */
    _getStorefrontBaseUrl() {
        const origin = window.location.origin;
        const pathSegments = window.location.pathname.split('/').filter(Boolean);
        if (pathSegments.length > 0 && /^[a-z]{2}(-[A-Z]{2})?$/i.test(pathSegments[0])) {
            return `${origin}/${pathSegments[0]}`;
        }
        return origin;
    }

    async _getOrRefreshToken() {
        const now = Date.now();

        // Module-scoped cache: survives checkout re-renders (new plugin
        // instances) within the same page, unlike instance properties.
        if (tokenCache && now < tokenCache.expiresAt && tokenCache.accessToken) {
            return {
                access_token: tokenCache.accessToken,
                issuers: tokenCache.issuers,
            };
        }

        try {
            const baseUrl = this._getStorefrontBaseUrl();
            const response = await fetch(`${baseUrl}/buckaroo/get-oauth-token`, {
                method: 'GET',
                headers: {
                    'X-Requested-From': 'ShopwareFrontend',
                },
            });

            const data = await response.json();
            if (!data || !data.data || !data.data.access_token) {
                throw new Error('No access token in response');
            }

            tokenCache = {
                accessToken: data.data.access_token,
                issuers: data.data.issuers,
                expiresAt: now + (10 * 60 * 1000),
            };

            return {
                access_token: tokenCache.accessToken,
                issuers: tokenCache.issuers,
            };
        } catch (error) {
            console.error('Token refresh failed:', error);
            return null;
        }
    }
}
