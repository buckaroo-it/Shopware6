import Plugin from 'src/plugin-system/plugin.class';

export default class BuckarooPaymentHelper extends Plugin {
    init() {
        this._checkCompany();
        const buckarooInputs = ['buckaroo_capayablein3_OrderAs'];
        for (const fieldId of buckarooInputs) {
            const field = document.getElementById(fieldId);
            if (field) {
                field.addEventListener('change', this._handleInputChanged.bind(this));
            }
        }

        const buckarooMobileInputs = ['buckarooAfterpayPhone','buckarooIn3Phone'];
        for (const fieldId of buckarooMobileInputs) {
            const field = document.getElementById(fieldId);
            if (field) {
                field.addEventListener('change', this._handleMobileInputChanged.bind(this));
            }
        }

        const buckarooDoBInputs = ['buckaroo_afterpay_DoB','buckaroo_capayablein3_DoB'];
        for (const fieldId of buckarooDoBInputs) {
            const field = document.getElementById(fieldId);
            if (field) {
                field.addEventListener('change', this._handleDoBInputChanged.bind(this));
            }
        }

        const field = document.getElementById('P24Currency');
        if (field) {
            if(field.value != 'PLN'){
                document.getElementById('confirmFormSubmit').disabled = true;
                document.getElementById('P24CurrencyError').style.display = 'block';
            }
        }
    }

    _checkCompany() {
        const field = document.getElementById('buckaroo_capayablein3_OrderAs');
        let display = 'none';
        let required = false;
        if(field){
            if(field.selectedIndex>0){
                required = true;
                display = 'block';
            }
        }
        const div = document.getElementById('buckaroo_capayablein3_COCNumberDiv');
        if(div){
            div.style.display = display;
            document.getElementById('buckaroo_capayablein3_CompanyNameDiv').style.display = display;
            document.getElementById('buckaroo_capayablein3_COCNumber').required = required;
            document.getElementById('buckaroo_capayablein3_CompanyName').required = required;
        }
    }

    _handleInputChanged(event) {
        const fieldId = event.target.id;
        switch (fieldId) {
            case 'buckaroo_capayablein3_OrderAs':
                this._checkCompany();
                break;
            default:
        }

    }

    _handleMobileInputChanged(event) {
        document.getElementById('buckarooMobilePhoneError').style.display = 'none';
        this._disableConfirmFormSubmit(false);
        if(!event.target.value.match(/^\d{10}$/)){
            document.getElementById('buckarooMobilePhoneError').style.display = 'block';
            this._disableConfirmFormSubmit(true);
        }
    }

    _handleDoBInputChanged(event) {
        document.getElementById('buckarooDoBPhoneError').style.display = 'none';
        this._disableConfirmFormSubmit(false);
        const fieldValue = event.target.value;
        const x = new Date(Date.parse(fieldValue));  
        const Cnow = new Date();
        if ((Cnow.getFullYear() - x.getFullYear() < 18) || x.getFullYear() < 1900){
            document.getElementById('buckarooDoBPhoneError').style.display = 'block';
            this._disableConfirmFormSubmit(true);
        }  
    }

    _disableConfirmFormSubmit(disable) {
        document.getElementById('confirmFormSubmit').disabled = disable;
    }
}