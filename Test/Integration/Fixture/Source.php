<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Fixture;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Test\Fixture\Source as CoreSource;
use Magento\TestFramework\Fixture\RevertibleDataFixtureInterface;

/**
 * Core MSI source fixture with a revert that honours `db/table_prefix`: the core revert passes `inventory_source`
 * through the adapter getTableName(), which never adds the prefix, so it fails on prefixed installs and leaves the
 * fixture resolver dirty for every following test. Data: same as the core fixture.
 */
final readonly class Source implements RevertibleDataFixtureInterface
{
    public function __construct(
        private CoreSource $source,
        private ResourceConnection $resourceConnection,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public function apply(array $data = []): ?DataObject
    {
        return $this->source->apply($data);
    }

    public function revert(DataObject $data): void
    {
        $this->resourceConnection->getConnection()->delete(
            $this->resourceConnection->getTableName('inventory_source'),
            [SourceInterface::SOURCE_CODE . ' = ?' => $data->getData(SourceInterface::SOURCE_CODE)]
        );
    }
}
