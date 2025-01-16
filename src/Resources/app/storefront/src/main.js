import BuckarooPaymentCreditcards from './creditcards/buckaroo-payment.creditcards';
import BuckarooPaymentHelper from './helper/buckaroo-payment.helper';
import BuckarooPaymentValidateSubmit from './helper/buckaroo-validate';
import PaypalExpressPlugin from './paypal-express/paypal-express.plugin';
import ApplePayPlugin from './applepay/applepay.plugin';
import BuckarooLoadScripts from './scripts/scripts.plugin';
import IdealQrPlugin from './ideal-qr/ideal-qr.plugin';
import BuckarooPayByBankSelect from './paybybank/select.plugin';
import BuckarooPayByBankLogo from './paybybank/logo.plugin';
import BuckarooBanContact from './bancontact/buckaroo-payment.bancontact';

const PluginManager = window.PluginManager;

const registerPlugin = (pluginName, pluginClass, selector = null) => {
    if (!PluginManager.getPlugin(pluginName)) {
        PluginManager.register(pluginName, pluginClass, selector);
    } else {
        console.warn(`Plugin "${pluginName}" is already registered.`);
    }
};

registerPlugin('BuckarooPaymentValidateSubmit', BuckarooPaymentValidateSubmit);
registerPlugin('BuckarooPaymentCreditcards', BuckarooPaymentCreditcards);
registerPlugin('BuckarooPaymentHelper', BuckarooPaymentHelper);
registerPlugin('PaypalExpressPlugin', PaypalExpressPlugin, '[data-paypal-express]');
registerPlugin('BuckarooIdealQrPlugin', IdealQrPlugin, '[data-ideal-qr]');
registerPlugin('BuckarooApplePayPlugin', ApplePayPlugin, '[data-bk-applepay]');
registerPlugin('BuckarooLoadScripts', BuckarooLoadScripts);
registerPlugin('BuckarooBanContact', BuckarooBanContact);
registerPlugin('BuckarooPayByBankSelect', BuckarooPayByBankSelect, '[data-bk-select]');
registerPlugin('BuckarooPayByBankLogo', BuckarooPayByBankLogo, '[data-bk-paybybank-logo]');
