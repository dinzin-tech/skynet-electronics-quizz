<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class QuestionImportService
{
    private PDO $db;
    public const MAX_FILE_SIZE = 10485760; // 10 MB
    public const MAX_ROWS = 500; // Easily accommodates 40+ questions

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Import questions from CSV or XLSX into a Question Bank.
     *
     * @return array{total_rows: int, imported_count: int, errors: string[]}
     */
    public function importToBank(int $bankId, string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("File not found: {$filePath}");
        }

        if (filesize($filePath) > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('File size exceeds the 10 MB limit');
        }

        // Verify bank exists
        $bStmt = $this->db->prepare('SELECT id, title FROM question_banks WHERE id = :id');
        $bStmt->execute(['id' => $bankId]);
        $bank = $bStmt->fetch(PDO::FETCH_ASSOC);

        if (!$bank) {
            throw new InvalidArgumentException("Question Bank ID {$bankId} not found");
        }

        // Check if bank is locked
        $qService = new QuestionService($this->db);
        $qService->assertBankNotLocked($bankId);

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'xlsx') {
            $rows = $this->parseXlsx($filePath);
        } else {
            $rows = $this->parseCsv($filePath);
        }

        if (empty($rows)) {
            throw new InvalidArgumentException('The uploaded file does not contain any question data');
        }

        $header = array_shift($rows);
        $colMap = $this->resolveHeaderColumns($header);

        $importedCount = 0;
        $errors = [];
        $validQuestions = [];

        // Determine current max display order in this bank
        $maxOrderStmt = $this->db->prepare('SELECT COALESCE(MAX(display_order), 0) FROM questions WHERE question_bank_id = :bid');
        $maxOrderStmt->execute(['bid' => $bankId]);
        $currentOrder = (int) $maxOrderStmt->fetchColumn();

        foreach ($rows as $rowIdx => $row) {
            $lineNum = $rowIdx + 2; // 1-based, accounting for header

            // Skip empty rows
            $hasData = false;
            foreach ($row as $val) {
                if (trim((string) $val) !== '') {
                    $hasData = true;
                    break;
                }
            }
            if (!$hasData) {
                continue;
            }

            $questionText = trim((string) ($row[$colMap['question_text']] ?? ''));
            if ($questionText === '') {
                $errors[] = "Row {$lineNum}: Question text is missing";
                continue;
            }

            $rawType = strtolower(trim((string) ($row[$colMap['type']] ?? 'mcq')));
            $type = in_array($rawType, ['written', 'text', 'written-answer', 'essay'], true) ? 'written' : 'mcq';

            $currentOrder++;
            $customOrder = isset($colMap['display_order']) && isset($row[$colMap['display_order']])
                ? (int) trim((string) $row[$colMap['display_order']])
                : 0;
            $displayOrder = $customOrder > 0 ? $customOrder : $currentOrder;

            if ($type === 'written') {
                $validQuestions[] = [
                    'text' => $questionText,
                    'type' => 'written',
                    'display_order' => $displayOrder,
                    'options' => [],
                ];
                continue;
            }

            // MCQ Question options parsing
            $rawOptions = [];
            foreach (['option_a', 'option_b', 'option_c', 'option_d'] as $optKey) {
                if (isset($colMap[$optKey]) && isset($row[$colMap[$optKey]])) {
                    $optText = trim((string) $row[$colMap[$optKey]]);
                    if ($optText !== '') {
                        $rawOptions[] = $optText;
                    }
                }
            }

            if (count($rawOptions) < 2) {
                $errors[] = "Row {$lineNum}: MCQ questions require at least 2 options (Option A and Option B)";
                continue;
            }

            // Resolve correct option
            $rawCorrect = strtoupper(trim((string) ($row[$colMap['correct_option']] ?? '')));
            $correctIdx = -1;

            if (in_array($rawCorrect, ['A', '1'], true)) {
                $correctIdx = 0;
            } elseif (in_array($rawCorrect, ['B', '2'], true)) {
                $correctIdx = 1;
            } elseif (in_array($rawCorrect, ['C', '3'], true)) {
                $correctIdx = 2;
            } elseif (in_array($rawCorrect, ['D', '4'], true)) {
                $correctIdx = 3;
            } else {
                foreach ($rawOptions as $idx => $optTxt) {
                    if (strcasecmp($optTxt, $rawCorrect) === 0) {
                        $correctIdx = $idx;
                        break;
                    }
                }
            }

            if ($correctIdx < 0 || $correctIdx >= count($rawOptions)) {
                $errors[] = "Row {$lineNum}: Correct option must be A, B, C, or D (found: '{$rawCorrect}')";
                continue;
            }

            $options = [];
            foreach ($rawOptions as $idx => $optTxt) {
                $options[] = [
                    'text' => $optTxt,
                    'is_correct' => ($idx === $correctIdx) ? 1 : 0,
                    'display_order' => $idx + 1,
                ];
            }

            $validQuestions[] = [
                'text' => $questionText,
                'type' => 'mcq',
                'display_order' => $displayOrder,
                'options' => $options,
            ];
        }

        if (!empty($errors)) {
            return [
                'total_rows' => count($rows),
                'imported_count' => 0,
                'errors' => $errors,
            ];
        }

        // Commit valid questions atomically into bank
        $this->db->beginTransaction();
        try {
            $insertQStmt = $this->db->prepare(
                'INSERT INTO questions (question_bank_id, quiz_id, question_text, type, display_order) VALUES (:bid, NULL, :text, :type, :ord)'
            );
            $insertOptStmt = $this->db->prepare(
                'INSERT INTO answer_options (question_id, option_text, is_correct, display_order) VALUES (:qid, :text, :corr, :ord)'
            );

            foreach ($validQuestions as $q) {
                $insertQStmt->execute([
                    'bid' => $bankId,
                    'text' => $q['text'],
                    'type' => $q['type'],
                    'ord' => $q['display_order'],
                ]);
                $questionId = (int) $this->db->lastInsertId();

                if ($q['type'] === 'mcq') {
                    foreach ($q['options'] as $opt) {
                        $insertOptStmt->execute([
                            'qid' => $questionId,
                            'text' => $opt['text'],
                            'corr' => $opt['is_correct'],
                            'ord' => $opt['display_order'],
                        ]);
                    }
                }
                $importedCount++;
            }

            $this->db->commit();

            return [
                'total_rows' => count($rows),
                'imported_count' => $importedCount,
                'errors' => [],
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Import questions from CSV or XLSX into a quiz (legacy).
     *
     * @return array{total_rows: int, imported_count: int, errors: string[]}
     */
    public function import(int $quizId, string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("File not found: {$filePath}");
        }

        if (filesize($filePath) > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('File size exceeds the 10 MB limit');
        }

        // Check if quiz has a linked question bank
        $qStmt = $this->db->prepare('SELECT id, status, question_bank_id FROM quizzes WHERE id = :id');
        $qStmt->execute(['id' => $quizId]);
        $quiz = $qStmt->fetch(PDO::FETCH_ASSOC);

        if (!$quiz) {
            throw new InvalidArgumentException("Quiz ID {$quizId} not found");
        }

        if (!empty($quiz['question_bank_id'])) {
            return $this->importToBank((int) $quiz['question_bank_id'], $filePath);
        }

        if ($quiz['status'] !== 'draft') {
            throw new RuntimeException("Cannot import questions into a '{$quiz['status']}' quiz. Only draft quizzes can be modified.");
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'xlsx') {
            $rows = $this->parseXlsx($filePath);
        } else {
            $rows = $this->parseCsv($filePath);
        }

        if (empty($rows)) {
            throw new InvalidArgumentException('The uploaded file does not contain any question data');
        }

        $header = array_shift($rows);
        $colMap = $this->resolveHeaderColumns($header);

        $importedCount = 0;
        $errors = [];
        $validQuestions = [];

        // Determine current max display order
        $maxOrderStmt = $this->db->prepare('SELECT COALESCE(MAX(display_order), 0) FROM questions WHERE quiz_id = :qid');
        $maxOrderStmt->execute(['qid' => $quizId]);
        $currentOrder = (int) $maxOrderStmt->fetchColumn();

        foreach ($rows as $rowIdx => $row) {
            $lineNum = $rowIdx + 2; // 1-based, accounting for header

            // Skip empty rows
            $hasData = false;
            foreach ($row as $val) {
                if (trim((string) $val) !== '') {
                    $hasData = true;
                    break;
                }
            }
            if (!$hasData) {
                continue;
            }

            $questionText = trim((string) ($row[$colMap['question_text']] ?? ''));
            if ($questionText === '') {
                $errors[] = "Row {$lineNum}: Question text is missing";
                continue;
            }

            $rawType = strtolower(trim((string) ($row[$colMap['type']] ?? 'mcq')));
            $type = in_array($rawType, ['written', 'text', 'written-answer', 'essay'], true) ? 'written' : 'mcq';

            $currentOrder++;
            $customOrder = isset($colMap['display_order']) && isset($row[$colMap['display_order']])
                ? (int) trim((string) $row[$colMap['display_order']])
                : 0;
            $displayOrder = $customOrder > 0 ? $customOrder : $currentOrder;

            if ($type === 'written') {
                $validQuestions[] = [
                    'text' => $questionText,
                    'type' => 'written',
                    'display_order' => $displayOrder,
                    'options' => [],
                ];
                continue;
            }

            // MCQ Question options parsing
            $rawOptions = [];
            foreach (['option_a', 'option_b', 'option_c', 'option_d'] as $optKey) {
                if (isset($colMap[$optKey]) && isset($row[$colMap[$optKey]])) {
                    $optText = trim((string) $row[$colMap[$optKey]]);
                    if ($optText !== '') {
                        $rawOptions[] = $optText;
                    }
                }
            }

            if (count($rawOptions) < 2) {
                $errors[] = "Row {$lineNum}: MCQ questions require at least 2 options (Option A and Option B)";
                continue;
            }

            // Resolve correct option
            $rawCorrect = strtoupper(trim((string) ($row[$colMap['correct_option']] ?? '')));
            $correctIdx = -1;

            if (in_array($rawCorrect, ['A', '1'], true)) {
                $correctIdx = 0;
            } elseif (in_array($rawCorrect, ['B', '2'], true)) {
                $correctIdx = 1;
            } elseif (in_array($rawCorrect, ['C', '3'], true)) {
                $correctIdx = 2;
            } elseif (in_array($rawCorrect, ['D', '4'], true)) {
                $correctIdx = 3;
            } else {
                // Check if the correct option text matches one of the option texts directly
                foreach ($rawOptions as $idx => $optTxt) {
                    if (strcasecmp($optTxt, $rawCorrect) === 0) {
                        $correctIdx = $idx;
                        break;
                    }
                }
            }

            if ($correctIdx < 0 || $correctIdx >= count($rawOptions)) {
                $errors[] = "Row {$lineNum}: Correct option must be A, B, C, or D (found: '{$rawCorrect}')";
                continue;
            }

            $options = [];
            foreach ($rawOptions as $idx => $optTxt) {
                $options[] = [
                    'text' => $optTxt,
                    'is_correct' => ($idx === $correctIdx) ? 1 : 0,
                    'display_order' => $idx + 1,
                ];
            }

            $validQuestions[] = [
                'text' => $questionText,
                'type' => 'mcq',
                'display_order' => $displayOrder,
                'options' => $options,
            ];
        }

        if (!empty($errors)) {
            return [
                'total_rows' => count($rows),
                'imported_count' => 0,
                'errors' => $errors,
            ];
        }

        // Commit valid questions atomically
        $this->db->beginTransaction();
        try {
            $insertQStmt = $this->db->prepare(
                'INSERT INTO questions (quiz_id, question_text, type, display_order) VALUES (:qid, :text, :type, :ord)'
            );
            $insertOptStmt = $this->db->prepare(
                'INSERT INTO answer_options (question_id, option_text, is_correct, display_order) VALUES (:qid, :text, :corr, :ord)'
            );

            foreach ($validQuestions as $q) {
                $insertQStmt->execute([
                    'qid' => $quizId,
                    'text' => $q['text'],
                    'type' => $q['type'],
                    'ord' => $q['display_order'],
                ]);
                $newQId = (int) $this->db->lastInsertId();

                if ($q['type'] === 'mcq') {
                    foreach ($q['options'] as $opt) {
                        $insertOptStmt->execute([
                            'qid' => $newQId,
                            'text' => $opt['text'],
                            'corr' => $opt['is_correct'],
                            'ord' => $opt['display_order'],
                        ]);
                    }
                }
                $importedCount++;
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'total_rows' => count($rows),
            'imported_count' => $importedCount,
            'errors' => [],
        ];
    }

    /**
     * Resolve CSV/Excel column mapping by searching for canonical names.
     *
     * @param string[] $header
     * @return array<string, int>
     */
    private function resolveHeaderColumns(array $header): array
    {
        $normalized = [];
        foreach ($header as $idx => $col) {
            $clean = strtolower(trim((string) $col));
            $clean = preg_replace('/[^a-z0-9]/', '', $clean);
            $normalized[$clean] = $idx;
        }

        $colMap = [];

        // Question Text
        foreach (['questiontext', 'question', 'prompt', 'questionprompt'] as $alias) {
            if (isset($normalized[$alias])) {
                $colMap['question_text'] = $normalized[$alias];
                break;
            }
        }
        if (!isset($colMap['question_text'])) {
            throw new InvalidArgumentException("Missing required column 'Question Text' in file header");
        }

        // Question Type
        foreach (['questiontype', 'type', 'format'] as $alias) {
            if (isset($normalized[$alias])) {
                $colMap['type'] = $normalized[$alias];
                break;
            }
        }

        // Options A, B, C, D
        foreach (['optiona', 'opta', 'a'] as $alias) {
            if (isset($normalized[$alias])) {
                $colMap['option_a'] = $normalized[$alias];
                break;
            }
        }
        foreach (['optionb', 'optb', 'b'] as $alias) {
            if (isset($normalized[$alias])) {
                $colMap['option_b'] = $normalized[$alias];
                break;
            }
        }
        foreach (['optionc', 'optc', 'c'] as $alias) {
            if (isset($normalized[$alias])) {
                $colMap['option_c'] = $normalized[$alias];
                break;
            }
        }
        foreach (['optiond', 'optd', 'd'] as $alias) {
            if (isset($normalized[$alias])) {
                $colMap['option_d'] = $normalized[$alias];
                break;
            }
        }

        // Correct Option
        foreach (['correctoption', 'correctanswer', 'answer', 'correct'] as $alias) {
            if (isset($normalized[$alias])) {
                $colMap['correct_option'] = $normalized[$alias];
                break;
            }
        }

        // Display Order
        foreach (['displayorder', 'order', 'slno', 'questionno'] as $alias) {
            if (isset($normalized[$alias])) {
                $colMap['display_order'] = $normalized[$alias];
                break;
            }
        }

        return $colMap;
    }

    /**
     * Parse standard CSV file.
     *
     * @return array<int, array<int, string>>
     */
    private function parseCsv(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new RuntimeException("Cannot open CSV file: {$filePath}");
        }

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_map('trim', $row);
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Parse native XLSX Excel file via ZipArchive and SimpleXML.
     *
     * @return array<int, array<int, string>>
     */
    private function parseXlsx(string $filePath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new InvalidArgumentException('Could not read Excel file as a valid XLSX archive');
        }

        // 1. Read shared strings if available
        $sharedStrings = [];
        $stringsXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($stringsXml !== false) {
            $xml = simplexml_load_string($stringsXml);
            if ($xml !== false) {
                foreach ($xml->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string) $si->t;
                    } elseif (isset($si->r)) {
                        $text = '';
                        foreach ($si->r as $r) {
                            $text .= (string) $r->t;
                        }
                        $sharedStrings[] = $text;
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }
        }

        // 2. Read first worksheet
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml === false) {
            $zip->close();
            throw new InvalidArgumentException('No sheet data found in XLSX file');
        }

        $xml = simplexml_load_string($sheetXml);
        $zip->close();

        if ($xml === false || !isset($xml->sheetData)) {
            throw new InvalidArgumentException('Could not parse worksheet in XLSX file');
        }

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $rowValues = [];
            $maxColIdx = 0;

            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                preg_match('/^([A-Z]+)(\d+)$/', $ref, $matches);
                $colLetters = $matches[1] ?? 'A';
                $colIdx = $this->letterToColumnIndex($colLetters);

                $cellType = (string) $cell['t'];
                $val = (string) $cell->v;

                if ($cellType === 's') {
                    $stringIdx = (int) $val;
                    $actualValue = $sharedStrings[$stringIdx] ?? '';
                } elseif ($cellType === 'inlineStr' && isset($cell->is->t)) {
                    $actualValue = (string) $cell->is->t;
                } else {
                    $actualValue = $val;
                }

                $rowValues[$colIdx] = trim($actualValue);
                if ($colIdx > $maxColIdx) {
                    $maxColIdx = $colIdx;
                }
            }

            // Fill empty cells
            $filledRow = [];
            for ($c = 0; $c <= $maxColIdx; $c++) {
                $filledRow[$c] = $rowValues[$c] ?? '';
            }
            $rows[] = $filledRow;
        }

        return $rows;
    }

    private function letterToColumnIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $num = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $num = $num * 26 + (ord($letters[$i]) - 64);
        }
        return $num - 1; // 0-based index
    }

    /**
     * Generate sample CSV template content.
     */
    public function getSampleTemplateCsv(): string
    {
        $headers = [
            'Question Text',
            'Question Type',
            'Option A',
            'Option B',
            'Option C',
            'Option D',
            'Correct Option',
            'Display Order',
        ];

        $sampleData = [
            [
                'What is the maximum charging wattage of OPPO 80W SuperVOOC technology?',
                'mcq',
                '65W',
                '80W',
                '100W',
                '120W',
                'B',
                '1',
            ],
            [
                'Which display refresh rate is featured in the Reno 11 Pro series?',
                'mcq',
                '60 Hz',
                '90 Hz',
                '120 Hz',
                '144 Hz',
                'C',
                '2',
            ],
            [
                'What is the proprietary imaging NPU developed by OPPO called?',
                'mcq',
                'MariSilicon X',
                'Bionic Fusion',
                'Snapdragon Sight',
                'Tensor G3',
                'A',
                '3',
            ],
            [
                'Please describe the primary customer benefits of the ColorOS Trinity Engine and how you explain it to retail walk-ins.',
                'written',
                '',
                '',
                '',
                '',
                '',
                '4',
            ],
            [
                'Share 2-3 key improvements you would recommend for improving store experience during upcoming festival campaigns.',
                'written',
                '',
                '',
                '',
                '',
                '',
                '5',
            ],
        ];

        // Add 35 more template placeholder MCQ questions to demonstrate 40 questions support
        for ($i = 6; $i <= 40; $i++) {
            $sampleData[] = [
                "Sample Assessment Question #{$i}: What is the standard operational procedure for customer device demonstration?",
                'mcq',
                'Greet, Qualify, Feature Demo, Close',
                'Direct Billing without consultation',
                'Handing box without explaining specs',
                'Only demonstrating camera features',
                'A',
                (string) $i,
            ];
        }

        $handle = fopen('php://memory', 'r+');
        fputcsv($handle, $headers);
        foreach ($sampleData as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return (string) $content;
    }
}
