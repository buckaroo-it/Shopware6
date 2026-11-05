/**
 * Applies the merchant's PayPal Express button style (color, shape, label).
 *
 * The Buckaroo SDK builds the paypal.Buttons() options itself and forwards no
 * style, so the style is merged into paypal.Buttons() instead. The same is done
 * in the Buckaroo WooCommerce plugin.
 *
 * Checked against Buckaroo Client SDK v1.8.2. This relies on how the SDK loads
 * PayPal (see initiateWithButtonStyle); once the SDK forwards options.style
 * itself, this wrapper can go.
 */

const STYLE_KEYS = ['color', 'shape', 'label'];

/**
 * Only PayPal's color, shape and label; anything else keeps PayPal's own behaviour
 *
 * @param {Object|null} style
 * @returns {Object|null}
 */
function pickStyle(style) {
    if (!style || typeof style !== 'object') {
        return null;
    }

    const picked = {};
    STYLE_KEYS.forEach((key) => {
        if (typeof style[key] === 'string' && style[key] !== '') {
            picked[key] = style[key];
        }
    });

    return Object.keys(picked).length > 0 ? picked : null;
}

/**
 * @param {Object} namespace window.paypal
 * @param {Object} style
 */
function wrapPaypalButtons(namespace, style) {
    try {
        const original = namespace.Buttons;

        if (typeof original !== 'function' || original.buckarooStylePatched === true) {
            return;
        }

        const patched = function (options) {
            return original.call(
                this,
                Object.assign({}, options, {
                    style: Object.assign({}, options && options.style, style),
                })
            );
        };

        Object.keys(original).forEach((key) => {
            patched[key] = original[key];
        });
        patched.buckarooStylePatched = true;

        namespace.Buttons = patched;
    } catch {
        // PayPal keeps its default style.
    }
}

/**
 * Run BuckarooSdk.PayPal.initiate() with paypal.Buttons() wrapped.
 *
 * The SDK loads PayPal's script and calls paypal.Buttons() from a load listener
 * on that script. The wrapper must land between PayPal defining window.paypal
 * and that listener. PayPal redefines window.paypal, so an accessor on window
 * does not work. Instead document.createElement is shadowed for the synchronous
 * part of initiate(), which puts a load listener on the script before the SDK's
 * own, so it runs first.
 *
 * Without a style, or on any failure, PayPal keeps its default style. When the
 * SDK no longer loads PayPal's script this way, a console warning says so.
 *
 * @param {Function} initiate calls BuckarooSdk.PayPal.initiate()
 * @param {Object|null} style {color, shape, label}
 */
export default function initiateWithButtonStyle(initiate, style) {
    const buttonStyle = pickStyle(style);

    if (buttonStyle === null) {
        initiate();
        return;
    }

    const createElement = document.createElement;
    let scriptCreated = false;

    try {
        document.createElement = function (tagName) {
            const element = createElement.apply(document, arguments);

            if (String(tagName).toLowerCase() === 'script') {
                scriptCreated = true;
                element.addEventListener('load', () => {
                    if (window.paypal) {
                        wrapPaypalButtons(window.paypal, buttonStyle);
                    }
                });
            }

            return element;
        };

        initiate();
    } finally {
        document.createElement = createElement;
    }

    if (!scriptCreated) {
        console.warn('Buckaroo PayPal Express: the button style could not be applied, PayPal shows its default style.');
    }
}
