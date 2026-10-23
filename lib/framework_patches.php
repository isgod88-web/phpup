<?php

/**
 * 框架级补丁：有些框架把所有 PHP 错误（包括 Deprecated 弃用提示）都转成异常抛出。
 * PHP 8.x 新增了大量 Deprecated 提示（如给内置函数传 null、ReflectionParameter::getClass() 等），
 * 在 PHP 7 上不会出现，升级后会被框架当成致命错误 → 整站 500。
 * 这里让框架的错误处理函数跳过 Deprecated（与 PHP 7 上的实际行为一致），其他错误照旧处理。
 *
 * 目前处理：ThinkPHP 5.0 / 5.1（think\Error::appError，error_reporting(E_ALL) 且不区分级别地抛异常）
 *
 * 帝国CMS：发布信息、生成页面时，会把数据库里的模板重新写成 e/data/tmp/*.php 再 include。
 * 模板里 $r[title] 这种写法升级工具改不到，每次重新生成都会带回来 → 发布 500。
 * 在 e/class/connect.php 的 WriteFiletext / WriteFiletext_n 里，写 e/data/tmp 下的 .php 前先过一遍 phpup_tmpfix()（见 phpup_compat.php）。
 *
 * 另外：时区旧别名（PRC、Japan 等）在使用系统 tzdata 的 PHP（如 Ubuntu 24.04+ 的软件源版本）里已不存在，
 * date_default_timezone_set('PRC') 会报 Notice，被上面这类框架当成致命错误。把这些别名换成等价的标准名称
 * （PRC 就是 Asia/Shanghai，时间完全相同）。只改 date_default_timezone_set() 和配置里的 'default_timezone'。
 *
 * 用法: php framework_patches.php <目录> <输出json>
 * 输出: { "文件": 修改处数 }
 */

declare(strict_types=1);

[$self, $dir, $out] = $argv + [null, null, null];
$dir = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', (string) realpath((string) $dir)), '/');
$marker = 'phpup: PHP 8 的 Deprecated 提示不中断运行';
$result = [];

$zones = ['PRC' => 'Asia/Shanghai', 'Japan' => 'Asia/Tokyo', 'ROC' => 'Asia/Taipei', 'ROK' => 'Asia/Seoul',
    'Hongkong' => 'Asia/Hong_Kong', 'Singapore' => 'Asia/Singapore'];
$zoneRe = '/((?:\bdate_default_timezone_set\s*\(\s*|[\'"]default_timezone[\'"]\s*=>\s*)([\'"]))(' . implode('|', array_keys($zones)) . ')(\2)/i';

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || !preg_match('/\.(php|inc|phtml)$/i', $f->getFilename())) {
        continue;
    }
    $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($f->getPathname(), strlen($dir) + 1));
    $code = (string) file_get_contents($f->getPathname());
    if (stripos($code, 'timezone') !== false) {
        $new = preg_replace_callback($zoneRe, static function ($m) use ($zones) {
            foreach ($zones as $k => $v) {
                if (strcasecmp($k, $m[3]) === 0) {
                    return $m[1] . $v . $m[4];
                }
            }
            return $m[0];
        }, $code, -1, $count);
        if ($count > 0 && $new !== null) {
            file_put_contents($f->getPathname(), $new);
            $code = $new;
            $result[$rel] = ($result[$rel] ?? 0) + $count;
        }
    }
    if ($f->getFilename() === 'connect.php' && preg_match('#(^|/)e/class/connect\.php$#i', $rel)
        && str_contains($code, 'function WriteFiletext(') && !str_contains($code, 'phpup_tmpfix')) {
        $hook = "\t\$string=function_exists('phpup_tmpfix')?\\phpup_tmpfix(\$filepath,\$string):\$string;// phpup: 模板缓存补引号\n";
        $new = preg_replace('/(function\s+WriteFiletext\s*\(\s*\$filepath\s*,\s*\$string\s*\)\s*\{[^}]*?\$string\s*=\s*stripSlashes\(\$string\);[ \t]*\r?\n)/i', '$1' . str_replace('\\', '\\\\', $hook), $code, 1, $c1);
        $new = preg_replace('/(function\s+WriteFiletext_n\s*\(\s*\$filepath\s*,\s*\$string\s*\)\s*\{\s*global\s+\$public_r;[ \t]*\r?\n)/i', '$1' . str_replace('\\', '\\\\', $hook), (string) $new, 1, $c2);
        if ($new !== null && $c1 + $c2 > 0) {
            file_put_contents($f->getPathname(), $new);
            $code = $new;
            $result[$rel] = ($result[$rel] ?? 0) + $c1 + $c2;
        }
    }
    if ($f->getFilename() !== 'Error.php' || !preg_match('#(^|/)think/Error\.php$#i', $rel)) {
        continue;
    }
    if (str_contains($code, $marker) || !str_contains($code, 'namespace think;')) {
        continue;
    }
    // public static function appError($errno, $errstr, $errfile = '', $errline = 0)\n    {
    $new = preg_replace_callback(
        '/(public\s+static\s+function\s+appError\s*\(\s*\$(\w+)[^)]*\)[^{]*\{)(\r?\n)([ \t]*)/',
        static fn ($m) => $m[1] . $m[3] . $m[4] . "// {$marker}（与 PHP 7 上的行为一致）" . $m[3]
            . $m[4] . "if (\${$m[2]} & (E_DEPRECATED | E_USER_DEPRECATED)) {" . $m[3]
            . $m[4] . '    return true;' . $m[3]
            . $m[4] . '}' . $m[3] . $m[4],
        $code,
        1,
        $count
    );
    if ($count === 1 && $new !== null) {
        file_put_contents($f->getPathname(), $new);
        $result[$rel] = ($result[$rel] ?? 0) + 1;
    }
}
ksort($result);
file_put_contents((string) $out, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
fwrite(STDERR, '框架错误处理 / 时区别名补丁：' . array_sum($result) . " 处\n");
