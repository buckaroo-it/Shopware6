<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\PaymentMethods\IdealQr;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Buckaroo\Shopware6\Entity\IdealQrOrder\IdealQrOrderRepository;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;

class IdealQrPaymentHandler extends AsyncPaymentHandler
{
    public const IDEAL_QR_INVOICE_PREFIX = 'iQR';

    protected string $paymentClass = IdealQr::class;

    protected int $invoice;

    protected IdealQrOrderRepository $idealQrRepository;

    /**
     * Buckaroo constructor.
     */
    public function __construct(
        AsyncPaymentService $asyncPaymentService,
        IdealQrOrderRepository $idealQrRepository
    ) {
        parent::__construct($asyncPaymentService);
        $this->idealQrRepository = $idealQrRepository;
    }

    /**
     * Override parameters common, remove invoice field
     *
     * @param mixed $orderTransaction
     * @param OrderEntity $order
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentCode
     * @param string|null $returnUrl
     *
     * @return array<mixed>
     */
    protected function getCommonRequestPayload(
        $orderTransaction,
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode,
        ?string $returnUrl
    ): array {
        $this->createIdealQrOrder($orderTransaction, $order, $salesChannelContext);
        $payload = parent::getCommonRequestPayload(
            $orderTransaction,
            $order,
            $dataBag,
            $salesChannelContext,
            $paymentCode,
            $returnUrl
        );
        unset($payload['invoice']);
        return $payload;
    }
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
    protected function getMethodPayload(
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {

        $fee =  $this->getFee($paymentCode, $salesChannelContext->getSalesChannelId());

        $expiration = (new \DateTime('now', new \DateTimeZone('Europe/Amsterdam')))
            ->add(new \DateInterval("P1D"))->format('Y-m-d H:i:s');
        return [
            'imageSize' => '1000',
            'purchaseId' => self::IDEAL_QR_INVOICE_PREFIX . $this->invoice,
            'isOneOff' => true,
            'amount' => $order->getAmountTotal() + $fee,
            'amountIsChangeable' => false,
            'expiration' => $expiration,
            'isProcessing' => false,
        ];
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
    protected function getMethodAction(
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): string {
        return 'generate';
    }

    protected function handleResponse(
        ClientResponseInterface $response,
        $orderTransaction,
        $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): RedirectResponse {

        $redirect = parent::handleResponse(
            $response,
            $orderTransaction,
            $order,
            $dataBag,
            $salesChannelContext,
            $paymentCode
        );

        $serviceParameters = $response->getServiceParameters();
        if (
            $response->isSuccess() &&
            isset($serviceParameters['qrimageurl']) &&
            is_string($serviceParameters['qrimageurl'])
        ) {
            return new RedirectResponse(
                $this->getReturnPageUrl(
                    $serviceParameters['qrimageurl'],
                    $response->getTransactionKey(),
                    $order->getId()
                )
            );
        }

        return $redirect;
    }

    private function getReturnPageUrl(string $qrImage, string $transactionKey, string $orderId): string
    {
        return  $this->asyncPaymentService
            ->urlService
            ->forwardToRoute('frontend.action.buckaroo.ideal.qr', [
                'qrImage' => $qrImage,
                'transactionKey' => $transactionKey,
                'orderId' => $orderId
            ]);
    }

    private function createIdealQrOrder(
        $orderTransaction,
        OrderEntity $order,
        SalesChannelContext $salesChannelContext
    ): void {
        $entity = $this->idealQrRepository->create($orderTransaction, $salesChannelContext);
        if ($entity !== null) {
            $this->invoice = $entity->getInvoice();
        }
    }
}
