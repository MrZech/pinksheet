<?php
/**
 * convert_ebay_images_to_png.php — Migration script
 *
 * Scans data/ebay_images/ for non-PNG images and converts them to PNG
 * using GD. Removes the original file after successful conversion.
 *
 * Also skips the UNASSIGNED directory if present.
 *
 * Run: php scripts/convert_ebay_images_to_png.php
 *
 * If you also want to convert existing sku_photos to PNG, see the
 * instructions in the docblock below (requires DB changes).
 *
 * ════════════════════════════════════════════════════════════════════
 * CONVERTING EXISTING sku_photos TO PNG
 * ════════════════════════════════════════════════════════════════════
 * To convert existing intake photos (sku_photos) as well:
 *
 *   1. Back up your database: cp data/intake.sqlite data/intake.sqlite.bak
 *   2. Run this script with --sku-photos flag:
 *         php scripts/convert_ebay_images_to_png.php --sku-photos
 *   3. This will:
 *      a. Scan data/sku_photos/ for non-PNG images
 *      b. Convert each to PNG
 *      c. Update the stored_name in the sku_photos table
 *      d. Remove the original file
 *
 * WARNING: sku_photos conversion modifies the database. Always backup first.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$convertSkuPhotos = in_array('--sku-photos', $argv ?? [], true);
$converted = 0;
$failed    = 0;

echo "eBay Listing Image PNG Converter\n";
echo str_repeat('=', 50) . "\n\n";

/* ─── Helper: Convert a single image to PNG ─────────────────── */
function convertToPng(string $srcPath): ?string
{
    $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
    $gd  = null;

    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            $gd = @imagecreatefromjpeg($srcPath);
            break;
        case 'png':
            return $srcPath; // already PNG
        case 'webp':
            $gd = @imagecreatefromwebp($srcPath);
            break;
        case 'gif':
            $gd = @imagecreatefromgif($srcPath);
            break;
        default:
            return null; // unsupported
    }

    if (!$gd) return null;

    imagealphablending($gd, false);
    imagesavealpha($gd, true);

    $dir  = dirname($srcPath);
    $base = pathinfo($srcPath, PATHINFO_FILENAME);
    $pngPath = $dir . '/' . $base . '.png';

    // Avoid overwriting existing converted file
    $n = 1;
    while (is_file($pngPath)) {
        $pngPath = $dir . '/' . $base . '_v' . $n . '.png';
        $n++;
    }

    $ok = imagepng($gd, $pngPath);
    imagedestroy($gd);

    return $ok ? $pngPath : null;
}

/* ─── Process ebay_images directory ──────────────────────────── */
$ebayDir = __DIR__ . '/../data/ebay_images';
if (is_dir($ebayDir)) {
    echo "Scanning: $ebayDir\n";
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($ebayDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($items as $path => $info) {
        if (!$info->isFile()) continue;
        $ext = strtolower($info->getExtension());
        if ($ext === 'png') continue;
        if (!in_array($ext, ['jpg', 'jpeg', 'webp', 'gif'], true)) continue;

        $src = $info->getRealPath();
        echo "  Converting: $src ... ";

        $pngPath = convertToPng($src);
        if ($pngPath === null) {
            echo "FAILED\n";
            error_log('convert_ebay_images_to_png: failed to convert ' . $src);
            $failed++;
            continue;
        }

        // Remove original
        @unlink($src);
        echo "OK -> " . basename($pngPath) . "\n";
        $converted++;
    }
} else {
    echo "Directory not found: $ebayDir (skipping)\n";
}

/* ─── Process sku_photos directory (optional) ────────────────── */
if ($convertSkuPhotos) {
    $skuDir = __DIR__ . '/../data/sku_photos';
    if (is_dir($skuDir)) {
        echo "\nScanning: $skuDir\n";
        $pdo = null;
        try {
            $pdo = new PDO('sqlite:' . __DIR__ . '/../data/intake.sqlite', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (Throwable $e) {
            echo "  WARNING: Could not open database. Skipping DB updates.\n";
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($skuDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $path => $info) {
            if (!$info->isFile()) continue;
            $ext = strtolower($info->getExtension());
            if ($ext === 'png') continue;
            if (!in_array($ext, ['jpg', 'jpeg', 'webp', 'gif'], true)) continue;

            $src = $info->getRealPath();
            $oldName = $info->getFilename();
            echo "  Converting: $oldName ... ";

            $pngPath = convertToPng($src);
            if ($pngPath === null) {
                echo "FAILED\n";
                error_log('convert_ebay_images_to_png: failed to convert ' . $src);
                $failed++;
                continue;
            }

            $newName = basename($pngPath);

            // Update DB record
            if ($pdo) {
                try {
                    $stmt = $pdo->prepare("UPDATE sku_photos SET stored_name = :new WHERE stored_name = :old");
                    $stmt->execute(['new' => $newName, 'old' => $oldName]);
                    if ($stmt->rowCount() === 0) {
                        // Maybe the file isn't in DB (orphan)
                        echo "OK (not in DB) -> $newName\n";
                    } else {
                        echo "OK (DB updated) -> $newName\n";
                    }
                } catch (Throwable $e) {
                    echo "CONVERTED but DB update failed: " . $e->getMessage() . "\n";
                    error_log('convert_ebay_images_to_png: DB update failed for ' . $oldName . ': ' . $e->getMessage());
                }
            } else {
                echo "OK (no DB) -> $newName\n";
            }

            @unlink($src);
            $converted++;
        }
    } else {
        echo "\nDirectory not found: $skuDir (skipping)\n";
    }
}

/* ─── Summary ─────────────────────────────────────────────────── */
echo "\n" . str_repeat('=', 50) . "\n";
echo "Done. Converted: $converted | Failed: $failed\n";
