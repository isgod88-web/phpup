<?php

/**
 * 顺带做的安全检查（只读，不修改任何文件）：
 *   1. 上传/附件目录里的 .php 文件 —— 正常情况下上传目录不应该有可执行脚本
 *   2. 内容其实是图片/二进制、却以 .php 结尾的文件 —— 常见的“图片马”
 *   3. 典型 WebShell 写法（eval($_POST[..]) 之类）
 *
 * 用法: php security.php <站点目录> <输出json> [排除通配符,逗号分隔]
 */

declare(strict_types=1);

[$self, $dir, $out] = $argv + [null, null, null];
$exclude = array_filter(explode(',', $argv[3] ?? ''));
$dir = rtrim((string) realpath((string) $dir), '/');

$uploadDir = '#(^|/)(upload|uploads|uploadfile|uploadfiles|upfile|upfiles|attachment|attachments|attach|userfiles)/|(^|/)d/file/#i';
$shellPatterns = [
    '/\b(eval|assert)\s*\(\s*(\$_(POST|GET|REQUEST|COOKIE|SERVER)|base64_decode|gzinflate|gzuncompress|str_rot13|stripslashes\s*\(\s*\$_)/i' => '执行外部传入的代码（eval/assert）',
    '/\$_(POST|GET|REQUEST|COOKIE)\s*\[[^\]]{1,40}\]\s*\(/i' => '把外部传入的参数当函数名调用',
    '/\b(system|passthru|shell_exec|exec|popen|proc_open)\s*\(\s*\$_(POST|GET|REQUEST|COOKIE)/i' => '直接执行外部传入的系统命令',
    '/preg_replace\s*\(\s*([\'"]).{0,40}\/e[a-z]*\1\s*,\s*\$_(POST|GET|REQUEST)/i' => 'preg_replace /e 执行外部输入',
    '/\bcreate_function\s*\([^;]{0,80}\$_(POST|GET|REQUEST)/i' => 'create_function 执行外部输入',
];
$magic = ["\xFF\xD8\xFF" => 'JPEG 图片', "\x89PNG" => 'PNG 图片', 'GIF8' => 'GIF 图片', 'PK' => 'ZIP 压缩包', '%PDF' => 'PDF'];

$result = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || !preg_match('/\.(php\d?|phtml|phar|inc)$/i', $f->getFilename())) {
        continue;
    }
    $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($f->getPathname(), strlen($dir) + 1));   // Windows 下统一成 /
    foreach ($exclude as $pattern) {
        if (fnmatch($pattern, '/' . $rel)) {
            continue 2;
        }
    }
    $reasons = [];
    $head = (string) file_get_contents($f->getPathname(), false, null, 0, 16);
    foreach ($magic as $sig => $type) {
        if (str_starts_with($head, $sig)) {
            $reasons[] = "文件内容是{$type}，却用了 .php 后缀";
        }
    }
    if (preg_match($uploadDir, $rel)) {
        $reasons[] = '位于上传/附件目录';
    }
    $code = $f->getSize() < 5 * 1024 * 1024 ? (string) file_get_contents($f->getPathname()) : '';
    foreach ($shellPatterns as $re => $desc) {
        if (preg_match($re, $code, $m, PREG_OFFSET_CAPTURE)) {
            $line = substr_count(substr($code, 0, $m[0][1]), "\n") + 1;
            $reasons[] = "疑似后门：{$desc}（第 {$line} 行）";
        }
    }
    if ($reasons !== []) {
        $result[$rel] = $reasons;
    }
}
ksort($result);
file_put_contents($out, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
