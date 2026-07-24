<?php
declare(strict_types=1);

/**
 * InventoryFixtures — Test data factory for Pinksheet CRUD tests.
 *
 * Provides static factory methods that return realistic inventory states.
 * Each method accepts an optional PDO to also seed a database.
 *
 * @coversNothing
 */
final class InventoryFixtures
{
    /**
     * Return an empty dataset — zero records in every table.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function emptyInventory(): array
    {
        return [
            'intake_items'  => [],
            'sku_photos'    => [],
            'intake_deleted' => [],
        ];
    }

    /**
     * Return a standard populated inventory with 12 varied items.
     * If a PDO is provided, the rows are inserted and the generated IDs
     * are reflected in the returned data.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function standardInventory(?PDO $pdo = null): array
    {
        $items = [
            [
                'sku'             => 'DT-1001',
                'sku_normalized'  => 'DT-1001',
                'status'          => 'intake',
                'what_is_it'      => 'Dell Latitude 5420 Laptop',
                'functional'      => 'Yes',
                'condition'       => 'Good',
                'is_square'       => 0,
                'diagnostics_test_ran' => 1,
                'reviewed'        => 0,
            ],
            [
                'sku'             => 'DT-1002',
                'sku_normalized'  => 'DT-1002',
                'status'          => 'ebay draft',
                'what_is_it'      => 'HP EliteBook 840 G8',
                'functional'      => 'Yes',
                'condition'       => 'Fair',
                'is_square'       => 1,
                'dispotech_price' => 299.99,
                'reviewed'        => 1,
            ],
            [
                'sku'             => 'DT-1003',
                'sku_normalized'  => 'DT-1003',
                'status'          => 'ebay review',
                'what_is_it'      => 'Lenovo ThinkPad X1 Carbon',
                'functional'      => 'Yes',
                'condition'       => 'Excellent',
                'is_square'       => 0,
                'ebay_price'      => 549.00,
                'reviewed'        => 1,
            ],
            [
                'sku'             => 'DT-1004',
                'sku_normalized'  => 'DT-1004',
                'status'          => 'dispo tech store',
                'what_is_it'      => 'MacBook Pro 14" M1 Pro',
                'functional'      => 'Yes',
                'condition'       => 'Excellent',
                'is_square'       => 1,
                'dispotech_price' => 1299.00,
                'reviewed'        => 0,
            ],
            [
                'sku'             => 'DT-1005',
                'sku_normalized'  => 'DT-1005',
                'status'          => 'ebay listed',
                'what_is_it'      => 'Dell UltraSharp U2719D Monitor',
                'functional'      => 'Yes',
                'condition'       => 'Good',
                'is_square'       => 0,
                'ebay_price'      => 189.99,
                'reviewed'        => 1,
            ],
            [
                'sku'             => 'DT-1006',
                'sku_normalized'  => 'DT-1006',
                'status'          => 'sold',
                'what_is_it'      => 'Logitech MX Master 3 Mouse',
                'functional'      => 'Yes',
                'condition'       => 'Good',
                'is_square'       => 0,
                'dispotech_price' => 49.99,
                'reviewed'        => 1,
            ],
            [
                'sku'             => 'DT-1007',
                'sku_normalized'  => 'DT-1007',
                'status'          => 'intake',
                'what_is_it'      => 'Dell PowerEdge R740 Server',
                'functional'      => 'Unknown',
                'condition'       => 'For Parts',
                'is_square'       => 0,
                'diagnostics_test_ran' => 0,
                'reviewed'        => 0,
            ],
            [
                'sku'             => 'DT-1008',
                'sku_normalized'  => 'DT-1008',
                'status'          => 'Tested',
                'what_is_it'      => 'Cisco Catalyst 2960 Switch',
                'functional'      => 'Yes',
                'condition'       => 'Good',
                'is_square'       => 1,
                'dispotech_price' => 85.00,
                'reviewed'        => 0,
            ],
            [
                'sku'             => 'DT-1009',
                'sku_normalized'  => 'DT-1009',
                'status'          => 'ebay review',
                'what_is_it'      => 'Apple Mac Mini M2',
                'functional'      => 'Yes',
                'condition'       => 'Excellent',
                'is_square'       => 0,
                'ebay_price'      => 459.00,
                'reviewed'        => 0,
            ],
            [
                'sku'             => 'DT-1010',
                'sku_normalized'  => 'DT-1010',
                'status'          => 'ebay listed',
                'what_is_it'      => 'Samsung 27" Curved Monitor',
                'functional'      => 'Yes',
                'condition'       => 'Good',
                'is_square'       => 0,
                'ebay_price'      => 159.99,
                'reviewed'        => 1,
            ],
            [
                'sku'             => 'DT-1011',
                'sku_normalized'  => 'DT-1011',
                'status'          => 'sold',
                'what_is_it'      => 'ThinkPad Docking Station',
                'functional'      => 'Yes',
                'condition'       => 'Fair',
                'is_square'       => 0,
                'dispotech_price' => 35.00,
                'reviewed'        => 1,
            ],
            [
                'sku'             => 'DT-1012',
                'sku_normalized'  => 'DT-1012',
                'status'          => 'dispo tech store',
                'what_is_it'      => 'HP ZBook Fury G9',
                'functional'      => 'Yes',
                'condition'       => 'Good',
                'is_square'       => 1,
                'dispotech_price' => 899.00,
                'reviewed'        => 0,
            ],
        ];

        if ($pdo !== null) {
            $stmt = $pdo->prepare('INSERT INTO intake_items (sku, sku_normalized, status, what_is_it, functional, condition, is_square, diagnostics_test_ran, reviewed, dispotech_price, ebay_price) VALUES (:sku, :sku_normalized, :status, :what_is_it, :functional, :condition, :is_square, :diagnostics_test_ran, :reviewed, :dispotech_price, :ebay_price)');
            foreach ($items as &$item) {
                $item['id'] = null;
                $stmt->execute([
                    ':sku'               => $item['sku'],
                    ':sku_normalized'    => $item['sku_normalized'],
                    ':status'            => $item['status'],
                    ':what_is_it'        => $item['what_is_it'],
                    ':functional'        => $item['functional'] ?? null,
                    ':condition'         => $item['condition'] ?? null,
                    ':is_square'         => $item['is_square'] ?? 0,
                    ':diagnostics_test_ran' => $item['diagnostics_test_ran'] ?? 0,
                    ':reviewed'          => $item['reviewed'] ?? 0,
                    ':dispotech_price'   => $item['dispotech_price'] ?? null,
                    ':ebay_price'        => $item['ebay_price'] ?? null,
                ]);
                $item['id'] = (int) $pdo->lastInsertId();
            }
            unset($item);
        }

        return $items;
    }

    /**
     * Return a fixture with two items sharing the same normalized SKU.
     * This simulates a duplicate-SKU condition for constraint testing.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function duplicateSkuInventory(?PDO $pdo = null): array
    {
        $items = [
            [
                'sku'            => 'DT-2001',
                'sku_normalized' => 'DT-2001',
                'status'         => 'intake',
                'what_is_it'     => 'Original item',
                'reviewed'       => 0,
            ],
            [
                'sku'            => 'dt-2001',
                'sku_normalized' => 'DT-2001',
                'status'         => 'intake',
                'what_is_it'     => 'Duplicate SKU entry',
                'reviewed'       => 0,
            ],
        ];

        if ($pdo !== null) {
            $stmt = $pdo->prepare('INSERT INTO intake_items (sku, sku_normalized, status, what_is_it, reviewed) VALUES (:sku, :sku_normalized, :status, :what_is_it, :reviewed)');
            foreach ($items as &$item) {
                $stmt->execute($item);
                $item['id'] = (int) $pdo->lastInsertId();
            }
            unset($item);
        }

        return $items;
    }

    /**
     * Return a fixture for concurrent-update testing: two updates to
     * the same item with different "updated_at" timestamps.
     *
     * @return array{update1: array, update2: array}
     */
    public static function concurrentUpdateScenario(): array
    {
        return [
            'update1' => [
                'status'          => 'ebay draft',
                'updated_at' => '2026-01-01 10:00:00',
            ],
            'update2' => [
                'status'  => 'SOLD',
                'updated_at' => '2026-01-01 10:00:01',
            ],
        ];
    }
}
