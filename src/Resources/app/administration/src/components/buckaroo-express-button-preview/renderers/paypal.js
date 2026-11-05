/**
 * PayPal Express button for the settings preview.
 *
 * Loads PayPal's script with the same funding options the Buckaroo SDK uses on the
 * storefront, so the preview draws the same buttons customers get.
 */

// Buckaroo's PayPal client id, as in the Buckaroo SDK; PayPal's script does not load without one
const CLIENT_ID = 'ATv1oKfBmc76Zzl8rAMai_OwpXIp9CsDTMzEceayY7X2Sy8t6bQT2rm7DIC7LYbfkch9m9S3R3amkeyU';

const SDK_PARAMS = {
    'client-id': CLIENT_ID,
    currency: 'EUR',
    'disable-funding': 'credit,card,bancontact,blik,eps,giropay,ideal,mercadopago,mybank,p24,sepa,sofort,venmo',
    'enable-funding': 'paylater',
};

const LOAD_TIMEOUT = 15000;

let loading = null;

// PayPal's script as added by the preview; kept while it may still load
let script = null;

function isLoaded() {
    return !!(window.paypal && typeof window.paypal.Buttons === 'function');
}

function sdkUrl() {
    const query = Object.keys(SDK_PARAMS)
        .map(name => `${name}=${encodeURIComponent(SDK_PARAMS[name])}`)
        .join('&');

    return `https://www.paypal.com/sdk/js?${query}`;
}

export default {
    // Style option => setting (without the config prefix)
    fields: {
        color: 'paypalExpressButtonStyle',
        shape: 'paypalExpressButtonShape',
        label: 'paypalExpressButtonType',
    },

    // PayPal's own defaults, also used by the storefront when a setting is empty
    defaults: {
        color: 'gold',
        shape: 'rect',
        label: 'paypal',
    },

    /**
     * @returns {Promise<void>}
     */
    load() {
        if (isLoaded()) {
            return Promise.resolve();
        }

        if (loading) {
            return loading;
        }

        // A script that timed out may still load, so a retry waits for the same script
        // instead of adding PayPal a second time
        if (!script) {
            script = document.createElement('script');
            script.src = sdkUrl();
            script.async = true;
            document.head.appendChild(script);
        }

        const pending = script;

        loading = new Promise((resolve, reject) => {
            const onLoad = () => {
                if (isLoaded()) {
                    done();
                } else {
                    done(new Error('PayPal script loaded without paypal.Buttons'), true);
                }
            };
            const onError = () => done(new Error('PayPal script failed to load'), true);
            const timeout = window.setTimeout(() => done(new Error('PayPal script timed out'), false), LOAD_TIMEOUT);

            function done(error, failed) {
                window.clearTimeout(timeout);
                pending.removeEventListener('load', onLoad);
                pending.removeEventListener('error', onError);

                // The next render adds a new script
                if (failed) {
                    pending.remove();
                    script = null;
                }

                if (error) {
                    reject(error);
                } else {
                    resolve();
                }
            }

            pending.addEventListener('load', onLoad);
            pending.addEventListener('error', onError);
        }).catch((error) => {
            // Try again on the next render
            loading = null;
            throw error;
        });

        return loading;
    },

    /**
     * Draws an inert button: nothing is payable in the preview.
     *
     * @param {HTMLElement} container
     * @param {Object} style {color, shape, label}
     * @returns {{instance: Object, rendered: Promise}}
     */
    draw(container, style) {
        const instance = window.paypal.Buttons({
            style,
            onInit(data, actions) {
                actions.disable();
            },
        });

        return {
            instance,
            rendered: Promise.resolve(instance.render(container)),
        };
    },

    /**
     * @param {Object} instance
     */
    destroy(instance) {
        // Rendering into a container that still holds a live button fails, so close it first
        if (instance && typeof instance.close === 'function') {
            try {
                instance.close();
            } catch {
                // Already gone
            }
        }
    },
};
