<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Buckaroo\Shopware6\PaymentMethods\IdealQr;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Buckaroo\Shopware6\Entity\IdealQrOrder\IdealQrOrderRepository;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;

class IdealQrPaymentHandler extends PaymentHandlerSimple
{
    public const IDEAL_QR_INVOICE_PREFIX = 'iQR';

    public string $paymentClass = IdealQr::class;

    protected ?int $invoice = null;

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
     * Create the iDEAL QR order, its invoice is the QR purchase id
     */
    protected function beforePayLegacy(
        \Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void {
        $this->createIdealQrOrder($transaction->getOrderTransaction(), $salesChannelContext->getContext());
    }

    /**
     * Create the iDEAL QR order, its invoice is the QR purchase id
     */
    protected function beforePayModern(
        PaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        Context $context
    ): void {
        $this->createIdealQrOrder(
            $this->asyncPaymentService->getTransaction($transaction->getOrderTransactionId(), $context),
            $context
        );
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
    public function getMethodPayload(
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {

        if ($this->invoice === null) {
            throw new \RuntimeException('Cannot create iDEAL QR order');
        }

        // The fee is already applied to the order total before the payload is built
        $amount = (new PaymentFeeCalculator($this->asyncPaymentService))->getOrderTotalWithFee(
            $order,
            $salesChannelContext->getSalesChannelId(),
            $paymentCode
        );

        $expiration = (new \DateTime('now', new \DateTimeZone('Europe/Amsterdam')))
            ->add(new \DateInterval("P1D"))->format('Y-m-d H:i:s');
        return [
            'imageSize' => '1000',
            'purchaseId' => self::IDEAL_QR_INVOICE_PREFIX . $this->invoice,
            'isOneOff' => true,
            'amount' => $amount,
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
    public function getMethodAction(
        RequestDataBag $dataBag,
        ?SalesChannelContext $salesChannelContext = null,
        ?string $paymentCode = null
    ): string {
        return 'generate';
    }

    /**
     * @param mixed $orderTransaction
     */
    protected function handleResponse(
        ClientResponseInterface $response,
        $orderTransaction,
        OrderEntity $order,
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

    private function createIdealQrOrder(?OrderTransactionEntity $orderTransaction, Context $context): void
    {
        // The handler is a shared service, never reuse the invoice of an earlier payment
        $this->invoice = null;
        if ($orderTransaction === null) {
            return;
        }

        $entity = $this->idealQrRepository->create($orderTransaction, $context);
        if ($entity !== null) {
            $this->invoice = $entity->getInvoice();
        }
    }
}
