<?php

declare(strict_types=1);

namespace PhpUp\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\BinaryOp\Minus;
use PhpParser\Node\Expr\BinaryOp\Plus;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * PHP 8.0 起 "+" "-" 的优先级高于 "."：
 *   "a" . $x - $y   在 PHP 7 里是 ("a" . $x) - $y，在 PHP 8 里变成 "a" . ($x - $y)
 * 为保证升级后运行结果与现网（PHP 7）完全一致，按 PHP 7 的计算顺序补上括号。
 * 源码里本来就有括号的不动。
 */
final class ConcatArithmeticPrecedenceRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Keep PHP 7 evaluation order of unparenthesized "." mixed with "+"/"-"', [
            new CodeSample('$s = "a" . $x - $y;', '$s = ("a" . $x) - $y;'),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [Concat::class];
    }

    public function refactor(Node $node)
    {
        if (!$this->isBareArith($node->right)) {
            return null;
        }
        return $this->rebuild($node->left, $node->right);
    }

    /** 把 A . (X op Y) 还原成 PHP 7 的 (A . X) op Y，X 仍是未加括号的 +/- 时继续向左展开 */
    private function rebuild(Expr $left, Expr $right): Expr
    {
        if ($this->isBareArith($right)) {
            /** @var Plus|Minus $right */
            $class = $right::class;
            return new $class($this->rebuild($left, $right->left), $right->right);
        }
        return new Concat($left, $right);
    }

    private function isBareArith(Expr $expr): bool
    {
        if (!$expr instanceof Plus && !$expr instanceof Minus) {
            return false;
        }
        $tokens = $this->file->getOldTokens();
        $start = $expr->getStartTokenPos();
        $end = $expr->getEndTokenPos();
        if ($start < 0 || $end < 0) {
            return false;               // 不是源码里的原始节点，不处理
        }
        $before = $start - 1;
        while ($before >= 0 && $tokens[$before]->isIgnorable()) {
            $before--;
        }
        $after = $end + 1;
        while (isset($tokens[$after]) && $tokens[$after]->isIgnorable()) {
            $after++;
        }
        $parenthesized = $before >= 0 && $tokens[$before]->text === '(' && isset($tokens[$after]) && $tokens[$after]->text === ')';
        return !$parenthesized;
    }
}
