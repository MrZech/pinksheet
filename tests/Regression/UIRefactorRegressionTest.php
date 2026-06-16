<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * UI Refactor Regression Tests.
 *
 * Enshrines the CSS variable contracts and DOM structure contracts that
 * were established during the square-design + mobile-responsiveness
 * refactor.
 *
 * These are *contract* tests: they verify that downstream consumers
 * (tests, JS selectors, PHP templates) use the correct variables.
 * They do NOT verify visual output (that requires a browser), but
 * they protect against regressions in the contract surface.
 *
 * [UI Refactor] — CSS variable contracts, DOM structure contracts.
 */
#[CoversNothing]
final class UIRefactorRegressionTest extends TestCase
{
    private string $styleFile;
    private string $kanbanFile;

    protected function setUp(): void
    {
        $this->styleFile  = TESTING_ROOT . '/assets/style.css';
        $this->kanbanFile = TESTING_ROOT . '/kanban.php';
    }

    // ── CSS Variable Contract ───────────────────────────────────────────

    public function test_border_radius_xs_is_2px(): void
    {
        $css = file_get_contents($this->styleFile);
        $this->assertStringContainsString('--radius-xs: 2px', $css);
    }

    public function test_border_radius_sm_is_4px(): void
    {
        $css = file_get_contents($this->styleFile);
        $this->assertStringContainsString('--radius-sm: 4px', $css);
    }

    public function test_border_radius_md_is_6px(): void
    {
        $css = file_get_contents($this->styleFile);
        $this->assertStringContainsString('--radius-md: 6px', $css);
    }

    public function test_border_radius_lg_is_8px(): void
    {
        $css = file_get_contents($this->styleFile);
        $this->assertStringContainsString('--radius-lg: 8px', $css);
    }

    public function test_border_radius_xl_is_10px(): void
    {
        $css = file_get_contents($this->styleFile);
        $this->assertStringContainsString('--radius-xl: 10px', $css);
    }

    public function test_border_radius_2xl_is_12px(): void
    {
        $css = file_get_contents($this->styleFile);
        $this->assertStringContainsString('--radius-2xl: 12px', $css);
    }

    // ── Reviewed Checkbox Contract ──────────────────────────────────────

    public function test_reviewed_checkbox_is_a_span_not_input(): void
    {
        $html = file_get_contents($this->kanbanFile);
        // Must use span, not input[type=checkbox]
        $this->assertStringContainsString('<span', $html);
        // Should NOT contain the old checkbox input pattern
        $this->assertStringNotContainsString('type="checkbox"', $html);
    }

    public function test_reviewed_checkbox_has_data_sku(): void
    {
        $html = file_get_contents($this->kanbanFile);
        $this->assertStringContainsString('data-sku=', $html);
    }

    // ── Kanban Card Contract ────────────────────────────────────────────

    public function test_kanban_card_has_card_body(): void
    {
        $html = file_get_contents($this->kanbanFile);
        $this->assertStringContainsString('card-body', $html);
    }

    public function test_kanban_card_has_action_buttons(): void
    {
        $html = file_get_contents($this->kanbanFile);
        // Check for delete and print buttons
        $this->assertStringContainsString('data-sku', $html);
    }

    // ── Responsive Breakpoints ──────────────────────────────────────────

    public function test_css_has_tablet_breakpoint(): void
    {
        $css = file_get_contents($this->styleFile);
        $this->assertStringContainsString('max-width: 1023px', $css);
    }

    public function test_css_has_tablet_small_breakpoint(): void
    {
        $css = file_get_contents($this->styleFile);
        $this->assertStringContainsString('max-width: 768px', $css);
    }

    public function test_css_has_mobile_breakpoint(): void
    {
        $css = file_get_contents($this->styleFile);
        $this->assertStringContainsString('max-width: 600px', $css);
    }

    public function test_css_has_small_mobile_breakpoint(): void
    {
        $css = file_get_contents($this->styleFile);
        $this->assertStringContainsString('max-width: 480px', $css);
    }

    // ── CSS Class Contract (no removal of critical classes) ─────────────

    public function test_css_has_dashboard_grid(): void
    {
        $css = file_get_contents($this->styleFile);
        $this->assertStringContainsString('dashboard-grid', $css);
    }

    public function test_css_has_quick_links_grid(): void
    {
        $css = file_get_contents($this->styleFile);
        $this->assertStringContainsString('quick-links', $css);
    }

    public function test_css_has_kanban_lane_selectors(): void
    {
        $css = file_get_contents($this->styleFile);
        $this->assertStringContainsString('kanban-lane', $css);
    }

    // ── Menu JS Contract ────────────────────────────────────────────────

    public function test_menu_js_initializes_menu_toggle(): void
    {
        $menuJs = file_get_contents(TESTING_ROOT . '/assets/menu.js');
        $this->assertStringContainsString('menuToggle', $menuJs);
        $this->assertStringContainsString('aria-expanded', $menuJs);
        $this->assertStringContainsString('aria-hidden', $menuJs);
    }

    public function test_menu_js_new_intake_clears_draft(): void
    {
        $menuJs = file_get_contents(TESTING_ROOT . '/assets/menu.js');
        $this->assertStringContainsString('data-new-intake', $menuJs);
        $this->assertStringContainsString('localStorage', $menuJs);
        $this->assertStringContainsString('intakeDraftV1', $menuJs);
    }

    // ── App JS Contract ─────────────────────────────────────────────────

    public function test_app_js_has_reviewed_toggle_handler(): void
    {
        $html = file_get_contents($this->kanbanFile);
        // reviewed-checkbox toggle moved inline to kanban.php
        $this->assertStringContainsString('reviewed-checkbox', $html);
    }

    public function test_app_js_has_drag_drop_handler(): void
    {
        $html = file_get_contents($this->kanbanFile);
        // Drag-and-drop moved inline to kanban.php
        $this->assertStringContainsString('drag', $html);
    }
}
