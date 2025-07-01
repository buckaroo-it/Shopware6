<template>
  <sw-card-section divider="top" secondary slim v-if="order.customFields !== null">
    <sw-container columns="1fr 440px"
      class="sw-order-detail__summary" v-if="order.customFields.buckarooFee !== null && order.customFields.buckarooFee > 0">
      <sw-description-list grid="265px 1fr"
        class="sw-order-detail__summary-data">
        <dt>{{ $tc('buckaroo-payment.fee') }}</dt>
        <dd>{{ order.customFields.buckarooFee }}
          <template v-if="order.currency.isoCode == 'PLN'">
            PLN
          </template>
          <template v-else>
            {{ order.currency.symbol }}
          </template>
        </dd>
      </sw-description-list>
    </sw-container>
  </sw-card-section>
  <slot />
</template>

<script setup>
const props = defineProps({
  order: { type: Object, required: true }
});
</script> 