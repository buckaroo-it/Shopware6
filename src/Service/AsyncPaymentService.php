<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Buckaroo\Shopware6\Service\PaymentStateService;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Buckaroo\Shopware6\Service\Buckaroo\ClientService;
use Buckaroo\Shopware6\Service\StateTransitionService;
use Shopware\Core\Framework\Event\ShopwareSalesChannelEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class AsyncPaymentService
{
    public function __construct(
        public SettingsService $settingsService,
        public UrlService $urlService,
        public StateTransitionService $stateTransitionService,
        public ClientService $clientService,
        public CheckoutHelper $checkoutHelper,
        public TransactionService $transactionService,
        public LoggerInterface $logger,
        public FormatRequestParamService $formatRequestParamService,
        public PaymentStateService $paymentStateService,
        protected EventDispatcherInterface $eventDispatcher,
        protected CancelPaymentService $cancelPaymentService,
        protected EntityRepository $orderTransactionRepository,
        protected SalesChannelContextService $salesChannelContextService,
        private PaymentServiceDecorator $paymentServiceDecorator
    ) {
    }



    public function getSalesChannelContext(Context $context, string $salesChannelId, string $token): SalesChannelContext
    {
        return $this->salesChannelContextService->get(
            $salesChannelId,
            $token,
            $context
        );
    }
    public function getCustomer(OrderEntity $order): OrderCustomerEntity
    {
        $customer = $order->getOrderCustomer();
        if ($customer === null) {
            throw new \InvalidArgumentException('Customer cannot be null');
        }
        return $customer;
    }

    public function getBillingAddress(OrderEntity $order): OrderAddressEntity
    {
        $address = $order->getBillingAddress();
        if ($address === null) {
            throw new \InvalidArgumentException('Billing address cannot be null');
        }
        return $address;
    }

    public function getShippingAddress(OrderEntity $order): OrderAddressEntity
    {
        $deliveries = $order->getDeliveries();

        if ($deliveries === null) {
            throw new \InvalidArgumentException('Deliveries cannot be null');
        }

        $address = $deliveries->getShippingAddress()?->first();
        if ($address === null) {
            $address = $this->getBillingAddress($order);
        }

        return $address;
    }

    public function getCountry(OrderAddressEntity $orderAddress): CountryEntity
    {
        $country = $orderAddress->getCountry();
        if ($country === null) {
            throw new \InvalidArgumentException('Shipping country cannot be null');
        }
        return $country;
    }

    public function getCurrency(OrderEntity $order): CurrencyEntity
    {
        $currency = $order->getCurrency();
        if ($currency === null) {
            throw new \InvalidArgumentException('Currency cannot be null');
        }
        return $currency;
    }

    public function dispatchEvent(ShopwareSalesChannelEvent $event): void
    {
        try {
            $this->eventDispatcher->dispatch($event);
        } catch (\Throwable $e) {
            dd('Dispatch failed', $e->getMessage(), $e->getTraceAsString());
        }
    }

    public function isMobile(Request $request): bool
    {
        $useragent = $request->server->get('HTTP_USER_AGENT') ?? '';
        $mobilePattern1 = '/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|' .
            'hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|netfront|opera m(ob|in)i|' .
            'palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|' .
            'up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i';
        
        $mobilePattern2 = '/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|' .
            'al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|' .
            'be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|' .
            'chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|' .
            'ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|' .
            'g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|' .
            'hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|' .
            'iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|' .
            'keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|' .
            'libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|' .
            'mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|' .
            'n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|' .
            'op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|' .
            'pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|' .
            'r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|' .
            'se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|' .
            'so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|' .
            'tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|' .
            'veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|' .
            'w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i';

        return preg_match($mobilePattern1, (string) $useragent) ||
            preg_match($mobilePattern2, substr((string) $useragent, 0, 4)) === 1;
    }
    public function cancelPreviousPayments(PaymentTransactionStruct $transaction, OrderEntity $order): void
    {
        try {
            $this->cancelPaymentService->cancel($transaction, $order);
        } catch (\Throwable $th) {
            $this->logger->error('Failed to cancel previous payments: ' . $th->getMessage(), [
                'transactionId' => $transaction->getOrderTransaction()->getId(),
                'orderId' => $transaction->getOrder()->getId(),
                'exception' => $th
            ]);
        }
    }
    public function getTransaction(string $transactionId, Context $context): ?OrderTransactionEntity
    {
        $criteria = new Criteria([$transactionId]);

        $criteria->addAssociation('order');
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('order.salesChannel');
        $criteria->addAssociation('order.orderCustomer');
        $criteria->addAssociation('order.orderCustomer.customer');
        $criteria->addAssociation('order.billingAddress');
        $criteria->addAssociation('order.deliveries');
        $criteria->addAssociation('order.deliveries.shippingAddress');
        $criteria->addAssociation('order.transactions');
        $criteria->addAssociation('order.lineItems');
        $criteria->addAssociation('order.deliveries.shippingAddress.country');
        $criteria->addAssociation('order.billingAddress.country');
        $criteria->addAssociation('order.orderCustomer');

        /** @var OrderTransactionEntity|null $result */
        $result = $this->orderTransactionRepository->search($criteria, $context)->first();

        return $result;
    }

    public function updateTransactionState(string $transactionId, string $state, Context $context): void
    {
        try {
            $this->stateTransitionService->transitionPaymentState(
                $state,
                $transactionId,
                $context
            );
        } catch (\Throwable $th) {
            $this->logger->error('Failed to update transaction state: ' . $th->getMessage(), [
                'transactionId' => $transactionId,
                'state' => $state,
                'exception' => $th
            ]);
            throw $th;
        }
    }

    public function isTransactionInFinalState(OrderTransactionEntity $transaction): bool
    {
        $state = $transaction->getStateMachineState();
        if ($state === null) {
            return false;
        }

        return in_array($state->getTechnicalName(), [
            OrderTransactionStates::STATE_PAID,
            OrderTransactionStates::STATE_CANCELLED,
            OrderTransactionStates::STATE_FAILED,
            OrderTransactionStates::STATE_REFUNDED
        ]);
    }
}
