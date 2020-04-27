import ApplePay from './applepay.js';

console.log("====applepay====1");

if ($('.applepay-button-container')[0]) {

    function loadBuckarooApple() {

        console.log("====applepay====3");
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
            console.log("====applepay====4");
            const applepay = new ApplePay;
            console.log("====applepay====5");

            applepay.init();
        });

    }

    if ((typeof jQuery == 'undefined') || (typeof jQuery.ajax == 'undefined')) {
        console.log("====applepay====jQuery1");

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
            console.log("====applepay====jQuery3");
            loadBuckarooApple();
        });
    } else {
        console.log("====applepay====jQuery2");
        loadBuckarooApple();
    }
}

