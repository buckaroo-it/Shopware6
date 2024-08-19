"use strict";(self.webpackChunk=self.webpackChunk||[]).push([["buckaroo-payments"],{2263:(e,t,n)=>{var i=n(6285);class o extends i.Z{init(){this._listenToSubmit(),this._createScript((()=>{const e=["creditcards_issuer","creditcards_cardholdername","creditcards_cardnumber","creditcards_expirationmonth","creditcards_expirationyear","creditcards_cvc"];for(const t of e){const e=document.getElementById(t);e&&e.addEventListener("change",this._handleInputChanged.bind(this))}const t=document.getElementById("creditcards_issuer");t&&document.getElementById("card_kind_img").setAttribute("src",t.options[t.selectedIndex].getAttribute("data-logo")),this._getEncryptedData()}))}_createScript(e){const t=document.createElement("script");t.type="text/javascript",t.src="https://static.buckaroo.nl/script/ClientSideEncryption001.js",t.addEventListener("load",e.bind(this),!1),document.head.appendChild(t)}_getEncryptedData(){const e=document.getElementById("creditcards_cardnumber"),t=document.getElementById("creditcards_expirationyear"),n=document.getElementById("creditcards_expirationmonth"),i=document.getElementById("creditcards_cvc"),o=document.getElementById("creditcards_cardholdername");var r,a,s,c,l;e&&t&&n&&i&&o&&(r=e.value,a=t.value,s=n.value,c=i.value,l=o.value,window.BuckarooClientSideEncryption.V001.encryptCardData(r,a,s,c,l,(function(e){const t=document.getElementById("encryptedCardData");t&&(t.value=e)})))}_handleInputChanged(e){const t=e.target.id,n=document.getElementById(t);if("creditcards_issuer"===t)document.getElementById("card_kind_img").setAttribute("src",n.options[n.selectedIndex].getAttribute("data-logo"));else this._CheckValidate();this._getEncryptedData()}_handleCheckField(e){switch(document.getElementById(e.id+"Error").style.display="none",e.id){case"creditcards_cardnumber":if(!window.BuckarooClientSideEncryption.V001.validateCardNumber(e.value.replace(/\s+/g,"")))return document.getElementById(e.id+"Error").style.display="block",!1;break;case"creditcards_cardholdername":if(!window.BuckarooClientSideEncryption.V001.validateCardholderName(e.value))return document.getElementById(e.id+"Error").style.display="block",!1;break;case"creditcards_cvc":if(!window.BuckarooClientSideEncryption.V001.validateCvc(e.value))return document.getElementById(e.id+"Error").style.display="block",!1;break;case"creditcards_expirationmonth":if(!window.BuckarooClientSideEncryption.V001.validateMonth(e.value))return document.getElementById(e.id+"Error").style.display="block",!1;break;case"creditcards_expirationyear":if(!window.BuckarooClientSideEncryption.V001.validateYear(e.value))return document.getElementById(e.id+"Error").style.display="block",!1}return!0}_CheckValidate(){let e=!1;const t=["creditcards_cardholdername","creditcards_cardnumber","creditcards_expirationmonth","creditcards_expirationyear","creditcards_cvc"];for(const n of t){const t=document.getElementById(n);t&&(this._handleCheckField(t)||(e=!0))}return this._disableConfirmFormSubmit(e)}_disableConfirmFormSubmit(e){return document.getElementById("confirmFormSubmit")&&(document.getElementById("confirmFormSubmit").disabled=e),e}_registerCheckoutSubmitButton(){const e=document.getElementById("confirmFormSubmit");e&&e.addEventListener("click",this._handleCheckoutSubmit.bind(this))}_validateOnSubmit(e){e.preventDefault();let t=!this._CheckValidate();document.$emitter.publish("buckaroo_payment_validate",{valid:t,type:"credicard"})}_listenToSubmit(){document.$emitter.subscribe("buckaroo_payment_submit",this._validateOnSubmit.bind(this))}}class r extends i.Z{get buckarooInputs(){return["buckaroo_capayablein3_OrderAs"]}get buckarooMobileInputs(){return["buckarooAfterpayPhone","buckarooIn3Phone","buckarooBillinkPhone"]}get buckarooDoBInputs(){return["buckaroo_afterpay_DoB","buckaroo_capayablein3_DoB","buckaroo_billink_DoB"]}init(){try{this._registerEvents()}catch(e){console.log("init error",e)}}_registerEvents(){this._checkCompany(),this._listenToSubmit();for(const e of this.buckarooInputs){const t=document.getElementById(e);t&&t.addEventListener("change",this._handleInputChanged.bind(this))}for(const e of this.buckarooMobileInputs){const t=document.getElementById(e);t&&t.addEventListener("change",this._handleMobileInputChanged.bind(this))}for(const e of this.buckarooDoBInputs){const t=document.getElementById(e);t&&t.addEventListener("change",this._handleDoBInputChanged.bind(this))}const e=document.getElementById("P24Currency");e&&"PLN"!=e.value&&(document.getElementById("confirmFormSubmit").disabled=!0,document.getElementById("P24CurrencyError").style.display="block")}_checkCompany(){const e=document.getElementById("buckaroo_capayablein3_OrderAs");let t="none",n=!1;e&&e.selectedIndex>0&&(n=!0,t="block");const i=document.getElementById("buckaroo_capayablein3_COCNumberDiv");return i&&(i.style.display=t,document.getElementById("buckaroo_capayablein3_CompanyNameDiv").style.display=t,document.getElementById("buckaroo_capayablein3_COCNumber").required=n,document.getElementById("buckaroo_capayablein3_CompanyName").required=n),n}_handleInputChanged(e){if("buckaroo_capayablein3_OrderAs"===e.target.id)this._checkCompany()}_handleMobileInputChanged(){this._CheckValidate()}_handleDoBInputChanged(){this._CheckValidate()}_CheckValidate(){let e=!1;for(const t of this.buckarooMobileInputs){const n=document.getElementById(t);n&&(this._handleCheckMobile(n)||(e=!0))}for(const t of this.buckarooDoBInputs){const n=document.getElementById(t);n&&(this._handleCheckDoB(n)||(e=!0))}return this._disableConfirmFormSubmit(e)}_handleCheckMobile(e){return document.getElementById("buckarooMobilePhoneError").style.display="none",!!e.value.match(/^\d{10}$/)||(document.getElementById("buckarooMobilePhoneError").style.display="block",!1)}_handleCheckDoB(e){document.getElementById("buckarooDoBError").style.display="none";const t=new Date(Date.parse(e.value));if("Invalid Date"==t)return document.getElementById("buckarooDoBError").style.display="block",!1;return!((new Date).getFullYear()-t.getFullYear()<18||t.getFullYear()<1900)||(document.getElementById("buckarooDoBError").style.display="block",!1)}_disableConfirmFormSubmit(e){return document.getElementById("confirmFormSubmit")&&(document.getElementById("confirmFormSubmit").disabled=e),e}_handleCompanyName(){let e=document.getElementById("buckaroo_capayablein3_CompanyNameError");return e.style.display="none",!!document.getElementById("buckaroo_capayablein3_CompanyName").value.length||(e.style.display="block",!1)}_isRadioOrCeckbox(e){return"radio"==e.type||"checkbox"==e.type}radioGroupHasRequired(e){let t=e.querySelectorAll('input[type="radio"]');return!!t&&[...t].filter((function(e){return e.checked})).length>0}isRadioGroup(e){return e.classList.contains("radio-group-required")}_handleRequired(){let e=document.getElementById("changePaymentForm").querySelectorAll("[required]");e&&e.length&&e.forEach((e=>{let t=e.parentElement;if("radio"===e.type&&(t=t.parentElement),t){let n=t.querySelector('[class="buckaroo-required"]');if(this.isRadioGroup(e)&&this.radioGroupHasRequired(e))n&&n.remove();else if(this._isRadioOrCeckbox(e)&&e.checked)n&&n.remove();else if(!this._isRadioOrCeckbox(e)&&!this.isRadioGroup(e)&&e.value.length>0)n&&n.remove();else if(null===n){n=this._createMessageElement(e),null===t.querySelector('[id$="Error"]')&&t.append(n)}}}))}_createMessageElement(e){let t=buckaroo_required_message,n=e.getAttribute("required-message");null!=n&&n.length&&(t=n);let i=document.createElement("label");return i.setAttribute("for",e.id),i.classList.add("buckaroo-required"),i.style.color="red",i.style.width="100%",i.innerHTML=t,i}_validateOnSubmit(){let e=!0;this._handleRequired();let t=document.querySelectorAll(".radio-group-required");for(const n of t)e=e&&this.radioGroupHasRequired(n);for(const t of this.buckarooMobileInputs){document.getElementById(t)&&(e=e&&!this._CheckValidate())}for(const t of this.buckarooDoBInputs){document.getElementById(t)&&(e=e&&!this._CheckValidate())}document.$emitter.publish("buckaroo_payment_validate",{valid:e,type:"general"})}_listenToSubmit(){document.$emitter.subscribe("buckaroo_payment_submit",this._validateOnSubmit.bind(this))}}class a extends i.Z{init(){try{this._registerCheckoutSubmitButton(),this._toggleApplePay(),this._getActivePayByBankLogo()}catch(e){console.log("init error",e)}}_getActivePayByBankLogo(){let e=document.querySelector(".bk-paybybank .payment-method-image"),t=document.querySelector(".bk-paybybank-active-logo");e&&t&&t.value&&t.value.length>0&&(e.src=t.value)}_toggleApplePay(){const e=document.querySelector(".payment-method.bk-applepay");if(e){const t=async function(e){return"ApplePaySession"in window?void 0===ApplePaySession?Promise.resolve(!1):await ApplePaySession.canMakePaymentsWithActiveCard(e):Promise.resolve(!1)};(async function(){const n=document.getElementById("bk-apple-merchant-id");if(n&&n.value.length>0){const i=await t(n);e.style.display=i?"block":"none"}})().catch()}}_registerCheckoutSubmitButton(){const e=document.getElementById("confirmOrderForm");if(e){e.querySelector('[type="submit"]').addEventListener("click",this._handleCheckoutSubmit.bind(this))}}_handleCheckoutSubmit(e){e.preventDefault(),document.$emitter.unsubscribe("buckaroo_payment_validate"),this._listenToValidation(),document.$emitter.publish("buckaroo_payment_submit")}_listenToValidation(){let e={general:this._deferred(),credicard:this._deferred()};document.$emitter.subscribe("buckaroo_payment_validate",(function(t){t.detail.type&&e[t.detail.type]&&e[t.detail.type].resolve(t.detail.valid)})),Promise.all([e.general,e.credicard]).then((function([e,t]){if(void 0===document.forms.confirmOrderForm||!document.forms.confirmOrderForm.reportValidity())return;e&&t?(void 0!==window.buckaroo_back_link&&window.history.pushState(null,null,buckaroo_back_link),window.isApplePay||document.forms.confirmOrderForm.submit()):document.getElementById("changePaymentForm").scrollIntoView()}))}_deferred(){let e,t;const n=new Promise(((n,i)=>{[e,t]=[n,i]}));return n.resolve=e,n.reject=t,n}}var s=n(8254),c=n(207);function l(e,t,n){return(t=function(e){var t=function(e,t){if("object"!=typeof e||null===e)return e;var n=e[Symbol.toPrimitive];if(void 0!==n){var i=n.call(e,t||"default");if("object"!=typeof i)return i;throw new TypeError("@@toPrimitive must return a primitive value.")}return("string"===t?String:Number)(e)}(e,"string");return"symbol"==typeof t?t:String(t)}(t))in e?Object.defineProperty(e,t,{value:n,enumerable:!0,configurable:!0,writable:!0}):e[t]=n,e}class d extends i.Z{constructor(...e){super(...e),l(this,"httpClient",new s.Z),l(this,"url","/buckaroo"),l(this,"sdk",void 0),l(this,"result",null),l(this,"cartToken",void 0),l(this,"sdkOptions",{containerSelector:".buckaroo-paypal-express",buckarooWebsiteKey:this.options.websiteKey,paypalMerchantId:this.options.merchantId,currency:"EUR",amount:.1,createPaymentHandler:this.createPaymentHandler.bind(this),onShippingChangeHandler:this.onShippingChangeHandler.bind(this),onSuccessCallback:this.onSuccessCallback.bind(this),onErrorCallback:this.onErrorCallback.bind(this),onCancelCallback:this.onCancelCallback.bind(this),onClickCallback:this.onClickCallback.bind(this)}),l(this,"form",void 0)}init(){null===this.merchantId&&alert("Merchant id is required"),document.$emitter.subscribe("buckaroo_scripts_loaded",(()=>{this.sdk=BuckarooSdk.PayPal,this.sdk.initiate(this.sdkOptions)}))}onShippingChangeHandler(e,t){return this.setShipping(e).then((e=>{if(!1===e.error)return this.cartToken=e.token,this.sdkOptions.amount=e.cart.value,t.order.patch([{op:"replace",path:"/purchase_units/@reference_id=='default'/amount",value:e.cart}]);this.displayErrorMessage(e.message),t.reject(e.message)}))}createPaymentHandler(e){return this.createTransaction(e.orderID)}onSuccessCallback(){!0===this.result.error?this.displayErrorMessage(message):this.result.redirect?window.location=this.result.redirect:this.displayErrorMessage(this.options.i18n.cannot_create_payment)}onErrorCallback(e){this.displayErrorMessage(e)}onCancelCallback(){this.displayErrorMessage(this.options.i18n.cancel_error_message)}onClickCallback(){this.result=null}createTransaction(e){let t={orderId:e};return this.cartToken&&(t.cartToken=this.cartToken),new Promise((e=>{this.httpClient.post(`${this.url}/paypal/pay`,JSON.stringify(t),(t=>{this.result=JSON.parse(t),e(JSON.parse(t))}))}))}setShipping(e){let t=null;return"product"===this.options.page&&(t=c.Z.serializeJson(this.el.closest("form"))),new Promise((n=>{this.httpClient.post(`${this.url}/paypal/create`,JSON.stringify({form:t,customer:e,page:this.options.page}),(e=>{n(JSON.parse(e))}))}))}displayErrorMessage(e){$(".buckaroo-paypal-express-error").remove(),"object"==typeof e&&(e=this.options.i18n.cannot_create_payment);const t=`\n        <div role="alert" class="alert alert-warning alert-has-icon buckaroo-paypal-express-error">\n            <span class="icon icon-warning">\n                <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="24" height="24" viewBox="0 0 24 24"><defs><path d="m13.7744 1.4124 9.7058 18.6649c.5096.98.1283 2.1875-.8517 2.6971a2 2 0 0 1-.9227.2256H2.2942c-1.1045 0-2-.8954-2-2a2 2 0 0 1 .2256-.9227l9.7058-18.665c.5096-.98 1.7171-1.3613 2.6971-.8517a2 2 0 0 1 .8517.8518zM2.2942 21h19.4116L12 2.335 2.2942 21zM12 17c.5523 0 1 .4477 1 1s-.4477 1-1 1-1-.4477-1-1 .4477-1 1-1zm1-2c0 .5523-.4477 1-1 1s-1-.4477-1-1v-5c0-.5523.4477-1 1-1s1 .4477 1 1v5z" id="icons-default-warning"></path></defs><use xlink:href="#icons-default-warning" fill="#758CA3" fill-rule="evenodd"></use></svg>\n            </span>                                    \n            <div class="alert-content-container"> \n                <div class="alert-content">\n                    ${e}\n                </div>\n                \n            </div>\n        </div>\n        `;$(".flashbags").first().prepend(t),setTimeout((function(){$(".buckaroo-paypal-express-error").fadeOut(1e3)}),1e4)}}l(d,"options",{page:"unknown",merchantId:null});const u={PayPayment:function(e){var t=this;this.applePayVersion=4,this.validationUrl="https://applepay.buckaroo.io/v1/request-session",this.abortSession=function(){t.session&&t.session.abort()},this.init=function(){null===document.getElementById("buckaroo-sdk-css")&&document.head.insertAdjacentHTML("beforeend",'<link id="buckaroo-sdk-css" href="https://checkout.buckaroo.nl/api/buckaroosdk/css" rel="stylesheet">')},this.validate=function(){if(!t.options.processCallback)throw"ApplePay: processCallback must be set";if(!t.options.storeName)throw"ApplePay: storeName is not set";if(!t.options.countryCode)throw"ApplePay: countryCode is not set";if(!t.options.currencyCode)throw"ApplePay: currencyCode is not set";if(!t.options.merchantIdentifier)throw"ApplePay: merchantIdentifier is not set"},this.beginPayment=function(){var e={countryCode:t.options.countryCode,currencyCode:t.options.currencyCode,merchantCapabilities:t.options.merchantCapabilities,supportedNetworks:t.options.supportedNetworks,lineItems:t.options.lineItems,total:t.options.totalLineItem,requiredBillingContactFields:t.options.requiredBillingContactFields,requiredShippingContactFields:t.options.requiredShippingContactFields,shippingType:t.options.shippingType,shippingMethods:t.options.shippingMethods};t.session=new ApplePaySession(t.applePayVersion,e),t.session.onvalidatemerchant=t.onValidateMerchant,t.options.shippingMethodSelectedCallback&&(t.session.onshippingmethodselected=t.onShippingMethodSelected),t.options.shippingContactSelectedCallback&&(t.session.onshippingcontactselected=t.onShippingContactSelected),t.options.cancelCallback&&(t.session.oncancel=t.onCancel),t.session.onpaymentauthorized=t.onPaymentAuthorized,t.session.begin()},this.onValidateMerchant=function(e){var n={validationUrl:e.validationURL,displayName:t.options.storeName,domainName:window.location.hostname,merchantIdentifier:t.options.merchantIdentifier};fetch(t.validationUrl,{method:"POST",body:JSON.stringify(n)}).then((e=>e.json())).then((function(e){t.session.completeMerchantValidation(e)}))},this.onPaymentAuthorized=function(e){var n=e.payment;t.options.processCallback(n).then((function(e){t.session.completePayment(e)}))},this.onShippingMethodSelected=function(e){t.options.shippingMethodSelectedCallback&&t.options.shippingMethodSelectedCallback(e.shippingMethod).then((function(e){e&&t.session.completeShippingMethodSelection(e)}))},this.onShippingContactSelected=function(e){t.options.shippingContactSelectedCallback&&t.options.shippingContactSelectedCallback(e.shippingContact).then((function(e){e&&t.session.completeShippingContactSelection(e)}))},this.onCancel=function(e){t.options.cancelCallback&&t.options.cancelCallback(e)},this.options=e,this.init(),this.validate()},PayOptions:function(e,t,n,i,o,r,a,s,c,l,d,u,p,h,m,y,b){void 0===d&&(d=null),void 0===u&&(u=null),void 0===p&&(p=["email","name","postalAddress"]),void 0===h&&(h=["email","name","postalAddress"]),void 0===m&&(m=null),void 0===y&&(y=["supports3DS","supportsCredit","supportsDebit"]),void 0===b&&(b=["masterCard","visa","maestro","vPay","cartesBancaires","privateLabel"]),this.storeName=e,this.countryCode=t,this.currencyCode=n,this.cultureCode=i,this.merchantIdentifier=o,this.lineItems=r,this.totalLineItem=a,this.shippingType=s,this.shippingMethods=c,this.processCallback=l,this.shippingMethodSelectedCallback=d,this.shippingContactSelectedCallback=u,this.requiredBillingContactFields=p,this.requiredShippingContactFields=h,this.cancelCallback=m,this.merchantCapabilities=y,this.supportedNetworks=b},checkPaySupport:async function(e){return"ApplePaySession"in window?void 0===ApplePaySession?Promise.resolve(!1):await ApplePaySession.canMakePaymentsWithActiveCard(e):Promise.resolve(!1)},getButtonClass:function(e,t){void 0===e&&(e="black"),void 0===t&&(t="plain");let n=["apple-pay","apple-pay-button"];switch(t){case"plain":n.push("apple-pay-button-type-plain");break;case"book":n.push("apple-pay-button-type-book");break;case"buy":n.push("apple-pay-button-type-buy");break;case"check-out":n.push("apple-pay-button-type-check-out");break;case"donate":n.push("apple-pay-button-type-donate");break;case"set-up":n.push("apple-pay-button-type-set-up");break;case"subscribe":n.push("apple-pay-button-type-subscribe")}switch(e){case"black":n.push("apple-pay-button-black");break;case"white":n.push("apple-pay-button-white");break;case"white-outline":n.push("apple-pay-button-white-with-line")}return n.join(" ")}};function p(e,t,n){return(t=function(e){var t=function(e,t){if("object"!=typeof e||null===e)return e;var n=e[Symbol.toPrimitive];if(void 0!==n){var i=n.call(e,t||"default");if("object"!=typeof i)return i;throw new TypeError("@@toPrimitive must return a primitive value.")}return("string"===t?String:Number)(e)}(e,"string");return"symbol"==typeof t?t:String(t)}(t))in e?Object.defineProperty(e,t,{value:n,enumerable:!0,configurable:!0,writable:!0}):e[t]=n,e}class h extends i.Z{constructor(...e){super(...e),p(this,"httpClient",new s.Z),p(this,"url","/buckaroo"),p(this,"sdk",void 0),p(this,"result",null),p(this,"cartToken",void 0)}init(){null===this.merchantId&&alert("Apple Pay Merchant id is required"),document.$emitter.subscribe("buckaroo_scripts_jquery_loaded",(()=>{$("#confirmFormSubmit").prop("disabled",!0),this.checkIsAvailable().then((e=>{$("#confirmFormSubmit").prop("disabled",!e),e&&this.renderButton()}))}))}renderButton(){"checkout"!==this.options.page?$(".bk-apple-pay-button").addClass(u.getButtonClass()).attr("lang",this.options.cultureCode).on("click",this.initPayment.bind(this)):(window.isApplePay=!0,$("#confirmFormSubmit").on("click",this.initPayment.bind(this)))}initPayment(e){e.preventDefault(),this.retrieveCartData().then((e=>{this.initApplePayment(e)}))}retrieveCartData(){let e=null;return"product"===this.options.page&&(e=c.Z.serializeJson(this.el.closest("form"))),new Promise(((t,n)=>{this.httpClient.post(`${this.url}/apple/cart/get`,JSON.stringify({form:e,page:this.options.page}),(e=>{let i=JSON.parse(e);i.error?(this.displayErrorMessage(i.message),n(i.message)):(this.cartToken=i.cartToken,t(i))}))}))}initApplePayment(e){const t=this,n=new u.PayOptions(e.storeName,e.country,e.currency,t.options.cultureCode,t.options.merchantId,e.lineItems,e.totals,"shipping",t.isCheckout(e.shippingMethods,[]),t.captureFunds,t.isCheckout(t.updateCart,null),t.isCheckout(t.updateCart,null));u.PayPayment(n),u.beginPayment()}isCheckout(e,t){return"checkout"===this.options.page?t:e}captureFunds(e){return new Promise((t=>{this.httpClient.post(`${this.url}/apple/order/create`,JSON.stringify({payment:JSON.stringify(e),cartToken:this.cartToken,page:this.options.page}),(e=>{const n=JSON.parse(e);if(n.redirect)t({status:ApplePaySession.STATUS_SUCCESS,errors:[]}),window.location=n.redirect;else{let e=this.options.i18n.cannot_create_payment;n.message&&(e=n.message),this.displayErrorMessage(e),t({status:ApplePaySession.STATUS_FAILURE,errors:[e]})}}))}))}updateCart(e){let t={cartToken:this.cartToken};return void 0!==e.identifier&&(t={...t,shippingMethod:e.identifier}),void 0!==e.countryCode&&(t={...t,shippingContact:e}),new Promise((e=>{this.httpClient.post(`${this.url}/apple/cart/update`,JSON.stringify(t),(t=>{const n=JSON.parse(t);let i=ApplePaySession.STATUS_SUCCESS;n.error&&(i=ApplePaySession.STATUS_FAILURE,this.displayErrorMessage(n.message),console.warn(n.message)),e({status:i,...n})}))}))}checkIsAvailable(){return u.checkPaySupport(this.options.merchantId)}displayErrorMessage(e){$(".buckaroo-apple-error").remove(),"object"==typeof e&&(e=this.options.i18n.cannot_create_payment);const t=`\n    <div role="alert" class="alert alert-warning alert-has-icon buckaroo-apple-error">\n        <span class="icon icon-warning">\n            <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="24" height="24" viewBox="0 0 24 24"><defs><path d="m13.7744 1.4124 9.7058 18.6649c.5096.98.1283 2.1875-.8517 2.6971a2 2 0 0 1-.9227.2256H2.2942c-1.1045 0-2-.8954-2-2a2 2 0 0 1 .2256-.9227l9.7058-18.665c.5096-.98 1.7171-1.3613 2.6971-.8517a2 2 0 0 1 .8517.8518zM2.2942 21h19.4116L12 2.335 2.2942 21zM12 17c.5523 0 1 .4477 1 1s-.4477 1-1 1-1-.4477-1-1 .4477-1 1-1zm1-2c0 .5523-.4477 1-1 1s-1-.4477-1-1v-5c0-.5523.4477-1 1-1s1 .4477 1 1v5z" id="icons-default-warning"></path></defs><use xlink:href="#icons-default-warning" fill="#758CA3" fill-rule="evenodd"></use></svg>\n        </span>                                    \n        <div class="alert-content-container"> \n            <div class="alert-content">\n                ${e}\n            </div>\n            \n        </div>\n    </div>\n\n  `;$(".flashbags").first().prepend(t),setTimeout((function(){$(".buckaroo-apple-error").fadeOut(1e3)}),1e4)}}p(h,"options",{page:"unknown",merchantId:null,cultureCode:"nl-NL"});class m extends i.Z{loadSdk(){return new Promise((e=>{var t=document.createElement("script");t.src="https://checkout.buckaroo.nl/api/buckaroosdk/script/en-US",t.async=!0,document.head.appendChild(t),t.onload=()=>{e()}}))}loadJquery(){return"undefined"==typeof jQuery||void 0===jQuery.ajax?new Promise((e=>{var t=document.createElement("script");t.src="https://code.jquery.com/jquery-3.2.1.min.js",t.async=!0,document.head.appendChild(t),t.onload=()=>{e()}})):Promise.resolve()}init(){this.loadJquery().then((()=>{document.$emitter.publish("buckaroo_scripts_jquery_loaded",{loaded:!0}),this.loadSdk().then((()=>{document.$emitter.publish("buckaroo_scripts_loaded",{loaded:!0})}))}))}}function y(e,t,n){return(t=function(e){var t=function(e,t){if("object"!=typeof e||null===e)return e;var n=e[Symbol.toPrimitive];if(void 0!==n){var i=n.call(e,t||"default");if("object"!=typeof i)return i;throw new TypeError("@@toPrimitive must return a primitive value.")}return("string"===t?String:Number)(e)}(e,"string");return"symbol"==typeof t?t:String(t)}(t))in e?Object.defineProperty(e,t,{value:n,enumerable:!0,configurable:!0,writable:!0}):e[t]=n,e}class b extends i.Z{constructor(...e){super(...e),y(this,"httpClient",new s.Z)}init(){this.pullStatus()}pullStatus(){setInterval(this.singlePullStatus.bind(this),this.options.interval)}singlePullStatus(){this.options,this.httpClient.post(this.options.pullUrl,JSON.stringify({orderId:this.options.orderId}),(e=>{const t=JSON.parse(e);void 0!==t.redirectUrl&&(window.location.href=t.redirectUrl)}))}}function g(e,t,n){return(t=function(e){var t=function(e,t){if("object"!=typeof e||null===e)return e;var n=e[Symbol.toPrimitive];if(void 0!==n){var i=n.call(e,t||"default");if("object"!=typeof i)return i;throw new TypeError("@@toPrimitive must return a primitive value.")}return("string"===t?String:Number)(e)}(e,"string");return"symbol"==typeof t?t:String(t)}(t))in e?Object.defineProperty(e,t,{value:n,enumerable:!0,configurable:!0,writable:!0}):e[t]=n,e}y(b,"options",{orderId:null,pullUrl:null,interval:5e3});const k="bk-is-mobile",f="bk-paybybank-selected";class v extends i.Z{constructor(...e){super(...e),g(this,"httpClient",new s.Z)}init(){this.listenToIsMobile(),this.onPageLoad(),this.listenToResize(),this.listenToIssuerChange(),this.togglePayByBankList(),this.emitSavedIssuer()}emitSavedIssuer(){"string"==typeof this.options.issuerSelected&&this.options.issuerSelected.length>0&&document.$emitter.publish(f,{code:this.options.issuerSelected,source:"other"})}onPageLoad(){document.$emitter.publish(k,{isMobile:(window.innerWidth||document.documentElement.clientWidth||document.body.clientWidth)<768})}listenToResize(){window.addEventListener("resize",function(){let e=!1;(window.innerWidth||document.documentElement.clientWidth||document.body.clientWidth)<768&&(e=!0),this.isMobile!==e&&document.$emitter.publish(k,{isMobile:e})}.bind(this))}listenToIsMobile(){document.$emitter.subscribe(k,function(e){this.isMobile=e.detail.isMobile,this.toggleInputType()}.bind(this))}toggleInputType(){const e=document.querySelector(".bk-paybybank-mobile"),t=document.querySelector(".bk-paybybank-not-mobile");this.isMobile&&e&&t?(e.style.display="block",t.style.display="none"):(e.style.display="none",t.style.display="block")}togglePayByBankList(){this._elementsToShow=document.querySelectorAll(".bk-paybybank-selector .custom-radio:nth-child(n+6)"),setTimeout((()=>{const e=localStorage.getItem("confirmOrderForm.payBybankMethod");null!==e&&document.$emitter.publish(f,{code:e,source:"other"})}),300),this.toggleElements(!1),this.el.addEventListener("click",function(e){const t=document.querySelector(".bk-toggle-wrap");if(null===t)return;const n=t.querySelector(".bk-toggle-text");if(e.target===n){const e=t.querySelector(".bk-toggle"),i=e.classList.contains("bk-toggle-down");e.classList.toggle("bk-toggle-down"),e.classList.toggle("bk-toggle-up");const o=n.getAttribute("text-less"),r=n.getAttribute("text-more");n.textContent=i?o:r,this.toggleElements(i)}}.bind(this))}listenToIssuerChange(){document.$emitter.subscribe(f,function(e){this.syncInputs(e.detail)}.bind(this));document.querySelector("#payBybankMethod").addEventListener("change",(function(e){document.$emitter.publish(f,{code:e.target.value,source:"select"}),function(){const e=document.querySelector(".bk-toggle-wrap");if(null!==e){const t=e.querySelector(".bk-toggle-text"),n=e.querySelector(".bk-toggle"),i=n.classList.contains("bk-toggle-down"),o=t.getAttribute("text-more");i||(n.classList.toggle("bk-toggle-down"),n.classList.toggle("bk-toggle-up"),t.textContent=o)}}()}));document.querySelectorAll(".bk-paybybank-radio input").forEach((function(e){e.addEventListener("change",(function(e){document.$emitter.publish(f,{code:e.target.value,source:"radio"})}))}))}syncInputs(e){if(this._elementsToShow=document.querySelectorAll(`.bk-paybybank-selector .custom-radio:not(.bankMethod${e.code})`),"other"===e.source&&this.toggleElements(!1),-1!==["radio","other"].indexOf(e.source)){document.querySelector("#payBybankMethod").value=e.code}if(-1!==["select","other"].indexOf(e.source)){document.querySelectorAll(".bk-paybybank-radio").forEach((function(t){const n=t.querySelector("input");t.style.display="none",null!==n&&(n.checked=!1,n.value===e.code&&(n.checked=!0,t.style.display="block"))})),e.code&&0!==e.code.length||this.showDefaultsIfEmptyIssuer()}}showDefaultsIfEmptyIssuer(){document.querySelectorAll(".bk-paybybank-selector .custom-radio:nth-child(-n+5)").forEach((function(e){e.style.display="block"}))}toggleElements(e,t="inline"){this._elementsToShow.forEach((function(n){n.style.display=e?t:"none"}))}}g(v,"options",{issuerSelected:""});class _ extends i.Z{init(){this.initialLogo(),this.listenToIssuerChange()}listenToIssuerChange(){document.$emitter.subscribe("bk-paybybank-selected",function(e){this.updateLogo(e.detail.code)}.bind(this))}initialLogo(){this.updateLogo(this.options.issuerSelected)}updateLogo(e){if(this.options.issuerLogos[e]){let t=document.querySelector(".bk-paybybank .payment-method-image");t&&(t.src=this.options.issuerLogos[e])}}}var C,S,E;C=_,E={issuerSelected:"",issuerLogos:[]},(S=function(e){var t=function(e,t){if("object"!=typeof e||null===e)return e;var n=e[Symbol.toPrimitive];if(void 0!==n){var i=n.call(e,t||"default");if("object"!=typeof i)return i;throw new TypeError("@@toPrimitive must return a primitive value.")}return("string"===t?String:Number)(e)}(e,"string");return"symbol"==typeof t?t:String(t)}(S="options"))in C?Object.defineProperty(C,S,{value:E,enumerable:!0,configurable:!0,writable:!0}):C[S]=E;class w extends i.Z{init(){this._listenToSubmit(),this._createScript((()=>{const e=["bancontactmrcash_cardholdername","bancontactmrcash_cardnumber","bancontactmrcash_expirationmonth","bancontactmrcash_expirationyear"];for(const t of e){const e=document.getElementById(t);e&&e.addEventListener("change",this._handleInputChanged.bind(this))}this._getEncryptedData()}))}_createScript(e){const t=document.createElement("script");t.type="text/javascript",t.src="https://static.buckaroo.nl/script/ClientSideEncryption001.js",t.addEventListener("load",e.bind(this),!1),document.head.appendChild(t)}_getEncryptedData(){const e=document.getElementById("bancontactmrcash_cardnumber"),t=document.getElementById("bancontactmrcash_expirationyear"),n=document.getElementById("bancontactmrcash_expirationmonth"),i=document.getElementById("bancontactmrcash_cardholdername");var o,r,a,s;e&&t&&n&&i&&(o=e.value,r=t.value,a=n.value,s=i.value,window.BuckarooClientSideEncryption.V001.encryptCardData(o,r,a,"",s,(function(e){const t=document.getElementById("encryptedCardData");t&&(t.value=e)})))}_handleInputChanged(e){this._CheckValidate(),this._getEncryptedData()}_handleCheckField(e){switch(document.getElementById(e.id+"Error").style.display="none",e.id){case"bancontactmrcash_cardnumber":if(!window.BuckarooClientSideEncryption.V001.validateCardNumber(e.value.replace(/\s+/g,"")))return document.getElementById(e.id+"Error").style.display="block",!1;break;case"bancontactmrcash_cardholdername":if(!window.BuckarooClientSideEncryption.V001.validateCardholderName(e.value))return document.getElementById(e.id+"Error").style.display="block",!1;break;case"bancontactmrcash_expirationmonth":if(!window.BuckarooClientSideEncryption.V001.validateMonth(e.value))return document.getElementById(e.id+"Error").style.display="block",!1;break;case"bancontactmrcash_expirationyear":if(!window.BuckarooClientSideEncryption.V001.validateYear(e.value))return document.getElementById(e.id+"Error").style.display="block",!1}return!0}_CheckValidate(){let e=!1;const t=["bancontactmrcash_cardholdername","bancontactmrcash_cardnumber","bancontactmrcash_expirationmonth","bancontactmrcash_expirationyear"];for(const n of t){const t=document.getElementById(n);t&&(this._handleCheckField(t)||(e=!0))}return this._disableConfirmFormSubmit(e)}_disableConfirmFormSubmit(e){return document.getElementById("confirmFormSubmit")&&(document.getElementById("confirmFormSubmit").disabled=e),e}_validateOnSubmit(e){e.preventDefault();let t=!this._CheckValidate();document.$emitter.publish("buckaroo_payment_validate",{valid:t,type:"bancontactmrcash"})}_listenToSubmit(){document.$emitter.subscribe("buckaroo_payment_submit",this._validateOnSubmit.bind(this))}}const I=window.PluginManager;I.register("BuckarooPaymentValidateSubmit",a),I.register("BuckarooPaymentCreditcards",o),I.register("BuckarooPaymentHelper",r),I.register("PaypalExpressPlugin",d,"[data-paypal-express]"),I.register("BuckarooIdealQrPlugin",b,"[data-ideal-qr]"),I.register("BuckarooApplePayPlugin",h,"[data-bk-applepay]"),I.register("BuckarooLoadScripts",m),I.register("BuckarooBanContact",w),I.register("BuckarooPayByBankSelect",v,"[data-bk-select]"),I.register("BuckarooPayByBankLogo",_,"[data-bk-paybybank-logo]")}},e=>{e.O(0,["vendor-node","vendor-shared"],(()=>{return t=2263,e(e.s=t);var t}));e.O()}]);