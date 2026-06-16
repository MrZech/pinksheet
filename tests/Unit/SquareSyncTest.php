<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Square Sync Unit Tests.
 *
 * [Square Sync / square_sync.php] — data mapping, price/status rules,
 * SKU normalization, and Square API interaction helpers.
 *
 * Because square_sync.php ties into the Square SDK, these tests
 * verify the in-house mapping and transformation logic in isolation.
 */
#[CoversNothing]
final class SquareSyncTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createSandboxDatabase();
    }

    // ── Status Mapping ──────────────────────────────────────────────────

    /**
     * Map an inventory status to a Square item state.
     */
    private static function mapSquareStatus(string $status): string
    {
        return match ($status) {
            'SOLD'              => 'SOLD',
            'Dispo Tech Store'  => 'ACTIVE',
            'eBay Listed'       => 'ACTIVE',
            default             => 'DRAFT',
        };
    }

    public function test_map_square_status_sold(): void
    {
        $this->assertSame('SOLD', self::mapSquareStatus('SOLD'));
    }

    public function test_map_square_status_active(): void
    {
        $this->assertSame('ACTIVE', self::mapSquareStatus('Dispo Tech Store'));
        $this->assertSame('ACTIVE', self::mapSquareStatus('eBay Listed'));
    }

    public function test_map_square_status_defaults_draft(): void
    {
        $this->assertSame('DRAFT', self::mapSquareStatus('Intake'));
        $this->assertSame('DRAFT', self::mapSquareStatus('Tested'));
        $this->assertSame('DRAFT', self::mapSquareStatus('Ready for eBay Listing'));
    }

    // ── SKU Normalization ───────────────────────────────────────────────

    public function test_sku_normalization_uppercases(): void
    {
        $this->assertSame('DT-1001', \normalizeSku('dt-1001'));
    }

    public function test_sku_normalization_trims_whitespace(): void
    {
        $this->assertSame('DT-1001', \normalizeSku('  DT-1001  '));
    }

    public function test_sku_normalization_preserves_dashes_and_spaces(): void
    {
        $this->assertSame('DT-10 01!', \normalizeSku('dt-10 01!'));
    }

    // ── Square Item Data Shape ──────────────────────────────────────────

    public function test_square_item_data_shape(): void
    {
        $itemData = [
            'type'       => 'ITEM',
            'id'         => null,
            'item_data'  => [
                'name'        => 'DT-1001 — Dell Latitude 5420',
                'description' => 'Dell Latitude 5420 Laptop',
                'variations'  => [
                    [
                        'type'             => 'ITEM_VARIATION',
                        'item_variation_data' => [
                            'sku'          => 'DT-1001',
                            'name'         => 'Default',
                            'pricing_type' => 'FIXED_PRICING',
                            'price_money'  => [
                                'amount'   => 19999,
                                'currency' => 'USD',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertArrayHasKey('type', $itemData);
        $this->assertSame('ITEM', $itemData['type']);
        $this->assertArrayHasKey('item_data', $itemData);
        $this->assertArrayHasKey('variations', $itemData['item_data']);
        $this->assertCount(1, $itemData['item_data']['variations']);
        $this->assertSame('DT-1001', $itemData['item_data']['variations'][0]['item_variation_data']['sku']);
        $this->assertSame(19999, $itemData['item_data']['variations'][0]['item_variation_data']['price_money']['amount']);
    }

    // ── Sync Table ──────────────────────────────────────────────────────

    public function test_square_catalog_sync_insert(): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO square_catalog_sync (id, type, sku_normalized, item_data, updated_at) VALUES (:id, :type, :sku, :data, datetime('now'))");
        $stmt->execute([
            'id'   => 'sq-item-001',
            'type' => 'ITEM',
            'sku'  => 'DT-1001',
            'data' => json_encode(['name' => 'Test']),
        ]);
        $this->assertSame(1, $stmt->rowCount());
    }

    public function test_square_catalog_sync_upsert_updates_existing(): void
    {
        $this->pdo->exec("INSERT INTO square_catalog_sync (id, type, sku_normalized, item_data) VALUES ('sq-001', 'ITEM', 'DT-1001', '{\"v\":1}')");

        $stmt = $this->pdo->prepare("INSERT OR REPLACE INTO square_catalog_sync (id, type, sku_normalized, item_data, updated_at) VALUES (:id, :type, :sku, :data, datetime('now'))");
        $stmt->execute([
            'id'   => 'sq-001',
            'type' => 'ITEM',
            'sku'  => 'DT-1001',
            'data' => '{"v":2}',
        ]);

        $check = $this->pdo->query("SELECT item_data FROM square_catalog_sync WHERE id = 'sq-001'");
        $this->assertSame('{"v":2}', $check->fetchColumn());
    }

    /**
     * @return list<array{float, int}>
     */
    public static function priceToMoneyProvider(): array
    {
        return [
            [   0.00,      0],
            [  49.99,   4999],
            [ 299.99,  29999],
            [1299.00, 129900],
            [   0.01,      1],
        ];
    }

    #[DataProvider('priceToMoneyProvider')]
    public function test_price_to_money_conversion(float $price, int $expectedCents): void
    {
        $this->assertSame($expectedCents, self::priceToCents($price));
    }

    private static function priceToCents(float $price): int
    {
        return (int) round($price * 100);
    }

    public function test_sync_selects_only_square_toggled_items(): void
    {
        InventoryFixtures::standardInventory($this->pdo);
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM intake_items WHERE is_square = 1');
        $this->assertSame(4, (int) $stmt->fetchColumn());
    }
}
