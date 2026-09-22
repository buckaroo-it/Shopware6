<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\BuckarooPayments;

/**
 * Integration test to verify plugin configuration and structure.
 */
class PluginConfigurationTest extends TestCase
{
    public function testPluginClassExists(): void
    {
        $this->assertTrue(
            class_exists(BuckarooPayments::class),
            "Plugin main class BuckarooPayments should exist"
        );
    }

    public function testPluginExtendsShopwarePlugin(): void
    {
        $this->assertTrue(
            is_subclass_of(BuckarooPayments::class, 'Shopware\Core\Framework\Plugin'),
            "BuckarooPayments should extend Shopware Plugin class"
        );
    }

    public function testPluginComposerJsonExists(): void
    {
        $composerPath = __DIR__ . '/../../composer.json';
        
        $this->assertFileExists(
            $composerPath,
            "composer.json should exist in plugin root"
        );
    }

    public function testPluginComposerJsonIsValid(): void
    {
        $composerPath = __DIR__ . '/../../composer.json';
        
        if (!file_exists($composerPath)) {
            $this->markTestSkipped('composer.json not found');
        }

        $content = file_get_contents($composerPath);
        $this->assertNotFalse($content, "composer.json should be readable");

        $decoded = json_decode($content, true);
        $this->assertNotNull($decoded, "composer.json should contain valid JSON");
        $this->assertIsArray($decoded);
    }

    public function testPluginComposerJsonHasRequiredFields(): void
    {
        $composerPath = __DIR__ . '/../../composer.json';
        
        if (!file_exists($composerPath)) {
            $this->markTestSkipped('composer.json not found');
        }

        $content = file_get_contents($composerPath);
        $config = json_decode($content, true);

        $this->assertArrayHasKey('name', $config);
        $this->assertArrayHasKey('type', $config);
        $this->assertEquals('shopware-platform-plugin', $config['type']);
    }

    public function testPluginHasServicesXml(): void
    {
        $servicesPath = __DIR__ . '/../../src/Resources/config/services.xml';
        
        $this->assertFileExists(
            $servicesPath,
            "services.xml should exist in plugin Resources/config"
        );
    }

    public function testPluginServicesXmlIsValid(): void
    {
        $servicesPath = __DIR__ . '/../../src/Resources/config/services.xml';
        
        if (!file_exists($servicesPath)) {
            $this->markTestSkipped('services.xml not found');
        }

        $content = file_get_contents($servicesPath);
        $this->assertNotFalse($content, "services.xml should be readable");

        // Verify it's valid XML
        $prevUseErrors = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prevUseErrors);

        $this->assertNotFalse($xml, "services.xml should be valid XML");
        $this->assertEmpty($errors, "services.xml should not have XML errors");
    }

    public function testPluginHasPaymentMethodDirectory(): void
    {
        $paymentMethodsPath = __DIR__ . '/../../src/PaymentMethods';
        
        $this->assertDirectoryExists(
            $paymentMethodsPath,
            "PaymentMethods directory should exist"
        );
    }

    public function testPluginHasHandlersDirectory(): void
    {
        $handlersPath = __DIR__ . '/../../src/Handlers';
        
        $this->assertDirectoryExists(
            $handlersPath,
            "Handlers directory should exist"
        );
    }

    public function testPluginHasServicesDirectory(): void
    {
        $servicesPath = __DIR__ . '/../../src/Service';
        
        $this->assertDirectoryExists(
            $servicesPath,
            "Service directory should exist"
        );
    }

    public function testPluginHasStorefrontDirectory(): void
    {
        $storefrontPath = __DIR__ . '/../../src/Storefront';
        
        $this->assertDirectoryExists(
            $storefrontPath,
            "Storefront directory should exist"
        );
    }

    public function testPluginNamespaceMatchesComposer(): void
    {
        $composerPath = __DIR__ . '/../../composer.json';
        
        if (!file_exists($composerPath)) {
            $this->markTestSkipped('composer.json not found');
        }

        $content = file_get_contents($composerPath);
        $config = json_decode($content, true);

        if (isset($config['autoload']['psr-4'])) {
            $namespaces = array_keys($config['autoload']['psr-4']);
            $this->assertContains(
                'Buckaroo\\Shopware6\\',
                $namespaces,
                "Plugin should have Buckaroo\\Shopware6\\ namespace in composer.json"
            );
        }
    }

    public function testPluginHasMinimumShopwareVersion(): void
    {
        $composerPath = __DIR__ . '/../../composer.json';
        
        if (!file_exists($composerPath)) {
            $this->markTestSkipped('composer.json not found');
        }

        $content = file_get_contents($composerPath);
        $config = json_decode($content, true);

        $this->assertArrayHasKey('require', $config);
        
        // Check if shopware/core is required
        $hasShopwareRequirement = false;
        foreach (array_keys($config['require']) as $package) {
            if (strpos($package, 'shopware') !== false) {
                $hasShopwareRequirement = true;
                break;
            }
        }
        
        $this->assertTrue(
            $hasShopwareRequirement,
            "Plugin should require a shopware package"
        );
    }
}
