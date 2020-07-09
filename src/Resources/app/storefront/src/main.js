import BuckarooPaymentCreditcards from './creditcards/buckaroo-payment.creditcards';
import BuckarooPaymentHelper from './helper/buckaroo-payment.helper';

const PluginManager = window.PluginManager;
PluginManager.register('BuckarooPaymentCreditcards', BuckarooPaymentCreditcards);
PluginManager.register('BuckarooPaymentHelper', BuckarooPaymentHelper);