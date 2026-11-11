<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

class ContactSpreadsheet
{
    private const Headers = ['name', 'phone', 'email', 'opted_in'];

    public function read(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'xlsx'], true)) {
            throw ValidationException::withMessages(['file' => 'contacts.invalid_file']);
        }

        $rows = $extension === 'csv' ? $this->readCsv($file->getRealPath()) : $this->readXlsx($file->getRealPath());
        $headers = array_map(fn ($value) => strtolower(trim((string) $value)), array_shift($rows) ?? []);
        if ($headers !== self::Headers) {
            throw ValidationException::withMessages(['file' => 'contacts.invalid_headers']);
        }

        $result = [];
        $phones = [];
        foreach ($rows as $row) {
            if (count($row) !== 4) {
                throw ValidationException::withMessages(['file' => 'contacts.invalid_rows']);
            }
            $values = array_map(fn ($value) => trim((string) $value), $row);
            if ($values === ['', '', '', '']) {
                continue;
            }
            if ($extension === 'csv') {
                foreach ($values as &$value) {
                    if (preg_match("/^'(?:\\s*[=+\\-@]|[\\t\\r])/", $value)) {
                        $value = substr($value, 1);
                    }
                }
                unset($value);
            }
            [$name, $phone, $email, $optedIn] = $values;
            $validator = Validator::make(compact('name', 'phone', 'email', 'optedIn'), [
                'name' => ['required', 'string', 'max:255'],
                'phone' => ['required', 'regex:/^\+[1-9][0-9]{6,14}$/'],
                'email' => ['nullable', 'email', 'max:255'],
                'optedIn' => ['required', 'in:0,1'],
            ]);
            if ($validator->fails() || isset($phones[$phone])) {
                throw ValidationException::withMessages(['file' => 'contacts.invalid_rows']);
            }
            $phones[$phone] = true;
            $result[] = ['name' => $name, 'phone' => $phone, 'email' => $email ?: null, 'opted_in' => $optedIn === '1'];
        }

        return $result;
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'contacts.invalid_file']);
        }
        $rows = [];
        try {
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $rows[] = $row;
                if (count($rows) > 5001) {
                    throw ValidationException::withMessages(['file' => 'contacts.too_many_rows']);
                }
            }
        } finally {
            fclose($handle);
        }
        if (isset($rows[0][0])) {
            $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]);
        }

        return $rows;
    }

    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'contacts.invalid_file']);
        }
        try {
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $size += $zip->statIndex($i)['size'];
                if ($size > 20 * 1024 * 1024 || $zip->numFiles > 1000) {
                    throw ValidationException::withMessages(['file' => 'contacts.invalid_file']);
                }
            }
        } finally {
            $zip->close();
        }

        try {
            $reader = IOFactory::createReader('Xlsx');
            $info = $reader->listWorksheetInfo($path);
            if (count($info) !== 1 || $info[0]['totalRows'] > 5001 || $info[0]['totalColumns'] > 4) {
                throw ValidationException::withMessages(['file' => 'contacts.too_many_rows']);
            }
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
            try {
                $sheet = $spreadsheet->getActiveSheet();
                $rows = [];
                for ($row = 1; $row <= $sheet->getHighestDataRow(); $row++) {
                    $values = [];
                    foreach (['A', 'B', 'C', 'D'] as $column) {
                        $cell = $sheet->getCell($column.$row);
                        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                            throw ValidationException::withMessages(['file' => 'contacts.invalid_rows']);
                        }
                        $values[] = $cell->getValue();
                    }
                    $rows[] = $values;
                }

                return $rows;
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        } catch (ReaderException $exception) {
            report($exception);
            throw ValidationException::withMessages(['file' => 'contacts.invalid_file']);
        }
    }

    public function write(iterable $contacts, string $format): void
    {
        if ($format === 'csv') {
            $output = fopen('php://output', 'wb');
            fputcsv($output, self::Headers, ',', '"', '');
            foreach ($contacts as $contact) {
                $values = [$contact->name, $contact->phone, $contact->email ?? '', $contact->opted_in ? '1' : '0'];
                $values = array_map(fn ($value) => preg_match('/^(?:\s*[=+\-@]|[\t\r])/', $value) ? "'".$value : $value, $values);
                fputcsv($output, $values, ',', '"', '');
            }
            fclose($output);

            return;
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach (self::Headers as $index => $value) {
            $sheet->setCellValueExplicit(chr(65 + $index).'1', $value, DataType::TYPE_STRING);
        }
        $rows = 2;
        foreach ($contacts as $contact) {
            $values = [$contact->name, $contact->phone, $contact->email ?? '', $contact->opted_in ? '1' : '0'];
            foreach ($values as $index => $value) {
                $sheet->setCellValueExplicit(chr(65 + $index).$rows, $value, DataType::TYPE_STRING);
            }
            $rows++;
        }
        try {
            (new Xlsx($spreadsheet))->save('php://output');
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }
}
