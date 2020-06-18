import Plugin from 'src/plugin-system/plugin.class';

export default class BuckarooPaymentCreditcards extends Plugin {
    init() {
        this._createScript(() => {
            const creditcardsInputs = ['creditcards_issuer', 'creditcards_cardholdername', 'creditcards_cardnumber', 'creditcards_expirationmonth', 'creditcards_expirationyear', 'creditcards_cvc'];
            for (const fieldId of creditcardsInputs) {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.addEventListener('change', this._handleInputChanged.bind(this));
                }
            }
            this._getEncryptedData();
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
                    encryptedCardDataInput.value = encryptedCardData;
                });
        };
        getEncryptedData(document.getElementById('creditcards_cardnumber').value, document.getElementById('creditcards_expirationyear').value, document.getElementById('creditcards_expirationmonth').value, document.getElementById('creditcards_cvc').value, document.getElementById('creditcards_cardholdername').value);
    }

    _handleInputChanged(event) {
        const fieldId = event.target.id;
        const field = document.getElementById(fieldId);
        switch (fieldId) {
            case 'creditcards_issuer':
                document.getElementById('card_kind_img').setAttribute('src', field.options[field.selectedIndex].getAttribute('data-logo'));
                break;
            case 'creditcards_cardnumber':
                if(!window.BuckarooClientSideEncryption.V001.validateCardNumber(field.value.replace(/\s+/g, ''))){
                    console.log('validateCardNumber false');
                }else{
                    console.log('validateCardNumber ok');
                }
                break;
            case 'creditcards_cardholdername':
                if(!window.BuckarooClientSideEncryption.V001.validateCardholderName(field.value)){
                    console.log('validateCardholderName false');
                }else{
                    console.log('validateCardholderName ok');
                }
                break;
            default:
        }

        this._getEncryptedData();
    }
}