<?php

/**
 * 查找使用短标签 "<?"（而不是 "<?php"）的文件。
 * 新装的 PHP 8 一般使用 php.ini-production，其中 short_open_tag=Off，
 * 这时短标签里的代码会被当成普通文本直接输出到页面上（源码泄露、页面错乱）。
 *
 * 用法: php -d short_open_tag=0 shorttags.php <目录> <输出json> [排除通配符,逗号分隔]
 */

declare(strict_types=1);

[$self, $dir, $out] = $argv + [null, null, null];
$exclude = array_filter(explode(',', $argv[3] ?? ''));
if ($dir === null || $out === null) {
    fwrite(STDERR, "用法: php -d short_open_tag=0 shorttags.php <目录> <输出json> [排除]\n");
    exit(1);
}
$dir = rtrim((string) realpath($dir), '/');

$result = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || !preg_match('/\.(php|inc|phtml)$/i', $f->getFilename())) {
        continue;
    }
    $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($f->getPathname(), strlen($dir) + 1));   // Windows 下统一成 /
    foreach ($exclude as $pattern) {
        if (fnmatch($pattern, '/' . $rel)) {
            continue 2;
        }
    }
    $code = (string) file_get_contents($f->getPathname());
    if (!preg_match('/<\?(?!php|=|xml)/i', $code)) {
        continue;
    }
    $lines = [];
    foreach (token_get_all($code) as $tok) {
        if (is_array($tok) && $tok[0] === T_INLINE_HTML && preg_match_all('/<\?(?!php|=|xml)/i', $tok[1], $mm, PREG_OFFSET_CAPTURE)) {
            foreach ($mm[0] as [, $offset]) {
                $lines[] = $tok[2] + substr_count(substr($tok[1], 0, $offset), "\n");
            }
        }
    }
    if ($lines !== []) {
        $result[$rel] = $lines;
    }
}
ksort($result);
file_put_contents($out, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
