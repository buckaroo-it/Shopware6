const { Application } = Shopware;
const ApiService = Shopware.Classes.ApiService;

class BuckarooPaymentSettingsService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'buckaroo') {
        super(httpClient, loginService, apiEndpoint);
    }

    getSupportVersion() {
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
}

Application.addServiceProvider('BuckarooPaymentSettingsService', (container) => {
    const initContainer = Application.getContainer('init');
    return new BuckarooPaymentSettingsService(initContainer.httpClient, container.loginService);
});

