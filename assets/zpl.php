<?php
declare(strict_types=1);

/**
 * ZPL label generation for Zebra-compatible printers.
 *
 * Two presets:
 *   - compact (1.5 x 0.5 in) — small SKU sticker
 *   - detail  (2.5 x 1.5 in) — larger intake label
 *
 * Supported barcode types: QR, Code 128, none.
 */

/* ── Label presets ───────────────────────────────────────────── */

function zplPresetCompact(): array
{
    return [
        'name'        => 'SKU sticker',
        'widthIn'     => 1.5,
        'heightIn'    => 0.5,
        'baseWidth'   => 304,
        'baseHeight'  => 102,
        'defaultFont' => 42,
        'defaultCode' => 'qr',
        'defaultDetails' => false,
    ];
}

function zplPresetDetail(): array
{
    return [
        'name'        => 'Intake label',
        'widthIn'     => 2.5,
        'heightIn'    => 1.5,
        'baseWidth'   => 508,
        'baseHeight'  => 305,
        'defaultFont' => 52,
        'defaultCode' => 'qr',
        'defaultDetails' => true,
    ];
}

function zplPreset(string $name): array
{
    return $name === 'detail' ? zplPresetDetail() : zplPresetCompact();
}

/* ── DPI-aware configuration ─────────────────────────────────── */

function zplLabelConfig(array $preset, int $dpi): array
{
    $scale = $dpi === 300 ? 300.0 / 203.0 : 1.0;
    return [
        'width'  => $dpi === 300 ? (int)round($preset['widthIn'] * 300) : $preset['baseWidth'],
        'height' => $dpi === 300 ? (int)round($preset['heightIn'] * 300) : $preset['baseHeight'],
        'scale'  => $scale,
    ];
}

/* ── Coordinate helper (dots, rounded) ───────────────────────── */

function zplX(float $value, array $config): int
{
    return (int)round($value * $config['scale']);
}

/* ── String sanitisation ─────────────────────────────────────── */

function sanitizeZpl(string $value): string
{
    $value = preg_replace('/[\^~\\\\]/', '', $value);
    $value = preg_replace('/[\x00-\x1f\x7f]/', ' ', $value);
    $value = preg_replace('/[^\x20-\x7E]/', '?', $value);
    return trim($value);
}

function sanitizeBarcode(string $value): string
{
    $clean = sanitizeZpl($value);
    $clean = preg_replace('/[^A-Za-z0-9.\/_-]/', '', $clean);
    $clean = function_exists('mb_substr') ? mb_substr($clean, 0, 20, 'UTF-8') : substr($clean, 0, 20);
    return $clean !== '' ? $clean : 'NO-SKU';
}

function truncateZpl(string $value, int $maxLength): string
{
    $len = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($len <= $maxLength) {
        return $value;
    }
    $truncated = function_exists('mb_substr')
        ? mb_substr($value, 0, max(0, $maxLength - 3), 'UTF-8')
        : substr($value, 0, max(0, $maxLength - 3));
    return $truncated . '...';
}

/* ── Date formatting ─────────────────────────────────────────── */

function formatZplDate(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $parts = explode('-', $value);
    if (count($parts) === 3 && $parts[0] !== '' && $parts[1] !== '' && $parts[2] !== '') {
        return $parts[1] . '/' . $parts[2] . '/' . substr($parts[0], -2);
    }
    return $value;
}

/* ── Main ZPL generation ─────────────────────────────────────── */

/**
 * Generate a ZPL string for a label.
 *
 * @param array  $item   {
 *     Key-value item data:
 *       sku         (string)      – SKU text
 *       itemName    (string)      – item / product name
 *       description (string|null) – short description
 *       date        (string|null) – YYYY-MM-DD date
 *       labelPreset (string)      – "compact" or "detail"
 *       dpi         (int)         – 203 or 300
 *       skuFontSize (int)         – SKU font size in dots
 *       codeType    (string)      – "qr", "code128", or "none"
 *       showDetails (bool)        – show item name / meta
 * }
 * @return string Raw ZPL (^XA … ^XZ)
 */
function generateLabelZpl(array $item): string
{
    $preset     = zplPreset($item['labelPreset'] ?? 'compact');
    $dpi        = (int)($item['dpi'] ?? 203);
    $config     = zplLabelConfig($preset, $dpi);
    $fontSize   = max(24, min(64, (int)($item['skuFontSize'] ?? $preset['defaultFont'])));
    $codeType   = $item['codeType'] ?? $preset['defaultCode'];
    $showDetail = !empty($item['showDetails']);

    /* Sanitise text fields */
    $safeSku  = truncateZpl(sanitizeZpl($item['sku'] ?? 'NO-SKU'), 24);
    $safeName = truncateZpl(sanitizeZpl($item['itemName'] ?? 'Unnamed item'), 44);

    $metaParts = array_filter([
        ($item['description'] ?? null),
        formatZplDate($item['date'] ?? null),
    ], function ($v) { return $v !== null && $v !== ''; });
    $safeMeta = truncateZpl(sanitizeZpl(implode(' | ', $metaParts)), 58);

    $encodedSku = sanitizeBarcode($item['sku'] ?? 'NO-SKU');

    $lines = [];
    $lines[] = '^XA';
    $lines[] = sprintf('^PW%d', $config['width']);
    $lines[] = sprintf('^LL%d', $config['height']);
    $lines[] = '^LH0,0';

    if ($preset['name'] === 'SKU sticker') {
        $textWidth      = $codeType === 'qr' ? zplX(202, $config) : $config['width'] - zplX(18, $config);
        $textAreaHeight = $codeType === 'code128' ? 66 : $preset['baseHeight'];
        $skuY           = $showDetail
            ? zplX(8, $config)
            : max(zplX(4, $config), (int)round(($textAreaHeight - $fontSize) / 2) + zplX(8, $config));

        $lines[] = sprintf('^CF0,%d', zplX($fontSize, $config));
        $lines[] = sprintf('^FO%d,%d^FB%d,2,%d,L,0^FD%s^FS',
            zplX(9, $config), $skuY, $textWidth, zplX(1, $config), $safeSku);

        if ($showDetail) {
            $detailY = $codeType === 'code128' ? zplX(54, $config) : zplX(72, $config);
            $lines[] = sprintf('^CF0,%d', zplX(12, $config));
            $lines[] = sprintf('^FO%d,%d^FB%d,1,0,L,0^FD%s^FS',
                zplX(10, $config), $detailY, $textWidth, truncateZpl($safeName, 28));
        }
    } else {
        $textWidth      = $codeType === 'qr' ? zplX(340, $config) : $config['width'] - zplX(32, $config);
        $textAreaHeight = $codeType === 'code128' ? 220 : $preset['baseHeight'];
        $skuY           = $showDetail
            ? zplX(28, $config)
            : max(zplX(12, $config), (int)round(($textAreaHeight - $fontSize) / 2) + zplX(10, $config));

        $lines[] = sprintf('^CF0,%d', zplX($fontSize, $config));
        $lines[] = sprintf('^FO%d,%d^FB%d,1,0,L,0^FD%s^FS',
            zplX(18, $config), $skuY, $textWidth, $safeSku);

        if ($showDetail) {
            $lines[] = sprintf('^CF0,%d', zplX(29, $config));
            $lines[] = sprintf('^FO%d,%d^FB%d,2,%d,L,0^FD%s^FS',
                zplX(18, $config), zplX(105, $config), $textWidth, zplX(4, $config), $safeName);

            if ($safeMeta !== '') {
                $metaY    = $codeType === 'code128' ? zplX(180, $config) : zplX(205, $config);
                $metaLines = $codeType === 'code128' ? 2 : 3;
                $lines[] = sprintf('^CF0,%d', zplX(22, $config));
                $lines[] = sprintf('^FO%d,%d^FB%d,%d,%d,L,0^FD%s^FS',
                    zplX(18, $config), $metaY, $textWidth, $metaLines, zplX(3, $config), $safeMeta);
            }
        }
    }

    /* ── Barcode block ── */
    if ($codeType === 'qr') {
        $qrX = $preset['name'] === 'SKU sticker' ? zplX(218, $config) : zplX(365, $config);
        $qrY = $preset['name'] === 'SKU sticker' ? zplX(7, $config) : zplX(55, $config);
        $magnification = $dpi === 300
            ? ($preset['name'] === 'SKU sticker' ? 4 : 8)
            : ($preset['name'] === 'SKU sticker' ? 3 : 5);
        $lines[] = sprintf('^FO%d,%d^BQN,2,%d^FDLA,%s^FS', $qrX, $qrY, $magnification, $encodedSku);
    } elseif ($codeType === 'code128') {
        $barcodeY     = $preset['name'] === 'SKU sticker' ? zplX(70, $config) : zplX(242, $config);
        $barcodeHeight = $preset['name'] === 'SKU sticker' ? zplX(18, $config) : zplX(45, $config);
        $lines[] = sprintf('^FO%d,%d^BY%d,2,%d',
            zplX(12, $config), $barcodeY, max(1, zplX(1, $config)), $barcodeHeight);
        $lines[] = sprintf('^BCN,%d,Y,N,N^FD%s^FS', $barcodeHeight, $encodedSku);
    }

    $lines[] = '^PQ1,0,1,Y';
    $lines[] = '^XZ';

    return implode("\r\n", $lines);
}
