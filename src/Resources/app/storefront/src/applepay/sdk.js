/* eslint-disable */
/*!
 * Buckaroo Apple Pay integration
 *
 * Uses Apple's official Apple Pay JS SDK for support detection and the
 * <apple-pay-button> web component / QR handoff. The Buckaroo hosted endpoint
 * is kept only for merchant validation + ApplePaySession orchestration.
 *
 * Copyright Buckaroo - MIT license - https://buckaroo.nl
 */

// The button is shared with the button preview in the plugin settings
import { APPLE_PAY_SDK_URL, createButton } from "./button";

/**
 * Load Apple's official Apple Pay JS SDK.
 *
 * It provides the <apple-pay-button> web component, ApplePaySession.applePayCapabilities()
 * and the cross-device (QR-code) handoff, so Apple Pay works in every browser
 * (Chrome, Edge, Firefox, Safari) and on non-Apple devices. Resolves once loaded
 * (or immediately if already present); never rejects.
 *
 * @returns {Promise<void>}
 */
const loadOfficialSdk = function () {
    return new Promise(function (resolve) {
        if (typeof window.ApplePaySession !== "undefined" &&
            typeof ApplePaySession.applePayCapabilities === "function") {
            resolve();
            return;
        }

        const existing = document.getElementById("apple-pay-sdk");
        if (existing) {
            existing.addEventListener("load", function () { resolve(); });
            existing.addEventListener("error", function () { resolve(); });
            return;
        }

        const script = document.createElement("script");
        script.id = "apple-pay-sdk";
        script.src = APPLE_PAY_SDK_URL;
        script.crossOrigin = "anonymous";
        script.onload = function () { resolve(); };
        script.onerror = function () { resolve(); };
        document.head.appendChild(script);
    });
};

/**
 * Detect Apple Pay support using Apple's official API (cross-browser).
 * Guards against insecure contexts and any thrown/rejected error so it can
 * never break checkout rendering.
 *
 * @param {string} merchantIdentifier
 * @returns {Promise<boolean>}
 */
const checkPaySupport = async function (merchantIdentifier) {
    if (!("ApplePaySession" in window) || typeof ApplePaySession === "undefined") {
        return false;
    }
    if (window.isSecureContext === false) {
        return false;
    }

    const safeCanMakePayments = function () {
        try {
            return ApplePaySession.canMakePayments() === true;
        } catch (e) {
            return false;
        }
    };

    try {
        if (typeof ApplePaySession.applePayCapabilities === "function") {
            try {
                const caps = await ApplePaySession.applePayCapabilities(merchantIdentifier);
                if (caps && caps.paymentCredentialStatus !== "applePayUnsupported") {
                    return true;
                }
                return safeCanMakePayments();
            } catch (e) {
                return safeCanMakePayments();
            }
        }
        return safeCanMakePayments();
    } catch (e) {
        return false;
    }
};

var PayPayment = function (options) {
    var _this = this;
    this.applePayVersion = 4;
    this.validationUrl = 'https://applepay.buckaroo.io/v1/request-session';
    /**
     * Aborts the current ApplePaySession if exists.
     */
    this.abortSession = function () {
        if (_this.session) {
            _this.session.abort();
        }
    };

    this.init = function () {
        // Intentionally empty: the official Apple Pay SDK provides the button
        // styling via the <apple-pay-button> web component, so the legacy
        // Buckaroo SDK stylesheet is no longer injected.
    };

    this.validate = function () {
        if (!_this.options.processCallback) {
            throw 'ApplePay: processCallback must be set';
        }
        if (!_this.options.storeName) {
            throw 'ApplePay: storeName is not set';
        }
        if (!_this.options.countryCode) {
            throw 'ApplePay: countryCode is not set';
        }
        if (!_this.options.currencyCode) {
            throw 'ApplePay: currencyCode is not set';
        }
    };

    this.beginPayment = function () {
        var paymentRequest = {
            countryCode: _this.options.countryCode,
            currencyCode: _this.options.currencyCode,
            merchantCapabilities: _this.options.merchantCapabilities,
            supportedNetworks: _this.options.supportedNetworks,
            lineItems: _this.options.lineItems,
            total: _this.options.totalLineItem,
            requiredBillingContactFields: _this.options.requiredBillingContactFields,
            requiredShippingContactFields: _this.options.requiredShippingContactFields,
            shippingType: _this.options.shippingType,
            shippingMethods: _this.options.shippingMethods,
        };
        _this.session = new ApplePaySession(_this.applePayVersion, paymentRequest);
        _this.session.onvalidatemerchant = _this.onValidateMerchant;

        if (_this.options.shippingMethodSelectedCallback) {
            _this.session.onshippingmethodselected = _this.onShippingMethodSelected;
        }

        if (_this.options.shippingContactSelectedCallback) {
            _this.session.onshippingcontactselected = _this.onShippingContactSelected;
        }

        if (_this.options.cancelCallback) {
            _this.session.oncancel = _this.onCancel;
        }
        _this.session.onpaymentauthorized = _this.onPaymentAuthorized;
        _this.session.begin();
    };

    /**
     * Handles merchant validation for the Apple Pay session.
     *
     * Any non-2xx response, network error or invalid JSON from the Buckaroo
     * request-session endpoint aborts the session explicitly. Passing an error
     * body into completeMerchantValidation() (or never calling it) surfaces as
     * a generic "Service Unavailable" on the device with nothing in any log.
     *
     * @param event - The ApplePayValidateMerchantEvent object.
     */
    this.onValidateMerchant = function (event) {
        var data = {
            validationUrl: event.validationURL,
            displayName: _this.options.storeName,
            domainName: window.location.hostname,
            merchantIdentifier: _this.options.merchantIdentifier,
        };
        fetch(_this.validationUrl, {
            method: 'POST',
            body: JSON.stringify(data),
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Merchant validation failed: HTTP ' + response.status);
            }
            return response.json();
        }).then(function (merchantSession) {
            if (!merchantSession || merchantSession.statusCode >= 400 || merchantSession.error) {
                throw new Error('Merchant validation failed: invalid merchant session');
            }
            _this.session.completeMerchantValidation(merchantSession);
        }).catch(function (error) {
            console.warn('Apple Pay merchant validation failed:', error);
            _this.abortSession();
        });
    };

    /**
     * Handles the Apple Pay payment being authorized by the user.
     * @param event - The ApplePayPaymentAuthorizedEvent object.
     */
    this.onPaymentAuthorized = function (event) {
        var payment = event.payment;
        _this.options.processCallback(payment).then(function (authorizationResult) {
            _this.session.completePayment(authorizationResult);
        });
    };

    /**
     * Handles the shipping method being changed by the user
     * @param event - The ApplePayShippingMethodSelectedEvent object.
     */
    this.onShippingMethodSelected = function (event) {
        if (!_this.options.shippingMethodSelectedCallback) {
            return;
        }
        _this.options
            .shippingMethodSelectedCallback(event.shippingMethod)
            .then(function (result) {
                if (!result) {
                    return;
                }
                _this.session.completeShippingMethodSelection(result);
            });
    };

    /**
     * Handles the shipping contact being changed by the user
     * @param event - The ApplePayShippingContactSelectedEvent object.
     */
    this.onShippingContactSelected = function (event) {
        if (!_this.options.shippingContactSelectedCallback) {
            return;
        }
        _this.options
            .shippingContactSelectedCallback(event.shippingContact)
            .then(function (result) {
                if (!result) {
                    return;
                }
                _this.session.completeShippingContactSelection(result);
            });
    };

    /**
     * An event handler that is automatically called when the payment UI is dismissed.
     * @param event - The Event object.
     */
    this.onCancel = function (event) {
        if (!_this.options.cancelCallback) {
            return;
        }
        _this.options.cancelCallback(event);
    };

    this.options = options;
    this.init();
    this.validate();
};

var PayOptions = function (
    storeName,
    countryCode,
    currencyCode,
    cultureCode,
    merchantIdentifier,
    lineItems,
    totalLineItem,
    shippingType,
    shippingMethods,
    processCallback,
    shippingMethodSelectedCallback,
    shippingContactSelectedCallback,
    requiredBillingContactFields,
    requiredShippingContactFields,
    cancelCallback,
    merchantCapabilities,
    supportedNetworks
) {
    if (shippingMethodSelectedCallback === void 0) {
        shippingMethodSelectedCallback = null;
    }
    if (shippingContactSelectedCallback === void 0) {
        shippingContactSelectedCallback = null;
    }
    if (requiredBillingContactFields === void 0) {
        requiredBillingContactFields = ['email', 'name', 'postalAddress'];
    }
    if (requiredShippingContactFields === void 0) {
        requiredShippingContactFields = ['email', 'name', 'postalAddress'];
    }
    if (cancelCallback === void 0) {
        cancelCallback = null;
    }
    if (merchantCapabilities === void 0) {
        merchantCapabilities = ['supports3DS', 'supportsCredit', 'supportsDebit'];
    }
    if (supportedNetworks === void 0) {
        supportedNetworks = [
            'masterCard',
            'visa',
            'maestro',
            'vPay',
            'cartesBancaires',
            'privateLabel',
        ];
    }
    this.storeName = storeName;
    this.countryCode = countryCode;
    this.currencyCode = currencyCode;
    this.cultureCode = cultureCode;
    this.merchantIdentifier = merchantIdentifier;
    this.lineItems = lineItems;
    this.totalLineItem = totalLineItem;
    this.shippingType = shippingType;
    this.shippingMethods = shippingMethods;
    this.processCallback = processCallback;
    this.shippingMethodSelectedCallback = shippingMethodSelectedCallback;
    this.shippingContactSelectedCallback = shippingContactSelectedCallback;
    this.requiredBillingContactFields = requiredBillingContactFields;
    this.requiredShippingContactFields = requiredShippingContactFields;
    this.cancelCallback = cancelCallback;
    this.merchantCapabilities = merchantCapabilities;
    this.supportedNetworks = supportedNetworks;
};

var ApplePay = {
    PayPayment,
    PayOptions,
    checkPaySupport,
    loadOfficialSdk,
    createButton
};

export default ApplePay;
/* eslint-enable */
