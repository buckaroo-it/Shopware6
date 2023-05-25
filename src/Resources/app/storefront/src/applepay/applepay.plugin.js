import HttpClient from "src/service/http-client.service";
import Plugin from "src/plugin-system/plugin.class";
import FormSerializeUtil from "src/utility/form/form-serialize.util";
import ApplePay from "./sdk";

export default class ApplePayPlugin extends Plugin {
  static options = {
    page: "unknown",
    merchantId: null,
    cultureCode: "nl-NL",
  };
  httpClient = new HttpClient();

  url = "/buckaroo";
  /**
   * buckaroo sdk
   */
  sdk;

  result = null;

  cartToken;

  init() {
    if (this.merchantId === null) {
      alert("Apple Pay Merchant id is required");
    }

    document.$emitter.subscribe("buckaroo_scripts_jquery_loaded", () => {
      $("#confirmFormSubmit").prop("disabled", true);
      this.checkIsAvailable().then((available) => {
        $("#confirmFormSubmit").prop("disabled", !available);
        if (available) {
          this.renderButton();
        }
      });
    });
  }

  /**
   * Render the pay button if not on the checkout page
   */
  renderButton() {
    if (this.options.page !== "checkout") {
      $(".bk-apple-pay-button")
        .addClass(ApplePay.getButtonClass())
        .attr("lang", this.options.cultureCode)
        .on("click", this.initPayment.bind(this));
    } else {
      window.isApplePay = true;
      $("#confirmFormSubmit").on("click", this.initPayment.bind(this));
    }
  }
  /**
   * Start the payment process
   */
  initPayment(e) {
    e.preventDefault();

    this.retrieveCartData().then((data) => {
      this.initApplePayment(data);
    });
  }

  /**
   * Retire cart data required by Apple
   * @returns Promise
   */
  retrieveCartData() {
    let formData = null;

    if (this.options.page === "product") {
      formData = FormSerializeUtil.serializeJson(this.el.closest("form"));
    }

    return new Promise((resolve, reject) => {
      this.httpClient.post(
        `${this.url}/apple/cart/get`,
        JSON.stringify({
          form: formData,
          page: this.options.page,
        }),
        (response) => {
          let resp = JSON.parse(response);

          if(resp.error) {
            this.displayErrorMessage(resp.message);
            reject(resp.message);
          } else {
            this.cartToken = resp.cartToken;
            resolve(resp);
          }
          
        }
      );
    });
  }

  /**
   * Start the payment process
   * @param {*} cart
   */
  initApplePayment(cart) {
    const self = this;
    const options = new ApplePay.PayOptions(
      cart.storeName,
      cart.country,
      cart.currency,
      self.options.cultureCode,
      self.options.merchantId,
      cart.lineItems,
      cart.totals,
      "shipping",
      self.isCheckout(cart.shippingMethods, []),
      self.captureFunds,
      self.isCheckout(self.updateCart, null),
      self.isCheckout(self.updateCart, null),
    );

    ApplePay.PayPayment(options);

    ApplePay.beginPayment();
  }

  /**
   * Check if the page is checkout and return the correct action
   */
  isCheckout(noTinCheckout, inCheckout) {
    if (this.options.page === "checkout") {
      return inCheckout;
    }
    return noTinCheckout;
  }

  /**
   * Create the sw6 order with the payment data
   * @param {*} payment
   */
  captureFunds(payment) {
    return new Promise((resolve) => {
      this.httpClient.post(
        `${this.url}/apple/order/create`,
        JSON.stringify({
          payment: JSON.stringify(payment),
          cartToken: this.cartToken,
          page: this.options.page
        }),
        (response) => {
          const resp = JSON.parse(response);
          if (resp.redirect) {
            resolve({
              status: ApplePaySession.STATUS_SUCCESS,
              errors: [],
            });
            window.location = resp.redirect;
          } else {

            let message = this.options.i18n.cannot_create_payment;
            if(resp.message) {
              message = resp.message;
            }
            this.displayErrorMessage(message);
            resolve({
              status: ApplePaySession.STATUS_FAILURE,
              errors: [message],
            });
          }
        }
      );
    });
  }

  /**
   * Update cart with the data received from apple pay
   * @param {*} data
   * @returns Promise
   */
  updateCart(data) {
    let request = {
      cartToken: this.cartToken,
    };

    //request body for changing shipping address
    if (data.identifier !== undefined) {
      request = {
        ...request,
        shippingMethod: data.identifier,
      };
    }

    // request body for setting the user
    if (data.countryCode !== undefined) {
      request = {
        ...request,
        shippingContact: data,
      };
    }

    return new Promise((resolve) => {
      this.httpClient.post(
        `${this.url}/apple/cart/update`,
        JSON.stringify(request),
        (response) => {
          const resp = JSON.parse(response);

          let status = ApplePaySession.STATUS_SUCCESS;
          if(resp.error) {
            status = ApplePaySession.STATUS_FAILURE;
            this.displayErrorMessage(resp.message);
            console.warn(resp.message);
          } 
          resolve({
            status: status,
            ...resp
          });
        }
      );
    });
  }

  /**
   * Check if apple pay is available
   * @returns Promise
   */
  checkIsAvailable() {
    return Promise.resolve(true);
    return ApplePay.checkPaySupport(this.options.merchantId);
  }

  /**
   * Display any validation errors we receive
   * @param {string} message
   */
  displayErrorMessage(message) {
    $(".buckaroo-apple-error").remove();
    if (typeof message === "object") {
      message = this.options.i18n.cannot_create_payment;
    }
    const content = `
    <div role="alert" class="alert alert-warning alert-has-icon buckaroo-apple-error">
        <span class="icon icon-warning">
            <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="24" height="24" viewBox="0 0 24 24"><defs><path d="m13.7744 1.4124 9.7058 18.6649c.5096.98.1283 2.1875-.8517 2.6971a2 2 0 0 1-.9227.2256H2.2942c-1.1045 0-2-.8954-2-2a2 2 0 0 1 .2256-.9227l9.7058-18.665c.5096-.98 1.7171-1.3613 2.6971-.8517a2 2 0 0 1 .8517.8518zM2.2942 21h19.4116L12 2.335 2.2942 21zM12 17c.5523 0 1 .4477 1 1s-.4477 1-1 1-1-.4477-1-1 .4477-1 1-1zm1-2c0 .5523-.4477 1-1 1s-1-.4477-1-1v-5c0-.5523.4477-1 1-1s1 .4477 1 1v5z" id="icons-default-warning"></path></defs><use xlink:href="#icons-default-warning" fill="#758CA3" fill-rule="evenodd"></use></svg>
        </span>                                    
        <div class="alert-content-container"> 
            <div class="alert-content">
                ${message}
            </div>
            
        </div>
    </div>

  `;
    $(".flashbags").first().prepend(content);
    setTimeout(function () {
      $(".buckaroo-apple-error").fadeOut(1000);
    }, 10000);
  }
}
