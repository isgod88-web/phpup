<?php

/**
 * 修正旧版工具生成的代码：把 \phpup_arrfn('array_map', [$this, 'x'], ...) 这类调用还原成 array_map([$this, 'x'], ...)。
 * 回调从 phpup_arrfn() 里调用会丢掉类作用域，private / protected 方法调不到 → TypeError。
 * 用法: php unwrap_callbacks.php <目录>
 */

require dirname(__DIR__) . '/vendor/rector/rector/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

$dir = rtrim($argv[1] ?? '', '/\\');
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "用法: php unwrap_callbacks.php <目录>\n");
    exit(1);
}
$parser = (new ParserFactory())->createForHostVersion();
$finder = new NodeFinder();
$total = 0;
$files = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (strtolower($f->getExtension()) !== 'php') {
        continue;
    }
    $code = file_get_contents($f->getPathname());
    if (!str_contains($code, 'phpup_arrfn')) {
        continue;
    }
    try {
        $ast = $parser->parse($code);
    } catch (Throwable $e) {
        continue;
    }
    $edits = [];
    foreach ($finder->findInstanceOf($ast, Expr\FuncCall::class) as $c) {
        if (!$c->name instanceof Node\Name || strtolower(ltrim($c->name->toString(), '\\')) !== 'phpup_arrfn'
            || count($c->args) < 2 || !$c->args[0]->value instanceof Node\Scalar\String_) {
            continue;
        }
        $fn = strtolower($c->args[0]->value->value);
        $cbAt = $fn === 'array_map' ? 1 : ($fn === 'array_filter' ? 2 : null);
        if ($cbAt === null || !isset($c->args[$cbAt])) {
            continue;
        }
        $cb = $c->args[$cbAt]->value;
        $safe = $cb instanceof Expr\Closure || $cb instanceof Expr\ArrowFunction
            || ($cb instanceof Expr\ConstFetch && strtolower($cb->name->toString()) === 'null')
            || ($cb instanceof Node\Scalar\String_ && !str_contains($cb->value, '::'));
        if ($safe) {
            continue;
        }
        $edits[] = [$c->name->getStartFilePos(), $c->args[1]->getStartFilePos(), $fn . '('];
    }
    if (!$edits) {
        continue;
    }
    usort($edits, static fn ($a, $b) => $b[0] <=> $a[0]);
    foreach ($edits as [$s, $e, $t]) {
        $code = substr($code, 0, $s) . $t . substr($code, $e);
    }
    file_put_contents($f->getPathname(), $code);
    $files++;
    $total += count($edits);
    echo substr($f->getPathname(), strlen($dir) + 1), ': ', count($edits), "\n";
}
echo "还原 {$total} 处（{$files} 个文件）\n";
