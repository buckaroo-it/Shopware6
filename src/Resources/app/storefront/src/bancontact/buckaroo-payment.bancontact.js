import Plugin from 'src/plugin-system/plugin.class';

export default class BuckarooPaymentBancontact extends Plugin {
    init()
    {

        this._listenToSubmit();
        this._createScript(() => {
            const bancontactmrcashInputs = [ 'bancontactmrcash_cardholdername', 'bancontactmrcash_cardnumber', 'bancontactmrcash_expirationmonth', 'bancontactmrcash_expirationyear'];
            for (const fieldId of bancontactmrcashInputs) {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.addEventListener('change', this._handleInputChanged.bind(this));
                }
            }
            this._getEncryptedData();
        });
    }

    _createScript(callback)
    {
        const url = 'https://static.buckaroo.nl/script/ClientSideEncryption001.js';
        const script = document.createElement('script');
        script.type = 'text/javascript';
        script.src = url;
        script.addEventListener('load', callback.bind(this), false);
        document.head.appendChild(script);
    }

    _getEncryptedData()
    {
        const getEncryptedData = function (cardNumber, year, month, cardholder) {
            window.BuckarooClientSideEncryption.V001.encryptCardData(
                cardNumber,
                year,
                month,
                '',
                cardholder,
                function (encryptedCardData) {
                    const encryptedCardDataInput = document.getElementById('encryptedCardData');
                    if (encryptedCardDataInput) {
                        encryptedCardDataInput.value = encryptedCardData;
                    }
                }
            );
        };
        const bancontactmrcash_cardnumber = document.getElementById('bancontactmrcash_cardnumber');
        const bancontactmrcash_expirationyear = document.getElementById('bancontactmrcash_expirationyear');
        const bancontactmrcash_expirationmonth = document.getElementById('bancontactmrcash_expirationmonth');
        const bancontactmrcash_cardholdername = document.getElementById('bancontactmrcash_cardholdername');
        if (bancontactmrcash_cardnumber && bancontactmrcash_expirationyear && bancontactmrcash_expirationmonth  && bancontactmrcash_cardholdername) {
            getEncryptedData(bancontactmrcash_cardnumber.value, bancontactmrcash_expirationyear.value, bancontactmrcash_expirationmonth.value,  bancontactmrcash_cardholdername.value);
        }
    }

    _handleInputChanged(event)
    {
        this._CheckValidate();
        this._getEncryptedData();
    }

    _handleCheckField(field)
    {
        document.getElementById(field.id + 'Error').style.display = 'none';
        switch (field.id) {
            case 'bancontactmrcash_cardnumber':
                if (!window.BuckarooClientSideEncryption.V001.validateCardNumber(field.value.replace(/\s+/g, ''))) {
                    document.getElementById(field.id + 'Error').style.display = 'block';
                    return false;
                }
                break;
            case 'bancontactmrcash_cardholdername':
                if (!window.BuckarooClientSideEncryption.V001.validateCardholderName(field.value)) {
                    document.getElementById(field.id + 'Error').style.display = 'block';
                    return false;
                }
                break;
            case 'bancontactmrcash_expirationmonth':
                if (!window.BuckarooClientSideEncryption.V001.validateMonth(field.value)) {
                    document.getElementById(field.id + 'Error').style.display = 'block';
                    return false;
                }
                break;
            case 'bancontactmrcash_expirationyear':
                if (!window.BuckarooClientSideEncryption.V001.validateYear(field.value)) {
                    document.getElementById(field.id + 'Error').style.display = 'block';
                    return false;
                }
                break;
            default:
        }
        return true;
    }

    _CheckValidate()
    {
        let not_valid = false;
        const buckarooInputs = ['bancontactmrcash_cardholdername', 'bancontactmrcash_cardnumber', 'bancontactmrcash_expirationmonth', 'bancontactmrcash_expirationyear'];
        for (const fieldId of buckarooInputs) {
            const field = document.getElementById(fieldId);
            if (field) {
                if (!this._handleCheckField(field)) {
                    not_valid = true;
                }
            }
        }
        return this._disableConfirmFormSubmit(not_valid);
    }

    _disableConfirmFormSubmit(disable)
    {
        const field = document.getElementById('confirmFormSubmit');
        if (field) {
            document.getElementById('confirmFormSubmit').disabled = disable;
        }
        return disable;
    }
    _validateOnSubmit(e)
    {
        e.preventDefault();
        let valid = !this._CheckValidate();
        document.$emitter.publish('buckaroo_payment_validate', {valid, type:'bancontactmrcash'});
    }
    _listenToSubmit()
    {
        document.$emitter.subscribe('buckaroo_payment_submit', this._validateOnSubmit.bind(this))
    }
}