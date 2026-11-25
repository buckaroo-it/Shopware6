<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Alipay;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class AlipayPaymentHandler extends PaymentHandlerSimple
{
    public string $paymentClass = Alipay::class;

    /**
     * Get parameters for specific payment method
     *
     * @return array<mixed>
     */
    public function getMethodPayload(
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {
        return [
            'useMobileView' => $this->asyncPaymentService->isMobile(Request::createFromGlobals()) ? 'true' : 'false',
        ];
    }
}
