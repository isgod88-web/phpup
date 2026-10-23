<?php

declare(strict_types=1);

namespace PhpUp\Rector;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name\FullyQualified;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * PHP 8.0 起 PDO 的默认错误模式从 ERRMODE_SILENT（出错返回 false）改成了 ERRMODE_EXCEPTION（抛异常）。
 * 老代码里 if (!$stmt = $pdo->query(...)) 之类的判断会变成未捕获异常 → 500 错误。
 * 对没有指定 ATTR_ERRMODE 的 new PDO(...)，显式传入 PHP 7 的默认值 ERRMODE_SILENT，行为与现网一致。
 * 代码里已经指定了 ATTR_ERRMODE（选项数组或之后的 setAttribute）的，保持原样或以代码自己的设置为准。
 *
 * 另外 PHP 8.1 起，模拟预处理（MySQL 默认）查出来的整数/小数直接返回 int/float，以前是字符串，
 * 代码里 $row['id'] === '1' 这类比较结果会变。补上 ATTR_STRINGIFY_FETCHES => true 恢复返回字符串。
 * 文件里出现 ATTR_EMULATE_PREPARES（可能关闭了模拟预处理，PHP 7 下本来就返回原生类型）或已指定 STRINGIFY 的，不加。
 */
final class PdoSilentErrmodeRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Keep PHP 7 default PDO error mode (silent)', [
            new CodeSample('new PDO($dsn, $u, $p);', 'new PDO($dsn, $u, $p, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT]);'),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [New_::class];
    }

    public function refactor(Node $node)
    {
        if (!$node->class instanceof Node\Name || strtolower(ltrim($node->class->toString(), '\\')) !== 'pdo' || $node->isFirstClassCallable()) {
            return null;
        }
        $args = $node->args;
        foreach ($args as $a) {
            if (!$a instanceof Arg || $a->unpack || $a->name !== null) {
                return null;
            }
        }
        if (count($args) === 0) {
            return null;
        }
        $pdo = static fn (string $c) => new ClassConstFetch(new FullyQualified('PDO'), $c);
        $wanted = ['ATTR_ERRMODE' => new ArrayItem($pdo('ERRMODE_SILENT'), $pdo('ATTR_ERRMODE'))];
        if (!str_contains($this->file->getFileContent(), 'ATTR_EMULATE_PREPARES')) {
            $wanted['ATTR_STRINGIFY_FETCHES'] = new ArrayItem($this->nodeFactory->createTrue(), $pdo('ATTR_STRINGIFY_FETCHES'));
        }
        if (count($args) >= 4) {
            $opts = $args[3]->value;
            if (!$opts instanceof Array_) {
                return null;            // 选项是变量，无法判断，保持原样
            }
            foreach ($opts->items as $i) {
                if ($i !== null && $i->key === null) {
                    return null;
                }
                if ($i !== null && $i->key instanceof ClassConstFetch && $i->key->name instanceof Node\Identifier) {
                    unset($wanted[$i->key->name->toString()]);
                }
            }
            if ($wanted === []) {
                return null;
            }
            array_push($opts->items, ...array_values($wanted));
            return $node;
        }
        // 补齐缺省的用户名/密码参数（PHP 7 默认值均为 null）
        while (count($args) < 3) {
            $args[] = new Arg($this->nodeFactory->createNull());
        }
        $args[] = new Arg(new Array_(array_values($wanted), ['kind' => Array_::KIND_SHORT]));
        $node->args = $args;
        return $node;
    }
}
