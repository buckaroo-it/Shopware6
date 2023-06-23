<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Installers;

use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\BuckarooPayments;
use Shopware\Core\Content\Media\MediaEntity;
use Buckaroo\Shopware6\Helpers\GatewayHelper;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class PaymentMethodsInstaller implements InstallerInterface
{
    public const BUCKAROO_KEY = 'buckaroo_key';
    public const IS_BUCKAROO = 'is_buckaroo';
    public const TEMPLATE = 'template';

    /** @var PluginIdProvider */
    public $pluginIdProvider;
    /** @var EntityRepository */
    public $paymentMethodRepository;
    /** @var EntityRepository */
    public $mediaRepository;
    /** @var SystemConfigService */
    private $systemConfigService;

    /**
     * PaymentMethodsInstaller constructor.
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        /** @var PluginIdProvider */
        $pluginIdProvider = $this->getDependency($container, PluginIdProvider::class);
        $this->pluginIdProvider = $pluginIdProvider;

        /** @var EntityRepository */
        $paymentMethodRepository = $this->getDependency($container, 'payment_method.repository');
        $this->paymentMethodRepository = $paymentMethodRepository;

        /** @var EntityRepository */
        $mediaRepository = $this->getDependency($container, 'media.repository');
        $this->mediaRepository = $mediaRepository;

        /** @var SystemConfigService */
        $systemConfigService = $this->getDependency($container, SystemConfigService::class);
        $this->systemConfigService = $systemConfigService;
    }

    /**
     * @param ContainerInterface $container
     * @param string $name
     * @return mixed
     */
    private function getDependency(ContainerInterface $container, string $name)
    {
        $repository =  $container->get($name);

        if ($repository === null) {
            throw new \UnexpectedValueException("Repository {$repository} not found");
        }
        return $repository;
    }

    /**
     * @param InstallContext $context
     */
    public function install(InstallContext $context): void
    {
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $this->addPaymentMethod(new $gateway(), $context->getContext());
        }
        $this->setDefaultValues();
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

    public function update(UpdateContext $updateContext): void
    {
        $this->addNewPaymentMethods($updateContext->getContext());
        $this->setDefaultValues();
        $this->copyAppleDomainAssociationFile();
    }

    /**
     * @param PaymentMethodInterface $paymentMethod
     * @param Context $context
     */
    public function addPaymentMethod(PaymentMethodInterface $paymentMethod, Context $context): void
    {
        $paymentMethodId = $this->getPaymentMethodId($paymentMethod, $context);
        $this->upsertPaymentMethod($paymentMethod, $context, $paymentMethodId);
    }

    
    private function upsertPaymentMethod(PaymentMethodInterface $paymentMethod, Context $context, string $paymentMethodId = null)
    {
        $pluginId = $this->pluginIdProvider->getPluginIdByBaseClass(BuckarooPayments::class, $context);

        $mediaId = $this->getMediaId($paymentMethod, $context);

        $paymentData = [
            'id' => $paymentMethodId,
            'handlerIdentifier' => $paymentMethod->getPaymentHandler(),
            'name' => $paymentMethod->getName(),
            'description' => $paymentMethod->getDescription(),
            'pluginId' => $pluginId,
            'mediaId' => $mediaId,
            'afterOrderEnabled' => true,
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

        return $paymentIds->firstId();
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

        /** @var MediaEntity|null $media */
        $media = $this->mediaRepository->search($criteria, $context)->first();

        if ($media === null) {
            return null;
        }

        return $media->getId();
    }

    /**
     * @param PaymentMethodInterface $paymentMethod
     * @return string
     */
    private function getMediaName(PaymentMethodInterface $paymentMethod): string
    {
        return md5($paymentMethod->getName());
    }

    /**
     * Create the apple-developer-merchantid-domain-association file so apple can authorise the domain for apple pay
     */
    protected function copyAppleDomainAssociationFile(): void
    {
        $root = $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] . '/' : getcwd() . '/public' . '/';

        if (!file_exists($root . '.well-known/apple-developer-merchantid-domain-association')) {
            if (!file_exists($root . '.well-known')) {
                mkdir($root . '.well-known', 0775, true);
            }

            copy(
                __DIR__ . '/../Resources/views/storefront/_resources/apple-developer-merchantid-domain-association',
                $root . '/.well-known/apple-developer-merchantid-domain-association'
            );
        }
    }

    /**
     *
     * @param string $key
     * @param mixed $value
     * @param string $label
     *
     * @return void
     */
    private function setBuckarooPaymentSettingsValue(string $key, $value, string $label = ''): void
    {
        $domain = 'BuckarooPayments.config.';
        $configKey = $domain . $key . $label;
        $currentValue = $this->systemConfigService->get($configKey);

        if ($currentValue !== null) {
            return;
        }
        if (is_scalar($value) || is_array($value) || is_null($value)) {
            $this->systemConfigService->set($configKey, $value);
        }
    }

    private function setDefaultValues(): void
    {
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $paymentMethod = new $gateway();

            $this->setBuckarooPaymentSettingsValue(
                $paymentMethod->getBuckarooKey(),
                'test',
                'Environment'
            );

            $this->setBuckarooPaymentSettingsValue(
                $paymentMethod->getBuckarooKey(),
                $paymentMethod->getName(),
                'Label'
            );
        }

        foreach ([
                ['paymentSuccesStatus' => 'paid'],
                ['orderStatus' => 'open'],
                ['klarnaBusiness' => 'B2C'],
                ['BillinkMode' => 'pay'],
                ['transferSendEmail' => 1],
                ['transferDateDue' => 7],
                ['payperemailEnabledfrontend' => true]
            ] as $key => $value
        ) {
            $this->setBuckarooPaymentSettingsValue((string)$key, $value);
        }
    }

    /**
     * Add new payment methods on update
     *
     * @param Context $context
     *
     * @return void
     */
    private function addNewPaymentMethods(Context $context): void
    {
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $paymentMethod = new $gateway();

            $paymentMethodId = $this->getPaymentMethodId($paymentMethod, $context);
            if($paymentMethodId === null) {
                $this->upsertPaymentMethod($paymentMethod, $context, $paymentMethodId);
            }
        }
    }
}
