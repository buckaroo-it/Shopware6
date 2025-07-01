<template>
  <template v-if="currentOrder.transactions.length > 0 && (!currentOrder.transactions.last().paymentMethod.translated.customFields || !currentOrder.transactions.last().paymentMethod.translated.customFields.is_buckaroo)">
    <slot />
  </template>
  <template v-if="currentOrder.transactions.length > 0 && currentOrder.transactions.last().paymentMethod.translated.customFields && currentOrder.transactions.last().paymentMethod.translated.customFields.is_buckaroo">
    <dt>{{ $tc('sw-order.detailBase.labelPaymentMethod') }}</dt>
    <dd>
      <template v-if="config['BuckarooPayments.config.' + currentOrder.transactions.last().paymentMethod.translated.customFields.buckaroo_key + 'Label']">
        {{ config['BuckarooPayments.config.' + currentOrder.transactions.last().paymentMethod.translated.customFields.buckaroo_key + 'Label'] }}
      </template>
    </dd>
  </template>
</template>

<script setup>
import { ref, onMounted, inject } from 'vue';

const props = defineProps({
  currentOrder: { type: Object, required: true }
});

const config = ref({});
const systemConfigApiService = inject('systemConfigApiService');

onMounted(() => {
  systemConfigApiService.getValues('BuckarooPayments.config', null)
    .then(values => {
      config.value = values;
    });
});
</script> 