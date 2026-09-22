/**
 * POST helper replacing Shopware's HttpClient service.
 *
 * `src/service/http-client.service` is deprecated in 6.7 and removed in 6.8, while
 * native fetch is available in every browser the storefront supports. Using fetch
 * directly therefore works unchanged across 6.5 - 6.8.
 *
 * The callback contract is kept identical to HttpClient.post() so the existing call
 * sites need no changes: the callback receives the raw response text, and it is also
 * invoked when the request fails, because HttpClient fired its callback on `loadend`
 * rather than only on success. Callers already guard their JSON.parse accordingly.
 *
 * The request headers mirror HttpClient too - `X-Requested-With` so Shopware treats
 * the call as XHR, and a JSON content type unless the body is FormData, in which case
 * the browser has to set the multipart boundary itself.
 *
 * @param {string} url
 * @param {string|FormData|null} data
 * @param {function(string, Response|null)} [callback]
 *
 * @returns {Promise<void>}
 */
export function post(url, data, callback) {
    const headers = {
        'X-Requested-With': 'XMLHttpRequest',
    };

    if (!(data instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
    }

    return fetch(url, {
        method: 'POST',
        headers: headers,
        body: data,
        // XMLHttpRequest sent same-origin cookies by default; be explicit so the
        // session is carried on browsers whose fetch default is still 'omit'.
        credentials: 'same-origin',
    })
        .then((response) => response.text().then((text) => {
            if (callback) {
                callback(text, response);
            }
        }))
        .catch((error) => {
            console.warn(`the request to ${url} failed`, error);

            if (callback) {
                callback('', null);
            }
        });
}
