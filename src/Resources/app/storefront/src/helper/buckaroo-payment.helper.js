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
}