import ApplePay from './applepay.js';

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

        if (document.querySelector('.product-detail-quantity-select')) {
            document.querySelector('.product-detail-quantity-select').addEventListener(
                "change",
                () => {
                    applepay.rebuild();
                    applepay.init();
                });
        }
    });

}

if ((typeof jQuery == 'undefined') || (typeof jQuery.ajax == 'undefined')) {
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

