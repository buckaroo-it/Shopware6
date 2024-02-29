<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Media\Pathname\UrlGeneratorInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

class RiveryProductImageUrlService
{

    private EntityRepository $productRepository;

    private EntityRepository $thumbnailRepository;

    private ?UrlGeneratorInterface $legacyGenerator;

    public function __construct(
        EntityRepository $productRepository,
        EntityRepository $thumbnailRepository,
        ?UrlGeneratorInterface $legacyGenerator
    ) {
        $this->productRepository = $productRepository;
        $this->thumbnailRepository = $thumbnailRepository;
        $this->legacyGenerator = $legacyGenerator;
    }

    public function getImageUrl(string $productId, Context $context): ?string
    {
        $media = $this->getMedia($productId, $context);

        if ($media === null) {
            return null;
        }
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('mediaId', $media->getId()))
            ->addAssociations(['media', 'productMedia'])
            ->addFilter(
                new MultiFilter(
                    MultiFilter::CONNECTION_AND,
                    [
                        new RangeFilter('width', [
                            RangeFilter::GTE => 100,
                            RangeFilter::LTE => 1280
                        ]),
                        new RangeFilter('height', [
                            RangeFilter::GTE => 100,
                            RangeFilter::LTE => 1280
                        ])
                    ]
                )
            )
            ->addSorting(new FieldSorting('height', FieldSorting::DESCENDING));

        /** @var \Shopware\Core\Content\Media\Aggregate\MediaThumbnail\MediaThumbnailEntity */
        $thumbnail = $this->thumbnailRepository->search($criteria,  $context)->first();

        if ($this->legacyGenerator !== null) {
            return $this->legacyGenerator->getAbsoluteThumbnailUrl($media, $thumbnail);
        }
        return $thumbnail->getUrl();
    }

    private function getMedia(string $productId, Context $context): ?MediaEntity
    {
        $criteria = (new Criteria([$productId]))
            ->addAssociations(['cover', 'cover.thumbnails']);

        /** @var \Shopware\Core\Content\Product\ProductEntity */
        $product = $this->productRepository->search($criteria, $context)->first();
        if ($product) {
            return $product->getCover()
                ?->getMedia();
        }
        return null;
    }
}
