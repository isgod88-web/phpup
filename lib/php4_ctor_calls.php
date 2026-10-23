<?php

/**
 * 1. Rector 只在能找到父类时才改 PHP4 风格构造函数（与类同名的方法）。父类不在站点里（如 include_path 里的 PEAR.php）时
 *    会跳过，而 PHP 8 不再把同名方法当构造函数 → new 对象时初始化代码不执行。这里把剩下的也改成 __construct
 *    （仅限没有命名空间、类里没有 __construct 的类，与 PHP 7 判断构造函数的规则一致）。
 * 2. Rector 把 PHP4 风格构造函数改名为 __construct 后，
 * 别的文件里的子类仍然写着 parent::父类名(...)，PHP 8 下会报“调用未定义的方法”。
 * Rector 逐个文件处理，看不到父类在别的文件里已被改名，这里在 Rector 之后全站补一遍：
 *   parent::父类名(...)  →  parent::__construct(...)
 *   父类名::父类名(...)  →  父类名::__construct(...)   （在子类构造函数里的老写法）
 *   parent::祖父类名(...) → 祖父类名::__construct(...)
 * 只在父类确实没有同名方法、但有 __construct 时才改。
 *
 * 用法: php php4_ctor_calls.php <目录> <输出json> [排除通配符,逗号分隔]
 * 输出: { "文件": 修改处数 }
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/rector/rector/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

ini_set('memory_limit', '4G');
[$self, $dir, $out] = $argv + [null, null, null];
$exclude = array_filter(explode(',', $argv[3] ?? ''));
$dir = rtrim((string) realpath((string) $dir), '/');
$parser = (new ParserFactory())->createForNewestSupportedVersion();
$finder = new NodeFinder();

$classes = [];   // lc 类名 => ['methods' => [lc => true], 'parent' => lc|null]（同名多份时合并）
$files = [];     // 相对路径 => [ast, code]
$renames = [];   // 相对路径 => [[起, 止, '__construct'], ...]
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || !preg_match('/\.(php|inc|phtml)$/i', $f->getFilename())) {
        continue;
    }
    $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($f->getPathname(), strlen($dir) + 1));   // Windows 下统一成 /
    $editable = true;
    foreach ($exclude as $pattern) {
        if (fnmatch($pattern, '/' . $rel)) {
            $editable = false;
        }
    }
    $code = (string) file_get_contents($f->getPathname());
    if (str_contains($code, "\0") || stripos($code, 'class') === false) {
        continue;
    }
    try {
        $ast = $parser->parse($code) ?? [];
    } catch (\Throwable) {
        continue;
    }
    $namespaced = [];
    foreach ($finder->findInstanceOf($ast, Node\Stmt\Namespace_::class) as $ns) {
        if ($ns->name !== null) {
            foreach ($finder->findInstanceOf($ns->stmts, Node\Stmt\Class_::class) as $c) {
                $namespaced[spl_object_id($c)] = true;
            }
        }
    }
    foreach ($finder->findInstanceOf($ast, Node\Stmt\Class_::class) as $c) {
        if ($c->name === null) {
            continue;
        }
        $name = strtolower($c->name->toString());
        $classes[$name]['parent'] ??= $c->extends ? strtolower($c->extends->getLast()) : null;
        $classes[$name]['methods'] ??= [];
        $old = $c->getMethod($c->name->toString());
        if ($editable && $old !== null && $c->getMethod('__construct') === null && !isset($namespaced[spl_object_id($c)])) {
            $renames[$rel][] = [$old->name->getStartFilePos(), $old->name->getEndFilePos(), '__construct'];
            $classes[$name]['methods']['__construct'] = true;
            foreach ($c->getMethods() as $m) {
                if ($m !== $old) {
                    $classes[$name]['methods'][strtolower($m->name->toString())] = true;
                }
            }
            continue;
        }
        foreach ($c->getMethods() as $m) {
            $classes[$name]['methods'][strtolower($m->name->toString())] = true;
        }
    }
    if ($editable && (isset($renames[$rel]) || preg_match('/::\s*\w+\s*\(/', $code))) {
        $files[$rel] = [$ast, $code];
    }
}

$renamed = static fn (string $cls): bool => isset($classes[$cls])
    && !isset($classes[$cls]['methods'][$cls]) && isset($classes[$cls]['methods']['__construct']);

$result = [];
foreach ($files as $rel => [$ast, $code]) {
    $edits = $renames[$rel] ?? [];
    foreach ($finder->findInstanceOf($ast, Node\Stmt\Class_::class) as $c) {
        $parent = $c->extends ? strtolower($c->extends->getLast()) : null;
        foreach ($finder->findInstanceOf($c->stmts, Node\Expr\StaticCall::class) as $call) {
            if (!$call->class instanceof Node\Name || !$call->name instanceof Node\Identifier) {
                continue;
            }
            $cls = strtolower($call->class->getLast());
            $target = $cls === 'parent' ? $parent : $cls;
            $method = strtolower($call->name->toString());
            // 沿继承链找“与方法同名的祖先类”（PHP 7 里可以通过 parent:: 调用祖父类的老式构造函数）
            $ancestor = null;
            for ($a = $target, $i = 0; $a !== null && $i < 20; $a = $classes[$a]['parent'] ?? null, $i++) {
                if ($a === $method) {
                    $ancestor = $a;
                    break;
                }
            }
            if ($ancestor === null || !$renamed($ancestor)) {
                continue;
            }
            if ($ancestor === $target) {
                $edits[] = [$call->name->getStartFilePos(), $call->name->getEndFilePos(), '__construct'];
            } else {
                // 调用的是祖父类的构造函数：parent::__construct 会指向父类自己的构造函数，所以写明类名
                $edits[] = [$call->class->getStartFilePos(), $call->name->getEndFilePos(), $call->name->toString() . '::__construct'];
            }
        }
    }
    if ($edits === []) {
        continue;
    }
    usort($edits, static fn ($a, $b) => $b[0] <=> $a[0]);
    foreach ($edits as [$s, $e, $text]) {
        $code = substr_replace($code, $text, $s, $e - $s + 1);
    }
    file_put_contents($dir . '/' . $rel, $code);
    $result[$rel] = count($edits);
}
ksort($result);
file_put_contents($out, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
fwrite(STDERR, 'PHP4 风格构造函数及其调用改为 __construct：' . array_sum($result) . " 处\n");
