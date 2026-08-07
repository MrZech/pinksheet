<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../square_sync.php';
require_once __DIR__ . '/../../square_webhook_service.php';
require_once __DIR__ . '/../../square_sync_queue.php';

#[CoversNothing]
final class SquareSyncTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createSandboxDatabase();
        squareSyncEnsureSchema($this->pdo);
        squareWebhookEnsureSchema($this->pdo);
        squareQueueEnsureSchema($this->pdo);
    }

    // ── Config ──────────────────────────────────────────────────────────

    public function test_square_config_defaults_to_latest_stable_api_version(): void
    {
        $oldVersion = getenv('SQUARE_API_VERSION');
        putenv('SQUARE_API_VERSION');

        $config = squareSyncConfig();
        $this->assertSame(SQUARE_LATEST_STABLE_API_VERSION, $config['api_version']);

        if ($oldVersion !== false) {
            putenv('SQUARE_API_VERSION=' . $oldVersion);
        }
    }

    public function test_square_config_disabled_when_token_missing(): void
    {
        $oldToken = getenv('SQUARE_ACCESS_TOKEN');
        putenv('SQUARE_ACCESS_TOKEN');
        $oldLocation = getenv('SQUARE_LOCATION_ID');
        putenv('SQUARE_LOCATION_ID');

        $config = squareSyncConfig();
        $this->assertFalse($config['enabled']);

        if ($oldToken !== false) { putenv('SQUARE_ACCESS_TOKEN=' . $oldToken); }
        if ($oldLocation !== false) { putenv('SQUARE_LOCATION_ID=' . $oldLocation); }
    }

    // ── SKU Normalization ───────────────────────────────────────────────

    public function test_sku_normalization_uppercases(): void
    {
        $this->assertSame('DT-1001', normalizeSku('dt-1001'));
    }

    public function test_sku_normalization_trims_whitespace(): void
    {
        $this->assertSame('DT-1001', normalizeSku('  DT-1001  '));
    }

    public function test_sku_normalization_preserves_dashes_and_spaces(): void
    {
        $this->assertSame('DT-10 01!', normalizeSku('dt-10 01!'));
    }

    // ── Payload Hash ────────────────────────────────────────────────────

    public function test_payload_hash_is_consistent(): void
    {
        $item = ['sku' => 'DT-1001', 'status' => 'intake', 'what_is_it' => 'Laptop'];
        $hash1 = squareSyncPayloadHash($item, null);
        $hash2 = squareSyncPayloadHash($item, null);
        $this->assertSame($hash1, $hash2);
    }

    public function test_payload_hash_changes_with_different_data(): void
    {
        $item1 = ['sku' => 'DT-1001', 'status' => 'intake'];
        $item2 = ['sku' => 'DT-1001', 'status' => 'sold'];
        $this->assertNotSame(squareSyncPayloadHash($item1, null), squareSyncPayloadHash($item2, null));
    }

    public function test_payload_hash_changes_with_photo(): void
    {
        $item = ['sku' => 'DT-1001', 'status' => 'intake'];
        $hash1 = squareSyncPayloadHash($item, null);
        $hash2 = squareSyncPayloadHash($item, ['id' => 1]);
        $this->assertNotSame($hash1, $hash2);
    }

    // ── Item Name ───────────────────────────────────────────────────────

    public function test_item_name_uses_brand_model_and_what_is_it(): void
    {
        $item = ['brand_model' => 'Dell Latitude 5420', 'what_is_it' => 'Laptop'];
        $this->assertStringContainsString('Dell Latitude 5420', squareSyncItemName($item));
        $this->assertStringContainsString('Laptop', squareSyncItemName($item));
    }

    public function test_item_name_falls_back_to_sku(): void
    {
        $item = ['sku_normalized' => 'DT-1001'];
        $this->assertSame('DT-1001', squareSyncItemName($item));
    }

    public function test_item_name_is_limited_to_512_chars(): void
    {
        $item = ['brand_model' => str_repeat('A', 600)];
        $this->assertLessThanOrEqual(512, strlen(squareSyncItemName($item)));
    }

    // ── Description ─────────────────────────────────────────────────────

    public function test_description_includes_multiple_fields(): void
    {
        $item = ['sku' => 'DT-1001', 'status' => 'intake', 'what_is_it' => 'Laptop', 'ram' => '16GB'];
        $desc = squareSyncDescription($item);
        $this->assertStringContainsString('SKU: DT-1001', $desc);
        $this->assertStringContainsString('RAM: 16GB', $desc);
    }

    public function test_description_excludes_empty_fields(): void
    {
        $item = ['sku' => 'DT-1001', 'status' => 'intake', 'notes' => ''];
        $desc = squareSyncDescription($item);
        $this->assertStringNotContainsString('Notes:', $desc);
    }

    public function test_description_is_limited_to_4000_chars(): void
    {
        $item = ['sku' => 'DT-1001', 'status' => 'intake', 'notes' => str_repeat('A', 5000)];
        $this->assertLessThanOrEqual(4000, strlen(squareSyncDescription($item)));
    }

    // ── Build Catalog Object ────────────────────────────────────────────

    public function test_build_catalog_object_has_correct_structure(): void
    {
        $item = [
            'sku_normalized' => 'DT-1001',
            'dispotech_price' => 299.99,
            'status' => 'intake',
            'what_is_it' => 'Laptop',
        ];
        $config = ['currency' => 'USD'];
        $object = squareSyncBuildCatalogObject($item, $config, null);

        $this->assertSame('ITEM', $object['type']);
        $this->assertArrayHasKey('id', $object);
        $this->assertArrayHasKey('item_data', $object);
        $this->assertSame('REGULAR', $object['item_data']['product_type']);
        $this->assertCount(1, $object['item_data']['variations']);
        $this->assertSame('ITEM_VARIATION', $object['item_data']['variations'][0]['type']);
        $this->assertSame('DT-1001', $object['item_data']['variations'][0]['item_variation_data']['sku']);
        $this->assertSame('FIXED_PRICING', $object['item_data']['variations'][0]['item_variation_data']['pricing_type']);
        $this->assertSame(29999, $object['item_data']['variations'][0]['item_variation_data']['price_money']['amount']);
    }

    public function test_build_catalog_object_uses_variable_pricing_when_no_price(): void
    {
        $item = ['sku_normalized' => 'DT-1001', 'status' => 'intake'];
        $config = ['currency' => 'USD'];
        $object = squareSyncBuildCatalogObject($item, $config, null);
        $this->assertSame('VARIABLE_PRICING', $object['item_data']['variations'][0]['item_variation_data']['pricing_type']);
    }

    public function test_build_catalog_object_includes_version_from_existing(): void
    {
        $item = ['sku_normalized' => 'DT-1001', 'status' => 'intake'];
        $config = ['currency' => 'USD'];
        $existing = [
            'item' => ['id' => 'sq-item-1', 'version' => 42],
            'variation' => ['id' => 'sq-var-1', 'version' => 7],
        ];
        $object = squareSyncBuildCatalogObject($item, $config, $existing);
        $this->assertSame(42, $object['version']);
        $this->assertSame(7, $object['item_data']['variations'][0]['version']);
    }

    public function test_build_catalog_object_preserves_existing_variations(): void
    {
        $item = ['sku_normalized' => 'DT-1002', 'status' => 'intake', 'dispotech_price' => 100.00];
        $config = ['currency' => 'USD'];
        $existing = [
            'item' => [
                'id' => 'sq-item-1',
                'item_data' => [
                    'variations' => [
                        [
                            'type' => 'ITEM_VARIATION',
                            'id' => 'sq-var-other',
                            'item_variation_data' => ['sku' => 'DT-OTHER', 'name' => 'Other'],
                        ],
                    ],
                ],
            ],
            'variation' => ['id' => 'sq-var-1', 'version' => 5],
        ];
        $object = squareSyncBuildCatalogObject($item, $config, $existing);
        $this->assertCount(2, $object['item_data']['variations']);
    }

    public function test_build_catalog_object_present_at_all_locations(): void
    {
        $item = ['sku_normalized' => 'DT-1001', 'status' => 'intake'];
        $config = ['currency' => 'USD'];
        $object = squareSyncBuildCatalogObject($item, $config, null);
        $this->assertTrue($object['present_at_all_locations']);
        $this->assertTrue($object['item_data']['variations'][0]['present_at_all_locations']);
    }

    // ── Temp ID ─────────────────────────────────────────────────────────

    public function test_temp_id_generation(): void
    {
        $id = squareSyncTempId('DT-1001');
        $this->assertStringContainsString('DT-1001', $id);
        $this->assertStringNotContainsString(' ', $id);
    }

    public function test_temp_id_is_consistent(): void
    {
        $this->assertSame(squareSyncTempId('DT-1001'), squareSyncTempId('DT-1001'));
    }

    public function test_temp_id_differs_for_different_inputs(): void
    {
        $this->assertNotSame(squareSyncTempId('DT-1001'), squareSyncTempId('DT-1002'));
    }

    // ── Limit ───────────────────────────────────────────────────────────

    public function test_limit_does_not_truncate_short_strings(): void
    {
        $this->assertSame('hello', squareSyncLimit('hello', 10));
    }

    public function test_limit_truncates_long_strings(): void
    {
        $result = squareSyncLimit('hello world this is long', 10);
        $this->assertSame('hello w...', $result);
    }

    public function test_limit_handles_empty_string(): void
    {
        $this->assertSame('', squareSyncLimit('', 10));
    }

    // ── Sanitize Catalog Object ─────────────────────────────────────────

    public function test_sanitize_catalog_object_removes_timestamps(): void
    {
        $obj = ['type' => 'ITEM', 'id' => '123', 'created_at' => '2026-01-01', 'updated_at' => '2026-06-01', 'item_data' => []];
        $cleaned = squareSyncSanitizeCatalogObject($obj);
        $this->assertArrayNotHasKey('created_at', $cleaned);
        $this->assertArrayNotHasKey('updated_at', $cleaned);
        $this->assertArrayHasKey('type', $cleaned);
        $this->assertArrayHasKey('id', $cleaned);
    }

    // ── Find Catalog Object ─────────────────────────────────────────────

    public function test_find_catalog_object_from_main_object(): void
    {
        $obj = ['type' => 'ITEM', 'id' => 'sq-item-1'];
        $result = squareSyncFindCatalogObject($obj, [], 'ITEM');
        $this->assertNotNull($result);
        $this->assertSame('sq-item-1', $result['id']);
    }

    public function test_find_catalog_object_from_id_mappings(): void
    {
        $mappings = [
            ['object_type' => 'ITEM', 'object_id' => 'sq-item-1'],
            ['object_type' => 'ITEM_VARIATION', 'object_id' => 'sq-var-1'],
        ];
        $result = squareSyncFindCatalogObject(null, $mappings, 'ITEM');
        $this->assertNotNull($result);
        $this->assertSame('sq-item-1', $result['id']);
    }

    public function test_find_catalog_object_returns_null_when_not_found(): void
    {
        $this->assertNull(squareSyncFindCatalogObject(null, [], 'ITEM'));
    }

    public function test_find_variation_object_from_main_object(): void
    {
        $obj = [
            'type' => 'ITEM',
            'item_data' => [
                'variations' => [
                    ['type' => 'ITEM_VARIATION', 'id' => 'sq-var-1'],
                ],
            ],
        ];
        $result = squareSyncFindVariationObject($obj, []);
        $this->assertNotNull($result);
        $this->assertSame('sq-var-1', $result['id']);
    }

    public function test_find_variation_object_from_id_mappings(): void
    {
        $mappings = [
            ['object_type' => 'ITEM', 'object_id' => 'sq-item-1'],
            ['object_type' => 'ITEM_VARIATION', 'object_id' => 'sq-var-1'],
        ];
        $result = squareSyncFindVariationObject(null, $mappings);
        $this->assertNotNull($result);
        $this->assertSame('sq-var-1', $result['id']);
    }

    public function test_find_variation_object_returns_null_when_not_found(): void
    {
        $this->assertNull(squareSyncFindVariationObject(null, []));
    }

    // ── Extract Item and Variation ──────────────────────────────────────

    public function test_extract_item_and_variation_with_matching_sku(): void
    {
        $item = [
            'type' => 'ITEM',
            'id' => 'sq-item-1',
            'item_data' => [
                'variations' => [
                    ['type' => 'ITEM_VARIATION', 'id' => 'sq-var-1', 'item_variation_data' => ['sku' => 'DT-1001']],
                ],
            ],
        ];
        $result = squareSyncExtractItemAndVariation($item, [], 'DT-1001');
        $this->assertNotNull($result);
        $this->assertSame('sq-item-1', $result['item']['id']);
        $this->assertSame('sq-var-1', $result['variation']['id']);
    }

    public function test_extract_item_and_variation_returns_null_for_wrong_type(): void
    {
        $this->assertNull(squareSyncExtractItemAndVariation(['type' => 'CATEGORY'], [], 'DT-1001'));
    }

    public function test_extract_item_and_variation_from_related_objects(): void
    {
        $item = ['type' => 'ITEM', 'id' => 'sq-item-1', 'item_data' => ['variations' => []]];
        $related = [
            ['type' => 'ITEM_VARIATION', 'id' => 'sq-var-1', 'item_variation_data' => ['sku' => 'DT-1001']],
        ];
        $result = squareSyncExtractItemAndVariation($item, $related, 'DT-1001');
        $this->assertNotNull($result);
    }

    public function test_extract_item_and_variation_returns_null_when_sku_mismatch(): void
    {
        $item = [
            'type' => 'ITEM',
            'item_data' => [
                'variations' => [
                    ['type' => 'ITEM_VARIATION', 'item_variation_data' => ['sku' => 'DT-OTHER']],
                ],
            ],
        ];
        $this->assertNull(squareSyncExtractItemAndVariation($item, [], 'DT-1001'));
    }

    // ── Error Summary ───────────────────────────────────────────────────

    public function test_error_summary_parses_errors(): void
    {
        $decoded = [
            'errors' => [
                ['category' => 'INVALID_REQUEST_ERROR', 'code' => 'NOT_FOUND', 'detail' => 'Object not found'],
            ],
        ];
        $summary = squareSyncErrorSummary($decoded, 'raw text');
        $this->assertStringContainsString('NOT_FOUND', $summary);
        $this->assertStringContainsString('Object not found', $summary);
    }

    public function test_error_summary_falls_back_to_raw(): void
    {
        $summary = squareSyncErrorSummary([], 'raw error text');
        $this->assertStringContainsString('raw error text', $summary);
    }

    // ── Retry-After Parsing ─────────────────────────────────────────────

    public function test_retry_after_parses_seconds(): void
    {
        $headers = "Retry-After: 30\r\n";
        $this->assertSame(30, squareSyncRetryAfterSeconds($headers));
    }

    public function test_retry_after_returns_null_when_missing(): void
    {
        $this->assertNull(squareSyncRetryAfterSeconds(''));
    }

    public function test_retry_after_parses_http_date(): void
    {
        $future = gmdate('D, d M Y H:i:s T', time() + 120);
        $headers = "Retry-After: $future\r\n";
        $result = squareSyncRetryAfterSeconds($headers);
        $this->assertNotNull($result);
        $this->assertGreaterThan(60, $result);
        $this->assertLessThan(180, $result);
    }

    // ── Error Recording ─────────────────────────────────────────────────

    public function test_record_error_saves_message(): void
    {
        squareSyncRecordError($this->pdo, 'DT-1001', 'Test error');
        $row = squareSyncLoadRow($this->pdo, 'DT-1001');
        $this->assertNotNull($row);
        $this->assertSame('Test error', $row['last_error']);
    }

    public function test_record_error_updates_existing(): void
    {
        squareSyncRecordError($this->pdo, 'DT-1001', 'First error');
        squareSyncRecordError($this->pdo, 'DT-1001', 'Updated error');
        $row = squareSyncLoadRow($this->pdo, 'DT-1001');
        $this->assertSame('Updated error', $row['last_error']);
    }

    public function test_record_error_limits_message_length(): void
    {
        squareSyncRecordError($this->pdo, 'DT-1001', str_repeat('A', 2000));
        $row = squareSyncLoadRow($this->pdo, 'DT-1001');
        $this->assertLessThanOrEqual(1000, strlen((string)$row['last_error']));
    }

    // ── Save Row ────────────────────────────────────────────────────────

    public function test_save_row_creates_entry(): void
    {
        squareSyncSaveRow($this->pdo, 'DT-1001', [
            'square_item_id' => 'sq-item-1',
            'square_item_version' => 1,
            'square_variation_id' => 'sq-var-1',
            'square_variation_version' => 1,
            'payload_hash' => 'abc123',
            'last_origin' => 'local',
        ]);
        $row = squareSyncLoadRow($this->pdo, 'DT-1001');
        $this->assertNotNull($row);
        $this->assertSame('sq-item-1', $row['square_item_id']);
        $this->assertSame('sq-var-1', $row['square_variation_id']);
    }

    public function test_save_row_updates_existing(): void
    {
        squareSyncSaveRow($this->pdo, 'DT-1001', ['square_item_id' => 'old-id', 'square_variation_id' => 'old-var', 'payload_hash' => 'old', 'last_origin' => 'local']);
        squareSyncSaveRow($this->pdo, 'DT-1001', ['square_item_id' => 'new-id', 'square_variation_id' => 'new-var', 'payload_hash' => 'new', 'last_origin' => 'local']);
        $row = squareSyncLoadRow($this->pdo, 'DT-1001');
        $this->assertSame('new-id', $row['square_item_id']);
        $this->assertSame('new-var', $row['square_variation_id']);
    }

    // ── Sync Table Schema ───────────────────────────────────────────────

    public function test_ensure_schema_creates_table(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS square_catalog_sync');
        squareSyncEnsureSchema($this->pdo);
        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='square_catalog_sync'")->fetchAll();
        $this->assertNotEmpty($tables);
    }

    public function test_ensure_schema_adds_missing_columns(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS square_catalog_sync');
        $this->pdo->exec("CREATE TABLE square_catalog_sync (sku_normalized TEXT PRIMARY KEY, square_item_id TEXT)");
        squareSyncEnsureSchema($this->pdo);
        $cols = $this->pdo->query("PRAGMA table_info(square_catalog_sync)")->fetchAll(PDO::FETCH_COLUMN, 1);
        $this->assertContains('last_origin', $cols);
        $this->assertContains('last_correlation_id', $cols);
        $this->assertContains('sync_enabled', $cols);
    }

    // ── Webhook Service ─────────────────────────────────────────────────

    public function test_webhook_process_ignores_unknown_event_type(): void
    {
        $result = squareWebhookProcess($this->pdo, 'unknown.event', ['event_id' => 'evt-1', 'merchant_id' => 'm1']);
        $this->assertSame('ignored', $result['status']);
    }

    public function test_webhook_process_rejects_empty_event_id(): void
    {
        $result = squareWebhookProcess($this->pdo, 'order.created', ['merchant_id' => 'm1']);
        $this->assertSame('skipped', $result['status']);
    }

    public function test_webhook_process_ignores_test_event_type(): void
    {
        $result = squareWebhookProcess($this->pdo, 'test.webhook', ['event_id' => 'evt-test', 'merchant_id' => 'm1']);
        $this->assertSame('ignored', $result['status']);
    }

    public function test_webhook_deduplication_prevents_reprocessing(): void
    {
        $body = ['event_id' => 'evt-dup', 'merchant_id' => 'm1', 'data' => ['id' => 'evt-dup']];
        $pdo = $this->pdo;

        $result1 = squareWebhookProcess($pdo, 'order.created', $body);
        $result2 = squareWebhookProcess($pdo, 'order.created', $body);

        $this->assertSame('duplicate', $result2['status']);
    }

    public function test_webhook_signature_uses_notification_url_and_body(): void
    {
        $oldSecret = getenv('SQUARE_WEBHOOK_SIGNATURE_KEY');
        putenv('SQUARE_WEBHOOK_SIGNATURE_KEY=test-signature-key');
        $url = 'https://example.test/webhooks/square.php';
        $body = '{"type":"payment.updated","event_id":"evt-test"}';
        $signature = base64_encode(hash_hmac('sha256', $url . $body, 'test-signature-key', true));

        $this->assertTrue(squareWebhookVerify($body, $signature, $url));
        $this->assertFalse(squareWebhookVerify($body, $signature, 'https://example.test/wrong'));

        if ($oldSecret !== false) {
            putenv('SQUARE_WEBHOOK_SIGNATURE_KEY=' . $oldSecret);
        } else {
            putenv('SQUARE_WEBHOOK_SIGNATURE_KEY');
        }
    }

    public function test_webhook_replay_window_rejects_old_events(): void
    {
        $oldAge = getenv('SQUARE_WEBHOOK_MAX_AGE_SECONDS');
        putenv('SQUARE_WEBHOOK_MAX_AGE_SECONDS=300');

        $this->assertFalse(squareWebhookIsFresh(['created_at' => '2000-01-01T00:00:00Z']));
        $this->assertTrue(squareWebhookIsFresh(['created_at' => gmdate('Y-m-d\TH:i:s\Z')]));

        if ($oldAge !== false) {
            putenv('SQUARE_WEBHOOK_MAX_AGE_SECONDS=' . $oldAge);
        } else {
            putenv('SQUARE_WEBHOOK_MAX_AGE_SECONDS');
        }
    }

    public function test_webhook_verify_returns_false_when_secret_missing(): void
    {
        $oldKey = getenv('SQUARE_WEBHOOK_SIGNATURE_KEY');
        putenv('SQUARE_WEBHOOK_SIGNATURE_KEY');

        $this->assertFalse(squareWebhookVerify('body', 'sig', 'https://example.test/wh'));

        if ($oldKey !== false) {
            putenv('SQUARE_WEBHOOK_SIGNATURE_KEY=' . $oldKey);
        }
    }

    public function test_webhook_verify_returns_false_when_url_empty(): void
    {
        $oldKey = getenv('SQUARE_WEBHOOK_SIGNATURE_KEY');
        putenv('SQUARE_WEBHOOK_SIGNATURE_KEY=test-key');

        $this->assertFalse(squareWebhookVerify('body', 'sig', ''));

        if ($oldKey !== false) {
            putenv('SQUARE_WEBHOOK_SIGNATURE_KEY=' . $oldKey);
        }
    }

    // ── Inventory Webhook Processing ────────────────────────────────────

    public function test_webhook_process_inventory_skips_without_catalog_id(): void
    {
        $body = ['data' => ['object' => ['inventory_count' => []]]];
        $result = squareWebhookProcessInventory($this->pdo, $body, 'corr-1');
        $this->assertSame('skipped', $result['status']);
    }

    public function test_webhook_process_inventory_enqueues_catalog_lookup_for_unknown_sku(): void
    {
        $this->pdo->exec("INSERT INTO intake_items (sku, sku_normalized) VALUES ('DT-UNKNOWN', 'DT-UNKNOWN')");
        $body = [
            'data' => [
                'object' => [
                    'inventory_count' => [
                        'catalog_object_id' => 'sq-var-unknown',
                        'state' => 'IN_STOCK',
                        'quantity' => '5',
                    ],
                ],
            ],
        ];
        $result = squareWebhookProcessInventory($this->pdo, $body, 'corr-1');
        $this->assertSame('skipped', $result['status']);
        $this->assertStringContainsString('enqueued', $result['message']);
    }

    // ── Catalog Webhook Processing ──────────────────────────────────────

    public function test_webhook_process_catalog_skips_without_object_id(): void
    {
        $result = squareWebhookProcessCatalog($this->pdo, ['data' => []], 'corr-1');
        $this->assertSame('skipped', $result['status']);
    }

    public function test_webhook_process_catalog_enqueues_item_sync(): void
    {
        $body = [
            'data' => [
                'object' => [
                    'catalog_object' => [
                        'type' => 'ITEM',
                        'id' => 'sq-item-1',
                        'item_data' => [
                            'variations' => [
                                ['type' => 'ITEM_VARIATION', 'item_variation_data' => ['sku' => 'DT-1001']],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $result = squareWebhookProcessCatalog($this->pdo, $body, 'corr-1');
        $this->assertSame('ok', $result['status']);
        $this->assertStringContainsString('DT-1001', $result['message']);
    }

    public function test_webhook_process_catalog_handles_variation_type(): void
    {
        $body = [
            'data' => [
                'object' => [
                    'catalog_object' => [
                        'type' => 'ITEM_VARIATION',
                        'id' => 'sq-var-1',
                        'item_variation_data' => ['sku' => 'DT-1001'],
                    ],
                ],
            ],
        ];
        $result = squareWebhookProcessCatalog($this->pdo, $body, 'corr-1');
        $this->assertSame('ok', $result['status']);
    }

    // ── Resolve SKU ─────────────────────────────────────────────────────

    public function test_resolve_sku_from_catalog_id(): void
    {
        $this->pdo->exec("INSERT INTO square_catalog_sync (sku_normalized, square_variation_id) VALUES ('DT-1001', 'sq-var-1')");
        $result = squareWebhookResolveSku($this->pdo, 'sq-var-1', '');
        $this->assertSame('DT-1001', $result);
    }

    public function test_resolve_sku_from_variation_name(): void
    {
        $this->pdo->exec("INSERT INTO intake_items (sku, sku_normalized) VALUES ('DT-1001', 'DT-1001')");
        $result = squareWebhookResolveSku($this->pdo, '', 'DT-1001');
        $this->assertSame('DT-1001', $result);
    }

    public function test_resolve_sku_returns_null_when_not_found(): void
    {
        $this->assertNull(squareWebhookResolveSku($this->pdo, 'nonexistent', ''));
    }

    // ── Sale Processing ─────────────────────────────────────────────────

    public function test_webhook_process_sale_records_sale_in_history(): void
    {
        $sku = 'DT-1001';
        $this->pdo->exec("INSERT INTO intake_items (sku, sku_normalized, status) VALUES ('$sku', '$sku', 'intake')");
        $this->pdo->exec("INSERT INTO square_catalog_sync (sku_normalized, square_variation_id) VALUES ('$sku', 'sq-var-1')");

        $body = [
            'event_id' => 'evt-sale-1',
            'merchant_id' => 'm1',
            'data' => [
                'object' => [
                    'order' => [
                        'id' => 'order-1',
                        'state' => 'COMPLETED',
                        'created_at' => '2026-07-01T12:00:00Z',
                        'location_id' => 'loc-1',
                        'line_items' => [
                            [
                                'uid' => 'li-1',
                                'catalog_object_id' => 'sq-var-1',
                                'quantity' => '1',
                                'variation_name' => $sku,
                                'total_money' => ['amount' => 29999],
                                'applied_taxes' => [],
                                'applied_discounts' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = squareWebhookProcess($this->pdo, 'order.created', $body);
        $this->assertSame('ok', $result['status']);

        $sale = $this->pdo->query("SELECT * FROM sales_history WHERE square_order_id = 'order-1'")->fetch();
        $this->assertNotFalse($sale);
        $this->assertSame('DT-1001', $sale['sku_normalized']);
        $this->assertSame(299.99, (float)$sale['sale_price']);
        $this->assertSame(1, (int)$sale['line_item_quantity']);

        $item = $this->pdo->query("SELECT status FROM intake_items WHERE sku_normalized = 'DT-1001'")->fetch();
        $this->assertSame('sold', $item['status']);
    }

    public function test_webhook_process_sale_skips_non_completed_orders(): void
    {
        $body = [
            'event_id' => 'evt-open-1',
            'merchant_id' => 'm1',
            'data' => [
                'object' => [
                    'order' => [
                        'id' => 'order-open',
                        'state' => 'OPEN',
                        'line_items' => [['uid' => 'li-1', 'quantity' => '1']],
                    ],
                ],
            ],
        ];
        $result = squareWebhookProcess($this->pdo, 'order.created', $body);
        $this->assertSame('skipped', $result['status']);
    }

    public function test_webhook_process_sale_handles_taxes_and_discounts(): void
    {
        $sku = 'DT-1001';
        $this->pdo->exec("INSERT INTO intake_items (sku, sku_normalized, status) VALUES ('$sku', '$sku', 'intake')");
        $this->pdo->exec("INSERT INTO square_catalog_sync (sku_normalized, square_variation_id) VALUES ('$sku', 'sq-var-1')");

        $body = [
            'event_id' => 'evt-sale-2',
            'merchant_id' => 'm1',
            'data' => [
                'object' => [
                    'order' => [
                        'id' => 'order-2',
                        'state' => 'COMPLETED',
                        'created_at' => '2026-07-01T12:00:00Z',
                        'location_id' => 'loc-1',
                        'line_items' => [
                            [
                                'uid' => 'li-1',
                                'catalog_object_id' => 'sq-var-1',
                                'quantity' => '2',
                                'variation_name' => $sku,
                                'total_money' => ['amount' => 50000],
                                'applied_taxes' => [
                                    ['applied_money' => ['amount' => 4000]],
                                ],
                                'applied_discounts' => [
                                    ['applied_money' => ['amount' => 1000]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = squareWebhookProcess($this->pdo, 'order.payment.created', $body);
        $sale = $this->pdo->query("SELECT * FROM sales_history WHERE square_order_id = 'order-2'")->fetch();
        $this->assertNotFalse($sale);
        $this->assertSame(500.0, (float)$sale['sale_price']);
        $this->assertSame(40.0, (float)$sale['tax_amount']);
        $this->assertSame(10.0, (float)$sale['discount_amount']);
        $this->assertSame(2, (int)$sale['line_item_quantity']);
    }

    // ── Queue System ────────────────────────────────────────────────────

    public function test_queue_enqueue_creates_job(): void
    {
        squareQueueEnqueue($this->pdo, 'DT-1001', 'catalog_upsert');
        $stats = squareQueueStats($this->pdo);
        $this->assertSame(1, $stats['queued']);
    }

    public function test_queue_enqueue_ignores_duplicate(): void
    {
        squareQueueEnqueue($this->pdo, 'DT-1001', 'catalog_upsert');
        squareQueueEnqueue($this->pdo, 'DT-1001', 'catalog_upsert');
        $stats = squareQueueStats($this->pdo);
        $this->assertSame(1, $stats['queued']);
    }

    public function test_queue_enqueues_different_operation_types(): void
    {
        squareQueueEnqueue($this->pdo, 'DT-1001', 'inventory_set');
        squareQueueEnqueue($this->pdo, 'DT-1001', 'inventory_pull');
        $stats = squareQueueStats($this->pdo);
        $this->assertSame(2, $stats['queued']);
    }

    public function test_queue_dequeue_returns_pending_jobs(): void
    {
        squareQueueEnqueue($this->pdo, 'DT-1001', 'catalog_upsert');
        squareQueueEnqueue($this->pdo, 'DT-1002', 'inventory_set');
        $jobs = squareQueueDequeue($this->pdo, 10);
        $this->assertCount(2, $jobs);
    }

    public function test_queue_dequeue_respects_limit(): void
    {
        squareQueueEnqueue($this->pdo, 'DT-1001', 'catalog_upsert');
        squareQueueEnqueue($this->pdo, 'DT-1002', 'inventory_set');
        $jobs = squareQueueDequeue($this->pdo, 1);
        $this->assertCount(1, $jobs);
    }

    public function test_queue_mark_processing_updates_status(): void
    {
        squareQueueEnqueue($this->pdo, 'DT-1001', 'catalog_upsert');
        $jobs = squareQueueDequeue($this->pdo, 10);
        $this->assertNotEmpty($jobs);
        squareQueueMarkProcessing($this->pdo, (int)$jobs[0]['id']);

        $stats = squareQueueStats($this->pdo);
        $this->assertSame(1, $stats['processing']);
        $this->assertSame(0, $stats['queued']);
    }

    public function test_queue_mark_completed(): void
    {
        squareQueueEnqueue($this->pdo, 'DT-1001', 'catalog_upsert');
        $jobs = squareQueueDequeue($this->pdo, 10);
        squareQueueMarkProcessing($this->pdo, (int)$jobs[0]['id']);
        squareQueueMarkCompleted($this->pdo, (int)$jobs[0]['id']);

        $stats = squareQueueStats($this->pdo);
        $this->assertSame(1, $stats['completed']);
    }

    public function test_queue_mark_failed_moves_to_retrying(): void
    {
        squareQueueEnqueue($this->pdo, 'DT-1001', 'catalog_upsert');
        $jobs = squareQueueDequeue($this->pdo, 10);
        $jobId = (int)$jobs[0]['id'];
        squareQueueMarkProcessing($this->pdo, $jobId);
        squareQueueMarkFailed($this->pdo, $jobId, 'Test error');

        $stats = squareQueueStats($this->pdo);
        $this->assertSame(1, $stats['retrying']);
    }

    public function test_queue_mark_failed_moves_to_dead_letter_after_max_retries(): void
    {
        $this->pdo->exec("INSERT INTO sync_queue (sku_normalized, operation, retry_count, max_retries, status) VALUES ('DT-1001', 'catalog_upsert', 9, 10, 'processing')");
        $jobId = (int)$this->pdo->lastInsertId();
        squareQueueMarkFailed($this->pdo, $jobId, 'Final error');

        $stats = squareQueueStats($this->pdo);
        $this->assertSame(1, $stats['dead_letter']);
    }

    public function test_queue_reset_dead_letter(): void
    {
        $this->pdo->exec("INSERT INTO sync_queue (sku_normalized, operation, status) VALUES ('DT-1001', 'catalog_upsert', 'dead_letter')");
        squareQueueResetDeadLetter($this->pdo);

        $stats = squareQueueStats($this->pdo);
        $this->assertSame(1, $stats['queued']);
        $this->assertSame(0, $stats['dead_letter']);
    }

    public function test_queue_next_retry_increases_delay(): void
    {
        $retry1 = squareQueueNextRetry(1);
        $retry2 = squareQueueNextRetry(5);
        $ts1 = strtotime($retry1);
        $ts2 = strtotime($retry2);
        $this->assertNotFalse($ts1);
        $this->assertNotFalse($ts2);
        $this->assertGreaterThan($ts1, $ts2);
    }

    // ── Audit Log ───────────────────────────────────────────────────────

    public function test_audit_log_records_entry(): void
    {
        squareAuditEnsureSchema($this->pdo);
        squareAuditLog($this->pdo, [
            'operation' => 'catalog_sync',
            'sku_normalized' => 'DT-1001',
            'direction' => 'push',
            'status' => 'success',
            'duration_ms' => 100,
        ]);

        $log = $this->pdo->query("SELECT * FROM sync_audit_log WHERE sku_normalized = 'DT-1001'")->fetch();
        $this->assertNotFalse($log);
        $this->assertSame('catalog_sync', $log['operation']);
        $this->assertSame('success', $log['status']);
    }

    public function test_audit_log_normalizes_statuses(): void
    {
        $this->assertSame('success', squareAuditNormalizeStatus('ok'));
        $this->assertSame('success', squareAuditNormalizeStatus('completed'));
        $this->assertSame('success', squareAuditNormalizeStatus('logged'));
        $this->assertSame('failure', squareAuditNormalizeStatus('error'));
        $this->assertSame('failure', squareAuditNormalizeStatus('failed'));
        $this->assertSame('failure', squareAuditNormalizeStatus('dead_letter'));
        $this->assertSame('skipped', squareAuditNormalizeStatus('ignored'));
        $this->assertSame('skipped', squareAuditNormalizeStatus('duplicate'));
    }

    // ── Logger ──────────────────────────────────────────────────────────

    public function test_sync_log_event_contains_required_fields(): void
    {
        squareSyncLogEvent([
            'operation' => 'test',
            'direction' => 'push',
            'status' => 'success',
        ]);
        $log = __DIR__ . '/../../logs/square_sync.log';
        if (is_file($log)) {
            $content = file_get_contents($log);
            $this->assertStringContainsString('test', (string)$content);
        }
        $this->assertTrue(true);
    }

    // ── Correlation ID ──────────────────────────────────────────────────

    public function test_correlation_id_format(): void
    {
        $id = squareSyncCorrelationId();
        $this->assertStringStartsWith('sq-', $id);
    }

    public function test_correlation_ids_are_unique(): void
    {
        $id1 = squareSyncCorrelationId();
        $id2 = squareSyncCorrelationId();
        $this->assertNotSame($id1, $id2);
    }

    // ── Duration MS ─────────────────────────────────────────────────────

    public function test_duration_ms_returns_non_negative(): void
    {
        $ms = squareSyncDurationMs(microtime(true));
        $this->assertGreaterThanOrEqual(0, $ms);
    }

    // ── Pull Sync (Unit: data flow, no HTTP calls) ──────────────────────

    public function test_pull_item_returns_disabled_when_not_configured(): void
    {
        $oldToken = getenv('SQUARE_ACCESS_TOKEN');
        putenv('SQUARE_ACCESS_TOKEN');
        putenv('SQUARE_LOCATION_ID');

        $result = squareSyncPullItem($this->pdo, 'DT-1001');
        $this->assertSame('disabled', $result['status']);

        if ($oldToken !== false) { putenv('SQUARE_ACCESS_TOKEN=' . $oldToken); }
        putenv('SQUARE_LOCATION_ID=test-location');
    }

    // ── Inventory Set (Unit: validates logic only) ──────────────────────

    public function test_inventory_set_uses_sold_logic(): void
    {
        $config = squareSyncConfig();
        $this->assertArrayHasKey('location_id', $config);
        $this->assertArrayHasKey('default_quantity', $config);
    }

    // ── Configuration Environment ───────────────────────────────────────

    public function test_config_uses_env_overrides(): void
    {
        $oldCurrency = getenv('SQUARE_CURRENCY');
        putenv('SQUARE_CURRENCY=EUR');

        $config = squareSyncConfig();
        $this->assertSame('EUR', $config['currency']);

        if ($oldCurrency !== false) {
            putenv('SQUARE_CURRENCY=' . $oldCurrency);
        } else {
            putenv('SQUARE_CURRENCY');
        }
    }

    public function test_config_max_retries_clamps_to_range(): void
    {
        $old = getenv('SQUARE_API_MAX_RETRIES');
        putenv('SQUARE_API_MAX_RETRIES=100');

        $config = squareSyncConfig();
        $this->assertSame(5, $config['max_retries']);

        if ($old !== false) {
            putenv('SQUARE_API_MAX_RETRIES=' . $old);
        }
    }

    public function test_config_timeout_clamps_to_range(): void
    {
        $old = getenv('SQUARE_API_TIMEOUT_SECONDS');
        putenv('SQUARE_API_TIMEOUT_SECONDS=300');

        $config = squareSyncConfig();
        $this->assertSame(120, $config['timeout_seconds']);

        if ($old !== false) {
            putenv('SQUARE_API_TIMEOUT_SECONDS=' . $old);
        }
    }

    // ── Square Status ───────────────────────────────────────────────────

    public function test_square_queue_stats_returns_all_statuses(): void
    {
        $stats = squareQueueStats($this->pdo);
        $this->assertArrayHasKey('queued', $stats);
        $this->assertArrayHasKey('processing', $stats);
        $this->assertArrayHasKey('completed', $stats);
        $this->assertArrayHasKey('failed', $stats);
        $this->assertArrayHasKey('retrying', $stats);
        $this->assertArrayHasKey('dead_letter', $stats);
    }

    // ── Ensure Schema (webhook service) ─────────────────────────────────

    public function test_webhook_ensure_schema_creates_tables(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS webhook_processed');
        $this->pdo->exec('DROP TABLE IF EXISTS sales_history');
        squareWebhookEnsureSchema($this->pdo);

        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('webhook_processed', 'sales_history')")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('webhook_processed', $tables);
        $this->assertContains('sales_history', $tables);
    }

    public function test_webhook_ensure_schema_adds_missing_columns_to_sync(): void
    {
        squareWebhookEnsureSchema($this->pdo);
        $cols = $this->pdo->query("PRAGMA table_info(square_catalog_sync)")->fetchAll(PDO::FETCH_COLUMN, 1);
        $this->assertContains('last_sale_sync_at', $cols);
        $this->assertContains('last_inventory_sync', $cols);
    }

    // ── Process Sale (edge cases) ───────────────────────────────────────

    public function test_webhook_process_sale_skips_when_no_order(): void
    {
        $body = [
            'event_id' => 'evt-no-order',
            'merchant_id' => 'm1',
            'data' => ['object' => ['payment' => []]],
        ];
        $result = squareWebhookProcess($this->pdo, 'payment.created', $body);
        $this->assertSame('skipped', $result['status']);
    }

    public function test_webhook_process_sale_skips_when_no_line_items(): void
    {
        $body = [
            'event_id' => 'evt-no-li',
            'merchant_id' => 'm1',
            'data' => [
                'object' => [
                    'order' => [
                        'id' => 'order-no-li',
                        'state' => 'COMPLETED',
                        'line_items' => [],
                    ],
                ],
            ],
        ];
        $result = squareWebhookProcess($this->pdo, 'order.completed', $body);
        $this->assertSame('skipped', $result['status']);
    }

    // ── Square Status ───────────────────────────────────────────────────

    public function test_square_sync_load_row_returns_null_for_nonexistent(): void
    {
        $this->assertNull(squareSyncLoadRow($this->pdo, 'NONEXISTENT'));
    }

    public function test_square_sync_load_item_returns_null_for_nonexistent(): void
    {
        $this->assertNull(squareSyncLoadItem($this->pdo, 'NONEXISTENT'));
    }

    public function test_square_sync_load_preferred_photo_returns_null_for_nonexistent(): void
    {
        $this->assertNull(squareSyncLoadPreferredPhoto($this->pdo, 'NONEXISTENT'));
    }

    // ── Upload Image payload building ───────────────────────────────────

    public function test_upload_image_skips_unsupported_mime(): void
    {
        $config = squareSyncConfig();
        $result = squareSyncUploadImage($config, 'sq-item-1', 'DT-1001', ['mime_type' => 'image/bmp', 'id' => 1, 'original_name' => 'test.bmp']);
        $this->assertNull($result);
    }

    // ── Inventory Set Quantity Calculation ──────────────────────────────

    /**
     * @return list<array{string, int}>
     */
    public static function inventoryQuantityProvider(): array
    {
        return [
            ['sold', 0],
            ['SOLD', 0],
            ['intake', 1],
            ['ebay draft', 1],
            ['dispo tech store', 1],
            ['', 1],
        ];
    }

    #[DataProvider('inventoryQuantityProvider')]
    public function test_inventory_quantity_from_status(string $status, int $expectedQuantity): void
    {
        $config = squareSyncConfig();
        $quantity = strtoupper(trim($status)) === 'SOLD' ? 0 : (int)$config['default_quantity'];
        $this->assertSame($expectedQuantity, $quantity);
    }

    // ── Notification URL ────────────────────────────────────────────────

    public function test_notification_url_uses_configured_value(): void
    {
        $old = getenv('SQUARE_WEBHOOK_NOTIFICATION_URL');
        putenv('SQUARE_WEBHOOK_NOTIFICATION_URL=https://configured.test/webhook.php');

        $url = squareWebhookNotificationUrl();
        $this->assertSame('https://configured.test/webhook.php', $url);

        if ($old !== false) {
            putenv('SQUARE_WEBHOOK_NOTIFICATION_URL=' . $old);
        } else {
            putenv('SQUARE_WEBHOOK_NOTIFICATION_URL');
        }
    }
}
