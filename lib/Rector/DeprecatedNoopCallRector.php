<?php

declare(strict_types=1);

namespace PhpUp\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\ErrorSuppress;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeVisitor;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * 处理 PHP 8 中“已经不起任何作用、调用只会产生弃用警告”的函数：
 *   openssl_free_key / openssl_pkey_free / openssl_x509_free（8.0 起资源改为对象，自动释放）
 *   libxml_disable_entity_loader（libxml 2.9+ 默认就禁止外部实体，8.0 起弃用）
 * 单独一行的调用直接删除；出现在表达式里时替换为它在 PHP 8 下的返回值。
 * 另外 ob_implicit_flush(0/1) 的整数参数改为 false/true（8.0 起参数类型为 bool）。
 */
final class DeprecatedNoopCallRector extends AbstractRector
{
    private const NOOP = ['openssl_free_key', 'openssl_pkey_free', 'openssl_x509_free', 'libxml_disable_entity_loader'];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Remove calls to functions that are no-ops in PHP 8', [
            new CodeSample('openssl_free_key($k);', ''),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [Expression::class, FuncCall::class];
    }

    public function refactor(Node $node)
    {
        if ($node instanceof Expression) {
            $expr = $node->expr instanceof ErrorSuppress ? $node->expr->expr : $node->expr;
            if ($expr instanceof FuncCall && $this->isNames($expr, self::NOOP)) {
                return NodeVisitor::REMOVE_NODE;
            }
            return null;
        }
        if ($this->isNames($node, ['openssl_free_key', 'openssl_pkey_free', 'openssl_x509_free'])) {
            return new ConstFetch(new Name('null'));
        }
        if ($this->isName($node, 'libxml_disable_entity_loader')) {
            return new ConstFetch(new Name('true'));
        }
        if ($this->isName($node, 'ob_implicit_flush') && isset($node->args[0])
            && $node->args[0] instanceof Node\Arg && $node->args[0]->value instanceof Int_) {
            $node->args[0]->value = new ConstFetch(new Name($node->args[0]->value->value ? 'true' : 'false'));
            return $node;
        }
        return null;
    }
}
