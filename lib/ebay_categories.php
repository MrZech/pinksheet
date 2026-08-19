<?php
declare(strict_types=1);

/**
 * eBay category catalog — bundled fallback + live snapshot loader.
 *
 * The intake form's typeable "eBay Category" combobox reads from
 * ebay_categories.php, which returns the live snapshot
 * (data/ebay_categories.json) when present and otherwise falls back to
 * the bundled list below.
 *
 * Refresh the snapshot from eBay's live Taxonomy API with:
 *
 *     php scripts/refresh_ebay_categories.php
 *
 * Each entry carries:
 *   - id   : eBay leaf category id. Several ids below are the real ids
 *            Dispo Tech already used in production (recovered from the
 *            legacy inventory export's ebay_category_id column). Where we
 *            do not have a trusted id, it is left empty and the live
 *            refresh script fills it in.
 *   - name : leaf category name shown in the dropdown
 *   - path : breadcrumb path from the top-level category to the leaf
 */

function ebayCategorySnapshotPath(): string
{
    return dirname(__DIR__) . '/data/ebay_categories.json';
}

/**
 * Bundled electronics + Dispo Tech-specific category list.
 *
 * @return list<array{id: string, name: string, path: string}>
 */
function ebayCategoryBundled(): array
{
    return [
        // ── Computers/Tablets & Networking ────────────────────────────────
        ['id' => '177', 'name' => 'PC Laptops & Netbooks', 'path' => 'Computers/Tablets & Networking > Laptops & Netbooks > PC Laptops & Netbooks'],
        ['id' => '111422', 'name' => 'Apple Laptops', 'path' => 'Computers/Tablets & Networking > Laptops & Netbooks > Apple Laptops'],
        ['id' => '', 'name' => 'Apple iPads', 'path' => 'Computers/Tablets & Networking > Tablets & eBook Readers > Apple iPads'],
        ['id' => '', 'name' => 'Android Tablets', 'path' => 'Computers/Tablets & Networking > Tablets & eBook Readers > Android Tablets'],
        ['id' => '', 'name' => 'Windows Tablets', 'path' => 'Computers/Tablets & Networking > Tablets & eBook Readers > Windows Tablets'],
        ['id' => '', 'name' => 'eBook Readers', 'path' => 'Computers/Tablets & Networking > Tablets & eBook Readers > eBook Readers'],
        ['id' => '179', 'name' => 'PC Desktops & All-in-Ones', 'path' => 'Computers/Tablets & Networking > Desktop & All-in-One PCs > PC Desktops & All-in-Ones'],
        ['id' => '111418', 'name' => 'Apple Desktops & All-in-Ones', 'path' => 'Computers/Tablets & Networking > Desktop & All-in-One PCs > Apple Desktops & All-in-Ones'],
        ['id' => '164', 'name' => 'CPUs/Processors', 'path' => 'Computers/Tablets & Networking > Computer Components & Parts > CPUs/Processors'],
        ['id' => '27386', 'name' => 'Graphics/Video Cards', 'path' => 'Computers/Tablets & Networking > Computer Components & Parts > Graphics/Video Cards'],
        ['id' => '170083', 'name' => 'Memory (RAM)', 'path' => 'Computers/Tablets & Networking > Computer Components & Parts > Memory (RAM)'],
        ['id' => '11210', 'name' => 'Server Memory (RAM)', 'path' => 'Computers/Tablets & Networking > Computer Components & Parts > Server Memory (RAM)'],
        ['id' => '', 'name' => 'Motherboards', 'path' => 'Computers/Tablets & Networking > Computer Components & Parts > Motherboards'],
        ['id' => '', 'name' => 'Power Supplies', 'path' => 'Computers/Tablets & Networking > Computer Components & Parts > Power Supplies'],
        ['id' => '', 'name' => 'Sound Cards', 'path' => 'Computers/Tablets & Networking > Computer Components & Parts > Sound Cards'],
        ['id' => '175669', 'name' => 'Internal Solid State Drives (SSD)', 'path' => 'Computers/Tablets & Networking > Drives, Storage & Blank Media > Internal Solid State Drives'],
        ['id' => '175670', 'name' => 'Internal Hard Disk Drives', 'path' => 'Computers/Tablets & Networking > Drives, Storage & Blank Media > Internal Hard Disk Drives'],
        ['id' => '56091', 'name' => 'RAID Controllers & Cards', 'path' => 'Computers/Tablets & Networking > Computer Components & Parts > RAID Controllers & Cards'],
        ['id' => '158816', 'name' => 'Hard Drive Trays & Caddies', 'path' => 'Computers/Tablets & Networking > Drives, Storage & Blank Media > Hard Drive Trays & Caddies'],
        ['id' => '', 'name' => 'Computer Cases', 'path' => 'Computers/Tablets & Networking > Computer Components & Parts > Computer Cases'],
        ['id' => '', 'name' => 'Monitors', 'path' => 'Computers/Tablets & Networking > Monitors, Projectors & Accs > Monitors'],
        ['id' => '', 'name' => 'Projectors', 'path' => 'Computers/Tablets & Networking > Monitors, Projectors & Accs > Projectors'],
        ['id' => '', 'name' => 'External Hard Drives', 'path' => 'Computers/Tablets & Networking > Drives, Storage & Blank Media > External Hard Drives'],
        ['id' => '', 'name' => 'USB Flash Drives', 'path' => 'Computers/Tablets & Networking > Drives, Storage & Blank Media > USB Flash Drives'],
        ['id' => '', 'name' => 'Memory Cards', 'path' => 'Computers/Tablets & Networking > Drives, Storage & Blank Media > Memory Cards'],
        ['id' => '', 'name' => 'Wireless Routers', 'path' => 'Computers/Tablets & Networking > Home Networking & Connectivity > Wireless Routers'],
        ['id' => '', 'name' => 'Modem-Router Combos', 'path' => 'Computers/Tablets & Networking > Home Networking & Connectivity > Modem-Router Combos'],
        ['id' => '51268', 'name' => 'Network Switches', 'path' => 'Computers/Tablets & Networking > Home Networking & Connectivity > Network Switches'],
        ['id' => '', 'name' => 'Wireless Access Points', 'path' => 'Computers/Tablets & Networking > Home Networking & Connectivity > Wireless Access Points'],
        ['id' => '', 'name' => 'Range Extenders & Boosters', 'path' => 'Computers/Tablets & Networking > Home Networking & Connectivity > Range Extenders & Boosters'],
        ['id' => '44992', 'name' => 'Patch Panels', 'path' => 'Computers/Tablets & Networking > Enterprise Networking, Servers > Patch Panels'],
        ['id' => '', 'name' => 'Servers', 'path' => 'Computers/Tablets & Networking > Enterprise Networking, Servers > Servers'],
        ['id' => '', 'name' => 'Network Attached Storage (NAS)', 'path' => 'Computers/Tablets & Networking > Enterprise Networking, Servers > Network Attached Storage (NAS)'],
        ['id' => '51199', 'name' => 'Server Rack Rails & Brackets', 'path' => 'Computers/Tablets & Networking > Enterprise Networking, Servers > Server Rack Rails & Brackets'],
        ['id' => '', 'name' => 'Keyboards', 'path' => 'Computers/Tablets & Networking > Keyboards, Mice & Pointers > Keyboards'],
        ['id' => '', 'name' => 'Mice', 'path' => 'Computers/Tablets & Networking > Keyboards, Mice & Pointers > Mice'],
        ['id' => '', 'name' => 'Webcams', 'path' => 'Computers/Tablets & Networking > Laptop & Desktop Accessories > Webcams'],
        ['id' => '', 'name' => 'Docking Stations', 'path' => 'Computers/Tablets & Networking > Laptop & Desktop Accessories > Docking Stations'],
        ['id' => '', 'name' => 'Laptop Chargers & Adapters', 'path' => 'Computers/Tablets & Networking > Laptop & Desktop Accessories > Laptop Chargers & Adapters'],
        ['id' => '', 'name' => 'Laptop Bags & Cases', 'path' => 'Computers/Tablets & Networking > Laptop & Desktop Accessories > Laptop Bags & Cases'],
        ['id' => '', 'name' => 'Printers', 'path' => 'Computers/Tablets & Networking > Printers, Scanners & Supplies > Printers'],
        ['id' => '11197', 'name' => 'Printer Feeders & Parts', 'path' => 'Computers/Tablets & Networking > Printers, Scanners & Supplies > Printer Parts & Accessories'],
        ['id' => '', 'name' => 'Scanners', 'path' => 'Computers/Tablets & Networking > Printers, Scanners & Supplies > Scanners'],
        ['id' => '', 'name' => '3D Printers & Supplies', 'path' => 'Computers/Tablets & Networking > Printers, Scanners & Supplies > 3D Printers & Supplies'],
        ['id' => '', 'name' => 'Operating Systems', 'path' => 'Computers/Tablets & Networking > Software > Operating Systems'],

        // ── Consumer Electronics ──────────────────────────────────────────
        ['id' => '', 'name' => 'Digital Cameras', 'path' => 'Consumer Electronics > Cameras & Photo > Digital Cameras'],
        ['id' => '', 'name' => 'DSLR Cameras', 'path' => 'Consumer Electronics > Cameras & Photo > DSLR Cameras'],
        ['id' => '', 'name' => 'Mirrorless Cameras', 'path' => 'Consumer Electronics > Cameras & Photo > Mirrorless Cameras'],
        ['id' => '', 'name' => 'Camera Lenses', 'path' => 'Consumer Electronics > Cameras & Photo > Lenses'],
        ['id' => '', 'name' => 'Camcorders', 'path' => 'Consumer Electronics > Cameras & Photo > Camcorders'],
        ['id' => '', 'name' => 'Camera Drones', 'path' => 'Consumer Electronics > Cameras & Photo > Drones'],
        ['id' => '', 'name' => 'TVs', 'path' => 'Consumer Electronics > TV, Video & Home Audio > TVs'],
        ['id' => '', 'name' => 'Home Theater Systems', 'path' => 'Consumer Electronics > TV, Video & Home Audio > Home Theater Systems'],
        ['id' => '', 'name' => 'Sound Bars', 'path' => 'Consumer Electronics > TV, Video & Home Audio > Sound Bars'],
        ['id' => '', 'name' => 'Streaming Media Players', 'path' => 'Consumer Electronics > TV, Video & Home Audio > Streaming Media Players'],
        ['id' => '', 'name' => 'Blu-ray & DVD Players', 'path' => 'Consumer Electronics > TV, Video & Home Audio > Blu-ray & DVD Players'],
        ['id' => '', 'name' => 'Headphones', 'path' => 'Consumer Electronics > TV, Video & Home Audio > Headphones'],
        ['id' => '', 'name' => 'Speakers', 'path' => 'Consumer Electronics > TV, Video & Home Audio > Speakers'],
        ['id' => '', 'name' => 'Receivers & Amplifiers', 'path' => 'Consumer Electronics > TV, Video & Home Audio > Receivers & Amplifiers'],
        ['id' => '48656', 'name' => 'TV Wall & Ceiling Mounts', 'path' => 'Consumer Electronics > TV, Video & Home Audio > TV Wall & Ceiling Mounts'],
        ['id' => '', 'name' => 'MP3 Players', 'path' => 'Consumer Electronics > Portable Audio & Headphones > MP3 Players'],
        ['id' => '', 'name' => 'Smart Speakers & Displays', 'path' => 'Consumer Electronics > Smart Home > Smart Speakers & Displays'],
        ['id' => '', 'name' => 'Smart Home Hubs & Controllers', 'path' => 'Consumer Electronics > Smart Home > Smart Home Hubs & Controllers'],
        ['id' => '', 'name' => 'Smart Cameras & Doorbells', 'path' => 'Consumer Electronics > Smart Home > Smart Cameras & Doorbells'],

        // ── Cell Phones, Smart Watches & Accessories ──────────────────────
        ['id' => '9355', 'name' => 'Cell Phones & Smartphones', 'path' => 'Cell Phones, Smart Watches & Accessories > Cell Phones & Smartphones'],
        ['id' => '43304', 'name' => 'Samsung Cell Phones & Smartphones', 'path' => 'Cell Phones, Smart Watches & Accessories > Cell Phones & Smartphones > Samsung Cell Phones & Smartphones'],
        ['id' => '', 'name' => 'Smart Watches', 'path' => 'Cell Phones, Smart Watches & Accessories > Smart Watches'],
        ['id' => '', 'name' => 'Phone Cases, Covers & Skins', 'path' => 'Cell Phones, Smart Watches & Accessories > Cell Phone Accessories > Cases, Covers & Skins'],
        ['id' => '', 'name' => 'Phone Chargers & Cradles', 'path' => 'Cell Phones, Smart Watches & Accessories > Cell Phone Accessories > Chargers & Cradles'],
        ['id' => '', 'name' => 'Screen Protectors', 'path' => 'Cell Phones, Smart Watches & Accessories > Cell Phone Accessories > Screen Protectors'],

        // ── Video Games & Consoles ────────────────────────────────────────
        ['id' => '', 'name' => 'Video Game Consoles', 'path' => 'Video Games & Consoles > Video Game Consoles'],
        ['id' => '', 'name' => 'Portable Game Systems', 'path' => 'Video Games & Consoles > Portable Game Systems'],

        // ── Point of Sale (POS) & Office Equipment ────────────────────────
        ['id' => '317', 'name' => 'Cash Drawers', 'path' => 'Business & Industrial > Retail & Services > Point of Sale (POS) Equipment > Cash Drawers'],
        ['id' => '46712', 'name' => 'Receipt Printers', 'path' => 'Business & Industrial > Retail & Services > Point of Sale (POS) Equipment > Receipt Printers'],
        ['id' => '71474', 'name' => 'POS Workstations & Stands', 'path' => 'Business & Industrial > Retail & Services > Point of Sale (POS) Equipment > Workstations & Stands'],
        ['id' => '184274', 'name' => 'Label Printers', 'path' => 'Business & Industrial > Office > Label Printers'],

        // ── Test, Measurement & Electrical ────────────────────────────────
        ['id' => '63942', 'name' => 'Rotary Laser Levels', 'path' => 'Business & Industrial > Test, Measurement & Inspection > Rotary Laser Levels'],
        ['id' => '181975', 'name' => 'Cable Locators & Test Equipment', 'path' => 'Business & Industrial > Test, Measurement & Inspection > Cable Locators & Test Equipment'],
        ['id' => '181725', 'name' => 'Process Controllers (PLC)', 'path' => 'Business & Industrial > Automation, Motors & Drives > PLCs & Process Controllers'],
        ['id' => '260823', 'name' => 'Fuses', 'path' => 'Business & Industrial > Electrical Equipment & Supplies > Fuses'],
        ['id' => '3300', 'name' => 'Extension Cords & Cables', 'path' => 'Business & Industrial > Electrical Equipment & Supplies > Extension Cords & Cables'],
    ];
}

/**
 * Normalise and de-duplicate a category list, keeping only valid entries.
 *
 * @param array<int, mixed> $list
 * @return list<array{id: string, name: string, path: string}>
 */
function ebayCategoryNormalizeList(array $list): array
{
    $out = [];
    $seen = [];
    foreach ($list as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $name = trim((string)($entry['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $path = trim((string)($entry['path'] ?? ''));
        $id = trim((string)($entry['id'] ?? ''));
        // De-duplicate by path when present, else by leaf name.
        $key = strtolower($path !== '' ? $path : $name);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = ['id' => $id, 'name' => $name, 'path' => $path];
    }
    return $out;
}

/**
 * Load the category list: live snapshot when valid, bundled fallback otherwise.
 *
 * @return array{categories: list<array{id: string, name: string, path: string}>, generated_at: string, source: string}
 */
function ebayCategoryList(): array
{
    $snapshot = ebayCategorySnapshotPath();
    if (is_file($snapshot) && is_readable($snapshot)) {
        $raw = @file_get_contents($snapshot);
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && is_array($decoded['categories'] ?? null)) {
                $categories = ebayCategoryNormalizeList($decoded['categories']);
                if ($categories !== []) {
                    return [
                        'categories' => $categories,
                        'generated_at' => (string)($decoded['generated_at'] ?? ''),
                        'source' => (string)($decoded['source'] ?? 'snapshot'),
                    ];
                }
            }
        }
    }

    return [
        'categories' => ebayCategoryNormalizeList(ebayCategoryBundled()),
        'generated_at' => '',
        'source' => 'bundled',
    ];
}
