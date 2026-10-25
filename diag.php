<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo "<h2>Portal File Syntax & Lint Scanner</h2>";

require_once __DIR__ . '/app/bootstrap.php';

$adminDir = __DIR__ . '/manage_portal_x92';

if (!is_dir($adminDir)) {
    die("❌ Directory <code>manage_portal_x92</code> not found.");
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($adminDir));
$errorsFound = 0;
$checkedCount = 0;

echo "<ul>";
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $checkedCount++;
        $filePath = $file->getPathname();
        $relative = str_replace(__DIR__ . '/', '', $filePath);
        $code = file_get_contents($filePath);

        // Check 1: Opening tag
        if (!str_starts_with(trim($code), '<?php')) {
            echo "<li style='color:red;'>❌ <b>{$relative}</b> does not start with <code>&lt;?php</code></li>";
            $errorsFound++;
            continue;
        }

        // Check 2: Parse tokens for syntax errors
        try {
            $tokens = @token_get_all($code, TOKEN_PARSE);
            echo "<li style='color:green;'>✅ <b>{$relative}</b>: Syntax OK</li>";
        } catch (\ParseError $e) {
            echo "<li style='color:red;'>❌ <b>{$relative}</b> — Syntax Error: " . htmlspecialchars($e->getMessage()) . " on line " . $e->getLine() . "</li>";
            $errorsFound++;
        }
    }
}
echo "</ul>";

echo "<h3>Scan Complete: Checked {$checkedCount} files. Found {$errorsFound} errors.</h3>";