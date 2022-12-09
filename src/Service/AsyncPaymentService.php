<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Buckaroo\BkrClient;
use Buckaroo\Shopware6\Service\UrlService;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\StateTransitionService;

class AsyncPaymentService
{
    public BkrClient $client;
    
    public SettingsService $settingsService;

    public UrlService $urlService;
    
    public StateTransitionService $stateTransitionService;

    public CheckoutHelper $checkoutHelper;

    /**
     * @var LoggerInterface
     */
    public $logger;

    public FormatRequestParamService $formatRequestParamService;

    /**
     * Buckaroo constructor.
     */
    public function __construct(
        SettingsService $settingsService,
        UrlService $urlService,
        StateTransitionService $stateTransitionService,
        BkrClient $client,
        CheckoutHelper $checkoutHelper,
        LoggerInterface $logger,
        FormatRequestParamService $formatRequestParamService
    ) {

        $this->settingsService = $settingsService;
        $this->urlService = $urlService;
        $this->stateTransitionService = $stateTransitionService;
        $this->client = $client;
        $this->checkoutHelper = $checkoutHelper;
        $this->logger = $logger;
        $this->formatRequestParamService = $formatRequestParamService;
    }
}