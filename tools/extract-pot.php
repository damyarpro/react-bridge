<?php
/**
 * React Bridge POT extractor (CLI only, no WordPress required).
 *
 * Scans every *.php file of the plugin for gettext calls carrying the
 * text domain "react-bridge" and writes a valid GNU gettext .pot catalog.
 *
 * Usage:
 *   php tools/extract-pot.php [plugin-root] [output.pot]
 *
 * Defaults: plugin root = parent of this file, output = languages/react-bridge.pot
 *
 * Parsing uses PHP's own tokenizer, so escaped quotes, concatenation and
 * nested calls are handled exactly as PHP itself sees them.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "extract-pot.php must be run from the command line.\n");
    exit(1);
}

const RB_TEXT_DOMAIN = 'react-bridge';

/**
 * Gettext functions, mapped to the argument layout we care about:
 *   singular index, plural index (null when none), context index (null when none),
 *   domain index.
 */
const RB_GETTEXT_FUNCTIONS = [
    '__'            => ['singular' => 0, 'plural' => null, 'context' => null, 'domain' => 1],
    '_e'            => ['singular' => 0, 'plural' => null, 'context' => null, 'domain' => 1],
    'esc_html__'    => ['singular' => 0, 'plural' => null, 'context' => null, 'domain' => 1],
    'esc_attr__'    => ['singular' => 0, 'plural' => null, 'context' => null, 'domain' => 1],
    'esc_html_e'    => ['singular' => 0, 'plural' => null, 'context' => null, 'domain' => 1],
    'esc_attr_e'    => ['singular' => 0, 'plural' => null, 'context' => null, 'domain' => 1],
    '_x'            => ['singular' => 0, 'plural' => null, 'context' => 1,    'domain' => 2],
    'esc_html_x'    => ['singular' => 0, 'plural' => null, 'context' => 1,    'domain' => 2],
    'esc_attr_x'    => ['singular' => 0, 'plural' => null, 'context' => 1,    'domain' => 2],
    '_ex'           => ['singular' => 0, 'plural' => null, 'context' => 1,    'domain' => 2],
    '_n'            => ['singular' => 0, 'plural' => 1,    'context' => null, 'domain' => 3],
    '_nx'           => ['singular' => 0, 'plural' => 1,    'context' => 3,    'domain' => 4],
];

/** Directories never scanned. */
const RB_SKIP_DIRS = ['languages', 'tools', 'node_modules', 'vendor', '.git'];

$root = isset($argv[1]) ? rtrim($argv[1], "\\/") : dirname(__DIR__);
$out  = $argv[2] ?? $root . '/languages/' . RB_TEXT_DOMAIN . '.pot';

if (!is_dir($root)) {
    fwrite(STDERR, "Plugin root not found: {$root}\n");
    exit(1);
}

/**
 * Collect *.php files, skipping generated and tooling directories.
 *
 * @return string[] Absolute paths, sorted for stable output.
 */
function rb_php_files(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $file): bool {
                if ($file->isDir()) {
                    return !in_array($file->getFilename(), RB_SKIP_DIRS, true);
                }
                return strtolower($file->getExtension()) === 'php';
            }
        )
    );

    foreach ($iterator as $file) {
        $files[] = str_replace('\\', '/', $file->getPathname());
    }

    sort($files);
    return $files;
}

/**
 * Strip whitespace and comment tokens so argument scanning stays simple.
 *
 * @return array<int, array{0:int|string, 1:string, 2:int}> Normalized tokens.
 */
function rb_significant_tokens(string $code): array
{
    $tokens = [];
    $line = 1;

    foreach (token_get_all($code) as $token) {
        if (is_array($token)) {
            [$id, $text, $line] = [$token[0], $token[1], $token[2]];
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $tokens[] = [$id, $text, $line];
            continue;
        }
        $tokens[] = [$token, $token, $line];
    }

    return $tokens;
}

/**
 * Read the argument list starting at the token index of "(".
 * Each argument is returned either as a literal string value or as null when
 * it is not a single constant string (variable, concatenation, call, ...).
 *
 * @param array<int, array{0:int|string, 1:string, 2:int}> $tokens
 * @return array{0: array<int, ?string>, 1: int} Arguments and index after ")".
 */
function rb_read_arguments(array $tokens, int $open): array
{
    $args = [];
    $depth = 0;
    $current = [];
    $count = count($tokens);

    for ($i = $open; $i < $count; $i++) {
        $token = $tokens[$i];
        $id = $token[0];

        if ($id === '(' || $id === '[' || $id === '{') {
            $depth++;
            if ($depth === 1) {
                continue;
            }
        } elseif ($id === ')' || $id === ']' || $id === '}') {
            $depth--;
            if ($depth === 0) {
                if ($current !== [] ) {
                    $args[] = rb_literal_value($current);
                }
                return [$args, $i + 1];
            }
        } elseif ($id === ',' && $depth === 1) {
            $args[] = rb_literal_value($current);
            $current = [];
            continue;
        }

        $current[] = $token;
    }

    return [$args, $count];
}

/**
 * @param array<int, array{0:int|string, 1:string, 2:int}> $tokens One argument.
 */
function rb_literal_value(array $tokens): ?string
{
    if (count($tokens) !== 1 || $tokens[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
        return null;
    }
    return rb_unquote($tokens[0][1]);
}

/** Turn a PHP source literal ('a\'b' or "a\nb") into its runtime value. */
function rb_unquote(string $raw): string
{
    $quote = $raw[0];
    $body = substr($raw, 1, -1);

    if ($quote === "'") {
        return strtr($body, ["\\'" => "'", '\\\\' => '\\']);
    }

    return stripcslashes($body);
}

/** Escape a value for a .po/.pot msgid or msgstr. */
function rb_po_escape(string $value): string
{
    return str_replace(
        ["\\", "\"", "\t", "\r", "\n"],
        ["\\\\", "\\\"", "\\t", "\\r", "\\n"],
        $value
    );
}

/** Render a po entry line, splitting on newlines like msgfmt does. */
function rb_po_line(string $keyword, string $value): string
{
    if (!str_contains($value, "\n")) {
        return $keyword . ' "' . rb_po_escape($value) . "\"\n";
    }

    $out = $keyword . " \"\"\n";
    $parts = explode("\n", $value);
    $last = count($parts) - 1;
    foreach ($parts as $index => $part) {
        $chunk = $index === $last ? $part : $part . "\n";
        if ($chunk === '') {
            continue;
        }
        $out .= '"' . rb_po_escape($chunk) . "\"\n";
    }

    return $out;
}

$files = rb_php_files($root);
if ($files === []) {
    fwrite(STDERR, "No PHP files found under {$root}\n");
    exit(1);
}

/** @var array<string, array{context: ?string, singular: string, plural: ?string, refs: string[]}> */
$entries = [];
$skipped = 0;

foreach ($files as $file) {
    $code = file_get_contents($file);
    if ($code === false) {
        fwrite(STDERR, "Could not read {$file}\n");
        exit(1);
    }

    $relative = ltrim(substr($file, strlen(str_replace('\\', '/', $root))), '/');
    $tokens = rb_significant_tokens($code);
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        [$id, $text, $line] = $tokens[$i];

        if ($id !== T_STRING || !isset(RB_GETTEXT_FUNCTIONS[$text])) {
            continue;
        }
        // Skip method/property calls and declarations: $o->__(), Cls::__(), function __().
        $previous = $i > 0 ? $tokens[$i - 1][0] : null;
        if (in_array($previous, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue;
        }
        if (!isset($tokens[$i + 1]) || $tokens[$i + 1][0] !== '(') {
            continue;
        }

        $spec = RB_GETTEXT_FUNCTIONS[$text];
        [$args, $next] = rb_read_arguments($tokens, $i + 1);
        $i = $next - 1;

        $domain = $args[$spec['domain']] ?? null;
        if ($domain !== RB_TEXT_DOMAIN) {
            continue;
        }

        $singular = $args[$spec['singular']] ?? null;
        if ($singular === null || $singular === '') {
            $skipped++;
            fwrite(STDERR, "Non-literal text in {$relative}:{$line} ({$text})\n");
            continue;
        }

        $plural = $spec['plural'] !== null ? ($args[$spec['plural']] ?? null) : null;
        $context = $spec['context'] !== null ? ($args[$spec['context']] ?? null) : null;

        $key = ($context ?? '') . "\x04" . $singular . "\x00" . ($plural ?? '');
        if (!isset($entries[$key])) {
            $entries[$key] = [
                'context'  => $context,
                'singular' => $singular,
                'plural'   => $plural,
                'refs'     => [],
            ];
        }
        $reference = $relative . ':' . $line;
        if (!in_array($reference, $entries[$key]['refs'], true)) {
            $entries[$key]['refs'][] = $reference;
        }
    }
}

uasort($entries, static function (array $a, array $b): int {
    return strnatcmp($a['refs'][0] ?? '', $b['refs'][0] ?? '') ?: strcmp($a['singular'], $b['singular']);
});

$now = gmdate('Y-m-d H:iO');
$pot = <<<POT
# Copyright (C) React Bridge contributors
# This file is distributed under the same license as the React Bridge plugin.
msgid ""
msgstr ""
"Project-Id-Version: React Bridge 1.3.0\\n"
"Report-Msgid-Bugs-To: \\n"
"POT-Creation-Date: {$now}\\n"
"PO-Revision-Date: {$now}\\n"
"Last-Translator: \\n"
"Language-Team: \\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\\n"
"X-Generator: react-bridge tools/extract-pot.php\\n"
"X-Domain: react-bridge\\n"

POT;

foreach ($entries as $entry) {
    $pot .= "\n";
    foreach (array_chunk($entry['refs'], 4) as $chunk) {
        $pot .= '#: ' . implode(' ', $chunk) . "\n";
    }
    if ($entry['context'] !== null) {
        $pot .= rb_po_line('msgctxt', $entry['context']);
    }
    $pot .= rb_po_line('msgid', $entry['singular']);
    if ($entry['plural'] !== null) {
        $pot .= rb_po_line('msgid_plural', $entry['plural']);
        $pot .= "msgstr[0] \"\"\n";
        $pot .= "msgstr[1] \"\"\n";
    } else {
        $pot .= "msgstr \"\"\n";
    }
}

$dir = dirname($out);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Could not create {$dir}\n");
    exit(1);
}

if (file_put_contents($out, $pot) === false) {
    fwrite(STDERR, "Could not write {$out}\n");
    exit(1);
}

printf(
    "Scanned %d files, wrote %d strings to %s%s\n",
    count($files),
    count($entries),
    $out,
    $skipped > 0 ? " ({$skipped} non-literal skipped)" : ''
);
