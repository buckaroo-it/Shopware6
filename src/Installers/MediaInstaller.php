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
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Content\Media\DataAbstractionLayer\MediaRepositoryDecorator;
use Shopware\Core\Content\Media\DataAbstractionLayer\MediaFolderRepositoryDecorator;

class MediaInstaller implements InstallerInterface
{
    public const BUCKAROO_FOLDER  = 'Buckaroo';

    private MediaRepositoryDecorator $mediaRepository;

    private MediaFolderRepositoryDecorator $mediaFolderRepository;

    private FileSaver $fileSaver;

    /**
     * MediaInstaller constructor.
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        /** @var MediaRepositoryDecorator */
        $mediaRepository = $this->getDependency($container, 'media.repository');
        $this->mediaRepository = $mediaRepository;

        /** @var MediaFolderRepositoryDecorator */
        $mediaFolderRepository = $this->getDependency($container, 'media_folder.repository');
        $this->mediaFolderRepository = $mediaFolderRepository;

        /** @var FileSaver */
        $fileSaver = $this->getDependency($container, FileSaver::class);
        $this->fileSaver = $fileSaver;
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
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $this->addMedia(new $gateway(), $context->getContext());
        }
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

    /**
     * @param PaymentMethodInterface $paymentMethod
     * @param Context $context
     * @throws \Shopware\Core\Content\Media\Exception\DuplicatedMediaFileNameException
     * @throws \Shopware\Core\Content\Media\Exception\EmptyMediaFilenameException
     * @throws \Shopware\Core\Content\Media\Exception\IllegalFileNameException
     * @throws \Shopware\Core\Content\Media\Exception\MediaNotFoundException
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function addMedia(PaymentMethodInterface $paymentMethod, Context $context): void
    {
        if (!$paymentMethod->getMedia()) {
            return;
        }

        if ($this->hasMediaAlreadyInstalled($paymentMethod, $context)) {
            return;
        }

        if (!$mediaFolderId = $this->getMediaFolderIdByName(self::BUCKAROO_FOLDER, $context)) {
            $mediaFolderId = $this->createMediaFolderIdByName(self::BUCKAROO_FOLDER, $context);
        }

        $mediaFile = $this->createMediaFile($paymentMethod->getMedia());
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
            $this->getMediaName($paymentMethod),
            $mediaId,
            $context
        );
    }

    private function removeMedia(PaymentMethodInterface $paymentMethod, Context $context): void
    {
        if (!$paymentMethod->getMedia()) {
            return;
        }

        if ($mediaId = $this->getMediaId($paymentMethod, $context)) {
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
     * @param PaymentMethodInterface $paymentMethod
     * @param Context $context
     * @return bool
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    private function hasMediaAlreadyInstalled(PaymentMethodInterface $paymentMethod, Context $context): bool
    {
        $criteria = (new Criteria())->addFilter(
            new EqualsFilter(
                'fileName',
                $this->getMediaName($paymentMethod)
            )
        );

        /** @var MediaEntity|null $media */
        $media = $this->mediaRepository->search($criteria, $context)->first();

        return $media !== null;
    }

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

    public function update(UpdateContext $updateContext): void
    {
    }

    private function getMediaFolderIdByName(string $folder, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $folder));
        $criteria->setLimit(1);
        $defaultFolder = $this->mediaFolderRepository->search($criteria, $context);
        $defaultFolderId = null;
        if ($defaultFolder->count() === 1) {
            $defaultFolderId = $defaultFolder->first()->getId();
        }

        return $defaultFolderId;
    }

    private function createMediaFolderIdByName(string $folder, Context $context): ?string
    {
        $defaultFolder = $this->mediaFolderRepository->create([
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
