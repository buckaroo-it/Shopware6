import HttpClient from "src/service/http-client.service";
import Plugin from "src/plugin-system/plugin.class";
import FormSerializeUtil from "src/utility/form/form-serialize.util";

export default class GooglePayPlugin extends Plugin {
  static options = {
    page: "unknown",
    productId: null,
    merchantId: null,
    gatewayMerchantId: null,
    merchantName: "",
    buttonColor: "default",
    environment: "TEST",
  };

  httpClient = new HttpClient();
  url = "/buckaroo";
  cartToken = null;
  googlePayment = null;

  init() {
    console.log("[GooglePay] init() — page:", this.options.page, "| options:", this.options);

    if (!this.options.merchantId || !this.options.gatewayMerchantId) {
      console.warn("[GooglePay] merchantId or gatewayMerchantId is not configured — Google Pay will not be initialised.");
      return;
    }

    // On checkout: immediately block form submission and disable the confirm
    // button while availability is being determined, mirroring Apple Pay behaviour.
    // This prevents the race condition where the form submits before isGooglePay is set.
    if (this.options.page === "checkout") {
      window.isGooglePay = true;
      this.setConfirmButtonDisabled(true);
    }

    this.loadBuckarooSdk()
      .then(() => {
        console.log("[GooglePay] Buckaroo SDK loaded successfully. BuckarooSdk.GooglePay available:", !!(window.BuckarooSdk && window.BuckarooSdk.GooglePay));
        return this.retrieveCartData();
      })
      .then((cartData) => {
        console.log("[GooglePay] Cart data received:", cartData);
        return this.checkIsAvailable(cartData).then((available) => {
          console.log("[GooglePay] isReadyToPay result:", available);
          if (available) {
            this.renderButton(cartData);
          } else {
            console.warn("[GooglePay] Google Pay is NOT available in this browser/device.");
            // Not available — release the block so the user can try another method.
            if (this.options.page === "checkout") {
              window.isGooglePay = false;
              this.setConfirmButtonDisabled(false);
            }
          }
        });
      })
      .catch((err) => {
        console.error("[GooglePay] init() failed:", err);
        if (this.options.page === "checkout") {
          window.isGooglePay = false;
          this.setConfirmButtonDisabled(false);
        }
      });
  }

  /**
   * Enable or disable the checkout confirm button
   * @param {boolean} disabled
   */
  setConfirmButtonDisabled(disabled) {
    const btn = document.getElementById("confirmFormSubmit");
    if (btn) {
      btn.disabled = disabled;
    }
  }

  /**
   * Load a script by src and poll until a readiness check passes.
   * @param {string} src
   * @param {function(): boolean} readyFn
   * @param {string} label  - used in log messages
   * @param {number} maxAttempts
   * @returns {Promise}
   */
  _loadScript(src, readyFn, label, maxAttempts = 50) {
    return new Promise((resolve, reject) => {
      if (readyFn()) {
        console.log("[GooglePay]", label, "already available.");
        resolve();
        return;
      }

      const poll = (attempts = 0) => {
        if (readyFn()) {
          console.log("[GooglePay]", label, "available after polling.");
          resolve();
          return;
        }
        if (attempts >= maxAttempts) {
          reject(new Error(label + " not available after " + (maxAttempts / 10) + "s"));
          return;
        }
        setTimeout(() => poll(attempts + 1), 100);
      };

      const existing = document.querySelector('script[src="' + src + '"]');
      if (existing) {
        console.log("[GooglePay]", label, "script already in DOM — polling...");
        poll();
        return;
      }

      console.log("[GooglePay] Injecting script:", src);
      const script = document.createElement("script");
      script.src = src;
      script.async = true;
      script.onload = () => poll();
      script.onerror = (e) => {
        console.error("[GooglePay] Failed to load script:", src, e);
        reject(e);
      };
      document.head.appendChild(script);
    });
  }

  /**
   * Load the Buckaroo ClientSide SDK and the Google Pay JS library in parallel.
   * Both must be ready before the payment button can be initialised.
   * @returns {Promise}
   */
  loadBuckarooSdk() {
    return Promise.all([
      this._loadScript(
        "https://checkout.buckaroo.nl/api/buckaroosdk/script",
        () => !!(window.BuckarooSdk && window.BuckarooSdk.GooglePay),
        "BuckarooSdk.GooglePay"
      ),
      this._loadScript(
        "https://pay.google.com/gp/p/js/pay.js",
        () => !!(window.google && window.google.payments && window.google.payments.api),
        "google.payments.api"
      ),
    ]).then(() => {
      console.log("[GooglePay] Both Buckaroo SDK and Google Pay JS ready.");
    });
  }

  /**
   * Check whether Google Pay is available in this browser/device.
   * google.payments.api is guaranteed to be loaded by this point (loadBuckarooSdk resolves both).
   * @param {object} cartData
   * @returns {Promise<boolean>}
   */
  checkIsAvailable(cartData) {
    return new Promise((resolve) => {
      if (!window.BuckarooSdk || !window.BuckarooSdk.GooglePay) {
        console.warn("[GooglePay] checkIsAvailable: BuckarooSdk.GooglePay not found on window.");
        resolve(false);
        return;
      }

      if (!window.google || !window.google.payments || !window.google.payments.api) {
        console.warn("[GooglePay] checkIsAvailable: google.payments.api not available.");
        resolve(false);
        return;
      }

      const env = this.options.environment === 'PRODUCTION' ? 'PRODUCTION' : 'TEST';
      const paymentsClient = new window.google.payments.api.PaymentsClient({ environment: env });

      paymentsClient.isReadyToPay({
        apiVersion: 2,
        apiVersionMinor: 0,
        allowedPaymentMethods: [{
          type: 'CARD',
          parameters: {
            allowedAuthMethods: ['PAN_ONLY', 'CRYPTOGRAM_3DS'],
            allowedCardNetworks: ['MASTERCARD', 'VISA'],
          },
        }],
      })
        .then((response) => {
          console.log("[GooglePay] isReadyToPay response:", response);
          resolve(!!response.result);
        })
        .catch((e) => {
          console.error("[GooglePay] isReadyToPay error:", e);
          resolve(false);
        });
    });
  }

  /**
   * Render the Google Pay button (product/cart pages) or wire the checkout
   * confirm button to open the payment sheet.
   *
   * Uses google.payments.api directly so that loadPaymentData() is always
   * called from within the original user gesture — Google Pay requires this
   * and silently refuses to open the sheet for synthetic / programmatic clicks.
   *
   * @param {object} cartData
   */
  renderButton(cartData) {
    console.log("[GooglePay] renderButton() — page:", this.options.page);

    if (this.options.page === "checkout") {
      this._wireCheckoutConfirmButton(cartData);
    } else {
      this._renderNativeButton(cartData);
    }
  }

  /**
   * On the checkout page: wire #confirmFormSubmit to open the Google Pay sheet
   * directly via loadPaymentData(). No hidden SDK button is used.
   * @param {object} cartData
   */
  _wireCheckoutConfirmButton(cartData) {
    // window.isGooglePay was set to true in init() to block early form submission.
    this.setConfirmButtonDisabled(false);

    const confirmBtn = document.getElementById("confirmFormSubmit");
    if (!confirmBtn) {
      console.warn("[GooglePay] #confirmFormSubmit not found in DOM.");
      window.isGooglePay = false;
      return;
    }

    console.log("[GooglePay] Checkout: wiring #confirmFormSubmit to open Google Pay sheet.");
    confirmBtn.addEventListener("click", (e) => {
      e.preventDefault();
      e.stopPropagation();
      console.log("[GooglePay] Confirm clicked — opening Google Pay sheet.");
      this.setConfirmButtonDisabled(true);
      // loadPaymentData() is called synchronously within the trusted click event,
      // so Google Pay accepts it as a real user gesture.
      this._openPaymentSheet(cartData);
    });
  }

  /**
   * On product/cart pages: render a native Google Pay button via
   * PaymentsClient.createButton(). The onClick fires with the real user
   * gesture so loadPaymentData() inside _openPaymentSheet() is accepted.
   * @param {object} cartData
   */
  _renderNativeButton(cartData) {
    const container = document.getElementById("google-pay-button-container");
    if (!container) {
      console.warn("[GooglePay] #google-pay-button-container not found.");
      return;
    }

    const env = this.options.environment === "PRODUCTION" ? "PRODUCTION" : "TEST";
    const paymentsClient = new window.google.payments.api.PaymentsClient({ environment: env });

    const buttonColor = this.options.buttonColor === "white" ? "white" : "black";
    const button = paymentsClient.createButton({
      buttonColor,
      buttonType: "buy",
      buttonSizeMode: "fill",
      onClick: () => {
        console.log("[GooglePay] Native button clicked — opening payment sheet.");
        this._openPaymentSheet(cartData);
      },
    });

    container.innerHTML = "";
    container.appendChild(button);
    console.log("[GooglePay] Native Google Pay button rendered.");
  }

  /**
   * Build the payment request and call loadPaymentData().
   * Must be called synchronously from within a trusted user-gesture handler.
   * @param {object} cartData
   */
  _openPaymentSheet(cartData) {
    const env = this.options.environment === "PRODUCTION" ? "PRODUCTION" : "TEST";
    const paymentsClient = new window.google.payments.api.PaymentsClient({ environment: env });

    const paymentRequest = {
      apiVersion: 2,
      apiVersionMinor: 0,
      merchantInfo: {
        merchantId: this.options.merchantId,
        merchantName: cartData.storeName || this.options.merchantName,
      },
      allowedPaymentMethods: [
        {
          type: "CARD",
          parameters: {
            allowedAuthMethods: ["PAN_ONLY", "CRYPTOGRAM_3DS"],
            allowedCardNetworks: ["MASTERCARD", "VISA"],
          },
          tokenizationSpecification: {
            type: "PAYMENT_GATEWAY",
            parameters: {
              gateway: "buckaroo",
              gatewayMerchantId:
                cartData.gatewayMerchantId || this.options.gatewayMerchantId,
            },
          },
        },
      ],
      transactionInfo: {
        totalPriceStatus: "FINAL",
        totalPrice: cartData.totalPrice || "0.01",
        currencyCode: cartData.currency || "EUR",
        countryCode: cartData.country || "NL",
      },
    };

    console.log("[GooglePay] Calling loadPaymentData() with:", paymentRequest);

    paymentsClient
      .loadPaymentData(paymentRequest)
      .then((paymentData) => {
        console.log("[GooglePay] Payment authorised — sending to backend.");
        return this.captureFunds(paymentData, cartData);
      })
      .then((result) => {
        if (!result || !result.success) {
          this.setConfirmButtonDisabled(false);
          if (result && result.error) {
            this.displayErrorMessage(result.error);
          }
        }
      })
      .catch((err) => {
        console.log("[GooglePay] loadPaymentData closed/error:", err);
        this.setConfirmButtonDisabled(false);
        // statusCode === 'CANCELED' means the user closed the sheet — not an error.
        if (err && err.statusCode !== "CANCELED") {
          this.displayErrorMessage("Could not complete Google Pay payment.");
        }
      });
  }

  /**
   * Retrieve cart data from the backend
   * @returns {Promise}
   */
  retrieveCartData() {
    let formData = null;

    if (this.options.page === "product") {
      // Try to serialize the nearest buy-form first.
      const form =
        this.el.closest("form") ||
        document.getElementById("productDetailPageBuyProductForm") ||
        document.querySelector("[data-product-detail-buy-form]") ||
        document.querySelector("form[action*='line-item/add'], form[action*='add-to-cart']");

      if (form) {
        const serialized = FormSerializeUtil.serializeJson(form);
        // Only use serialized data when it actually contains line-item fields.
        if (serialized && Object.keys(serialized).some((k) => k.includes("lineItems"))) {
          formData = serialized;
        }
      }

      // Fallback: form not found or didn't contain lineItems (e.g. buy-widget rendered
      // outside the <form> in Shopware 6.7+). Build the payload from the productId
      // option injected by Twig so the backend can create a temporary cart.
      if (!formData && this.options.productId) {
        const productId = this.options.productId;

        // Read the selected quantity from the page — Shopware uses several selectors
        // depending on the storefront version.
        const quantityEl = document.querySelector(
          `input[name="lineItems[${productId}][quantity]"],` +
          ` .product-detail-quantity-select,` +
          ` [data-quantity-selector] input,` +
          ` [data-quantity-selector] select`
        );
        const quantity = quantityEl ? (parseInt(quantityEl.value, 10) || 1) : 1;

        console.log(
          "[GooglePay] Form not found / no lineItems — building form data from productId option:",
          productId, "qty:", quantity
        );

        formData = {
          [`lineItems[${productId}][id]`]: productId,
          [`lineItems[${productId}][referencedId]`]: productId,
          [`lineItems[${productId}][type]`]: "product",
          [`lineItems[${productId}][quantity]`]: String(quantity),
          [`lineItems[${productId}][stackable]`]: "1",
          [`lineItems[${productId}][removable]`]: "1",
        };
      }
    }

    const body = { form: formData, page: this.options.page };
    console.log("[GooglePay] retrieveCartData() — POST /buckaroo/googlepay/cart/get body:", body);

    return new Promise((resolve, reject) => {
      this.httpClient.post(
        `${this.url}/googlepay/cart/get`,
        JSON.stringify(body),
        (response) => {
          console.log("[GooglePay] cart/get raw response:", response);
          const resp = JSON.parse(response);
          if (resp.error) {
            // "empty cart" means there is nothing to pay for on this page —
            // hide the button silently rather than surfacing an error to the shopper.
            if (resp.emptyCart) {
              console.warn("[GooglePay] cart/get — cart is empty, hiding button silently.");
              const container = document.getElementById("google-pay-button-container");
              if (container) container.style.display = "none";
              reject(resp.message);
              return;
            }
            console.error("[GooglePay] cart/get returned error:", resp.message);
            this.displayErrorMessage(resp.message);
            reject(resp.message);
          } else {
            this.cartToken = resp.cartToken;
            console.log("[GooglePay] cart/get success — cartToken:", resp.cartToken, "| full response:", resp);
            resolve(resp);
          }
        }
      );
    });
  }

  /**
   * Send payment token to backend and create the order
   * @param {object} paymentData  Google Pay paymentData object
   * @param {object} cartData
   * @returns {Promise}
   */
  captureFunds(paymentData, cartData) {
    const body = {
      payment: JSON.stringify(paymentData),
      cartToken: this.cartToken,
      page: this.options.page,
    };
    console.log("[GooglePay] captureFunds() — POST /buckaroo/googlepay/order/create body:", body);

    return new Promise((resolve) => {
      this.httpClient.post(
        `${this.url}/googlepay/order/create`,
        JSON.stringify(body),
        (response) => {
          console.log("[GooglePay] order/create raw response:", response);

          let resp = null;
          try {
            resp = response ? JSON.parse(response) : null;
          } catch (e) {
            console.error("[GooglePay] Failed to parse order/create response:", e, "| raw:", response);
          }

          if (resp && resp.redirect) {
            console.log("[GooglePay] Order created successfully — redirecting to:", resp.redirect);
            resolve({ success: true });
            window.location = resp.redirect;
          } else {
            const message = (resp && resp.message) || "Could not complete Google Pay payment.";
            console.error("[GooglePay] order/create failed:", message, "| full response:", resp);
            this.displayErrorMessage(message);
            resolve({ success: false, error: message });
          }
        }
      );
    });
  }

  /**
   * Display an inline error message in the Shopware flash bag area
   * @param {string} message
   */
  displayErrorMessage(message) {
    const existing = document.querySelector(".buckaroo-googlepay-error");
    if (existing) {
      existing.remove();
    }

    if (typeof message === "object") {
      message = "Could not complete Google Pay payment.";
    }

    const content = `
      <div role="alert" class="alert alert-warning alert-has-icon buckaroo-googlepay-error">
        <span class="icon icon-warning">
          <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="24" height="24" viewBox="0 0 24 24">
            <defs><path d="m13.7744 1.4124 9.7058 18.6649c.5096.98.1283 2.1875-.8517 2.6971a2 2 0 0 1-.9227.2256H2.2942c-1.1045 0-2-.8954-2-2a2 2 0 0 1 .2256-.9227l9.7058-18.665c.5096-.98 1.7171-1.3613 2.6971-.8517a2 2 0 0 1 .8517.8518zM2.2942 21h19.4116L12 2.335 2.2942 21zM12 17c.5523 0 1 .4477 1 1s-.4477 1-1 1-1-.4477-1-1 .4477-1 1-1zm1-2c0 .5523-.4477 1-1 1s-1-.4477-1-1v-5c0-.5523.4477-1 1-1s1 .4477 1 1v5z" id="icons-default-warning"></path></defs>
            <use xlink:href="#icons-default-warning" fill="#758CA3" fill-rule="evenodd"></use>
          </svg>
        </span>
        <div class="alert-content-container">
          <div class="alert-content">${message}</div>
        </div>
      </div>`;

    const flashbags = document.querySelector(".flashbags");
    if (flashbags) {
      flashbags.insertAdjacentHTML("afterbegin", content);
      setTimeout(() => {
        const el = document.querySelector(".buckaroo-googlepay-error");
        if (el) {
          el.style.transition = "opacity 1s";
          el.style.opacity = "0";
          setTimeout(() => el.remove(), 1000);
        }
      }, 10000);
    }
  }
}
