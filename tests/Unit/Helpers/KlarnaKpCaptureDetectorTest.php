<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\Helpers\KlarnaKpCaptureDetector;
use Symfony\Component\HttpFoundation\Request;

/**
 * Payloads below are real pushes taken from a production log, with signatures and
 * personal data removed.
 */
class KlarnaKpCaptureDetectorTest extends TestCase
{
    /**
     * @param array<string, string> $post
     * @dataProvider pushProvider
     */
    public function testIsEngineCapture(bool $expected, array $post, string $case): void
    {
        $this->assertSame(
            $expected,
            KlarnaKpCaptureDetector::isEngineCapture(new Request([], $post)),
            $case
        );
    }

    /**
     * @return array<string, array{0: bool, 1: array<string, string>, 2: string}>
     */
    public static function pushProvider(): array
    {
        return [
            'klarnakp V610 pay push with a capture id' => [
                true,
                [
                    'brq_amount'                      => '273.95',
                    'brq_mutationtype'                => 'Processing',
                    'brq_SERVICE_klarnakp_CaptureId'  => '7b20ed3d-bb0e-42c2-a7b8-bef3d951651a',
                    'brq_statuscode'                  => '190',
                    'brq_transaction_method'          => 'KlarnaKp',
                    'brq_transaction_type'            => 'V610',
                ],
                'the reservation was paid; captured must be recorded',
            ],
            'klarnakp reserve push carrying an AutoPay transaction key' => [
                true,
                [
                    'brq_primary_service'                            => 'KlarnaKp',
                    'brq_SERVICE_klarnakp_AutoPayTransactionKey'      => '13B097483CEB40A88EB85FB6AA83D71A',
                    'brq_SERVICE_klarnakp_ReservationNumber'          => '8ff10537-f735-4485-bdc8-3b19dd50f733',
                    'brq_statuscode'                                 => '190',
                ],
                'AutoPay paid the reservation without CaptureService running',
            ],
            'klarnakp V610 without a capture id still counts as a pay' => [
                true,
                [
                    'brq_transaction_method' => 'klarnakp',
                    'brq_transaction_type'   => 'V610',
                    'brq_statuscode'         => '190',
                ],
                'transaction type alone identifies a pay on reservation',
            ],
            'klarnakp reserve push without AutoPay' => [
                false,
                [
                    'brq_primary_service'                   => 'KlarnaKp',
                    'brq_SERVICE_klarnakp_ReservationNumber' => '04dcc442-1688-4047-87c4-e8441ddec5e6',
                    'brq_statuscode'                        => '190',
                ],
                'a plain reservation is not a capture; capture-on-shipment must stay available',
            ],
            'klarnakp 491 validation failure' => [
                false,
                [
                    'brq_amount'             => '120.95',
                    'brq_mutationtype'       => 'NotSet',
                    'brq_statuscode'         => '491',
                    'brq_statusmessage'      => 'Validation failure',
                    'brq_transaction_method' => 'KlarnaKp',
                ],
                'a rejected pay is not a capture',
            ],
            'klarnakp refund push' => [
                false,
                [
                    'brq_amount_credit'                => '280.00',
                    'brq_mutationtype'                 => 'Processing',
                    'brq_SERVICE_klarnakp_Processed'    => 'Classic',
                    'brq_statuscode'                   => '190',
                    'brq_transaction_method'           => 'klarnakp',
                ],
                'a refund is not a capture',
            ],
            'klarna MoR push is out of scope' => [
                false,
                [
                    'brq_primary_service'                => 'klarna',
                    'brq_SERVICE_klarna_DataRequestKey'  => '65105EB3A3704E6D8FDCF8A1DCE8DD9E',
                    'brq_statuscode'                     => '190',
                ],
                'MoR has its own dataRequestKey bookkeeping',
            ],
            'unrelated ideal push' => [
                false,
                [
                    'brq_amount'             => '138.00',
                    'brq_statuscode'         => '190',
                    'brq_transaction_method' => 'ideal',
                    'brq_transaction_type'   => 'C021',
                ],
                'non Klarna KP methods are never affected',
            ],
            'empty push' => [
                false,
                [],
                'must not blow up on a payload without a method',
            ],
        ];
    }
}
