<?php
declare(strict_types=1);

/**
 * ZIP writer helpers for the folder export (export_bundle.php).
 *
 * Entries are described as arrays with exactly one of:
 *   ['name' => 'dir/', 'is_dir' => true]
 *   ['name' => 'file.txt', 'content' => '...']
 *   ['name' => 'photo.jpg', 'path' => '/abs/path/photo.jpg']
 *
 * The zip extension may be missing on minimal server builds, so a pure-PHP
 * (store-only) writer is provided as a fallback. Both writers emit standard
 * ZIP archives that any extractor can open.
 */

/**
 * DOS-format modification time/date pair used by ZIP entry headers.
 */
function bundleDosDateTime(int $timestamp): array
{
    $parts = getdate($timestamp);
    $dosTime = ($parts['hours'] << 11) | ($parts['minutes'] << 5) | intdiv($parts['seconds'], 2);
    $dosDate = ((max($parts['year'], 1980) - 1980) << 9) | ($parts['mon'] << 5) | $parts['mday'];
    return [$dosTime, $dosDate];
}

/**
 * Write collected entries to a ZIP via ZipArchive.
 *
 * @param string $zipPath Destination path for the ZIP file.
 * @param array  $entries List of entry descriptors (see file docblock).
 */
function bundleWriteWithZipArchive(string $zipPath, array $entries): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the ZIP archive.');
    }
    foreach ($entries as $entry) {
        if (!empty($entry['is_dir'])) {
            $zip->addEmptyDir(rtrim($entry['name'], '/'));
        } elseif (array_key_exists('content', $entry)) {
            $zip->addFromString($entry['name'], $entry['content']);
        } else {
            $zip->addFile($entry['path'], $entry['name']);
        }
    }
    $zip->close();
}

/**
 * Pure-PHP ZIP writer (store-only, no compression) used when the zip
 * extension is not installed. Produces a standard, extractable ZIP:
 * local file headers, central directory, and end-of-central-directory
 * record, with CRC-32 computed incrementally so photos stream through
 * without loading whole files into memory.
 *
 * @param string $zipPath Destination path for the ZIP file.
 * @param array  $entries List of entry descriptors (see file docblock).
 */
function bundleWritePureZip(string $zipPath, array $entries): void
{
    $out = @fopen($zipPath, 'wb');
    if ($out === false) {
        throw new RuntimeException('Could not create the ZIP archive.');
    }

    [$dosTime, $dosDate] = bundleDosDateTime(time());

    $central = '';
    $offset = 0;
    $written = 0;

    foreach ($entries as $entry) {
        $name = $entry['name'];
        $isDir = !empty($entry['is_dir']);
        $data = null;
        $source = null;

        if ($isDir) {
            $crc = 0;
            $size = 0;
        } elseif (array_key_exists('content', $entry)) {
            $data = $entry['content'];
            $crc = crc32($data) & 0xFFFFFFFF;
            $size = strlen($data);
        } else {
            $source = $entry['path'];
            $size = (int)@filesize($source);
            $ctx = hash_init('crc32b');
            $fh = @fopen($source, 'rb');
            if ($fh === false) {
                continue; // skip a file that vanished after collection
            }
            while (!feof($fh)) {
                hash_update($ctx, (string)fread($fh, 1048576));
            }
            fclose($fh);
            $crc = (int)hexdec(hash_final($ctx));
        }

        $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0);
        fwrite($out, $localHeader . $name);

        if ($data !== null) {
            fwrite($out, $data);
        } elseif ($source !== null) {
            $fh = @fopen($source, 'rb');
            if ($fh !== false) {
                while (!feof($fh)) {
                    fwrite($out, (string)fread($fh, 1048576));
                }
                fclose($fh);
            }
        }

        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0, 0, 0, 0, $isDir ? 0x10 : 0, $offset) . $name;
        $offset += strlen($localHeader) + strlen($name) + $size;
        $written++;
    }

    // Correct layout: local entries, then the central directory, then the
    // end-of-central-directory record.
    fwrite($out, $central);
    fwrite($out, pack('VvvvvVVv', 0x06054b50, 0, 0, $written, $written, strlen($central), $offset, 0));
    fclose($out);
}
