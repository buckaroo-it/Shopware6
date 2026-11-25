<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Installers;

use Buckaroo\Shopware6\BuckarooPayments;
use Buckaroo\Shopware6\Helpers\GatewayHelper;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;

class PaymentMethodsInstaller implements InstallerInterface
{
    public const BUCKAROO_KEY = 'buckaroo_key';
    public const IS_BUCKAROO = 'is_buckaroo';
    public const TEMPLATE = 'template';

    /**
     * @var PluginIdProvider
     */
    public $pluginIdProvider;

    /**
     * @var EntityRepository
     */
    public $paymentMethodRepository;

    /**
     * @var EntityRepository
     */
    public $mediaRepository;

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * Shopware's public filesystem. Writing through it instead of touching the
     * document root directly keeps the plugin working on read-only or
     * non-local (e.g. object storage) deployments.
     */
    private ?FilesystemOperator $publicFilesystem = null;

    private ?LoggerInterface $logger = null;

    /**
     * PaymentMethodsInstaller constructor.
     */
    public function __construct(?ContainerInterface $container = null)
    {
        if ($container === null) {
            throw new \Exception('Container is null', 1);
        }

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

        // Both are optional: a missing filesystem or logger must never break the install.
        try {
            $publicFilesystem = $container->get('shopware.filesystem.public');
            if ($publicFilesystem instanceof FilesystemOperator) {
                $this->publicFilesystem = $publicFilesystem;
            }
        } catch (\Throwable $filesystemError) {
            $this->publicFilesystem = null;
        }

        try {
            $logger = $container->get('logger');
            if ($logger instanceof LoggerInterface) {
                $this->logger = $logger;
            }
        } catch (\Throwable $loggerError) {
            $this->logger = null;
        }
    }

    public function install(InstallContext $context): void
    {
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $this->addPaymentMethod(new $gateway(), $context->getContext());
        }
        $this->setDefaultValues();
        // New installs only: not in config.xml or setDefaultValues(), so updates don't switch it on for existing shops
        $this->setBuckarooPaymentSettingsValue('paypalSellerprotection', true);
        $this->copyAppleDomainAssociationFile();
    }

    public function uninstall(UninstallContext $context): void
    {
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $this->setPaymentMethodActive(false, new $gateway(), $context->getContext());
        }
    }

    public function activate(ActivateContext $context): void
    {
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $this->setPaymentMethodActive(true, new $gateway(), $context->getContext());
        }
    }

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

    public function addPaymentMethod(PaymentMethodInterface $paymentMethod, Context $context): void
    {
        $paymentMethodId = $this->getPaymentMethodId($paymentMethod, $context);
        $this->upsertPaymentMethod($paymentMethod, $context, $paymentMethodId);
    }

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
     * Create the apple-developer-merchantid-domain-association file so apple can authorise the domain for apple pay
     */
    protected function copyAppleDomainAssociationFile(): void
    {
        $target = '.well-known/apple-developer-merchantid-domain-association';
        $source = __DIR__ . '/../Resources/views/storefront/_resources/apple-developer-merchantid-domain-association';

        if ($this->publicFilesystem === null) {
            $this->logAppleDomainWarning('public filesystem is unavailable');

            return;
        }

        try {
            if ($this->publicFilesystem->fileExists($target)) {
                return;
            }

            $contents = file_get_contents($source);
            if ($contents === false) {
                $this->logAppleDomainWarning(sprintf('could not read "%s"', $source));

                return;
            }

            // write() creates the .well-known directory when it does not exist yet.
            $this->publicFilesystem->write($target, $contents);
        } catch (\Throwable $writeError) {
            $this->logAppleDomainWarning($writeError->getMessage());
        }
    }

    /**
     * @return mixed
     */
    private function getDependency(ContainerInterface $container, string $name)
    {
        $repository = $container->get($name);

        if ($repository === null) {
            throw new \UnexpectedValueException("Repository {$repository} not found");
        }

        return $repository;
    }

    private function upsertPaymentMethod(
        PaymentMethodInterface $paymentMethod,
        Context $context,
        ?string $paymentMethodId = null
    ): void {
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
            'active' => true,
            'translations' => $paymentMethod->getTranslations(),
            'customFields' => [
                self::BUCKAROO_KEY => $paymentMethod->getBuckarooKey(),
                self::IS_BUCKAROO => true,
                self::TEMPLATE => $paymentMethod->getTemplate(),
            ],
            'technicalName' => $paymentMethod->getTechnicalName(),
        ];

        $this->paymentMethodRepository->upsert([$paymentData], $context);
    }

    /**
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
        $media = $this->mediaRepository->search($criteria, $context)->getEntities()->first();

        if ($media === null) {
            return null;
        }

        return $media->getId();
    }

    private function getMediaName(PaymentMethodInterface $paymentMethod): string
    {
        return md5($paymentMethod->getBuckarooKey());
    }

    /**
     * Apple Pay stays unavailable without this file, so a failure must be visible
     * in the log rather than silently swallowed - but it must not fail the install.
     */
    private function logAppleDomainWarning(string $reason): void
    {
        $message = '[BuckarooPayments][PaymentMethodsInstaller] Could not publish the Apple Pay '
            . 'domain association file: ' . $reason;

        if ($this->logger !== null) {
            $this->logger->warning($message);

            return;
        }

        error_log($message);
    }

    /**
     * @param mixed $value
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

        // Other defaults are the <defaultValue> elements in config.xml
    }

    /**
     * Add new payment methods and update existing ones on update (sync name, description, media from PHP classes).
     */
    private function addNewPaymentMethods(Context $context): void
    {
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $paymentMethod = new $gateway();
            $paymentMethodId = $this->getPaymentMethodId($paymentMethod, $context);
            $this->upsertPaymentMethod($paymentMethod, $context, $paymentMethodId);
        }
    }
}
