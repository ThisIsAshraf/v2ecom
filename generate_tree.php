<?php

function buildDirectoryTree(string $dir, string $prefix = ''): string
{
    if (!is_dir($dir)) {
        return "Directory does not exist: {$dir}\n";
    }

    $output = '';
    // Exclude system files and common heavy directories like vendor or .git if needed
    $items = array_diff(scandir($dir), ['.', '..']);

    usort($items, function ($a, $b) use ($dir) {
        $pathA = $dir . DIRECTORY_SEPARATOR . $a;
        $pathB = $dir . DIRECTORY_SEPARATOR . $b;
        if (is_dir($pathA) === is_dir($pathB)) {
            return strnatcasecmp($a, $b);
        }
        return is_dir($pathA) ? -1 : 1;
    });

    $totalItems = count($items);
    $currentIndex = 0;

    foreach ($items as $item) {
        $currentIndex++;
        $isLast = ($currentIndex === $totalItems);
        $path = $dir . DIRECTORY_SEPARATOR . $item;

        $branch = $isLast ? '└── ' : '├── ';
        $output .= $prefix . $branch . $item . "\n";

        if (is_dir($path)) {
            $nextPrefix = $prefix . ($isLast ? '    ' : '│   ');
            $output .= buildDirectoryTree($path, $nextPrefix);
        }
    }

    return $output;
}

$targetDirectory = __DIR__; // Uses C:\xampp\htdocs\v2ecom
$outputFilePath  = $targetDirectory . '/folder_tree.txt';

$treeResult = basename(realpath($targetDirectory)) . "\n│\n";
$treeResult .= buildDirectoryTree($targetDirectory);

file_put_contents($outputFilePath, $treeResult);

echo "Folder tree generated successfully at: {$outputFilePath}\n";
