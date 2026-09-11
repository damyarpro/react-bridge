<?php
/**
 * React Bridge .po to .mo compiler (CLI only, pure PHP).
 *
 * Needs neither the gettext extension nor msgfmt. Writes a standard
 * little-endian MO file (magic 0x950412de, revision 0) with an empty hash
 * table, which every gettext reader, WordPress included, accepts.
 *
 * Usage:
 *   php tools/make-mo.php languages/react-bridge-fa_IR.po [output.mo]
 *
 * Entries that are fuzzy, obsolete or untranslated are skipped, exactly as
 * msgfmt does, so a half finished catalog falls back to the English source.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "make-mo.php must be run from the command line.\n");
    exit(1);
}

const RB_MO_MAGIC = 0x950412de;
const RB_CONTEXT_GLUE = "\x04";
const RB_PLURAL_GLUE = "\x00";

$source = $argv[1] ?? null;
if ($source === null) {
    fwrite(STDERR, "Usage: php tools/make-mo.php <file.po> [file.mo]\n");
    exit(1);
}
if (!is_readable($source)) {
    fwrite(STDERR, "Cannot read {$source}\n");
    exit(1);
}

$target = $argv[2] ?? preg_replace('/\.po$/i', '', $source) . '.mo';

/** Decode a quoted .po string literal. */
function rb_po_unescape(string $raw): string
{
    return stripcslashes($raw);
}

/**
 * Parse a .po file into [msgid => msgstr] pairs ready for the MO tables.
 *
 * @return array<string, string>
 */
function rb_parse_po(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        fwrite(STDERR, "Cannot open {$path}\n");
        exit(1);
    }

    $entries = [];
    $entry = null;
    $field = null;
    $pluralIndex = 0;

    $reset = static function () use (&$entry, &$field, &$pluralIndex): void {
        $entry = ['context' => null, 'id' => null, 'plural' => null, 'str' => [], 'fuzzy' => false];
        $field = null;
        $pluralIndex = 0;
    };

    $flush = static function () use (&$entry, &$entries): void {
        if ($entry === null || $entry['id'] === null) {
            return;
        }
        $translations = $entry['str'];
        ksort($translations);
        $joined = implode(RB_PLURAL_GLUE, $translations);

        $isHeader = $entry['id'] === '' && $entry['context'] === null;
        if (!$isHeader && ($entry['fuzzy'] || trim($joined) === '')) {
            return;
        }

        $key = $entry['id'];
        if ($entry['plural'] !== null) {
            $key .= RB_PLURAL_GLUE . $entry['plural'];
        }
        if ($entry['context'] !== null) {
            $key = $entry['context'] . RB_CONTEXT_GLUE . $key;
        }
        $entries[$key] = $joined;
    };

    $reset();

    while (($line = fgets($handle)) !== false) {
        $line = rtrim($line, "\r\n");
        $trimmed = trim($line);

        if ($trimmed === '') {
            $flush();
            $reset();
            continue;
        }

        if (str_starts_with($trimmed, '#~')) {
            // Obsolete entry: ignore the whole line.
            continue;
        }

        if ($trimmed[0] === '#') {
            if (str_starts_with($trimmed, '#,') && str_contains($trimmed, 'fuzzy')) {
                $entry['fuzzy'] = true;
            }
            continue;
        }

        if (preg_match('/^(msgctxt|msgid_plural|msgid|msgstr)(?:\[(\d+)\])?\s+"(.*)"$/s', $trimmed, $m)) {
            $keyword = $m[1];
            $value = rb_po_unescape($m[3]);

            switch ($keyword) {
                case 'msgctxt':
                    $entry['context'] = $value;
                    $field = 'context';
                    break;
                case 'msgid':
                    $entry['id'] = $value;
                    $field = 'id';
                    break;
                case 'msgid_plural':
                    $entry['plural'] = $value;
                    $field = 'plural';
                    break;
                case 'msgstr':
                    $pluralIndex = $m[2] !== '' ? (int) $m[2] : 0;
                    $entry['str'][$pluralIndex] = $value;
                    $field = 'str';
                    break;
            }
            continue;
        }

        if (preg_match('/^"(.*)"$/s', $trimmed, $m) && $field !== null) {
            $value = rb_po_unescape($m[1]);
            if ($field === 'str') {
                $entry['str'][$pluralIndex] .= $value;
            } else {
                $entry[$field] .= $value;
            }
            continue;
        }

        fwrite(STDERR, "Unexpected line in {$path}: {$line}\n");
        exit(1);
    }

    fclose($handle);
    $flush();

    return $entries;
}

/** Build the binary MO payload. Originals must be sorted for binary search. */
function rb_build_mo(array $entries): string
{
    ksort($entries, SORT_STRING);

    $count = count($entries);
    $originalTable = 28;
    $translationTable = $originalTable + 8 * $count;
    $hashOffset = $translationTable + 8 * $count;

    $header = pack(
        'VVVVVV',
        RB_MO_MAGIC,
        0,               // File format revision.
        $count,
        $originalTable,
        $translationTable,
        0                // Hash table size: zero means no hash table.
    ) . pack('V', $hashOffset);

    $offset = $hashOffset;
    $originals = '';
    $translations = '';
    $blob = '';

    foreach ($entries as $id => $str) {
        $originals .= pack('VV', strlen((string) $id), $offset);
        $blob .= $id . "\0";
        $offset += strlen((string) $id) + 1;
    }

    foreach ($entries as $str) {
        $translations .= pack('VV', strlen($str), $offset);
        $blob .= $str . "\0";
        $offset += strlen($str) + 1;
    }

    return $header . $originals . $translations . $blob;
}

$entries = rb_parse_po($source);
if ($entries === []) {
    fwrite(STDERR, "No translated entries found in {$source}\n");
    exit(1);
}

$mo = rb_build_mo($entries);
if (file_put_contents($target, $mo) === false) {
    fwrite(STDERR, "Could not write {$target}\n");
    exit(1);
}

printf("Compiled %d entries (%d bytes) to %s\n", count($entries), strlen($mo), $target);
