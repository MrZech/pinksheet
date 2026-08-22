<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Spreadsheet-with-photos export tests.
 *
 * export_spreadsheet.php builds an .xlsx with one row per SKU and the photos
 * embedded as anchored thumbnails, so the boss can open one file anywhere
 * (Excel / LibreOffice / Numbers / Google Sheets) with no external setup.
 *
 * These tests pin:
 *  1. The endpoint streams a real .xlsx download with the right content type,
 *     uses the export concurrency lock, and supports the active scope.
 *  2. The pure part builders (lib/sheet_xlsx.php) produce a structurally
 *     valid workbook: every XML part is well-formed, the sheet references the
 *     drawing part, the drawing references the media, and the media bytes are
 *     actually embedded — validated end-to-end through the pure-PHP ZIP
 *     writer, the same path the production server uses (no zip extension).
 */
#[CoversNothing]
final class ExportSpreadsheetTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(TESTING_ROOT . '/export_spreadsheet.php');
    }

    public function test_endpoint_streams_an_xlsx_download(): void
    {
        $source = $this->source();
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $source);
        $this->assertStringContainsString('Content-Disposition: attachment', $source);
        $this->assertStringContainsString('inventory_photos_', $source);
        $this->assertStringContainsString('.xlsx', $source);
        $this->assertStringContainsString('lib/sheet_xlsx.php', $source);
    }

    public function test_endpoint_uses_the_export_lock_and_active_scope(): void
    {
        $source = $this->source();
        $this->assertStringContainsString('.bundle_export.lock', $source);
        $this->assertStringContainsString('LOCK_EX | LOCK_NB', $source);
        $this->assertStringContainsString("LOWER(TRIM(status)) != 'sold'", $source);
    }

    public function test_endpoint_streams_via_pure_writer_when_zip_is_missing(): void
    {
        $source = $this->source();
        $this->assertStringContainsString('bundleWritePureZipToStream', $source);
        $this->assertStringContainsString("fopen('php://output', 'wb')", $source);
    }

    /**
     * Build a complete workbook through the pure-PHP writers and validate
     * that every part is present, well-formed, and correctly wired together.
     */
    public function test_built_workbook_is_structurally_valid_with_embedded_photo(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is needed to validate the workbook.');
        }
        require_once TESTING_ROOT . '/lib/sheet_xlsx.php';
        require_once TESTING_ROOT . '/lib/bundle_zip.php';

        $dir = TESTING_ROOT . '/tmp/test_data/sheet_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);

        // A tiny real JPEG so the media bytes are a genuine image.
        $jpeg = base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='
        );
        $photoPath = $dir . '/photo.jpg';
        file_put_contents($photoPath, $jpeg);
        $this->assertGreaterThan(0, strlen($jpeg));

        // Generate a thumbnail like the endpoint does.
        $thumbsDir = $dir . '/thumbs';
        mkdir($thumbsDir, 0777, true);
        $thumb = sheetThumbnail($photoPath, $thumbsDir, 7);
        $this->assertNotNull($thumb, 'thumbnail generation must succeed');
        [$thumbPath, $tw, $th] = $thumb;
        $this->assertGreaterThan(0, $tw);
        $this->assertGreaterThan(0, $th);

        $rows = [
            ['SKU', 'Status', 'What is it?', 'eBay Category', 'Qty', 'Dispotech Price', 'eBay Price', 'Updated', 'Photos'],
            ['A-1', 'intake', 'ThinkPad T480', 'Laptops', 2, 249.99, '', '2026-08-20 10:00:00', ''],
            ['B-2 & <C>', 'sold', "line1\nline2", '', 1, '', 150.5, '', ''],
        ];

        $image = [
            'row' => 1,
            'yOffEmu' => 0,
            'wEmu' => $tw * SHEET_PX_TO_EMU,
            'hEmu' => $th * SHEET_PX_TO_EMU,
            'rId' => 1,
            'path' => $thumbPath,
        ];

        $parts = [
            '[Content_Types].xml'              => sheetBuildContentTypes(true),
            '_rels/.rels'                      => sheetBuildRootRels(),
            'docProps/core.xml'                => sheetBuildCoreProps(),
            'docProps/app.xml'                 => sheetBuildAppProps(),
            'xl/workbook.xml'                  => sheetBuildWorkbook(),
            'xl/_rels/workbook.xml.rels'       => sheetBuildWorkbookRels(),
            'xl/theme/theme1.xml'              => sheetBuildTheme(),
            'xl/styles.xml'                    => sheetBuildStyles(),
            'xl/worksheets/sheet1.xml'         => sheetBuildSheetXml($rows, true, [1 => 60.0], [1 => 16, 2 => 13, 9 => 30]),
            'xl/worksheets/_rels/sheet1.xml.rels' => sheetBuildSheetRels(),
            'xl/drawings/drawing1.xml'         => sheetBuildDrawing([$image]),
            'xl/drawings/_rels/drawing1.xml.rels' => sheetBuildDrawingRels(1),
        ];

        $entries = [];
        foreach ($parts as $name => $content) {
            $entries[] = ['name' => $name, 'content' => $content];
        }
        $entries[] = ['name' => 'xl/media/image1.jpg', 'path' => $thumbPath];

        $xlsxPath = $dir . '/out.xlsx';
        bundleWritePureZip($xlsxPath, $entries);

        $this->assertFileExists($xlsxPath);
        $this->assertGreaterThan(0, filesize($xlsxPath));

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($xlsxPath) === true, 'workbook must be a valid zip');

        // Every XML part must be well-formed.
        $expectedParts = [
            '[Content_Types].xml', '_rels/.rels', 'docProps/core.xml', 'docProps/app.xml',
            'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/theme/theme1.xml',
            'xl/styles.xml', 'xl/worksheets/sheet1.xml',
            'xl/worksheets/_rels/sheet1.xml.rels', 'xl/drawings/drawing1.xml',
            'xl/drawings/_rels/drawing1.xml.rels', 'xl/media/image1.jpg',
        ];
        foreach ($expectedParts as $part) {
            $this->assertNotFalse($zip->statName($part), "missing part: $part");
            if (str_ends_with($part, '.xml') || str_ends_with($part, '.rels')) {
                $xml = $zip->getFromName($part);
                $this->assertIsString($xml);
                $prev = libxml_use_internal_errors(true);
                $doc = simplexml_load_string($xml);
                $this->assertNotFalse($doc, "part is not well-formed XML: $part");
                libxml_use_internal_errors($prev);
            }
        }

        // The sheet must reference the drawing part.
        $sheet = (string)$zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('<drawing r:id="rId1"/>', $sheet);
        $this->assertStringContainsString('ThinkPad T480', $sheet);
        $this->assertStringContainsString('A-1', $sheet);
        $this->assertStringContainsString('249.99', $sheet, 'numbers must be written as numeric values');
        $this->assertStringContainsString("line1\nline2", $sheet, 'newlines are preserved verbatim in the inline string (valid XML, rendered as a line break)');
        $this->assertStringContainsString('B-2 &amp; &lt;C&gt;', $sheet, 'special characters must be XML-escaped');
        $this->assertStringContainsString('ySplit="1"', $sheet, 'header row must be frozen');

        // The drawing must reference the media image and anchor it at the
        // data row (xdr:row = 1, i.e. just below the header).
        $drawing = (string)$zip->getFromName('xl/drawings/drawing1.xml');
        $this->assertStringContainsString('r:embed="rId1"', $drawing);
        $this->assertStringContainsString('<xdr:col>8</xdr:col>', $drawing);
        $this->assertStringContainsString('<xdr:row>1</xdr:row>', $drawing);

        // The embedded media bytes must match the thumbnail file.
        $this->assertSame(
            hash_file('sha256', $thumbPath),
            hash('sha256', (string)$zip->getFromName('xl/media/image1.jpg'))
        );

        $zip->close();
        $this->removeDir($dir);
    }

    public function test_workbook_without_photos_has_no_drawing_parts(): void
    {
        require_once TESTING_ROOT . '/lib/sheet_xlsx.php';

        $contentTypes = sheetBuildContentTypes(false);
        $this->assertStringNotContainsString('drawing', $contentTypes);

        $sheet = sheetBuildSheetXml([
            ['SKU', 'Status', 'What is it?', 'eBay Category', 'Qty', 'Dispotech Price', 'eBay Price', 'Updated', 'Photos'],
            ['A-1', 'intake', 'Laptop', 'Laptops', 1, 100.0, '', '', ''],
        ], false);
        $this->assertStringNotContainsString('<drawing', $sheet);
        $this->assertStringContainsString('Laptop', $sheet);
    }

    /**
     * Recursively remove a temporary directory tree.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
