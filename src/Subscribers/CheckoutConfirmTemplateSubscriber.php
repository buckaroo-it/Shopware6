<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Subscribers;

use Buckaroo\Shopware6\Helper\ApiHelper;
use Buckaroo\Shopware6\Helper\BkrHelper;
use Buckaroo\Shopware6\Storefront\Struct\BuckarooStruct;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CheckoutConfirmTemplateSubscriber implements EventSubscriberInterface
{
    /** @var ApiHelper */
    private $apiHelper;
    private $bkrHelper;
    private $customerRepository;

    /**
     * @var array
     */
    protected $issuers = [
        [
            'name' => 'ABN AMRO',
            'code' => 'ABNANL2A',
        ],
        [
            'name' => 'ASN Bank',
            'code' => 'ASNBNL21',
        ],
        [
            'name' => 'Bunq Bank',
            'code' => 'BUNQNL2A',
        ],
        [
            'name' => 'ING',
            'code' => 'INGBNL2A',
        ],
        [
            'name' => 'Knab Bank',
            'code' => 'KNABNL2H',
        ],
        [
            'name' => 'Moneyou',
            'code' => 'MOYONL21',
        ],
        [
            'name' => 'Rabobank',
            'code' => 'RABONL2U',
        ],
        [
            'name' => 'RegioBank',
            'code' => 'RBRBNL21',
        ],
        [
            'name' => 'SNS Bank',
            'code' => 'SNSBNL2A',
        ],
        [
            'name' => 'Triodos Bank',
            'code' => 'TRIONL2U',
        ],
        [
            'name' => 'Van Lanschot',
            'code' => 'FVLBNL22',
        ],
        [
            'name' => 'Handelsbanken',
            'code' => 'HANDNL2A',
        ],
    ];

    /**
     * CheckoutConfirmTemplateSubscriber constructor.
     * @param ApiHelper $apiHelper
     * @param BkrHelper $bkrHelper
     * @param EntityRepositoryInterface $customerRepository
     */
    public function __construct(
        ApiHelper $apiHelper,
        BkrHelper $bkrHelper,
        EntityRepositoryInterface $customerRepository
    ) {
        $this->apiHelper = $apiHelper;
        $this->bkrHelper = $bkrHelper;
        $this->customerRepository = $customerRepository;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'addBuckarooExtension'
        ];
    }

    /**
     * @param CheckoutConfirmPageLoadedEvent $event
     * @throws \Exception
     */
    public function addBuckarooExtension(CheckoutConfirmPageLoadedEvent $event): void
    {
        $request = $this->bkrHelper->getGlobals();

        $customer = $event->getSalesChannelContext()->getCustomer();

        $issuer = $request->get('issuer');
        if ($issuer) {
            $this->customerRepository->upsert(
                [[
                    'id' => $customer->getId(),
                    'customFields' => ['last_used_issuer' => $issuer]
                ]],
                $event->getContext()
            );
            $customer = $this->getCustomer($customer->getId(), $event->getContext());
        }

        // $client = $this->apiHelper->initializeBuckarooClient();
        $struct = new BuckarooStruct();

        // $issuers = $client->issuers->get();
        $issuers = $this->issuers;
        $lastUsedIssuer = $customer->getCustomFields()['last_used_issuer'];

        $struct->assign([
            'issuers' => $issuers,
            'last_used_issuer' => $lastUsedIssuer,
            'payment_method_name' => $this->getPaymentMethodName($issuers, $lastUsedIssuer)
        ]);

        $event->getPage()->addExtension(
            BuckarooStruct::EXTENSION_NAME,
            $struct
        );
    }

    /**
     * @param string $customerId
     * @param Context $context
     * @return CustomerEntity
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    private function getCustomer(string $customerId, Context $context): CustomerEntity
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('id', $customerId));
        return $this->customerRepository->search($criteria, $context)->first();
    }

    /**
     * @param array $issuers
     * @param string|null $lastUsedIssuer
     * @return string
     */
    private function getPaymentMethodName(array $issuers, ?string $lastUsedIssuer): string
    {
        foreach ($issuers as $issuer) {
            if ($issuer['code'] === $lastUsedIssuer) {
                $issuerName = $issuer['name'];
                return 'Buckaroo iDEAL ('.$issuerName.')';
            }
        }

        return 'Buckaroo iDEAL';
    }
}
