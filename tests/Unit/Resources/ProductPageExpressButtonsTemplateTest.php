<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;

/**
 * The express buttons on the product page come from two overrides: one for product pages with a
 * layout (Shopware 6.5 to 6.7) and one for product pages without a layout (Shopware 6.5, BTI-2062).
 * Both add the same shared template after the add to cart form, so they can't drift apart.
 */
class ProductPageExpressButtonsTemplateTest extends TestCase
{
    private const SHARED = '@BuckarooPayments/storefront/buckaroo/product-express-buttons.html.twig';

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function overrideProvider(): array
    {
        return [
            'product page with a layout' => [
                'src/Resources/views/storefront/component/buy-widget/buy-widget-form.html.twig',
                '@Storefront/storefront/component/buy-widget/buy-widget-form.html.twig',
                'buy_widget_buy_form_inner',
            ],
            'product page without a layout (Shopware 6.5)' => [
                'src/Resources/views/storefront/page/product-detail/buy-widget-form.html.twig',
                '@Storefront/storefront/page/product-detail/buy-widget-form.html.twig',
                'page_product_detail_buy_form_inner',
            ],
        ];
    }

    /**
     * @dataProvider overrideProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('overrideProvider')]
    public function testOverrideAddsTheSharedButtonsAfterTheForm(string $file, string $parent, string $block): void
    {
        $path = $this->pluginPath($file);
        $this->assertFileExists($path);
        $source = (string)file_get_contents($path);

        $this->assertStringContainsString("{% sw_extends '$parent' %}", $source);
        $this->assertMatchesRegularExpression(
            '/\{%\s*block ' . $block . '\s*%\}\s*\{\{\s*parent\(\)\s*\}\}\s*\{%\s*sw_include \'' . preg_quote(self::SHARED, '/') . '\'\s*%\}\s*\{%\s*endblock\s*%\}/',
            $source,
            "$file adds the shared buttons after the form in $block"
        );
        $this->assertStringNotContainsString('express-checkout-buttons.html.twig', $source, 'Button options belong in the shared template');
    }

    public function testSharedTemplateSetsUpEveryExpressButtonForTheProductPage(): void
    {
        $source = (string)file_get_contents($this->pluginPath('src/Resources/views/storefront/buckaroo/product-express-buttons.html.twig'));

        $this->assertStringContainsString('@BuckarooPayments/storefront/buckaroo/express-checkout-buttons.html.twig', $source);
        foreach (['paypalOptions', 'applePayOptions', 'googlePayOptions', 'idealOptions'] as $options) {
            $this->assertMatchesRegularExpression("/$options:[^}]*page: \"product\"/s", $source, "$options on the product page");
        }
    }

    private function pluginPath(string $relativePath): string
    {
        return __DIR__ . '/../../../' . $relativePath;
    }
}
