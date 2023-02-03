const { Application } = Shopware;
const ApiService = Shopware.Classes.ApiService;

class BuckarooPaymentService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'buckaroo')
    {
        super(httpClient, loginService, apiEndpoint);
    }

    getBuckarooTransaction(transaction)
    {
        const apiRoute = `_action / ${this.getApiBasePath()} / getBuckarooTransaction`;

        return this.httpClient.post(
            apiRoute,
            {
                transaction: transaction
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    refundPayment(transaction, transactionsToRefund, orderItems, customRefundAmount)
    {
        const apiRoute = `_action / ${this.getApiBasePath()} / refund`;

        return this.httpClient.post(
            apiRoute,
            {
                transaction: transaction,
                transactionsToRefund: transactionsToRefund,
                orderItems: orderItems,
                customRefundAmount: customRefundAmount
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    captureOrder(transaction, transactionsToRefund, orderItems)
    {
        const apiRoute = `_action / ${this.getApiBasePath()} / capture`;

        return this.httpClient.post(
            apiRoute,
            {
                transaction: transaction
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    createPaylink(transaction, transactionsToRefund, orderItems)
    {
        const apiRoute = `_action / ${this.getApiBasePath()} / paylink`;

        return this.httpClient.post(
            apiRoute,
            {
                transaction: transaction
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

