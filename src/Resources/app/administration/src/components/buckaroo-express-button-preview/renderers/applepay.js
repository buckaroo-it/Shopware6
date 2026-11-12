/**
 * Apple Pay express button for the settings preview.
 *
 * Loads the same Apple Pay JS SDK and draws the button with the same function as the storefront,
 * so the preview shows the button customers get.
 */
import createScriptLoader from './load-script';
import {
    APPLE_PAY_SDK_URL,
    BUTTON_STYLES,
    BUTTON_TYPES,
    createButton,
} from '../../../../../storefront/src/applepay/button';

// Height of the storefront button in a 300-499px column, the same as the other previews
const BUTTON_HEIGHT = '45px';

// Corner radius of the storefront button ($bk-express-button-radius)
const BUTTON_RADIUS = '4px';

// The button hides itself where Apple Pay is unavailable (e.g. in a browser without Apple Pay).
// The preview only shows the style, so these are removed.
const HIDING_ATTRIBUTES = ['hidden', 'aria-hidden', 'disabled'];

// How long the preview keeps removing them: the button sets them after its availability check
const REVEAL_DURATION = 10000;

// How long to wait for the <apple-pay-button> element once Apple's script has loaded
const ELEMENT_TIMEOUT = 10000;

function isButtonDefined() {
    return !!(window.customElements && window.customElements.get('apple-pay-button'));
}

/**
 * Apple's script defines <apple-pay-button> only some time after its load event
 *
 * @returns {Promise<void>}
 */
function whenButtonDefined() {
    if (isButtonDefined()) {
        return Promise.resolve();
    }

    if (!window.customElements) {
        return Promise.reject(new Error('Apple Pay button needs custom elements'));
    }

    return new Promise((resolve, reject) => {
        const timeout = window.setTimeout(
            () => reject(new Error('Apple Pay script loaded without the apple-pay-button element')),
            ELEMENT_TIMEOUT,
        );

        window.customElements.whenDefined('apple-pay-button').then(() => {
            window.clearTimeout(timeout);
            resolve();
        });
    });
}

export default {
    // <apple-pay-button> option => setting (without the config prefix)
    fields: {
        buttonStyle: 'applepayButtonStyle',
        buttonType: 'applepayButtonType',
    },

    // The defaults of the settings (the first options), also used by the storefront when a setting is empty
    defaults: {
        buttonStyle: BUTTON_STYLES[0],
        buttonType: BUTTON_TYPES[0],
    },

    /**
     * @returns {Promise<void>}
     */
    load: createScriptLoader(
        'Apple Pay',
        APPLE_PAY_SDK_URL,
        isButtonDefined,
        'the apple-pay-button element',
        whenButtonDefined,
    ),

    /**
     * Draws an inert button: there is no click handler, so clicking it does nothing.
     *
     * @param {HTMLElement} container
     * @param {Object} style {buttonStyle, buttonType}
     * @param {{locale: (string|undefined)}} options locale of the storefront, e.g. "de-DE"
     * @returns {{instance: (MutationObserver|null), rendered: Promise}}
     */
    draw(container, style, { locale } = {}) {
        const button = createButton({ ...style, locale });

        // As the storefront sizes it: the button fills a wrapper of the button height
        const wrapper = document.createElement('div');
        wrapper.style.width = '100%';
        wrapper.style.height = BUTTON_HEIGHT;
        button.style.display = 'block';
        button.style.height = '100%';
        button.style.cursor = 'default';
        button.style.setProperty('--apple-pay-button-height', '100%');
        button.style.setProperty('--apple-pay-button-border-radius', BUTTON_RADIUS);
        button.style.setProperty('--apple-pay-button-box-sizing', 'border-box');

        wrapper.appendChild(button);
        container.appendChild(wrapper);

        const reveal = () => {
            HIDING_ATTRIBUTES.forEach((name) => {
                if (button.hasAttribute(name)) {
                    button.removeAttribute(name);
                }
            });
        };
        reveal();

        let observer = null;
        if (window.MutationObserver) {
            observer = new window.MutationObserver(reveal);
            observer.observe(button, { attributes: true, attributeFilter: HIDING_ATTRIBUTES });
            window.setTimeout(() => observer.disconnect(), REVEAL_DURATION);
        }

        return {
            instance: observer,
            rendered: Promise.resolve(),
        };
    },

    destroy(observer) {
        // The container is emptied before the next draw; only stop watching the old button
        if (observer) {
            observer.disconnect();
        }
    },
};
