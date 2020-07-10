export default class ApplePay {
  constructor() {
    this.store_info = this.makeRequest('/Buckaroo/getShopInformation');
    this.selected_shipping_method = null;
    this.total_price = null;
    this.country_id = this.store_info.country_code;
    this.is_downloadable = document.getElementById('is_downloadable') ? document.getElementById('is_downloadable').value : '';
  }

  rebuild() {
    jQuery('.applepay-button-container div').remove();
    jQuery('.applepay-button-container').append('<div>');
  }

  init() {
    this.log('9', this.store_info.merchant_id);
    this.mode =  '';
    this.data =  {};
    BuckarooSdk.ApplePay
      .checkApplePaySupport(this.store_info.merchant_id)
      .then((is_applepay_supported) => {
        //is_applepay_supported = true; //ZAK
        this.log('10', is_applepay_supported);
        if (is_applepay_supported && location.protocol === 'https:') {

          if (document.getElementById('confirmFormSubmit')) {
            //checkout
            this.mode = 'checkout';
            document.getElementById('confirmFormSubmit').disabled = false;
          } else {
            if (
                document.querySelector('[itemprop~="productID"]')
                &&
                document.querySelector('[itemprop~="productID"]').getAttribute('content')
            ) {
              //product
              const productId = document.querySelector('[itemprop~="productID"]').getAttribute('content');
              this.log('11', productId);
              this.mode = 'product';

              let qty = 1;
              let productElement = document.querySelector('.product-detail-quantity-select');
              if (productElement) {
                qty = productElement.value;
              }

              this.data = {
                product_id: productId,
                qty: qty,
                country_code: this.country_id
              }

            } else {
              //cart
              this.log('12');
              this.mode = 'cart';
              this.data = {
                country_code: this.country_id
              }
            }
          }

          let requiredBillingContactFields = undefined;
          let requiredShippingContactFields = undefined;

          if (this.mode == 'checkout') {
            requiredBillingContactFields = ["postalAddress"];
            requiredShippingContactFields = [];
          }

          let result = this.makeRequest(
              (this.mode == 'checkout') ? '/Buckaroo/applepayInit' : '/Buckaroo/applepayInitNonCheckout',
              'POST',
              this.data
          );
          this.log('13');

          if (result.shippingMethods.length > 0) {
            this.selected_shipping_method = result.shippingMethods[0].identifier;
            //this.selected_shipping_amount = result.shippingMethods[0].amount;
          }

          const applepay_options = new BuckarooSdk.ApplePay.ApplePayOptions(
              result.storeName,
              result.countryCode,
              result.currencyCode,
              result.cultureCode,
              this.store_info.merchant_id,
              result.lineItems,
              result.totalLineItems,
              result.shippingType,
              this.mode == 'checkout' ? [] : result.shippingMethods,
              this.captureFunds.bind(this),
              this.mode == 'checkout' ? null : this.processShippingMethodsCallback.bind(this),
              this.mode == 'checkout' ? null : this.processChangeContactInfoCallback.bind(this),
              requiredBillingContactFields,
              requiredShippingContactFields
          );
          const applepay_payment = new BuckarooSdk.ApplePay.ApplePayPayment(
              ".applepay-button-container div",
              applepay_options
          );

          this.log('14');

          applepay_payment.showPayButton("black");

        } else {
          //alert('ApplePay is not available!')
        }
    });
  }

  processChangeContactInfoCallback(contact_info) {
    this.log('21');

    this.data.country_code = contact_info.countryCode;

    this.log('22', contact_info.countryCode);

    let result = this.makeRequest(
        '/Buckaroo/applepayInitNonCheckout',
        'POST',
        this.data
    );
    this.log('23');

    const info = {
      newShippingMethods: result.shippingMethods,
      newTotal: result.totalLineItems,
      newLineItems: result.lineItems
    }

    if (result.shippingMethods.length > 0) {
      this.selected_shipping_method = result.shippingMethods[0].identifier;
      //this.selected_shipping_amount = result.shippingMethods[0].amount;
      var errors = {};
    }
    else {
      var errors = this.shippingCountryError(contact_info);
    }

    return Promise.resolve(
        Object.assign(info, errors)
    );

  }

  processShippingMethodsCallback(selected_method) {
    this.log('51', selected_method);

    this.selected_shipping_method = selected_method.identifier;
    this.data.selected_shipping_method = selected_method.identifier;

    let result = this.makeRequest(
        '/Buckaroo/applepayInitNonCheckout',
        'POST',
        this.data
    );
    this.log('53');

    const info = {
      newShippingMethods: result.shippingMethods,
      newTotal: result.totalLineItems,
      newLineItems: result.lineItems
    }

    if (result.shippingMethods.length > 0) {
      var errors = {};
    }
    else {
      var errors = this.shippingCountryError(contact_info);
    }

    return Promise.resolve(
        Object.assign(info, errors)
    );
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
      if (this.mode == 'checkout') {
        this.log('32');
        if (document.getElementById('applePayInfo')) {
          document.getElementById('applePayInfo').value = JSON.stringify(payment);
          if (document.getElementById('confirmFormSubmit')) {
            this.log('33');
            window.buckaroo.submit = true;
            document.getElementById('confirmFormSubmit').click();
            return Promise.resolve(authorizationSuccessResult);
          }
        }
      } else {
        this.log('41', [this.selected_shipping_method]);

        let result = this.makeRequest(
            '/Buckaroo/applepaySaveOrder',
            'POST',
            {
              items: this.mode == 'cart' ? '' : [this.data],
              selected_shipping_method: this.selected_shipping_method,
              //selected_shipping_amount: this.selected_shipping_amount,
              paymentData: payment
            }
        );
        this.log('12', result);
        //return Promise.resolve(authorizationSuccessResult);
        if (result && result.hasOwnProperty('redirectURL')) {
          this.timeoutRedirect(result.redirectURL);
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
    this.log('makeRequest1', [url, method, data]);

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