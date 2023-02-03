<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo;

use Buckaroo\BuckarooClient;

class Client
{
    protected BuckarooClient $client;

    protected string $paymentCode;

    public function __construct(string $websiteKey, string $secretKey, string $paymentCode, string $mode = 'live')
    {
        $this->client = new BuckarooClient(
            $websiteKey,
            $secretKey,
            $mode
        );
        $this->paymentCode = $paymentCode;
    }

    /**
     * Execute buckaroo request
     *
     * @param array<mixed> $payload
     * @param string $action
     *
     * @return ClientResponseInterface
     * @throws \Exception
     */
    public function execute(array $payload, string $action = 'pay'): ClientResponseInterface
    {
        return new ClientResponse(
            $this->client->method($this->paymentCode)->$action($payload)
        );
    }
}
