<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Google Sheets mirror script tests.
 *
 * scripts/sync_google_sheet.php pushes the inventory to a Google Sheet using
 * the service-account OAuth flow. These tests pin the two pieces that are
 * pure and can regress locally without touching the network:
 *
 *  1. The RS256 JWT is signed correctly (verifiable with the public key) and
 *     carries the right iss / aud / scope / exp claims for Google's token
 *     endpoint.
 *  2. The value grid matches the CSV export columns and handles missing
 *     fields and the eBay category fallback.
 *
 * The CLI main() is not executed when the file is included (argv guard), and
 * config.php is only loaded on the CLI path, so requiring the script here is
 * safe for the test suite.
 */
#[CoversNothing]
final class GoogleSheetSyncTest extends TestCase
{
    private function loadScript(): void
    {
        require_once TESTING_ROOT . '/scripts/sync_google_sheet.php';
    }

    /**
     * Create an RSA keypair and return [pem private key, pem public key].
     *
     * @return array{0:string,1:string}
     */
    private function makeKeyPair(): array
    {
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        // On Windows, openssl_pkey_new fails silently without an openssl.cnf.
        foreach ([
            dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
            dirname(PHP_BINARY) . '/openssl.cnf',
            TESTING_ROOT . '/php-8.5.4/extras/ssl/openssl.cnf',
            'C:/Program Files/Common Files/SSL/openssl.cnf',
            'C:/Program Files/Git/usr/ssl/openssl.cnf',
        ] as $candidate) {
            if (is_file($candidate)) {
                $config['config'] = $candidate;
                break;
            }
        }
        $res = openssl_pkey_new($config);
        $this->assertNotFalse($res, 'openssl must be able to generate a key pair');
        $private = '';
        $exportOptions = isset($config['config']) ? ['config' => $config['config']] : null;
        $this->assertTrue(openssl_pkey_export($res, $private, null, $exportOptions), 'private key export must succeed');
        $details = openssl_pkey_get_details($res);
        $this->assertIsArray($details);
        return [$private, (string)$details['key']];
    }

    public function test_build_jwt_is_validly_signed_with_correct_claims(): void
    {
        $this->loadScript();
        if (!function_exists('openssl_sign')) {
            $this->markTestSkipped('openssl extension is required.');
        }

        [$privatePem, $publicPem] = $this->makeKeyPair();
        $key = [
            'client_email' => 'pinksheet-sync@my-project.iam.gserviceaccount.com',
            'private_key'  => $privatePem,
        ];
        $now = 1700000000;

        $jwt = googleSheetBuildJwt($key, $now);
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts, 'JWT must have header, claims and signature');

        $header = json_decode((string)base64_decode(strtr($parts[0], '-_', '+/')), true);
        $claims = json_decode((string)base64_decode(strtr($parts[1], '-_', '+/')), true);

        $this->assertSame('RS256', $header['alg'] ?? null);
        $this->assertSame('JWT', $header['typ'] ?? null);
        $this->assertSame($key['client_email'], $claims['iss'] ?? null);
        $this->assertSame('https://oauth2.googleapis.com/token', $claims['aud'] ?? null);
        $this->assertSame('https://www.googleapis.com/auth/spreadsheets', $claims['scope'] ?? null);
        $this->assertSame($now, $claims['iat'] ?? null);
        $this->assertSame($now + 3600, $claims['exp'] ?? null);

        // Signature must verify against the public key (proves the private
        // key signed the exact header.claims payload).
        $signature = (string)base64_decode(strtr($parts[2], '-_', '+/'));
        $this->assertSame(
            1,
            openssl_verify($parts[0] . '.' . $parts[1], $signature, $publicPem, OPENSSL_ALGO_SHA256),
            'JWT signature must verify with the public key'
        );
    }

    public function test_build_jwt_rejects_missing_private_key(): void
    {
        $this->loadScript();
        $this->expectException(RuntimeException::class);
        googleSheetBuildJwt(['client_email' => 'x@example.com'], time());
    }

    public function test_build_rows_matches_the_csv_export_columns(): void
    {
        $this->loadScript();

        $grid = googleSheetBuildRows([
            [
                'sku' => 'A-1',
                'status' => 'intake',
                'what_is_it' => 'ThinkPad T480, 16GB',
                'ebay_category' => 'Laptops & Netbooks',
                'ebay_category_path' => '',
                'quantity' => 2,
                'dispotech_price' => 249.99,
                'ebay_price' => 299.0,
                'updated_at' => '2026-08-20 10:00:00',
            ],
            [
                // Legacy row: no ebay_category, must fall back to the path,
                // and nulls must become empty strings (not "NULL").
                'sku' => null,
                'status' => 'SOLD',
                'what_is_it' => null,
                'ebay_category' => null,
                'ebay_category_path' => 'Cell Phones & Accessories > Smartphones',
                'quantity' => null,
                'dispotech_price' => null,
                'ebay_price' => 150.5,
                'updated_at' => null,
            ],
        ]);

        $this->assertSame(
            ['SKU', 'Status', 'What is it?', 'eBay Category', 'Qty', 'Dispotech Price', 'eBay Price', 'Updated'],
            $grid[0]
        );

        $this->assertSame(
            ['A-1', 'intake', 'ThinkPad T480, 16GB', 'Laptops & Netbooks', '2', '249.99', '299', '2026-08-20 10:00:00'],
            $grid[1]
        );

        $this->assertSame(
            ['', 'SOLD', '', 'Cell Phones & Accessories > Smartphones', '1', '', '150.5', ''],
            $grid[2],
            'nulls become empty strings, quantity defaults to 1, category falls back to path'
        );
    }

    public function test_build_rows_with_no_items_returns_just_the_header(): void
    {
        $this->loadScript();
        $grid = googleSheetBuildRows([]);
        $this->assertCount(1, $grid);
        $this->assertSame('SKU', $grid[0][0]);
    }
}
