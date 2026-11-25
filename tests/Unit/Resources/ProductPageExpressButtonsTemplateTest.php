<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;

/**
 * Guards the express buttons on the product page.
 *
 * Shopware renders the buy form of a product page from one of two core templates: product pages
 * with a layout (every product page from 6.6 on) use component/buy-widget/buy-widget-form.html.twig,
 * product pages without a layout on 6.5 use page/product-detail/buy-widget-form.html.twig.
 * A page renders only one of them. The plugin overrides both, and both include one shared template
 * with the buttons, so the two versions cannot drift apart.
 */
class ProductPageExpressButtonsTemplateTest extends TestCase
{
    private const VIEWS = 'src/Resources/views/storefront/';

    private const SHARED = 'buckaroo/product-express-buttons.html.twig';

    private const INCLUDE = "{% sw_include '@BuckarooPayments/storefront/" . self::SHARED . "' %}";

    /**
     * @return array<string, array{string, string}>
     */
    public static function overrideProvider(): array
    {
        return [
            'product page with a layout'     => [
                'component/buy-widget/buy-widget-form.html.twig',
                'buy_widget_buy_form_inner',
            ],
            'without a layout, Shopware 6.5' => [
                'page/product-detail/buy-widget-form.html.twig',
                'page_product_detail_buy_form_inner',
            ],
        ];
    }

    /**
     * @dataProvider overrideProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('overrideProvider')]
    public function testOverrideAddsTheSharedButtonsAfterTheCoreForm(string $template, string $block): void
    {
        $path = $this->pluginPath(self::VIEWS . $template);
        $this->assertFileExists($path);
        $source = (string)file_get_contents($path);

        $this->assertStringContainsString(
            "{% sw_extends '@Storefront/storefront/$template' %}",
            $source,
            "$template extends the core template of the same name"
        );
        $this->assertMatchesRegularExpression(
            '/\{% block ' . $block . ' %\}\s*\{\{ parent\(\) \}\}\s*'
                . preg_quote(self::INCLUDE, '/') . '\s*\{% endblock %\}/',
            $source,
            "$template keeps the core form and adds the shared buttons below it"
        );
        $this->assertSame(1, substr_count($source, 'sw_include'), "$template includes the buttons once");
        $this->assertStringNotContainsString(
            'express-checkout-buttons',
            $source,
            'The markup lives in the shared template'
        );
    }

    public function testSharedTemplateRendersEveryProductPageExpressButton(): void
    {
        $path = $this->pluginPath(self::VIEWS . self::SHARED);
        $this->assertFileExists($path);
        $source = (string)file_get_contents($path);

        $this->assertStringContainsString('{% if page.extensions.buckaroo %}', $source);
        $this->assertSame(1, substr_count($source, 'express-checkout-buttons.html.twig'));

        foreach (
            [
                'paypalOptions: buckaroo.showPaypalExpress ?',
                'applePayOptions: buckaroo.applepayShowProduct ?',
                'googlePayOptions: buckaroo.googlepayShowProduct ?',
                'idealOptions: buckaroo.showIdealFastCheckout ?',
            ] as $option
        ) {
            $this->assertStringContainsString($option, $source);
        }
        $this->assertSame(4, substr_count($source, 'page: "product"'), 'Every button knows it is on the product page');
    }

    private function pluginPath(string $relativePath): string
    {
        return __DIR__ . '/../../../' . $relativePath;
    }
}
