<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Buckaroo\Shopware6\Handlers\PayByBankPaymentHandler;
use Symfony\Component\Asset\Packages;

class PayByBankService
{
    protected array $payByBankIssuers = [
        [
            'name' => 'ABN AMRO',
            'code' => 'ABNANL2A',
            'imgName' => 'abnamro'
        ],
        [
            'name' => 'ASN Bank',
            'code' => 'ASNBNL21',
            'imgName' => 'asnbank'
        ],
        [
            'name' => 'ING',
            'code' => 'INGBNL2A',
            'imgName' => 'ing'
        ],
        [
            'name' => 'Knab Bank',
            'code' => 'KNABNL2H',
            'imgName' => 'knab'
        ],
        [
            'name' => 'Rabobank',
            'code' => 'RABONL2U',
            'imgName' => 'rabobank'
        ],
        [
            'name' => 'RegioBank',
            'code' => 'RBRBNL21',
            'imgName' => 'regiobank'
        ],
        [
            'name' => 'SNS Bank',
            'code' => 'SNSBNL2A',
            'imgName' => 'sns'
        ],
        [
            'name' => 'N26',
            'code' => 'NTSBDEB1',
            'imgName' => 'n26'
        ]
    ];

    protected Packages $packages;

    public function __construct(Packages $packages)
    {
        $this->packages = $packages;
    }


    /**
     * Get list of issuers
     *
     * @param CustomerEntity|null $customer
     *
     * @return array
     */
    public function getIssuers(CustomerEntity $customer = null): array
    {
        $savedBankIssuer = null;
        if ($customer !== null) {
            $savedBankIssuer = $customer->getCustomFieldsValue(PayByBankPaymentHandler::ISSUER_LABEL);
        }

        return array_map(function ($issuer) use ($savedBankIssuer) {
            $issuer['selected'] = is_scalar($savedBankIssuer) &&
                isset($issuer['code']) &&
                $issuer['code'] === $savedBankIssuer;
            return $issuer;
        }, $this->payByBankIssuers);
    }

    /**
     * Get issuer logo based on code
     *
     * @param string $issuerCode
     *
     * @return string
     */
    public function getIssuerLogo(string $issuerCode): string
    {
        $img = '';
        foreach ($this->payByBankIssuers as $issuer) {
            if ($issuer['code'] === $issuerCode) {
                $img = $issuer['imgName'];
                break;
            }
        }
        return $this->packages->getUrl(
            "/bundles/buckaroopayments/storefront/buckaroo/issuers/{$img}.svg"
        );
    }

    public function getActiveIssuerLogo(CustomerEntity $customer = null): ?string
    {
        $issuers = $this->getIssuers($customer);
        $active = null;

        foreach ($issuers as $issuer) {
            if ($issuer['selected']) {
                $active = $issuer;
                break;
            }
        }

        if ($active === null) {
            return null;
        }

        return $this->getIssuerLogo($active['code']);
    }
}