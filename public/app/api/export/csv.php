<?php
declare(strict_types=1);

require __DIR__ . '/../../../../src/bootstrap.php';

// Download every table as CSV, bundled in one zip file (admin only).

api_start();
if (!is_admin()) {
    json_error('Only administrators can export the database.', 403);
}

$db = db();
$zipFile = tempnam(sys_get_temp_dir(), 'export');
$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::OVERWRITE) !== true) {
    json_error('Could not create the export file.', 500);
}

foreach (['unit_groups', 'units', 'shifts', 'positions', 'tasks', 'employees'] as $table) {
    $csv = fopen('php://temp', 'w+');
    fwrite($csv, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows non-ASCII characters correctly
    $stmt = $db->query("SELECT * FROM `$table` ORDER BY id");
    $header = false;
    while ($row = $stmt->fetch()) {
        if (!$header) {
            fputcsv($csv, array_keys($row));
            $header = true;
        }
        fputcsv($csv, $row);
    }
    if (!$header) {
        fputcsv($csv, array_column($db->query("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field'));
    }
    rewind($csv);
    $zip->addFromString("$table.csv", stream_get_contents($csv));
    fclose($csv);
}
$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="norm-staffing-export-' . date('Y-m-d') . '.zip"');
header('Content-Length: ' . filesize($zipFile));
readfile($zipFile);
unlink($zipFile);
