<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Installers;

use Exception;
use Psr\Log\LoggerInterface;
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

    private ?LoggerInterface $logger = null;

    /**
     * MediaInstaller constructor.
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container = null)
    {
        if ($container === null) {
            throw new \Exception("Container is null", 1);
        }
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

        // Logger is optional; if it cannot be resolved we fall back to error_log.
        try {
            $logger = $container->get('logger');
            if ($logger instanceof LoggerInterface) {
                $this->logger = $logger;
            }
        } catch (\Throwable $loggerError) {
            $this->logger = null;
        }
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
        // Media import must never break plugin installation (Shopware 6.7.10+
        // ships a strict SVG content validator that may reject icons the
        // gateways try to import). Each gateway/media file is therefore
        // installed in isolation and any failure is logged as a warning so
        // that `bin/console plugin:install --activate BuckarooPayments` keeps
        // succeeding even when individual icons cannot be persisted.
        try {
            $mediaFolderId = $this->getOrCreateMediaFolder($context->getContext());
        } catch (\Throwable $folderError) {
            $this->logMediaWarning('Could not create or resolve Buckaroo media folder; skipping media import.', $folderError);
            return;
        }

        foreach (GatewayHelper::GATEWAYS as $gateway) {
            try {
                $this->addMedia(new $gateway(), $mediaFolderId, $context->getContext());
            } catch (\Throwable $mediaError) {
                $this->logMediaWarning(
                    sprintf('Failed to install media for gateway "%s"', $gateway),
                    $mediaError
                );
            }
        }

        try {
            $this->setupAdditionalMedia($mediaFolderId, $context->getContext());
        } catch (\Throwable $additionalError) {
            $this->logMediaWarning('Failed to install additional Buckaroo media', $additionalError);
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

    private function setupAdditionalMedia(string $mediaFolderId, Context $context): void
    {
        $mediaList = [
            [
                "path" => __DIR__  . '/../Resources/views/storefront/buckaroo/payments/in3.svg',
                "name" => 'buckaroo-in3-v2'
            ]
        ];

        foreach ($mediaList as $media) {
            try {
                if ($mediaId = $this->getMediaId($media['name'], $context)) {
                    $this->mediaRepository->delete([['id' => $mediaId]], $context);
                }

                $this->createMediaObject($media['path'], $mediaFolderId, $media['name'], $context);
            } catch (\Throwable $mediaError) {
                $this->logMediaWarning(
                    sprintf('Failed to install additional media "%s"', $media['name']),
                    $mediaError
                );
            }
        }
    }

    private function createMediaObject(
        string $path,
        string $mediaFolderId,
        string $newFileName,
        Context $context
    ): string {
        // Sanitize SVGs so they pass Shopware 6.7.10+ strict SVG validation
        // (which uses an XMLReader-based allowlist). The sanitized copy is
        // written to a temp file and removed after persistFileToMedia runs.
        $uploadPath = $this->prepareUploadFile($path);
        $sanitizedTempFile = $uploadPath !== $path ? $uploadPath : null;

        try {
            $mediaFile = $this->createMediaFile($uploadPath);
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

            try {
                $this->fileSaver->persistFileToMedia(
                    $mediaFile,
                    $newFileName,
                    $mediaId,
                    $context
                );
            } catch (\Throwable $persistError) {
                // Clean up the orphan media record so the install does not
                // leave dangling rows behind when validation rejects the file.
                try {
                    $this->mediaRepository->delete([['id' => $mediaId]], $context);
                } catch (\Throwable $cleanupError) {
                    // Cleanup failures are not fatal; the install must still continue.
                }
                throw $persistError;
            }

            return $mediaId;
        } finally {
            if ($sanitizedTempFile !== null && is_file($sanitizedTempFile)) {
                @unlink($sanitizedTempFile);
            }
        }
    }

    /**
     * Return a path to a file safe to hand to Shopware's FileSaver.
     *
     * For SVG inputs the file is sanitized (scripts, event handlers,
     * <foreignObject>, javascript: URLs and Shopware 6.7.10+ disallowed
     * attributes are stripped) and a temporary file is returned. For any
     * other type the original path is returned unchanged.
     */
    private function prepareUploadFile(string $path): string
    {
        if (!is_file($path)) {
            return $path;
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($extension !== 'svg') {
            return $path;
        }

        $original = @file_get_contents($path);
        if (!is_string($original) || $original === '') {
            return $path;
        }

        $sanitized = $this->sanitizeSvgContent($original);
        if ($sanitized === $original) {
            return $path;
        }

        $tempBase = @tempnam(sys_get_temp_dir(), 'buckaroo_svg_');
        if ($tempBase === false) {
            return $path;
        }

        // FileSaver / mime detection benefits from a .svg extension.
        $tempPath = $tempBase . '.svg';
        if (!@rename($tempBase, $tempPath)) {
            $tempPath = $tempBase;
        }

        if (@file_put_contents($tempPath, $sanitized) === false) {
            @unlink($tempPath);
            return $path;
        }

        return $tempPath;
    }

    /**
     * Strip clearly unsafe constructs and Shopware 6.7.10+ disallowed
     * attributes from an SVG so the resulting document is static and
     * passes the core SvgContentValidator allowlist.
     *
     * This deliberately does NOT touch Shopware's core validation: it only
     * cleans the file we ship so the validator accepts it.
     */
    private function sanitizeSvgContent(string $svg): string
    {
        $patterns = [
            // <script>...</script> (also self-closing)
            '#<script\b[^>]*>.*?</script\s*>#is'                         => '',
            '#<script\b[^>]*/\s*>#i'                                     => '',
            // <foreignObject>...</foreignObject> (also self-closing)
            '#<foreignObject\b[^>]*>.*?</foreignObject\s*>#is'           => '',
            '#<foreignObject\b[^>]*/\s*>#i'                              => '',
            // on* event handler attributes (onclick, onload, onerror, ...)
            '#\son[a-zA-Z]+\s*=\s*"[^"]*"#i'                            => '',
            "#\\son[a-zA-Z]+\\s*=\\s*'[^']*'#i"                          => '',
            // javascript: URLs inside href / xlink:href -> neutralize to #
            '#(href\s*=\s*")\s*javascript:[^"]*(")#i'                    => '$1#$2',
            "#(href\\s*=\\s*')\\s*javascript:[^']*(')#i"                 => '$1#$2',
            // data: URLs (e.g. embedded base64 PNGs) in href / xlink:href
            // are flagged as "external reference" by Shopware 6.7.10+ and
            // reject the whole SVG. Drop the entire <image>/<use> element
            // that carries the data URL so the rest of the SVG still loads.
            '#<image\b[^>]*\b(?:xlink:)?href\s*=\s*"\s*data:[^"]*"[^>]*/\s*>#is'    => '',
            '#<image\b[^>]*\b(?:xlink:)?href\s*=\s*"\s*data:[^"]*"[^>]*>.*?</image\s*>#is' => '',
            '#<use\b[^>]*\b(?:xlink:)?href\s*=\s*"\s*data:[^"]*"[^>]*/\s*>#is'      => '',
            '#<use\b[^>]*\b(?:xlink:)?href\s*=\s*"\s*data:[^"]*"[^>]*>.*?</use\s*>#is' => '',
            // For any other element still carrying a data: reference,
            // strip just the offending attribute so the element survives.
            '#\s(?:xlink:)?href\s*=\s*"\s*data:[^"]*"#i'                 => '',
            "#\\s(?:xlink:)?href\\s*=\\s*'\\s*data:[^']*'#i"             => '',
            // Adobe Illustrator / Inkscape attributes not in Shopware allowlist.
            '#\sbaseProfile\s*=\s*"[^"]*"#i'                             => '',
            "#\\sbaseProfile\\s*=\\s*'[^']*'#i"                          => '',
            '#\sdata-[a-zA-Z0-9_-]+\s*=\s*"[^"]*"#i'                    => '',
            "#\\sdata-[a-zA-Z0-9_-]+\\s*=\\s*'[^']*'#i"                  => '',
            // Doctype + entity declarations (rejected by the new validator).
            '#<!DOCTYPE[^>]*>#i'                                         => '',
            '#<!ENTITY[^>]*>#i'                                          => '',
            // xml-stylesheet processing instruction (rejected by new validator).
            '#<\?xml-stylesheet[^?]*\?>#i'                               => '',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $result = preg_replace($pattern, $replacement, $svg);
            if (is_string($result)) {
                $svg = $result;
            }
        }

        return $svg;
    }

    private function logMediaWarning(string $message, \Throwable $exception = null): void
    {
        $fullMessage = '[BuckarooPayments][MediaInstaller] ' . $message;
        if ($exception !== null) {
            $fullMessage .= ' - ' . $exception->getMessage();
        }

        if ($this->logger !== null) {
            $context = $exception !== null ? ['exception' => $exception] : [];
            $this->logger->warning($fullMessage, $context);
            return;
        }

        @error_log($fullMessage);
    }


    private function getOrCreateMediaFolder(Context $context): string
    {
        $mediaFolderId = $this->getMediaFolderIdByName(self::BUCKAROO_FOLDER, $context);
        if ($mediaFolderId === null) {
            $mediaFolderId = $this->createMediaFolderIdByName(self::BUCKAROO_FOLDER, $context);
        }

        if ($mediaFolderId === null) {
            throw new \Exception("Could not create media folder");
        }
        return $mediaFolderId;
    }

    /**
     * @param PaymentMethodInterface $paymentMethod
     * @param Context $context
     * @throws \Throwable
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function addMedia(PaymentMethodInterface $paymentMethod, string $mediaFolderId, Context $context): ?string
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

        /** @var MediaEntity|null */
        return $this->mediaRepository->search($criteria, $context)->first();
    }

    private function getMediaId(string $mediaName, Context $context): ?string
    {
        /** @var MediaEntity|null $media */
        $media = $this->getMediaFromRepo($mediaName, $context);

        return $media !== null ? $media->getId() : null;
    }

    /**
     * @param PaymentMethodInterface $paymentMethod
     * @return string
     */
    private function getMediaName(PaymentMethodInterface $paymentMethod): string
    {
        return md5($paymentMethod->getBuckarooKey());
    }

    public function update(UpdateContext $updateContext): void
    {
        $context = $updateContext->getContext();

        try {
            $mediaFolderId = $this->getOrCreateMediaFolder($context);
        } catch (\Throwable $folderError) {
            $this->logMediaWarning('Could not create or resolve Buckaroo media folder; skipping media update.', $folderError);
            return;
        }

        foreach (GatewayHelper::GATEWAYS as $gateway) {
            try {
                $gatewayObject = new $gateway();
                $this->removeMedia($gatewayObject, $context);
                $mediaId = $this->addMedia($gatewayObject, $mediaFolderId, $context);
                $this->updateMediaOnPaymentMethod($gatewayObject, $context, $mediaId);
            } catch (\Throwable $mediaError) {
                $this->logMediaWarning(
                    sprintf('Failed to update media for gateway "%s"', $gateway),
                    $mediaError
                );
            }
        }

        try {
            $this->setupAdditionalMedia($mediaFolderId, $context);
        } catch (\Throwable $additionalError) {
            $this->logMediaWarning('Failed to update additional Buckaroo media', $additionalError);
        }
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
