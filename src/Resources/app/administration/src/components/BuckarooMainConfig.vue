<template>
  <div>
    <BuckarooConfigCard
      :card="mainCard"
      :methods="elementMethods"
      :isNotDefaultSalesChannel="isNotDefaultSalesChannel"
      :currentSalesChannelId="currentSalesChannelId"
      :modelValue="modelValue"
      @update:modelValue="updateValue"
    />
    <BuckarooPaymentList
      v-if="selectedCard === 'general'"
      :configSettings="configSettings"
      :currentSalesChannelId="currentSalesChannelId"
      :modelValue="modelValue"
      @update:modelValue="updateValue"
    />
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useRoute } from 'vue-router';
import BuckarooConfigCard from './buckaroo-config-card/BuckarooConfigCard.vue';
import BuckarooPaymentList from './buckaroo-payment-list/BuckarooPaymentList.vue';

const props = defineProps({
  configSettings: { type: Array, required: true },
  modelValue: { type: Object, required: true },
  elementMethods: { type: Object, required: true },
  isNotDefaultSalesChannel: { type: Boolean, required: true },
  currentSalesChannelId: { type: String, required: true },
  selectedCard: String
});

const emit = defineEmits(['update:modelValue']);

const route = useRoute();
const selectedCard = ref(route.params?.paymentCode || 'general');

watch(
  () => route.params.paymentCode,
  (newVal) => {
    if (newVal) selectedCard.value = newVal;
  }
);

const mainCard = computed(() => {
  return props.configSettings.filter(card => card.name === selectedCard.value).pop();
});

function updateValue(val) {
  emit('update:modelValue', val);
}
</script> 