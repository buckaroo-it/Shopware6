import Plugin from 'src/plugin-system/plugin.class';
import DomAccess from 'src/helper/dom-access.helper';
import Iterator from 'src/helper/iterator.helper';

export default class BuckarooPaymentHelper extends Plugin {
    get buckarooInputs()
    {
        return ['buckaroo_capayablein3_OrderAs'];
    }

    get buckarooMobileInputs()
    {
        return ['buckarooAfterpayPhone','buckarooIn3Phone','buckarooBillinkPhone'];
    }

    get buckarooDoBInputs()
    {
        return ['buckaroo_afterpay_DoB','buckaroo_capayablein3_DoB','buckaroo_billink_DoB'];
    }

    init()
    {
        try {
            this._registerEvents();
        } catch (e) {
            // do nothing
            console.log('init error', e);
        }
    }

    _registerEvents()
    {
        this._checkCompany();
        this._listenToSubmit();
        for (const fieldId of this.buckarooInputs) {
            const field = document.getElementById(fieldId);
            if (field) {
                field.addEventListener('change', this._handleInputChanged.bind(this));
            }
        }

        for (const fieldId of this.buckarooMobileInputs) {
            const field = document.getElementById(fieldId);
            if (field) {
                field.addEventListener('change', this._handleMobileInputChanged.bind(this));
            }
        }

        for (const fieldId of this.buckarooDoBInputs) {
            const field = document.getElementById(fieldId);
            if (field) {
                field.addEventListener('change', this._handleDoBInputChanged.bind(this));
            }
        }

        // this._CheckValidate();

        const field = document.getElementById('P24Currency');
        if (field) {
            if (field.value != 'PLN') {
                document.getElementById('confirmFormSubmit').disabled = true;
                document.getElementById('P24CurrencyError').style.display = 'block';
            }
        }

    }

    _checkCompany()
    {
        const field = document.getElementById('buckaroo_capayablein3_OrderAs');
        let display = 'none';
        let required = false;
        if (field) {
            if (field.selectedIndex > 0) {
                required = true;
                display = 'block';
            }
        }
        const div = document.getElementById('buckaroo_capayablein3_COCNumberDiv');
        if (div) {
            div.style.display = display;
            document.getElementById('buckaroo_capayablein3_CompanyNameDiv').style.display = display;
            document.getElementById('buckaroo_capayablein3_COCNumber').required = required;
            document.getElementById('buckaroo_capayablein3_CompanyName').required = required;
        }
        return required;
    }

    _handleInputChanged(event)
    {
        switch (event.target.id) {
            case 'buckaroo_capayablein3_OrderAs':
                this._checkCompany();
                break;
            default:
        }
    }

    _handleMobileInputChanged()
    {
        this._CheckValidate();
    }

    _handleDoBInputChanged()
    {
        this._CheckValidate();
    }

    _CheckValidate()
    {
        let not_valid = false;

        for (const fieldId of this.buckarooMobileInputs) {
            const field = document.getElementById(fieldId);
            if (field) {
                if (!this._handleCheckMobile(field)) {
                    not_valid = true;
                }
            }
        }

        for (const fieldId of this.buckarooDoBInputs) {
            const field = document.getElementById(fieldId);
            if (field) {
                if (!this._handleCheckDoB(field)) {
                    not_valid = true;
                }
            }
        }

        return this._disableConfirmFormSubmit(not_valid);
    }

    _handleCheckMobile(field)
    {
        document.getElementById('buckarooMobilePhoneError').style.display = 'none';
        if (!field.value.match(/^\d{10}$/)) {
            document.getElementById('buckarooMobilePhoneError').style.display = 'block';
            return false;
        }
        return true;
    }

    _handleCheckDoB(field)
    {
        document.getElementById('buckarooDoBError').style.display = 'none';
        const x = new Date(Date.parse(field.value));
        if (x == 'Invalid Date') {
            document.getElementById('buckarooDoBError').style.display = 'block';
            return false;
        }
        const Cnow = new Date();
        if ((Cnow.getFullYear() - x.getFullYear() < 18) || x.getFullYear() < 1900) {
            document.getElementById('buckarooDoBError').style.display = 'block';
            return false;
        }

        return true;
    }

    _disableConfirmFormSubmit(disable)
    {
        const field = document.getElementById('confirmFormSubmit');
        if (field) {
            document.getElementById('confirmFormSubmit').disabled = disable;
        }
        return disable;
    }

    _handleCompanyName()
    {
        let validationElement = document.getElementById('buckaroo_capayablein3_CompanyNameError')
        validationElement.style.display = 'none';
        if (!document.getElementById('buckaroo_capayablein3_CompanyName').value.length) {
            validationElement.style.display = 'block';
            return false;
        }
        return true;
    }
    _isRadioOrCeckbox(element)
    {
        return element.type == 'radio' || element.type == 'checkbox';
    }

    radioGroupHasRequired(element)
    {
        let radioElements =  element.querySelectorAll('input[type="radio"]');
        if (radioElements) {
            return [...radioElements].filter(function (radioElement) {
                return radioElement.checked;
            }).length > 0;
        }
        return false;
    }
    isRadioGroup(element)
    {
        return element.classList.contains('radio-group-required');
    }
    _handleRequired()
    {
        let requiredElements = document.getElementById('changePaymentForm').querySelectorAll("[required]");

        if (requiredElements && requiredElements.length) {
            requiredElements.forEach((element) => {

                let parent = element.parentElement;
                if (element.type === 'radio') {
                    parent = parent.parentElement;
                }
                if (parent) {
                    let previousMessage = parent.querySelector('[class="buckaroo-required"]');


                    if (this.isRadioGroup(element) && this.radioGroupHasRequired(element)) {
                        if (previousMessage) {
                            previousMessage.remove()
                        }
                    } else if (this._isRadioOrCeckbox(element) && element.checked) {
                        if (previousMessage) {
                              previousMessage.remove()
                        }
                    } else if (!this._isRadioOrCeckbox(element) && !this.isRadioGroup(element) && element.value.length > 0 ) {
                        if (previousMessage) {
                              previousMessage.remove()
                        }
                    } else if (previousMessage === null) {
                        previousMessage  = this._createMessageElement(element);
                        let otherMessages = parent.querySelector('[id$="Error"]');
                        if (otherMessages === null) {
                            parent.append(previousMessage);
                        }
                    }
                }
            })
        }
    }
    _createMessageElement(forElement)
    {
        let textMessage = buckaroo_required_message;
        let attributeTextMessage = forElement.getAttribute('required-message');
        if (attributeTextMessage != null && attributeTextMessage.length) {
            textMessage = attributeTextMessage;
        }
        let messageElement = document.createElement("label");
        messageElement.setAttribute('for', forElement.id);
        messageElement.classList.add('buckaroo-required');
        messageElement.style.color = 'red';
        messageElement.style.width = '100%';
        messageElement.innerHTML = textMessage;
        return messageElement;
    }
    _validateOnSubmit()
    {
        let valid = true;
        this._handleRequired();

        let radioGroups = document.querySelectorAll('.radio-group-required');
        for (const radioGroup of radioGroups) {
            valid = valid && this.radioGroupHasRequired(radioGroup);
        }
        for (const fieldId of this.buckarooMobileInputs) {
            const field = document.getElementById(fieldId);
            if (field) {
                valid = valid && !this._CheckValidate();
            }
        }

        for (const fieldId of this.buckarooDoBInputs) {
            const field = document.getElementById(fieldId);
            if (field) {
                valid = valid && !this._CheckValidate();
            }
        }
        document.$emitter.publish('buckaroo_payment_validate', {valid, type:'general'});
    }

    _listenToSubmit()
    {
        document.$emitter.subscribe('buckaroo_payment_submit', this._validateOnSubmit.bind(this));

        const confirmButton = document.getElementById('confirmFormSubmit');
        if (confirmButton) {
            confirmButton.addEventListener('click', (event) => {
                confirmButton.disabled = true;

                setTimeout(() => {
                    const notValid = this._CheckValidate();
                    if (notValid) {
                        confirmButton.disabled = false; // Validation failed, allow retry
                    }
                }, 2000);
            });
        }
    }


}