import Plugin from 'src/plugin-system/plugin.class';

export default class BuckarooCreditCards extends Plugin {
    init()
    {
        this._loadHostedFieldsScript(() => {
            this._initializeHostedFields();
            this._listenToSubmit();
        });
    }

    _loadHostedFieldsScript(callback)
    {
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

    async _initializeHostedFields()
    {
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
                console.error("Failed to retrieve Buckaroo OAuth token.");
                return;
            }

            const accessToken = data.data.access_token;
            this.sdkClient = new BuckarooHostedFieldsSdk.HFClient(accessToken);
            this.sdkClient.setLanguage("en");
            console.log(data.data.issuers)
            await this.sdkClient.setSupportedServices(data.data.issuers);
            let payButton = document.getElementById("pay");

            let service = "";

            // Mount Hosted Fields
            await this.sdkClient.startSession((event) => {
                this.sdkClient.handleValidation(
                    event,
                    "cc-name-error",
                    "cc-number-error",
                    "cc-expiry-error",
                    "cc-cvc-error"
                );

                if (payButton) {
                    let disabled = !this.sdkClient.formIsValid();

                    payButton.disabled = disabled;

                    if (disabled) {
                        payButton.style.backgroundColor = "#ff5555";
                        payButton.style.cursor = "not-allowed";
                        payButton.style.opacity = "0.5";
                    } else {
                        payButton.style.backgroundColor = "";
                        payButton.style.cursor = "";
                        payButton.style.opacity = "";
                    }
                }

                service = this.sdkClient.getService();
            });
            let styling = {
                fontSize:"14px",
                fontFamily: 'Consolas, Liberation Mono, Menlo, Courier, monospace',
                textAlign: 'left',
                background: 'inherit',
                color:'black',
                placeholderColor:'grey'
            };

            // Options for CardholderName.
            let chnameOptions = {
                id: "ccname",
                placeHolder: "John Doe",
                labelSelector: "#cc-name-label",
                baseStyling: styling
            };

            // Mount CardholderName and focus in it
            await this.sdkClient.mountCardHolderName("#cc-name-wrapper", chnameOptions)
                .then(function (cardHolderNameField) {
                    cardHolderNameField.focus();
                });

            // Options for Cardnumber. optional: CardLogoStyling
            let ccOptions = {
                id: "cc",
                placeHolder: "555x xxxx xxxx xxxx",
                labelSelector: "#cc-number-label",
                baseStyling: styling
            };

            // Mount CardNumber.
            await this.sdkClient.mountCardNumber("#cc-number-wrapper", ccOptions);

            // Options for CVC.
            let cvcOptions = {
                id: "cvc",
                placeHolder: "1234",
                labelSelector: "#cc-cvc-label",
                baseStyling: styling
            };

            // Mount CVC.
            await this.sdkClient.mountCvc("#cc-cvc-wrapper", cvcOptions);

            // Options for Expiry Date.
            let expiryDateOptions = {
                id: "expiry",
                placeHolder: "MM / YY",
                labelSelector: "#cc-expiry-label",
                baseStyling: styling
            };

            // Mount Expiry Date.
            await this.sdkClient.mountExpiryDate("#cc-expiry-wrapper", expiryDateOptions);


        } catch (error) {
            console.error("Error initializing Buckaroo Hosted Fields:", error);
        }
    }

    async _handleSubmit(event)
    {
        event.preventDefault();

        try {
            const paymentToken = await this.sdkClient.submitSession();
            if (!paymentToken) {
                console.error("Failed to retrieve Hosted Fields token.");
                return;
            }

            // Store token in hidden input field
            const tokenField = document.getElementById("buckaroo-token");
            if (tokenField) {
                tokenField.value = paymentToken;
            }

            // Submit the form
            document.getElementById("confirmOrderForm").submit();
        } catch (error) {
            console.error("Error processing Buckaroo payment:", error);
        }
    }

    _listenToSubmit()
    {
        const submitButton = document.getElementById("pay");
        if (submitButton) {
            submitButton.addEventListener("click", this._handleSubmit.bind(this));
        }
    }
}
