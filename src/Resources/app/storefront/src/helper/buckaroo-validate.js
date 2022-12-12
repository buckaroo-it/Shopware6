import Plugin from 'src/plugin-system/plugin.class';

export default class BuckarooPaymentValidateSubmit extends Plugin {

    init() {
        try {
            this._registerCheckoutSubmitButton();
        } catch (e) {
            // do nothing
            console.log('init error', e);
        }
    }
    _registerCheckoutSubmitButton() {
        const confirmOrderForm = document.getElementById('confirmOrderForm')
        if (confirmOrderForm) {
            const submitButton = confirmOrderForm.querySelector('[type="submit"]');
            submitButton.addEventListener('click', this._handleCheckoutSubmit.bind(this));
        }
    }
    _handleCheckoutSubmit(e) {
        e.preventDefault();
        //unsubscribe any previous subscription
        document.$emitter.unsubscribe('buckaroo_payment_validate');
        //listen to validation events
        this._listenToValidation(); 
        document.$emitter.publish('buckaroo_payment_submit');
    }
    _listenToValidation() {
        let validator = {
            general:this._deferred(),
            credicard:this._deferred()
        }
        document.$emitter.subscribe('buckaroo_payment_validate', function(event) {
            if(event.detail.type && validator[event.detail.type]) {
                validator[event.detail.type].resolve(event.detail.valid)
            }
        })

        //when all validators are valid submit the form
        Promise.all([validator.general, validator.credicard]).then(function([generalValid, creditValid]) {
            if(document.forms['confirmOrderForm'] === undefined || !document.forms['confirmOrderForm'].reportValidity()) {
                return;
            }
            let valid = generalValid && creditValid;
            if (!valid) {
                document.getElementById("changePaymentForm").scrollIntoView();
            } else {
                    // if(buckaroo_back_link !== undefined) {
                    //     window.history.pushState(
                    //         null, null, buckaroo_back_link
                    //     );
                    // }

                    if (!window.isApplePay) {
                        document.forms['confirmOrderForm'].submit();
                    }
            }
        })

    }
    /** create deferred promise */
    _deferred() {
        let resolve, reject;
        const promise = new Promise((res, rej) => {
          [resolve, reject] = [res, rej];
        });
        promise.resolve = resolve;
        promise.reject = reject;
        return promise;
      }
}