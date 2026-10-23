<?php

/**
 * 帝国CMS 模板缓存（e/data/tmp/*.php）：由后台模板生成，里面常有 $r[title] 这种 PHP 8 会致命的写法和短标签 <?。
 * 主流程会跳过这个目录（它会被重新生成），这里单独按运行时同样的规则（phpup_compat.php 里的 phpup_fixphpfile）修一遍，
 * 让现有缓存在上线后立刻可用；之后重新生成的缓存由 connect.php 里的 phpup_tmpfix 钩子处理。
 *
 * 用法: php tmpcache.php <站点目录> <输出json> [写入目录，默认就是站点目录]
 */

declare(strict_types=1);

[$self, $dir, $out, $dst] = $argv + [null, null, null, null];
$dir = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', (string) realpath((string) $dir)), '/');
$dst = $dst === null ? $dir : rtrim(str_replace(DIRECTORY_SEPARATOR, '/', (string) $dst), '/');
$tmp = "{$dir}/e/data/tmp";
$result = ['fixed' => []];
if (is_dir($tmp)) {
    require_once __DIR__ . '/compat/phpup_compat.php';
    // 站点自己 define 的常量要当作“已定义”，否则 $r[ECMS_PATH] 之类会被误加引号
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && preg_match('/\.(php|inc)$/i', $f->getFilename()) && !str_contains(str_replace('\\', '/', $f->getPathname()), '/e/data/tmp/')) {
            if (preg_match_all('/\bdefine\s*\(\s*[\'"]([A-Za-z_]\w*)[\'"]/', (string) file_get_contents($f->getPathname()), $m)) {
                foreach ($m[1] as $c) {
                    if (!defined($c)) {
                        define($c, $c);
                    }
                }
            }
        }
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || !preg_match('/\.php$/i', $f->getFilename())) {
            continue;
        }
        $code = (string) file_get_contents($f->getPathname());
        $new = phpup_fixphpfile($code);
        if ($new === $code) {
            continue;
        }
        // 修改后语法有问题的（多半原来就坏了）不动
        $chk = tempnam(sys_get_temp_dir(), 'phpup');
        file_put_contents($chk, $new);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($chk) . ' 2>&1', $o, $rc);
        unlink($chk);
        if ($rc !== 0) {
            continue;
        }
        $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($dir) + 1);
        if (!is_dir(dirname("{$dst}/{$rel}"))) {
            mkdir(dirname("{$dst}/{$rel}"), 0777, true);
        }
        file_put_contents("{$dst}/{$rel}", $new);
        $result['fixed'][$rel] = 1;
    }
}
file_put_contents((string) $out, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
fwrite(STDERR, '帝国CMS 模板缓存修复：' . count($result['fixed']) . " 个文件\n");
