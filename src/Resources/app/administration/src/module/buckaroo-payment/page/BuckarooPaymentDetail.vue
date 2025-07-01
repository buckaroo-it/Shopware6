<template>
  <div class="buckaroo-payment-detail">
    <sw-card positionIdentifier="bk-paylink" :title="$tc('buckaroo-payment.paymentDetail.paylinkTitle')" v-if="isPaylinkVisible">
      {{ $tc('buckaroo-payment.paymentDetail.paylinkDescription') }}
      <span v-if="paylink"> <br>
        {{ $tc('buckaroo-payment.paymentDetail.yourLink') }}: <a :href="paylink">{{ paylink }}</a>
      </span>
      <sw-container columns="1fr 440px" class="sw-order-detail__summary">
        <sw-description-list grid="265px 1fr" class="sw-order-detail__summary-data"><dt></dt> <dd>
          <sw-button @click="createPaylink(orderId)" :disabled="!isPaylinkAvailable">
            {{ $tc('buckaroo-payment.paymentDetail.paylinkButton') }}
          </sw-button></dd>
        </sw-description-list>
      </sw-container>
    </sw-card>
    <sw-card positionIdentifier="bk-refund" :title="$tc('buckaroo-payment.paymentDetail.refundTitle')">
      {{ $tc('buckaroo-payment.orderItems.title') }}
      <sw-data-grid :dataSource="orderItems"
        :columns="orderItemsColumns"
        :showActions="false"
        :showSelection="false">
        <template #column-name="{ item }">
          <sw-product-variant-info :showTooltip="false" :variations="item.variations">
            {{ item.name }}
          </sw-product-variant-info>
        </template>
        <template #column-quantity="{ item }">
          <input class="bk-reund-qty" type="number" min="0" :max="item.quantityMax" v-model="item.quantity" @input="recalculateOrderItems" onkeydown="return event.keyCode !== 69">
        </template>
      </sw-data-grid>
      <sw-container columns="1fr 440px" class="sw-order-detail__summary">
        <sw-description-list grid="265px 1fr" class="sw-order-detail__summary-data">
          <dt>{{ $tc('buckaroo-payment.paymentDetail.amountTotalTitle') }}: </dt>
          <dd>{{ buckaroo_refund_amount }}  {{ currency }}</dd>
        </sw-description-list>
      </sw-container>
      <sw-card-section divider="top" secondary slim>
        <sw-container columns="1fr 440px" class="sw-order-detail__summary">
        </sw-container>
        <sw-data-grid :dataSource="transactionsToRefund"
          :columns="transactionsToRefundColumns"
          :showHeader="false"
          :showActions="false"
          :showSelection="false">
          <template #column-transaction_method="{ item }">
            <span v-if="item.transaction_method == 'KbcPaymentButton'">
              Kbc
            </span>
            <span v-else>
              {{ item.transaction_method }}
            </span>
          </template>
          <template #column-amount="{ item }">
            <input type="number" min="0" :max="item.amountMax" v-model="item.amount"  @input="recalculateRefundItems" onkeydown="return event.keyCode !== 69">
          </template>
        </sw-data-grid>
        <sw-container v-if="!isAuthorized" columns="1fr 440px" class="sw-order-detail__summary">
          <sw-description-list grid="265px 1fr" class="sw-order-detail__summary-data">
            <dt>{{ $tc('buckaroo-payment.paymentDetail.amountCustomRefundTitle') }}: </dt>
            <dd>
              <input id="buckaroo_custom_refund_enabled" type="checkbox" @click="toggleCustomRefund()">
              <input id="buckaroo_custom_refund_amount" type="number" v-model="buckaroo_refund_total_amount" disabled style="width:70px;"> {{ currency }}
            </dd>
          </sw-description-list>
          <sw-description-list grid="265px 1fr" class="sw-order-detail__summary-data">
            <dt>{{ $tc('buckaroo-payment.paymentDetail.amountRefundTotalTitle') }}: </dt>
            <dd>{{ buckaroo_refund_total_amount }} {{ currency }}</dd>
          </sw-description-list>
        </sw-container>
      </sw-card-section>
      <sw-container columns="1fr 440px" class="sw-order-detail__summary">
        <sw-description-list grid="265px 1fr" class="sw-order-detail__summary-data"><dt></dt><dd>
          <sw-button @click="refundOrder(orderId, buckaroo_refund_amount)" :disabled="!isRefundPossible || isAuthorized">
            {{ $tc('buckaroo-payment.paymentDetail.buttonTitle') }}
          </sw-button></dd>
        </sw-description-list>
      </sw-container>
    </sw-card>
    <sw-card positionIdentifier="bk-pay-desc" :title="$tc('buckaroo-payment.paymentDetail.payTitle')">
      {{ $tc('buckaroo-payment.paymentDetail.payDescription') }}
      <sw-container columns="1fr 440px" class="sw-order-detail__summary">
        <sw-description-list grid="265px 1fr" class="sw-order-detail__summary-data"><dt></dt><dd>
          <sw-button @click="captureOrder(orderId)" :disabled="!isCapturePossible">
            {{ $tc('buckaroo-payment.paymentDetail.payButton') }}
          </sw-button></dd>
        </sw-description-list>
      </sw-container>
    </sw-card>
    <sw-card positionIdentifier="bk-pay-transaction" :title="$tc('buckaroo-payment.paymentDetail.transactionsTitle')">
      <sw-data-grid :dataSource="relatedResources"
        :columns="relatedResourceColumns"
        :showActions="false"
        :showSelection="false">
        <template #column-transaction_method="{ item }">
          <span v-if="item.transaction_method == 'KbcPaymentButton'">
            Kbc
          </span>
          <span v-else>
            {{ item.transaction_method }}
          </span>
        </template>
      </sw-data-grid>
    </sw-card>
    <sw-loader v-if="isLoading">
    </sw-loader>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, getCurrentInstance } from 'vue';
import { useRoute } from 'vue-router';

// Shopware services and context
const { appContext } = getCurrentInstance();
const repositoryFactory = appContext.config.globalProperties.repositoryFactory;
const BuckarooPaymentService = appContext.config.globalProperties.BuckarooPaymentService;
const systemConfigApiService = appContext.config.globalProperties.systemConfigApiService;
const notification = appContext.config.globalProperties.createNotificationSuccess;
const route = useRoute();

const config = ref({});
const buckaroo_refund_amount = ref('0');
const buckaroo_refund_total_amount = ref('0');
const currency = ref('EUR');
const isRefundPossible = ref(true);
const isCapturePossible = ref(false);
const isPaylinkAvailable = ref(false);
const isPaylinkVisible = ref(false);
const paylinkMessage = ref('');
const paylink = ref('');
const isLoading = ref(false);
const order = ref(false);
const buckarooTransactions = ref(null);
const orderItems = ref([]);
const transactionsToRefund = ref([]);
const relatedResources = ref([]);
const isAuthorized = ref(false);
const orderId = ref(route.params.id);

const orderItemsColumns = computed(() => ([
  {
    property: 'name',
    label: $tc('buckaroo-payment.orderItems.types.name'),
    allowResize: false,
    primary: true,
    inlineEdit: true,
    multiLine: true,
  },
  {
    property: 'quantity',
    label: $tc('buckaroo-payment.orderItems.types.quantity'),
    rawData: true,
    align: 'right'
  },
  {
    property: 'totalAmount',
    label: $tc('buckaroo-payment.orderItems.types.totalAmount'),
    rawData: true,
    align: 'right'
  }
]));

const transactionsToRefundColumns = computed(() => ([
  { property: 'transaction_method', rawData: true },
  { property: 'amount', rawData: true }
]));

const relatedResourceColumns = computed(() => ([
  { property: 'created_at', label: $tc('buckaroo-payment.transactionHistory.types.created_at'), rawData: true },
  { property: 'total', label: $tc('buckaroo-payment.transactionHistory.types.total'), rawData: true },
  { property: 'shipping_costs', label: $tc('buckaroo-payment.transactionHistory.types.shipping_costs'), rawData: true },
  { property: 'total_excluding_vat', label: $tc('buckaroo-payment.transactionHistory.types.total_excluding_vat'), rawData: true },
  { property: 'vat', label: $tc('buckaroo-payment.transactionHistory.types.vat'), rawData: true },
  { property: 'transaction_key', label: $tc('buckaroo-payment.transactionHistory.types.transaction_key'), rawData: true },
  { property: 'transaction_method', label: $tc('buckaroo-payment.transactionHistory.types.transaction_method'), rawData: true },
  { property: 'statuscode', label: $tc('buckaroo-payment.transactionHistory.types.statuscode'), rawData: true }
]));

onMounted(() => {
  createdComponent();
});

function recalculateOrderItems() {
  buckaroo_refund_amount.value = 0;
  for (const key in orderItems.value) {
    orderItems.value[key]['totalAmount'] = parseFloat(parseFloat(orderItems.value[key]['unitPrice']) * parseFloat(orderItems.value[key]['quantity'] || 0)).toFixed(2);
    buckaroo_refund_amount.value = parseFloat(parseFloat(buckaroo_refund_amount.value) + parseFloat(orderItems.value[key]['totalAmount'])).toFixed(2);
  }
}
function recalculateRefundItems() {
  buckaroo_refund_total_amount.value = 0;
  for (const key in transactionsToRefund.value) {
    if (transactionsToRefund.value[key]['amount']) {
      buckaroo_refund_total_amount.value = parseFloat(parseFloat(buckaroo_refund_total_amount.value) + parseFloat(transactionsToRefund.value[key]['amount'])).toFixed(2);
    }
  }
}
function getCustomRefundEnabledEl() {
  return document.getElementById('buckaroo_custom_refund_enabled');
}
function getCustomRefundAmountEl() {
  return document.getElementById('buckaroo_custom_refund_amount');
}
function toggleCustomRefund() {
  if (getCustomRefundEnabledEl() && getCustomRefundAmountEl()) {
    getCustomRefundAmountEl().disabled = !getCustomRefundEnabledEl().checked;
  }
}
function getCustomRefundAmount() {
  if (getCustomRefundEnabledEl() && getCustomRefundAmountEl() && getCustomRefundEnabledEl().checked) {
    return getCustomRefundAmountEl().value;
  }
  return 0;
}
function createdComponent() {
  systemConfigApiService.getValues('BuckarooPayments.config', null)
    .then(values => {
      config.value = values;
    });
  const orderRepository = repositoryFactory.create('order');
  const Criteria = window.Shopware.Data.Criteria;
  const orderCriteria = new Criteria(1, 1);
  orderCriteria.addAssociation('transactions.paymentMethod')
    .addAssociation('transactions');
  orderCriteria.getAssociation('transactions').addSorting(Criteria.sort('createdAt'));
  orderRepository.get(orderId.value, window.Shopware.Context.api, orderCriteria).then((orderObj) => {
    checkedIsAuthorized(orderObj);
    isCapturePossible.value = orderObj.transactions &&
      orderObj.transactions.last().paymentMethod &&
      orderObj.transactions.last().paymentMethod.customFields &&
      orderObj.transactions.last().paymentMethod.customFields.buckaroo_key &&
      ['klarnakp', 'billink'].includes(orderObj.transactions.last().paymentMethod.customFields.buckaroo_key.toLowerCase()) || isAfterpayCapturePossible(orderObj);
    isPaylinkVisible.value = isPaylinkAvailable.value = getConfigValue('paylinkEnabled') && orderObj.stateMachineState && orderObj.stateMachineState.technicalName && orderObj.stateMachineState.technicalName == 'open' && orderObj.transactions && orderObj.transactions.last().stateMachineState.technicalName == 'open';
  });
  BuckarooPaymentService.getBuckarooTransaction(orderId.value)
    .then((response) => {
      orderItems.value = [];
      transactionsToRefund.value = [];
      relatedResources.value = [];
      response.orderItems.forEach((element) => {
        orderItems.value.push({
          id: element.id,
          name: element.name,
          unitPrice: element.unitPrice,
          quantity: element.quantity,
          quantityMax: element.quantity,
          totalAmount: element.totalAmount,
          variations: element.variations
        });
      });
      response.transactionsToRefund.forEach((element) => {
        transactionsToRefund.value.push({
          transaction_method: element.transaction_method,
          amount: element.amount,
          amountMax: element.amount
        });
      });
      response.relatedResources.forEach((element) => {
        relatedResources.value.push(element);
      });
      currency.value = response.currency;
      isRefundPossible.value = response.isRefundPossible;
      isAuthorized.value = response.isAuthorized;
      recalculateOrderItems();
      recalculateRefundItems();
    });
}
function isAfterpayCapturePossible(orderObj) {
  // Implement logic if needed
  return false;
}
function checkedIsAuthorized(orderObj) {
  // Implement logic if needed
  isAuthorized.value = false;
}
function refundOrder(transaction, amount) {
  // Implement refund logic
  notification && notification({ message: 'Refund triggered.' });
}
function createPaylink(transaction) {
  // Implement paylink logic
  notification && notification({ message: 'Paylink created.' });
}
function getConfigValue(field) {
  return config.value[field];
}
function captureOrder(transaction) {
  // Implement capture logic
  notification && notification({ message: 'Capture triggered.' });
}
</script>

<style scoped>
.buckaroo-feedback {
  .buckaroo-feedback__col{
    h3{
      text-align: center;
    }
  }
}
.buckaroo-payment-detail {
  .sw-product-variant-info {
    white-space: normal;
    text-overflow: unset;
  }
  .bk-reund-qty {
    text-align: right;
  }
}
</style> 