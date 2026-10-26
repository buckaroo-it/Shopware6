<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Struct;

use Buckaroo\Shopware6\Storefront\Struct\BuckarooStruct;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The subscriber assign()s template variables onto BuckarooStruct. On PHP 8.2+
 * this must not create dynamic properties (deprecation), and templates must
 * still read the values as page.extensions.buckaroo.<name>.
 */
class BuckarooStructTest extends TestCase
{
    public function testAssignDoesNotCreateDynamicProperties(): void
    {
        $deprecations = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$deprecations): bool {
            $deprecations[] = $errstr;
            return true;
        }, E_DEPRECATED | E_USER_DEPRECATED);

        try {
            $struct = (new BuckarooStruct())->assign([
                'websiteKey'  => 'key',
                'showIssuers' => true,
                'creditcard'  => [['code' => 'visa', 'name' => 'Visa']],
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecations);
        $this->assertArrayNotHasKey('websiteKey', get_object_vars($struct));
        $this->assertSame('key', $struct->get('websiteKey'));
        $this->assertTrue($struct->get('showIssuers'));
    }

    public function testTwigReadsAssignedValuesAsAttributes(): void
    {
        $struct = (new BuckarooStruct())->assign([
            'backLink'      => '/restore',
            'buckarooFee'   => null,
            'creditcard'    => [['code' => 'visa'], ['code' => 'amex']],
        ]);

        $twig = new Environment(new ArrayLoader([
            'tpl' => '{{ b.backLink }}|{{ b.buckarooFee is null ? "null" : "set" }}|'
                . '{{ b.creditcard|length }}|{% for c in b.creditcard %}{{ c.code }} {% endfor %}|'
                . '{{ b.missing is defined ? "defined" : "undefined" }}',
        ]));

        $this->assertSame(
            '/restore|null|2|visa amex |undefined',
            $twig->render('tpl', ['b' => $struct])
        );
    }

    public function testExtensionNameIsUnchanged(): void
    {
        $this->assertSame('buckaroo', BuckarooStruct::EXTENSION_NAME);
    }
}
