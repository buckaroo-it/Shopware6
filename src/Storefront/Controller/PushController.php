<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Buckaroo\Shopware6\Entity\IdealQrOrder\IdealQrOrderEntity;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Service\InvoiceService;
use Symfony\Component\Routing\Annotation\Route;
use Buckaroo\Shopware6\Events\PushProcessingEvent;
use Buckaroo\Shopware6\Service\TransactionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Buckaroo\Shopware6\Handlers\IdealQrPaymentHandler;
use Buckaroo\Shopware6\Service\StateTransitionService;
use Buckaroo\Shopware6\Helpers\Constants\ResponseStatus;
use Shopware\Storefront\Controller\StorefrontController;
use Buckaroo\Shopware6\Service\SignatureValidationService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Buckaroo\Shopware6\Entity\IdealQrOrder\IdealQrOrderRepository;
use Buckaroo\Shopware6\Events\PushPaymentStateChangeEvent;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\StateMachine\Exception\StateMachineNotFoundException;
use Shopware\Core\System\StateMachine\Exception\StateMachineStateNotFoundException;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Buckaroo\Shopware6\Service\OrderService;
use Buckaroo\Shopware6\Service\CustomerService;

class PushController extends StorefrontController
{

    public const AUTHORIZE_REQUESTS = [
        'I872',
        'V054',
        'I872',
        'I876',
        'I880',
        'I038',
        'I072',
        'I069',
        'I108'
    ];

    private LoggerInterface $logger;

    private CheckoutHelper $checkoutHelper;

    protected SignatureValidationService $signatureValidationService;

    protected TransactionService $transactionService;

    protected StateTransitionService $stateTransitionService;

    protected InvoiceService $invoiceService;

    protected EventDispatcherInterface $eventDispatcher;

    protected IdealQrOrderRepository $idealQrRepository;

    protected OrderService $orderService;
    protected CustomerService $customerService;

    public function __construct(
        SignatureValidationService $signatureValidationService,
        TransactionService $transactionService,
        StateTransitionService $stateTransitionService,
        InvoiceService $invoiceService,
        CheckoutHelper $checkoutHelper,
        LoggerInterface $logger,
        EventDispatcherInterface $eventDispatcher,
        IdealQrOrderRepository $idealQrRepository,
        OrderService $orderService,
        CustomerService $customerService,
    ) {
        $this->signatureValidationService = $signatureValidationService;
        $this->transactionService         = $transactionService;
        $this->stateTransitionService     = $stateTransitionService;
        $this->invoiceService        = $invoiceService;
        $this->checkoutHelper        = $checkoutHelper;
        $this->logger                = $logger;
        $this->eventDispatcher       = $eventDispatcher;
        $this->idealQrRepository     = $idealQrRepository;
        $this->orderService = $orderService;
        $this->customerService = $customerService;
    }

    /**
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return JsonResponse
     */
    #[Route(path: "/buckaroo/push", defaults: ['_routeScope' => ['storefront']], options: ["seo" => false], name: "buckaroo.payment.push", methods: ["POST"])]
    public function pushBuckaroo(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {


        $this->logger->info(__METHOD__ . "|1|", [$_POST]);

        $status             = (string)$request->request->get('brq_statuscode');
        $context            = $salesChannelContext->getContext();

        $brqAmount          = (float)$request->request->get('brq_amount');
        $brqOrderId         = (string)$request->request->get('ADD_orderId');
        if ($request->request->has('brq_AdditionalParameters_orderId')) {
            $brqOrderId = (string)$request->request->get('brq_AdditionalParameters_orderId');
        }

        $brqAmountCredit    = (float)$request->request->get('brq_amount_credit', 0);
        $brqInvoicenumber   = (string)$request->request->get('brq_invoicenumber');
        $orderTransactionId = (string)$request->request->get('ADD_orderTransactionId');
        if ($request->request->has('brq_AdditionalParameters_orderTransactionId')) {
            $orderTransactionId = (string)$request->request->get('brq_AdditionalParameters_orderTransactionId');
        }

        $brqTransactionType = (string)$request->request->get('brq_transaction_type');
        $paymentMethod      = (string)$request->request->get('brq_primary_service');
        $mutationType       = (string)$request->request->get('brq_mutationtype');
        $brqPaymentMethod   = (string)$request->request->get('brq_transaction_method');
        $originalTransactionKey   = (string)$request->request->get('brq_transactions');
        $salesChannelId     =  $salesChannelContext->getSalesChannelId();

        if ($this->isIdealQrRequest($request)) {
            $entity = $this->getIdealQrEntity($request, $salesChannelContext);
            if ($entity !== null) {
                $brqOrderId = $entity->getOrderId();
                $orderTransactionId = $entity->getOrderTransactionId();
            }
        }

        if (empty($brqOrderId) || empty($orderTransactionId)) {
            return $this->response('buckaroo.messages.paymentError', false);
        }

        if (!$this->signatureValidationService->validateSignature(
            $request,
            $salesChannelId
        ) && !$this->isIdealFastCheckout($request)) {
            $this->logger->info(__METHOD__ . "|5|");
            return $this->response('buckaroo.messages.signatureIncorrect', false);
        }

        if ($this->isIdealFastCheckout($request)) {
            $this->updateIdealFastCheckout($request, $salesChannelContext);
        }
        // Handle event
        $event = new PushProcessingEvent(
            $request,
            $salesChannelContext,
        );
        $this->eventDispatcher->dispatch($event);

        if (!$event->canContinue()) {
            return $this->response('buckaroo.messages.pushInterrupted');
        }
        // end handle event

        if (
            in_array($brqTransactionType, self::AUTHORIZE_REQUESTS)
        ) {
            $this->setStatusAuthorized($orderTransactionId, $salesChannelContext, $request, $status);
        }

        //skip mutationType Informational except for group transactions
        if (
            $mutationType == ResponseStatus::BUCKAROO_MUTATION_TYPE_INFORMATIONAL &&
            $brqTransactionType !== "I150"
        ) {
            $this->logger->info(__METHOD__ . "|5.1|");
            $data = [
                'originalTransactionKey' => $originalTransactionKey,
                'brqPaymentMethod'       => $brqPaymentMethod,
                'brqInvoicenumber'       => $brqInvoicenumber,
            ];
            $this->transactionService->saveTransactionData($orderTransactionId, $context, $data);

            return $this->response('buckaroo.messages.skipInformational');
        }
        if (
            !empty($request->request->get('brq_transaction_method'))
            && ($request->request->get('brq_transaction_method') === 'paypal')
            && ($status == ResponseStatus::BUCKAROO_STATUSCODE_PENDING_PROCESSING)
        ) {
            $status = ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_USER;
        }

        $order = $this->checkoutHelper->getOrderById($brqOrderId, $context);

        if ($order === null) {
            return $this->response('buckaroo.messages.paymentError', false);
        }

        if (!$this->checkDuplicatePush($order, $orderTransactionId, $context)) {
            return $this->response('buckaroo.messages.pushAlreadySend', false);
        }

        if ($brqTransactionType != ResponseStatus::BUCKAROO_AUTHORIZE_TYPE_GROUP_TRANSACTION) {
            $this->logger->info(__METHOD__ . "|10|");
            $this->checkoutHelper->saveBuckarooTransaction($request);
        }

        $totalPrice = $order->getPrice()->getTotalPrice();

        //Check if the push is a refund request or cancel authorize
        if ($brqAmountCredit > 0) {
            $this->logger->info(__METHOD__ . "|15|", [$brqAmountCredit]);
            if (
                $status != ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS &&
                $brqTransactionType == ResponseStatus::BUCKAROO_AUTHORIZE_TYPE_CANCEL
            ) {
                $this->logger->info(__METHOD__ . "|20|");
                return $this->response('buckaroo.messages.paymentCancelled');
            }

            $alreadyRefunded = 0;
            if ($orderTransaction = $this->transactionService->getOrderTransactionById(
                $context,
                $orderTransactionId
            )) {
                $this->logger->info(__METHOD__ . "|21|");
                $customFields = $orderTransaction->getCustomFields() ?? [];
                if (!empty($customFields['alreadyRefunded'])) {
                    $this->logger->info(__METHOD__ . "|22|");
                    $alreadyRefunded = $customFields['alreadyRefunded'];
                }
            }

            $this->logger->info(
                __METHOD__ . "|23|",
                [
                    $brqAmountCredit,
                    $alreadyRefunded,
                    $brqAmountCredit + $alreadyRefunded,
                    $totalPrice
                ]
            );

            $status = $this->checkoutHelper->areEqualAmounts($brqAmountCredit + $alreadyRefunded, $totalPrice)
                ? 'refunded'
                : 'partial_refunded';

            $this->logger->info(__METHOD__ . "|25|", [$status]);
            $this->transactionService->saveTransactionData(
                $orderTransactionId,
                $context,
                [$status => 1, 'alreadyRefunded' => $brqAmountCredit + $alreadyRefunded]
            );

            $this->stateTransitionService
                ->transitionPaymentState(
                    $status,
                    $orderTransactionId,
                    $context
                );

            return $this->response('buckaroo.messages.refundSuccessful');
        }

        //skip any giftcard pushes
        if ($request->request->has('brq_relatedtransaction_partialpayment')) {
            return $this->response('buckaroo.messages.giftcards.skippedPush');
        }

        if ($status == ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS) {
            $this->logger->info(__METHOD__ . "|30|");
            try {
                if ($this->stateTransitionService->isOrderState($order, ['cancel'])) {
                    $this->logger->info(__METHOD__ . "|35|");
                    $this->stateTransitionService->changeOrderStatus($order, $context, 'reopen');
                }

                if ($this->stateTransitionService->isTransitionPaymentState(
                    ['refunded', 'partial_refunded'],
                    $orderTransactionId,
                    $context
                )) {
                    $this->logger->info(__METHOD__ . "|40|");
                    return $this->response('buckaroo.messages.paymentUpdatedEarlier');
                }

                $customFields = $this->transactionService->getCustomFields($order, $context);
                $paymentSuccesStatus = $this->getPaymentSuccessStatus($salesChannelId);
                $alreadyPaid = round($brqAmount + ($customFields['alreadyPaid'] ?? 0), 2);
                $paymentState        = ($alreadyPaid >= round($totalPrice, 2)) ? $paymentSuccesStatus : "pay_partially";
                $data                = [];
                if ($paymentMethod && (strtolower($paymentMethod) == 'klarnakp')) {
                    $this->logger->info(__METHOD__ . "|42|");
                    $paymentState              = 'authorize';
                    $data['reservationNumber'] = $request->request->get('brq_SERVICE_klarnakp_ReservationNumber');
                    $originalTransactionKey = $request->request->get('brq_SERVICE_klarnakp_AutoPayTransactionKey');
                }
                $this->logger->info(__METHOD__ . "|45|", [$paymentState, $brqAmount, $totalPrice]);

                $this->setPaymentState(
                    $paymentState,
                    $orderTransactionId,
                    $salesChannelContext,
                    $request
                );

                $paymentMethodCode = $paymentMethod ? $paymentMethod : $request->request->get('brq_transaction_method');
                $data = array_merge($data, [
                    'originalTransactionKey' => $originalTransactionKey,
                    'brqPaymentMethod'       => $paymentMethodCode,
                    'alreadyPaid' => $alreadyPaid,
                ]);

                $this->transactionService->saveTransactionData(
                    $orderTransactionId,
                    $context,
                    $data
                );

                $orderStatus = $this->checkoutHelper->getSettingsValue('orderStatus', $salesChannelId);
                if (is_string($orderStatus)) {
                    if ($orderStatus == 'complete') {
                        $orderStatus = 'process';
                    }
                    $this->stateTransitionService->changeOrderStatus($order, $context, $orderStatus);
                }

                $this->logger->info(__METHOD__ . "|50.1|");
                if (
                    !$this->invoiceService->isInvoiced($brqOrderId, $context) &&
                    !$this->invoiceService->isCreateInvoiceAfterShipment(
                        $brqTransactionType,
                        false,
                        $salesChannelId
                    )
                ) {
                    $this->logger->info(__METHOD__ . "|50.2|");
                    if (round($brqAmount, 2) == round($totalPrice, 2)) {
                        $this->invoiceService->generateInvoice(
                            $order,
                            $context,
                            $salesChannelId
                        );
                    }
                }
            } catch (
                InconsistentCriteriaIdsException | IllegalTransitionException | StateMachineNotFoundException
                | StateMachineStateNotFoundException $exception
            ) {
                $this->logger->info(__METHOD__ . "|55|");
                throw PaymentException::asyncProcessInterrupted(
                    $orderTransactionId,
                    $exception->getMessage()
                );
            }
            $this->logger->info(__METHOD__ . "|60|");
            return $this->response('buckaroo.messages.paymentUpdated');
        }

        if (in_array(
            $status,
            [
                ResponseStatus::BUCKAROO_STATUSCODE_TECHNICAL_ERROR,
                ResponseStatus::BUCKAROO_STATUSCODE_VALIDATION_FAILURE,
                ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_MERCHANT,
                ResponseStatus::BUCKAROO_STATUSCODE_FAILED,
                ResponseStatus::BUCKAROO_STATUSCODE_REJECTED,
                ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_USER
            ]
        )) {
            if (
                $this->stateTransitionService->isTransitionPaymentState(
                    ['paid', 'pay_partially'],
                    $orderTransactionId,
                    $context
                ) ||
                $this->stateTransitionService->isOrderPaid($order)
            ) {
                $this->logger->info(__METHOD__ . '|Push ignored because order is already paid');
                return $this->response('buckaroo.messages.skippedPush');
            }

            $this->setPaymentState("fail", $orderTransactionId, $salesChannelContext, $request);

            $paymentSuccesStatus = $this->getCancelOpenOrderSetting($salesChannelId);
            
            if ($paymentSuccesStatus == 'enabled') {
                $this->stateTransitionService->changeOrderStatus($order, $context, 'cancel');
                $this->stateTransitionService->changeDeliveryStatus($order, $context, 'cancel');
            }

            return $this->response('buckaroo.messages.orderCancelled');
        }

        if ($status == ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_USER) {
            if (
                $this->stateTransitionService->isTransitionPaymentState(
                    ['paid', 'pay_partially'],
                    $orderTransactionId,
                    $context
                ) ||
                $this->stateTransitionService->isOrderPaid($order)
            ) {
                $this->logger->info(__METHOD__ . '|Push ignored because order is already paid');
                return $this->response('buckaroo.messages.skippedPush');
            }
        }

        return $this->response('buckaroo.messages.paymentError', false);
    }

    private function getSuccessAuthorizeStatus(Request $request, string $salesChannelId): string
    {
        if (
            in_array(
                $request->request->get('brq_transaction_method'),
                ['afterpay', 'afterpaydigiaccept']
            )
        ) {
            $captureOnShipment = $this->checkoutHelper->getSettingsValue('afterpayCaptureonshippent', $salesChannelId);
            if ($captureOnShipment) {
                $status = $this->checkoutHelper->getSettingsValue('afterpayPaymentstatus', $salesChannelId);
                return $status !== null && is_scalar($status) ? (string)$status : "authorize";
            }
        }

        return "authorize";
    }

    private function setStatusAuthorized(
        string $orderTransactionId,
        SalesChannelContext $salesChannelContext,
        Request $request,
        string $status
    ): void {
        if ($this->stateTransitionService->isTransitionPaymentState(
            ['paid', 'pay_partially'],
            $orderTransactionId,
            $salesChannelContext->getContext()
        )) {
            return;
        }

        $orderStatus = null;
        if ($status == ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS) {
            $orderStatus = $this->getSuccessAuthorizeStatus(
                $request,
                $salesChannelContext->getSalesChannelId()
            );
        }

        if (in_array(
            $status,
            [
                ResponseStatus::BUCKAROO_STATUSCODE_TECHNICAL_ERROR,
                ResponseStatus::BUCKAROO_STATUSCODE_VALIDATION_FAILURE,
                ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_MERCHANT,
                ResponseStatus::BUCKAROO_STATUSCODE_FAILED,
                ResponseStatus::BUCKAROO_STATUSCODE_REJECTED
            ]
        )) {
            $orderStatus = "fail";
        }

        if ($orderStatus !== null) {
            $this->setPaymentState($orderStatus, $orderTransactionId, $salesChannelContext, $request);
        }
    }

    private function setPaymentState(
        string $state,
        string $orderTransactionId,
        SalesChannelContext $salesChannelContext,
        Request $request
    ): void {
        $this->stateTransitionService->transitionPaymentState(
            $state,
            $orderTransactionId,
            $salesChannelContext->getContext()
        );
        $this->eventDispatcher->dispatch(
            new PushPaymentStateChangeEvent(
                $request,
                $salesChannelContext,
                $state
            )
        );
    }

    private function getPaymentSuccessStatus(string $salesChannelId): string
    {
        $status = $this->checkoutHelper->getSettingsValue('paymentSuccesStatus', $salesChannelId);
        if ($status !== null && is_string($status)) {
            return $status;
        }
        return "completed";
    }
    
    private function getCancelOpenOrderSetting(string $salesChannelId): string
    {
        $status = $this->checkoutHelper->getSettingsValue('automaticallyCloseOpenOrders', $salesChannelId);
        if ($status !== null && is_string($status)) {
            return $status;
        }

        return "disabled";
    }

    private function response(
        string $message,
        bool $status = true
    ): JsonResponse {
        return $this->json(['status' => $status, 'message' => $this->trans($message)]);
    }

    private function checkDuplicatePush(
        OrderEntity $order,
        string $orderTransactionId,
        Context $context
    ): bool {
        $rand = range(0, 6, 2);
        shuffle($rand);
        usleep(array_shift($rand) * 1000000);
        $postData = $_POST;
        $calculated = $this->signatureValidationService->calculatePushHash($postData);
        $this->logger->info(__METHOD__ . "|calculated|" . $calculated);

        $customFields = $this->transactionService->getCustomFields($order, $context);
        $pushHash = isset($customFields['pushHash']) ? $customFields['pushHash'] : '';

        $this->logger->info(__METHOD__ . "|pushHash|" . $pushHash);
        $customFields['pushHash'] = $calculated;
        $this->transactionService->updateTransactionCustomFields($orderTransactionId, $customFields);
        if ($pushHash == $calculated) {
            $this->logger->info(__METHOD__ . "|pushHash == calculated|");
            return false;
        }

        return true;
    }

    protected function isIdealQrRequest(Request $request): bool
    {
        $invoice = $request->request->get('brq_invoicenumber');
        return is_string($invoice) && strpos($invoice, IdealQrPaymentHandler::IDEAL_QR_INVOICE_PREFIX) !== false;
    }

    protected function isIdealFastCheckout(Request $request): bool
    {
        $transactionFlow = $request->request->get('brq_SERVICE_ideal_TransactionFlow');
        return $transactionFlow === 'Fast_Checkout';
    }

    protected function getIdealQrEntity(Request $request, SalesChannelContext $salesChannelContext): ?IdealQrOrderEntity
    {
        if (!is_scalar($request->request->get('brq_invoicenumber'))) {
            return null;
        }

        $invoice = str_replace(IdealQrPaymentHandler::IDEAL_QR_INVOICE_PREFIX, "", (string)$request->request->get('brq_invoicenumber'));
        return $this->idealQrRepository->findByInvoice((int)$invoice, $salesChannelContext);
    }

    /**
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     */
    private function updateIdealFastCheckout(Request $request, SalesChannelContext $salesChannelContext): void
    {
        try {
            $orderTransactionId = (string)($request->request->get('ADD_orderTransactionId') ??
                $request->request->get('brq_AdditionalParameters_orderTransactionId'));

            if (empty($orderTransactionId)) {
                $this->logger->warning(__METHOD__ . '|Missing orderTransactionId for Ideal Fast Checkout');
                return;
            }

            $context = $salesChannelContext->getContext();
            $order = $this->orderService
                ->setSaleChannelContext($salesChannelContext)
                ->getOrderById(
                    (string)($request->request->get('ADD_orderId') ??
                        $request->request->get('brq_AdditionalParameters_orderId')),
                    ['transactions', 'orderCustomer', 'addresses', 'deliveries.shippingOrderAddress'],
                    $context
                );

            if (!$order || !$order->getOrderCustomer()) {
                $this->logger->warning(__METHOD__ . '|No customer found for Ideal Fast Checkout order');
                return;
            }

            $customerId = $order->getOrderCustomer()->getCustomerId();
            $customer = null;

            if ($customerId !== null) {
                $customer = $this->customerService
                    ->setSaleChannelContext($salesChannelContext)
                    ->getCustomerById($customerId);
            }

            $countryId = $salesChannelContext->getSalesChannel()->getCountryId();

            $customerData = [
                'first_name'   => urldecode((string)$request->request->get('brq_SERVICE_ideal_ContactDetailsFirstName')),
                'last_name'    => urldecode((string)$request->request->get('brq_SERVICE_ideal_ContactDetailsLastName')),
                'email'        => urldecode((string)$request->request->get('brq_SERVICE_ideal_ContactDetailsEmail')),
                'country_code' => $countryId
            ];

            $billingData = [
                'firstName'   => urldecode((string)$request->request->get('brq_SERVICE_ideal_InvoiceAddressFirstName')),
                'lastName'    => urldecode((string)$request->request->get('brq_SERVICE_ideal_InvoiceAddressLastName')),
                'street'      => urldecode((string)$request->request->get('brq_SERVICE_ideal_InvoiceAddressStreet')),
                'zipcode'     => urldecode((string)$request->request->get('brq_SERVICE_ideal_InvoiceAddressPostalCode')),
                'city'        => urldecode((string)$request->request->get('brq_SERVICE_ideal_InvoiceAddressCity')),
                'company'     => urldecode((string)$request->request->get('brq_SERVICE_ideal_InvoiceAddressCompanyName')),
                'country_code'=> $countryId
            ];

            $shippingData = [
                'firstName'   => urldecode((string)$request->request->get('brq_SERVICE_ideal_ShippingAddressFirstName')),
                'lastName'    => urldecode((string)$request->request->get('brq_SERVICE_ideal_ShippingAddressLastName')),
                'street'      => urldecode((string)$request->request->get('brq_SERVICE_ideal_ShippingAddressStreet')),
                'zipcode'     => urldecode((string)$request->request->get('brq_SERVICE_ideal_ShippingAddressPostalCode')),
                'city'        => urldecode((string)$request->request->get('brq_SERVICE_ideal_ShippingAddressCity')),
                'company'     => urldecode((string)$request->request->get('brq_SERVICE_ideal_ShippingAddressCompanyName')),
                'country_code'=> $countryId
            ];

            if ($customer != null) {
                $this->customerService
                    ->setSaleChannelContext($salesChannelContext)
                    ->updateDummyCustomerFromPush($order, $customer, $customerData, $billingData, $shippingData, $context);

                $this->orderService->updateOrderAddresses($order, $billingData, $shippingData, $countryId, $context);

                $this->logger->info(__METHOD__ . '|Customer and order addresses updated for Ideal Fast Checkout', [
                    'customerId' => $customer->getId()
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . '|Exception occurred: ' . $e->getMessage());
        }
    }
}
