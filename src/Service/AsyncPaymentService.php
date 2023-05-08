<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Service\UrlService;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Service\Buckaroo\ClientService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\PaymentStateService;
use Buckaroo\Shopware6\Service\StateTransitionService;

class AsyncPaymentService
{
    public ClientService $clientService;
    
    public SettingsService $settingsService;

    public UrlService $urlService;
    
    public StateTransitionService $stateTransitionService;

    public CheckoutHelper $checkoutHelper;

    /**
     * @var LoggerInterface
     */
    public $logger;
    
    public FormatRequestParamService $formatRequestParamService;
    
    public PaymentStateService $paymentStateService;
    /**
     * Buckaroo constructor.
     */
    public function __construct(
        SettingsService $settingsService,
        UrlService $urlService,
        StateTransitionService $stateTransitionService,
        ClientService $clientService,
        CheckoutHelper $checkoutHelper,
        LoggerInterface $logger,
        FormatRequestParamService $formatRequestParamService,
        PaymentStateService $paymentStateService
    ) {

        $this->settingsService = $settingsService;
        $this->urlService = $urlService;
        $this->stateTransitionService = $stateTransitionService;
        $this->clientService = $clientService;
        $this->checkoutHelper = $checkoutHelper;
        $this->logger = $logger;
        $this->formatRequestParamService = $formatRequestParamService;
        $this->paymentStateService = $paymentStateService;
    }
}