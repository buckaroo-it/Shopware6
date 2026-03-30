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
   * Dynamically inject the Buckaroo ClientSide SDK script once
   * @returns {Promise}
   */
  loadBuckarooSdk() {
    return new Promise((resolve, reject) => {
      if (window.BuckarooSdk && window.BuckarooSdk.GooglePay) {
        console.log("[GooglePay] SDK already present, skipping injection.");
        resolve();
        return;
      }

      const waitForGooglePay = (attempts = 0) => {
        if (window.BuckarooSdk && window.BuckarooSdk.GooglePay) {
          console.log("[GooglePay] BuckarooSdk.GooglePay is now available after polling.");
          resolve();
          return;
        }
        if (attempts >= 20) {
          console.error("[GooglePay] BuckarooSdk.GooglePay did not become available after 2s. BuckarooSdk:", window.BuckarooSdk);
          reject(new Error("BuckarooSdk.GooglePay not available"));
          return;
        }
        setTimeout(() => waitForGooglePay(attempts + 1), 100);
      };

      const existing = document.querySelector(
        'script[src="https://checkout.buckaroo.nl/api/buckaroosdk/script"]'
      );

      if (existing) {
        console.log("[GooglePay] SDK script tag already in DOM, waiting for GooglePay module...");
        waitForGooglePay();
        return;
      }

      console.log("[GooglePay] Injecting Buckaroo SDK script...");
      const script = document.createElement("script");
      script.src = "https://checkout.buckaroo.nl/api/buckaroosdk/script";
      script.async = true;
      script.onload = () => {
        console.log("[GooglePay] SDK script loaded. BuckarooSdk:", window.BuckarooSdk, "— polling for GooglePay module...");
        waitForGooglePay();
      };
      script.onerror = (e) => {
        console.error("[GooglePay] Failed to load Buckaroo SDK script:", e);
        reject(e);
      };
      document.head.appendChild(script);
    });
  }

  /**
   * Check whether Google Pay is available in this browser/device
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

      // Use the native Google Pay API for availability — the Buckaroo SDK loads it as a dependency.
      // google.payments.api may need a short moment to appear after BuckarooSdk is ready.
      const doCheck = (attempts = 0) => {
        if (window.google && window.google.payments && window.google.payments.api) {
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
              console.log("[GooglePay] google.payments.api.isReadyToPay response:", response);
              resolve(!!response.result);
            })
            .catch((e) => {
              console.error("[GooglePay] google.payments.api.isReadyToPay error:", e);
              resolve(false);
            });

        } else if (attempts < 20) {
          // google.payments.api not yet loaded — poll for up to 2 s
          console.log("[GooglePay] Waiting for google.payments.api... attempt", attempts + 1);
          setTimeout(() => doCheck(attempts + 1), 100);
        } else {
          // Still not available — proceed optimistically so the button can attempt to render
          console.warn("[GooglePay] google.payments.api not available after 2s, proceeding optimistically.");
          resolve(true);
        }
      };

      doCheck();
    });
  }

  /**
   * Render the Google Pay button or hook into the checkout submit.
   *
   * On product/cart pages the button is rendered visibly in its container.
   * On the checkout page we render the button into a visually-hidden container
   * and wire the existing "Confirm Order" button to programmatically trigger it,
   * so no extra button appears in the UI (mirrors the Apple Pay checkout UX).
   *
   * @param {object} cartData
   */
  renderButton(cartData) {
    console.log("[GooglePay] renderButton() — page:", this.options.page);

    if (this.options.page !== "checkout") {
      this.initGooglePayButton(cartData);
      // For product/cart pages the SDK renders its own button, but direct DOM
      // clicks on that button are sometimes swallowed by the SDK internally.
      // Placing a transparent overlay on top and forwarding clicks as a
      // programmatic .click() (the same technique used on checkout) fixes this.
      this.setupProductCartClickOverlay();
      return;
    }

    // Checkout page: render the SDK button into a hidden container so the SDK
    // is fully initialised, then forward the confirm-form click to it.
    // window.isGooglePay was already set to true in init() to block form submission early.
    this.initGooglePayButton(cartData);
    this.hideGooglePayContainer();
    // Button is ready — re-enable confirm so the user can click it.
    this.setConfirmButtonDisabled(false);

    const confirmBtn = document.getElementById("confirmFormSubmit");
    if (confirmBtn) {
      console.log("[GooglePay] Checkout page: wiring #confirmFormSubmit to open Google Pay sheet.");
      confirmBtn.addEventListener("click", (e) => {
        e.preventDefault();
        e.stopPropagation();
        console.log("[GooglePay] Confirm clicked — triggering Google Pay sheet.");

        // Disable button while Google Pay sheet is open to prevent double-submit.
        this.setConfirmButtonDisabled(true);

        // Locate the button rendered by the Buckaroo SDK inside the container.
        const container = document.getElementById("google-pay-button-container");
        const gpBtn = container
          ? container.querySelector(
              "button, google-pay-button, [class*='gpay'], [class*='google-pay']"
            )
          : null;

        if (gpBtn) {
          gpBtn.click();
        } else {
          console.warn("[GooglePay] Google Pay button not found in container — re-initialising.");
          this.retrieveCartData().then((freshCartData) => {
            this.initGooglePayButton(freshCartData);
            this.hideGooglePayContainer();
            setTimeout(() => {
              const retryBtn = document.querySelector(
                "#google-pay-button-container button, #google-pay-button-container google-pay-button, #google-pay-button-container [class*='gpay']"
              );
              if (retryBtn) {
                retryBtn.click();
              } else {
                console.error("[GooglePay] Google Pay button still not available after re-init.");
                this.setConfirmButtonDisabled(false);
              }
            }, 300);
          }).catch(() => this.setConfirmButtonDisabled(false));
        }
      });
    } else {
      console.warn("[GooglePay] #confirmFormSubmit not found in DOM.");
    }
  }

  /**
   * Hide the Google Pay button container on the checkout page.
   * The button is kept functional for programmatic .click() calls.
   */
  hideGooglePayContainer() {
    const container = document.getElementById("google-pay-button-container");
    if (!container) return;
    Object.assign(container.style, {
      position: "absolute",
      width: "1px",
      height: "1px",
      overflow: "hidden",
      opacity: "0",
      clip: "rect(0 0 0 0)",
      whiteSpace: "nowrap",
    });
  }

  /**
   * For product/cart pages: place a transparent overlay on top of the SDK
   * button container that intercepts user clicks and re-fires them as a
   * programmatic .click() on the underlying SDK button.
   *
   * This mirrors the checkout approach (confirm-button → gpBtn.click()) and
   * resolves the issue where direct DOM clicks on the SDK-rendered button are
   * silently ignored by the Google Pay SDK.
   */
  setupProductCartClickOverlay() {
    const container = document.getElementById("google-pay-button-container");
    if (!container) {
      console.warn("[GooglePay] setupProductCartClickOverlay: #google-pay-button-container not found.");
      return;
    }

    // Ensure the container is a positioning context so the overlay can fill it.
    container.style.position = "relative";

    const overlay = document.createElement("div");
    overlay.setAttribute("aria-label", "Pay with Google Pay");
    overlay.setAttribute("role", "button");
    overlay.setAttribute("tabindex", "0");
    Object.assign(overlay.style, {
      position: "absolute",
      inset: "0",
      cursor: "pointer",
      zIndex: "9",
      background: "transparent",
    });

    const triggerSdkButton = () => {
      // Find the button rendered by the Buckaroo/Google Pay SDK.
      // Exclude our own overlay (which also has role="button").
      const gpBtn = container.querySelector(
        "button, google-pay-button, [class*='gpay'], [class*='google-pay']"
      );
      if (gpBtn) {
        console.log("[GooglePay] Overlay click — forwarding to SDK button programmatically.");
        gpBtn.click();
      } else {
        console.warn("[GooglePay] Overlay click — SDK button not yet in container; retrying in 300 ms.");
        setTimeout(() => {
          const retryBtn = container.querySelector(
            "button, google-pay-button, [class*='gpay'], [class*='google-pay']"
          );
          if (retryBtn) {
            console.log("[GooglePay] Retry: forwarding to SDK button.");
            retryBtn.click();
          } else {
            console.error("[GooglePay] Retry: SDK button still not found.");
          }
        }, 300);
      }
    };

    overlay.addEventListener("click", (e) => {
      e.preventDefault();
      e.stopPropagation();
      triggerSdkButton();
    });

    // Keyboard accessibility: allow Enter / Space to trigger payment.
    overlay.addEventListener("keydown", (e) => {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        triggerSdkButton();
      }
    });

    container.appendChild(overlay);
    console.log("[GooglePay] Click overlay added to #google-pay-button-container.");
  }

  /**
   * Initialise the Google Pay button via the Buckaroo SDK
   * @param {object} cartData
   */
  initGooglePayButton(cartData) {
    console.log("[GooglePay] initGooglePayButton() — cartData:", cartData);

    if (!window.BuckarooSdk || !window.BuckarooSdk.GooglePay) {
      console.error("[GooglePay] BuckarooSdk.GooglePay not available at button init time.");
      this.displayErrorMessage("Google Pay SDK not available.");
      return;
    }

    const config = {
      environment: this.options.environment,
      buttonContainerId: "google-pay-button-container",
      buttonSizeMode: "fill",
      totalPriceStatus: "FINAL",
      totalPrice: cartData.totalPrice || "0.01",
      currencyCode: cartData.currency || "EUR",
      countryCode: cartData.country || "NL",
      merchantName: cartData.storeName || this.options.merchantName,
      merchantId: this.options.merchantId,
      gatewayMerchantId: cartData.gatewayMerchantId || this.options.gatewayMerchantId,
      buttonColor: this.options.buttonColor,
      onGooglePayLoadError: (error) => {
        console.error("[GooglePay] onGooglePayLoadError:", error);
        this.displayErrorMessage("Google Pay is not available.");
        this.setConfirmButtonDisabled(false);
      },
      processPayment: (paymentData) => {
        console.log("[GooglePay] processPayment() called — paymentData:", paymentData);
        return this.captureFunds(paymentData, cartData).then((result) => {
          if (result && result.success) {
            // Success — the redirect is already happening via window.location in captureFunds.
            // Return the Google Pay success state so the sheet can close cleanly.
            return { transactionState: "SUCCESS" };
          }

          // Re-enable the confirm button so the user can retry.
          this.setConfirmButtonDisabled(false);

          // Return a structured Google Pay error so the SDK can display it properly
          // instead of raising a DEVELOPER_ERROR for an unexpected callback return value.
          return {
            transactionState: "ERROR",
            error: {
              intent: "PAYMENT_AUTHORIZATION",
              message: (result && result.error) || "Could not complete Google Pay payment.",
              reason: "OTHER_ERROR",
            },
          };
        });
      },
    };

    console.log("[GooglePay] Calling GooglePayPayment.initiate() with config:", config);

    try {
      const payment = new window.BuckarooSdk.GooglePay.GooglePayPayment(config);
      payment.initiate();
      console.log("[GooglePay] initiate() called successfully.");
    } catch (e) {
      console.error("[GooglePay] Error during initiate():", e);
      this.displayErrorMessage("Could not initialise Google Pay.");
    }
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
