import ApplePay from './applepay.js';

console.log("====applepay====1");

if ($('.applepay-button-container')[0]) {

    function loadBuckarooApple() {

        console.log("====applepay====3");
        const load_buckaroo_sdk = new Promise((resolve) => {
            var buckaroo_sdk = document.createElement("script");
            buckaroo_sdk.src = "https://checkout.buckaroo.nl/api/buckaroosdk/script/en-US";
            buckaroo_sdk.async = true;
            document.head.appendChild(buckaroo_sdk);
            buckaroo_sdk.onload = () => {
                resolve();
            };
        });

        load_buckaroo_sdk.then(() => {
            console.log("====applepay====4");
            const applepay = new ApplePay;
            console.log("====applepay====5");

            applepay.init();

            /*

            $("[name='sQuantity']").change(() => {
                applepay.rebuild();
                applepay.init();
            });

            $.publish('plugin/swAjaxVariant/onRequestDataCompleted');
            $.subscribe('plugin/swAjaxVariant/onRequestDataCompleted', () => {
                applepay.rebuild();
                applepay.init();
            })

             */
        });

    }

    if ((typeof jQuery == 'undefined') || (typeof jQuery.ajax == 'undefined')) {
        console.log("====applepay====jQuery1");

        const load_jquery = new Promise((resolve) => {
            var script = document.createElement("script");
            script.src = "https://code.jquery.com/jquery-3.2.1.min.js";
            script.async = true;
            document.head.appendChild(script);
            script.onload = () => {
                resolve();
            };
        });

        load_jquery.then(() => {
            console.log("====applepay====jQuery3");
            loadBuckarooApple();
        });
    } else {
        console.log("====applepay====jQuery2");
        loadBuckarooApple();
    }
}

