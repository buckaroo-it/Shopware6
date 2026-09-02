import Plugin from 'src/plugin-system/plugin.class';

const SDK_SCRIPT_ID = 'buckaroo-sdk';
const SDK_SCRIPT_URL = 'https://hostedfields-externalapi.prod-pci.buckaroo.io/v1/sdk';

/**
 * The SDK reports every event against one of these target types. `Global`
 * carries the detected card scheme rather than a field, so it has no markup.
 */
const FIELD_WRAPPER_IDS = {
    CardHolderName: 'cc-name-wrapper',
    CardNumber: 'cc-number-wrapper',
    ExpiryDate: 'cc-expiry-wrapper',
    Cvc: 'cc-cvc-wrapper',
};

const FIELD_ERROR_IDS = {
    CardHolderName: 'cc-name-error',
    CardNumber: 'cc-number-error',
    ExpiryDate: 'cc-expiry-error',
    Cvc: 'cc-cvc-error',
};

/** Wrapper class mirroring the focus state of the input inside the iframe. */
const FOCUS_CLASS = 'bk-hosted-field--focus';

/** Storefront class that reveals the sibling .invalid-feedback element. */
const INVALID_CLASS = 'is-invalid';

/** Matches a computed background that would leave the iframe see-through. */
const TRANSPARENT_BACKGROUND = /^(transparent|rgba\(0,\s*0,\s*0,\s*0\))$/;

/**
 * OAuth token cache shared between plugin instances (module scope).
 *
 * Shopware replaces the checkout DOM on payment method switches, address or
 * shipping updates and AJAX section refreshes. Every replacement creates a new
 * plugin instance (see lifecycle note on the class below). Keeping the token
 * at module scope prevents a new token request on every checkout re-render.
 */
let tokenCache = null;

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
            await this._waitUntilVisible();
            await this._initializeHostedFields();
            this._listenToSubmit();
        } catch (error) {
            console.error('Error initializing Buckaroo Hosted Fields:', error);
        }
    }

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

    _waitUntilVisible() {
        if (this.el.offsetParent !== null) {
            return Promise.resolve();
        }

        return new Promise((resolve) => {
            let done = false;
            const finish = () => {
                if (done) {
                    return;
                }
                done = true;
                observer.disconnect();
                resolve();
            };

            const observer = new IntersectionObserver((entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    finish();
                }
            });
            observer.observe(this.el);

            const collapseParent = this.el.closest('.collapse');
            if (collapseParent) {
                collapseParent.addEventListener('shown.bs.collapse', finish, { once: true });
            }
        });
    }

    async _initializeHostedFields() {
        const requiredWrappers = Object.values(FIELD_WRAPPER_IDS).map((id) => `#${id}`);
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

        /**
         * A field is "touched" once the shopper has left it. Until then its
         * validation message stays unwritten, so the form does not greet the
         * shopper with errors for fields they have not filled in yet.
         */
        this._touchedFields = new Set();

        await this.sdkClient.startSession((event) => this._handleFieldEvent(event));

        const { baseStyling: styling, cardLogoStyling } = this._buildFieldStyling();

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

    /**
     * Builds the styling the SDK applies inside its iframes.
     *
     * The card inputs render on Buckaroo's own domain, so no Storefront CSS
     * reaches them and the SDK's styling API is the only way in. Rather than
     * hardcoding a look, the values are read back from the wrapper element —
     * a real `.form-control` — so the fields follow whatever font, size and
     * colours the merchant's theme gives Storefront inputs.
     *
     * The wrapper draws the border, radius and padding (see
     * scss/_hosted-fields.scss), which is why the input inside the iframe is
     * told to render nothing but text.
     */
    _buildFieldStyling() {
        const reference = this.el.querySelector(`#${FIELD_WRAPPER_IDS.CardHolderName}`);
        const computed = window.getComputedStyle(reference);

        // Placeholders cannot be reached with CSS either, so the theme colour
        // travels to the SDK through a custom property on the wrapper.
        const placeholderColor =
            computed.getPropertyValue('--bk-hf-placeholder-color').trim() || computed.color;

        // A see-through background would make the iframe fall back to the
        // browser default instead of the field it sits in.
        const background = computed.backgroundColor;

        return {
            baseStyling: {
                fontSize: computed.fontSize,
                fontFamily: computed.fontFamily,
                fontStyle: 'normal',
                fontWeight: computed.fontWeight,
                textAlign: 'left',
                textTransform: 'none',
                background: TRANSPARENT_BACKGROUND.test(background) ? 'inherit' : background,
                color: computed.color,
                placeholderColor,
                border: 'none',
                borderRadius: '0',
                padding: '0',
                boxShadow: 'none',
            },
            cardLogoStyling: {
                height: '80%',
                position: 'absolute',
                border: 'none',
                borderRadius: computed.borderRadius,
                opacity: '1',
                transition: 'all 0.3s ease',
                right: '0',
                backgroundColor: 'inherit',
            },
        };
    }

    /**
     * Handles one validation event from the SDK.
     *
     * Focus and invalid states are reported by the SDK but happen inside the
     * iframe, so neither can style the wrapper by itself — both are mirrored
     * onto the wrapper here using the Storefront's own form classes.
     */
    _handleFieldEvent(event) {
        const wrapper = this._getFieldWrapper(event.targetType);

        if (wrapper) {
            if (event.eventType === 'Focus') {
                wrapper.classList.add(FOCUS_CLASS);
            } else if (event.eventType === 'Blur') {
                wrapper.classList.remove(FOCUS_CLASS);
                this._touchedFields.add(event.targetType);
            }
        }

        // handleValidation() also records the per-field state that
        // formIsValid() reads, so it has to run for every event. Passing null
        // for a field the shopper has not left yet suppresses only its
        // message, leaving the validation logic itself untouched.
        this.sdkClient.handleValidation(
            event,
            this._visibleErrorId('CardHolderName'),
            this._visibleErrorId('CardNumber'),
            this._visibleErrorId('ExpiryDate'),
            this._visibleErrorId('Cvc')
        );

        this._syncFieldValidity(event.targetType, wrapper);

        this._updateButtonState();

        const issuerField = document.getElementById('selected-issuer');
        if (issuerField) {
            issuerField.value = this.sdkClient.getService();
        }
    }

    /** The error element id for a field, or null while it is untouched. */
    _visibleErrorId(targetType) {
        return this._touchedFields.has(targetType) ? FIELD_ERROR_IDS[targetType] : null;
    }

    _getFieldWrapper(targetType) {
        const wrapperId = FIELD_WRAPPER_IDS[targetType];

        return wrapperId ? this.el.querySelector(`#${wrapperId}`) : null;
    }

    /**
     * The SDK writes the message into the feedback element but never reveals
     * it. Marking the wrapper invalid is what makes Bootstrap show the
     * sibling `.invalid-feedback` and colour the border.
     */
    _syncFieldValidity(targetType, wrapper) {
        const errorId = FIELD_ERROR_IDS[targetType];
        if (!wrapper || !errorId) {
            return;
        }

        const errorElement = document.getElementById(errorId);
        const hasError = Boolean(errorElement && errorElement.innerText.trim());

        wrapper.classList.toggle(INVALID_CLASS, hasError);
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
        const submitButton = this.el.querySelector('#pay');
        if (submitButton) {
            submitButton.addEventListener('click', this._handleSubmit.bind(this));
        }

        const tosCheckbox =
            document.getElementById('tos') ||
            document.querySelector('.checkout-confirm-tos-checkbox');
        if (tosCheckbox) {
            tosCheckbox.addEventListener('change', () => this._updateButtonState());
        }

        this._updateButtonState();
    }

    _updateButtonState() {
        const payButton = document.getElementById('pay');
        if (!payButton) {
            return;
        }

        const formIsValid = this.sdkClient && this.sdkClient.formIsValid();

        const tosCheckbox =
            document.getElementById('tos') ||
            document.querySelector('.checkout-confirm-tos-checkbox');
        const tosIsChecked = tosCheckbox ? tosCheckbox.checked : true;

        // The disabled look comes from the theme's own .btn:disabled styling
        // rather than a hardcoded colour, so it matches the rest of checkout.
        payButton.disabled = !formIsValid || !tosIsChecked;

        const hint = this.el.querySelector('.buckaroo-hf-error');
        if (hint) {
            const message = formIsValid && !tosIsChecked
                ? 'Please accept the terms and conditions to place your order.'
                : '';

            hint.textContent = message;
            hint.classList.toggle('d-block', message !== '');
        }
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
