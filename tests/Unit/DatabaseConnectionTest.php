<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Database Connection Tests — verifies PDO creation and error handling.
 *
 * [Database Layer] — PDO connection lifecycle, error modes, schema init.
 */
#[CoversNothing]
final class DatabaseConnectionTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createSandboxDatabase();
    }

    public function test_can_connect_to_sqlite_in_memory(): void
    {
        $this->assertInstanceOf(PDO::class, $this->pdo);
        $this->assertEquals('sqlite', $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public function test_pdo_exception_mode_is_set(): void
    {
        $errmode = $this->pdo->getAttribute(PDO::ATTR_ERRMODE);
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $errmode);
    }

    public function test_foreign_keys_are_enabled(): void
    {
        $stmt = $this->pdo->query('PRAGMA foreign_keys');
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function test_intake_items_table_exists(): void
    {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='intake_items'");
        $this->assertSame('intake_items', $stmt->fetchColumn());
    }

    public function test_sku_photos_table_exists(): void
    {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='sku_photos'");
        $this->assertSame('sku_photos', $stmt->fetchColumn());
    }

    public function test_intake_deleted_table_exists(): void
    {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='intake_deleted'");
        $this->assertSame('intake_deleted', $stmt->fetchColumn());
    }

    public function test_intake_deleted_has_deleted_at_column(): void
    {
        $cols = $this->pdo->query('PRAGMA table_info(intake_deleted)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        $this->assertContains('deleted_at', $names);
    }

    /**
     * [PHP Logic & Syntax] — malformed query must throw.
     */
    public function test_malformed_query_throws_exception(): void
    {
        $this->expectException(PDOException::class);
        $this->pdo->query('SELECT * FROM nonexistent_table');
    }

    /**
     * [PHP Logic & Syntax] — connection with bad path must not silently fail.
     */
    public function test_connection_to_unwritable_path_throws(): void
    {
        $this->expectException(PDOException::class);
        new PDO('sqlite:/dev/null/nope.sqlite');
    }

    public function test_insert_and_last_insert_id_roundtrips(): void
    {
        $this->pdo->exec("INSERT INTO intake_items (sku) VALUES ('TEST-ROUNDTRIP')");
        $id = (int) $this->pdo->lastInsertId();
        $this->assertGreaterThan(0, $id);

        $stmt = $this->pdo->prepare('SELECT sku FROM intake_items WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $this->assertSame('TEST-ROUNDTRIP', $stmt->fetchColumn());
    }
}
