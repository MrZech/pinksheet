<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../square_webhook_lib.php';

#[CoversNothing]
final class SquareWebhookTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createSandboxDatabase();
        $this->pdo->exec('DROP TABLE square_catalog_sync');
        $this->pdo->exec('CREATE TABLE square_catalog_sync (sku_normalized TEXT PRIMARY KEY, square_variation_id TEXT)');
        squareWebhookEnsureSchema($this->pdo);
        $this->pdo->exec("INSERT INTO intake_items(sku,sku_normalized,status) VALUES ('DT-1','DT-1','Listed'),('DT-2','DT-2','SOLD')");
        $this->pdo->exec("INSERT INTO square_catalog_sync(sku_normalized,square_variation_id) VALUES ('DT-1','var-1'),('DT-2','var-2')");
    }

    public function test_signature_verification_fails_closed_when_any_input_changes(): void
    {
        $body = '{"event_id":"1"}'; $key = 'secret'; $url = 'https://example.test/square_webhook.php';
        $signature = base64_encode(hash_hmac('sha256', $url . $body, $key, true));
        $this->assertTrue(squareWebhookVerifySignature($body, $signature, $key, $url));
        $this->assertFalse(squareWebhookVerifySignature($body . ' ', $signature, $key, $url));
        $this->assertFalse(squareWebhookVerifySignature($body, $signature, $key, $url . '?x=1'));
        $this->assertFalse(squareWebhookVerifySignature($body, $signature, 'other', $url));
        $this->assertFalse(squareWebhookVerifySignature($body, '', $key, $url));
    }

    public function test_claim_deduplicates_processed_event_and_retries_failed_event(): void
    {
        $event = ['event_id' => 'evt-1', 'type' => 'order.updated'];
        $this->assertSame('new', squareWebhookClaimEvent($this->pdo, $event));
        squareWebhookSetEventStatus($this->pdo, 'evt-1', 'processed');
        $this->assertSame('duplicate', squareWebhookClaimEvent($this->pdo, $event));
        squareWebhookSetEventStatus($this->pdo, 'evt-1', 'failed', 'test');
        $this->assertSame('retry', squareWebhookClaimEvent($this->pdo, $event));
        $this->assertSame(1, (int)$this->pdo->query("SELECT retry_count FROM square_webhook_events WHERE event_id='evt-1'")->fetchColumn());
    }

    public function test_completed_payment_marks_all_mapped_items_and_records_lines_idempotently(): void
    {
        $event = ['event_id'=>'evt-pay','type'=>'payment.updated','data'=>['object'=>['payment'=>['id'=>'pay-1','order_id'=>'order-1','status'=>'COMPLETED','amount_money'=>['amount'=>300,'currency'=>'USD']]]]];
        $api = static fn(string $path): array => ['order'=>['line_items'=>[
            ['uid'=>'line-1','catalog_object_id'=>'var-1','quantity'=>'1','total_money'=>['amount'=>100,'currency'=>'USD']],
            ['uid'=>'line-2','catalog_object_id'=>'var-2','quantity'=>'1','total_money'=>['amount'=>200,'currency'=>'USD']],
            ['uid'=>'custom','quantity'=>'1'], ['uid'=>'unmapped','catalog_object_id'=>'missing','quantity'=>'1'],
        ]]];
        squareWebhookClaimEvent($this->pdo, $event); squareWebhookDispatch($this->pdo, $event, $api);
        $this->assertSame('SOLD', $this->pdo->query("SELECT status FROM intake_items WHERE sku_normalized='DT-1'")->fetchColumn());
        $this->assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM square_sale_items WHERE square_payment_id='pay-1'")->fetchColumn());
        squareWebhookProcessPayment($this->pdo, $event, $api);
        $this->assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM square_sale_items WHERE square_payment_id='pay-1'")->fetchColumn());
    }

    public function test_inventory_zero_is_observed_but_never_marks_item_sold(): void
    {
        $event=['event_id'=>'evt-inv','type'=>'inventory.count.updated','data'=>['object'=>['inventory_counts'=>[['catalog_object_id'=>'var-1','location_id'=>'loc','state'=>'IN_STOCK','quantity'=>'0']]]]];
        squareWebhookClaimEvent($this->pdo,$event); squareWebhookDispatch($this->pdo,$event);
        $this->assertSame('Listed',$this->pdo->query("SELECT status FROM intake_items WHERE sku_normalized='DT-1'")->fetchColumn());
        $this->assertSame('warning',$this->pdo->query("SELECT reconciliation_status FROM square_inventory_events WHERE event_id='evt-inv'")->fetchColumn());
    }

    public function test_completed_refund_moves_sold_item_to_inspection_status(): void
    {
        $this->pdo->exec("INSERT INTO square_sale_items(square_payment_id,square_line_item_uid,sku_normalized) VALUES ('pay-2','line','DT-2')");
        putenv('SQUARE_WEBHOOK_REFUND_STATUS=RETURNED - NEEDS INSPECTION');
        $event=['event_id'=>'evt-refund','type'=>'refund.updated','data'=>['object'=>['refund'=>['id'=>'ref-1','payment_id'=>'pay-2','status'=>'COMPLETED']]]];
        squareWebhookClaimEvent($this->pdo,$event); squareWebhookDispatch($this->pdo,$event);
        $this->assertSame('RETURNED - NEEDS INSPECTION',$this->pdo->query("SELECT status FROM intake_items WHERE sku_normalized='DT-2'")->fetchColumn());
        squareWebhookProcessRefund($this->pdo,$event);
        $this->assertSame('RETURNED - NEEDS INSPECTION',$this->pdo->query("SELECT status FROM intake_items WHERE sku_normalized='DT-2'")->fetchColumn());
    }
}
