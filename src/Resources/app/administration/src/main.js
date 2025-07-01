import './module/buckaroo-payment';
import './api/buckaroo-payment.service';
import './api/buckaroo-payment-settings.service';
import BuckarooAfterpayOldTax from './components/BuckarooAfterpayOldTax.vue';
import BuckarooMainConfig from './components/BuckarooMainConfig.vue';
import BuckarooConfigCard from './components/BuckarooConfigCard.vue';
import BuckarooPaymentList from './components/BuckarooPaymentList.vue';
import BuckarooTestCredentials from './components/BuckarooTestCredentials.vue';
import BuckarooToggleStatus from './components/BuckarooToggleStatus.vue';

// Register components globally if needed
const { Component } = Shopware;
Component.register('buckaroo-afterpay-old-tax', BuckarooAfterpayOldTax);
Component.register('buckaroo-main-config', BuckarooMainConfig);
Component.register('buckaroo-config-card', BuckarooConfigCard);
Component.register('buckaroo-payment-list', BuckarooPaymentList);
Component.register('buckaroo-test-credentials', BuckarooTestCredentials);
Component.register('buckaroo-toggle-status', BuckarooToggleStatus);