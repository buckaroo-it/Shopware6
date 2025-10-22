const { ApiService } = Shopware.Classes;

class BuckarooPaymentSettingsService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'buckaroo')
    {
        super(httpClient, loginService, apiEndpoint);
    }

    getBasicHeaders() {
        if (this.loginService && typeof this.loginService.getToken === 'function') {
            return super.getBasicHeaders();
        }
        return {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        };
    }

    getSupportVersion()
    {
        const apiRoute = `_action/${this.getApiBasePath()}/version`;

        return this.httpClient.post(
            apiRoute,
            {
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    getTaxes()
    {
        const apiRoute = `_action/${this.getApiBasePath()}/taxes`;

        return this.httpClient.post(
            apiRoute,
            {
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    getIn3Icons()
    {
        const apiRoute = `_action/${this.getApiBasePath()}/in3/logos`;

        return this.httpClient.post(
            apiRoute,
            {
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    getApiTest(websiteKeyId, secretKeyId, currentSalesChannelId)
    {
        const apiRoute = `_action/${this.getApiBasePath()}/getBuckarooApiTest`;

        return this.httpClient.post(
            apiRoute,
            {
                websiteKeyId: websiteKeyId,
                secretKeyId: secretKeyId,
                saleChannelId: currentSalesChannelId
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }
}

Shopware.Service().register('BuckarooPaymentSettingsService', () => {
    const initContainer = Shopware.Application.getContainer('init');
    // Ensure we use the global loginService which always exists in admin
    const loginService = Shopware.Service('loginService');
    return new BuckarooPaymentSettingsService(initContainer.httpClient, loginService);
});

