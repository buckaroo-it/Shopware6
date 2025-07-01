<template>
  <sw-button
    @click="sendTestApi"
    :disabled="!enabled"
    variant="primary"
    :square="false"
    :block="true"
    :isLoading="isLoading"
  >
    {{ $tc('buckaroo-payment.button.labelTestApi') }}
  </sw-button>
</template>

<script setup>
import { ref, computed, inject } from 'vue';

const props = defineProps({
  config: { type: Object, required: true },
  currentSalesChannelId: { required: true }
});

const isLoading = ref(false);
const BuckarooPaymentSettingsService = inject('BuckarooPaymentSettingsService');

const enabled = computed(() => {
  return (
    (getConfigValue('websiteKey') || '').length > 0 &&
    (getConfigValue('secretKey') || '').length > 0
  );
});

function getConfigValue(name) {
  return props.config[`BuckarooPayments.config.${name}`];
}

function sendTestApi() {
  isLoading.value = true;
  const websiteKeyId = getConfigValue('websiteKey');
  const secretKeyId = getConfigValue('secretKey');
  BuckarooPaymentSettingsService.getApiTest(websiteKeyId, secretKeyId, props.currentSalesChannelId)
    .then((result) => {
      isLoading.value = false;
      if (result.status === 'success') {
        window.createNotificationSuccess && window.createNotificationSuccess({
          title: 'Success',
          message: result.message
        });
      } else {
        window.createNotificationError && window.createNotificationError({
          title: 'Error',
          message: result.message
        });
      }
    })
    .catch(() => {
      isLoading.value = false;
    });
}
</script> 