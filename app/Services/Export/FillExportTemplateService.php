<?php

namespace App\Services\Export;

use App\Models\ExportTemplate;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FillExportTemplateService
{
    public const CELL_PATTERN = '/^[A-Z]+[1-9]\d*$/';

    /**
     * @param  array<string, string>  $cellMap  column key => Excel cell (e.g. name => G7)
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    public function fillAndDownload(
        ExportTemplate $template,
        array $columns,
        array $cellMap,
        array $rows,
        string $downloadName = 'export.xlsx'
    ): StreamedResponse {
        $cellMap = $this->normalizeAndAssertCellMap($columns, $cellMap);

        $absolutePath = Storage::disk(ExportTemplateService::DISK)->path($template->path);
        if (! is_file($absolutePath)) {
            throw new RuntimeException('La plantilla no existe en el almacenamiento.');
        }

        $spreadsheet = IOFactory::load($absolutePath);
        $sheet = $spreadsheet->getActiveSheet();

        $anchors = [];
        foreach ($columns as $column) {
            [$colLetters, $rowIndex] = Coordinate::coordinateFromString($cellMap[$column]);
            $anchors[$column] = [
                'col' => Coordinate::columnIndexFromString($colLetters),
                'row' => (int) $rowIndex,
            ];
        }

        foreach ($rows as $offset => $row) {
            foreach ($columns as $column) {
                $colIndex = $anchors[$column]['col'];
                $rowIndex = $anchors[$column]['row'] + $offset;
                $address = Coordinate::stringFromColumnIndex($colIndex).$rowIndex;
                $value = $row[$column] ?? '';
                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                } elseif (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $text = $value === null ? '' : (string) $value;
                $cell = $sheet->getCell($address);
                if ($text === '' && $cell->isFormula()) {
                    continue;
                }
                $sheet->setCellValue($address, $text);
            }
        }

        return $this->streamXlsx($spreadsheet, $downloadName);
    }

    /**
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    public function csvDownload(
        array $columns,
        array $rows,
        string $downloadName = 'export.csv'
    ): StreamedResponse {
        return response()->streamDownload(function () use ($columns, $rows) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            // UTF-8 BOM for Excel
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns);

            foreach ($rows as $row) {
                $line = [];
                foreach ($columns as $column) {
                    $value = $row[$column] ?? '';
                    if (is_array($value)) {
                        $value = implode(', ', $value);
                    }
                    $line[] = $value === null ? '' : (string) $value;
                }
                fputcsv($handle, $line);
            }

            fclose($handle);
        }, $downloadName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, string>  $cellMap
     * @return array<string, string>
     */
    public function normalizeAndAssertCellMap(array $columns, array $cellMap): array
    {
        $normalized = [];
        foreach ($columns as $column) {
            if (! array_key_exists($column, $cellMap)) {
                throw new RuntimeException("Falta la celda para la columna \"{$column}\".");
            }
            $cell = strtoupper(trim((string) $cellMap[$column]));
            if (! preg_match(self::CELL_PATTERN, $cell)) {
                throw new RuntimeException("Celda inválida \"{$cellMap[$column]}\" para \"{$column}\".");
            }
            $normalized[$column] = $cell;
        }

        return $normalized;
    }

    private function streamXlsx(Spreadsheet $spreadsheet, string $downloadName): StreamedResponse
    {
        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $downloadName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
