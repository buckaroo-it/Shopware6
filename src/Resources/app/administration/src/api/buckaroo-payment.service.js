const { Application } = Shopware;
const ApiService = Shopware.Classes.ApiService;

class BuckarooPaymentService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'buckaroo') {
        super(httpClient, loginService, apiEndpoint);
    }

    refundPayment(transaction, amount) {
        const apiRoute = `_action/${this.getApiBasePath()}/refund`;

        return this.httpClient.post(
            apiRoute,
            {
                transaction: transaction,
                amount: amount
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }
}

Application.addServiceProvider('BuckarooPaymentService', (container) => {
    const initContainer = Application.getContainer('init');
    return new BuckarooPaymentService(initContainer.httpClient, container.loginService);
});

