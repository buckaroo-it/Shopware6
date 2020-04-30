export default class ApplePay {
  constructor() {
    this.store_info = this.makeRequest('/Buckaroo/getShopInformation');
  }

  init() {
    this.log('9', this.store_info.merchant_id);

    BuckarooSdk.ApplePay
      .checkApplePaySupport(this.store_info.merchant_id)
      .then((is_applepay_supported) => {
        //is_applepay_supported = true;
        this.log('10', is_applepay_supported);
        if (is_applepay_supported && location.protocol === 'https:') {

          if (document.getElementById('confirmFormSubmit')) {
            document.getElementById('confirmFormSubmit').disabled = false;
          }

          var result = this.makeRequest('/Buckaroo/applepayInit', 'POST', { stub: 1 });
          this.log('12');

          const applepay_options = new BuckarooSdk.ApplePay.ApplePayOptions(
              result.storeName,
              result.countryCode,
              result.currencyCode,
              result.cultureCode,
              this.store_info.merchant_id,
              result.lineItems,
              result.totalLineItems,
              result.shippingType,
              result.shippingMethods,
              this.captureFunds.bind(this),
              null, null,
              //this.processShippingMethodsCallback.bind(this),
              //this.processChangeContactInfoCallback.bind(this)
              ["postalAddress"], []
          );
          const applepay_payment = new BuckarooSdk.ApplePay.ApplePayPayment(
              ".applepay-button-container div",
              applepay_options
          );

          this.log('14');

          applepay_payment.showPayButton("black");

        } else {
          alert('ApplePay is not available!')
        }
    });
  }

  captureFunds(payment) {
    this.log('15', payment);

    var authorizationSuccessResult = {
      status: ApplePaySession.STATUS_SUCCESS,
      errors: []
    };

    var authorizationFailedResult = {
      status: ApplePaySession.STATUS_FAILURE,
      errors: []
    };

    if (payment) {
      this.log('31');
      if (document.getElementById('applePayInfo')) {
        document.getElementById('applePayInfo').value = JSON.stringify(payment);
        if (document.getElementById('confirmFormSubmit')) {
          this.log('33');
          window.buckaroo.submit = true;
          document.getElementById('confirmFormSubmit').click();
          return Promise.resolve(authorizationSuccessResult);
        }
      }
    }

    return Promise.resolve(authorizationFailedResult);

  }

  getStoreInformation() {
    var result = this.makeRequest('/Buckaroo/getShopInformation');
    return result;
  }

  makeRequest(url, method = 'GET', data = false) {
    this.log('makeRequest1', [url, method]);

    var information = [];
    var xhr = new XMLHttpRequest();
    xhr.open(method, url, false);
    if (method == 'POST') {

      xhr.setRequestHeader("Content-Type", "application/json");
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.setRequestHeader('sw-access-key', window.accessKey);
      xhr.setRequestHeader('sw-context-token', window.contextToken);

      if (window.csrf.enabled && window.buckaroo && window.buckaroo.csrf) {
        data['_csrf_token'] = window.buckaroo.csrf[url];
      }
      xhr.send(JSON.stringify(data));
    } else {
      xhr.send();
    }

    this.log('makeRequest2', xhr.status);

    if (xhr.status === 200) {
      if (xhr.responseText.length > 0) {
        try {
          information = JSON.parse(xhr.responseText);
          this.log('makeRequest3', information);
          return information;
        } catch (e) {
        }
      }
    }
    this.log('makeRequest4');
    return information;
  }

  timeoutRedirect(url = false) {
    this.log('timeoutRedirect', url);
    /** Set Timeout to prevent Safari from crashing and reload window to show error in Magento. */
    setTimeout(
        function() {
          if (url) {
            window.location.href = url;
          } else {
            window.location.reload();
          }
        }, 1500
    )
  }

  log(id, variable) {
    //ZAK
    console.log("====applepay====" + id);
    if (variable !== undefined) {
      console.log(variable);
    }

  }

}