/**
 * Google Pay express button for the settings preview.
 *
 * Draws the button with Google's own createButton(), as the storefront does, so the preview
 * shows the same button customers get.
 */
import createScriptLoader from './load-script';
import googlePayButtonLocale from '../../../../../storefront/src/googlepay/button-locale';

// Height of the storefront button in a 300-499px column, the same as the PayPal preview
const BUTTON_HEIGHT = '45px';

// One client for every redraw; it only draws buttons, so the Test environment is enough
let client = null;

export default {
    // createButton() option => setting (without the config prefix)
    fields: {
        buttonColor: 'googlepayButtonStyle',
        buttonType: 'googlepayButtonType',
    },

    // The defaults of the settings, also used by the storefront when a setting is empty
    defaults: {
        buttonColor: 'black',
        buttonType: 'buy',
    },

    /**
     * @returns {Promise<void>}
     */
    load: createScriptLoader(
        'Google Pay',
        'https://pay.google.com/gp/p/js/pay.js',
        () => !!(window.google && window.google.payments && window.google.payments.api),
        'google.payments.api',
    ),

    /**
     * Draws an inert button: clicking it does nothing in the preview.
     *
     * @param {HTMLElement} container
     * @param {Object} style {buttonColor, buttonType}
     * @param {{locale: (string|undefined)}} options locale of the storefront, e.g. "de-DE"
     * @returns {{instance: null, rendered: Promise}}
     */
    draw(container, style, { locale } = {}) {
        const buttonLocale = googlePayButtonLocale(locale);
        client = client || new window.google.payments.api.PaymentsClient({ environment: 'TEST' });
        const button = client.createButton({
            ...style,
            ...(buttonLocale ? { buttonLocale } : {}),
            buttonSizeMode: 'fill',
            onClick: () => {},
        });

        // With "fill", the button takes the size of the element around it
        const wrapper = document.createElement('div');
        wrapper.style.width = '100%';
        wrapper.style.height = BUTTON_HEIGHT;
        button.style.width = '100%';
        button.style.height = '100%';

        wrapper.appendChild(button);
        container.appendChild(wrapper);

        return {
            instance: null,
            rendered: Promise.resolve(),
        };
    },

    destroy() {
        // Nothing to close: the container is emptied before the next draw
    },
};
