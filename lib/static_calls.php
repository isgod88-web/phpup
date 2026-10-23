<?php

/**
 * 修复“用静态方式调用非静态方法”：  Foo::bar()  而 bar() 没有声明 static。
 * PHP 7 只给出弃用提示照常运行；PHP 8 直接抛 Error（页面白屏）。PHPCompatibility 检测不到这一项。
 *
 * 做法：扫描整个站点，找出被静态调用的非静态方法。如果方法体里没有用到 $this（PHP 7 下静态调用时本来就没有 $this），
 * 就给方法加上 static 关键字。加 static 后用 $obj->bar() 的普通调用照样可用，行为与现网一致。
 * 方法里又以 self::/static::/parent:: 调用了别的非静态方法时，一并处理；
 * 用到 $this、或者父类/子类有同名方法等无法保证安全的情况，不改，列入报告。
 *
 * 用法: php static_calls.php <目录> <输出json> [排除通配符,逗号分隔] [--dry-run]
 *   排除的目录（如 vendor）只读取类定义，不修改。
 * 输出: {"fixed": {"文件": ["Class::method", ...]}, "unsafe": [{"file","line","call","reason"}]}
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/rector/rector/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

ini_set('memory_limit', '4G');
$args = array_values(array_filter(array_slice($argv, 1), static fn ($a) => $a !== '--dry-run'));
$dryRun = in_array('--dry-run', $argv, true);
[$dir, $out] = $args + [null, null];
$exclude = array_filter(explode(',', $args[2] ?? ''));
$dir = rtrim((string) realpath((string) $dir), '/');

$parser = (new ParserFactory())->createForNewestSupportedVersion();

/** 类信息: lc(FQCN) => list of ['file','parent'=>lc|null,'iface'=>bool,'methods'=>[lc => ['name','static','abstract','usesThis','selfCalls'=>[[cls,method]],'pos','line']]] */
$classes = [];
/** 调用点: [file, line, cls(lc), method(lc), ctxClass(lc|null), ctxHasThis(bool), ctxMethod(lc|null)] */
$calls = [];
$editable = [];

final class Collector extends NodeVisitorAbstract
{
    public array $classes = [];
    public array $calls = [];
    private array $classStack = [];
    private array $methodStack = [];   // [name lc, static bool]
    private const CALLBACK_FUNCS = ['call_user_func', 'call_user_func_array', 'array_map', 'array_filter', 'array_walk',
        'array_walk_recursive', 'usort', 'uasort', 'uksort', 'set_error_handler', 'set_exception_handler',
        'register_shutdown_function', 'spl_autoload_register', 'preg_replace_callback', 'iterator_apply', 'ob_start'];

    public function __construct(private string $file)
    {
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            $name = $node->namespacedName ? strtolower($node->namespacedName->toString()) : null;
            $this->classStack[] = [$name, $node instanceof Node\Stmt\Class_ && $node->extends ? strtolower($node->extends->toString()) : null];
            if ($name !== null) {
                $this->classes[] = ['name' => $name, 'display' => $node->namespacedName->toString(), 'file' => $this->file,
                    'parent' => end($this->classStack)[1],
                    'iface' => !$node instanceof Node\Stmt\Class_, 'methods' => []];
            }
        } elseif ($node instanceof Node\Stmt\ClassMethod) {
            $m = strtolower($node->name->toString());
            $this->methodStack[] = [$m, $node->isStatic()];
            $cls = end($this->classStack)[0] ?? null;
            if ($cls !== null) {
                $usesThis = false;
                $finder = new \PhpParser\NodeFinder();
                if ($node->stmts !== null) {
                    $usesThis = $finder->findFirst($node->stmts, static fn (Node $n) => $n instanceof Node\Expr\Variable && $n->name === 'this') !== null;
                }
                $idx = array_key_last($this->classes);
                $this->classes[$idx]['methods'][$m] = [
                    'name' => $node->name->toString(), 'static' => $node->isStatic(), 'abstract' => $node->isAbstract() || $node->stmts === null,
                    'usesThis' => $usesThis, 'selfCalls' => [], 'pos' => $node->getStartFilePos(),
                    'line' => $node->getStartLine(), 'file' => $this->file,
                ];
            }
        } elseif ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
            $this->addCall($node->class->toString(), $node->name->toString(), $node->getStartLine(), true);
        } elseif ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
            && in_array(strtolower($node->name->toString()), self::CALLBACK_FUNCS, true)) {
            foreach ($node->args as $arg) {
                if (!$arg instanceof Node\Arg) {
                    continue;
                }
                $v = $arg->value;
                if ($v instanceof Node\Expr\Array_ && count($v->items) === 2
                    && $v->items[0]?->value instanceof Node\Scalar\String_ && $v->items[1]?->value instanceof Node\Scalar\String_) {
                    $this->addCall($v->items[0]->value->value, $v->items[1]->value->value, $node->getStartLine(), false);
                } elseif ($v instanceof Node\Scalar\String_ && preg_match('/^\\\\?([\w\\\\]+)::(\w+)$/', $v->value, $mm)) {
                    $this->addCall($mm[1], $mm[2], $node->getStartLine(), false);
                }
            }
        }
        return null;
    }

    private function addCall(string $cls, string $method, int $line, bool $resolved): void
    {
        $lc = strtolower(ltrim($cls, '\\'));
        $ctx = end($this->classStack) ?: [null, null];
        if ($lc === 'self' || $lc === 'static') {
            $lc = $ctx[0];
        } elseif ($lc === 'parent') {
            $lc = $ctx[1];
        }
        if ($lc === null) {
            return;
        }
        $m = end($this->methodStack) ?: null;
        $hasThis = $m !== null && !$m[1];
        $this->calls[] = [$this->file, $line, $lc, strtolower($method), $ctx[0], $hasThis, $m[0] ?? null];
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->classStack);
        } elseif ($node instanceof Node\Stmt\ClassMethod) {
            array_pop($this->methodStack);
        }
        return null;
    }
}

// ---------- 1. 扫描 ----------
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || !preg_match('/\.(php|inc|phtml)$/i', $f->getFilename())) {
        continue;
    }
    $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($f->getPathname(), strlen($dir) + 1));   // Windows 下统一成 /
    $isEditable = true;
    foreach ($exclude as $pattern) {
        if (fnmatch($pattern, '/' . $rel)) {
            $isEditable = false;
            break;
        }
    }
    $code = (string) file_get_contents($f->getPathname());
    if (str_contains($code, "\0") || !preg_match('/\b(class|::)\b|::/i', $code)) {
        continue;
    }
    try {
        $ast = $parser->parse($code);
    } catch (\Throwable) {
        continue;
    }
    if ($ast === null) {
        continue;
    }
    $tr = new NodeTraverser(new NameResolver(), $collector = new Collector($rel));
    $tr->traverse($ast);
    foreach ($collector->classes as $c) {
        $classes[$c['name']][] = $c;
    }
    foreach ($collector->calls as $c) {
        if ($isEditable) {
            $calls[] = $c;
        }
    }
    $editable[$rel] = $isEditable;
}

// ---------- 2. 分析 ----------
$parentOf = static function (string $cls) use ($classes): ?string {
    return $classes[$cls][0]['parent'] ?? null;
};
// 同名类可能有多份（不同目录下的副本），任意一份的继承链满足即可
$isSubclassOf = static function (?string $cls, string $ancestor) use ($classes): bool {
    $todo = $cls === null ? [] : [$cls];
    $seen = [];
    while ($todo) {
        $c = array_pop($todo);
        if ($c === $ancestor) {
            return true;
        }
        if (isset($seen[$c]) || count($seen) > 200) {
            continue;
        }
        $seen[$c] = true;
        foreach ($classes[$c] ?? [] as $def) {
            if ($def['parent'] !== null) {
                $todo[] = $def['parent'];
            }
        }
    }
    return false;
};
/** 沿继承链查找声明该方法的类 */
$declaring = static function (string $cls, string $m) use ($classes, $parentOf): ?string {
    for ($i = 0; $cls !== null && $i < 50; $i++, $cls = $parentOf($cls)) {
        foreach ($classes[$cls] ?? [] as $c) {
            if (isset($c['methods'][$m])) {
                return $cls;
            }
        }
    }
    return null;
};

/** 判断一个静态调用点：返回 null（无需处理）、['fix', "cls::m"] 或 ['unsafe', 原因, "cls::m", 显示名] */
$evaluate = static function (string $cls, string $m, ?string $ctxCls, bool $ctxHasThis)
    use ($classes, $declaring, $isSubclassOf, $editable): ?array {
    if (!isset($classes[$cls])) {
        return null;
    }
    $decl = $declaring($cls, $m);
    if ($decl === null) {
        return null;
    }
    $infos = array_filter(array_map(static fn ($c) => $c['methods'][$m] ?? null, $classes[$decl]));
    if ($infos === [] || array_filter($infos, static fn ($i) => !$i['static']) === []) {
        return null;   // 已经是 static
    }
    if ($ctxHasThis && $ctxCls !== null && ($isSubclassOf($ctxCls, $decl) || $isSubclassOf($ctxCls, $cls))) {
        return null;   // 在本类/子类的普通方法里调用，PHP 8 也允许（等同于 $this 调用）
    }
    $label = reset($infos)['name'];
    $why = null;
    foreach ($infos as $i) {
        if ($i['usesThis']) {
            $why = '方法里用到了 $this';
        } elseif ($i['abstract']) {
            $why = '抽象方法';
        } elseif (!($editable[$i['file']] ?? false)) {
            $why = '方法定义在第三方库/排除目录中';
        }
    }
    foreach ($classes[$decl] as $c) {
        if ($c['iface']) {
            $why = '接口方法';
        }
    }
    // 继承链上其他类的同名方法：加 static 会导致 "Cannot make non static method static" 致命错误
    foreach ($classes as $other => $defs) {
        if ($other === $decl) {
            continue;
        }
        foreach ($defs as $c) {
            if (isset($c['methods'][$m]) && ($isSubclassOf($other, $decl) || $isSubclassOf($decl, $other))) {
                $why = "继承关系中的 {$other} 也定义了同名方法";
            }
        }
    }
    return $why === null ? ['fix', "{$decl}::{$m}"] : ['unsafe', $why, "{$decl}::{$m}", $classes[$decl][0]['display'] . "::{$label}()"];
};

// 方法内部的调用点，按“所在方法”分组，供“改成 static 之后，方法内部的 self:: 调用失去 $this”时复查
$callsIn = [];
foreach ($calls as $c) {
    if ($c[4] !== null && $c[6] !== null) {
        $callsIn[$c[4] . '::' . $c[6]][] = $c;
    }
}
$blocked = [];    // 方法内部有无法处理的调用，不能改成 static 的方法
do {
    $toFix = [];
    $unsafe = [];
    $queue = [];
    foreach ($calls as $c) {
        $queue[] = [$c, $c[5]];
    }
    $restart = false;
    while ($queue) {
        [[$file, $line, $cls, $m, $ctxCls, , $ctxMethod], $hasThis] = array_shift($queue);
        $r = $evaluate($cls, $m, $ctxCls, $hasThis);
        if ($r === null) {
            continue;
        }
        if ($r[0] === 'fix' && isset($blocked[$r[1]])) {
            $r = ['unsafe', $blocked[$r[1]], $r[1], $r[1] . '()'];
        }
        if ($r[0] === 'unsafe') {
            $unsafe[] = ['file' => $file, 'line' => $line, 'call' => $r[3], 'reason' => $r[1]];
            // 这次调用发生在某个“将被改成 static”的方法里 → 那个方法也不能改
            $outer = $ctxCls !== null && $ctxMethod !== null ? $ctxCls . '::' . $ctxMethod : null;
            if (!$hasThis && $outer !== null && isset($toFix[$outer]) && !isset($blocked[$outer])) {
                $blocked[$outer] = '方法内部调用了无法改为 static 的方法';
                $restart = true;
                break;
            }
            continue;
        }
        if (!isset($toFix[$r[1]])) {
            $toFix[$r[1]] = true;
            // 方法改成 static 后，其内部的 self::/parent:: 调用不再有 $this，需要复查
            foreach ($callsIn[$r[1]] ?? [] as $inner) {
                $queue[] = [$inner, false];
            }
        }
    }
} while ($restart);

// ---------- 3. 修改：在 function 关键字前插入 static ----------
$fixed = [];
$edits = [];   // file => [pos, ...]
foreach (array_keys($toFix) as $key) {
    [$cls, $m] = explode('::', $key);
    foreach ($classes[$cls] as $c) {
        if (isset($c['methods'][$m]) && !$c['methods'][$m]['static']) {
            $edits[$c['file']][] = $c['methods'][$m]['pos'];
            $fixed[$c['file']][] = $c['display'] . '::' . $c['methods'][$m]['name'] . '()';
        }
    }
}
if (!$dryRun) {
    foreach ($edits as $file => $positions) {
        $path = $dir . '/' . $file;
        $code = (string) file_get_contents($path);
        rsort($positions);
        foreach (array_unique($positions) as $pos) {
            // 方法声明从修饰符或 function 开始；找到其后的 function 关键字
            if (!preg_match('/\bfunction\b/i', $code, $mm, PREG_OFFSET_CAPTURE, $pos)) {
                continue;
            }
            $code = substr_replace($code, 'static ', $mm[0][1], 0);
        }
        file_put_contents($path, $code);
    }
}
ksort($fixed);
$seen = [];
$unsafe = array_values(array_filter($unsafe, static function ($u) use (&$seen) {
    $k = $u['file'] . ':' . $u['line'] . $u['call'];
    return !isset($seen[$k]) && ($seen[$k] = true);
}));
file_put_contents($out, json_encode(['fixed' => $fixed, 'unsafe' => $unsafe], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
fwrite(STDERR, sprintf("静态调用检查：加 static %d 处，无法自动处理 %d 处\n", array_sum(array_map('count', $fixed)), count($unsafe)));
