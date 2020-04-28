import ApplePay from './applepay.js';

if ($('.applepay-button-container')[0]) {

    function loadBuckarooApple() {

        const loadBuckarooSdk = new Promise((resolve) => {
            var script = document.createElement("script");
            script.src = "https://checkout.buckaroo.nl/api/buckaroosdk/script/en-US";
            script.async = true;
            document.head.appendChild(script);
            script.onload = () => {
                resolve();
            };
        });

        loadBuckarooSdk.then(() => {
            const applepay = new ApplePay;
            applepay.init();
        });

    }

    if ((typeof jQuery == 'undefined') || (typeof jQuery.ajax == 'undefined')) {
        //console.log("====applepay====jQuery1");
        const loadJquery = new Promise((resolve) => {
            var script = document.createElement("script");
            script.src = "https://code.jquery.com/jquery-3.2.1.min.js";
            script.async = true;
            document.head.appendChild(script);
            script.onload = () => {
                resolve();
            };
        });

        loadJquery.then(() => {
            loadBuckarooApple();
        });
    } else {
        loadBuckarooApple();
    }
}

