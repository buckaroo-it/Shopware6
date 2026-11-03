<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Buckaroo\Client;
use Buckaroo\Shopware6\PaymentMethods\Bancontact;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class BancontactPaymentHandler extends PaymentHandlerSimple
{
    /**
     * Buckaroo service the Hosted Fields session token is paid with
     */
    public const HOSTED_FIELDS_SERVICE = 'bancontactmrcash';

    public const HOSTED_FIELDS_ACTION = 'payWithToken';

    public const HOSTED_FIELDS_SETTING = 'bancontactmrcashHostedFields';

    public string $paymentClass = Bancontact::class;

    /**
     * Get parameters for specific payment method
     *
     * @param OrderEntity $order
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentCode
     *
     * @return array<mixed>
     */
    public function getMethodPayload(
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {
        if ($this->isHostedFields($dataBag, $salesChannelContext)) {
            return [
                'name'      => self::HOSTED_FIELDS_SERVICE,
                'sessionId' => $this->getHostedFieldsToken($dataBag)
            ];
        }
        return [];
    }

    /**
     * Get method action for specific payment method
     *
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentCode
     *
     * @return string
     */
    public function getMethodAction(
        RequestDataBag $dataBag,
        ?SalesChannelContext $salesChannelContext = null,
        ?string $paymentCode = null
    ): string {
        if ($this->isHostedFields($dataBag, $salesChannelContext)) {
            return self::HOSTED_FIELDS_ACTION;
        }
        return parent::getMethodAction($dataBag, $salesChannelContext, $paymentCode);
    }

    /**
     * @param Client $client
     * @param string $paymentCode
     * @param SalesChannelContext $salesChannelContext
     * @return void
     */
    protected function configureClient(
        $client,
        string $paymentCode,
        $salesChannelContext
    ): void {
        $this->useCreditCardServiceForToken($client);
    }

    protected function configureLegacyClient(
        Client $client,
        string $paymentCode,
        SalesChannelContext $salesChannelContext
    ): void {
        $this->useCreditCardServiceForToken($client);
    }

    /**
     * The Buckaroo SDK only implements PayWithToken on its credit card method, which
     * takes the service name from the payload ("name"), so a Hosted Fields payment is
     * sent through it as the bancontactmrcash service. The client keeps the Bancontact
     * configuration (credentials, test/live mode), only the SDK method changes.
     */
    private function useCreditCardServiceForToken(Client $client): void
    {
        if ($client->getAction() === self::HOSTED_FIELDS_ACTION) {
            $client->setPaymentCode('creditcard');
        }
    }

    /**
     * A Hosted Fields payment needs a session token and Hosted Fields enabled for the
     * sales channel. The hidden token field is always posted, so an empty one (the order
     * placed without the Hosted Fields submit) and a token posted while Hosted Fields are
     * disabled both keep the standard Bancontact redirect.
     */
    private function isHostedFields(RequestDataBag $dataBag, ?SalesChannelContext $salesChannelContext): bool
    {
        if ($salesChannelContext === null || $this->getHostedFieldsToken($dataBag) === '') {
            return false;
        }

        $enabled = $this->asyncPaymentService->settingsService->getSetting(
            self::HOSTED_FIELDS_SETTING,
            $salesChannelContext->getSalesChannelId()
        );

        return $enabled === true || $enabled === 1 || $enabled === '1' || $enabled === 'true';
    }

    private function getHostedFieldsToken(RequestDataBag $dataBag): string
    {
        $token = $dataBag->get('buckaroo-token');

        return is_string($token) ? trim($token) : '';
    }
}
