export const IdealFastCheckout = {
    initiate: function (options) {

        let checkoutButton = document.querySelector(options.containerSelector);
        if (!checkoutButton) {
            console.error('Ideal Fast Checkout button not found');
            return;
        }


        checkoutButton.addEventListener('click', function () {
            const dummyOrderId = 'dummyOrderId'; // You can replace this with the actual orderId if necessary

            options.createPaymentHandler({ orderId: dummyOrderId })
                .then(() => options.onSuccessCallback())
                .catch(reason => {
                    console.log(reason);
                    if (options.onErrorCallback) {
                        options.onErrorCallback(reason);
                    }
                });
        });
    }
};