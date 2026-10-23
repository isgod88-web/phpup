<?php

declare(strict_types=1);

namespace PhpUp\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\ErrorSuppress;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeVisitor;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * magic_quotes 系列函数在 PHP 8.0 被删除。
 * 自 PHP 5.4 起 magic_quotes 功能已不存在，这些函数一直返回 false，所以：
 *   get_magic_quotes_gpc() / get_magic_quotes_runtime()  → false
 *   单独一行的 set_magic_quotes_runtime(...) / magic_quotes_runtime(...)  → 删除
 *   出现在表达式里的 set_magic_quotes_runtime(...)  → false
 * 行为与站点在 PHP 7.x 上的实际运行结果完全一致。
 */
final class MagicQuotesToFalseRector extends AbstractRector
{
    private const GETTERS = ['get_magic_quotes_gpc', 'get_magic_quotes_runtime'];
    private const SETTERS = ['set_magic_quotes_runtime', 'magic_quotes_runtime'];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Replace removed magic_quotes functions with their constant result', [
            new CodeSample('if (get_magic_quotes_gpc()) {} @set_magic_quotes_runtime(0);', 'if (false) {}'),
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
            if ($expr instanceof FuncCall && $this->isNames($expr, self::SETTERS)) {
                return NodeVisitor::REMOVE_NODE;
            }
            return null;
        }

        if ($this->isNames($node, array_merge(self::GETTERS, self::SETTERS))) {
            return new ConstFetch(new Name('false'));
        }
        return null;
    }
}
