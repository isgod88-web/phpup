<?php

/**
 * phpup 兼容层：把 PHP 8 删除、但老代码仍可能在用的函数补回来，行为与 PHP 7 一致。
 *
 * 用法（二选一）：
 *   1. php.ini 里加一行   auto_prepend_file = "/站点或公共目录/phpup_compat.php"
 *   2. 或者在站点公共入口文件最前面 require_once 本文件
 *
 * 只在函数不存在时才定义，重复加载、在旧版 PHP 上加载都没有副作用。
 * phpup 已经把能静态改写的调用都改掉了，这里是给“动态拼出来的调用”等漏网情况兜底。
 *
 * 不包含 mysql_* / ereg* / split 等：它们在 PHP 7.0 就已删除，现网 PHP 7.x 上这些代码本来就不会运行，
 * 补回来反而可能改变程序走向（很多程序用 function_exists('mysql_connect') 判断走哪条分支）。
 */

if (!function_exists('each')) {
    function each(&$array)
    {
        $key = key($array);
        if ($key === null) {
            return false;
        }
        $value = current($array);
        next($array);
        return [1 => $value, 'value' => $value, 0 => $key, 'key' => $key];
    }
}

if (!function_exists('create_function')) {
    function create_function($args, $code)
    {
        static $n = 0;
        do {
            $name = '__phpup_lambda_' . (++$n);
        } while (function_exists($name));
        eval('function ' . $name . '(' . $args . ') {' . $code . '}');
        return $name;
    }
}

if (!function_exists('get_magic_quotes_gpc')) {
    function get_magic_quotes_gpc()
    {
        return false;
    }
}
if (!function_exists('get_magic_quotes_runtime')) {
    function get_magic_quotes_runtime()
    {
        return false;
    }
}
if (!function_exists('set_magic_quotes_runtime')) {
    function set_magic_quotes_runtime($new_setting)
    {
        return false;
    }
}
if (!function_exists('magic_quotes_runtime')) {
    function magic_quotes_runtime($new_setting)
    {
        return false;
    }
}

if (!function_exists('is_real')) {
    function is_real($value)
    {
        return is_float($value);
    }
}

if (!function_exists('fgetss')) {
    function fgetss($handle, $length = null, $allowable_tags = null)
    {
        $line = $length === null ? fgets($handle) : fgets($handle, $length);
        return $line === false ? false : strip_tags($line, $allowable_tags);
    }
}

if (!function_exists('restore_include_path')) {
    function restore_include_path()
    {
        ini_restore('include_path');
    }
}

if (!function_exists('money_format')) {
    /** 覆盖常见的 %i / %n / %.2n 写法；不支持 locale 的货币符号 */
    function money_format($format, $number)
    {
        return preg_replace_callback('/%([=^+(!-]*\d*(?:#\d+)?)(?:\.(\d+))?([in%])/', static function ($m) use ($number) {
            if ($m[3] === '%') {
                return '%';
            }
            $decimals = $m[2] !== '' ? (int) $m[2] : 2;
            return number_format((float) $number, $decimals);
        }, $format);
    }
}

if (!function_exists('hebrevc') && function_exists('hebrev')) {
    function hebrevc($hebrew_text, $max_chars_per_line = 0)
    {
        return nl2br(hebrev($hebrew_text, $max_chars_per_line));
    }
}

if (!function_exists('convert_cyr_string')) {
    function convert_cyr_string($str, $from, $to)
    {
        $map = ['k' => 'KOI8-R', 'w' => 'Windows-1251', 'i' => 'ISO-8859-5', 'a' => 'CP866', 'd' => 'CP866', 'm' => 'MacCyrillic'];
        $f = $map[strtolower($from)] ?? null;
        $t = $map[strtolower($to)] ?? null;
        if ($f === null || $t === null || $f === $t) {
            return $str;
        }
        $r = @iconv($f, $t . '//IGNORE', $str);
        return $r === false ? $str : $r;
    }
}

if (!function_exists('ezmlm_hash')) {
    function ezmlm_hash($addr)
    {
        $h = 5381;
        $addr = strtolower($addr);
        for ($i = 0, $l = strlen($addr); $i < $l; $i++) {
            $h = (($h + ($h << 5)) ^ ord($addr[$i])) & 0xffffffff;
        }
        return $h % 53;
    }
}

if (!function_exists('read_exif_data') && function_exists('exif_read_data')) {
    function read_exif_data($file, $sections = null, $arrays = false, $thumbnail = false)
    {
        return exif_read_data($file, $sections, $arrays, $thumbnail);
    }
}

if (!function_exists('image2wbmp') && function_exists('imagewbmp')) {
    function image2wbmp($image, $filename = null, $threshold = null)
    {
        return imagewbmp($image, $filename, $threshold);
    }
}

// mbstring 的旧别名（PHP 8.0 删除）
if (function_exists('mb_ereg')) {
    foreach ([
        'mbregex_encoding' => 'mb_regex_encoding', 'mbereg' => 'mb_ereg', 'mberegi' => 'mb_eregi',
        'mbereg_replace' => 'mb_ereg_replace', 'mberegi_replace' => 'mb_eregi_replace', 'mbsplit' => 'mb_split',
        'mbereg_match' => 'mb_ereg_match', 'mbereg_search' => 'mb_ereg_search', 'mbereg_search_pos' => 'mb_ereg_search_pos',
        'mbereg_search_regs' => 'mb_ereg_search_regs', 'mbereg_search_init' => 'mb_ereg_search_init',
        'mbereg_search_getregs' => 'mb_ereg_search_getregs', 'mbereg_search_getpos' => 'mb_ereg_search_getpos',
        'mbereg_search_setpos' => 'mb_ereg_search_setpos',
    ] as $__phpup_old => $__phpup_new) {
        if (!function_exists($__phpup_old)) {
            eval('function ' . $__phpup_old . '(...$a) { return ' . $__phpup_new . '(...$a); }');
        }
    }
    unset($__phpup_old, $__phpup_new);
}

// PHP 8.1 起 mysqli 默认在出错时抛异常（MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT），
// 老代码里 mysqli_query(...) or die(...)、if (!$result) {...} 这类写法会变成未捕获异常 → 500 错误。
// 恢复 PHP 7 的默认行为：出错时返回 false，由代码自己判断。
// ---------------------------------------------------------------------------
// 运行时类型兜底（由 phpup 的 runtime_types 步骤在代码里调用）。
// PHP 8 把下面这些情况从“警告”升级成了致命错误；这些函数让结果与 PHP 7 完全一致，其余情况原样计算。
// ---------------------------------------------------------------------------

/** 非数字字符串（包括空字符串 ''）参与 + - * / % ** 运算：PHP 7 当 0 处理，PHP 8 抛 TypeError */
if (!function_exists('phpup_num')) {
    function phpup_num($v)
    {
        if (is_string($v) && !is_numeric($v) && !preg_match('/^\s*[+-]?(\d|\.\d)/', $v)) {
            return 0;
        }
        return $v;
    }
}

/** 除法：除数为 0 时 PHP 7 返回 INF / -INF / NAN（带警告），PHP 8 抛 DivisionByZeroError */
if (!function_exists('phpup_div')) {
    function phpup_div($a, $b)
    {
        $a = phpup_num($a);
        $b = phpup_num($b);
        if (($b === null || is_scalar($b)) && (float) $b == 0.0 && ($a === null || is_scalar($a))) {
            $fa = (float) $a;
            return $fa > 0 ? INF : ($fa < 0 ? -INF : NAN);
        }
        return $a / $b;
    }
}

/** count()：PHP 7 对 null 返回 0、对其他非数组返回 1（带警告），PHP 8 抛 TypeError */
if (!function_exists('phpup_count')) {
    function phpup_count($v, $mode = COUNT_NORMAL)
    {
        if (is_array($v) || $v instanceof \Countable) {
            return count($v, $mode);
        }
        return $v === null ? 0 : 1;
    }
}

/**
 * 需要数组参数的内置函数收到 null / 字符串等：PHP 7 返回 null（带警告），PHP 8 抛 TypeError。
 * 参数类型正确时与直接调用完全相同。
 */
if (!function_exists('phpup_arrfn')) {
    function phpup_arrfn($fn, ...$args)
    {
        static $spec = [
            'in_array' => [1], 'array_search' => [1], 'array_key_exists' => [1], 'key_exists' => [1],
            'array_keys' => [0], 'array_values' => [0], 'array_slice' => [0], 'array_flip' => [0],
            'array_unique' => [0], 'array_sum' => [0], 'array_product' => [0], 'array_filter' => [0], 'array_reverse' => [0],
            'array_count_values' => [0], 'array_column' => [0], 'array_chunk' => [0], 'array_fill_keys' => [0],
            'array_pad' => [0], 'array_rand' => [0], 'array_key_first' => [0], 'array_key_last' => [0],
            'array_combine' => [0, 1], 'array_change_key_case' => [0],
            'array_merge' => '*', 'array_merge_recursive' => '*', 'array_replace' => '*', 'array_diff' => '*',
            'array_diff_key' => '*', 'array_diff_assoc' => '*', 'array_intersect' => '*', 'array_intersect_key' => '*',
            'array_intersect_assoc' => '*', 'array_map' => '1+',
        ];
        $fn = strtolower($fn);
        if ($fn === 'implode' || $fn === 'join') {
            if (count($args) === 1) {
                return is_array($args[0]) ? implode($args[0]) : null;
            }
            if (is_array($args[1])) {
                return implode((string) $args[0], $args[1]);
            }
            // PHP 7 还接受 implode($数组, $分隔符) 这种反过来的写法
            return is_array($args[0]) ? implode((string) $args[1], $args[0]) : null;
        }
        if (($fn === 'array_key_exists' || $fn === 'key_exists') && isset($args[1]) && is_object($args[1])) {
            $args[1] = get_object_vars($args[1]);
        }
        $need = $spec[$fn] ?? [];
        $idx = $need === '*' ? array_keys($args) : ($need === '1+' ? array_slice(array_keys($args), 1) : $need);
        foreach ($idx as $i) {
            if (array_key_exists($i, $args) && !is_array($args[$i])) {
                return null;
            }
        }
        return $fn(...$args);
    }
}

/**
 * 文件 / 目录句柄函数收到 false（fopen / opendir 失败时）：PHP 7 只给警告，PHP 8 抛 TypeError。
 * 句柄正常时与直接调用完全相同；句柄无效时返回 false（feof 返回 true），避免 while 循环读不到结束。
 */
if (!function_exists('phpup_resfn')) {
    function phpup_resfn($fn, ...$args)
    {
        if (!array_key_exists(0, $args) || !is_resource($args[0]) && !is_object($args[0])) {
            return strtolower($fn) === 'feof';
        }
        return $fn(...$args);
    }
}

/** 文件函数收到空路径（'' 或 null）：PHP 7 警告并返回 false，PHP 8 抛 ValueError */
if (!function_exists('phpup_pathfn')) {
    function phpup_pathfn($fn, ...$args)
    {
        if (!array_key_exists(0, $args) || $args[0] === null || $args[0] === '') {
            return false;
        }
        return $fn(...$args);
    }
}

/** 内置函数参数个数不对，如 mysqli_query("BEGIN") 少了连接参数：PHP 7 警告后返回 null、什么都不做，PHP 8 抛 ArgumentCountError */
if (!function_exists('phpup_badcall')) {
    function phpup_badcall($fn, ...$args)
    {
        error_log("phpup: {$fn}() 参数个数不对（" . count($args) . " 个），按 PHP 7 的行为跳过");
        return null;
    }
}

if (!function_exists('phpup_fixcode')) {
    // 给运行时才出现的 PHP 代码补引号：$r[title] → $r['title']，只改未定义的常量。
    // 帝国CMS 会把数据库里的模板 eval() 执行，或者每次发布时重新写成 e/data/tmp/*.php 再 include，
    // 这些代码升级工具改不到，所以在运行时修。
    function phpup_fixtokens(array $tokens, $from)
    {
        $out = '';
        $n = count($tokens);
        $inStr = false;   // 在 "..." 或 heredoc 里，且不在 {$...} 里：$r[title] 本身就是合法写法
        $braces = [];
        for ($i = $from; $i < $n; $i++) {
            $t = $tokens[$i];
            if ($t === '"' || is_array($t) && ($t[0] === T_START_HEREDOC || $t[0] === T_END_HEREDOC)) {
                $inStr = !$inStr;
            } elseif (is_array($t) && ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES)) {
                $braces[] = $inStr;
                $inStr = false;
            } elseif ($t === '{') {
                $braces[] = $inStr;
            } elseif ($t === '}' && $braces) {
                $inStr = array_pop($braces);
            }
            if (!$inStr && is_array($t) && $t[0] === T_STRING) {
                $p = $i - 1;
                while ($p > 0 && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE) { $p--; }
                $q = $i + 1;
                while ($q < $n && is_array($tokens[$q]) && $tokens[$q][0] === T_WHITESPACE) { $q++; }
                $name = $t[1];
                if ($p >= 0 && $tokens[$p] === '[' && ($tokens[$q] ?? null) === ']' && !defined($name)
                    && !in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                    $out .= "'" . $name . "'";
                    continue;
                }
            }
            $out .= is_array($t) ? $t[1] : $t;
        }
        return $out;
    }

    // eval() 用：传入的是不带开头 PHP 标签的代码
    function phpup_fixcode($code)
    {
        static $cache = [];
        if (!is_string($code) || $code === '' || strpbrk($code, '[') === false) {
            return $code;
        }
        $key = md5($code);
        if (!isset($cache[$key])) {
            if (count($cache) > 500) {
                $cache = [];
            }
            $cache[$key] = phpup_fixtokens(@token_get_all('<?php ' . $code), 1);
        }
        return $cache[$key];
    }

    // 写 e/data/tmp/*.php 用：整个文件（HTML 里夹着 PHP 代码段），顺便把短标签改成完整的 PHP 标签
    function phpup_fixphpfile($content)
    {
        if (!is_string($content) || strpos($content, '<?') === false) {
            return $content;
        }
        $content = preg_replace('/<\?(?!php\b|=|xml)/i', '<?php ', $content);
        return phpup_fixtokens(@token_get_all($content), 0);
    }
}

if (!function_exists('phpup_tmpfix')) {
    // 帝国CMS 写模板缓存（e/data/tmp/ 下的 .php）时调用，其他文件原样返回
    function phpup_tmpfix($filepath, $string)
    {
        if (is_string($filepath) && preg_match('#[/\\\\]e[/\\\\]data[/\\\\]tmp[/\\\\].*\.php$#i', str_replace('\\', '/', $filepath))) {
            return phpup_fixphpfile($string);
        }
        return $string;
    }
}

// 新服务器放在反向代理 / 负载均衡后面时，PHP 收不到 $_SERVER['HTTPS']，程序会以为是 http 访问
// （帝国CMS 后台的来源检查因此直接 exit，页面空白）。代理传了 X-Forwarded-Proto 时据此补上。
if (empty($_SERVER['HTTPS']) && (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
    || strtolower($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PORT'] ?? '') === '443')) {
    $_SERVER['HTTPS'] = 'on';
}
// 同样在代理后面时，PHP 看到的是容器内部的端口（如 80/8080）和空的 SERVER_NAME，
// 第三方平台（OAuth 签名回调等）用 https://SERVER_NAME:SERVER_PORT/… 验签，会拼出 https://:8080/… 而失败。
$fwdProto = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
if ($fwdProto === 'https' || strtolower($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on') {
    $fwdPort = $_SERVER['HTTP_X_FORWARDED_PORT'] ?? '';
    $_SERVER['SERVER_PORT'] = ctype_digit($fwdPort) ? $fwdPort : '443';
} elseif ($fwdProto === 'http') {
    // 代理收到的是 http 请求（例如平台用 http 地址回调），按 http://域名/… 验签
    $fwdPort = $_SERVER['HTTP_X_FORWARDED_PORT'] ?? '';
    $_SERVER['SERVER_PORT'] = ctype_digit($fwdPort) ? $fwdPort : '80';
    if (($_SERVER['HTTPS'] ?? '') === 'on') {
        unset($_SERVER['HTTPS']);
    }
} elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' && ($_SERVER['SERVER_PORT'] ?? '') == '80') {
    $_SERVER['SERVER_PORT'] = '443';
}
if (($_SERVER['SERVER_NAME'] ?? '') === '' && !empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['SERVER_NAME'] = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']);
}

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}
