import * as convert  from './helpers/convert.js';
import Shopware      from './shopware.js';

export default class ApplePay {
  constructor() {
    this.shopware = new Shopware;
    this.store_info = this.shopware.getStoreInformation();
    this.selected_shipping_method = null;
    this.selected_shipping_amount = null;
    this.total_price = null;
    this.country_id = this.store_info.country_code;
    this.is_downloadable = 0; //ZAK // document.getElementById('is_downloadable').value;
  }

  rebuild() {
    $('.applepay-button-container div').remove();
    $('.applepay-button-container').append('<div>');
  }

  init() {
    console.log("====applepay====9", this.store_info.merchant_id);

    BuckarooSdk.ApplePay
      .checkApplePaySupport(this.store_info.merchant_id)
      .then((is_applepay_supported) => {
        //ZAK
        is_applepay_supported = true;

        console.log("====applepay====10", is_applepay_supported);
        if (is_applepay_supported && location.protocol === 'https:') {
          console.log("====applepay====11");

          if (document.getElementById('confirmFormSubmit')) {
            document.getElementById('confirmFormSubmit').disabled = false;
          }

          var result = this.shopware.makeRequest('/Buckaroo/applepayInit', 'POST', { country_code: 1 });

          console.log("====applepay====12");

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
              this.processApplepayCallback.bind(this),
              null, null,
              //this.processShippingMethodsCallback.bind(this),
              //this.processChangeContactInfoCallback.bind(this)
              ["postalAddress"], ["postalAddress"]
          );
          const applepay_payment = new BuckarooSdk.ApplePay.ApplePayPayment(
              ".applepay-button-container div",
              applepay_options
          );

          console.log("====applepay====14");

          applepay_payment.showPayButton("black");

        } else {
          alert('ApplePay is not available!')
        }
      })
    ;
  }

  processChangeContactInfoCallback(contact_info) {
    console.log("====applepay====17");

    this.country_id = contact_info.countryCode

    const cart_items = this.getItems();
    const shipping_methods = this.shopware.getShippingMethods(this.country_id);
    const first_shipping_item = this.getFirstShippingItem(shipping_methods);

    const all_items = first_shipping_item !== null 
      ? [].concat(cart_items, first_shipping_item) 
      : cart_items;

    const total_to_pay = this.sumTotalAmount(all_items);
    
    const total_item = {
      label: "Totaal",
      amount: total_to_pay,
      type: 'final'
    };
    
    const info = {
      newShippingMethods: shipping_methods,
      newTotal: total_item,
      newLineItems: all_items
    }

    if (shipping_methods.length > 0) {
      var errors = {};
      this.selected_shipping_method = shipping_methods[0].identifier;
      this.selected_shipping_amount = shipping_methods[0].amount;
    } 
    else {
      var errors = this.shippingCountryError(contact_info);
    }
    
    this.total_price = total_to_pay;

    return Promise.resolve(
      Object.assign(info, errors)
    );
  }

  processShippingMethodsCallback(selected_method) {
    console.log("====applepay====16");

    const cart_items = this.getItems();
    const shipping_item = {
      type: 'final',
      label: selected_method.label,
      amount: convert.toDecimal(selected_method.amount) || 0,
      qty: 1
    };

    const all_items = [].concat(cart_items, shipping_item);
    const total_to_pay = this.sumTotalAmount(all_items);
    
    const total_item = {
      label: "Totaal",
      amount: total_to_pay,
      type: 'final'
    }

    this.selected_shipping_method = selected_method.identifier;
    this.selected_shipping_amount = selected_method.amount;
    this.total_price = total_to_pay;

    return Promise.resolve({
      status: ApplePaySession.STATUS_SUCCESS,
      newTotal: total_item,
      newLineItems: all_items
    });
  }

  processApplepayCallback(payment) {
    console.log("====applepay====15");
    console.log(payment);


    const authorization_result = {
      status: ApplePaySession.STATUS_SUCCESS,
      errors: []
    }

    if (authorization_result.status === ApplePaySession.STATUS_SUCCESS) {
      console.log("====applepay====20");

      if (payment) {
        console.log("====applepay====31");
        if (document.getElementById('applePayInfo')) {
          console.log("====applepay====32");
          document.getElementById('applePayInfo').value = JSON.stringify(payment);
          if (document.getElementById('confirmFormSubmit')) {
            console.log("====applepay====33");
            window.buckaroo.submit = true;
            document.getElementById('confirmFormSubmit').click();
          }
        }
      }
    }
    else {
      console.log("====applepay====21");
      const errors = authorization_result.errors.map((error) => { 
        return error.message; 
      }).join(" ");

      this.shopware.displayErrorMessage(
        `Your payment could not be processed. ${errors}`
      );
    }

    return Promise.resolve(authorization_result);
  }

  timeoutRedirect(url = false) {
    console.log("====applepay====timeoutRedirect", url);
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

  getFirstShippingItem(shipping_methods) {
    if(this.is_downloadable === '1'){
      return {
        type: 'final',
        label: 'No shipping fee',
        amount:  0,
        qty: 1
      };
    }
    if (shipping_methods.length > 0) {
      return {
        type: 'final',
        label: shipping_methods[0].label,
        amount: shipping_methods[0].amount || 0,
        qty: 1
      };
    }
     return null;
  }

  getItems() {
    return this.shopware.getItems(this.country_id)
      .map((item) => {
        const label = `${item.qty} x ${item.name}`;
        return {
          type: 'final',
          label: convert.maxCharacters(label, 25),
          amount: convert.toDecimal(item.price * item.qty),
          qty: item.qty
        };
      })
    ;
  }

  shippingCountryError(contact_info) {
    return { 
      errors: [new ApplePayError(
        "shippingContactInvalid",
        "country", 
        "Shipping is not available for the selected country"
      )] 
    };
  }
}