import BuckarooPaymentCreditcard from './creditcards/buckaroo-payment.creditcard';
import BuckarooPaymentHelper from './helper/buckaroo-payment.helper';
import BuckarooPaymentValidateSubmit from './helper/buckaroo-validate';
import PaypalExpressPlugin from './paypal-express/paypal-express.plugin';
import IdealFastCheckoutPlugin from './ideal-fast-checkout/ideal-fast-checkout.plugin';
import ApplePayPlugin from './applepay/applepay.plugin';
import BuckarooLoadScripts from './scripts/scripts.plugin';
import IdealQrPlugin from './ideal-qr/ideal-qr.plugin';
import BuckarooPayByBankSelect from './paybybank/select.plugin';
import BuckarooPayByBankLogo from './paybybank/logo.plugin';
import BuckarooBanContact from './bancontact/buckaroo-payment.bancontact';

const PluginManager = window.PluginManager;


const init = () => {
    if('BuckarooPaymentValidateSubmit' in window.PluginManager.getPluginList()) {
        return;
    }

    PluginManager.register('BuckarooPaymentValidateSubmit', BuckarooPaymentValidateSubmit);
    PluginManager.register('BuckarooPaymentCreditcard', BuckarooPaymentCreditcard);
    PluginManager.register('BuckarooPaymentHelper', BuckarooPaymentHelper);
    PluginManager.register('PaypalExpressPlugin', PaypalExpressPlugin, '[data-paypal-express]');
    PluginManager.register('BuckarooIdealQrPlugin', IdealQrPlugin, '[data-ideal-qr]');
    PluginManager.register('BuckarooApplePayPlugin', ApplePayPlugin, '[data-bk-applepay]');
    PluginManager.register('BuckarooLoadScripts', BuckarooLoadScripts);
    PluginManager.register('BuckarooBanContact', BuckarooBanContact);
    PluginManager.register('BuckarooPayByBankSelect', BuckarooPayByBankSelect, '[data-bk-select]');
    PluginManager.register('BuckarooPayByBankLogo', BuckarooPayByBankLogo, '[data-bk-paybybank-logo]');
    PluginManager.register('IdealFastCheckoutPlugin', IdealFastCheckoutPlugin, '[data-bk-ideal-fast-checkout]');
}

init();