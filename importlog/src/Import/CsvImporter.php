<?php

declare(strict_types=1);

namespace ImportLog\Import;

class CsvImporter
{
    private const array REQUIRED_HEADERS = ['name', 'email', 'age'];
    private const int MAX_AGE = 150;
    private const int MAX_NAME_LENGTH = 255;
    private const int MAX_EMAIL_LENGTH = 255;

    /**
     * @return array{
     *   rows: list<array{name: string, email: string, age: int|null}>,
     *   errors: list<array{row: int, value: string, error: string}>
     * }
     */
    public function parse(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        if ($lines === false || count($lines) === 0) {
            return ['rows' => [], 'errors' => []];
        }

        $rows = [];
        $errors = [];
        $seenEmails = [];

        foreach ($lines as $i => $line) {
            $fields = str_getcsv($line, ',', '"', '\\');
            $fields = array_map(static fn(?string $f): string => trim((string) ($f ?? '')), $fields);

            if ($i === 0) {
                continue; // skip header
            }

            $rowNum = $i + 1;

            if (count($fields) < 3) {
                $errors[] = ['row' => $rowNum, 'value' => $line, 'error' => 'insufficient columns'];
                continue;
            }

            $name = $fields[0];
            $email = $fields[1];
            $ageRaw = $fields[2];

            $rowErrors = [];

            // name
            if ($name === '') {
                $rowErrors[] = 'name is required';
            } elseif (mb_strlen($name) > self::MAX_NAME_LENGTH) {
                $rowErrors[] = 'name too long';
            }

            // email
            if ($email === '') {
                $rowErrors[] = 'email is required';
            } elseif (mb_strlen($email) > self::MAX_EMAIL_LENGTH) {
                $rowErrors[] = 'email too long';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $rowErrors[] = 'invalid email format';
            } elseif (isset($seenEmails[$email])) {
                $rowErrors[] = 'duplicate email in import batch';
            }

            // age (optional)
            $age = null;
            if ($ageRaw !== '') {
                if (!ctype_digit($ageRaw)) {
                    $rowErrors[] = 'age must be a non-negative integer';
                } else {
                    $age = (int) $ageRaw;
                    if ($age < 0 || $age > self::MAX_AGE) {
                        $rowErrors[] = 'age must be between 0 and ' . self::MAX_AGE;
                    }
                }
            }

            if ($rowErrors !== []) {
                $errors[] = [
                    'row' => $rowNum,
                    'value' => $email !== '' ? $email : $name,
                    'error' => implode('; ', $rowErrors),
                ];
                continue;
            }

            $seenEmails[$email] = true;
            $rows[] = ['name' => $name, 'email' => $email, 'age' => $age];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Validate that the first line contains the expected headers.
     */
    public function validateHeader(string $csv): bool
    {
        $firstLine = strtok($csv, "\r\n");
        if ($firstLine === false) {
            return false;
        }
        $headers = array_map(
            static fn(?string $h): string => trim((string) ($h ?? '')),
            str_getcsv($firstLine, ',', '"', '\\'),
        );
        $headers = array_map('strtolower', $headers);
        return $headers === self::REQUIRED_HEADERS;
    }
}
