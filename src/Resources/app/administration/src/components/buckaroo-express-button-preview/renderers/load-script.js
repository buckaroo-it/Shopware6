const LOAD_TIMEOUT = 15000;

/**
 * Loader for a payment provider's script, for a renderer's load().
 *
 * The script is added once. A load that failed adds it again on the next call; one that timed out
 * may still load, so the next call waits for the same script instead of adding it a second time.
 *
 * @param {string} name for error messages
 * @param {string|function(): string} url
 * @param {function(): boolean} isLoaded whether the provider's API is ready
 * @param {string} api what isLoaded() waits for, e.g. "paypal.Buttons", for error messages
 * @param {function(): Promise<void>} [whenReady] for a provider whose API is only ready some time
 *     after its script has loaded: waits for it, instead of checking isLoaded() on load. When it
 *     rejects, the script counts as failed, so the next call adds it again.
 * @returns {function(): Promise<void>}
 */
export default function createScriptLoader(name, url, isLoaded, api, whenReady = null) {
    let loading = null;

    // The script as added by the preview; kept while it may still load
    let script = null;

    return function load() {
        if (isLoaded()) {
            return Promise.resolve();
        }

        if (loading) {
            return loading;
        }

        if (!script) {
            script = document.createElement('script');
            script.src = typeof url === 'function' ? url() : url;
            script.async = true;
            document.head.appendChild(script);
        }

        const pending = script;

        loading = new Promise((resolve, reject) => {
            let settled = false;

            const onLoad = () => {
                if (whenReady) {
                    // The script is in: from here whenReady() decides, within its own time limit
                    window.clearTimeout(timeout);
                    whenReady().then(() => done(), error => done(error, true));
                } else if (isLoaded()) {
                    done();
                } else {
                    done(new Error(`${name} script loaded without ${api}`), true);
                }
            };
            const onError = () => done(new Error(`${name} script failed to load`), true);
            const timeout = window.setTimeout(() => done(new Error(`${name} script timed out`), false), LOAD_TIMEOUT);

            function done(error, failed) {
                // Only the first outcome counts, e.g. a load after the timeout
                if (settled) {
                    return;
                }
                settled = true;

                window.clearTimeout(timeout);
                pending.removeEventListener('load', onLoad);
                pending.removeEventListener('error', onError);

                // The next call adds a new script
                if (failed) {
                    pending.remove();
                    script = null;
                }

                if (error) {
                    reject(error);
                } else {
                    resolve();
                }
            }

            pending.addEventListener('load', onLoad);
            pending.addEventListener('error', onError);
        }).catch((error) => {
            // Try again on the next call
            loading = null;
            throw error;
        });

        return loading;
    };
}
