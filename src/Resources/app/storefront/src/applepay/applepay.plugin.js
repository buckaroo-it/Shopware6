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

  result = null;

  cartToken;

  cartData = null;

  payment;

  init() {
    const isCheckout = this.options.page === "checkout";

    if (isCheckout) {
      window.isApplePay = true;
      this.setConfirmButtonDisabled(true);
    }

    document.$emitter.subscribe("buckaroo_scripts_jquery_loaded", () => {
      ApplePay.loadOfficialSdk()
        .then(() => this.retrieveCartData())
        .then((cartData) => {
          this.cartData = cartData;
          return this.checkIsAvailable().then((available) => {
            if (available) {
              this.renderButton(cartData);
            } else if (isCheckout) {
              // Not available — release the block so another method can be used.
              window.isApplePay = false;
              this.setConfirmButtonDisabled(false);
            }
          });
        })
        .catch(() => {
          if (isCheckout) {
            window.isApplePay = false;
            this.setConfirmButtonDisabled(false);
          }
        });
    });
  }

  /**
   * Enable/disable the checkout confirm (Place Order) button.
   * @param {boolean} disabled
   */
  setConfirmButtonDisabled(disabled) {
    const btn = document.getElementById("confirmFormSubmit");
    if (btn) {
      btn.disabled = disabled;
    }
  }

  /**
   * Wire the checkout confirm button (standard method) or render the express
   * <apple-pay-button>. In both cases the click opens the Apple Pay sheet
   * synchronously using the pre-fetched cart data.
   * @param {*} cartData
   */
  renderButton(cartData) {
    if (this.options.page === "checkout") {
      this.wireCheckoutConfirmButton(cartData);
    } else {
      this.renderExpressButton(cartData);
    }
  }

  /**
   * Standard checkout method: open the Apple Pay sheet from "Place Order".
   * begin() runs synchronously inside the trusted click event so the sheet/QR
   * actually opens (Shopware then creates the order from the authorised token).
   * @param {*} cartData
   */
  wireCheckoutConfirmButton(cartData) {
    this.setConfirmButtonDisabled(false);

    const btn = document.getElementById("confirmFormSubmit");
    if (!btn) {
      window.isApplePay = false;
      return;
    }

    btn.addEventListener("click", (e) => {
      e.preventDefault();
      e.stopPropagation();
      this.initApplePayment(cartData);
    });
  }

  /**
   * Express (product/cart, and the confirm-page express button): render Apple's
   * official <apple-pay-button> web component and open the sheet synchronously
   * on click.
   * @param {*} cartData
   */
  renderExpressButton(cartData) {
    const container = $(".bk-apple-pay-button");
    container.empty();
    const button = ApplePay.createButton({
      buttonStyle: "black",
      locale: this.options.cultureCode,
    });
    button.addEventListener("click", (e) => {
      e.preventDefault();
      this.initApplePayment(cartData);
    });
    container.append(button);
  }

  /**
   * Retrieve cart data required by Apple. Called once, up front (not on click),
   * so the sheet can be opened synchronously later.
   * @returns Promise
   */
  retrieveCartData() {
    let formData = null;

    if (this.options.page === "product") {
      const form = this.el.closest("form");
      if (form) {
        formData = FormSerializeUtil.serializeJson(form);
      }
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

          if (resp.error) {
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
   * Construct the Apple Pay session and open the sheet. MUST be called
   * synchronously from a user-gesture handler (click) with already-fetched
   * cart data.
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
      // Callbacks bound to the instance. On checkout the shipping callbacks are
      // null, so the sheet only authorises and Shopware owns the address.
      self.captureFunds.bind(self),
      self.isCheckout(self.updateCart.bind(self), null),
      self.isCheckout(self.updateCart.bind(self), null),
      self.isCheckout(["email", "name", "postalAddress"], []),
      self.isCheckout(["email", "name", "postalAddress"], []),
    );

    self.payment = new ApplePay.PayPayment(options);
    self.payment.beginPayment();
  }

  /**
   * Return inCheckout on the checkout page, otherwise notInCheckout.
   */
  isCheckout(notInCheckout, inCheckout) {
    if (this.options.page === "checkout") {
      return inCheckout;
    }
    return notInCheckout;
  }

  /**
   * Create the sw6 order with the payment data (called after authorisation).
   * @param {*} payment
   */
  captureFunds(payment) {
    return new Promise((resolve) => {
      this.httpClient.post(
        `${this.url}/apple/order/create`,
        JSON.stringify({
          payment: JSON.stringify(payment),
          cartToken: this.cartToken,
          page: this.options.page,
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
            if (resp.message) {
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
   * Update cart with the data received from apple pay (express only)
   * @param {*} data
   * @returns Promise
   */
  updateCart(data) {
    let request = {
      cartToken: this.cartToken,
    };

    // request body for changing shipping method
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
          if (resp.error) {
            status = ApplePaySession.STATUS_FAILURE;
            this.displayErrorMessage(resp.message);
            console.warn(resp.message);
          }
          resolve({
            status: status,
            ...resp,
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
