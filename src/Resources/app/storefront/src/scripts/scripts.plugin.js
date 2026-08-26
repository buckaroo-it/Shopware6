import Plugin from 'src/plugin-system/plugin.class';

export default class BuckarooLoadScripts extends Plugin {

    loadSdk()
    {
        return new Promise((resolve) => {
            var script = document.createElement("script");
            script.src = this.getSdkUrl();
            script.async = true;
            document.head.appendChild(script);
            script.onload = () => {
                resolve();
            };
        })
    }

    /**
     * Resolve the Buckaroo Client SDK url for the current environment.
     *
     * When PayPal Express runs in test mode the sandbox SDK build is loaded
     * (testcheckout), which uses the PayPal sandbox client ids. Otherwise
     * the live endpoint is used, so live stores are unaffected.
     */
    getSdkUrl()
    {
        const base = this.isPaypalExpressTestMode()
            ? "https://testcheckout.buckaroo.nl"
            : "https://checkout.buckaroo.nl";

        return `${base}/api/buckaroosdk/script/en-US`;
    }

    /**
     * Whether a PayPal Express button on the current page is configured
     * to run in test (sandbox) mode.
     */
    isPaypalExpressTestMode()
    {
        const element = document.querySelector('[data-paypal-express-plugin-options]');
        if (!element) {
            return false;
        }

        try {
            const options = JSON.parse(element.getAttribute('data-paypal-express-plugin-options'));
            return options.isTestMode === true;
        } catch (e) {
            return false;
        }
    }
    loadJquery()
    {

        if ((typeof jQuery == 'undefined') || (typeof jQuery.ajax == 'undefined')) {
            return new Promise((resolve) => {
                var script = document.createElement("script");
                script.src = "https://code.jquery.com/jquery-3.2.1.min.js";
                script.async = true;
                document.head.appendChild(script);
                script.onload = () => {
                    resolve();
                };
            });
        } else {
            return Promise.resolve();
        }
    }

    init()
    {
        this.loadJquery().then(() => {
            document.$emitter.publish('buckaroo_scripts_jquery_loaded', {loaded: true});
            this.loadSdk().then(() => {
                document.$emitter.publish('buckaroo_scripts_loaded', {loaded: true});
            })
        })
    }

}