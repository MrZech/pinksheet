<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/ebay_categories.php';
require_once __DIR__ . '/../../square_sync.php';

/**
 * eBay category catalog and integration tests.
 *
 * The intake form's combobox is fed by lib/ebay_categories.php (bundled
 * fallback + live snapshot), and the chosen category must flow through the
 * Square catalog description/payload hash and the inventory CSV export.
 */
#[CoversNothing]
final class EbayCategoriesTest extends TestCase
{
    public function test_bundled_list_is_non_empty_and_well_formed(): void
    {
        $list = ebayCategoryNormalizeList(ebayCategoryBundled());
        $this->assertNotEmpty($list);
        foreach ($list as $entry) {
            $this->assertArrayHasKey('id', $entry);
            $this->assertArrayHasKey('name', $entry);
            $this->assertArrayHasKey('path', $entry);
            $this->assertNotSame('', $entry['name']);
            $this->assertStringContainsString('>', $entry['path']);
        }
    }

    public function test_bundled_list_contains_common_electronics_leaves(): void
    {
        $names = array_column(ebayCategoryNormalizeList(ebayCategoryBundled()), 'name');
        $this->assertContains('PC Laptops & Netbooks', $names);
        $this->assertContains('Apple iPads', $names);
        $this->assertContains('TVs', $names);
        $this->assertContains('Cell Phones & Smartphones', $names);
        $this->assertContains('Video Game Consoles', $names);
    }

    public function test_bundled_list_uses_real_dispo_tech_category_ids(): void
    {
        $byName = [];
        foreach (ebayCategoryNormalizeList(ebayCategoryBundled()) as $entry) {
            $byName[$entry['name']] = $entry['id'];
        }
        $this->assertSame('177', $byName['PC Laptops & Netbooks']);
        $this->assertSame('179', $byName['PC Desktops & All-in-Ones']);
        $this->assertSame('164', $byName['CPUs/Processors']);
        $this->assertSame('170083', $byName['Memory (RAM)']);
        $this->assertSame('175669', $byName['Internal Solid State Drives (SSD)']);
        $this->assertSame('51268', $byName['Network Switches']);
    }

    public function test_bundled_list_covers_dispo_tech_pos_and_industrial_items(): void
    {
        $names = array_column(ebayCategoryNormalizeList(ebayCategoryBundled()), 'name');
        $this->assertContains('Cash Drawers', $names);
        $this->assertContains('Receipt Printers', $names);
        $this->assertContains('Label Printers', $names);
        $this->assertContains('Server Memory (RAM)', $names);
        $this->assertContains('RAID Controllers & Cards', $names);
        $this->assertContains('Patch Panels', $names);
        $this->assertContains('TV Wall & Ceiling Mounts', $names);
    }

    public function test_normalize_list_deduplicates_and_drops_invalid_entries(): void
    {
        $list = ebayCategoryNormalizeList([
            ['id' => '1', 'name' => 'Laptop', 'path' => 'A > B > Laptop'],
            ['id' => '', 'name' => 'Laptop', 'path' => 'A > B > Laptop'], // duplicate path
            ['id' => '2', 'name' => '', 'path' => 'A > B'],              // missing name
            ['id' => '3', 'name' => 'Tablet', 'path' => 'A > C > Tablet'],
        ]);
        $this->assertCount(2, $list);
        $this->assertSame('Tablet', $list[1]['name']);
    }

    public function test_category_list_returns_valid_shape(): void
    {
        $result = ebayCategoryList();
        $this->assertArrayHasKey('categories', $result);
        $this->assertArrayHasKey('generated_at', $result);
        $this->assertArrayHasKey('source', $result);
        $this->assertNotEmpty($result['categories']);
    }

    public function test_square_description_includes_ebay_category(): void
    {
        $item = [
            'sku' => 'DT-1001',
            'what_is_it' => 'Laptop',
            'ebay_category' => 'PC Laptops & Netbooks',
            'ebay_category_path' => 'Computers/Tablets & Networking > Laptops & Netbooks > PC Laptops & Netbooks',
            'ebay_category_id' => '175672',
        ];
        $desc = squareSyncDescription($item);
        $this->assertStringContainsString('eBay Category: PC Laptops & Netbooks', $desc);
        $this->assertStringContainsString('eBay Category ID: 175672', $desc);
    }

    public function test_square_description_omits_empty_category(): void
    {
        $item = ['sku' => 'DT-1001', 'what_is_it' => 'Laptop'];
        $desc = squareSyncDescription($item);
        $this->assertStringNotContainsString('eBay Category', $desc);
    }

    public function test_payload_hash_changes_with_category(): void
    {
        $item = ['sku' => 'DT-1001', 'what_is_it' => 'Laptop'];
        $withCategory = $item + ['ebay_category' => 'PC Laptops & Netbooks'];
        $this->assertNotSame(squareSyncPayloadHash($item, null), squareSyncPayloadHash($withCategory, null));
    }

    public function test_export_inventory_has_single_ebay_category_column(): void
    {
        $source = (string)file_get_contents(TESTING_ROOT . '/export_inventory.php');
        $this->assertStringContainsString('eBay Category', $source);
        $this->assertStringNotContainsString('eBay Category Path', $source);
        $this->assertStringNotContainsString('eBay Category ID', $source);
    }
}
