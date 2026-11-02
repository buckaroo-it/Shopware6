<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Installers;

use Buckaroo\Shopware6\Helpers\GatewayHelper;
use Buckaroo\Shopware6\Installers\MediaInstaller;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderCollection;
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderEntity;
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The installer used to query the media and payment method repositories once per
 * gateway (an N+1 on every install and update). These tests pin that the lookups
 * are batched into one query each while every gateway icon is still installed and
 * linked to its payment method.
 */
class MediaInstallerTest extends TestCase
{
    private const FOLDER_ID = 'folder-id';

    private Context $context;

    /** @var EntityRepository&MockObject */
    private EntityRepository $mediaRepository;

    /** @var EntityRepository&MockObject */
    private EntityRepository $mediaFolderRepository;

    /** @var EntityRepository&MockObject */
    private EntityRepository $paymentMethodRepository;

    /** @var FileSaver&MockObject */
    private FileSaver $fileSaver;

    protected function setUp(): void
    {
        $this->context = Context::createDefaultContext();
        $this->mediaRepository = $this->createMock(EntityRepository::class);
        $this->mediaFolderRepository = $this->createMock(EntityRepository::class);
        $this->paymentMethodRepository = $this->createMock(EntityRepository::class);
        $this->fileSaver = $this->createMock(FileSaver::class);

        $folder = new MediaFolderEntity();
        $folder->setId(self::FOLDER_ID);
        $this->mediaFolderRepository->method('search')->willReturn(
            $this->searchResult('media_folder', new MediaFolderCollection([$folder]))
        );
    }

    public function testInstallLooksUpExistingGatewayMediaInOneQuery(): void
    {
        $paymentMethods = $this->paymentMethodsWithMedia();
        $alreadyInstalled = array_slice($paymentMethods, 1);

        // One lookup for every gateway icon, one for the additional media list.
        $this->mediaRepository->expects(static::exactly(2))
            ->method('search')
            ->willReturnOnConsecutiveCalls(
                $this->searchResult('media', $this->mediaCollection($alreadyInstalled)),
                $this->searchResult('media', new MediaCollection())
            );

        // Only the missing gateway icon and the additional in3 icon are created.
        $this->mediaRepository->expects(static::exactly(2))->method('create');
        $this->fileSaver->expects(static::exactly(2))->method('persistFileToMedia');
        $this->mediaRepository->expects(static::never())->method('delete');

        $installContext = $this->createMock(InstallContext::class);
        $installContext->method('getContext')->willReturn($this->context);

        $this->createInstaller()->install($installContext);
    }

    public function testUpdateLooksUpMediaAndPaymentMethodsInOneQueryEach(): void
    {
        $paymentMethods = $this->paymentMethodsWithMedia();
        $existing = array_slice($paymentMethods, 0, 1);

        $this->mediaRepository->expects(static::exactly(2))
            ->method('search')
            ->willReturnOnConsecutiveCalls(
                $this->searchResult('media', $this->mediaCollection($existing)),
                $this->searchResult('media', new MediaCollection())
            );

        $this->paymentMethodRepository->expects(static::once())
            ->method('search')
            ->willReturn($this->searchResult('payment_method', $this->paymentMethodCollection($paymentMethods)));

        // The existing icon is replaced, every gateway icon (plus in3) is re-created.
        $this->mediaRepository->expects(static::once())
            ->method('delete')
            ->with([['id' => $this->mediaId(reset($existing))]]);
        $this->mediaRepository->expects(static::exactly(count($paymentMethods) + 1))->method('create');
        $this->fileSaver->expects(static::exactly(count($paymentMethods) + 1))->method('persistFileToMedia');

        $updatedPaymentMethodIds = [];
        $this->paymentMethodRepository->expects(static::exactly(count($paymentMethods)))
            ->method('update')
            ->willReturnCallback(function (array $data) use (&$updatedPaymentMethodIds) {
                static::assertNotEmpty($data[0]['mediaId']);
                $updatedPaymentMethodIds[] = $data[0]['id'];

                return $this->createMock(EntityWrittenContainerEvent::class);
            });

        $updateContext = $this->createMock(UpdateContext::class);
        $updateContext->method('getContext')->willReturn($this->context);

        $this->createInstaller()->update($updateContext);

        $expectedIds = array_map(
            fn (PaymentMethodInterface $method) => $this->paymentMethodId($method),
            $paymentMethods
        );
        static::assertSame(array_values($expectedIds), $updatedPaymentMethodIds);
    }

    private function createInstaller(): MediaInstaller
    {
        $container = $this->createMock(ContainerInterface::class);
        $onInvalid = ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE;
        $container->method('get')->willReturnMap([
            ['media.repository', $onInvalid, $this->mediaRepository],
            ['media_folder.repository', $onInvalid, $this->mediaFolderRepository],
            [FileSaver::class, $onInvalid, $this->fileSaver],
            ['payment_method.repository', $onInvalid, $this->paymentMethodRepository],
            ['logger', $onInvalid, null],
        ]);

        return new MediaInstaller($container);
    }

    /**
     * @return array<PaymentMethodInterface>
     */
    private function paymentMethodsWithMedia(): array
    {
        $paymentMethods = [];
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $paymentMethod = new $gateway();
            if ($paymentMethod->getMedia()) {
                $paymentMethods[] = $paymentMethod;
            }
        }
        static::assertGreaterThan(1, count($paymentMethods));

        return $paymentMethods;
    }

    /**
     * @param array<PaymentMethodInterface> $paymentMethods
     */
    private function mediaCollection(array $paymentMethods): MediaCollection
    {
        $collection = new MediaCollection();
        foreach ($paymentMethods as $paymentMethod) {
            $media = new MediaEntity();
            $media->setId($this->mediaId($paymentMethod));
            $media->setFileName(md5($paymentMethod->getBuckarooKey()));
            $collection->add($media);
        }

        return $collection;
    }

    /**
     * @param array<PaymentMethodInterface> $paymentMethods
     */
    private function paymentMethodCollection(array $paymentMethods): PaymentMethodCollection
    {
        $collection = new PaymentMethodCollection();
        foreach ($paymentMethods as $paymentMethod) {
            $entity = new PaymentMethodEntity();
            $entity->setId($this->paymentMethodId($paymentMethod));
            $entity->setHandlerIdentifier($paymentMethod->getPaymentHandler());
            $collection->add($entity);
        }

        return $collection;
    }

    private function mediaId(PaymentMethodInterface $paymentMethod): string
    {
        return 'media-' . $paymentMethod->getBuckarooKey();
    }

    private function paymentMethodId(PaymentMethodInterface $paymentMethod): string
    {
        return 'payment-method-' . $paymentMethod->getBuckarooKey();
    }

    /**
     * @param EntityCollection<Entity> $entities
     */
    private function searchResult(string $entity, EntityCollection $entities): EntitySearchResult
    {
        return new EntitySearchResult($entity, $entities->count(), $entities, null, new Criteria(), $this->context);
    }
}
