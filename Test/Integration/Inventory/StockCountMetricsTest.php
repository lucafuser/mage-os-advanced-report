<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Inventory;

use Magento\Catalog\Test\Fixture\Product;
use Magento\Checkout\Test\Fixture\PlaceOrder;
use Magento\Checkout\Test\Fixture\SetBillingAddress;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod;
use Magento\Checkout\Test\Fixture\SetGuestEmail;
use Magento\Checkout\Test\Fixture\SetPaymentMethod;
use Magento\Checkout\Test\Fixture\SetShippingAddress;
use Magento\InventoryApi\Test\Fixture\DeleteSourceItems;
use Magento\InventoryApi\Test\Fixture\SourceItems;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use MageOS\AdvancedReport\Test\Integration\Fixture\Source;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Distinct SKU counts `skus_in_stock` (`catalog_stock`) and `skus_salable` (`catalog_salable`, default stock 1,
 * single-source mode). Every test method declares its full fixture chain (method-level fixtures replace class-level
 * ones). Sources ar-sc-w1 and ar-sc-w2 are enabled, ar-sc-off is disabled; none is linked to a stock (no DDL).
 *
 * `skus_in_stock` has no stock-status term: a SKU counts when any enabled-source row has quantity > 0.
 *  | test                          | rows (sku, source, quantity, status)                 | skus_in_stock          |
 *  | status and zero quantity      | a w1 5 st0; b w1 0 st1; c w1 4 st1                   | 2 (a, c; b has 0)      |
 *  | two sources                   | a w1 10, a w2 5; b w1 0, b w2 0                      | 1 (a once, b zero)     |
 *  | disabled source               | a w1 3, a off 100; b off 7                           | 1 (a; b only on off)   |
 *
 * `skus_salable` counts SKUs whose salable quantity (index quantity + reservations - minimum quantity 0) is > 0.
 *  | test                          | stock quantity, placed order                         | salable quantity       |
 *  | positive after reservations   | a 10, order 4; b 5, no order; c 0 (out of stock)     | a 6, b 5, c 0 => 2     |
 *  | fully reserved                | a 3, order 3; b 5, no order                          | a 0, b 5 => 1          |
 */
#[DbIsolation(true), AppIsolation(true), AppArea('adminhtml')]
final class StockCountMetricsTest extends TestCase
{
    private const array STOCK_SKUS = ['ar-sc-a', 'ar-sc-b', 'ar-sc-c'];

    private ReportTestRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new ReportTestRunner();
        $this->runner->login();
    }

    protected function tearDown(): void
    {
        $this->runner->logout();
    }

    #[
        DataFixture(Product::class, ['sku' => 'ar-sc-a', 'price' => 10]),
        DataFixture(Product::class, ['sku' => 'ar-sc-b', 'price' => 10]),
        DataFixture(Product::class, ['sku' => 'ar-sc-c', 'price' => 10]),
        DataFixture(
            DeleteSourceItems::class,
            [['sku' => 'ar-sc-a', 'source_code' => 'default'], ['sku' => 'ar-sc-b', 'source_code' => 'default'], ['sku' => 'ar-sc-c', 'source_code' => 'default']]
        ),
        DataFixture(Source::class, ['source_code' => 'ar-sc-w1', 'enabled' => true]),
        DataFixture(Source::class, ['source_code' => 'ar-sc-w2', 'enabled' => true]),
        DataFixture(Source::class, ['source_code' => 'ar-sc-off', 'enabled' => false]),
        DataFixture(
            SourceItems::class,
            [
                ['sku' => 'ar-sc-a', 'source_code' => 'ar-sc-w1', 'quantity' => 5, 'status' => 0],
                ['sku' => 'ar-sc-b', 'source_code' => 'ar-sc-w1', 'quantity' => 0, 'status' => 1],
                ['sku' => 'ar-sc-c', 'source_code' => 'ar-sc-w1', 'quantity' => 4, 'status' => 1],
            ]
        ),
    ]
    public function testItCountsSkusWithPositiveQuantityAsInStockEvenWhenTheSourceItemStatusIsOutOfStockAndExcludesZeroQuantity(): void
    {
        $this->assertEquals(2, $this->skusInStock());
    }

    #[
        DataFixture(Product::class, ['sku' => 'ar-sc-a', 'price' => 10]),
        DataFixture(Product::class, ['sku' => 'ar-sc-b', 'price' => 10]),
        DataFixture(
            DeleteSourceItems::class,
            [['sku' => 'ar-sc-a', 'source_code' => 'default'], ['sku' => 'ar-sc-b', 'source_code' => 'default']]
        ),
        DataFixture(Source::class, ['source_code' => 'ar-sc-w1', 'enabled' => true]),
        DataFixture(Source::class, ['source_code' => 'ar-sc-w2', 'enabled' => true]),
        DataFixture(Source::class, ['source_code' => 'ar-sc-off', 'enabled' => false]),
        DataFixture(
            SourceItems::class,
            [
                ['sku' => 'ar-sc-a', 'source_code' => 'ar-sc-w1', 'quantity' => 10, 'status' => 1],
                ['sku' => 'ar-sc-a', 'source_code' => 'ar-sc-w2', 'quantity' => 5, 'status' => 1],
                ['sku' => 'ar-sc-b', 'source_code' => 'ar-sc-w1', 'quantity' => 0, 'status' => 1],
                ['sku' => 'ar-sc-b', 'source_code' => 'ar-sc-w2', 'quantity' => 0, 'status' => 1],
            ]
        ),
    ]
    public function testItDoesNotCountASkuTwiceWhenItHasQuantityOnTwoSources(): void
    {
        $this->assertEquals(1, $this->skusInStock());
    }

    #[
        DataFixture(Product::class, ['sku' => 'ar-sc-a', 'price' => 10]),
        DataFixture(Product::class, ['sku' => 'ar-sc-b', 'price' => 10]),
        DataFixture(
            DeleteSourceItems::class,
            [['sku' => 'ar-sc-a', 'source_code' => 'default'], ['sku' => 'ar-sc-b', 'source_code' => 'default']]
        ),
        DataFixture(Source::class, ['source_code' => 'ar-sc-w1', 'enabled' => true]),
        DataFixture(Source::class, ['source_code' => 'ar-sc-w2', 'enabled' => true]),
        DataFixture(Source::class, ['source_code' => 'ar-sc-off', 'enabled' => false]),
        DataFixture(
            SourceItems::class,
            [
                ['sku' => 'ar-sc-a', 'source_code' => 'ar-sc-w1', 'quantity' => 3, 'status' => 1],
                ['sku' => 'ar-sc-a', 'source_code' => 'ar-sc-off', 'quantity' => 100, 'status' => 1],
                ['sku' => 'ar-sc-b', 'source_code' => 'ar-sc-off', 'quantity' => 7, 'status' => 1],
            ]
        ),
    ]
    public function testItExcludesSkusOnDisabledSourcesFromSkusInStock(): void
    {
        $this->assertEquals(1, $this->skusInStock());
    }

    #[
        Config('cataloginventory/item_options/min_qty', '0'),
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        DataFixture(
            Product::class,
            [
                'sku' => 'ar-sc-a',
                'price' => 10,
                'tax_class_id' => 0,
                'extension_attributes' => ['stock_item' => ['qty' => 10, 'is_in_stock' => true]],
            ],
            'producta'
        ),
        DataFixture(
            Product::class,
            [
                'sku' => 'ar-sc-b',
                'price' => 10,
                'tax_class_id' => 0,
                'extension_attributes' => ['stock_item' => ['qty' => 5, 'is_in_stock' => true]],
            ],
            'productb'
        ),
        DataFixture(
            Product::class,
            [
                'sku' => 'ar-sc-c',
                'price' => 10,
                'tax_class_id' => 0,
                'extension_attributes' => ['stock_item' => ['qty' => 0, 'is_in_stock' => false]],
            ],
            'productc'
        ),
        DataFixture(GuestCart::class, as: 'carta'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$carta.id$', 'product_id' => '$producta.id$', 'qty' => 4]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$carta.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$carta.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$carta.id$', 'email' => 'ar-sc-a@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$carta.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$carta.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$carta.id$'], 'ordera'),
    ]
    public function testItCountsSkusWithPositiveSalableQuantityAfterReservationsAsSalable(): void
    {
        $this->assertEquals(2, $this->skusSalable());
    }

    #[
        Config('cataloginventory/item_options/min_qty', '0'),
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        DataFixture(
            Product::class,
            [
                'sku' => 'ar-sc-a',
                'price' => 10,
                'tax_class_id' => 0,
                'extension_attributes' => ['stock_item' => ['qty' => 3, 'is_in_stock' => true]],
            ],
            'producta'
        ),
        DataFixture(
            Product::class,
            [
                'sku' => 'ar-sc-b',
                'price' => 10,
                'tax_class_id' => 0,
                'extension_attributes' => ['stock_item' => ['qty' => 5, 'is_in_stock' => true]],
            ],
            'productb'
        ),
        DataFixture(GuestCart::class, as: 'carta'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$carta.id$', 'product_id' => '$producta.id$', 'qty' => 3]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$carta.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$carta.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$carta.id$', 'email' => 'ar-sc-a@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$carta.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$carta.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$carta.id$'], 'ordera'),
    ]
    public function testItExcludesASkuFullyReservedByPlacedOrdersFromSkusSalable(): void
    {
        $this->assertEquals(1, $this->skusSalable());
    }

    private function skusInStock(): int
    {
        $result = $this->runner->run(
            'catalog_stock',
            ['skus_in_stock'],
            [],
            [['field' => 'sku', 'operator' => 'in', 'value' => self::STOCK_SKUS]]
        );

        return (int) $this->runner->totals($result)['skus_in_stock'];
    }

    private function skusSalable(): int
    {
        $result = $this->runner->run(
            'catalog_salable',
            ['skus_salable'],
            [],
            [
                ['field' => 'sku', 'operator' => 'in', 'value' => self::STOCK_SKUS],
                ['field' => 'stock_id', 'operator' => 'in', 'value' => [1]],
            ]
        );

        return (int) $this->runner->totals($result)['skus_salable'];
    }
}
