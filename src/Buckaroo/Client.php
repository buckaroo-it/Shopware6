<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo;

use Buckaroo\BuckarooClient;
use Buckaroo\Shopware6\Buckaroo\PayloadFragmentInterface;

class Client
{
    protected BuckarooClient $client;

    protected string $paymentCode;

    /**
     * Additional services
     *
     * @var array
     */
    protected array $services = [];

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
        $request =  $this->client->method($this->paymentCode);

        if(count($this->services)) {
            foreach($this->services as $service) {
                $request->combine($service);
            }
        }
        return new ClientResponse(
           $request->$action($payload)
        );
    }

    /**
     * Add additional services to the request
     * 
     * @param mixed $service
     * @return void
     */
    public function addService($service): void
    {
        $this->services[] = $service;
    }

    /**
     * Get additonal services
     */
    public function getServices(): array
    {
        return  $this->services;
    }

    /**
     * Build a service object
     * @param string $action
     * @param array $payload
     * @param string|null $method
     * 
     * @return mixed
     */
    public function build(string $action, array $payload, string $method = null)
    {
        if($method === null) {
            $method = $this->paymentCode;
        }

        return $this->client->method($method)->manually()->$action($payload);
    }
}
