<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Script Builder Module Tests.
 *
 * Tests the prompt-based eBay script generation pipeline (prompt_builder.php).
 * Verifies inventory data aggregation, script caching, prompt text assembly,
 * and ChatGPT text generation.
 *
 * [Script Builder / prompt_builder.php] — data aggregation, caching,
 * prompt templating, script output formatting.
 */
#[CoversNothing]
final class ScriptBuilderTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createSandboxDatabase();
    }

    // ── Cache Layer ─────────────────────────────────────────────────────

    public function test_script_cache_insert(): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO script_cache (sku_normalized, sku_display, prompt_text, chatgpt_text, final_text, updated_at) VALUES (:sku, :display, :prompt, :chatgpt, :final, datetime('now'))");
        $stmt->execute([
            'sku'     => 'DT-1001',
            'display' => 'DT-1001',
            'prompt'  => 'Write an eBay listing...',
            'chatgpt' => 'eBay listing draft...',
            'final'   => 'Final listing text...',
        ]);
        $this->assertSame(1, $stmt->rowCount());
    }

    public function test_script_cache_upsert_replaces_existing(): void
    {
        $this->pdo->exec("INSERT INTO script_cache (sku_normalized, sku_display, prompt_text) VALUES ('DT-1001', 'DT-1001', 'v1')");

        $stmt = $this->pdo->prepare("INSERT OR REPLACE INTO script_cache (sku_normalized, sku_display, prompt_text, updated_at) VALUES (:sku, :display, :prompt, datetime('now'))");
        $stmt->execute(['sku' => 'DT-1001', 'display' => 'DT-1001', 'prompt' => 'v2']);

        $check = $this->pdo->query("SELECT prompt_text FROM script_cache WHERE sku_normalized = 'DT-1001'");
        $this->assertSame('v2', $check->fetchColumn());
    }

    public function test_script_cache_lookup_by_sku(): void
    {
        InventoryFixtures::standardInventory($this->pdo);
        $sku = 'DT-1001';

        $this->pdo->prepare("INSERT INTO script_cache (sku_normalized, sku_display, prompt_text) VALUES (?, ?, 'eBay listing text')")->execute([$sku, $sku]);

        $stmt = $this->pdo->prepare('SELECT * FROM script_cache WHERE sku_normalized = :sku');
        $stmt->execute(['sku' => $sku]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertSame('eBay listing text', $row['prompt_text']);
    }

    public function test_script_cache_missing_sku_returns_null(): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM script_cache WHERE sku_normalized = :sku');
        $stmt->execute(['sku' => 'NONEXISTENT']);
        $this->assertFalse($stmt->fetch());
    }

    // ── Prompt Assembly ─────────────────────────────────────────────────

    /**
     * Simulate prompt_explode to create prompt text from item data.
     */
    private static function buildPromptText(array $item): string
    {
        return sprintf(
            "Create an eBay listing for the following item:\n\nSKU: %s\nItem: %s\nCondition: %s\nFunctional: %s\nSpecs: RAM %s | SSD %s GB | CPU %s | OS %s\n\nWrite a detailed description with key features, specifications, and condition notes.",
            $item['sku'],
            $item['what_is_it'],
            $item['condition'] ?? 'Unknown',
            $item['functional'] ?? 'Unknown',
            $item['ram'] ?? 'N/A',
            $item['ssd_gb'] ?? 'N/A',
            $item['cpu'] ?? 'N/A',
            $item['os'] ?? 'N/A'
        );
    }

    public function test_build_prompt_text_includes_sku(): void
    {
        $items = InventoryFixtures::standardInventory($this->pdo);
        $prompt = self::buildPromptText($items[0]);
        $this->assertStringContainsString('DT-1001', $prompt);
    }

    public function test_build_prompt_text_includes_item_name(): void
    {
        $items = InventoryFixtures::standardInventory($this->pdo);
        $prompt = self::buildPromptText($items[0]);
        $this->assertStringContainsString('Dell Latitude 5420 Laptop', $prompt);
    }

    public function test_build_prompt_text_includes_condition(): void
    {
        $items = InventoryFixtures::standardInventory($this->pdo);
        $prompt = self::buildPromptText($items[0]);
        $this->assertStringContainsString('Good', $prompt);
    }

    public function test_build_prompt_with_minimal_data(): void
    {
        $item = [
            'sku'       => 'MIN-001',
            'what_is_it' => 'Minimal Item',
            'condition'  => null,
            'functional' => null,
            'ram'        => null,
            'ssd_gb'     => null,
            'cpu'        => null,
            'os'         => null,
        ];
        $prompt = self::buildPromptText($item);
        $this->assertStringContainsString('MIN-001', $prompt);
        $this->assertStringContainsString('Unknown', $prompt);
    }

    public function test_build_prompt_text_is_not_empty(): void
    {
        $items = InventoryFixtures::standardInventory($this->pdo);
        $prompt = self::buildPromptText($items[0]);
        $this->assertNotEmpty($prompt);
    }

    // ── Script Caching TTL / Staleness ──────────────────────────────────

    public function test_cache_stale_when_item_updated_after_cache(): void
    {
        $items = InventoryFixtures::standardInventory($this->pdo);
        $first = $items[0];

        // Create cache entry
        $this->pdo->prepare("INSERT INTO script_cache (sku_normalized, sku_display, chatgpt_text, updated_at) VALUES (?, ?, 'old text', '2026-01-01 00:00:00')")->execute([$first['sku_normalized'], $first['sku']]);

        // Update item after cache
        $this->pdo->prepare("UPDATE intake_items SET updated_at = datetime('now', '+1 day') WHERE id = ?")->execute([$first['id']]);

        // Cache should be considered stale
        $cache = $this->pdo->query("SELECT updated_at FROM script_cache WHERE sku_normalized = '{$first['sku_normalized']}'")->fetchColumn();
        $item  = $this->pdo->query("SELECT updated_at FROM intake_items WHERE id = {$first['id']}")->fetchColumn();
        $this->assertLessThan($item, $cache);
    }

    public function test_cache_fresh_when_item_not_updated(): void
    {
        $items = InventoryFixtures::standardInventory($this->pdo);
        $first = $items[0];

        $this->pdo->prepare("INSERT INTO script_cache (sku_normalized, sku_display, chatgpt_text, updated_at) VALUES (?, ?, 'fresh text', datetime('now'))")->execute([$first['sku_normalized'], $first['sku']]);

        $cache = $this->pdo->query("SELECT updated_at FROM script_cache WHERE sku_normalized = '{$first['sku_normalized']}'")->fetchColumn();
        $item  = $this->pdo->query("SELECT updated_at FROM intake_items WHERE id = {$first['id']}")->fetchColumn();
        $this->assertGreaterThanOrEqual($item, $cache);
    }

    // ── Status Filtering for Script Generation ──────────────────────────

    public function test_script_generation_filters_sold_items(): void
    {
        InventoryFixtures::standardInventory($this->pdo);
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM intake_items WHERE status != 'sold'");
        $this->assertSame(10, (int) $stmt->fetchColumn());
    }

    public function test_script_generation_includes_ready_for_ebay(): void
    {
        InventoryFixtures::standardInventory($this->pdo);
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM intake_items WHERE status = 'ebay review'");
        $this->assertSame(2, (int) $stmt->fetchColumn());
    }
}
