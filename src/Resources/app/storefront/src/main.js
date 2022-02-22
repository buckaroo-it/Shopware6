import BuckarooPaymentCreditcards from './creditcards/buckaroo-payment.creditcards';
import BuckarooPaymentHelper from './helper/buckaroo-payment.helper';
import BuckarooPaymentValidateSubmit from './helper/buckaroo-validate';

const PluginManager = window.PluginManager;
PluginManager.register('BuckarooPaymentValidateSubmit', BuckarooPaymentValidateSubmit);
PluginManager.register('BuckarooPaymentCreditcards', BuckarooPaymentCreditcards);
PluginManager.register('BuckarooPaymentHelper', BuckarooPaymentHelper);