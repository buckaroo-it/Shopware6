<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\Service\Buckaroo\ClientService;
use Shopware\Core\Framework\Adapter\Cache\CacheValueCompressor;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class IdealIssuerService
{
    /**
     * @var string
     */
    protected const CACHE_KEY = 'buckaroo_ideal_issuers';

    /**
     * @var int
     */
    protected const CACHE_LIFETIME_SECONDS = 86400; // 24hours

    /**
     * @var array
     */
    protected const ISSUERS_IMAGES = [
        'ABNANL2A' => 'abnamro',
        'ASNBNL21' => 'asnbank',
        'INGBNL2A' => 'ing',
        'RABONL2U' => 'rabobank',
        'SNSBNL2A' => 'sns',
        'RBRBNL21' => 'regiobank',
        'TRIONL2U' => 'triodos',
        'FVLBNL22' => 'vanlanschot',
        'KNABNL2H' => 'knab',
        'BUNQNL2A' => 'bunq',
        'REVOLT21' => 'revolut',
        'REVONL22' => 'revolut',
        'BITSNL2A' => 'yoursafe',
        'NTSBDEB1' => 'n26',
        'NNBANL2G' => 'nn',
    ];

    protected CacheInterface $cache;

    protected ClientService $clientService;

    /**
     * @return void
     */
    public function __construct(
        CacheInterface $cache,
        ClientService $clientService
    ) {
        $this->cache = $cache;
        $this->clientService = $clientService;
    }

    /**
     * Get a list of issuers
     */
    public function get(string $salesChannelId): array
    {
        $issuers = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) use ($salesChannelId) {
            $item->expiresAfter(self::CACHE_LIFETIME_SECONDS);

            return CacheValueCompressor::compress(
                $this->addLogos(
                    $this->requestIssuers($salesChannelId)
                )
            );
        });

        $result = CacheValueCompressor::uncompress($issuers);

        if (is_array($result)) {
            return $result;
        }

        return [];
    }

    /**
     * Do request to the payment engine
     */
    private function requestIssuers(string $salesChannelId): array
    {
        return $this->clientService
            ->get('ideal', $salesChannelId)
            ->getIdealIssuers();
    }

    /**
     * Add logo to the list of issuer
     */
    private function addLogos(array $issuers): array
    {
        return array_map(
            function ($issuer) {
                $logo = null;
                if (
                    isset($issuer['id'])
                    && isset(self::ISSUERS_IMAGES[$issuer['id']])
                ) {
                    $logo = self::ISSUERS_IMAGES[$issuer['id']];
                }
                $issuer['imgName'] = $logo;

                return $issuer;
            },
            $issuers
        );
    }
}
