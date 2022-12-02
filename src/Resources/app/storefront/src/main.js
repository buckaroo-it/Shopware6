import BuckarooPaymentCreditcards from './creditcards/buckaroo-payment.creditcards';
import BuckarooPaymentHelper from './helper/buckaroo-payment.helper';
import BuckarooPaymentValidateSubmit from './helper/buckaroo-validate';
import PaypalExpressPlugin from './paypal-express/paypal-express.plugin';
import ApplePayPlugin from './applepay/applepay.plugin';
import BuckarooLoadScrips from './scripts/scripts.plugin';

const PluginManager = window.PluginManager;
PluginManager.register('BuckarooPaymentValidateSubmit', BuckarooPaymentValidateSubmit);
PluginManager.register('BuckarooPaymentCreditcards', BuckarooPaymentCreditcards);
PluginManager.register('BuckarooPaymentHelper', BuckarooPaymentHelper);
PluginManager.register('PaypalExpressPlugin', PaypalExpressPlugin, '[data-paypal-express]');
PluginManager.register('BuckarooApplePayPlugin', ApplePayPlugin, '[data-bk-applepay]');
PluginManager.register('BuckarooLoadScrips', BuckarooLoadScrips);