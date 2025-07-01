<template>
  <sw-card position-identifier="bk-config-payment-list" :title="$tc('buckaroo-payment.payment-methods')">
    <div class="bk-payment-wrap">
      <template v-for="payment in payments" :key="payment.code">
        <div class="bk-payment">
          <div class="bk-payment-inner">
            <router-link :title="$tc('buckaroo-payment.configure-payment')" :to="{name: 'buckaroo.payment.config', params: {namespace: 'BuckarooPayments', paymentCode: payment.code } }">
              <div class="bk-payment-img">
                <img :src="assetFilter(`buckaroopayments/administration/static/${payment.logo}`)" alt="Payment logo">
              </div>
              <div class="bk-payment-name">
                {{ getPaymentTitle(payment.code) }}
              </div>
            </router-link>
            <BuckarooToggleStatus
              :currentSalesChannelId="currentSalesChannelId"
              :method="payment.code"
              :modelValue="modelValue"
              @update:modelValue="updateValue"
            />
            <router-link class="bk-link" :title="$tc('buckaroo-payment.configure-payment')" :to="{name: 'buckaroo.payment.config', params: {namespace: 'BuckarooPayments', paymentCode: payment.code } }">
              {{$tc('buckaroo-payment.configure-link')}}
            </router-link>
          </div>
        </div>
      </template>
    </div>
  </sw-card>
</template>

<script setup>
import { computed } from 'vue';
import BuckarooToggleStatus from './BuckarooToggleStatus.vue';

const props = defineProps({
  configSettings: { required: true },
  modelValue: { required: true },
  currentSalesChannelId: { required: true }
});
const emit = defineEmits(['update:modelValue']);

function updateValue(val) {
  emit('update:modelValue', val);
}

const payments = [
  { code: "Alipay", logo: "alipay.svg" },
  { code: "applepay", logo: "applepay.svg" },
  { code: "bancontactmrcash", logo: "bancontact.svg" },
  { code: "blik", logo: "blik.svg" },
  { code: "belfius", logo: "belfius.svg" },
  { code: "Billink", logo: "billink.svg" },
  { code: "creditcard", logo: "creditcards.svg" },
  { code: "creditcards", logo: "creditcards.svg" },
  { code: "eps", logo: "eps.svg" },
  { code: "giftcards", logo: "giftcards.svg" },
  { code: "idealqr", logo: "ideal-qr.svg" },
  { code: "ideal", logo: "ideal.svg" },
  { code: "capayable", logo: "ideal-in3.svg" },
  { code: "KBCPaymentButton", logo: "kbc.svg" },
  { code: "klarnakp", logo: "klarna.svg" },
  { code: "knaken", logo: "gosettle.svg" },
  { code: "mbway", logo: "mbway.svg" },
  { code: "multibanco", logo: "multibanco.svg" },
  { code: "paybybank", logo: "paybybank.svg" },
  { code: "payconiq", logo: "payconiq.svg" },
  { code: "paypal", logo: "paypal.svg" },
  { code: "payperemail", logo: "payperemail.svg" },
  { code: "Przelewy24", logo: "przelewy24.svg" },
  { code: "afterpay", logo: "afterpay.svg" },
  { code: "sepadirectdebit", logo: "sepa-directdebit.svg" },
  { code: "transfer", logo: "sepa-credittransfer.svg" },
  { code: "Trustly", logo: "trustly.svg" },
  { code: "WeChatPay", logo: "wechatpay.svg" }
];

function getPaymentTitle(code) {
  const card = props.configSettings.find((card) => card.name === code);
  if (card) {
    return window.getInlineSnippet ? window.getInlineSnippet(card.title) : card.title;
  }
  const payment = payments.find(payment => payment.code === code);
  return payment ? payment.code : 'Unknown Payment';
}
function assetFilter(path) {
  // Shopware's asset filter, fallback to just the path
  if (window.Shopware && window.Shopware.Filter && window.Shopware.Filter.getByName) {
    return window.Shopware.Filter.getByName('asset')(path);
  }
  return path;
}
</script>

<style scoped>
.bk-payment-wrap {
    display: flex;
    flex-wrap: wrap;
}
.bk-payment {
    flex: 0 20%;
    display: flex;
    flex-direction: column;
    align-items: center;
}
.bk-payment-inner {
    display: flex;
    width: 170px;
    flex-direction: column;
    align-items: center;
    margin: 0.25rem 0.125rem;
    padding: 0.5rem;
    background-color: #fafafa;
    border-radius: 0.5rem;
}
.bk-payment-inner a {
    text-decoration: none;
}
.bk-payment-name {
    text-align: center;
    text-decoration: none;
    padding: 0.25rem 0 0.5rem;
    color: #555555;
    font-weight: bold;
    font-size: 16px;
}
.bk-payment-img {
    height: 60px;
    width: auto;
    padding: 10px;
    display: flex;
}
.bk-payment-img img {
    width: 100%;
    height: auto;
}
.bk-link {
    padding: 0.25rem 0 0.125rem;
    color: #555555;
}
.bk-link:hover {
   font-weight: bold;
}
</style> 