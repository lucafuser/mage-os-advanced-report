<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Performance;

use Magento\Catalog\Test\Fixture\Product;
use Magento\Checkout\Test\Fixture\PlaceOrder;
use Magento\Checkout\Test\Fixture\SetBillingAddress;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod;
use Magento\Checkout\Test\Fixture\SetGuestEmail;
use Magento\Checkout\Test\Fixture\SetPaymentMethod;
use Magento\Checkout\Test\Fixture\SetShippingAddress;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Data\Form\FormKey;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractBackendController;
use MageOS\AdvancedReport\Model\DataSource\Mysql\Guardrails;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ServerFlavour;
use MageOS\AdvancedReport\Model\DataSource\ReportConnectionUnavailableException;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use MageOS\AdvancedReport\Test\Integration\Helper\ServerFlavourAssertions;

/**
 * Query safeguards on the real database: execution time hint on every report statement and an explicit failure
 * for an unavailable report connection.
 *
 * Inputs: one guest order created 2026-06-10 12:00:00 with three products (ar-sg-a, ar-sg-b, ar-sg-c).
 *
 * Hint check scope: the DB profiler is enabled on the report connection (`connection_name = default`), which is also
 * the connection of Magento itself. Only statements reading report tables are asserted (see REPORT_TABLES);
 * statements Magento or the module cannot or must not hint (EXCLUDED_STATEMENTS) are skipped. The reports are run
 * with the cache bypassed (no lock statements) and after a warm-up request, so detectors, stores and config are
 * already loaded when profiling starts.
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    DataFixture(Product::class, ['sku' => 'ar-sg-a', 'price' => 10, 'tax_class_id' => 0], 'productA'),
    DataFixture(Product::class, ['sku' => 'ar-sg-b', 'price' => 20, 'tax_class_id' => 0], 'productB'),
    DataFixture(Product::class, ['sku' => 'ar-sg-c', 'price' => 30, 'tax_class_id' => 0], 'productC'),
    DataFixture(GuestCart::class, as: 'cart'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$productA.id$', 'qty' => 1]),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$productB.id$', 'qty' => 1]),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$productC.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart.id$', 'email' => 'ar-sg@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart.id$'], 'order'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order.id$', 'created_at' => '2026-06-10 12:00:00']),
]
final class SafeguardsTest extends AbstractBackendController
{
    private const string DATA_URI = 'backend/advancedreport/report/data';

    /** Statements reading report tables (the lookbehind admits a table prefix such as `pre_`, which \b would not). */
    private const string REPORT_TABLES = '/(?<![a-z0-9])(?:sales_order\w*|inventory_\w+|cataloginventory_\w+|customer_entity)\b/i';

    /**
     * Never hinted on purpose: server version and timezone-support probes (excluded from the timeout by design) the
     * cache lock statements and SHOW TABLES existence checks (not SELECT statements).
     */
    private const array EXCLUDED_STATEMENTS = ['/^\s*SELECT\s+VERSION\(\)/i', '/CONVERT_TZ\(/i', '/\b(?:GET|RELEASE)_LOCK\(/i', '/^\s*SHOW\s/i'];

    private const string HINT = '/^\s*(?:SELECT\s+\/\*\+\s*MAX_EXECUTION_TIME\(\d+\)\s*\*\/|SET\s+STATEMENT\s+max_statement_time=\d+\s+FOR\s+)/i';

    private ReportTestRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new ReportTestRunner();
    }

    public function testItAddsTheExecutionTimeHintToEveryReportStatement(): void
    {
        $this->runner->run('sales_orders', ['orders'], [], [], '2026-06-01', '2026-06-30');
        $profiler = Bootstrap::getObjectManager()->get(ReportConnection::class)->get()->getProfiler();
        $profiler->setEnabled(true);

        $this->runner->runWith('sales_items', ['units_sold', 'gross_sales'], [['code' => 'sku', 'granularity' => null]], [], true, [
            'dateRange' => ['type' => 'custom', 'from' => '2026-06-01', 'to' => '2026-06-30'],
            'compareTo' => 'previous_period',
            'limit' => 1,
        ]);
        $this->runner->runWith('sales_orders', ['orders'], [['code' => 'status', 'granularity' => null]], [], true, [
            'dateRange' => ['type' => 'custom', 'from' => '2026-06-01', 'to' => '2026-06-30'],
        ]);
        $this->runner->runPreset('dead_stock', '2026-06-01', '2026-06-30');
        $this->runner->runWith('catalog_salable', ['salable_qty'], [['code' => 'sku', 'granularity' => null]]);

        $statements = array_map(
            static fn ($profile): string => (string) $profile->getQuery(),
            (array) $profiler->getQueryProfiles()
        );
        $profiler->setEnabled(false);
        $reportStatements = array_values(array_filter(
            $statements,
            fn (string $sql): bool => preg_match(self::REPORT_TABLES, $sql) === 1 && !$this->isExcluded($sql)
        ));

        $this->assertGreaterThanOrEqual(8, \count($reportStatements), implode("\n--\n", $statements));
        foreach ($reportStatements as $sql) {
            $this->assertMatchesRegularExpression(self::HINT, $sql, 'Missing timeout hint: ' . $sql);
        }
    }

    public function testItAssertsTheMysqlExecutionTimeGuardrailVariableOnMysqlServers(): void
    {
        ServerFlavourAssertions::skipUnless($this, ServerFlavour::Mysql);

        $this->assertNotNull($this->reportConnection()->fetchOne('SELECT @@SESSION.max_execution_time'));
        $this->assertMatchesRegularExpression(
            '/^\s*SELECT\s+\/\*\+\s*MAX_EXECUTION_TIME\(\d+\)\s*\*\/\s+/i',
            $this->guardedStatement()
        );
    }

    public function testItAssertsTheMariadbStatementTimeGuardrailVariableOnMariadbServers(): void
    {
        ServerFlavourAssertions::skipUnless($this, ServerFlavour::MariaDb);

        $this->assertNotNull($this->reportConnection()->fetchOne('SELECT @@SESSION.max_statement_time'));
        $this->assertMatchesRegularExpression('/^\s*SET\s+STATEMENT\s+max_statement_time=\d+\s+FOR\s+/i', $this->guardedStatement());
    }

    #[Config('mageos_advancedreport/performance/connection_name', 'missing')]
    public function testItFailsExplicitlyWhenTheReportConnectionIsMissing(): void
    {
        $this->expectException(ReportConnectionUnavailableException::class);

        $this->runner->run('sales_orders', ['orders'], [], [], '2026-06-01', '2026-06-30');
    }

    #[Config('mageos_advancedreport/performance/connection_name', 'missing')]
    public function testItReturns503WithAnExplicitMessageWhenTheReportConnectionIsMissing(): void
    {
        $this->post(self::DATA_URI, ['request' => (string) json_encode([
            'dataset' => 'sales_orders',
            'metrics' => ['orders'],
            'dimensions' => [],
            'filters' => [],
            'dateRange' => ['type' => 'custom', 'from' => '2026-06-01', 'to' => '2026-06-30'],
            'storeIds' => [],
            'bypassCache' => true,
        ])]);

        $response = $this->getResponse();
        $this->assertInstanceOf(HttpResponse::class, $response);
        $this->assertSame(503, $response->getHttpResponseCode());
        $payload = json_decode($response->getBody(), true);
        $this->assertTrue($payload['error']);
        $this->assertStringContainsString('"missing"', $payload['message']);
        $this->assertStringContainsString('not available', $payload['message']);
    }

    private function reportConnection(): \Magento\Framework\DB\Adapter\AdapterInterface
    {
        return Bootstrap::getObjectManager()->get(ReportConnection::class)->get();
    }

    private function guardedStatement(): string
    {
        return Bootstrap::getObjectManager()->get(Guardrails::class)->applyTimeout('SELECT 1');
    }

    private function isExcluded(string $sql): bool
    {
        foreach (self::EXCLUDED_STATEMENTS as $pattern) {
            if (preg_match($pattern, $sql) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function post(string $uri, array $params): void
    {
        $request = $this->getRequest();
        $this->assertInstanceOf(HttpRequest::class, $request);
        $formKey = Bootstrap::getObjectManager()->get(FormKey::class)->getFormKey();
        $request->setMethod('POST')->setPostValue($params + ['form_key' => $formKey]);
        $this->dispatch($uri);
    }
}
