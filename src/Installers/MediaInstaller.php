<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Installers;

use Exception;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Content\Media\MediaEntity;
use Buckaroo\Shopware6\Helpers\GatewayHelper;
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Content\Media\File\MediaFile;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderEntity;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class MediaInstaller implements InstallerInterface
{
    public const BUCKAROO_FOLDER  = 'Buckaroo';

    /** @var EntityRepository */
    private $mediaRepository;

    /** @var EntityRepository */
    private $mediaFolderRepository;

    private FileSaver $fileSaver;

    /** @var EntityRepository */
    public $paymentMethodRepository;

    /**
     * MediaInstaller constructor.
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        /** @var EntityRepository */
        $mediaRepository = $this->getDependency($container, 'media.repository');
        $this->mediaRepository = $mediaRepository;

        /** @var EntityRepository */
        $mediaFolderRepository = $this->getDependency($container, 'media_folder.repository');
        $this->mediaFolderRepository = $mediaFolderRepository;

        /** @var FileSaver */
        $fileSaver = $this->getDependency($container, FileSaver::class);
        $this->fileSaver = $fileSaver;

        /** @var EntityRepository */
        $paymentMethodRepository = $this->getDependency($container, 'payment_method.repository');
        $this->paymentMethodRepository = $paymentMethodRepository;
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
            throw new Exception("Repository {$repository} not found");
        }
        return $repository;
    }

    /**
     * @param InstallContext $context
     */
    public function install(InstallContext $context): void
    {
        $mediaFolderId = $this->getOrCreateMediaFolder($context->getContext());
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $this->addMedia(new $gateway(), $mediaFolderId, $context->getContext());
        }
        $this->setupAdditionalMedia($mediaFolderId, $context->getContext());
    }

    /**
     * @param UninstallContext $context
     */
    public function uninstall(UninstallContext $context): void
    {
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $this->removeMedia(new $gateway(), $context->getContext());
        }
        $this->removeMediaFolderIdByName(self::BUCKAROO_FOLDER, $context->getContext());
    }

    /**
     * @param ActivateContext $context
     */
    public function activate(ActivateContext $context): void
    {
        return;
    }

    /**
     * @param DeactivateContext $context
     */
    public function deactivate(DeactivateContext $context): void
    {
        return;
    }

    private function setupAdditionalMedia(string $mediaFolderId, Context $context)
    {
        $mediaList = [
            [
                "path" => __DIR__  . '/../Resources/views/storefront/buckaroo/payments/in3-ideal.svg',
                "name" => 'buckaroo-in3-ideal'
            ]
        ];

        foreach ($mediaList as $media) {
            if ($mediaId = $this->getMediaId($media['name'], $context)) {
                $this->mediaRepository->delete([['id' => $mediaId]], $context);
            }

            $this->createMediaObject($media['path'], $mediaFolderId, $media['name'], $context);
        }
    }

    private function createMediaObject(string $path, string $mediaFolderId, string $newFileName, Context $context)
    {
        $mediaFile = $this->createMediaFile($path);
        $mediaId = Uuid::randomHex();

        $this->mediaRepository->create(
            [
                [
                    'id' => $mediaId,
                    'private' => false,
                    'mediaFolderId' => $mediaFolderId,
                ]
            ],
            $context
        );

        $this->fileSaver->persistFileToMedia(
            $mediaFile,
            $newFileName,
            $mediaId,
            $context
        );

        return $mediaId;
    }


    private function getOrCreateMediaFolder(Context $context): string
    {
        $mediaFolderId = $this->getMediaFolderIdByName(self::BUCKAROO_FOLDER, $context);
        if ($mediaFolderId === null) {
            $mediaFolderId = $this->createMediaFolderIdByName(self::BUCKAROO_FOLDER, $context);
        }
        return $mediaFolderId;
    }

    /**
     * @param PaymentMethodInterface $paymentMethod
     * @param Context $context
     * @throws \Shopware\Core\Content\Media\Exception\DuplicatedMediaFileNameException
     * @throws \Shopware\Core\Content\Media\Exception\EmptyMediaFilenameException
     * @throws \Shopware\Core\Content\Media\Exception\IllegalFileNameException
     * @throws \Shopware\Core\Content\Media\Exception\MediaNotFoundException
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function addMedia(PaymentMethodInterface $paymentMethod, $mediaFolderId, Context $context): ?string
    {
        if (!$paymentMethod->getMedia()) {
            return null;
        }

        if ($this->hasMediaAlreadyInstalled($this->getMediaName($paymentMethod), $context)) {
            return null;
        }

        return $this->createMediaObject(
            $paymentMethod->getMedia(),
            $mediaFolderId,
            $this->getMediaName($paymentMethod),
            $context
        );
    }

    private function removeMedia(PaymentMethodInterface $paymentMethod, Context $context): void
    {
        if (!$paymentMethod->getMedia()) {
            return;
        }

        if ($mediaId = $this->getMediaId($this->getMediaName($paymentMethod), $context)) {
            $this->mediaRepository->delete([['id' => $mediaId]], $context);
        }
    }

    /**
     * @param string $filePath
     * @return MediaFile
     */
    private function createMediaFile(string $filePath): MediaFile
    {
        $mime = mime_content_type($filePath);
        /** @var string */
        $path = pathinfo($filePath, PATHINFO_EXTENSION);

        return new MediaFile(
            $filePath,
            (string)$mime,
            $path,
            (int)filesize($filePath)
        );
    }

    /**
     * @param string $mediaName
     * @param Context $context
     * @return bool
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    private function hasMediaAlreadyInstalled(string $mediaName, Context $context): bool
    {
        return $this->getMediaFromRepo($mediaName, $context) !== null;
    }

    private function getMediaFromRepo(string $mediaName, Context $context): ?MediaEntity
    {
        $criteria = (new Criteria())->addFilter(
            new EqualsFilter(
                'fileName',
                $mediaName
            )
        );

        /** @var MediaEntity|null $media */
        return $this->mediaRepository->search($criteria, $context)->first();
    }

    private function getMediaId(string $mediaName, Context $context): ?string
    {
        /** @var MediaEntity|null $media */
        $media = $this->getMediaFromRepo($mediaName, $context);

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

    public function update(UpdateContext $updateContext): void
    {
        $context = $updateContext->getContext();
        $mediaFolderId = $this->getOrCreateMediaFolder($context);
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $gatewayObject = new $gateway();
            $this->removeMedia($gatewayObject, $context);
            $mediaId = $this->addMedia($gatewayObject, $mediaFolderId, $context);
            $this->updateMediaOnPaymentMethod($gatewayObject, $context, $mediaId);
        }
        $this->setupAdditionalMedia($mediaFolderId, $context);
    }

    private function updateMediaOnPaymentMethod(
        PaymentMethodInterface $paymentMethod,
        Context $context,
        string $mediaId = null
    ): void {
        if ($mediaId === null) {
            return;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', $paymentMethod->getPaymentHandler()));

        $paymentMethodHandlerId = $this->paymentMethodRepository
            ->searchIds($criteria, $context)
            ->firstId();
        if ($paymentMethodHandlerId !== null) {
            $this->paymentMethodRepository->update(
                [
                    [
                        'id' => $paymentMethodHandlerId,
                        'mediaId' => $mediaId
                    ]
                ],
                $context
            );
        }
    }

    private function getMediaFolderIdByName(string $folder, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $folder));
        $criteria->setLimit(1);

        /** @var MediaFolderEntity|null */
        $defaultFolder = $this->mediaFolderRepository->search($criteria, $context)->first();
        if ($defaultFolder === null) {
            return null;
        }
        return $defaultFolder->getId();
    }

    private function createMediaFolderIdByName(string $folder, Context $context): ?string
    {
        $this->mediaFolderRepository->create([
            [
                'name' => $folder,
                'useParentConfiguration' => false,
                'configuration' => [],
            ],
        ], $context);
        return $this->getMediaFolderIdByName($folder, $context);
    }

    private function removeMediaFolderIdByName(string $folder, Context $context): void
    {
        if ($mediaFolderId = $this->getMediaFolderIdByName($folder, $context)) {
            $this->mediaFolderRepository->delete([['id' => $mediaFolderId]], $context);
        }
    }
}
