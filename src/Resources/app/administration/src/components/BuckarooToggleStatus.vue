<template>
  <div class="bk-toogle-wrap">
    <button
      type="button"
      :disabled="isLoading"
      :class="`live ${getClass('live')}`"
      @click="setStatus('live')"
    >
      Live
    </button>
    <button
      v-if="method !== 'idealqr'"
      type="button"
      :disabled="isLoading"
      :class="`test ${getClass('test')}`"
      @click="setStatus('test')"
    >
      Test
    </button>
    <button
      type="button"
      :disabled="isLoading"
      :class="`disabled ${getClass('disabled')}`"
      @click="setStatus('disabled')"
    >
      Off
    </button>
  </div>
</template>

<script setup>
import { ref, watch, inject } from 'vue';

const props = defineProps({
  method: { type: String, required: true },
  modelValue: { required: true },
  currentSalesChannelId: { required: true }
});
const emit = defineEmits(['update:modelValue']);

const systemConfigApiService = inject('systemConfigApiService');
const isLoading = ref(false);
const status = ref(getStatus());

watch(() => props.modelValue, () => {
  status.value = getStatus();
});

function getStatus() {
  if (!isActive()) {
    return 'disabled';
  }
  return getEnvironment();
}
function isActive() {
  return getValueForName(`${props.method}Enabled`);
}
function getEnvironment() {
  const env = getValueForName(`${props.method}Environment`);
  if (env === undefined) {
    return 'test';
  }
  return env;
}
function getValueForName(name) {
  return props.modelValue?.[`BuckarooPayments.config.${name}`];
}
function setStatus(newStatus) {
  status.value = newStatus;
  saveStatus();
}
function getClass(buttonStatus) {
  return status.value === buttonStatus ? 'active' : '';
}
async function saveStatus() {
  const enabledKey = `BuckarooPayments.config.${props.method}Enabled`;
  const environmentKey = `BuckarooPayments.config.${props.method}Environment`;
  let data = { [enabledKey]: false };
  const newValue = { ...props.modelValue };
  newValue[enabledKey] = false;
  if (['live', 'test'].includes(status.value)) {
    data = {
      [enabledKey]: true,
      [environmentKey]: status.value
    };
    newValue[enabledKey] = true;
    newValue[environmentKey] = status.value;
  }
  isLoading.value = true;
  try {
    await systemConfigApiService.batchSave({ [props.currentSalesChannelId]: data });
    isLoading.value = false;
    emit('update:modelValue', newValue);
    renderSuccess();
  } catch (error) {
    isLoading.value = false;
    renderError(error);
  }
}
function renderSuccess() {
  window.createNotificationSuccess && window.createNotificationSuccess({
    message: 'Saved successfully.'
  });
}
function renderError(err) {
  window.createNotificationError && window.createNotificationError({
    message: err?.message || err
  });
}
</script>

<style scoped>
.bk-toogle-wrap {
    display: flex;
    border-radius: 0.25rem;
    box-shadow: 0 0 1px rgba(0, 0, 0, 0.2);
    padding:0.125rem;
    background-color: #fff;
}
.bk-toogle-wrap button {
    outline: none;
    margin:0.125rem;
    padding:0.25rem;
    background-color: rgb(229 231 235);
    border-radius: 0.125rem;
    text-transform: uppercase;
    border:0;
    color: #9ca3af;
    cursor: pointer;
    flex-grow: 1;
}
.bk-toogle-wrap button.test:hover,  .bk-toogle-wrap button.test.active {
    background-color: #eab308;
    color:#fff;
}
.bk-toogle-wrap button.live:hover, .bk-toogle-wrap button.live.active {
    background-color: #22c55e;
    color:#fff;
}
.bk-toogle-wrap button.disabled:hover, .bk-toogle-wrap button.disabled.active {
    background-color: #000;
    color:#fff;
}
.bk-toogle-wrap .bk-edit-link {
    display: flex;
    align-items: center;
    margin:0 0.125rem
}
</style> 