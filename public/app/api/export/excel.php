<?php
declare(strict_types=1);

require __DIR__ . '/../../../../src/bootstrap.php';
require __DIR__ . '/../../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Download every table as one Excel workbook, one sheet per table (admin only).

api_start();
if (!is_admin()) {
    json_error('Only administrators can export the database.', 403);
}

$db = db();
$spreadsheet = new Spreadsheet();
$spreadsheet->removeSheetByIndex(0);

foreach (['unit_groups', 'units', 'shifts', 'positions', 'tasks', 'employees'] as $table) {
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle($table);
    $columns = array_column($db->query("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field');
    $sheet->fromArray($columns, null, 'A1');
    $sheet->getStyle('1:1')->getFont()->setBold(true);
    $sheet->freezePane('A2');
    $rows = $db->query("SELECT * FROM `$table` ORDER BY id")->fetchAll(PDO::FETCH_NUM);
    if ($rows) {
        $sheet->fromArray($rows, null, 'A2', true); // strict: keep zeros
    }
    foreach ($sheet->getColumnIterator() as $column) {
        $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
    }
}
$spreadsheet->setActiveSheetIndex(0);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="norm-staffing-export-' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');
(new Xlsx($spreadsheet))->save('php://output');
