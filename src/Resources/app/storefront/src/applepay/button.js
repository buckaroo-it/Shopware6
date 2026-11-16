/**
 * Apple Pay express button, shared by the storefront and the button preview in the plugin settings,
 * so both draw the same button.
 */

// Apple's official Apple Pay JS SDK: provides the <apple-pay-button> web component
export const APPLE_PAY_SDK_URL = "https://applepay.cdn-apple.com/jsapi/1.latest/apple-pay-sdk.js";

// Button styles and types of <apple-pay-button>; the first is the default
export const BUTTON_STYLES = ["black", "white", "white-outline"];
export const BUTTON_TYPES = ["plain", "buy", "check-out", "order", "book", "subscribe"];

/**
 * The value when it is one of the options, otherwise the first (default) option
 *
 * @param {*} value
 * @param {string[]} options
 * @returns {string}
 */
export function buttonOption(value, options) {
  return options.includes(value) ? value : options[0];
}

/**
 * Create Apple's official <apple-pay-button> web component.
 *
 * Attributes are set in a safe order (locale before type): the component
 * re-reads `locale` when `type` changes and throws "t.trim is not a function"
 * if locale is null, so a valid locale string must be present first.
 *
 * @param {{buttonStyle?: string, buttonType?: string, locale?: string}} options
 * @returns {HTMLElement}
 */
export function createButton(options) {
  options = options || {};
  const locale = (typeof options.locale === "string" && options.locale.trim() !== "")
    ? options.locale
    : ((typeof navigator !== "undefined" && navigator.language) || "en-US");
  const button = document.createElement("apple-pay-button");
  button.setAttribute("locale", locale);
  button.setAttribute("buttonstyle", buttonOption(options.buttonStyle, BUTTON_STYLES));
  button.setAttribute("type", buttonOption(options.buttonType, BUTTON_TYPES));
  button.style.width = "100%";
  button.style.cursor = "pointer";
  return button;
}
