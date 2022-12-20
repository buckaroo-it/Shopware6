<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo;

use Buckaroo\BuckarooClient;

class Client
{
    protected BuckarooClient $client;

    public function __construct(string $websiteKey, string $secretKey, string $mode = 'live')
    {
        $this->client = new BuckarooClient(
            $websiteKey,
            $secretKey,
            $mode
        );
    }

    /**
     * Execute buckaroo request
     *
     * @param string $paymentCode
     * @param string $action
     * @param array $payload
     *
     * @return ClientResponseInterface
     * @throws \Exception
     */
    public function execute(string $paymentCode, array $payload, string $action = 'pay'): ClientResponseInterface
    {
        return new ClientResponse(
            $this->client->method($paymentCode)->$action($payload)
        );
    }
}
