<template>
  <sw-card position-identifier="xxsw-system-config-content">
    <template #title>
      {{ getInlineSnippet(card.title) }}
    </template>
    <div v-for="element in card.elements" :key="element.name">
      <div v-if="canShow(element)">
        <slot name="card-element" :element="getElementBind(element)" :config="modelValue" :card="card">
          <sw-inherit-wrapper
            :modelValue="modelValue[element.name]"
            v-bind="getInheritWrapperBind(element)"
            :has-parent="isNotDefaultSalesChannel"
            :inherited-value="getInheritedValue(element)"
            @update:modelValue="val => updateElementValue(element.name, val)"
          >
            <component
              :is="element.component"
              v-bind="getElementBind(element)"
              :modelValue="modelValue[element.name]"
              @update:modelValue="val => updateElementValue(element.name, val)"
            />
          </sw-inherit-wrapper>
        </slot>
      </div>
    </div>
    <BuckarooTestCredentials
      :config="modelValue"
      :currentSalesChannelId="currentSalesChannelId"
    />
  </sw-card>
</template>

<script setup>
import { computed } from 'vue';
import BuckarooTestCredentials from '../buckaroo-test-credentials/BuckarooTestCredentials.vue';

const props = defineProps({
  card: { type: Object, required: true },
  methods: { type: Object, required: true },
  isNotDefaultSalesChannel: { type: Boolean, required: true },
  currentSalesChannelId: { type: String, required: true },
  modelValue: { type: Object, required: true }
});

const emit = defineEmits(['update:modelValue']);

const getElementBind = (element, extraProps) => props.methods.getElementBind(element, extraProps);
const getInheritWrapperBind = (element) => props.methods.getInheritWrapperBind(element);
const getFieldError = (name) => props.methods.getFieldError(name);
const kebabCase = (string) => props.methods.kebabCase(string);
const getInlineSnippet = (title) => props.methods.getInlineSnippet(title);
const getInheritedValue = (element) => props.methods.getInheritedValue(element);

function updateElementValue(name, val) {
  emit('update:modelValue', { ...props.modelValue, [name]: val });
}

function getValueForName(name) {
  return props.modelValue[`BuckarooPayments.config.${name}`];
}

function canShow(element) {
  const name = element.name.replace('BuckarooPayments.config.', '');
  if ([
    'orderStatus',
    'paymentSuccesStatus',
    'automaticallyCloseOpenOrders',
    'sendInvoiceEmail',
  ].includes(name)) {
    return getValueForName('advancedConfiguration');
  }
  if (name === 'idealprocessingRenderMode') {
    return getValueForName('idealprocessingShowissuers');
  }
  if (name === 'idealRenderMode') {
    return getValueForName('idealShowissuers');
  }
  if ([
    'idealFastCheckoutEnabled',
    'idealFastCheckoutVisibility',
    'idealFastCheckoutLogoScheme',
  ].includes(name)) {
    return getValueForName('idealFastCheckout');
  }
  if (name === 'afterpayPaymentstatus') {
    return getValueForName('afterpayCaptureonshippent');
  }
  if (name === 'afterpayOldtax') {
    return getValueForName('afterpayEnabledold');
  }
  return true;
}

function canShowCredentialTester(element) {
  if (getValueForName('advancedConfiguration')) {
    return element.name === 'BuckarooPayments.config.orderStatus';
  }
  return element.name === 'BuckarooPayments.config.advancedConfiguration';
}
</script> 