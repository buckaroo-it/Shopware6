<template>
  <div>
    <div
      v-if="isPaymentInTestMode && setIsBuckarooPayment"
      style="max-width: 960px; margin: 0 auto 15px; background: rgb(251, 227, 231); border-radius:4px; color: #de294c; display:flex; align-items:center;">
      <div style="background:#de294c;padding: 4px 8px;border-radius: 4px 0 0 4px;"><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="24" height="24" viewBox="0 0 24 24"><defs><path d="m13.7744 1.4124 9.7058 18.6649c.5096.98.1283 2.1875-.8517 2.6971a2 2 0 0 1-.9227.2256H2.2942c-1.1045 0-2-.8954-2-2a2 2 0 0 1 .2256-.9227l9.7058-18.665c.5096-.98 1.7171-1.3613 2.6971-.8517a2 2 0 0 1 .8517.8518zM2.2942 21h19.4116L12 2.335 2.2942 21zM12 17c.5523 0 1 .4477 1 1s-.4477 1-1 1-1-.4477-1-1 .4477-1 1-1zm1-2c0 .5523-.4477 1-1 1s-1-.4477-1-1v-5c0-.5523.4477-1 1-1s1 .4477 1 1v5z" id="icons-default-warning"></path></defs><use xlink:href="#icons-default-warning" fill="#fff" fill-rule="evenodd"></use></svg></div>
      <p style="margin-left:10px;">{{ $tc('buckaroo-payment.paymentInTestMode') }}</p>
    </div>
    <slot name="order-detail-content-tabs" />
    <sw-tabs-item v-if="setIsBuckarooPayment" :route="{ name: 'buckaroo.payment.detail', params: { id: $route.params.id } }" :title="$tc('buckaroo-payment.tabs.title')">
      {{ $tc('buckaroo-payment.tabs.title') }}
    </sw-tabs-item>
    <slot name="order-detail-content-tabs-general" />
    <template v-if="isEditable"></template>
    <slot name="order-detail-actions" />
  </div>
</template>

<script setup>
import { ref, computed, watch, getCurrentInstance, onMounted } from 'vue';
import { useRoute } from 'vue-router';

const { appContext } = getCurrentInstance();
const repositoryFactory = appContext.config.globalProperties.repositoryFactory;
const Context = window.Shopware.Context;
const Criteria = window.Shopware.Data.Criteria;
const route = useRoute();

const orderId = ref(route.params.id);
const isBuckarooPayment = ref(false);
const isPaymentInTestMode = ref(false);

const isEditable = computed(() => {
  return !isBuckarooPayment.value || route.name !== 'buckaroo.payment.detail';
});

const setIsBuckarooPayment = computed(() => isBuckarooPayment.value);

watch(orderId, async (newOrderId) => {
  if (!newOrderId) {
    setIsBuckarooPayment.value = null;
    return;
  }
  const orderRepository = repositoryFactory.create('order');
  const orderCriteria = new Criteria(1, 1);
  orderCriteria.addAssociation('transactions');
  const order = await orderRepository.get(newOrderId, Context.api, orderCriteria);
  setPaymentInTestMode(order);
  if (order.transactions.length <= 0 || !order.transactions.last().paymentMethodId) {
    setIsBuckarooPayment.value = null;
    return;
  }
  const paymentMethodId = order.transactions.last().paymentMethodId;
  if (paymentMethodId !== undefined && paymentMethodId !== null) {
    await setIsBuckarooPaymentFn(paymentMethodId);
  }
}, { immediate: true });

function setPaymentInTestMode(order) {
  if (order.customFields && order.customFields.buckaroo_payment_in_test_mode) {
    isPaymentInTestMode.value = order.customFields.buckaroo_payment_in_test_mode === true;
  }
}
async function setIsBuckarooPaymentFn(paymentMethodId) {
  if (!paymentMethodId) {
    return;
  }
  const paymentMethodRepository = repositoryFactory.create('payment_method');
  const paymentMethod = await paymentMethodRepository.get(paymentMethodId, Context.api);
  isBuckarooPayment.value = paymentMethod.formattedHandlerIdentifier.indexOf('buckaroo') >= 0;
}
</script> 