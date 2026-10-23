<?php

/**
 * Rector 之前的“词法级”预处理（只动标签和个别符号，其余内容一字不动）：
 *   1. 短标签 "<?" → "<?php "（新装的 PHP 8 默认 short_open_tag=Off，短标签里的代码会被当成文本直接输出）
 *   2. "=& new" → "= new"（PHP 7.0 起是语法错误，整个文件无法加载；对象本来就是按引用传递，去掉 & 行为不变）
 * 用 PHP 自身的词法分析（short_open_tag=On 模式，与现网旧服务器一致）定位，不会误改字符串、注释里的内容。
 * 二进制文件（例如被改成 .php 后缀的图片）一律跳过。
 *
 * 用法: php -d short_open_tag=1 prefix_tokens.php <目录> <输出json> [排除通配符,逗号分隔] [--keep-short-tags]
 * 输出: { "相对路径": {"short_tags": n, "ref_new": n}, ... }
 */

declare(strict_types=1);

if (!ini_get('short_open_tag')) {
    fwrite(STDERR, "请用 php -d short_open_tag=1 运行\n");
    exit(1);
}
$args = array_values(array_filter(array_slice($argv, 1), static fn ($a) => $a !== '--keep-short-tags'));
$fixShort = !in_array('--keep-short-tags', $argv, true);
[$dir, $out] = $args + [null, null];
$exclude = array_filter(explode(',', $args[2] ?? ''));
$dir = rtrim((string) realpath((string) $dir), '/');

$skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
$text = static fn ($t) => is_array($t) ? $t[1] : $t;
$id = static fn ($t) => is_array($t) ? $t[0] : $t;
$changed = [];
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
    $hasShort = $fixShort && preg_match('/<\?(?!php|=|xml)/i', $code);
    $hasRefNew = preg_match('/=\s*&\s*new\b/i', $code);
    if (str_contains($code, "\0") || (!$hasShort && !$hasRefNew)) {
        continue;
    }
    $tokens = token_get_all($code);
    $n = count($tokens);
    $stat = ['short_tags' => 0, 'ref_new' => 0];
    $new = '';
    for ($i = 0; $i < $n; $i++) {
        $tok = $tokens[$i];
        if ($hasShort && $id($tok) === T_OPEN_TAG && preg_match('/^<\?(\s*)$/', $tok[1], $m)) {
            $next = $text($tokens[$i + 1] ?? '');
            if ($m[1] === '' && preg_match('/^xml\b/i', $next)) {
                $new .= $tok[1];                // XML 声明（<?xml ...），保持原样
                continue;
            }
            $new .= '<?php' . ($m[1] === '' && !preg_match('/^\s/', $next) ? ' ' : $m[1]);
            $stat['short_tags']++;
            continue;
        }
        if ($hasRefNew && $text($tok) === '&') {
            $p = $i - 1;
            while ($p >= 0 && in_array($id($tokens[$p]), $skip, true)) {
                $p--;
            }
            $q = $i + 1;
            while ($q < $n && in_array($id($tokens[$q]), $skip, true)) {
                $q++;
            }
            if ($p >= 0 && $text($tokens[$p]) === '=' && $q < $n && $id($tokens[$q]) === T_NEW) {
                $stat['ref_new']++;
                continue;                       // 去掉 "&"
            }
        }
        $new .= $text($tok);
    }
    if ($new !== $code) {
        file_put_contents($f->getPathname(), $new);
        $changed[$rel] = array_filter($stat);
    }
}
ksort($changed);
file_put_contents($out, json_encode($changed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
