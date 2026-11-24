<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo;

use Buckaroo\BuckarooClient;
use Buckaroo\Config\DefaultConfig;
use Composer\InstalledVersions;

class Client
{
    protected BuckarooClient $client;

    protected string $paymentCode;

    protected array $payload = [];

    protected string $action = 'pay';

    protected ?int $version = null;

    /**
     * Additional services
     */
    protected array $services = [];

    public function __construct(
        string $websiteKey,
        string $secretKey,
        string $paymentCode,
        string $mode = 'live',
        string $shopwareVersion = 'unknown',
        ?string $culture = null
    ) {
        $this->client = new BuckarooClient(
            new DefaultConfig(
                $websiteKey,
                $secretKey,
                $mode,
                null,
                null,
                null,
                null,
                'Shopware (6)',
                $shopwareVersion,
                'Buckaroo',
                'BuckarooPayments',
                InstalledVersions::getVersion('buckaroo/shopware6'),
                $culture
            )
        );
        $this->paymentCode = $paymentCode;
    }

    public function setServiceVersion(int $serviceVersion): self
    {
        $this->version = $serviceVersion;

        return $this;
    }

    /**
     * Execute buckaroo request
     *
     * @throws \Exception
     */
    public function execute(): ClientResponseInterface
    {
        $request = $this->client->method($this->paymentCode);
        if (count($this->services)) {
            foreach ($this->services as $service) {
                $request->combine($service);
            }
        }
        if (
            $this->version !== null
        ) {
            $request->setServiceVersion($this->version);
        }

        return new ClientResponse(
            $request->{$this->action}($this->payload)
        );
    }

    /**
     * Add additional services to the request
     *
     * @param mixed $service
     */
    public function addService($service): self
    {
        $this->services[] = $service;

        return $this;
    }

    /**
     * Get additonal services
     */
    public function getServices(): array
    {
        return $this->services;
    }

    /**
     * Build a service object
     *
     * @return mixed
     */
    public function build(string $action, array $payload, ?string $method = null)
    {
        if ($method === null) {
            $method = $this->paymentCode;
        }

        return $this->client->method($method)->manually()->$action($payload);
    }

    /**
     * Set payment code
     */
    public function setPaymentCode(string $paymentCode): self
    {
        $this->paymentCode = $paymentCode;

        return $this;
    }

    /**
     * Get main payload
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * Set main payload
     *
     * @param array<mixed> $payload
     */
    public function setPayload(array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    /**
     * Set main action
     */
    public function setAction(string $action): self
    {
        $this->action = $action;

        return $this;
    }

    /**
     * Get main action
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * Get ideal issuers
     */
    public function getIdealIssuers(): array
    {
        return $this->client->method($this->paymentCode)->issuers(); // @phpstan-ignore-line
    }
}
