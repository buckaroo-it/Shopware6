import Plugin from 'src/plugin-system/plugin.class';

export default class BuckarooPaymentCreditcards extends Plugin {
    init() {
        this._listenToSubmit();
        this._createScript(() => {
            const creditcardsInputs = ['creditcards_issuer', 'creditcards_cardholdername', 'creditcards_cardnumber', 'creditcards_expirationmonth', 'creditcards_expirationyear', 'creditcards_cvc'];
            for (const fieldId of creditcardsInputs) {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.addEventListener('change', this._handleInputChanged.bind(this));
                }
            }
            const field = document.getElementById('creditcards_issuer');
            if (field) {
                document.getElementById('card_kind_img').setAttribute('src', field.options[field.selectedIndex].getAttribute('data-logo'));
            }
            this._getEncryptedData();
            // this._CheckValidate();
        });
    }

    _createScript(callback) {
        const url = 'https://static.buckaroo.nl/script/ClientSideEncryption001.js';
        const script = document.createElement('script');
        script.type = 'text/javascript';
        script.src = url;
        script.addEventListener('load', callback.bind(this), false);
        document.head.appendChild(script);
    }

    _getEncryptedData() {
        const getEncryptedData = function(cardNumber, year, month, cvc, cardholder) {
            window.BuckarooClientSideEncryption.V001.encryptCardData(cardNumber,
                year,
                month,
                cvc,
                cardholder,
                function(encryptedCardData) {
                    const encryptedCardDataInput = document.getElementById('encryptedCardData');
                    if(encryptedCardDataInput){
                        encryptedCardDataInput.value = encryptedCardData;
                    }
                });
        };
        const creditcards_cardnumber = document.getElementById('creditcards_cardnumber');
        const creditcards_expirationyear = document.getElementById('creditcards_expirationyear');
        const creditcards_expirationmonth = document.getElementById('creditcards_expirationmonth');
        const creditcards_cvc = document.getElementById('creditcards_cvc');
        const creditcards_cardholdername = document.getElementById('creditcards_cardholdername');
        if(creditcards_cardnumber && creditcards_expirationyear && creditcards_expirationmonth && creditcards_cvc && creditcards_cardholdername){
            getEncryptedData(creditcards_cardnumber.value, creditcards_expirationyear.value, creditcards_expirationmonth.value, creditcards_cvc.value, creditcards_cardholdername.value);
        }
    }

    _handleInputChanged(event) {
        const fieldId = event.target.id;
        const field = document.getElementById(fieldId);
        switch (fieldId) {
            case 'creditcards_issuer':
                document.getElementById('card_kind_img').setAttribute('src', field.options[field.selectedIndex].getAttribute('data-logo'));
                break;
            default:
                this._CheckValidate();
        }
        this._getEncryptedData();
    }

    _handleCheckField(field) {
        document.getElementById(field.id+'Error').style.display = 'none';
        switch (field.id) {
            case 'creditcards_cardnumber':
                if(!window.BuckarooClientSideEncryption.V001.validateCardNumber(field.value.replace(/\s+/g, ''))){
                    document.getElementById(field.id+'Error').style.display = 'block';
                    return false;
                }
                break;
            case 'creditcards_cardholdername':
                if(!window.BuckarooClientSideEncryption.V001.validateCardholderName(field.value)){
                    document.getElementById(field.id+'Error').style.display = 'block';
                    return false;
                }
                break;
            case 'creditcards_cvc':
                if(!window.BuckarooClientSideEncryption.V001.validateCvc(field.value)){
                    document.getElementById(field.id+'Error').style.display = 'block';
                    return false;
                }
                break;
            case 'creditcards_expirationmonth':
                if(!window.BuckarooClientSideEncryption.V001.validateMonth(field.value)){
                    document.getElementById(field.id+'Error').style.display = 'block';
                    return false;
                }
                break;
            case 'creditcards_expirationyear':
                if(!window.BuckarooClientSideEncryption.V001.validateYear(field.value)){
                    document.getElementById(field.id+'Error').style.display = 'block';
                    return false;
                }
                break;
            default:
        }
        return true;
    }

    _CheckValidate(){
        let not_valid = false;
        const buckarooInputs = ['creditcards_cardholdername', 'creditcards_cardnumber', 'creditcards_expirationmonth', 'creditcards_expirationyear', 'creditcards_cvc'];
        for (const fieldId of buckarooInputs) {
            const field = document.getElementById(fieldId);
            if (field) {
                if(!this._handleCheckField(field)){
                    not_valid = true;
                }
            }
        }
        return this._disableConfirmFormSubmit(not_valid);
    }

    _disableConfirmFormSubmit(disable) {
        const field = document.getElementById('confirmFormSubmit');
        if (field) {
            document.getElementById('confirmFormSubmit').disabled = disable;
        }
        return disable;
    }
    _registerCheckoutSubmitButton() {
        const field = document.getElementById('confirmFormSubmit');
        if (field) {
            field.addEventListener('click', this._handleCheckoutSubmit.bind(this));
        }
    }
    _validateOnSubmit(e) {
        e.preventDefault();
        let valid = !this._CheckValidate();
        document.$emitter.publish('buckaroo_payment_validate', {valid, type:'credicard'});
    }
    _listenToSubmit() {
        document.$emitter.subscribe('buckaroo_payment_submit', this._validateOnSubmit.bind(this))
    }
}