<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Print Layout Regression Tests.
 *
 * Validates that the four-row print grid structure, CSS rules, and
 * visual regression baseline configuration remain stable.
 *
 * ── DOM Structure ──────────────────────────────────
 * Assert that the four row containers exist in the
 * correct parent-child order inside the .print-grid
 * element in index.php.
 *
 * ── CSS Contract ───────────────────────────────────
 * Assert key print.css rules (grid, hiding, dimensions)
 * are present and use the expected values.
 *
 * ── Visual Regression ──────────────────────────────
 * Provides a BackstopJS scenario configuration for
 * pixel-level snapshot comparison. Run with:
 *   npx backstop test --config=backstop.print.js
 *
 * [Print Layout] — DOM structure, CSS contracts.
 */
#[CoversNothing]
final class PrintLayoutTest extends TestCase
{
    private string $indexFile;
    private string $printCssFile;

    protected function setUp(): void
    {
        $this->indexFile   = TESTING_ROOT . '/index.php';
        $this->printCssFile = TESTING_ROOT . '/assets/print.css';
    }

    // ── DOM STRUCTURE ─────────────────────────────────────────────────────

    public function test_print_grid_container_exists_in_markup(): void
    {
        $html = file_get_contents($this->indexFile);
        $this->assertNotFalse($html);

        $this->assertStringContainsString(
            '<div class="print-grid"',
            $html,
            'The .print-grid container must be present in index.php markup'
        );
    }

    public function test_four_print_rows_exist_in_correct_order(): void
    {
        $html = file_get_contents($this->indexFile);
        $this->assertNotFalse($html);

        $expectedRows = [
            'print-header-row',
            'print-fields-row',
            'print-notes-row',
            'print-gallery-row',
        ];

        // Find the .print-grid block and extract row class names in order
        $pattern = '/<div\s+class="print-grid"[^>]*>.*?<\/div>\s*<\/div>\s*<\/div>\s*<\/div>\s*<\/div>\s*<\/section>/s';
        if (!preg_match($pattern, $html, $matches)) {
            // Fallback: find print-grid start and count rows sequentially
            $start = strpos($html, '<div class="print-grid"');
            $this->assertNotFalse($start, '.print-grid opening tag must exist');
        }

        $lastPos = 0;
        foreach ($expectedRows as $i => $rowClass) {
            $search = 'print-row ' . $rowClass;
            $pos = strpos($html, $search, $lastPos);
            $this->assertNotFalse(
                $pos,
                sprintf('Row %d: class "print-row %s" must exist in DOM order', $i + 1, $rowClass)
            );
            $this->assertGreaterThan(
                $lastPos,
                $pos,
                sprintf('Row %d ("%s") must appear after previous row', $i + 1, $rowClass)
            );
            $lastPos = $pos;
        }
    }

    public function test_print_grid_directly_contains_all_four_rows(): void
    {
        $html = file_get_contents($this->indexFile);
        $this->assertNotFalse($html);

        // Extract the print-grid block bounded by the PRINT LAYOUT comment
        // and the following recent-items section.
        $marker = 'PRINT LAYOUT: four rigid horizontal rows';
        $markerPos = strpos($html, $marker);
        $this->assertNotFalse($markerPos, 'Print layout comment marker must exist');

        $gridOpenPos = strpos($html, '<div class="print-grid"', $markerPos);
        $this->assertNotFalse($gridOpenPos, '.print-grid opening tag must exist after comment marker');

        $sectionPos = strpos($html, '</section>', $gridOpenPos);
        $this->assertNotFalse($sectionPos, 'closing section tag must follow .print-grid');

        $gridBlock = substr($html, $gridOpenPos, $sectionPos - $gridOpenPos);

        $rowClasses = [
            'print-header-row',
            'print-fields-row',
            'print-notes-row',
            'print-gallery-row',
        ];

        foreach ($rowClasses as $cls) {
            $this->assertStringContainsString(
                $cls,
                $gridBlock,
                sprintf('.print-grid must contain .%s', $cls)
            );
        }

        // The first child of .print-grid (after the opening tag and whitespace/comments)
        // should be the print-header-row, not an intermediate wrapper.
        // Remove comments and whitespace to check the first real element.
        $stripped = preg_replace('/<!--.*?-->/s', '', $gridBlock);
        $stripped = trim(preg_replace('/\s+/', ' ', $stripped));
        // After stripping, find the opening tag's >
        $firstClose = strpos($stripped, '>');
        $afterOpen = trim(substr($stripped, $firstClose + 1));
        $this->assertStringStartsWith(
            '<div class="print-row print-header-row"',
            $afterOpen,
            'The first child of .print-grid must be the print-header-row (no intermediate wrapper)'
        );
    }

    public function test_print_grid_setup_contains_sku_price_status_and_thumbnail(): void
    {
        $html = file_get_contents($this->indexFile);
        $this->assertNotFalse($html);

        $this->assertStringContainsString(
            'class="print-label">SKU</span>',
            $html,
            'Print grid header must contain SKU label'
        );
        $this->assertStringContainsString(
            'class="print-label">Price</span>',
            $html,
            'Print grid header must contain Price label'
        );
        $this->assertStringContainsString(
            'class="print-label">Status</span>',
            $html,
            'Print grid header must contain Status label'
        );
        $this->assertStringContainsString(
            'class="print-thumb-cell"',
            $html,
            'Print grid header must contain thumbnail cell'
        );
        $this->assertStringContainsString(
            'class="print-qr-cell"',
            $html,
            'Print grid header must contain QR code cell'
        );
    }

    public function test_print_grid_has_aria_hidden_attribute(): void
    {
        $html = file_get_contents($this->indexFile);
        $this->assertNotFalse($html);

        $this->assertStringContainsString(
            'aria-hidden="true"',
            $html,
            '.print-grid should have aria-hidden="true" to hide from screen readers'
        );
    }

    public function test_row_d1_d2_panels_exist_as_empty_containers(): void
    {
        $html = file_get_contents($this->indexFile);
        $this->assertNotFalse($html);

        $this->assertStringContainsString(
            'id="print-d1-panel"',
            $html,
            'D1 panel must have id="print-d1-panel" for JS population'
        );
        $this->assertStringContainsString(
            'id="print-d2-panel"',
            $html,
            'D2 panel must have id="print-d2-panel" for JS population'
        );
    }

    public function test_notes_row_has_empty_content_placeholder(): void
    {
        $html = file_get_contents($this->indexFile);
        $this->assertNotFalse($html);

        $this->assertStringContainsString(
            'id="print-notes-content"',
            $html,
            'Notes row must have id="print-notes-content" for JS population'
        );
    }

    public function test_gallery_row_exists_for_js_population(): void
    {
        $html = file_get_contents($this->indexFile);
        $this->assertNotFalse($html);

        $this->assertStringContainsString(
            'id="print-gallery-row"',
            $html,
            'Gallery row must have id="print-gallery-row" for JS population'
        );
    }

    // ── CSS CONTRACT ──────────────────────────────────────────────────────

    public function test_print_css_has_page_rule(): void
    {
        $css = file_get_contents($this->printCssFile);
        $this->assertNotFalse($css);

        $this->assertStringContainsString(
            '@page',
            $css,
            'print.css must contain an @page rule'
        );
    }

    public function test_page_rule_specifies_portrait(): void
    {
        $css = file_get_contents($this->printCssFile);
        $this->assertNotFalse($css);

        $this->assertStringContainsString(
            'size: letter portrait',
            $css,
            '@page must specify size: letter portrait'
        );
    }

    public function test_page_rule_margin_is_04in(): void
    {
        $css = file_get_contents($this->printCssFile);
        $this->assertNotFalse($css);

        $this->assertStringContainsString(
            'margin: 0.4in',
            $css,
            '@page margin must be 0.4in'
        );
    }

    public function test_media_print_block_exists(): void
    {
        $css = file_get_contents($this->printCssFile);
        $this->assertNotFalse($css);

        $this->assertStringContainsString(
            '@media print',
            $css,
            'print.css must contain @media print block'
        );
    }

    public function test_print_grid_is_grid_display(): void
    {
        $css = file_get_contents($this->printCssFile);
        $this->assertNotFalse($css);

        $this->assertStringContainsString(
            '.print-grid',
            $css,
            'print.css must define .print-grid'
        );
        $this->assertStringContainsString(
            'grid-template-rows: auto auto auto auto',
            $css,
            '.print-grid must have four auto rows'
        );
    }

    public function test_header_row_uses_split_layout(): void
    {
        $css = file_get_contents($this->printCssFile);
        $this->assertNotFalse($css);

        $this->assertStringContainsString(
            '.print-header-row',
            $css,
            'print.css must define .print-header-row'
        );
        $this->assertStringContainsString(
            'grid-template-columns: 1fr auto',
            $css,
            '.print-header-row must split left/right'
        );
    }

    public function test_header_left_uses_flex_row(): void
    {
        $css = file_get_contents($this->printCssFile);
        $this->assertNotFalse($css);

        $this->assertStringContainsString(
            '.print-header-left',
            $css
        );
        $this->assertStringContainsString(
            'flex-direction: row',
            $css
        );
    }

    public function test_qr_cell_is_fixed_120px(): void
    {
        $css = file_get_contents($this->printCssFile);
        $this->assertNotFalse($css);

        $this->assertStringContainsString(
            '.print-qr-cell',
            $css
        );
        $this->assertStringContainsString(
            'width: 120px',
            $css
        );
        $this->assertStringContainsString(
            'height: 120px',
            $css
        );
    }

    public function test_fields_row_has_two_equal_columns(): void
    {
        $css = file_get_contents($this->printCssFile);
        $this->assertNotFalse($css);

        $this->assertStringContainsString(
            '.print-fields-row',
            $css
        );
        $this->assertStringContainsString(
            'grid-template-columns: 1fr 1fr',
            $css,
            '.print-fields-row must have two equal columns for D1/D2'
        );
    }

    public function test_gallery_row_has_four_columns(): void
    {
        $css = file_get_contents($this->printCssFile);
        $this->assertNotFalse($css);

        $this->assertStringContainsString(
            '.print-gallery-row',
            $css
        );
        $this->assertStringContainsString(
            'grid-template-columns: repeat(4, 1fr)',
            $css,
            '.print-gallery-row must have four equal columns'
        );
    }

    public function test_gallery_items_have_fixed_height_100px(): void
    {
        $css = file_get_contents($this->printCssFile);
        $this->assertNotFalse($css);

        $this->assertStringContainsString(
            '.print-gallery-item',
            $css
        );
        $this->assertStringContainsString(
            'height: 100px',
            $css,
            'Gallery items must have fixed 100px height'
        );
        $this->assertStringContainsString(
            'object-fit: cover',
            $css,
            'Gallery images must use object-fit: cover'
        );
    }

    public function test_gallery_images_use_relative_dimensions(): void
    {
        $css = file_get_contents($this->printCssFile);
        $this->assertNotFalse($css);

        // Images in gallery are constrained by the parent .print-gallery-item
        // which sets height: 100px and overflow: hidden
        $this->assertStringContainsString(
            '.print-gallery-item',
            $css
        );
        $this->assertStringContainsString(
            'overflow: hidden',
            $css,
            'Gallery items must have overflow: hidden to clip oversized images'
        );

        // The gallery item itself must have a fixed height constraint
        $this->assertStringContainsString(
            'height: 100px',
            $css,
            'Gallery items must have fixed 100px height'
        );

        // Extract the .print-gallery-item img rule block
        $pattern = '/\.print-gallery-item\s+img\s*\{([^}]+)\}/s';
        preg_match($pattern, $css, $matches);
        $this->assertNotEmpty($matches, 'A CSS rule for .print-gallery-item img must exist');

        $ruleBody = $matches[1];

        // Width must be relative (100% or auto), not an absolute unit that could overflow the column
        $this->assertMatchesRegularExpression(
            '/width\s*:\s*100%/',
            $ruleBody,
            '.print-gallery-item img must use width: 100% to fit within its grid cell'
        );

        // Height must be relative (100%) to fill the fixed parent container
        $this->assertMatchesRegularExpression(
            '/height\s*:\s*100%/',
            $ruleBody,
            '.print-gallery-item img must use height: 100% to fill the 100px container'
        );

        // Verify images use object-fit: cover to avoid distortion
        $this->assertStringContainsString(
            'object-fit: cover',
            $ruleBody,
            'Gallery images must use object-fit: cover to crop without distortion'
        );
    }

    public function test_all_rows_have_page_break_avoid(): void
    {
        $css = file_get_contents($this->printCssFile);
        $this->assertNotFalse($css);

        $rowSelectors = [
            '.print-header-row',
            '.print-fields-row',
            '.print-notes-row',
            '.print-gallery-row',
        ];

        foreach ($rowSelectors as $selector) {
            $this->assertStringContainsString(
                $selector,
                $css,
                sprintf('CSS must define %s', $selector)
            );
        }

        // Verify page-break-inside: avoid is applied to rows
        $breakCount = substr_count($css, 'page-break-inside: avoid');
        $this->assertGreaterThanOrEqual(
            4,
            $breakCount,
            'At least 4 page-break-inside: avoid rules must exist (one per row)'
        );
    }

    public function test_ui_chrome_is_hidden_in_print(): void
    {
        $css = file_get_contents($this->printCssFile);
        $this->assertNotFalse($css);

        $hiddenSelectors = [
            '.app-menu',
            '.menu-panel',
            '.print-toggle',
            '.print-button',
            '.theme-toggle',
            '.breadcrumbs',
            '.recent-items',
            '.actions',
            '.copy-sku',
            '.sku-photo-dropzone',
            '.compat-os-btn',
            '.hint',
            '.intake-qr-wrap',
            'input[type="file"]',
            '.modal',
            '#intake-form',
            '.form-grid',
            '.sheet-header',
        ];

        foreach ($hiddenSelectors as $selector) {
            $this->assertStringContainsString(
                $selector,
                $css,
                sprintf('print.css must contain a rule for %s to hide it', $selector)
            );
        }
    }

    public function test_existing_form_is_hidden_in_print(): void
    {
        $css = file_get_contents($this->printCssFile);
        $this->assertNotFalse($css);

        $this->assertStringContainsString(
            'display: none',
            $css,
            'print.css must use display: none to hide screen elements'
        );
    }

    // ── PRINT.PINK OVERLAY ────────────────────────────────────────────────

    public function test_print_pink_variant_is_supported(): void
    {
        $css = file_get_contents($this->printCssFile);
        $this->assertNotFalse($css);

        $this->assertStringContainsString(
            'print-pink',
            $css,
            'print.css must support .print-pink overlay variant'
        );
    }

    // ── VISUAL REGRESSION BASELINE ─────────────────────────────────────────

    /**
     * Generate a BackstopJS scenario configuration for the print layout.
     *
     * Visual regression can be run with:
     *   npx backstop test --config=backstop.print.js
     *
     * This test asserts the configuration file exists or provides
     * instructions for creating one.
     */
    public function test_visual_regression_config_can_be_generated(): void
    {
        $backstopConfig = TESTING_ROOT . '/backstop.print.js';

        if (file_exists($backstopConfig)) {
            $config = file_get_contents($backstopConfig);
            $this->assertStringContainsString(
                '.print-grid',
                $config,
                'BackstopJS config must target the .print-grid layout'
            );
            $this->assertStringContainsString(
                'print',
                $config,
                'BackstopJS config must use print media type'
            );
            return;
        }

        // If config doesn't exist yet, verify the print grid has stable
        // selectors that would make visual regression configuration easy
        $html = file_get_contents($this->indexFile);
        $this->assertNotFalse($html);

        $this->assertStringContainsString(
            'print-header-row',
            $html,
            'Print row selectors are stable for BackstopJS scenario targeting'
        );
        $this->assertStringContainsString(
            'print-fields-row',
            $html
        );
        $this->assertStringContainsString(
            'print-gallery-row',
            $html
        );

        // Emit the recommended BackstopJS config as a data-driven hint
        $this->markTestIncomplete(
            'No backstop.print.js config found. Generate it by running the ' .
            'print-grid visual regression workflow, or create ' .
            'backstop.print.js from the template printed below. ' .
            PHP_EOL . PHP_EOL .
            $this->backstopConfigTemplate()
        );
    }

    /**
     * Returns a ready-to-use BackstopJS configuration targeting the print layout.
     */
    public function backstopConfigTemplate(): string
    {
        $baseUrl = 'http://localhost';

        return <<<JS
// backstop.print.js — Visual regression for Dispo.Tech Print Layout
//
// Usage:
//   1. Install BackstopJS:  npm install -g backstopjs
//   2. Reference reference: backstop reference --config=backstop.print.js
//   3. Test:                backstop test --config=backstop.print.js
//
module.exports = {
  id: 'pinksheet_print',
  viewports: [
    {
      name: 'letter-portrait',
      width: 816,   // 8.5in × 96dpi
      height: 1056, // 11in  × 96dpi
    },
  ],
  scenarios: [
    {
      label: 'print-grid-four-rows',
      url: '{$baseUrl}/intake.php?sku=DT-1000',
      selectors: ['.print-grid'],
      selectorExpansion: true,
      misMatchThreshold: 0.5,
      requireSameDimensions: true,
      readySelector: '.print-grid',
      delay: 500,
      onBeforeScript: 'puppet/onBefore.js',
      onReadyScript: 'puppet/onReady.js',
    },
  ],
  paths: {
    bitmaps_reference: 'tests/visual/backstop/reference',
    bitmaps_test: 'tests/visual/backstop/test',
    html_report: 'tests/visual/backstop/html-report',
    ci_report: 'tests/visual/backstop/ci-report',
  },
  report: ['browser', 'CI'],
  engine: 'puppeteer',
  engineOptions: {
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  },
  asyncCaptureLimit: 3,
  asyncCompareLimit: 10,
  debug: false,
  debugWindow: false,
};
JS;
    }

    // ── PRINT CSS FILE METADATA ──────────────────────────────────────────

    public function test_print_css_file_exists(): void
    {
        $this->assertFileExists($this->printCssFile);
    }

    public function test_print_css_has_no_empty_rules(): void
    {
        $css = file_get_contents($this->printCssFile);
        $this->assertNotFalse($css);

        // Check for empty selectors like "selector {}"
        $this->assertDoesNotMatchRegularExpression(
            '/\{\s*\}/',
            $css,
            'print.css should not contain empty rule blocks'
        );
    }

    /**
     * Ensure the print CSS file is properly linked from index.php.
     */
    public function test_print_css_is_linked_in_html_head(): void
    {
        $html = file_get_contents($this->indexFile);
        $this->assertNotFalse($html);

        $this->assertStringContainsString(
            'media="print"',
            $html,
            'index.php must link print.css with media="print" attribute'
        );
        $this->assertStringContainsString(
            'print.css',
            $html,
            'index.php must reference print.css in a <link> tag'
        );
    }
}
