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

        this._CheckValidate();

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
        switch (event.target.id) {
            case 'buckaroo_capayablein3_OrderAs':
                this._checkCompany();
                break;
            default:
        }
    }

    _handleMobileInputChanged() {
        this._CheckValidate();
    }

    _handleDoBInputChanged() {
        this._CheckValidate();
    }

    _CheckValidate(){
        let not_valid = false;

        const buckarooMobileInputs = ['buckarooAfterpayPhone','buckarooIn3Phone'];
        for (const fieldId of buckarooMobileInputs) {
            const field = document.getElementById(fieldId);
            if (field) {
                if(!this._handleCheckMobile(field)){
                    not_valid = true;
                }
            }
        }

        const buckarooDoBInputs = ['buckaroo_afterpay_DoB','buckaroo_capayablein3_DoB'];
        for (const fieldId of buckarooDoBInputs) {
            const field = document.getElementById(fieldId);
            if (field) {
                if(!this._handleCheckDoB(field)){
                    not_valid = true;
                }
            }
        }

        return this._disableConfirmFormSubmit(not_valid);
    }

    _handleCheckMobile(field) {
        document.getElementById('buckarooMobilePhoneError').style.display = 'none';
        if(!field.value.match(/^\d{10}$/)){
            document.getElementById('buckarooMobilePhoneError').style.display = 'block';
            return false;
        }
        return true;
    }

    _handleCheckDoB(field) {
        document.getElementById('buckarooDoBPhoneError').style.display = 'none';
        const x = new Date(Date.parse(field.value));
        if(x == 'Invalid Date'){
            document.getElementById('buckarooDoBPhoneError').style.display = 'block';
            return false;
        }
        const Cnow = new Date();
        if ((Cnow.getFullYear() - x.getFullYear() < 18) || x.getFullYear() < 1900){
            document.getElementById('buckarooDoBPhoneError').style.display = 'block';
            return false;
        }
        
        return true;
    }

    _disableConfirmFormSubmit(disable) {
        const field = document.getElementById('confirmFormSubmit');
        if (field) {
            document.getElementById('confirmFormSubmit').disabled = disable;
        }
    }
}