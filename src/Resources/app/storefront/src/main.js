import BuckarooPaymentCreditcards from './creditcards/buckaroo-payment.creditcards';
import BuckarooPaymentHelper from './helper/buckaroo-payment.helper';
import BuckarooPaymentValidateSubmit from './helper/buckaroo-validate';
import PaypalExpressPlugin from './paypal-express/paypal-express.plugin';
import ApplePayPlugin from './applepay/applepay.plugin';
import BuckarooLoadScripts from './scripts/scripts.plugin';
import IdealQrPlugin from './ideal-qr/ideal-qr.plugin';

const PluginManager = window.PluginManager;
PluginManager.register('BuckarooPaymentValidateSubmit', BuckarooPaymentValidateSubmit);
PluginManager.register('BuckarooPaymentCreditcards', BuckarooPaymentCreditcards);
PluginManager.register('BuckarooPaymentHelper', BuckarooPaymentHelper);
PluginManager.register('PaypalExpressPlugin', PaypalExpressPlugin, '[data-paypal-express]');
PluginManager.register('BuckarooIdealQrPlugin', IdealQrPlugin, '[data-ideal-qr]');
PluginManager.register('BuckarooApplePayPlugin', ApplePayPlugin, '[data-bk-applepay]');
PluginManager.register('BuckarooLoadScripts', BuckarooLoadScripts);