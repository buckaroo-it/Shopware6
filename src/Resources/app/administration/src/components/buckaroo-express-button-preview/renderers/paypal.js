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

        loading = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            const timeout = window.setTimeout(() => reject(new Error('PayPal script timed out')), LOAD_TIMEOUT);

            script.src = sdkUrl();
            script.async = true;
            script.addEventListener('load', () => {
                window.clearTimeout(timeout);
                if (isLoaded()) {
                    resolve();
                } else {
                    reject(new Error('PayPal script loaded without paypal.Buttons'));
                }
            });
            script.addEventListener('error', () => {
                window.clearTimeout(timeout);
                reject(new Error('PayPal script failed to load'));
            });

            document.head.appendChild(script);
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
