import Plugin from 'src/plugin-system/plugin.class';

export default class BuckarooCreditCards extends Plugin {
    init() {
        const element = document.getElementById("tailwind-wrapper-creditcards");

        if (!element) {
            return;
        }

        this._loadHostedFieldsScript(() => {
            this._initializeHostedFields();
            this._listenToSubmit();
        });
    }

    _loadHostedFieldsScript(callback) {

        const scriptUrl = "https://hostedfields-externalapi.prod-pci.buckaroo.io/v1/sdk";

        if (!document.getElementById("buckaroo-sdk")) {
            const script = document.createElement("script");
            script.id = "buckaroo-sdk";
            script.src = scriptUrl;
            script.addEventListener("load", callback.bind(this), false);
            document.head.appendChild(script);
        } else {
            callback();
        }
    }

    async _initializeHostedFields() {
        try {
            const tokenData = await this._getOrRefreshToken();

            if (!tokenData || !tokenData.access_token) {
                console.error("Failed to retrieve Buckaroo OAuth token.");
                return;
            }

            const accessToken = tokenData.access_token;

            this.sdkClient = new BuckarooHostedFieldsSdk.HFClient(accessToken);
            this.sdkClient.setLanguage("en");

            await this.sdkClient.setSupportedServices(tokenData.issuers);

            const issuerField = document.getElementById("selected-issuer");
            let payButton = document.getElementById("pay");
            let service = "";

            await this.sdkClient.startSession((event) => {
                this.sdkClient.handleValidation(
                    event,
                    "cc-name-error",
                    "cc-number-error",
                    "cc-expiry-error",
                    "cc-cvc-error"
                );

                if (payButton) {
                    this._updateButtonState(payButton);
                }

                service = this.sdkClient.getService();
                issuerField.value = service;
            });

            const cardLogoStyling = {
                height: "80%",
                position: 'absolute',
                border: '1px solid gray',
                radius: '5px',
                opacity: '1',
                transition: 'all 0.3s ease',
                right: '5px',
                backgroundColor: 'inherit'
            };

            const styling = {
                fontSize: "14px",
                fontFamily: 'Consolas, Liberation Mono, Menlo, Courier, monospace',
                textAlign: 'left',
                background: 'inherit',
                color: 'black',
                placeholderColor: 'grey',
                cardLogoStyling
            };

            await this.sdkClient.mountCardHolderName("#cc-name-wrapper", {
                id: "ccname",
                placeHolder: "John Doe",
                labelSelector: "#cc-name-label",
                baseStyling: styling
            }).then(field => field.focus());

            await this.sdkClient.mountCardNumber("#cc-number-wrapper", {
                id: "cc",
                placeHolder: "555x xxxx xxxx xxxx",
                labelSelector: "#cc-number-label",
                baseStyling: styling,
                cardLogoStyling
            });

            await this.sdkClient.mountCvc("#cc-cvc-wrapper", {
                id: "cvc",
                placeHolder: "1234",
                labelSelector: "#cc-cvc-label",
                baseStyling: styling
            });

            await this.sdkClient.mountExpiryDate("#cc-expiry-wrapper", {
                id: "expiry",
                placeHolder: "MM / YY",
                labelSelector: "#cc-expiry-label",
                baseStyling: styling
            });


        } catch (error) {
            console.error("Error initializing Buckaroo Hosted Fields:", error);
        }
    }

    async _handleSubmit(event) {
        event.preventDefault();

        try {
            const paymentToken = await this.sdkClient.submitSession();

            if (!paymentToken) {
                console.error("Failed to retrieve Hosted Fields token.");
                return;
            }

            const tokenField = document.getElementById("buckaroo-token");

            if (tokenField) tokenField.value = paymentToken;

            document.getElementById("confirmOrderForm").submit();
        } catch (error) {
            console.error("Error processing Buckaroo payment:", error);
        }
    }

    _listenToSubmit() {
        const submitButton = document.getElementById("pay");
        if (submitButton) {
            submitButton.addEventListener("click", this._handleSubmit.bind(this));
        }

        // Listen to TOS checkbox changes
        const tosCheckbox = document.getElementById("tos") || document.querySelector(".checkout-confirm-tos-checkbox");
        if (tosCheckbox && submitButton) {
            tosCheckbox.addEventListener("change", () => {
                this._updateButtonState(submitButton);
            });
        }
    }

    _updateButtonState(payButton) {
        if (!payButton) return;

        // Check if hosted fields form is valid
        const formIsValid = this.sdkClient && this.sdkClient.formIsValid();
        
        // Check if TOS checkbox is checked
        const tosCheckbox = document.getElementById("tos") || document.querySelector(".checkout-confirm-tos-checkbox");
        const tosIsChecked = tosCheckbox ? tosCheckbox.checked : true; // If no TOS checkbox, don't block
        
        // Button should only be enabled if both conditions are met
        const disabled = !formIsValid || !tosIsChecked;
        
        payButton.disabled = disabled;
        payButton.style.backgroundColor = disabled ? "#ff5555" : "";
        payButton.style.cursor = disabled ? "not-allowed" : "";
        payButton.style.opacity = disabled ? "0.5" : "";
    }
    async _getOrRefreshToken() {
        const now = Date.now();

        if (this.tokenExpiresAt && now < this.tokenExpiresAt && this.accessToken) {

            return {
                access_token: this.accessToken,
                issuers: this.issuers
            };
        }
        try {
            const baseUrl = window.location.origin;
            const response = await fetch(`${baseUrl}/buckaroo/get-oauth-token`, {
                method: "GET",
                headers: {
                    "X-Requested-From": "ShopwareFrontend"
                }
            });

            const data = await response.json();
            if (!data || !data.data.access_token) {
                throw new Error("No access token in response");
            }


            this.accessToken = data.data.access_token;
            this.issuers = data.data.issuers;
            this.tokenExpiresAt = now + (10 * 60 * 1000);

            return {
                access_token: this.accessToken,
                issuers: this.issuers
            };
        } catch (error) {
            console.error("Token refresh failed:", error);
            return null;
        }
    }

}
