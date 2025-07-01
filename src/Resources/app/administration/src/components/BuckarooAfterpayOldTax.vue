<template>
  <div>
    <a style="margin-bottom:10px;" @click="showTaxes = !showTaxes">{{$tc('buckaroo-payment.afterpay.setup')}}</a>
    <div v-if="showTaxes" style="padding: 10px;background: rgb(246 246 246); margin-bottom:10px;">
      <div class="tax" v-for="tax in taxes" :key="tax.id">
        <sw-select-field
          :label="tax.name"
          label-property="name"
          value-property="id"
          :options="afterpayTaxes"
          @change="setTaxAssociation(tax.id, $event)"
          :value="getSelectValue(tax.id)"
        ></sw-select-field>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, inject, onMounted } from 'vue';

const props = defineProps({
  name: { type: String, required: true, default: '' },
  value: { type: Object, required: false, default: () => ({}) }
});
const emit = defineEmits(['change']);

const BuckarooPaymentSettingsService = inject('BuckarooPaymentSettingsService');
const taxes = ref([]);
const showTaxes = ref(false);
const afterpayTaxes = ref([
  { name: $tc('buckaroo-payment.afterpay.hightTaxes'), id: 1 },
  { name: $tc('buckaroo-payment.afterpay.middleTaxes'), id: 5 },
  { name: $tc('buckaroo-payment.afterpay.lowTaxes'), id: 2 },
  { name: $tc('buckaroo-payment.afterpay.zeroTaxes'), id: 3 },
  { name: $tc('buckaroo-payment.afterpay.noTaxes'), id: 4 },
]);
const taxAssociation = ref({});

onMounted(() => {
  BuckarooPaymentSettingsService.getTaxes().then((result) => {
    taxes.value = result.taxes.map((tax) => ({ id: tax.id, name: tax.name }));
  });
});

function setTaxAssociation(taxId, value) {
  taxAssociation.value[taxId] = value;
  emit('change', { ...props.value, ...taxAssociation.value });
}
function getSelectValue(taxId) {
  if (props.value[taxId]) {
    return props.value[taxId];
  }
  return undefined;
}
</script> 