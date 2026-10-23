<?php

/**
 * 把 Rector / PHPCompatibility / php -l 的结果汇总成中文报告（报告.md + 人工处理清单.csv）
 * 由 phpup.sh 调用。
 */

declare(strict_types=1);

$opt = getopt('', ['mode:', 'site:', 'target:', 'out:', 'phpcs:', 'rector:', 'lint:', 'root:', 'from:', 'profile:', 'shorttags:', 'vendors:', 'excluded:',
    'prefix:', 'staticcalls:', 'security:', 'keep-short-tags', 'lintpre:', 'php4ctor:', 'framework:', 'runtime:']);
foreach (['mode', 'site', 'target', 'out', 'phpcs', 'rector', 'lint', 'root'] as $k) {
    if (!isset($opt[$k])) {
        fwrite(STDERR, "缺少参数 --{$k}\n");
        exit(1);
    }
}
$mode   = $opt['mode'];
$site   = rtrim($opt['site'], '/');
$target = $opt['target'];
$from   = (string) ($opt['from'] ?? '');                    // 站点当前运行的 PHP 版本（如 7.3），可为空
$root   = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', (string) realpath($opt['root'])), '/');   // 被扫描/修改的目录（preview 时为副本）

$phpcs  = json_decode((string) @file_get_contents($opt['phpcs']), true) ?: ['totals' => [], 'files' => []];
$rector = json_decode((string) @file_get_contents($opt['rector']), true) ?: [];
$lint   = trim((string) @file_get_contents($opt['lint']));
$profile   = (string) ($opt['profile'] ?? '通用');
$excluded  = array_filter(explode(',', (string) ($opt['excluded'] ?? '')));
$shortTags = json_decode((string) @file_get_contents((string) ($opt['shorttags'] ?? '')), true) ?: [];
$vendorDirs = array_values(array_filter(array_map('trim', @file((string) ($opt['vendors'] ?? '')) ?: [])));
$readJson  = static fn (string $k) => json_decode((string) @file_get_contents((string) ($opt[$k] ?? '')), true) ?: [];
$prefix    = $readJson('prefix');                          // 短标签 / =& new 预处理结果
$static    = $readJson('staticcalls') + ['fixed' => [], 'unsafe' => []];
$security  = $readJson('security');
$php4ctor  = $readJson('php4ctor');
$framework = $readJson('framework');
$runtime   = ($readJson('runtime') + ['fixed' => []])['fixed'];   // 运行时类型修复                       // 框架补丁（ThinkPHP 错误处理、时区别名）
$keepShort = isset($opt['keep-short-tags']);

/** 把绝对路径转成相对站点根目录的路径 */
$rel = static function (string $path) use ($root): string {
    $real = str_replace(DIRECTORY_SEPARATOR, '/', realpath($path) ?: $path);   // Windows 路径统一成 /
    return str_starts_with($real, $root . '/') ? substr($real, strlen($root) + 1) : $real;
};
$isVendor = static fn (string $relPath): bool => (bool) preg_match('#(^|/)vend[oe]r2?/#', $relPath);

/** 没放在 vendor/ 里、而是直接拷进业务目录的常见第三方库 → 返回库名 */
$bundledLib = static function (string $relPath): ?string {
    $libs = [
        '#(^|/)PHPExcel(/|\.php$)#i'                      => 'PHPExcel（已停止维护，PHP 8 下问题多，建议换 PhpSpreadsheet）',
        '#(^|/)HTTP/(Request2?|Socket)(\.php$|/)|(^|/)PEAR\.php$|(^|/)Net/(URL2?|Socket)\.php$#' => 'PEAR HTTP_Request / Net_Socket（老 PEAR 库，建议换 curl 或 Guzzle）',
        '#(^|/)(class\.)?phpmailer|(^|/)PHPMailer/#i'       => 'PHPMailer',
        '#(^|/)smarty|(^|/)Smarty\.class\.php$#i'          => 'Smarty',
        '#(^|/)tcpdf/#i'                                    => 'TCPDF',
        '#(^|/)phpqrcode/#i'                                => 'phpqrcode',
        '#(^|/)thinkphp/#i'                                 => 'ThinkPHP 框架核心',
        '#(^|/)JWT\.php$|(^|/)php-jwt/#i'                  => 'firebase/php-jwt',
        '#(^|/)OAuth\.php$#'                               => 'OAuth 1.0 老库（OAuth.php）',
    ];
    foreach ($libs as $re => $name) {
        if (preg_match($re, $relPath)) {
            return $name;
        }
    }
    return null;
};

/** 从提示中取出“在哪个版本被删除” */
$removedIn = static function (string $msg): ?string {
    return preg_match('/removed (?:since|in) PHP ([\d]+\.[\d]+)/i', $msg, $m) ? $m[1] : null;
};

// ---------------------------------------------------------------------------
// Rector 规则 → 中文说明（未列出的规则显示原名）
// ---------------------------------------------------------------------------
$ruleText = [
    'Php4ConstructorRector'                       => ['PHP4 风格构造函数（与类同名的方法）改为 __construct', false],
    'EregToPregMatchRector'                       => ['ereg/eregi/split 系列函数改为 preg_*（正则写法会自动加分隔符）', true],
    'CreateFunctionToAnonymousFunctionRector'     => ['create_function() 改为匿名函数', true],
    'WhileEachToForeachRector'                    => ['while(list()=each()) 循环改为 foreach', true],
    'ListEachRector'                              => ['list(...) = each() 改为 key()/current()/next()', true],
    'ReplaceEachAssignmentWithKeyCurrentRector'   => ['$x = each($arr) 改为 key()/current()/next()', true],
    'SensitiveDefineRector'                       => ['define() 去掉第三个参数（大小写不敏感常量已移除）', false],
    'SensitiveConstantNameRector'                 => ['常量大小写写法统一', false],
    'SensitiveHereNowDocRector'                   => ['heredoc/nowdoc 结束标记兼容处理', false],
    'CurlyToSquareBracketArrayStringRector'       => ['$str{0} 改为 $str[0]', false],
    'MoneyFormatToNumberFormatRector'             => ['money_format() 改为 number_format()（货币符号/本地化格式需确认）', true],
    'ParenthesizeNestedTernaryRector'             => ['嵌套三元运算加括号（保持 PHP7 原有的运算顺序）', false],
    'VariableInStringInterpolationFixerRector'    => ['字符串中的 ${var} 改为 {$var}', false],
    'Utf8DecodeEncodeToMbConvertEncodingRector'   => ['utf8_encode/utf8_decode 改为 mb_convert_encoding', false],
    'RemoveGetClassGetParentClassNoArgsRector'    => ['get_class()/get_parent_class() 无参调用改为 self::class / parent::class', false],
    'RenameCastRector'                            => ['已移除/弃用的类型转换改名，如 (real)→(float)、(integer)→(int)', false],
    'RenameFunctionRector'                        => ['已移除/弃用的函数改名为替代函数，如 is_real→is_float', false],
    'RenameMethodRector'                          => ['已弃用的方法改名', false],
    'RenameClassConstFetchRector'                 => ['已弃用的类常量改名（如 PDO::MYSQL_ATTR_* → Pdo\\Mysql::ATTR_*）', false],
    'RenameConstantRector'                        => ['已弃用的常量改名', false],
    'ConsistentImplodeRector'                     => ['implode() 参数顺序统一为 (分隔符, 数组)', false],
    'FinalPrivateToPrivateVisibilityRector'       => ['去掉 private 方法上的 final', false],
    'FilterVarToAddSlashesRector'                 => ['FILTER_SANITIZE_MAGIC_QUOTES 改为 addslashes()', false],
    'MbStrrposEncodingArgumentPositionRector'     => ['mb_strrpos() 第三参数传编码的写法修正', false],
    'AddEscapeArgumentRector'                     => ['fgetcsv/fputcsv/str_getcsv 显式传入 escape 参数', false],
    'ExplicitNullableParamTypeRector'             => ['隐式可空参数 int $x = null 改为 ?int $x = null', false],
    'ArrayKeyExistsOnPropertyRector'              => ['array_key_exists() 用在对象上改为 property_exists()', false],
    'ExportToReflectionFunctionRector'            => ['ReflectionFunction::export() 改写', false],
    'HebrevcToNl2brHebrevRector'                  => ['hebrevc() 改为 nl2br(hebrev())', false],
    'RestoreIncludePathToIniRestoreRector'        => ['restore_include_path() 改为 ini_restore()', false],
    'StringifyStrNeedlesRector'                   => ['strpos 等函数的 needle 参数转为字符串', false],
    'RegexDashEscapeRector'                       => ['正则字符类中的 - 转义（PCRE2 兼容）', false],
    'StringifyDefineRector'                       => ['define() 常量名改为字符串', false],
    'StringsAssertNakedRector'                    => ['assert() 字符串参数改为表达式', true],
    'ParseStrWithResultArgumentRector'            => ['parse_str() 补上结果数组参数', true],
    'GetClassOnNullRector'                        => ['get_class() 参数可能为 null 时改为 self::class（PHP 8 会报错）', true],
    'UnsetCastRector'                             => ['(unset) 类型转换改为 null', false],
    'SetStateToStaticRector'                      => ['__set_state() 改为 static 方法', false],
    'AddParamBasedOnParentClassMethodRector'      => ['子类方法补齐父类方法的参数（避免签名不兼容致命错误）', false],
    'RemoveParentCallWithoutParentRector'         => ['删除没有父类时的 parent:: 调用', false],
    'FunctionArgumentDefaultValueReplacerRector'  => ['函数参数旧取值改为新取值（如 version_compare 的运算符）', false],
    'NullToStrictStringFuncCallArgRector'         => ['把可能为 null 的参数转为字符串后再传给内置函数（8.1 弃用项）', false],
    'NullToStrictIntPregSlitFuncCallLimitArgRector' => ['preg_split 的 limit 参数 null 改为 -1', false],
    'FilesystemIteratorSkipDotsRector'            => ['FilesystemIterator 显式加 SKIP_DOTS', false],
    'CombineHostPortLdapUriRector'                => ['ldap_connect(host, port) 改为 URI 写法', false],
    'EmptyListRector'                             => ['空的 list() 写法修正', false],
    'ListSplitStringRector'                       => ['list() 解构字符串改为 str_split', false],
    'CallUserMethodRector'                        => ['call_user_method() 改为 call_user_func()', false],
    'ReduceMultipleDefaultSwitchRector'           => ['switch 中多个 default 只保留一个', false],
    'BreakNotInLoopOrSwitchToReturnRector'        => ['循环外的 break 改为 return', true],
    'RenameMktimeWithoutArgsToTimeRector'         => ['无参 mktime() 改为 time()', false],
    'WrapVariableVariableNameInCurlyBracesRector' => ['可变变量加花括号（PHP7 语法解析顺序变化）', false],
    'ThisCallOnStaticMethodToStaticCallRector'    => ['$this 调用静态方法改为 static::', false],
    'ExceptionHandlerTypehintRector'              => ['异常处理函数的 Exception 类型改为 Throwable', false],
    'AssignArrayToStringRector'                   => ['字符串变量当数组赋值的写法修正', false],
    'BinaryOpBetweenNumberAndStringRector'        => ['数字与空字符串运算的写法修正', false],
    'RemoveExtraParametersRector'                 => ['删除调用内置函数时多余的参数', false],
    'ContinueToBreakInSwitchRector'               => ['switch 中的 continue 改为 break', false],
    'ReplaceHttpServerVarsByServerRector'         => ['$HTTP_SERVER_VARS 等改为 $_SERVER', false],
    'RemoveReferenceFromCallRector'               => ['删除调用时的 & 引用传参', false],
    'RemoveZeroBreakContinueRector'               => ['break 0 / continue 0 修正', false],
    'PregReplaceEModifierRector'                  => ['preg_replace 的 /e 修饰符改为 preg_replace_callback', true],
    'ClearReturnNewByReferenceRector'             => ['=& new 改为 = new', false],
    'RemoveFuncCallArgRector'                     => ['删除已废弃的函数参数', false],
    'RemoveFuncCallRector'                        => ['删除已无作用的函数调用（如 curl_close、imagedestroy）', false],
    'RemoveFinfoBufferContextArgRector'           => ['finfo_buffer 删除无用的 context 参数', false],
    'NullDebugInfoReturnRector'                   => ['__debugInfo() 返回 null 改为 []', false],
    'ColonAfterSwitchCaseRector'                  => ['case 后面的分号改为冒号', false],
    'ArrayKeyExistsNullToEmptyStringRector'       => ['array_key_exists 的 null 键改为空字符串', false],
    'ChrArgModuloRector'                          => ['chr() 参数超出范围时取模', false],
    'OrdSingleByteRector'                         => ['ord() 只传单字节', false],
    'ShellExecFunctionCallOverBackticksRector'    => ['反引号 `命令` 改为 shell_exec()', false],
    // 本工具自带规则
    'MagicQuotesToFalseRector'                    => ['get_magic_quotes_gpc() 等改为 false、删除 set_magic_quotes_runtime()（PHP 5.4 起就恒为 false）', false],
    'OptionalBeforeRequiredParamRector'           => ['必选参数前面的参数去掉默认值（PHP 7 下那个默认值本来就用不上）', false],
    'DeprecatedNoopCallRector'                    => ['删除已无作用的 openssl_free_key()/libxml_disable_entity_loader() 调用；ob_implicit_flush(1) 改为 true', false],
    'PdoSilentErrmodeRector'                      => ['new PDO(...) 补上 PHP 7 的默认设置：出错返回 false（8.0 起默认抛异常）、查询结果数字仍为字符串（8.1 起默认返回 int/float）', false],
    'ConcatArithmeticPrecedenceRector'            => ['"." 与 "+"/"-" 混用处加括号，保持 PHP 7 的计算顺序（PHP 8 改了优先级）', false],
];

// ---------------------------------------------------------------------------
// PHPCompatibility 检测项 → 中文分类和处理建议（按顺序匹配，先匹配先用）
// ---------------------------------------------------------------------------
$advice = [
    ['/mysql_/i', 'mysql_* 函数（PHP 7.0 已删除）',
        '改用 mysqli 或 PDO。最省事的是逐个换成 mysqli_* 过程式函数（注意 mysqli_query/mysqli_real_escape_string 等需要把连接 $link 作为第一个参数），长远建议改为预处理语句。也可以先在公共文件里封装一层兼容函数，集中替换。'],
    ['/mcrypt/i', 'mcrypt 加密扩展（PHP 7.2 已删除）',
        '改用 openssl_encrypt/openssl_decrypt；随机数改用 random_bytes()。注意：算法、模式、填充必须与旧数据一致，否则已有加密数据无法解密，需要专门测试。'],
    ['/magic_quotes/i', 'magic_quotes 相关函数（PHP 8.0 已删除）',
        'get_magic_quotes_gpc()/get_magic_quotes_runtime() 在 PHP 5.4 之后一直返回 false，可直接替换为 false，并删除依赖它的 stripslashes 分支。'],
    ['/\beach/i', 'each() 函数（PHP 8.0 已删除）',
        '改写为 foreach，或用 key()/current()/next() 组合。注意 each() 会移动数组内部指针。'],
    ['/create_function/i', 'create_function()（PHP 8.0 已删除）', '改写为匿名函数 function (...) { ... }。'],
    ['/RemovedPCREModifiers/i', 'preg_replace 的 /e 修饰符（PHP 7.0 已删除）', '改为 preg_replace_callback()，把替换表达式写进回调函数里。注意：在 PHP 7 上这行代码其实已经不生效（返回 null），改好后行为会恢复为 PHP 5 时的效果，需要测试。'],
    ['/ereg|\.split|spliti/i', 'ereg/split 系列正则函数（PHP 7.0 已删除）', '改为 preg_match/preg_replace/preg_split，正则需要加分隔符。'],
    ['/OptionalBeforeRequired/i', '可选参数写在必选参数前面（PHP 8.0 弃用）',
        '把前面参数的默认值去掉，或者调整参数顺序（调整顺序需要同步修改所有调用处）。目前只是弃用警告，不影响运行。'],
    ['/ImplicitlyNullable/i', '隐式可空参数类型（PHP 8.4 弃用）', '把 int $x = null 改为 ?int $x = null。'],
    ['/GetClassNoArgs/i', '无参调用 get_class()', '在类里改为 self::class 或 static::class；在类外调用会直接报错，需要传入对象。'],
    ['/DynamicProperty|AllowDynamicProperties/i', '动态属性（PHP 8.2 弃用）',
        '在类中显式声明属性，或在类上加 #[\\AllowDynamicProperties]。'],
    ['/__autoload/i', '__autoload()（PHP 8.0 已删除）', '改为 spl_autoload_register()。'],
    ['/RemovedExtensions/i', '已删除的扩展', '该扩展已不在 PHP 核心中，需要换用替代扩展或通过 PECL 单独安装。'],
    ['/RemovedFunctions|RemovedFunctionParameters|RemovedConstants|RemovedTypeCasts/i', '已删除/弃用的函数、参数或常量',
        '按提示里的替代方法修改（原文提示中 "Use ... instead" 即为替代写法）。'],
    ['/Removed|Forbidden/i', '已删除/禁止的语法或用法', '按提示修改。'],
    ['/./', '其他兼容性问题', '按提示修改。'],
];

// 已删除、但兼容层（lib/compat/phpup_compat.php）补回来的函数
$compatFuncs = ['each', 'create_function', 'money_format', 'get_magic_quotes_gpc', 'get_magic_quotes_runtime',
    'set_magic_quotes_runtime', 'magic_quotes_runtime', 'fgetss', 'convert_cyr_string', 'hebrevc', 'restore_include_path',
    'is_real', 'ezmlm_hash', 'read_exif_data', 'image2wbmp', 'mbregex_encoding', 'mbereg', 'mberegi', 'mbereg_replace',
    'mberegi_replace', 'mbsplit', 'mbereg_match', 'mbereg_search', 'mbereg_search_pos', 'mbereg_search_regs',
    'mbereg_search_init', 'mbereg_search_getregs', 'mbereg_search_getpos', 'mbereg_search_setpos'];
$compatFunc = static function (string $source) use ($compatFuncs): ?string {
    return preg_match('/\.RemovedFunctions\.(\w+?)(?:Deprecated|Removed)/i', $source, $m) && in_array(strtolower($m[1]), $compatFuncs, true)
        ? strtolower($m[1]) : null;
};
// PHP 7.0 起就是致命错误的写法：现网 7.x 上所在文件本来就无法运行
$deadSniffs = '/ForbiddenBreakContinueOutsideLoop|ForbiddenGlobalVariableVariable|RemovedNewReference/';
// 升级后不会报错、也不改变运行结果的提示：
//   移除的 ini 项（ini_get/ini_set 只返回 false）、PHP 7.0 起就已生效的语法变化（现网就是这个行为）、
//   int 传给 bool 参数（非严格模式下自动转换）、软保留字
$harmlessSniffs = '/RemovedIniDirectives|NewUniformVariableSyntax\.Found|ArgumentFunctionsReportCurrentValue|ChangedIntToBoolParamType/';

$classify = static function (string $source) use ($advice): array {
    foreach ($advice as [$re, $title, $tip]) {
        if (preg_match($re, $source)) {
            return [$title, $tip];
        }
    }
    return ['其他兼容性问题', ''];
};

/** 把 PHPCompatibility 常见的英文提示翻译成中文（翻不了的保留原文） */
$translate = static function (string $msg): string {
    $use = static fn (array $m, int $i): string => isset($m[$i]) && $m[$i] !== '' ? "；替代：{$m[$i]}" : '';
    $patterns = [
        '/^Function (\S+) is deprecated since PHP ([\d.]+) and removed since PHP ([\d.]+)(?:; Use (.+) instead)?$/'
            => static fn ($m) => "函数 {$m[1]} 自 PHP {$m[2]} 弃用，PHP {$m[3]} 已删除" . $use($m, 4),
        '/^Function (\S+) is removed since PHP ([\d.]+)(?:; Use (.+) instead)?$/'
            => static fn ($m) => "函数 {$m[1]} 在 PHP {$m[2]} 已删除" . $use($m, 3),
        '/^Function (\S+) is deprecated since PHP ([\d.]+)(?:; Use (.+) instead)?$/'
            => static fn ($m) => "函数 {$m[1]} 自 PHP {$m[2]} 弃用" . $use($m, 3),
        '/^Extension \'(\S+)\' is deprecated since PHP ([\d.]+) and removed since PHP ([\d.]+)(?:; Use (.+) instead)?$/'
            => static fn ($m) => "扩展 {$m[1]} 自 PHP {$m[2]} 弃用，PHP {$m[3]} 已删除" . $use($m, 4),
        '/^The constant "(\S+)" is deprecated since PHP ([\d.]+) and removed since PHP ([\d.]+)(?:; Use (.+) instead)?$/'
            => static fn ($m) => "常量 {$m[1]} 自 PHP {$m[2]} 弃用，PHP {$m[3]} 已删除" . $use($m, 4),
        '/^The constant "(\S+)" is deprecated since PHP ([\d.]+)(?:; Use (.+) instead)?$/'
            => static fn ($m) => "常量 {$m[1]} 自 PHP {$m[2]} 弃用" . $use($m, 3),
    ];
    foreach ($patterns as $re => $fn) {
        if (preg_match($re, $msg, $m)) {
            return $fn($m);
        }
    }
    return $msg;
};

// ---------------------------------------------------------------------------
// 汇总数据
// ---------------------------------------------------------------------------
$fixed = [];          // 规则短名 => [文件...]
$fixedFiles = [];
foreach ($rector['file_diffs'] ?? [] as $fd) {
    $file = $rel($fd['file']);
    $fixedFiles[$file] = true;
    foreach ($fd['applied_rectors'] as $cls) {
        $short = substr((string) strrchr('\\' . $cls, '\\'), 1);
        $fixed[$short][$file] = true;
    }
}
ksort($fixed);

// Rector 无法解析的文件：用 PHP 7 语法再解析一次，PHP 7 下也解析失败的说明现网上这个文件本来就无法运行
$deadFiles = [];      // 文件 => 原因
$parseFail = [];      // PHP 7 下能运行、但工具无法解析的文件 => 原因（需人工）
$p7 = null;
if (($rector['errors'] ?? []) !== [] && is_file(dirname(__DIR__) . '/vendor/rector/rector/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/rector/rector/vendor/autoload.php';
    $p7 = (new \PhpParser\ParserFactory())->createForVersion(\PhpParser\PhpVersion::fromString($from !== '' && version_compare($from, '8.0', '<') ? $from : '7.4'));
}
foreach ($rector['errors'] ?? [] as $e) {
    if (!isset($e['file'])) {
        continue;
    }
    $file = $rel($e['file']);
    $msg = mb_substr((string) $e['message'], 0, 200);
    $ok7 = false;
    if ($p7 !== null && is_file($e['file'])) {
        try {
            $p7->parse((string) file_get_contents($e['file']));
            $ok7 = true;
        } catch (\Throwable) {
        }
    }
    if ($ok7) {
        $parseFail[$file] = '第 ' . ($e['line'] ?? '?') . " 行：{$msg}";
    } else {
        $deadFiles[$file] = 'PHP 7 下也有语法错误（第 ' . ($e['line'] ?? '?') . " 行：{$msg}），现网无法加载这个文件";
    }
}

// 修改后语法检查失败、但修改前的原文件也报同样错误的：现网本来就无法加载
foreach (@file((string) ($opt['lintpre'] ?? '')) ?: [] as $l) {
    if (preg_match('/^(?:PHP )?(?:Fatal|Parse) error:\s*(.+?) in (.+?) on line (\d+)/', trim($l), $mm)) {
        $deadFiles[$rel($mm[2])] = "PHP 7 下也会报错（第 {$mm[3]} 行：{$mm[1]}），现网无法加载这个文件";
    }
}

$groups = [];         // 需人工：分类标题 => ['tip'=>, 'items'=>[]]
$vendor = [];         // 第三方库：包名 => [错误数, 警告数]
$errCount = $deadCount = 0;
$dead = [];           // 站点当前版本上就已失效的代码：文件 => [[行, 说明]...]
$compat = [];         // 由兼容层兜底：函数名 => [[文件, 行]...]
$harmless = [];       // 不影响运行：分类标题 => 处数
$harmlessCount = 0;
$csv = [['类型', '分类', '文件', '行', '说明(原文)', '检测规则']];
foreach ($phpcs['files'] ?? [] as $path => $info) {
    $file = $rel($path);
    $linesWithOther = [];
    foreach ($info['messages'] as $m) {
        if (!str_contains($m['source'], '.RemovedExtensions.')) {
            $linesWithOther[$m['line']] = true;
        }
    }
    foreach ($info['messages'] as $m) {
        // PHPCS 自身的提示（混合换行符、文件里没有 PHP 代码）与版本升级无关，忽略
        if (str_starts_with($m['source'], 'Internal.')) {
            continue;
        }
        // 同一行既报“扩展已删除”又报“函数已删除”时，只保留更具体的函数那条
        if (str_contains($m['source'], '.RemovedExtensions.') && isset($linesWithOther[$m['line']])) {
            continue;
        }
        $isError = $m['type'] === 'ERROR';
        $lib = $bundledLib($file);
        if ($isVendor($file) || $lib !== null) {
            if ($lib !== null) {
                $pkg = $lib;
            } else {
                preg_match('#(?:^|/)vend[oe]r2?/([^/]+/[^/]+)/#', $file, $mm)
                    || preg_match('#(?:^|/)vend[oe]r2?/([^/]+)#', $file, $mm);
                $pkg = $mm[1] ?? 'vendor';
            }
            $vendor[$pkg] ??= [0, 0];
            $vendor[$pkg][$isError ? 0 : 1]++;
            continue;
        }
        // 在站点当前运行的版本里就已经被删除/会致命错误的写法：这段代码现在就不可能正常执行，
        // 多为废弃代码或从未走到的分支，单独列出、低优先级
        $rv = $removedIn($m['message']);
        if (isset($deadFiles[$file]) || ($isError && preg_match($deadSniffs, $m['source']))
            || ($from !== '' && $isError && $rv !== null && version_compare($rv, $from, '<='))) {
            $dead[$file][] = [(int) $m['line'], $translate($m['message'])];
            $deadCount++;
            $csv[] = ['现网已失效', '当前版本已删除/无法运行的写法', $file, $m['line'], $m['message'], $m['source']];
            continue;
        }
        $fn = $compatFunc($m['source']);
        if ($fn !== null) {
            $compat[$fn][] = [$file, (int) $m['line']];
            $csv[] = ['兼容层兜底', $fn . '()', $file, $m['line'], $m['message'], $m['source']];
            continue;
        }
        [$title, $tip] = $classify($m['source']);
        if (!$isError || preg_match($harmlessSniffs, $m['source'])) {
            $harmless[$title] = ($harmless[$title] ?? 0) + 1;
            $harmlessCount++;
            $csv[] = ['不影响运行', $title, $file, $m['line'], $m['message'], $m['source']];
            continue;
        }
        $groups[$title]['tip'] = $tip;
        $groups[$title]['items'][] = [$isError, $file, (int) $m['line'], $translate($m['message'])];
        $errCount++;
        $csv[] = ['需人工处理', $title, $file, $m['line'], $m['message'], $m['source']];
    }
}
foreach ($deadFiles as $file => $why) {
    if (!isset($dead[$file]) && !$isVendor($file) && $bundledLib($file) === null) {
        $dead[$file][] = [0, $why];
        $deadCount++;
    }
    $csv[] = ['现网已失效', '文件有语法错误', $file, '', $why, 'phpup.ParseError'];
}
foreach ($parseFail as $file => $why) {
    $groups['工具无法解析的文件']['tip'] = '文件在 PHP 7 下语法正确，但新版解析器无法解析（通常是用了 PHP 8 新增的保留字作函数/类名，如 match），需要人工改名。';
    $groups['工具无法解析的文件']['items'][] = [true, $file, 0, $why];
    $errCount++;
    $csv[] = ['需人工处理', '工具无法解析的文件', $file, '', $why, 'phpup.ParseError'];
}
foreach ($static['unsafe'] as $u) {
    if ($isVendor($u['file'])) {
        continue;
    }
    $groups['静态方式调用非静态方法']['tip'] = 'PHP 8 起 `类名::方法()` 调用未声明 static 的方法会直接报错。下面这些因为方法里用到了 $this 等原因无法自动加 static，需要改成先 new 对象再调用。';
    $groups['静态方式调用非静态方法']['items'][] = [true, $u['file'], (int) $u['line'], "{$u['call']}：{$u['reason']}"];
    $errCount++;
    $csv[] = ['需人工处理', '静态方式调用非静态方法', $u['file'], $u['line'], $u['call'] . ' ' . $u['reason'], 'phpup.StaticCallNonStatic'];
}
uasort($groups, static fn ($a, $b) => count($b['items']) <=> count($a['items']));
arsort($harmless);
ksort($compat);
ksort($vendor);
$lintErrors = $lint === '' ? [] : array_values(array_filter(explode("\n", $lint), static fn ($l) => str_contains($l, 'error')));
$errCount += count($lintErrors);
$shortFixed = array_filter($prefix, static fn ($s) => isset($s['short_tags']));
$refNewFixed = array_filter($prefix, static fn ($s) => isset($s['ref_new']));
$staticFixedCount = array_sum(array_map('count', $static['fixed']));
$compatCount = array_sum(array_map('count', $compat));

// ---------------------------------------------------------------------------
// 输出 Markdown
// ---------------------------------------------------------------------------
$modeName = ['scan' => '检测（未修改代码）', 'preview' => '预演（在副本上修复，原代码未修改）', 'apply' => '正式修复（已写入原代码）'][$mode];
$fixMode = $mode !== 'scan';
$md = [];
$md[] = "# PHP {$target} 升级报告：" . basename($site);
$md[] = '';
$md[] = "- 站点目录：`{$site}`";
$md[] = "- 模式：{$modeName}";
$md[] = "- 识别为：{$profile}" . ($excluded !== [] ? '（已自动跳过数据/缓存目录：`' . implode('`、`', $excluded) . '`）' : '');
if ($from !== '') {
    $md[] = "- 站点当前 PHP 版本：{$from}（升级到 {$target}）";
}
$md[] = '- 生成时间：' . date('Y-m-d H:i:s');
$md[] = '';
$md[] = '## 一、总览';
$md[] = '';
if ($fixMode) {
    $md[] = $errCount === 0
        ? '**结论：业务代码里没有剩下需要人工修改的地方。** 按第五节启用兼容层和 php.ini 设置后即可在新版本上测试。'
        : "**结论：还有 {$errCount} 处需要人工确认（见第四节），其余全部已自动处理。**";
    $md[] = '';
}
$md[] = '| 项目 | 数量 |';
$md[] = '|---|---|';
if ($fixMode) {
    $md[] = '| 自动修复的文件（合计） | ' . count($fixedFiles + $prefix + $static['fixed'] + $php4ctor + $framework + $runtime) . ' |';
    $md[] = '| 其中：短标签 `<?` 改为 `<?php` 的文件 | ' . count($shortFixed) . ' |';
    $md[] = '| 其中：给被静态调用的方法加 static | ' . $staticFixedCount . ' 个方法 |';
    $md[] = '| 修复后语法错误 | ' . count($lintErrors) . ' |';
}
$md[] = "| **需人工处理** | **{$errCount}** |";
$md[] = "| 由兼容层兜底（启用兼容层即可，不用改代码） | {$compatCount} |";
$md[] = "| 不影响运行的弃用提示（可以不管） | {$harmlessCount} |";
$md[] = "| 现网上已经失效的代码（不用管，建议日后清理） | {$deadCount} |";
$md[] = '| 安全提示 | ' . count($security) . ' |';
$md[] = '| 第三方库中的问题 | ' . array_sum(array_map('array_sum', $vendor)) . ' |';
$md[] = '';

if ($fixMode) {
    $md[] = '## 二、自动修复明细';
    $md[] = '';
    $md[] = '所有改写都按“升级后运行结果与现在 PHP ' . ($from ?: '7') . ' 上一致”的原则进行，不涉及业务逻辑。标 ⚠️ 的项目建议重点测试相关页面。'
        . ($mode === 'preview' ? '完整差异见同目录的 `changes.diff`。' : '');
    $md[] = '';
    $md[] = '| 修复内容 | 文件数 | 涉及文件 |';
    $md[] = '|---|---|---|';
    $row = static function (string $text, array $list) use (&$md): void {
        $shown = implode('<br>', array_map(static fn ($f) => "`{$f}`", array_slice($list, 0, 10)));
        if (count($list) > 10) {
            $shown .= '<br>…等 ' . count($list) . ' 个';
        }
        $md[] = "| {$text} | " . count($list) . " | {$shown} |";
    };
    if ($shortFixed !== []) {
        $row('短标签 `<?` 改为 `<?php`（新服务器无论 short_open_tag 开关如何都能正常运行）', array_keys($shortFixed));
    }
    if ($refNewFixed !== []) {
        $row('`=& new` 改为 `= new`（PHP 7 起是语法错误，这些文件现网本来无法加载）', array_keys($refNewFixed));
    }
    if ($php4ctor !== []) {
        $row('PHP4 风格构造函数补充处理：父类不在站点里、Rector 跳过的类，以及子类里 `parent::父类名()` 的调用，统一改为 `__construct`', array_keys($php4ctor));
    }
    if ($framework !== []) {
        $row('框架补丁：ThinkPHP 的错误处理不再把 PHP 8 新增的 Deprecated 提示当成致命错误（否则整站 500）；时区旧别名 PRC 等改为等价的 Asia/Shanghai 等', array_keys($framework));
    }
    $rtKinds = [
        'num'   => '空字符串 / 非数字字符串参与 + - * % 运算：PHP 7 当 0 计算，PHP 8 直接致命错误。改为经过 `phpup_num()`，结果与 PHP 7 相同',
        'div'   => '除法改为 `phpup_div()`：除数为 0 或空字符串时 PHP 8 致命错误，改后与 PHP 7 结果相同（INF），其余情况结果不变',
        'count' => '`count()` 的参数可能不是数组（如 null）：PHP 8 致命错误。改为 `phpup_count()`，结果与 PHP 7 相同',
        'arr'   => '`in_array()` / `array_merge()` / `implode()` 等收到 null 或非数组：PHP 8 致命错误。改为经过 `phpup_arrfn()`，结果与 PHP 7 相同',
        'res'   => '文件函数：`fread()` / `readdir()` 等收到 false（打开失败时），或 `file_get_contents()` / `fopen()` 等收到空路径：PHP 8 致命错误。改为经过 `phpup_resfn()` / `phpup_pathfn()`，正常情况结果不变',
        'argc'  => '内置函数参数个数不对（如 `mysqli_query("BEGIN")` 少了数据库连接参数）：PHP 7 警告后跳过，PHP 8 致命错误。改为经过 `phpup_badcall()`，保持 PHP 7 的行为（跳过不执行，写一条错误日志）',
        'const' => '没加引号的常量名（如 `function_exists(getcvar)`）：PHP 7 当字符串，PHP 8 致命错误。补上引号',
    ];
    foreach ($rtKinds as $k => $text) {
        $list = array_keys(array_filter($runtime, static fn ($s) => !empty($s[$k])));
        if ($list !== []) {
            $row($text, $list);
        }
    }
    if ($static['fixed'] !== []) {
        $row('被 `类名::方法()` 静态调用、但没声明 static 的方法加上 static（PHP 8 起不加会直接报错）', array_keys($static['fixed']));
    }
    foreach ($fixed as $short => $files) {
        [$text, $review] = $ruleText[$short] ?? [$short, false];
        $row(($review ? '⚠️ ' : '') . $text, array_keys($files));
    }
    $md[] = '';
    $md[] = '## 三、语法检查';
    $md[] = '';
    if ($lintErrors === []) {
        $md[] = '所有被修改的文件都通过了 `php -l` 语法检查。';
    } else {
        $md[] = '以下文件修复后仍有语法错误，请人工检查：';
        $md[] = '';
        $md[] = '```';
        foreach ($lintErrors as $l) {
            $md[] = str_replace($root . '/', '', str_replace(DIRECTORY_SEPARATOR, '/', $l));
        }
        $md[] = '```';
    }
    $md[] = '';
}

$md[] = '## 四、需人工处理';
$md[] = '';
if ($groups === []) {
    $md[] = '没有。';
} else {
    if ($mode === 'scan') {
        $md[] = '注意：这是修复**前**的检测结果，其中大部分会被 `preview`/`apply` 自动修复。请运行 `preview` 查看自动修复后剩下的问题。';
        $md[] = '';
    }
    $md[] = '下面这些在 PHP ' . $target . ' 上会报错，工具无法保证自动改写后行为不变。完整清单另见 `人工处理清单.csv`（可用 Excel 打开）。';
    $md[] = '';
    foreach ($groups as $title => $g) {
        $md[] = "### {$title}（" . count($g['items']) . ' 处）';
        $md[] = '';
        if ($g['tip'] !== '') {
            $md[] = "处理建议：{$g['tip']}";
            $md[] = '';
        }
        foreach (array_slice($g['items'], 0, 50) as [$isError, $file, $line, $msg]) {
            $md[] = "- 🔴 `{$file}" . ($line > 0 ? ":{$line}" : '') . '` ' . $msg;
        }
        if (count($g['items']) > 50) {
            $md[] = '- …其余 ' . (count($g['items']) - 50) . ' 处见 CSV';
        }
        $md[] = '';
    }
}

$md[] = '## 五、上线配置：兼容层 + php.ini';
$md[] = '';
$md[] = '本目录下的 `上线配置/` 里有两个文件：';
$md[] = '';
$md[] = '1. `phpup_compat.php`：兼容函数库，把 PHP 8 删除的 each()、create_function() 等函数补回来（行为与 PHP 7 相同）。'
    . '能静态改写的调用工具已经改掉了，它负责兜底“动态拼出来的调用”这类漏网情况。放到服务器任意位置，在 php.ini 里用 `auto_prepend_file` 指向它即可，**不用改任何代码**。';
$md[] = '2. `php-phpup.ini`：建议的 php.ini 设置，追加到新服务器的 php.ini（或放进 conf.d 目录）。';
$md[] = '';
$rtFiles = array_filter($runtime, static fn ($s) => array_sum($s) - ($s['const'] ?? 0) > 0);
if ($rtFiles !== []) {
    $md[] = '**`phpup_compat.php` 必须放在网站根目录**（升级包里已包含，' . ($mode === 'apply' ? '本次已复制到站点根目录' : '随升级包一起上传即可') . '）：有 '
        . count($rtFiles) . ' 个文件开头用 `require_once` 直接加载它（运行时类型修复用到的 phpup_num 等函数），不配置 php.ini 也能运行；删掉这个文件这些页面会报错。';
    $md[] = '';
}
if ($compat === []) {
    $md[] = '本站点修复后的代码里已经没有需要兼容层兜底的函数调用' . ($rtFiles !== [] ? '，php.ini 里的 auto_prepend_file 可配可不配（配上也没有副作用）。' : '，兼容层可装可不装（装上也没有副作用）。');
} else {
    $md[] = '本站点修复后仍有以下调用依赖兼容层（多为参数是变量、无法静态改写的情况），**请务必启用兼容层**：';
    $md[] = '';
    foreach ($compat as $fn => $items) {
        $md[] = "- `{$fn}()` " . count($items) . ' 处：' . implode('、', array_map(static fn ($i) => "`{$i[0]}:{$i[1]}`", array_slice($items, 0, 5))) . (count($items) > 5 ? ' …' : '');
    }
}
$md[] = '';

$md[] = '## 六、不影响运行的弃用提示（可以不管）';
$md[] = '';
if ($harmless === []) {
    $md[] = '无。';
} else {
    $md[] = '这些在新版本上只会在错误日志里留下 Deprecated 提示，**页面照常运行、结果不变**。按 `php-phpup.ini` 关闭 E_DEPRECATED 后日志里也不会出现。明细见 CSV（类型为“不影响运行”）。';
    $md[] = '';
    $md[] = '| 类别 | 处数 |';
    $md[] = '|---|---|';
    foreach ($harmless as $title => $n) {
        $md[] = "| {$title} | {$n} |";
    }
}
$md[] = '';

$md[] = '## 七、现网已失效的代码（不用管）';
$md[] = '';
if ($dead === []) {
    $md[] = '无。';
} else {
    $md[] = '下面这些写法在站点**当前运行的 PHP ' . ($from ?: '7.x') . ' 上就已经无法运行**（函数早已删除，或文件本身有语法错误）。'
        . '既然站点现在正常，说明这些代码根本没有被执行（废弃文件、备份文件、走不到的分支）。升级不会让情况变差，所以工具不去动它们；建议日后确认后直接删除。';
    $md[] = '';
    $md[] = '| 文件 | 处数 | 示例 |';
    $md[] = '|---|---|---|';
    uasort($dead, static fn ($a, $b) => count($b) <=> count($a));
    foreach (array_slice($dead, 0, 100, true) as $file => $items) {
        $md[] = "| `{$file}` | " . count($items) . ' | ' . ($items[0][0] > 0 ? '第 ' . $items[0][0] . ' 行：' : '') . str_replace('|', '\\|', $items[0][1]) . ' |';
    }
    if (count($dead) > 100) {
        $md[] = '| …其余 ' . (count($dead) - 100) . ' 个文件见 CSV | | |';
    }
}
$md[] = '';

$md[] = '## 八、安全提示';
$md[] = '';
if ($security === []) {
    $md[] = '未发现上传目录中的 PHP 文件、伪装成 PHP 的图片或典型后门写法。';
} else {
    $md[] = '顺带做的只读检查，**工具没有修改或删除这些文件**，请人工确认：上传/附件目录里正常不应出现 .php 文件；内容是图片却以 .php 结尾的文件通常说明上传功能没有限制后缀，是常见的入侵手法。'
        . '建议确认后删除可疑文件，并在 Web 服务器上禁止上传目录执行 PHP。';
    $md[] = '';
    foreach ($security as $file => $reasons) {
        $md[] = "- `{$file}`：" . implode('；', $reasons);
        $csv[] = ['安全提示', '可疑文件', $file, '', implode('；', $reasons), 'phpup.Security'];
    }
}
$md[] = '';

$md[] = '## 九、第三方库（vendor/ 及直接拷进目录的库）';
$md[] = '';
if ($vendor === []) {
    $md[] = '在已检测的范围内未发现第三方库的兼容性问题。';
} else {
    $md[] = '直接拷进业务目录的老库（如 PHPExcel、PEAR）工具已照常做了兼容修复，下表是修复后仍有提示的数量（大多是弃用提示）。这类库本身已停止维护，建议日后换成新库。';
    $md[] = '';
    $md[] = '| 包 | 错误 | 弃用提示 |';
    $md[] = '|---|---|---|';
    foreach ($vendor as $pkg => [$e, $w]) {
        $md[] = "| `{$pkg}` | {$e} | {$w} |";
    }
}
if ($vendorDirs !== []) {
    $md[] = '';
    $md[] = '站点里有以下 composer 依赖目录，默认**没有检测、也没有修改**（加 `--include-vendor` 可以一起处理）。'
        . '请在对应的 composer.json 处执行 `composer update`，或确认这些库的版本支持 PHP ' . $target . '：';
    $md[] = '';
    foreach ($vendorDirs as $vd) {
        $md[] = "- `{$vd}/`";
    }
}
$md[] = '';
if ($keepShort && $shortTags !== []) {
    $md[] = '## 附：短标签 `<?`（按要求未修改）';
    $md[] = '';
    $md[] = '以下文件用了短标签 `<?`。新服务器必须设置 `short_open_tag = On`，否则这些代码会被当成文本**直接显示在页面上**。';
    $md[] = '';
    foreach (array_slice($shortTags, 0, 100, true) as $file => $lines) {
        $md[] = "- `{$file}` 第 " . implode(', ', array_slice($lines, 0, 10)) . (count($lines) > 10 ? ' …' : '') . ' 行';
    }
    $md[] = '';
}
$md[] = '## 十、静态检测发现不了、测试时留意的变化';
$md[] = '';
$md[] = '- **字符串与数字比较（8.0）**：`0 == "abc"` 以前为 true，现在为 false。涉及用户输入比较、`in_array`/`switch` 判断的地方要测试。';
$md[] = '- **类型错误变为致命错误（8.0）**：对 null/字符串调用 `count()`、对数组做算术运算、给内置函数传错类型，现在会抛 TypeError。';
$md[] = '- **未定义变量/数组下标（8.0）**：从 Notice 升级为 Warning。生产环境按 `php-phpup.ini` 关闭 display_errors，只写日志。';
$md[] = '- **数据库错误处理**：PHP 8.1 起 mysqli 出错默认抛异常（兼容层已恢复为 PHP 7 的“返回 false”）；PDO 从 8.0 起默认 ERRMODE_EXCEPTION，兼容层无法全局改回，如代码依赖 PDO 出错返回 false，需在连接后设置 PDO::ATTR_ERRMODE。';
$md[] = '- **数据库 SSL 连接**：MySQL 5.6 只支持 TLS 1.0/1.1，PHP 8.5 搭配的 OpenSSL 3 默认拒绝。普通明文连接不受影响；如果用 SSL 连 5.6 的数据库，上线前先在测试环境连一次。MySQL 8.0 的 caching_sha2_password 认证 PHP 8.5 完全支持（需 openssl 扩展）。';
$md[] = '- **扩展**：新服务器需要安装站点用到的扩展（mysqli、pdo_mysql、gd、mbstring、curl、openssl 等）。';
$md[] = '- 建议上线前先在测试环境用新版 PHP 跑一遍主要页面，并把 PHP 错误日志里的 Fatal/Warning 拿来对照。';
$md[] = '';

file_put_contents($opt['out'] . '/报告.md', implode("\n", $md) . "\n");

$fp = fopen($opt['out'] . '/人工处理清单.csv', 'w');
fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM，方便 Excel 直接打开不乱码
foreach ($csv as $row) {
    fputcsv($fp, $row, ',', '"', '\\');
}
fclose($fp);

echo "\n汇总：";
if ($fixMode) {
    echo '自动修复 ' . count($fixedFiles + $prefix + $static['fixed'] + $php4ctor + $framework + $runtime) . ' 个文件，语法错误 ' . count($lintErrors) . ' 个，';
}
echo "需人工处理 {$errCount} 处，兼容层兜底 {$compatCount} 处，不影响运行 {$harmlessCount} 处，现网已失效 {$deadCount} 处，安全提示 " . count($security) . " 个\n";
