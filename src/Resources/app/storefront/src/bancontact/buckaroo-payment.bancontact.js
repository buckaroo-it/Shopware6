import BuckarooCreditCards from '../creditcards/buckaroo-payment.creditcard';

/**
 * Buckaroo Bancontact Hosted Fields.
 *
 * Same flow and styling as the card Hosted Fields; only the token endpoint's
 * supported service and the mounted fields differ. The SDK's BancontactMrCash
 * service has no security code, it refuses to mount a CVC field.
 */
export default class BuckarooPaymentBancontact extends BuckarooCreditCards {
    static options = {
        paymentMethod: 'bancontactmrcash',

        fields: {
            CardHolderName: {
                id: 'bcname',
                wrapperId: 'bc-name-wrapper',
                labelId: 'bc-name-label',
                errorId: 'bc-name-error',
                placeHolder: 'John Doe',
            },
            CardNumber: {
                id: 'bc',
                wrapperId: 'bc-number-wrapper',
                labelId: 'bc-number-label',
                errorId: 'bc-number-error',
                placeHolder: '6703 xxxx xxxx xxxx x',
            },
            ExpiryDate: {
                id: 'bcexpiry',
                wrapperId: 'bc-expiry-wrapper',
                labelId: 'bc-expiry-label',
                errorId: 'bc-expiry-error',
                placeHolder: 'MM / YY',
            },
        },
    };
}
