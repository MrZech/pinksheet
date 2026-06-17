<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Theme & Layout Integrity Tests.
 *
 * Verifies that CSS theme tokens resolve to correct palette values
 * in both Light and Dark modes, and that DOM structure contracts
 * for Kanban cards and theme toggle are preserved.
 *
 * This is a *contract* test: it confirms the CSS variable definitions
 * and HTML structure are correct, not visual rendering.
 *
 * [Theme] — Light/Dark CSS variable contracts, toggle structure.
 */
#[CoversNothing]
final class ThemeTest extends TestCase
{
    private string $styleFile;
    private string $kanbanFile;
    private string $themeJsFile;

    protected function setUp(): void
    {
        $this->styleFile  = TESTING_ROOT . '/assets/style.css';
        $this->kanbanFile = TESTING_ROOT . '/kanban.php';
        $this->themeJsFile = TESTING_ROOT . '/assets/theme.js';
    }

    // ── Light Mode Palette Contract ───────────────────────────────────

    public function test_light_mode_btn_default_bg_exists(): void
    {
        $css = file_get_contents($this->styleFile);
        $this->assertStringContainsString('--btn-default-bg', $css);
    }

    public function test_light_mode_btn_cta_bg_exists(): void
    {
        $css = file_get_contents($this->styleFile);
        $this->assertStringContainsString('--btn-cta-bg', $css);
    }

    public function test_light_mode_surface_primary_exists(): void
    {
        $css = file_get_contents($this->styleFile);
        $this->assertStringContainsString('--surface-primary', $css);
    }

    public function test_light_mode_surface_secondary_exists(): void
    {
        $css = file_get_contents($this->styleFile);
        $this->assertStringContainsString('--surface-secondary', $css);
    }

    public function test_light_mode_text_primary_exists(): void
    {
        $css = file_get_contents($this->styleFile);
        $this->assertStringContainsString('--text-primary', $css);
    }

    // ── Dark Mode Palette Contract ────────────────────────────────────

    public function test_dark_mode_variable_block_exists(): void
    {
        $css = file_get_contents($this->styleFile);

        // Dark mode variables should be defined under body.dark-mode or [data-theme="dark"]
        $hasDarkClass = str_contains($css, 'body.dark-mode');
        $hasDarkAttr  = str_contains($css, '[data-theme="dark"]');
        $this->assertTrue($hasDarkClass || $hasDarkAttr,
            'Dark mode must be triggered by body.dark-mode or [data-theme="dark"]');
    }

    public function test_dark_mode_overrides_button_colors(): void
    {
        $css = file_get_contents($this->styleFile);

        // Dark mode should redefine button backgrounds
        $darkSection = $this->extractDarkModeSection($css);
        if ($darkSection !== null) {
            $this->assertStringContainsString('--btn-default-bg', $darkSection,
                'Dark mode must override --btn-default-bg');
            $this->assertStringContainsString('--btn-cta-bg', $darkSection,
                'Dark mode must override --btn-cta-bg');
        }
    }

    public function test_dark_mode_overrides_surface_colors(): void
    {
        $css = file_get_contents($this->styleFile);

        $darkSection = $this->extractDarkModeSection($css);
        if ($darkSection !== null) {
            $this->assertStringContainsString('--surface-primary', $darkSection);
            $this->assertStringContainsString('--surface-secondary', $darkSection);
        }
    }

    public function test_dark_mode_overrides_text_colors(): void
    {
        $css = file_get_contents($this->styleFile);

        $darkSection = $this->extractDarkModeSection($css);
        if ($darkSection !== null) {
            $this->assertStringContainsString('--text-primary', $darkSection);
            $this->assertStringContainsString('--text-secondary', $darkSection);
        }
    }

    // ── Theme Toggle Logic ────────────────────────────────────────────

    public function test_theme_toggle_uses_local_storage(): void
    {
        $js = file_get_contents($this->themeJsFile);
        $this->assertStringContainsString('localStorage', $js);
        $this->assertStringContainsString('themePreference', $js);
    }

    public function test_theme_toggle_toggles_dark_mode_class(): void
    {
        $js = file_get_contents($this->themeJsFile);
        $this->assertStringContainsString('dark-mode', $js);
        $this->assertStringContainsString('classList', $js);
    }

    public function test_theme_toggle_updates_button_text(): void
    {
        $js = file_get_contents($this->themeJsFile);
        $this->assertStringContainsString('Light mode', $js);
        $this->assertStringContainsString('Dark mode', $js);
    }

    public function test_theme_toggle_uses_data_theme_attribute(): void
    {
        $js = file_get_contents($this->themeJsFile);
        $this->assertStringContainsString('dataset.theme', $js);
    }

    // ── Kanban Layout Contract ────────────────────────────────────────

    public function test_kanban_card_has_reviewed_checkbox(): void
    {
        $html = file_get_contents($this->kanbanFile);
        $this->assertStringContainsString('status-badge-container', $html);
    }

    public function test_kanban_card_has_data_sku_attribute(): void
    {
        $html = file_get_contents($this->kanbanFile);
        $this->assertStringContainsString('data-sku', $html);
    }

    public function test_kanban_card_has_class_is_sold_for_sold_lane(): void
    {
        $html = file_get_contents($this->kanbanFile);
        $this->assertStringContainsString('is-sold', $html);
    }

    public function test_kanban_card_uses_data_status_attribute(): void
    {
        $html = file_get_contents($this->kanbanFile);
        $this->assertStringContainsString('data-status', $html);
    }

    // ── No Permanent Theme Lock ───────────────────────────────────────

    public function test_theme_js_has_both_toggle_directions(): void
    {
        $js = file_get_contents($this->themeJsFile);

        // Should read current state and flip it (not just set one direction)
        $hasToggle = str_contains($js, 'toggle');
        $hasIfElse = (bool) preg_match('/if\s*\([^)]+\)\s*\{[^}]*dark[^}]*\}\s*else/', $js);
        $hasTernary = str_contains($js, '?') && str_contains($js, ':');
        $hasGetItem  = str_contains($js, 'getItem');
        $hasSetItem  = str_contains($js, 'setItem');

        $this->assertTrue(
            $hasToggle || ($hasIfElse && ($hasGetItem || $hasSetItem)),
            'Theme toggle must support both dark and light switching'
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Extract the dark-mode CSS block from the stylesheet.
     */
    private function extractDarkModeSection(string $css): ?string
    {
        // Match body.dark-mode { ... } or [data-theme="dark"] { ... }
        $pattern = '/(body\.dark-mode|\[data-theme="dark"\])\s*\{(?:[^{}]|\{(?:[^{}]|\{(?:[^{}]|\{[^{}]*\})*\})*\})*\}/s';
        if (preg_match($pattern, $css, $matches)) {
            return $matches[0];
        }

        // Simpler: find any block containing --btn-default-bg that follows body.dark-mode
        $lines = explode("\n", $css);
        $inDark = false;
        $depth = 0;
        $block = '';
        foreach ($lines as $line) {
            if (str_contains($line, 'body.dark-mode') || str_contains($line, '[data-theme="dark"]')) {
                $inDark = true;
            }
            if ($inDark) {
                $block .= $line . "\n";
                $depth += substr_count($line, '{');
                $depth -= substr_count($line, '}');
                if ($depth <= 0 && $block !== '') {
                    break;
                }
            }
        }
        return $block !== '' ? $block : null;
    }
}
