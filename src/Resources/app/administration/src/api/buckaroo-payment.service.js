const { ApiService } = Shopware.Classes;

class BuckarooPaymentService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'buckaroo')
    {
        super(httpClient, loginService, apiEndpoint);
    }

    getBuckarooTransaction(transaction)
    {
        const apiRoute = `_action/${this.getApiBasePath()}/getBuckarooTransaction`;

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
        const apiRoute = `_action/${this.getApiBasePath()}/refund`;

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

    captureOrder(transaction)
    {
        const apiRoute = `_action/${this.getApiBasePath()}/capture`;

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

    createPaylink(transaction)
    {
        const apiRoute = `_action/${this.getApiBasePath()}/paylink`;

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

Shopware.Service().register('BuckarooPaymentService', () => {
    const initContainer = Shopware.Application.getContainer('init');
    // Ensure we use the global loginService which always exists in admin
    const loginService = Shopware.Service('loginService');
    return new BuckarooPaymentService(initContainer.httpClient, loginService);
});

