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
          this.renderButton(cartData);
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
   */
  setConfirmButtonDisabled(disabled) {
    const btn = document.getElementById("confirmFormSubmit");
    if (btn) {
      btn.disabled = disabled;
    }
  }

  renderButton(cartData) {
    if (this.options.page === "checkout") {
      this.wireCheckoutConfirmButton(cartData);
    } else {
      this.renderExpressButton(cartData);
    }
  }

  /**
   * Standard checkout method: open the Apple Pay sheet from "Place Order".
   * begin() runs synchronously inside the trusted click so the sheet/QR opens.
   */
  wireCheckoutConfirmButton(cartData) {
    this.setConfirmButtonDisabled(false);

    const btn = document.getElementById("confirmFormSubmit");
    if (!btn) {
      return;
    }

    document.addEventListener(
      "click",
      (e) => {
        const t = e.target;
        if (t !== btn && !(t && t.closest && t.closest("#confirmFormSubmit"))) {
          return;
        }
        e.preventDefault();
        e.stopImmediatePropagation();
        // Respect native form validation (e.g. required terms checkbox)
        // before opening the Apple Pay sheet/QR modal.
        const form = document.forms["confirmOrderForm"];
        if (form && !form.reportValidity()) {
          return;
        }
        this.initApplePayment(cartData);
      },
      true
    );
  }

  /**
   * Express (product/cart, confirm-page express button): render Apple's
   * official <apple-pay-button> and open the sheet synchronously on click.
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
   * Retrieve cart data up front (not on click), so the sheet can open synchronously.
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
   * synchronously from a click handler with already-fetched cart data.
   */
  initApplePayment(cart) {
    const self = this;
    try {
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
        self.captureFunds.bind(self),
        self.isCheckout(self.updateCart.bind(self), null),
        self.isCheckout(self.updateCart.bind(self), null),
        self.isCheckout(["email", "name", "postalAddress"], []),
        self.isCheckout(["email", "name", "postalAddress"], []),
      );

      self.payment = new ApplePay.PayPayment(options);
      self.payment.beginPayment();
    } catch (e) {
      // Apple Pay cannot open here. Keep window.isApplePay true so no order is
      // placed without authorisation; surface a message and re-enable the button.
      console.warn("Apple Pay could not open the payment sheet:", e);
      self.displayErrorMessage(
        (self.options.i18n && self.options.i18n.cannot_create_payment) ||
          "Apple Pay is not available in this browser."
      );
      if (self.options.page === "checkout") {
        self.setConfirmButtonDisabled(false);
      }
    }
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
   * Create the sw6 order with the payment data (after authorisation).
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
   */
  updateCart(data) {
    let request = {
      cartToken: this.cartToken,
    };

    if (data.identifier !== undefined) {
      request = {
        ...request,
        shippingMethod: data.identifier,
      };
    }

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

  checkIsAvailable() {
    return ApplePay.checkPaySupport(this.options.merchantId);
  }

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
