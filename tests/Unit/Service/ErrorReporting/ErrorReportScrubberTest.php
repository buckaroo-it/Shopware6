<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service\ErrorReporting;

use Buckaroo\Shopware6\Service\ErrorReporting\ErrorReportScrubber;
use PHPUnit\Framework\TestCase;

class ErrorReportScrubberTest extends TestCase
{
    private ErrorReportScrubber $scrubber;

    protected function setUp(): void
    {
        $this->scrubber = new ErrorReportScrubber();
    }

    /**
     * @dataProvider stringProvider
     */
    public function testScrubString(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->scrubber->scrubString($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function stringProvider(): array
    {
        return [
            'card number' => ['card 4111111111111111 declined', 'card [CARD] declined'],
            'card with spaces' => ['card 4111 1111 1111 1111', 'card [CARD]'],
            'card with dashes' => ['card 5500-0000-0000-0004', 'card [CARD]'],
            '13 digits' => ['pan 4222222222222', 'pan [CARD]'],
            '19 digits' => ['pan 6011000990139424124', 'pan [CARD]'],
            'long order number stays' => ['order 1000001234567 at 1730716800000', 'order 1000001234567 at 1730716800000'],
            'short numbers stay' => ['order 100000123 line 42', 'order 100000123 line 42'],
            'email' => ['mail john.doe+shop@example.co.uk now', 'mail [EMAIL] now'],
            'iban' => ['iban NL91ABNA0417164300', 'iban [IBAN]'],
            'iban with spaces' => ['iban DE89 3704 0044 0532 0130 00', 'iban [IBAN]'],
            'password pair' => ['password=hunter2&user=x', 'password=[FILTERED]&user=x'],
            'json secret' => ['{"secretKey":"abc123","a":"b"}', '{"secretKey":"[FILTERED]","a":"b"}'],
            'php array token' => ["'access_token' => 'xyz'", "'access_token' => '[FILTERED]'"],
            'api key colon' => ['api_key: 0123abcd', 'api_key: [FILTERED]'],
            'plain message' => ['Cannot initiate buckaroo sdk client', 'Cannot initiate buckaroo sdk client'],
        ];
    }

    public function testScrubArrayMasksSensitiveKeysAndValues(): void
    {
        $result = $this->scrubber->scrubArray([
            'password' => 'hunter2',
            'secretKey' => 'abc',
            'api_key' => 'k',
            'token' => ['nested' => 'value'],
            'email' => 'a@b.nl',
            'nested' => ['card' => '4111111111111111', 'count' => 3],
        ]);

        $this->assertSame([
            'password' => '[FILTERED]',
            'secretKey' => '[FILTERED]',
            'api_key' => '[FILTERED]',
            'token' => '[FILTERED]',
            'email' => '[EMAIL]',
            'nested' => ['card' => '[CARD]', 'count' => 3],
        ], $result);
    }

    public function testInvalidUtf8IsStillScrubbed(): void
    {
        $this->assertSame("bad \xB1x [CARD]", $this->scrubber->scrubString("bad \xB1x 4111111111111111"));
    }
}
