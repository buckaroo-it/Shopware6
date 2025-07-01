<template>
  <template v-if="domain !== 'BuckarooPayments.config'">
    <slot />
  </template>
  <template v-else>
    <BuckarooMainConfig
      v-model="actualConfigData[currentSalesChannelId]"
      :configSettings="config"
      :isNotDefaultSalesChannel="isNotDefaultSalesChannel"
      :currentSalesChannelId="currentSalesChannelId"
      :elementMethods="{
        getElementBind,
        getFieldError,
        getInheritWrapperBind,
        getInheritedValue,
        hasMapInheritanceSupport,
        getInlineSnippet,
        kebabCase
      }"
      v-if="!isLoading"
    />
  </template>
</template>

<script setup>
import { computed, toRefs } from 'vue';
import BuckarooMainConfig from '@/components/BuckarooMainConfig.vue';

const props = defineProps({
  domain: String,
  actualConfigData: Object,
  currentSalesChannelId: String,
  config: Array,
  isNotDefaultSalesChannel: Boolean,
  isLoading: Boolean,
  getElementBind: Function,
  getFieldError: Function,
  getInheritWrapperBind: Function,
  getInheritedValue: Function,
  hasMapInheritanceSupport: Function,
  getInlineSnippet: Function,
  kebabCase: Function
});

// Methods for saveAll, saveBuckaroo, getCurrentConfigCard, getSelectedValues can be implemented as needed in the parent or via emits.
</script> 