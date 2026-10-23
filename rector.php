<?php

/**
 * PHP 版本迁移专用 Rector 配置（由 phpup.sh 调用，一般不需要手改）
 *
 * 原则：只做“版本兼容”所必须的改写，不做代码风格/现代化改写，不碰业务逻辑。
 * 做法：启用 Rector 官方的 PHP 版本规则集（5.3 ~ 目标版本），再把其中
 *       “仅仅是让代码更现代/更好看”的规则全部跳过。
 *
 * 环境变量（phpup.sh 会自动设置）：
 *   PHPUP_PATHS        要处理的站点目录，多个用 PATH_SEPARATOR 分隔（Linux 为冒号，Windows 为分号）
 *   PHPUP_TARGET       目标版本，例如 8.5（默认 8.5）
 *   PHPUP_EXCLUDE      额外排除的目录/通配符，逗号分隔
 *   PHPUP_STRICT_NULL  =1 时启用“null 传给字符串参数”修复（PHP 8.1 弃用项，会加很多 (string) 转换）
 *   PHPUP_INCLUDE_VENDOR =1 时连 vendor/ 第三方库一起改（默认不改，建议用 composer 升级第三方库）
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;

// 本工具自带的补充规则（Rector 官方没有覆盖、但可以安全自动修复的项目）
require_once __DIR__ . '/lib/Rector/MagicQuotesToFalseRector.php';
require_once __DIR__ . '/lib/Rector/OptionalBeforeRequiredParamRector.php';
require_once __DIR__ . '/lib/Rector/DeprecatedNoopCallRector.php';
require_once __DIR__ . '/lib/Rector/ConcatArithmeticPrecedenceRector.php';
require_once __DIR__ . '/lib/Rector/PdoSilentErrmodeRector.php';

$paths = array_values(array_filter(explode(PATH_SEPARATOR, (string) getenv('PHPUP_PATHS'))));
if ($paths === []) {
    fwrite(STDERR, "PHPUP_PATHS 未设置，请通过 phpup.sh 运行\n");
    exit(1);
}

$target = getenv('PHPUP_TARGET') ?: '8.5';
$versionMap = [
    '8.2' => [PhpVersion::PHP_82, 'php82'],
    '8.3' => [PhpVersion::PHP_83, 'php83'],
    '8.4' => [PhpVersion::PHP_84, 'php84'],
    '8.5' => [PhpVersion::PHP_85, 'php85'],
];
if (!isset($versionMap[$target])) {
    fwrite(STDERR, "不支持的目标版本: {$target}（可选 8.2 / 8.3 / 8.4 / 8.5）\n");
    exit(1);
}
[$phpVersionId, $setName] = $versionMap[$target];

// ---------------------------------------------------------------------------
// 只是“现代化/美化”的规则：跳过。它们不影响能否在新版本上运行。
// ---------------------------------------------------------------------------
$modernizationOnly = [
    // 5.x
    'Rector\Php52\Rector\Property\VarToPublicPropertyRector',
    'Rector\Php53\Rector\Ternary\TernaryToElvisRector',
    'Rector\Php53\Rector\FuncCall\DirNameFileConstantToDirConstantRector',
    'Rector\Visibility\Rector\ClassMethod\ExplicitPublicClassMethodRector',
    'Rector\Php54\Rector\Array_\LongArrayToShortArrayRector',
    'Rector\Php55\Rector\String_\StringClassNameToClassConstantRector',
    'Rector\Php55\Rector\ClassConstFetch\StaticToSelfOnFinalClassRector',
    'Rector\Php55\Rector\Class_\ClassConstantToSelfClassRector',
    'Rector\Php55\Rector\FuncCall\GetCalledClassToSelfClassRector',
    'Rector\Php55\Rector\FuncCall\GetCalledClassToStaticClassRector',
    'Rector\Php56\Rector\FuncCall\PowToExpRector',
    // 7.0
    'Rector\Php70\Rector\Ternary\TernaryToNullCoalescingRector',
    'Rector\Php70\Rector\FuncCall\RandomFunctionRector',
    'Rector\Php70\Rector\FuncCall\MultiDirnameRector',
    'Rector\Php70\Rector\Ternary\TernaryToSpaceshipRector',
    'Rector\Php70\Rector\If_\IfToSpaceshipRector',
    'Rector\Php70\Rector\StmtsAwareInterface\IfIssetToCoalescingRector',
    // 站点已经在 PHP 7.x 上运行，以下 5.x→7.x 规则对它们没有必要，而且可能改变现有行为：
    //   $$a['b'] 加花括号会按 PHP5 语义改写；删除“多余参数”会影响用 func_get_args() 的函数；
    //   $this->静态方法() 在 8.x 仍然合法；strpos 等 needle 加 (string) 在 8.x 是无意义的噪音；
    //   BinaryOpBetweenNumberAndString 实测会把 '' !== $x 改成 0 !== $x，改变判断结果
    'Rector\Php70\Rector\Variable\WrapVariableVariableNameInCurlyBracesRector',
    'Rector\Php70\Rector\MethodCall\ThisCallOnStaticMethodToStaticCallRector',
    'Rector\Php71\Rector\FuncCall\RemoveExtraParametersRector',
    'Rector\Php71\Rector\Assign\AssignArrayToStringRector',
    'Rector\Php73\Rector\FuncCall\StringifyStrNeedlesRector',
    'Rector\Php71\Rector\BinaryOp\BinaryOpBetweenNumberAndStringRector',
    // 会把自己 define 的大小写混合常量（如 FTP_OS_Unix）改成全大写，导致常量未定义 → 实测有害，跳过
    'Rector\Php73\Rector\ConstFetch\SensitiveConstantNameRector',
    // 实测（帝国CMS SendEmail.inc.php）会生成 function () use ($this)，PHP 7.1+ 直接致命错误 → 交给人工清单
    'Rector\Php55\Rector\FuncCall\PregReplaceEModifierRector',
    // PHP 7.3 起已支持；站点已在 7.3+ 运行，无需改写 heredoc 结束标记
    'Rector\Php73\Rector\String_\SensitiveHereNowDocRector',
    // 7.1
    'Rector\Php71\Rector\BooleanOr\IsIterableRector',
    'Rector\Php71\Rector\TryCatch\MultiExceptionCatchRector',
    'Rector\Php71\Rector\List_\ListToArrayDestructRector',
    // 7.3
    'Rector\Php73\Rector\BooleanOr\IsCountableRector',
    'Rector\Php73\Rector\FuncCall\ArrayKeyFirstLastRector',
    'Rector\Php73\Rector\FuncCall\ArrayKeysToArrayKeyFirstLastRector',
    'Rector\Php73\Rector\FuncCall\SetCookieRector',
    // 7.4
    'Rector\Php74\Rector\Assign\NullCoalescingOperatorRector',
    'Rector\Php74\Rector\If_\IfToNullCoalescingAssignRector',
    'Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector',
    'Rector\Php74\Rector\Property\RestoreDefaultNullToNullableTypePropertyRector',
    // 8.0
    'Rector\Php80\Rector\NotIdentical\StrContainsRector',
    'Rector\Php80\Rector\NotIdentical\MbStrContainsRector',
    'Rector\Php80\Rector\Identical\StrStartsWithRector',
    'Rector\Php80\Rector\Identical\StrEndsWithRector',
    'Rector\Php80\Rector\Class_\StringableForToStringRector',
    'Rector\Php80\Rector\FuncCall\ClassOnObjectRector',
    'Rector\Php80\Rector\Ternary\GetDebugTypeRector',
    'Rector\Php80\Rector\Ternary\TernaryToNullsafeCoalesceRector',
    'Rector\Php80\Rector\Catch_\RemoveUnusedVariableInCatchRector',
    'Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector',
    'Rector\Php80\Rector\Switch_\ChangeSwitchToMatchRector',
    'Rector\Php80\Rector\ClassConstFetch\ClassOnThisVariableObjectRector',
    // 会同时改函数参数顺序和所有调用处，风险较大 → 交给人工清单
    'Rector\CodeQuality\Rector\ClassMethod\OptionalParametersAfterRequiredRector',
    // 8.1
    'Rector\Php81\Rector\Property\ReadOnlyPropertyRector',
    'Rector\Php81\Rector\Class_\MyCLabsClassToEnumRector',
    'Rector\Php81\Rector\MethodCall\MyCLabsMethodCallToEnumConstRector',
    'Rector\Php81\Rector\New_\MyCLabsConstructorCallToEnumFromRector',
    'Rector\Php81\Rector\Class_\SpatieEnumClassToEnumRector',
    'Rector\Php81\Rector\MethodCall\SpatieEnumMethodCallToEnumConstRector',
    'Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector',
    'Rector\Php81\Rector\MethodCall\RemoveReflectionSetAccessibleCallsRector',
    'Rector\TypeDeclaration\Rector\ClassMethod\ReturnNeverTypeRector',
    'Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector',
    'Rector\CodingStyle\Rector\Closure\ClosureDelegatingCallToFirstClassCallableRector',
    'Rector\CodingStyle\Rector\FuncCall\ClosureFromCallableToFirstClassCallableRector',
    'Rector\CodingStyle\Rector\FuncCall\FunctionFirstClassCallableRector',
    // 8.2
    'Rector\Php82\Rector\Class_\ReadOnlyClassRector',
    // 8.3
    'Rector\Php83\Rector\ClassConst\AddTypeToConstRector',
    'Rector\Php83\Rector\Class_\ReadOnlyAnonymousClassRector',
    'Rector\Php83\Rector\FuncCall\DynamicClassConstFetchRector',
    'Rector\Php83\Rector\BooleanAnd\JsonValidateRector',
    // 8.4
    'Rector\Php84\Rector\MethodCall\NewMethodCallWithoutParenthesesRector',
    'Rector\Php84\Rector\FuncCall\RoundingModeEnumRector',
    'Rector\Php84\Rector\Foreach_\ForeachToArrayFindRector',
    'Rector\Php84\Rector\Foreach_\ForeachToArrayFindKeyRector',
    'Rector\Php84\Rector\Foreach_\ForeachToArrayAllRector',
    'Rector\Php84\Rector\Foreach_\ForeachToArrayAnyRector',
    // 8.5
    'Rector\Php85\Rector\ArrayDimFetch\ArrayFirstLastRector',
    'Rector\Php85\Rector\Property\AddOverrideAttributeToOverriddenPropertiesRector',
    // __sleep/__wakeup 改写会改变序列化行为 → 交给人工清单
    'Rector\Php85\Rector\Class_\SleepToSerializeRector',
    'Rector\Php85\Rector\Class_\WakeupToUnserializeRector',
    // 父类在别处（如 include_path 里的 PEAR.php）、Rector 找不到时，会把 parent::xxx() 当成“没有父类”而删掉 → 改变行为
    'Rector\DeadCode\Rector\StaticCall\RemoveParentCallWithoutParentRector',
    // 8.5 仅为弃用提示，原规则的改写反而会改变结果：
    //   array_key_exists((string) $k) 对 false/小数键的结果与原来不同；ord($s[0]) 在空字符串时多出警告
    'Rector\Php85\Rector\FuncCall\ArrayKeyExistsNullToEmptyStringRector',
    'Rector\Php85\Rector\FuncCall\OrdSingleByteRector',
];

// 只跳过当前 Rector 版本中真实存在的规则（不同版本的命名空间可能略有差异）
$skip = array_values(array_filter($modernizationOnly, 'class_exists'));

// 排除：第三方库、缓存/编译模板目录
$excludeDirs = ['node_modules', 'templates_c', 'cache', 'runtime', 'storage/framework', '.git'];
if (getenv('PHPUP_INCLUDE_VENDOR') !== '1') {
    $excludeDirs[] = 'vendor';
}
foreach ($excludeDirs as $dir) {
    $skip[] = '*/' . $dir . '/*';
}
foreach (array_filter(explode(',', (string) getenv('PHPUP_EXCLUDE'))) as $pattern) {
    $skip[] = trim($pattern);
}

$rules = [
    \PhpUp\Rector\MagicQuotesToFalseRector::class,
    \PhpUp\Rector\OptionalBeforeRequiredParamRector::class,
    \PhpUp\Rector\DeprecatedNoopCallRector::class,
    \PhpUp\Rector\ConcatArithmeticPrecedenceRector::class,
    \PhpUp\Rector\PdoSilentErrmodeRector::class,
];
if (getenv('PHPUP_STRICT_NULL') === '1') {
    $rules[] = 'Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector';
}

$cacheDir = getenv('PHPUP_CACHE_DIR') ?: sys_get_temp_dir() . '/phpup-rector-cache';

$builder = RectorConfig::configure()
    ->withPaths($paths)
    ->withFileExtensions(['php', 'inc', 'phtml'])
    ->withPhpVersion($phpVersionId)
    ->withPhpSets(...[$setName => true])
    ->withRules($rules)
    ->withSkip($skip)
    ->withCache($cacheDir)
    ->withRealPathReporting()
    ->withoutParallel();

// php-parser 的打印器仍按 PHP 7 的规则认为 "." 与 "+" "-" 优先级相同（源码里标注了 FIXME），
// 改写后的代码可能漏掉必要的括号，例如 ("a" . $x) - 1 被打印成 "a" . $x - 1，在 PHP 8 下含义就变了。
// 这里把 "." 的优先级修正为 PHP 8 的实际规则（低于 + - << >>）。
return static function (RectorConfig $config) use ($builder): void {
    $builder($config);
    $config->afterResolving(\Rector\PhpParser\Printer\BetterStandardPrinter::class, static function (object $printer): void {
        $prop = new \ReflectionProperty(\PhpParser\PrettyPrinterAbstract::class, 'precedenceMap');
        $map = $prop->getValue($printer);
        $map[\PhpParser\Node\Expr\BinaryOp\Concat::class] = [62, 63, 62];
        $prop->setValue($printer, $map);
    });
};
