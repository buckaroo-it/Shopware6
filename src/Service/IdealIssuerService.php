<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Buckaroo\Shopware6\Service\Buckaroo\ClientService;
use Shopware\Core\Framework\Adapter\Cache\CacheValueCompressor;

class IdealIssuerService
{
    /**
     * @var string
     */
    protected const CACHE_KEY = 'buckaroo_ideal_issuers';

    /**
     * @var int
     */
    protected const CACHE_LIFETIME_SECONDS = 86400; //24hours

    /**
     * @var CacheInterface
     */
    protected CacheInterface $cache;

    protected ClientService $clientService;

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
        'BITSNL2A' => 'yoursafe',
        'NTSBDEB1' => 'n26',
        'NNBANL2G' => 'nn'
    ];

    /**
     * @param CacheInterface $cache
     * @param ClientService $clientService
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
     *
     * @return array
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



    private function requestIssuers(string $salesChannelId): array
    {
        return $this->clientService
            ->get('ideal', $salesChannelId)
            ->getIdealIssuers();
    }



    /**
     * Add logo to the list of issuer
     *
     * @param array $issuers
     * @return array
     */
    private function addLogos(array $issuers): array
    {
        return array_map(
            function ($issuer) {
                $logo = null;
                if (
                    isset($issuer['id']) &&
                    isset(self::ISSUERS_IMAGES[$issuer['id']])
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
