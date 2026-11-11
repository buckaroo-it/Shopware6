// Languages of the Google Pay button (buttonLocale of createButton()), as Google lists them
const BUTTON_LOCALES = [
  "en", "ar", "bg", "ca", "cs", "da", "de", "el", "es", "et", "fi", "fr", "hr", "id", "it", "ja",
  "ko", "ms", "nl", "no", "pl", "pt", "ru", "sk", "sl", "sr", "sv", "th", "tr", "uk", "zh",
];

/**
 * The Google Pay button language for a locale such as "de-DE", or null when Google has no
 * button in that language: the button then follows the browser language.
 *
 * @param {string|null|undefined} locale
 * @returns {string|null}
 */
export default function googlePayButtonLocale(locale) {
  const language = String(locale || "").toLowerCase().split(/[-_]/)[0];

  // Google uses "no" for Norwegian; Shopware uses Bokmål or Nynorsk
  const code = language === "nb" || language === "nn" ? "no" : language;

  return BUTTON_LOCALES.includes(code) ? code : null;
}
