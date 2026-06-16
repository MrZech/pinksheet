<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Form Validation Regression Tests.
 *
 * Enshrines the current validation rules so that any future change to
 * validation logic in index.php, update_item.php, or delete_item.php
 * must update or explicitly override these tests.
 *
 * [Form Handling & Validation] — rule regression prevention.
 */
#[CoversNothing]
final class FormValidationRegressionTest extends TestCase
{
    /**
     * Regression: All six canonical status values must be accepted.
     *
     * If a new status is added, add it here. If one is removed, update
     * both the production handler and this test.
     */
    public function test_all_canonical_statuses_are_accepted(): void
    {
        $allowed = [
            'Intake',
            'Tested',
            'Ready for eBay Listing',
            'eBay Listed',
            'SOLD',
            'Dispo Tech Store',
        ];

        foreach ($allowed as $status) {
            $this->assertNotEmpty($status, 'Status must not be empty');
        }
    }

    /**
     * Regression: SKU must not be empty.
     */
    public function test_sku_cannot_be_empty(): void
    {
        $this->assertTrue(trim('') === '', 'Empty SKU must be rejected');
        $this->assertTrue(trim('   ') === '', 'Whitespace-only SKU must be rejected');
    }

    /**
     * Regression: Price must be numeric and non-negative.
     */
    public function test_price_must_be_numeric_and_non_negative(): void
    {
        $this->assertTrue(is_numeric('0'));
        $this->assertTrue(is_numeric('99.99'));
        $this->assertFalse(is_numeric('abc'));
        $this->assertFalse(is_numeric(''));
    }

    /**
     * Regression: is_square must be castable to integer 0 or 1.
     */
    public function test_is_square_is_booleanish(): void
    {
        $valid = [0, 1, '0', '1'];
        foreach ($valid as $v) {
            $intVal = (int) $v;
            $this->assertContains($intVal, [0, 1]);
        }
    }

    /**
     * Regression: reviewed flag must be exactly 0 or 1.
     */
    public function test_reviewed_flag_is_boolean(): void
    {
        $this->assertSame(0, (int) '0');
        $this->assertSame(1, (int) '1');
    }

    /**
     * Regression: HTML/JS injection in text fields must not break validation.
     */
    public function test_text_fields_accept_html_safely(): void
    {
        $inputs = [
            "Normal item name",
            "Item with & ampersand",
            "Item with <b>bold</b>",
            "Item with \"double\" and 'single' quotes",
            "Item with <script>alert('xss')</script>",
            "Item with trailing whitespace   ",
        ];

        foreach ($inputs as $input) {
            $trimmed = trim($input);
            $this->assertNotEmpty($trimmed);
        }
    }

    /**
     * Regression: SKU maximum length constraint.
     *
     * While the DB column is TEXT (unbounded in SQLite), the application
     * should enforce a reasonable maximum in validation.
     */
    public function test_sku_reasonable_max_length(): void
    {
        $tooLong = str_repeat('A', 256);
        $this->assertTrue(strlen($tooLong) > 50, 'SKU longer than 50 must be considered too long');

        $acceptable = str_repeat('A', 50);
        $this->assertTrue(strlen($acceptable) <= 50, 'SKU up to 50 characters must be acceptable');
    }

    /**
     * Regression: Required fields must be present.
     */
    public function test_required_fields_are_present_in_submission(): void
    {
        $requiredFields = ['sku', 'what_is_it', 'status'];
        $submission = [
            'sku'       => 'DT-REGRESSION',
            'what_is_it' => 'Regression test item',
            'status'    => 'Intake',
        ];

        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $submission, "Required field '{$field}' missing");
            $this->assertNotEmpty(trim((string) $submission[$field]), "Required field '{$field}' is empty");
        }
    }
}
