<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Helpers;

use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\SettingsService;
use Symfony\Component\HttpFoundation\RequestStack;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Buckaroo\Shopware6\Entity\Transaction\BuckarooTransactionEntityRepository;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class CheckoutHelper
{
    private string $shopwareVersion;

    private SettingsService $settingsService;

    private EntityRepository $orderRepository;

    private BuckarooTransactionEntityRepository $buckarooTransactionEntityRepository;

    protected RequestStack $requestStack;

    public function __construct(
        string $shopwareVersion,
        SettingsService $settingsService,
        EntityRepository $orderRepository,
        BuckarooTransactionEntityRepository $buckarooTransactionEntityRepository,
        RequestStack $requestStack
    ) {
        $this->shopwareVersion                     = $shopwareVersion;
        $this->settingsService                     = $settingsService;
        $this->orderRepository                     = $orderRepository;
        $this->buckarooTransactionEntityRepository = $buckarooTransactionEntityRepository;
        $this->requestStack = $requestStack;
    }

    public function getSession(): SessionInterface
    {
        return $this->requestStack->getSession();
    }

    public function getShopwareVersion(): string
    {
        return $this->shopwareVersion;
    }
    /**
     *
     * @param string $orderId
     * @param float $fee
     * @param Context $context
     *
     * @return void
     */
    public function applyFeeToOrder(string $orderId, float $fee, Context $context): void
    {
        $order = $this->getOrderById($orderId, $context);
        if ($order === null) {
            throw new \Exception("A valid order is required", 1);
        }

        $price = $order->getPrice();

        $savedCustomFields = $order->getCustomFields();

        if ($savedCustomFields === null) {
            $savedCustomFields = [];
        }

        $buckarooFee = $fee;

        if (isset($savedCustomFields['buckarooFee'])) {
            $buckarooFee = $buckarooFee - $savedCustomFields['buckarooFee'];
        }
        $savedCustomFields['buckarooFee'] = $fee;

        if ($buckarooFee !== 0) {
            $data = [
                'id'           => $orderId,
                'customFields' => array_merge($savedCustomFields, $savedCustomFields),
                'price' => new CartPrice(
                    $price->getNetPrice() + $buckarooFee,
                    $price->getTotalPrice() + $buckarooFee,
                    $price->getPositionPrice(),
                    $price->getCalculatedTaxes(),
                    $price->getTaxRules(),
                    $price->getTaxStatus()
                )
            ];
            $this->orderRepository->update([$data], Context::createDefaultContext());
        }
    }

    /**
     * Append additional data to order custom fields
     *
     * @param string $orderId
     * @param array<mixed> $customFields
     * @param Context|null $context
     *
     * @return void
     */
    public function appendCustomFields(string $orderId, array $customFields, Context $context = null)
    {
        $order = $this->getOrderById($orderId, $context);
        if ($order === null) {
            throw new \Exception("A valid order is required", 1);
        }

        $savedCustomFields = $order->getCustomFields();

        if ($savedCustomFields === null) {
            $savedCustomFields = [];
        }
        $this->orderRepository->update(
            [['id' => $orderId, 'customFields' => array_merge($savedCustomFields, $customFields)]],
            Context::createDefaultContext()
        );
    }




    public function getOrderById(string $orderId, Context $context = null): ?OrderEntity
    {
        $context       = $context !== null ? $context : Context::createDefaultContext();
        $orderCriteria = new Criteria([$orderId]);
        $orderCriteria->addAssociation('orderCustomer.salutation');
        $orderCriteria->addAssociation('stateMachineState');
        $orderCriteria->addAssociation('lineItems');
        $orderCriteria->addAssociation('transactions');
        $orderCriteria->addAssociation('transactions.paymentMethod');
        $orderCriteria->addAssociation('transactions.paymentMethod.plugin');
        $orderCriteria->addAssociation('salesChannel');

        /** @var \Shopware\Core\Checkout\Order\OrderEntity|null */
        return $this->orderRepository->search($orderCriteria, $context)->first();
    }

    public function saveBuckarooTransaction(Request $request): ?string
    {
        return $this->buckarooTransactionEntityRepository->save(null, $this->pusToArray($request), []);
    }

    /**
     *
     * @param Request $request
     *
     * @return array<mixed>
     */
    public function pusToArray(Request $request): array
    {
        $now  = new \DateTime();
        $type = 'push';
        if ($request->request->get('brq_transaction_type') == 'I150') {
            $type = 'info';
        }
        return [
            'order_id'             => $request->request->get('ADD_orderId'),
            'order_transaction_id' => $request->request->get('ADD_orderTransactionId'),
            'amount'               => $request->request->get('brq_amount'),
            'amount_credit'        => $request->request->get('brq_amount_credit'),
            'currency'             => $request->request->get('brq_currency'),
            'ordernumber'          => $request->request->get('brq_invoicenumber'),
            'statuscode'           => $request->request->get('brq_statuscode'),
            'transaction_method'   => $request->request->get('brq_transaction_method'),
            'transaction_type'     => $request->request->get('brq_transaction_type'),
            'transactions'         => $request->request->get('brq_transactions'),
            'relatedtransaction'   => $request->request->get('brq_relatedtransaction_partialpayment'),
            'type'                 => $type,
            'created_at'           => $now,
            'updated_at'           => $now,
        ];
    }

    /**
     * @param string $value
     * @param string|null $salesChannelId
     *
     * @return mixed
     */
    public function getSettingsValue(string $value, string $salesChannelId = null)
    {
        return $this->settingsService->getSetting($value, $salesChannelId);
    }

    /**
     * @param mixed $amount1
     * @param mixed $amount2
     *
     * @return boolean
     */
    public function areEqualAmounts($amount1, $amount2): bool
    {
        if (!is_scalar($amount1) || !is_scalar($amount2)) {
            return false;
        }

        if ($amount2 == 0) {
            return $amount1 == $amount2;
        } else {
            return abs((floatval($amount1) - floatval($amount2)) / floatval($amount2)) < 0.00001;
        }
    }
}
