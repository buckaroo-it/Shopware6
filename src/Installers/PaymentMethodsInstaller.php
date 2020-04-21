<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Installers;

use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Context;

use Buckaroo\Shopware6\Helpers\GatewayHelper;
use Buckaroo\Shopware6\BuckarooPayment;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;

class PaymentMethodsInstaller implements InstallerInterface
{
    public const BUCKAROO_KEY = 'buckaroo_key';
    public const IS_BUCKAROO = 'is_buckaroo';
    public const TEMPLATE = 'template';

    /** @var PluginIdProvider */
    public $pluginIdProvider;
    /** @var EntityRepositoryInterface */
    public $paymentMethodRepository;
    /** @var EntityRepositoryInterface */
    public $mediaRepository;

    /**
     * PaymentMethodsInstaller constructor.
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->pluginIdProvider = $container->get(PluginIdProvider::class);
        $this->paymentMethodRepository = $container->get('payment_method.repository');
        $this->mediaRepository = $container->get('media.repository');
    }

    /**
     * @param InstallContext $context
     */
    public function install(InstallContext $context): void
    {
        $this->updateBuckarooPaymentMethod($context->getContext());

        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $this->addPaymentMethod(new $gateway(), $context->getContext());
        }

        $this->copyAppleDomainAssociationFile();
    }

    /**
     * @param UninstallContext $context
     */
    public function uninstall(UninstallContext $context): void
    {
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $this->setPaymentMethodActive(false, new $gateway(), $context->getContext());
        }
    }

    /**
     * @param ActivateContext $context
     */
    public function activate(ActivateContext $context): void
    {
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $this->setPaymentMethodActive(true, new $gateway(), $context->getContext());
        }
    }

    /**
     * @param DeactivateContext $context
     */
    public function deactivate(DeactivateContext $context): void
    {
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $this->setPaymentMethodActive(false, new $gateway(), $context->getContext());
        }
    }

    /**
     * @param PaymentMethodInterface $paymentMethod
     * @param Context $context
     */
    public function addPaymentMethod(PaymentMethodInterface $paymentMethod, Context $context): void
    {
        $paymentMethodId = $this->getPaymentMethodId($paymentMethod, $context);

        $pluginId = $this->pluginIdProvider->getPluginIdByBaseClass(BuckarooPayment::class, $context);

        $mediaId = $this->getMediaId($paymentMethod, $context);

        $paymentData = [
            'id' => $paymentMethodId,
            'handlerIdentifier' => $paymentMethod->getPaymentHandler(),
            'name' => $paymentMethod->getName(),
            'description' => $paymentMethod->getDescription(),
            'pluginId' => $pluginId,
            'mediaId' => $mediaId,
            'translations' => $paymentMethod->getTranslations(),
            'customFields' => [
                self::BUCKAROO_KEY => $paymentMethod->getBuckarooKey(),
                self::IS_BUCKAROO => true,
                self::TEMPLATE => $paymentMethod->getTemplate()
            ]
        ];

        $this->paymentMethodRepository->upsert([$paymentData], $context);
    }

    /**
     * @param PaymentMethodInterface $paymentMethod
     * @param Context $context
     * @return string|null
     */
    public function getPaymentMethodId(PaymentMethodInterface $paymentMethod, Context $context): ?string
    {
        $paymentCriteria = (new Criteria())->addFilter(
            new EqualsFilter(
                'handlerIdentifier',
                $paymentMethod->getPaymentHandler()
            )
        );

        $paymentIds = $this->paymentMethodRepository->searchIds(
            $paymentCriteria,
            $context
        );

        if ($paymentIds->getTotal() === 0) {
            return null;
        }

        return $paymentIds->getIds()[0];
    }

    /**
     * @param bool $active
     * @param PaymentMethodInterface $paymentMethod
     * @param Context $context
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    public function setPaymentMethodActive(bool $active, PaymentMethodInterface $paymentMethod, Context $context): void
    {
        $paymentMethodId = $this->getPaymentMethodId($paymentMethod, $context);

        if (!$paymentMethodId) {
            return;
        }

        $paymentData = [
            'id' => $paymentMethodId,
            'active' => $active,
        ];

        $this->paymentMethodRepository->upsert([$paymentData], $context);
    }

    /**
     * @param PaymentMethodInterface $paymentMethod
     * @param Context $context
     * @return string|null
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    private function getMediaId(PaymentMethodInterface $paymentMethod, Context $context): ?string
    {
        $criteria = (new Criteria())->addFilter(
            new EqualsFilter(
                'fileName',
                $this->getMediaName($paymentMethod)
            )
        );

        /** @var MediaEntity $media */
        $media = $this->mediaRepository->search($criteria, $context)->first();

        if (!$media) {
            return null;
        }

        return $media->getId();
    }

    /**
     * @param Context $context
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    private function updateBuckarooPaymentMethod(Context $context): void
    {
        $paymentCriteria = (new Criteria())->addFilter(
            new EqualsFilter(
                'handlerIdentifier',
                Buckaroo::class
            )
        );

        $paymentIds = $this->paymentMethodRepository->searchIds(
            $paymentCriteria,
            $context
        );

        if ($paymentIds->getTotal() === 0) {
            return;
        }

        $paymentData = [
            'id' => $paymentIds->getIds()[0],
            'handlerIdentifier' => (new Buckaroo())->getPaymentHandler(),
        ];

        $this->paymentMethodRepository->upsert([$paymentData], $context);
    }

    /**
     * @param PaymentMethodInterface $paymentMethod
     * @return string
     */
    private function getMediaName(PaymentMethodInterface $paymentMethod): string
    {
/*        if ($paymentMethod->getName() === (new IngHomePay())->getName()) {
            return 'bkr_ING-HomePay';
        }*/

        return 'bkr_' . $paymentMethod->getName();
    }

    /**
     * Create the apple-developer-merchantid-domain-association file so apple can authorise the domain for apple pay
     */
    protected function copyAppleDomainAssociationFile(): void
    {
        $root = $_SERVER['DOCUMENT_ROOT'] . '/';

        if (!file_exists($root . '.well-known/apple-developer-merchantid-domain-association')) {
            if (!file_exists($root . '.well-known')) {
                mkdir($root . '.well-known', 0775, true);
            }

            copy(__DIR__ . '/../Resources/views/storefront/_resources/apple-developer-merchantid-domain-association', $root . '/.well-known/apple-developer-merchantid-domain-association');
        }
    }

    public function update(UpdateContext $updateContext): void
    {
        $this->copyAppleDomainAssociationFile();
    }
}
