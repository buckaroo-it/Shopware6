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
     *
     * @var array
     */
    protected array $services = [];

    public function __construct(
        string $websiteKey,
        string $secretKey,
        string $paymentCode,
        string $mode = 'live',
        string $shopwareVersion = 'unknown'
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
                InstalledVersions::getVersion('buckaroo/shopware6')
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
     * @return ClientResponseInterface
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
//dump($request);
//dump($this->action);
//dump($this->payload);
//die

        return new ClientResponse(
            $request->{$this->action}($this->payload)
        );
    }

    /**
     * Add additional services to the request
     * @param mixed $service
     * @return self
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
     * @param string $action
     * @param array $payload
     * @param string|null $method
     * @return mixed
     */
    public function build(string $action, array $payload, string $method = null)
    {
        if ($method === null) {
            $method = $this->paymentCode;
        }

        return $this->client->method($method)->manually()->$action($payload);
    }

    /**
     * Set payment code
     *
     * @param string $paymentCode
     * @return self
     */
    public function setPaymentCode(string $paymentCode): self
    {
        $this->paymentCode = $paymentCode;
        return $this;
    }

    /**
     * Get main payload
     * @return array
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * Set main payload
     * @param array<mixed> $payload
     * @return self
     */
    public function setPayload(array $payload): self
    {
        $this->payload = $payload;
        return $this;
    }

    /**
     * Set main action
     * @return self
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
     *
     * @return array
     */
    public function getIdealIssuers(): array
    {
        return $this->client->method($this->paymentCode)->issuers(); // @phpstan-ignore-line
    }
}
