import Plugin from 'src/plugin-system/plugin.class';

export default class BuckarooLoadScripts extends Plugin {

    loadSdk()
    {
        return new Promise((resolve) => {
            let script = document.createElement("script");
            script.src = "https://checkout.buckaroo.nl/api/buckaroosdk/script/en-US";
            // script.src = "https://testcheckout.buckaroo.nl/api/buckaroosdk/script/en-US";
            script.async = true;
            document.head.appendChild(script);
            script.onload = () => {
                resolve();
            };
        })
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