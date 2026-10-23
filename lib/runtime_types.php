<?php

/**
 * 运行时类型修复：PHP 8 把下面几类“警告”升级成了致命错误，静态检测工具发现不了，上线后才会白屏 / 500。
 *
 *   1. 非数字字符串（包括空字符串 ''）参与 + - * / % ** 运算        PHP 7 当 0 处理  → PHP 8 TypeError
 *   2. 除数为 0                                                      PHP 7 得到 INF    → PHP 8 DivisionByZeroError
 *   3. count() / in_array() / array_merge() / implode() 等收到 null   PHP 7 返回 null   → PHP 8 TypeError
 *   4. 没加引号的常量名，如 function_exists(getcvar)                 PHP 7 当字符串    → PHP 8 Error
 *   5. 内置函数参数个数不对，如 mysqli_query("BEGIN")                PHP 7 返回 null  → PHP 8 ArgumentCountError
 *
 * 做法：1~3 把“无法确定类型”的操作数 / 参数交给兼容层里的 phpup_num()、phpup_div()、phpup_count()、phpup_arrfn()，
 * 这几个函数在类型正确时与直接计算完全相同，只在 PHP 8 会报致命错误的情况下返回 PHP 7 的结果。
 * 能确定类型的（数字、(int) 强制转换、count() 的结果、只被赋过数字的局部变量、array 类型的参数等）不改，减少改动。
 * 用到这些函数的文件，开头会加一行 require_once 站点根目录的 phpup_compat.php，保证不配 php.ini 也能运行。
 * 4 把站点里（以及 PHP 自身）都没有定义、且含小写字母的常量名改成同名字符串（与 PHP 7 的实际行为相同）。
 *
 * 用法: php runtime_types.php <目录> <输出json> [排除通配符,逗号分隔]
 * 输出: {"fixed": {"文件": {"num": n, "div": n, "count": n, "arr": n, "const": n}}, "constants": {"名称": 次数}}
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/rector/rector/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;

ini_set('memory_limit', '-1');
[$self, $dir, $out] = $argv + [null, null, null];
$exclude = array_filter(explode(',', $argv[3] ?? ''));
$dir = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', (string) realpath((string) $dir)), '/');
$parser = (new ParserFactory())->createForNewestSupportedVersion();

const COMPAT = 'phpup_compat.php';

// 结果一定是数字（或 bool / null，参与运算不会报错）的内置函数
const NUM_FUNCS = ['count', 'sizeof', 'strlen', 'mb_strlen', 'intval', 'floatval', 'doubleval', 'boolval', 'abs', 'round', 'floor',
    'ceil', 'time', 'mktime', 'gmmktime', 'strtotime', 'rand', 'mt_rand', 'random_int', 'ord', 'intdiv', 'fmod', 'pow', 'sqrt',
    'strpos', 'stripos', 'strrpos', 'strripos', 'mb_strpos', 'mb_stripos', 'mb_strrpos', 'filesize', 'filemtime', 'crc32',
    'hexdec', 'bindec', 'octdec', 'ip2long', 'memory_get_usage', 'memory_get_peak_usage', 'array_sum', 'array_product',
    'substr_count', 'mb_substr_count', 'str_word_count', 'is_numeric', 'is_array', 'is_string', 'is_int', 'is_null', 'isset',
    'in_array', 'array_key_exists', 'function_exists', 'method_exists', 'file_exists', 'is_file', 'is_dir', 'strcmp',
    'strcasecmp', 'strncmp', 'strncasecmp', 'version_compare', 'preg_match', 'preg_match_all', 'checkdate', 'date_default_timezone_set',
    'ctype_digit', 'is_object', 'is_bool', 'is_float', 'is_scalar', 'empty', 'deg2rad', 'rad2deg', 'log', 'log10', 'exp',
    'sin', 'cos', 'tan', 'pi', 'hypot', 'max', 'min', 'gettimeofday', 'hrtime', 'getmypid', 'func_num_args', 'connection_status',
    'phpup_num', 'phpup_div', 'phpup_count', 'phpup_resfn', 'phpup_pathfn', 'mt_getrandmax', 'getrandmax', 'error_reporting', 'ignore_user_abort', 'levenshtein', 'similar_text', 'array_search'];
// 结果一定是数组的内置函数
const ARR_FUNCS = ['explode', 'array_keys', 'array_values', 'array_merge', 'array_merge_recursive', 'array_slice', 'array_map',
    'array_filter', 'array_unique', 'array_reverse', 'array_flip', 'array_fill', 'array_fill_keys', 'array_pad', 'array_chunk',
    'array_column', 'array_diff', 'array_diff_key', 'array_diff_assoc', 'array_intersect', 'array_intersect_key',
    'array_intersect_assoc', 'array_replace', 'range', 'compact', 'str_split', 'mb_str_split', 'preg_split', 'func_get_args',
    'get_object_vars', 'array_count_values', 'array_change_key_case', 'preg_grep', 'str_getcsv', 'array_splice', 'getdate',
    'localeconv', 'get_class_methods', 'array_combine', 'iterator_to_array', 'get_defined_vars', 'array_unique'];
// phpup_arrfn 处理的函数 => 必须是数组的参数位置（'*' = 全部，'1+' = 第 2 个起）
const ARR_SPEC = [
    'in_array' => [1], 'array_search' => [1], 'array_key_exists' => [1], 'key_exists' => [1],
    'array_keys' => [0], 'array_values' => [0], 'array_slice' => [0], 'array_flip' => [0],
    'array_unique' => [0], 'array_sum' => [0], 'array_product' => [0], 'array_filter' => [0], 'array_reverse' => [0],
    'array_count_values' => [0], 'array_column' => [0], 'array_chunk' => [0], 'array_fill_keys' => [0],
    'array_pad' => [0], 'array_rand' => [0], 'array_key_first' => [0], 'array_key_last' => [0],
    'array_combine' => [0, 1], 'array_change_key_case' => [0],
    'array_merge' => '*', 'array_merge_recursive' => '*', 'array_replace' => '*', 'array_diff' => '*',
    'array_diff_key' => '*', 'array_diff_assoc' => '*', 'array_intersect' => '*', 'array_intersect_key' => '*',
    'array_intersect_assoc' => '*', 'array_map' => '1+', 'implode' => 'implode', 'join' => 'implode',
];
// 第 1 个参数是文件 / 目录句柄的函数：fopen() / opendir() 失败返回 false 时 PHP 8 抛 TypeError
const RES_FUNCS = ['fread', 'fwrite', 'fputs', 'fclose', 'fgets', 'fgetc', 'feof', 'fseek', 'ftell', 'rewind', 'flock',
    'fgetcsv', 'fputcsv', 'ftruncate', 'fflush', 'fpassthru', 'fstat', 'readdir', 'closedir', 'rewinddir',
    'stream_get_contents', 'stream_set_timeout', 'stream_set_blocking', 'stream_get_meta_data', 'pclose', 'gzclose',
    'gzread', 'gzwrite', 'gzgets', 'gzeof'];
// 第 1 个参数是路径、空路径时 PHP 8 抛 ValueError 的函数
const PATH_FUNCS = ['file_get_contents', 'file_put_contents', 'fopen', 'file', 'readfile', 'opendir', 'scandir', 'md5_file',
    'sha1_file', 'hash_file', 'parse_ini_file'];
// 常见扩展的常量前缀（本机 PHP 可能没装这些扩展，不能当成未定义）
const EXT_CONST_PREFIX = '/^(MYSQLI?|PDO|CURL|GD|IMG|IMAGETYPE|EXIF|INTL|IDNA|U_|GRAPHEME|LIBXML|XML|XSL|JSON|PREG|SODIUM|OPENSSL|PHP|E|ZEND|ZIP|ZLIB|FILEINFO|FILTER|MB|ICONV|LDAP|SOAP|ENT|SORT|COUNT|GLOB|LC|LOG|FTP|SSH2|MEMCACHE|MEMCACHED|REDIS|IMAP|PGSQL|SQLITE3?|BCMATH|GMP|INI|STR|PATHINFO|PASSWORD|CRYPT|MCRYPT|PSFS|SEEK|STREAM|FILE|LOCK|SOCK|SO|SOL|AF|IPPROTO|MSG|PREG|T|DATE|SUNFUNCS|CAL|DNS|CONNECTION|UPLOAD|ARRAY|EXTR|CASE|HTML|DEBUG|ASSERT|DIRECTORY|PATH|M|NAN|INF|SID|SIGTERM|SIG|WNOHANG|PCNTL|POSIX|YAML|IGBINARY|MSGPACK|APCU?|OPCACHE|XDEBUG|GEOIP|IMAGICK|GMAGICK|FPM|RAR|BZ|DOM|TIDY|PSPELL|ENCHANT|SNMP|SYSVMSG|SHM|EV|EVENT|UV|SWOOLE|OAUTH|AMQP|MONGO|COM|DISP|VT|CP)_/';
const ARITH = [Expr\BinaryOp\Plus::class, Expr\BinaryOp\Minus::class, Expr\BinaryOp\Mul::class, Expr\BinaryOp\Mod::class,
    Expr\BinaryOp\Pow::class];
const ARITH_ASSIGN = [Expr\AssignOp\Plus::class => '+', Expr\AssignOp\Minus::class => '-', Expr\AssignOp\Mul::class => '*',
    Expr\AssignOp\Mod::class => '%', Expr\AssignOp\Pow::class => '**', Expr\AssignOp\Div::class => '/'];

/** 遍历代码文件 */
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || !preg_match('/\.(php|inc|phtml)$/i', $f->getFilename())) {
        continue;
    }
    $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($f->getPathname(), strlen($dir) + 1));
    $editable = $rel !== COMPAT;
    foreach ($exclude as $pattern) {
        if (fnmatch($pattern, '/' . $rel)) {
            $editable = false;
        }
    }
    $files[$rel] = $editable;
}

// ---------------------------------------------------------------------------
// 第一遍：收集站点里定义过的常量（define('X') / const X），所有文件都看，包括排除的目录
// ---------------------------------------------------------------------------
$defined = [];
$finder = new PhpParser\NodeFinder();
foreach (array_keys($files) as $rel) {
    $code = (string) @file_get_contents("{$dir}/{$rel}");
    // 只认 PHP 代码里的 define() / const（页面里嵌的 JavaScript 也有 const，不能算）
    if (!preg_match('/\b(define|const)\b/i', $code) || str_contains($code, "\0")) {
        continue;
    }
    try {
        $ast = $parser->parse($code) ?? [];
    } catch (\Throwable) {
        continue;
    }
    foreach ($finder->find($ast, static fn (Node $n) => $n instanceof Expr\FuncCall || $n instanceof Stmt\Const_) as $n) {
        if ($n instanceof Stmt\Const_) {
            foreach ($n->consts as $c) {
                $defined[strtolower($c->name->toString())] = true;
            }
        } elseif ($n->name instanceof Node\Name && strtolower($n->name->getLast()) === 'define'
            && isset($n->args[0]) && $n->args[0] instanceof Node\Arg && $n->args[0]->value instanceof Scalar\String_) {
            $defined[strtolower($n->args[0]->value->value)] = true;
        }
    }
}
unset($ast);
foreach ([...array_keys(get_defined_constants()), 'true', 'false', 'null', '__COMPILER_HALT_OFFSET__'] as $n) {
    $defined[strtolower($n)] = true;
}

// ---------------------------------------------------------------------------
// 工具函数
// ---------------------------------------------------------------------------
$byRef = [];   // 内置函数名 => [按引用传递的参数位置]
$argRange = [];   // 内置函数名 => [最少参数个数, 最多参数个数(null=不限)]
$argc = static function (string $fn) use (&$argRange): ?array {
    if (!array_key_exists($fn, $argRange)) {
        $argRange[$fn] = null;
        if (function_exists($fn) && ($r = new ReflectionFunction($fn))->isInternal()) {
            $argRange[$fn] = [$r->getNumberOfRequiredParameters(), $r->isVariadic() ? null : $r->getNumberOfParameters()];
        }
    }
    return $argRange[$fn];
};
$refParams = static function (string $fn) use (&$byRef): ?array {
    $fn = strtolower($fn);
    if (!array_key_exists($fn, $byRef)) {
        $byRef[$fn] = null;
        if (function_exists($fn)) {
            $r = new ReflectionFunction($fn);
            if ($r->isInternal()) {
                $byRef[$fn] = [];
                foreach ($r->getParameters() as $i => $p) {
                    if ($p->isPassedByReference()) {
                        $byRef[$fn][] = $p->isVariadic() ? '*' . $i : $i;
                    }
                }
            }
        }
    }
    return $byRef[$fn];
};

/** 取函数名（只认不带命名空间的普通函数调用） */
function fname(Expr\FuncCall $c): ?string
{
    if (!$c->name instanceof Node\Name) {
        return null;
    }
    $n = $c->name->toString();
    return str_contains($n, '\\') && !str_starts_with($c->name->toCodeString(), '\\') ? null : strtolower(ltrim($n, '\\'));
}

/** 参数里有没有 ...展开 或 命名参数 */
function plainArgs(Expr\FuncCall $c): bool
{
    if ($c->isFirstClassCallable()) {
        return false;
    }
    foreach ($c->args as $a) {
        if (!$a instanceof Node\Arg || $a->unpack || $a->name !== null) {
            return false;
        }
    }
    return true;
}

/**
 * 列出一个作用域里的节点（不进入嵌套的函数 / 类），嵌套的函数 / 方法收集到 $nested。
 * 常量表达式（参数默认值、static 变量初值、const、declare）不进入。
 */
function scopeNodes(array $nodes, array &$nested): array
{
    $list = [];
    $stack = array_reverse($nodes);
    while ($stack) {
        $n = array_pop($stack);
        if (!$n instanceof Node) {
            continue;
        }
        if ($n instanceof Node\FunctionLike) {
            $nested[] = $n;
            $list[] = $n;     // 函数本身作为表达式（闭包）出现在当前作用域
            if ($n instanceof Expr\Closure) {
                foreach ($n->uses as $u) {
                    $list[] = $u;
                }
            }
            continue;
        }
        if ($n instanceof Stmt\ClassLike) {
            foreach ($n->getMethods() as $m) {
                $nested[] = $m;
            }
            continue;
        }
        if ($n instanceof Stmt\Const_ || $n instanceof Stmt\Declare_ && $n->stmts === null || $n instanceof Node\Param
            || $n instanceof Node\AttributeGroup) {
            continue;
        }
        $list[] = $n;
        if ($n instanceof Stmt\Static_) {
            foreach ($n->vars as $v) {
                $list[] = $v->var;
            }
            continue;
        }
        if ($n instanceof Expr\New_ && $n->class instanceof Stmt\Class_) {
            foreach ($n->class->getMethods() as $m) {
                $nested[] = $m;
            }
            foreach (array_reverse($n->args) as $a) {
                $stack[] = $a;
            }
            continue;
        }
        $children = [];
        foreach ($n->getSubNodeNames() as $name) {
            $sub = $n->$name;
            if ($sub instanceof Node) {
                $children[] = $sub;
            } elseif (is_array($sub)) {
                foreach ($sub as $s) {
                    if ($s instanceof Node) {
                        $children[] = $s;
                    }
                }
            }
        }
        foreach (array_reverse($children) as $c) {
            $stack[] = $c;
        }
    }
    return $list;
}

/** 一定是非空字符串：'abc'、__DIR__、含非空字面量的拼接 */
function nonEmptyStr(Node $e): bool
{
    if ($e instanceof Scalar\String_) {
        return $e->value !== '';
    }
    if ($e instanceof Scalar\MagicConst\Dir || $e instanceof Scalar\MagicConst\File) {
        return true;
    }
    if ($e instanceof Expr\BinaryOp\Concat) {
        return nonEmptyStr($e->left) || nonEmptyStr($e->right);
    }
    if ($e instanceof Scalar\InterpolatedString) {
        foreach ($e->parts as $p) {
            if ($p instanceof Node\InterpolatedStringPart && $p->value !== '') {
                return true;
            }
        }
    }
    return false;
}

function varName(Node $n): ?string
{
    return $n instanceof Expr\Variable && is_string($n->name) ? $n->name : null;
}

/** 简单表达式（可以重复求值、没有副作用）：$a、$a['x']、$a->b、常量 */
function isSimple(Node $e): bool
{
    if ($e instanceof Expr\Variable) {
        return is_string($e->name);
    }
    if ($e instanceof Expr\ArrayDimFetch) {
        return $e->dim !== null && isSimple($e->var) && ($e->dim instanceof Scalar || isSimple($e->dim));
    }
    if ($e instanceof Expr\PropertyFetch) {
        return $e->name instanceof Node\Identifier && isSimple($e->var);
    }
    if ($e instanceof Expr\StaticPropertyFetch) {
        return $e->class instanceof Node\Name && $e->name instanceof Node\VarLikeIdentifier;
    }
    return $e instanceof Expr\ConstFetch || $e instanceof Expr\ClassConstFetch;
}

// ---------------------------------------------------------------------------
// 第二遍：逐个文件处理
// ---------------------------------------------------------------------------
$result = ['fixed' => [], 'constants' => []];
foreach ($files as $rel => $editable) {
    if (!$editable) {
        continue;
    }
    $path = "{$dir}/{$rel}";
    $code = (string) file_get_contents($path);
    if (str_contains($code, "\0")) {
        continue;
    }
    try {
        $ast = $parser->parse($code) ?? [];
    } catch (\Throwable) {
        continue;
    }
    $namespaced = false;
    foreach ($ast as $s) {
        if ($s instanceof Stmt\Namespace_) {
            if ($s->getAttribute('kind') !== Stmt\Namespace_::KIND_SEMICOLON) {
                continue 2;   // 花括号形式的命名空间：跳过整个文件
            }
            $namespaced = true;
        }
    }
    // 运算符的位置（左右操作数之间可能有括号、注释，按 token 找）
    $tokens = [];
    $offset = 0;
    foreach (PhpToken::tokenize($code) as $tok) {
        $tokens[] = [$offset, $tok->text, $tok->id];
        $offset += strlen($tok->text);
    }
    $opAt = static function (int $from, int $to, string $op) use ($tokens): ?int {
        foreach ($tokens as [$pos, $text]) {
            if ($pos >= $to) {
                break;
            }
            if ($pos >= $from && $text === $op) {
                return $pos;
            }
        }
        return null;
    };

    $edits = [];   // [pos, kind(0=后缀,1=替换,2=前缀), 长度(排序用), 替换结束位置, 文本]
    $stat = ['num' => 0, 'div' => 0, 'count' => 0, 'arr' => 0, 'res' => 0, 'const' => 0, 'argc' => 0];
    $wrap = static function (Node $n, string $pre, string $post) use (&$edits): void {
        $s = $n->getStartFilePos();
        $e = $n->getEndFilePos() + 1;
        $edits[] = [$s, 2, $e - $s, $s, $pre];
        $edits[] = [$e, 0, $e - $s, $e, $post];
    };
    $replace = static function (int $from, int $to, string $text) use (&$edits): void {   // [from, to)
        $edits[] = [$from, 1, 0, $to, $text];
    };
    $src = static fn (Node $n): string => substr($code, $n->getStartFilePos(), $n->getEndFilePos() - $n->getStartFilePos() + 1);

    // 作用域队列：[stmts(数组), params, 是否顶层, 是否箭头函数]
    $queue = [[$ast, [], true, false]];
    while ($queue) {
        [$stmts, $params, $top, $arrow] = array_shift($queue);
        $nested = [];
        $nodes = scopeNodes($stmts, $nested);
        foreach ($nested as $fn) {
            if ($fn instanceof Expr\ArrowFunction) {
                $queue[] = [[$fn->expr], $fn->params, false, true];
            } elseif ($fn->getStmts() !== null) {
                $queue[] = [$fn->getStmts(), $fn->getParams(), false, false];
            }
        }

        // ---- 变量分析 ----
        $dirty = $top || $arrow;        // 顶层变量可能被别的文件改动；箭头函数里的外部变量不可知
        $bad = ['this' => true, 'GLOBALS' => true, '_GET' => true, '_POST' => true, '_REQUEST' => true, '_COOKIE' => true,
            '_SERVER' => true, '_SESSION' => true, '_FILES' => true, '_ENV' => true, 'http_response_header' => true, 'php_errormsg' => true];
        $assigns = [];     // 变量 => [表达式|'NUM'|'ARRW'(下标写入)]
        $domArr = [];      // 变量 => [在作用域顶层语句中、无条件赋数组的位置]
        $numParam = [];
        $arrParam = [];
        foreach ($params as $p) {
            $n = varName($p->var);
            if ($n === null) {
                continue;
            }
            $t = $p->type instanceof Node\Identifier ? strtolower($p->type->toString()) : null;
            $nullDefault = $p->default instanceof Expr\ConstFetch && strtolower($p->default->name->toString()) === 'null';
            if ($t === 'array' && !$nullDefault && !$p->byRef && !$p->variadic) {
                $arrParam[$n] = true;
            } elseif (in_array($t, ['int', 'float'], true) && !$nullDefault && !$p->byRef) {
                $numParam[$n] = true;
            } elseif ($p->variadic && !$p->byRef) {
                $arrParam[$n] = true;
            }
            $bad[$n] = true;
        }
        $topStmts = [];
        foreach ($stmts as $s) {
            if ($s instanceof Stmt\Expression) {
                $topStmts[spl_object_id($s->expr)] = true;
            }
        }
        foreach ($nodes as $n) {
            if ($n instanceof Expr\Variable && !is_string($n->name)) {
                $dirty = true;                          // $$name
            } elseif ($n instanceof Expr\Include_ || $n instanceof Expr\Eval_) {
                $dirty = true;
            } elseif ($n instanceof Expr\FuncCall) {
                $fn = fname($n);
                if ($fn === null || in_array($fn, ['extract', 'parse_str', 'get_defined_vars', 'mb_parse_str'], true)) {
                    $dirty = $dirty || $fn !== null || !$n->name instanceof Node\Name;
                }
                $refs = $fn !== null ? $refParams($fn) : null;
                foreach ($n->args as $i => $a) {
                    if (!$a instanceof Node\Arg) {
                        continue;
                    }
                    $v = varName($a->value);
                    if ($v === null) {
                        continue;
                    }
                    if ($refs === null || in_array($i, $refs, true) || array_filter($refs, static fn ($r) => is_string($r) && $i >= (int) substr($r, 1))) {
                        $bad[$v] = true;               // 可能按引用被修改
                    }
                }
            } elseif ($n instanceof Expr\MethodCall || $n instanceof Expr\StaticCall || $n instanceof Expr\New_
                || $n instanceof Expr\NullsafeMethodCall) {
                foreach ($n->isFirstClassCallable() ? [] : $n->args as $a) {
                    if ($a instanceof Node\Arg && ($v = varName($a->value)) !== null) {
                        $bad[$v] = true;
                    }
                }
            } elseif ($n instanceof Stmt\Global_ || $n instanceof Stmt\Static_) {
                foreach ($n->vars as $v) {
                    if (($name = varName($v instanceof Node\StaticVar ? $v->var : $v)) !== null) {
                        $bad[$name] = true;
                    }
                }
            } elseif ($n instanceof Node\ClosureUse) {
                if (($name = varName($n->var)) !== null) {
                    $bad[$name] = true;
                }
            } elseif ($n instanceof Expr\AssignRef) {
                foreach ([$n->var, $n->expr] as $x) {
                    if (($name = varName($x)) !== null) {
                        $bad[$name] = true;
                    }
                }
            } elseif ($n instanceof Stmt\Foreach_) {
                foreach ([$n->keyVar, $n->valueVar] as $x) {
                    if ($x === null) {
                        continue;
                    }
                    if (($name = varName($x)) !== null) {
                        $bad[$name] = true;
                    }
                    if ($x instanceof Expr\List_ || $x instanceof Expr\Array_) {
                        foreach ((new PhpParser\NodeFinder())->findInstanceOf([$x], Expr\Variable::class) as $vv) {
                            if (($name = varName($vv)) !== null) {
                                $bad[$name] = true;
                            }
                        }
                    }
                }
            } elseif ($n instanceof Stmt\Catch_ && $n->var !== null) {
                $bad[(string) $n->var->name] = true;
            } elseif ($n instanceof Stmt\Unset_) {
                foreach ($n->vars as $x) {
                    if (($name = varName($x)) !== null) {
                        $assigns[$name][] = 'NULL';
                    }
                }
            } elseif ($n instanceof Expr\Assign) {
                $t = $n->var;
                if (($name = varName($t)) !== null) {
                    $assigns[$name][] = $n->expr;
                    if (isset($topStmts[spl_object_id($n)])) {
                        $domArr[$name][] = [$n->getStartFilePos(), $n->expr];
                    }
                } elseif ($t instanceof Expr\List_ || $t instanceof Expr\Array_) {
                    foreach ((new PhpParser\NodeFinder())->findInstanceOf([$t], Expr\Variable::class) as $vv) {
                        if (($name = varName($vv)) !== null) {
                            $bad[$name] = true;
                        }
                    }
                } else {
                    // $x[..] = v / $x->p = v
                    $base = $t;
                    while ($base instanceof Expr\ArrayDimFetch || $base instanceof Expr\PropertyFetch) {
                        if ($base instanceof Expr\PropertyFetch) {
                            $base = $base->var;
                            if (($name = varName($base)) !== null) {
                                $bad[$name] = true;
                            }
                            continue;
                        }
                        $base = $base->var;
                    }
                    if ($t instanceof Expr\ArrayDimFetch && ($name = varName($base)) !== null) {
                        $assigns[$name][] = 'ARRW';
                        if (isset($topStmts[spl_object_id($n)]) && $t->var === $base) {
                            $domArr[$name][] = [$n->getStartFilePos(), 'ARRW'];
                        }
                    }
                }
            } elseif ($n instanceof Expr\AssignOp) {
                if (($name = varName($n->var)) !== null) {
                    $assigns[$name][] = isset(ARITH_ASSIGN[$n::class]) || !$n instanceof Expr\AssignOp\Concat
                        && !$n instanceof Expr\AssignOp\Coalesce ? 'NUM' : ($n instanceof Expr\AssignOp\Coalesce ? $n->expr : 'STR');
                } else {
                    $base = $n->var;
                    while ($base instanceof Expr\ArrayDimFetch || $base instanceof Expr\PropertyFetch) {
                        $base = $base->var;
                    }
                    if (($name = varName($base)) !== null) {
                        $assigns[$name][] = 'ARRW';
                    }
                }
            } elseif ($n instanceof Expr\PreInc || $n instanceof Expr\PreDec || $n instanceof Expr\PostInc || $n instanceof Expr\PostDec) {
                if (varName($n->var) === null) {
                    $base = $n->var;
                    while ($base instanceof Expr\ArrayDimFetch || $base instanceof Expr\PropertyFetch) {
                        $base = $base->var;
                    }
                    if (($name = varName($base)) !== null) {
                        $assigns[$name][] = 'ARRW';
                    }
                }
            }
        }

        // ---- 类型判断 ----
        $numVars = [];
        $arrVars = [];
        $isNum = null;
        $isArr = null;
        $isNum = static function (Node $e) use (&$isNum, &$numVars, &$numParam): bool {
            if ($e instanceof Scalar\Int_ || $e instanceof Scalar\Float_ || $e instanceof Scalar\MagicConst\Line) {
                return true;
            }
            if ($e instanceof Scalar\String_) {
                return is_numeric($e->value);
            }
            if ($e instanceof Expr\Cast\Int_ || $e instanceof Expr\Cast\Double || $e instanceof Expr\Cast\Bool_
                || $e instanceof Expr\Isset_ || $e instanceof Expr\Empty_ || $e instanceof Expr\Instanceof_
                || $e instanceof Expr\BooleanNot || $e instanceof Expr\UnaryMinus || $e instanceof Expr\UnaryPlus) {
                return true;
            }
            if ($e instanceof Expr\BinaryOp) {
                if ($e instanceof Expr\BinaryOp\Concat) {
                    return false;
                }
                if ($e instanceof Expr\BinaryOp\Coalesce) {
                    return $isNum($e->left) && $isNum($e->right);
                }
                return true;     // 算术（改写后不会出错）、比较、逻辑、位运算
            }
            if ($e instanceof Expr\ConstFetch || $e instanceof Expr\ClassConstFetch) {
                return true;
            }
            if ($e instanceof Expr\FuncCall) {
                $fn = fname($e);
                return $fn !== null && in_array($fn, NUM_FUNCS, true);
            }
            if ($e instanceof Expr\Variable) {
                return is_string($e->name) && (isset($numVars[$e->name]) || isset($numParam[$e->name]));
            }
            if ($e instanceof Expr\PreInc || $e instanceof Expr\PreDec || $e instanceof Expr\PostInc || $e instanceof Expr\PostDec) {
                return $isNum($e->var);
            }
            if ($e instanceof Expr\Ternary) {
                return ($e->if === null || $isNum($e->if)) && $isNum($e->else) && ($e->if !== null || $isNum($e->cond));
            }
            if ($e instanceof Expr\Assign) {
                return $isNum($e->expr);
            }
            if ($e instanceof Expr\AssignOp) {
                return isset(ARITH_ASSIGN[$e::class]);
            }
            if ($e instanceof Expr\ErrorSuppress) {
                return $isNum($e->expr);
            }
            return false;
        };
        $wrapsArr = null;   // 某个调用是否会被改写成 phpup_arrfn（改写后可能返回 null）
        $isArr = static function (Node $e, int $pos) use (&$isArr, &$arrVars, &$arrParam, &$domArr, &$wrapsArr): bool {
            if ($e instanceof Expr\Array_ || $e instanceof Expr\Cast\Array_) {
                return true;
            }
            if ($e instanceof Expr\FuncCall) {
                $fn = fname($e);
                return $fn !== null && in_array($fn, ARR_FUNCS, true) && !$wrapsArr($e);
            }
            if ($e instanceof Expr\Variable) {
                if (!is_string($e->name)) {
                    return false;
                }
                if (isset($arrParam[$e->name])) {
                    return isset($arrVars[$e->name]) || !isset($domArr[$e->name]);
                }
                if (!isset($arrVars[$e->name])) {
                    return false;
                }
                foreach ($domArr[$e->name] ?? [] as [$p]) {
                    if ($p < $pos) {
                        return true;
                    }
                }
                return false;
            }
            if ($e instanceof Expr\Ternary) {
                return $e->if !== null && $isArr($e->if, $pos) && $isArr($e->else, $pos);
            }
            if ($e instanceof Expr\Assign) {
                return $isArr($e->expr, $pos);
            }
            return false;
        };
        $wrapsArr = static function (Expr\FuncCall $c) use (&$isArr): bool {
            $fn = fname($c);
            if ($fn === null || !isset(ARR_SPEC[$fn]) || !plainArgs($c) || $c->args === []) {
                return false;
            }
            // 回调参数（array_map 第 1 个、array_filter 第 2 个）是 [$this, 'x'] / 'self::x' / 变量时，
            // 改成从 phpup_arrfn() 里调用会丢掉类作用域，private / protected 方法就调不到了 → 不改
            $cbAt = $fn === 'array_map' ? 0 : ($fn === 'array_filter' ? 1 : null);
            if ($cbAt !== null && isset($c->args[$cbAt])) {
                $cb = $c->args[$cbAt]->value;
                $safeCb = $cb instanceof Expr\Closure || $cb instanceof Expr\ArrowFunction
                    || ($cb instanceof Expr\ConstFetch && strtolower($cb->name->toString()) === 'null')
                    || ($cb instanceof Node\Scalar\String_ && !str_contains($cb->value, '::'));
                if (!$safeCb) {
                    return false;
                }
            }
            $spec = ARR_SPEC[$fn];
            $pos = $c->getStartFilePos();
            if ($spec === 'implode') {
                // implode($分隔符, $数组)；PHP 7 还接受 implode($数组, $分隔符)，PHP 8 不再接受
                return !$isArr($c->args[count($c->args) === 1 ? 0 : 1]->value, $pos);
            }
            $idx = $spec === '*' ? array_keys($c->args) : ($spec === '1+' ? array_slice(array_keys($c->args), 1) : $spec);
            foreach ($idx as $i) {
                if (isset($c->args[$i]) && !$isArr($c->args[$i]->value, $pos)) {
                    return true;
                }
            }
            return false;
        };

        if (!$dirty) {
            // 不动点：先假设所有局部变量都安全，逐步剔除被赋过不安全值的
            foreach ($assigns as $name => $list) {
                if (!isset($bad[$name])) {
                    $numVars[$name] = true;
                    $arrVars[$name] = true;
                }
            }
            foreach ($arrParam as $name => $_) {
                $arrVars[$name] = true;
            }
            do {
                $changed = false;
                foreach ($numVars as $name => $_) {
                    foreach ($assigns[$name] ?? [] as $x) {
                        if ($x === 'NUM' || $x === 'NULL') {
                            continue;
                        }
                        if (!$x instanceof Node || !$isNum($x)) {
                            unset($numVars[$name]);
                            $changed = true;
                            break;
                        }
                    }
                }
                foreach ($arrVars as $name => $_) {
                    foreach ($assigns[$name] ?? [] as $x) {
                        if ($x === 'ARRW') {
                            continue;
                        }
                        if (!$x instanceof Node || !$isArr($x, PHP_INT_MAX)) {
                            unset($arrVars[$name]);
                            $changed = true;
                            break;
                        }
                    }
                }
            } while ($changed);
            // 从未赋值的局部变量 = null，参与运算没问题
            foreach ($nodes as $n) {
                if (($name = varName($n)) !== null && !isset($bad[$name]) && !isset($assigns[$name])) {
                    $numVars[$name] = true;
                }
            }
        }

        // ---- 改写 ----
        $num = static function (Node $e) use ($wrap, &$stat): void {
            $wrap($e, '\phpup_num(', ')');
            $stat['num']++;
        };
        $dimKeys = [];   // 作为数组下标出现的常量：$_SERVER[REQUEST_URI]、$r[ID]
        foreach ($nodes as $n) {
            if ($n instanceof Expr\ArrayDimFetch && $n->dim instanceof Expr\ConstFetch) {
                $dimKeys[spl_object_id($n->dim)] = true;
            }
            if ($n instanceof Expr\BinaryOp && in_array($n::class, ARITH, true)) {
                foreach ([$n->left, $n->right] as $side) {
                    if (!$isNum($side)) {
                        $num($side);
                    }
                }
            } elseif ($n instanceof Expr\BinaryOp\Div) {
                if ($isNum($n->left) && ($n->right instanceof Scalar\Int_ || $n->right instanceof Scalar\Float_) && $n->right->value != 0) {
                    continue;
                }
                // A / B  →  \phpup_div(A , B)
                $at = $opAt($n->left->getEndFilePos() + 1, $n->right->getStartFilePos(), '/');
                if ($at === null) {
                    continue;
                }
                $wrap($n, '\phpup_div(', ')');
                $replace($at, $at + 1, ',');
                $stat['div']++;
            } elseif (($n instanceof Expr\UnaryMinus || $n instanceof Expr\UnaryPlus) && !$isNum($n->expr)) {
                $num($n->expr);
            } elseif ($n instanceof Expr\AssignOp && isset(ARITH_ASSIGN[$n::class])) {
                $op = ARITH_ASSIGN[$n::class];
                $targetSafe = $isNum($n->var);
                $at = $opAt($n->var->getEndFilePos() + 1, $n->expr->getStartFilePos(), $op . '=');
                if ($at === null) {
                    continue;
                }
                if ($op === '/') {
                    if (!isSimple($n->var)) {
                        continue;
                    }
                    // $x /= B  →  $x = \phpup_div($x, B)
                    $replace($at, $at + strlen($op) + 1, '= \phpup_div(' . $src($n->var) . ',');
                    $edits[] = [$n->expr->getEndFilePos() + 1, 0, PHP_INT_MAX, 0, ')'];
                    $stat['div']++;
                    continue;
                }
                if (!$targetSafe && isSimple($n->var)) {
                    // $x += B  →  $x = \phpup_num($x) + B（$x 可能是 '' 之类）
                    $replace($at, $at + strlen($op) + 1, '= \phpup_num(' . $src($n->var) . ') ' . $op);
                    $stat['num']++;
                }
                if (!$isNum($n->expr)) {
                    $num($n->expr);
                }
            } elseif ($n instanceof Expr\FuncCall && plainArgs($n)) {
                $fn = fname($n);
                $range = $fn === null ? null : $argc($fn);
                if ($range !== null && (count($n->args) < $range[0] || $range[1] !== null && count($n->args) > $range[1])) {
                    // mysqli_query("BEGIN")  →  \phpup_badcall('mysqli_query', "BEGIN")
                    $replace($n->name->getStartFilePos(), $n->name->getEndFilePos() + 1, '\phpup_badcall');
                    if ($n->args === []) {
                        $close = $opAt($n->name->getEndFilePos() + 1, $n->getEndFilePos() + 1, ')');
                        $edits[] = [$close, 2, PHP_INT_MAX, 0, var_export($fn, true)];
                    } else {
                        $edits[] = [$n->args[0]->getStartFilePos(), 2, PHP_INT_MAX, 0, var_export($fn, true) . ', '];
                    }
                    $stat['argc']++;
                } elseif (($fn === 'count' || $fn === 'sizeof') && $n->args !== [] && count($n->args) <= 2
                    && !$isArr($n->args[0]->value, $n->getStartFilePos())) {
                    $replace($n->name->getStartFilePos(), $n->name->getEndFilePos() + 1, '\phpup_count');
                    $stat['count']++;
                } elseif ($fn !== null && in_array($fn, RES_FUNCS, true) && $n->args !== []
                    && !$n->args[0]->value instanceof Expr\FuncCall) {
                    // fread($fp, ...)  →  \phpup_resfn('fread', $fp, ...)
                    $replace($n->name->getStartFilePos(), $n->name->getEndFilePos() + 1, '\phpup_resfn');
                    $edits[] = [$n->args[0]->getStartFilePos(), 2, PHP_INT_MAX, 0, var_export($fn, true) . ', '];
                    $stat['res']++;
                } elseif ($fn !== null && in_array($fn, PATH_FUNCS, true) && $n->args !== [] && !nonEmptyStr($n->args[0]->value)) {
                    // file_get_contents($url)  →  \phpup_pathfn('file_get_contents', $url)
                    $replace($n->name->getStartFilePos(), $n->name->getEndFilePos() + 1, '\phpup_pathfn');
                    $edits[] = [$n->args[0]->getStartFilePos(), 2, PHP_INT_MAX, 0, var_export($fn, true) . ', '];
                    $stat['res']++;
                } elseif ($fn !== null && $wrapsArr($n)) {
                    $replace($n->name->getStartFilePos(), $n->name->getEndFilePos() + 1, '\phpup_arrfn');
                    $edits[] = [$n->args[0]->getStartFilePos(), 2, PHP_INT_MAX, 0, var_export($fn, true) . ', '];
                    $stat['arr']++;
                }
            } elseif ($n instanceof Expr\Eval_ && !$n->expr instanceof Node\Scalar\String_) {
                // eval($tpl)：帝国CMS 从数据库取出的模板代码里有 $r[title] 这种写法，运行时再修
                $wrap($n->expr, '\phpup_fixcode(', ')');
                $stat['argc']++;
            } elseif ($n instanceof Expr\ConstFetch && !$namespaced) {
                $name = $n->name->toString();
                // 含小写字母或非 ASCII 字符（如误打的全角空格“　”）的名称不可能是 PHP 内置常量
                // 全大写的名称可能是本机 PHP 没装的扩展里的常量，只在作为数组下标时处理，并排除常见扩展前缀
                $lowerOk = preg_match('/[a-z\x80-\xff]/', $name)
                    || isset($dimKeys[spl_object_id($n)]) && !preg_match(EXT_CONST_PREFIX, $name);
                if (!isset($defined[strtolower($name)]) && $lowerOk && preg_match('/^[A-Za-z_\x80-\xff][\w\x80-\xff]*$/', $name)) {
                    $replace($n->getStartFilePos(), $n->getEndFilePos() + 1, var_export($name, true));
                    $stat['const']++;
                    $result['constants'][$name] = ($result['constants'][$name] ?? 0) + 1;
                }
            }
        }
    }

    if (array_sum($stat) === 0) {
        continue;
    }
    // 用到兼容函数的文件：开头加载站点根目录的 phpup_compat.php（重复加载无副作用）
    if ($stat['num'] + $stat['div'] + $stat['count'] + $stat['arr'] + $stat['res'] + $stat['argc'] > 0) {
        $depth = substr_count($rel, '/');
        $req = "require_once __DIR__ . '" . str_repeat('/..', $depth) . '/' . COMPAT . "'; ";
        $after = null;
        foreach ($ast as $s) {
            if ($s instanceof Stmt\Declare_) {
                $after = $s->getEndFilePos() + 1;       // declare(strict_types=1); 必须是第一句
                continue;
            }
            if ($s instanceof Stmt\Namespace_ && $s->name !== null) {
                // namespace X;（分号形式）必须在最前面：加在这一句后面
                $semi = strpos($code, ';', $s->name->getEndFilePos() + 1);
                $after = $semi === false ? null : $semi + 1;
            }
            break;
        }
        if ($after !== null) {
            $edits[] = [$after, 2, PHP_INT_MAX, 0, ' ' . rtrim($req)];
        } else {
            $openPos = null;
            $echoPos = null;
            $offset = 0;
            foreach (PhpToken::tokenize($code) as $tok) {
                if ($tok->id === T_OPEN_TAG) {
                    $openPos = $offset + strlen($tok->text);
                    break;
                }
                if ($tok->id === T_OPEN_TAG_WITH_ECHO && $echoPos === null) {
                    $echoPos = $offset;
                }
                $offset += strlen($tok->text);
            }
            if ($echoPos !== null && ($openPos === null || $echoPos < $openPos)) {
                $edits[] = [$echoPos, 2, PHP_INT_MAX, 0, '<?php ' . $req . '?>'];
            } elseif ($openPos !== null) {
                // "<?php" 后面紧跟的是换行时插到下一行行首，行号不变
                $edits[] = [$openPos, 2, PHP_INT_MAX, 0, $req];
            }
        }
    }

    // 应用改动：同一位置先放（前一个节点的）后缀、再放外层到内层的前缀、最后替换
    $rank = [0 => 0, 2 => 1, 1 => 2];
    usort($edits, static function ($a, $b) use ($rank) {
        if ($a[0] !== $b[0]) {
            return $a[0] <=> $b[0];
        }
        if ($a[1] !== $b[1]) {
            return $rank[$a[1]] <=> $rank[$b[1]];
        }
        return $a[1] === 0 ? $a[2] <=> $b[2] : $b[2] <=> $a[2];
    });
    $outCode = '';
    $cur = 0;
    foreach ($edits as [$pos, $kind, , $to, $text]) {
        if ($pos < $cur) {
            continue;   // 落在已被替换的范围里（理论上不会发生）
        }
        $outCode .= substr($code, $cur, $pos - $cur) . $text;
        $cur = $kind === 1 ? $to : $pos;
    }
    $outCode .= substr($code, $cur);
    try {
        $parser->parse($outCode);
    } catch (\Throwable $e) {
        fwrite(STDERR, "  跳过（改写后无法解析）: {$rel}: {$e->getMessage()}\n");
        continue;
    }
    file_put_contents($path, $outCode);
    $result['fixed'][$rel] = array_filter($stat);
}

ksort($result['fixed']);
arsort($result['constants']);
file_put_contents((string) $out, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
$tot = ['num' => 0, 'div' => 0, 'count' => 0, 'arr' => 0, 'res' => 0, 'const' => 0, 'argc' => 0];
foreach ($result['fixed'] as $s) {
    foreach ($s as $k => $v) {
        $tot[$k] += $v;
    }
}
fwrite(STDERR, sprintf("运行时类型修复：%d 个文件（运算 %d、除法 %d、count %d、数组函数 %d、文件句柄 %d、未加引号的常量 %d、参数个数 %d）\n",
    count($result['fixed']), $tot['num'], $tot['div'], $tot['count'], $tot['arr'], $tot['res'], $tot['const'], $tot['argc']));
