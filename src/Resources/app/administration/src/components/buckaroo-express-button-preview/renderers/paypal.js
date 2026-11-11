/**
 * PayPal Express button for the settings preview.
 *
 * Loads PayPal's script with the same funding options the Buckaroo SDK uses on the
 * storefront, so the preview draws the same buttons customers get.
 */
import createScriptLoader from './load-script';

// Buckaroo's PayPal sandbox client id, as in the Buckaroo test SDK (testcheckout.buckaroo.nl):
// PayPal's script does not load without one, and the sandbox keeps the preview off the live app
const CLIENT_ID = 'Ab8jz-WnSLFLHjn-i_Bs0k_eWmRThzLHKpWnFwtwuPlQigUZsZWzgDF1AXQG3dHKPgW2YWKkGtJIjFty';

const SDK_PARAMS = {
    'client-id': CLIENT_ID,
    currency: 'EUR',
    'disable-funding': 'credit,card,bancontact,blik,eps,giropay,ideal,mercadopago,mybank,p24,sepa,sofort,venmo',
    'enable-funding': 'paylater',
};

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
    load: createScriptLoader(
        'PayPal',
        sdkUrl,
        () => !!(window.paypal && typeof window.paypal.Buttons === 'function'),
        'paypal.Buttons',
    ),

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
