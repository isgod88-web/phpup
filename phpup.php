<?php

/**
 * phpup —— PHP 版本升级代码自动迁移工具（PHP 版主程序，Windows / Linux / macOS 通用）
 *
 * 与 phpup.sh 功能相同，但不依赖 bash、find、tar、diff 等命令，Windows 上双击 .bat 即可运行。
 *
 * 用法:
 *   php phpup.php scan    <站点目录> [选项]   只检测
 *   php phpup.php preview <站点目录> [选项]   预演：在副本上修复，输出差异和报告（不改原代码）
 *   php phpup.php apply   <站点目录> [选项]   正式写入：先备份（zip），再修复
 *   php phpup.php restore <备份.zip> <站点目录>  从备份还原
 *
 * 选项与 phpup.sh 相同：--target 8.5  --php <路径>  --strict-null  --include-vendor  --exclude a,b
 *                      --from 7.3  --keep-short-tags  --yes  --reports <目录>
 *   --server-root <路径>   服务器上网站根目录（如 /var/www/example.com），写进升级包里的 php.ini 设置；不填则留占位符
 *
 * preview / apply 完成后会在报告目录生成「升级包-站点名-时间.zip」：只含有变更的文件（保持原目录结构）
 * + phpup_compat.php + 上线配置 + 变更文件清单 + 上线步骤，直接上传到正式环境即可。
 */

declare(strict_types=1);

const KIT = __DIR__;
const CODE_EXT = '/\.(php|inc|phtml)$/i';

ini_set('memory_limit', '-1');
set_time_limit(0);

function info(string $msg): void
{
    echo '==> ', $msg, PHP_EOL;
}

function fail(string $msg): never
{
    fwrite(STDERR, '错误: ' . $msg . PHP_EOL);
    exit(1);
}

/** 统一成正斜杠（Windows 的 PHP 也接受 /） */
function norm(string $p): string
{
    return rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $p), '/');
}

/** 运行一个命令，返回退出码；$stdout/$stderr 为文件路径时写入文件，null 时直接显示 */
function run(array $cmd, array $env = [], ?string $stdout = null, ?string $stderr = null): int
{
    $spec = [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => $stdout !== null ? ['file', $stdout, 'w'] : STDOUT,
        2 => $stderr !== null ? ['file', $stderr, 'w'] : STDERR];
    $proc = proc_open($cmd, $spec, $pipes, null, $env === [] ? null : array_merge(getenv(), $env));
    if (!is_resource($proc)) {
        fail('无法启动: ' . implode(' ', $cmd));
    }
    return proc_close($proc);
}

/** 运行命令并取回输出（标准输出 + 错误输出） */
function capture(array $cmd): array
{
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($proc), (string) $out];
}

/** 遍历目录下的代码文件，返回相对路径（正斜杠） */
function code_files(string $dir, array $pruneDirs = []): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $f) use ($dir, $pruneDirs): bool {
                if ($f->isDir()) {
                    $rel = substr(norm($f->getPathname()), strlen($dir) + 1);
                    return !in_array($rel, $pruneDirs, true);
                }
                return true;
            }
        )
    );
    foreach ($it as $f) {
        if ($f->isFile() && preg_match(CODE_EXT, $f->getFilename())) {
            $files[] = substr(norm($f->getPathname()), strlen($dir) + 1);
        }
    }
    sort($files);
    return $files;
}

function copy_file(string $from, string $to): void
{
    if (!is_dir(dirname($to))) {
        mkdir(dirname($to), 0777, true);
    }
    // 文件名含 Windows 不允许的字符、或复制过程中被删掉时跳过，不中断整个流程
    if (!is_file($from) || !@copy($from, $to)) {
        fwrite(STDERR, "    跳过无法复制的文件: $from\n");
        return;
    }
    @touch($to, (int) @filemtime($from));
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

// ---------------------------------------------------------------------------
// 参数解析
// ---------------------------------------------------------------------------
$argvList = array_slice($argv, 1);
$usage = static function (): never {
    $doc = (string) file_get_contents(__FILE__);
    preg_match('#/\*\*(.+?)\*/#s', $doc, $m);
    echo preg_replace('/^ \* ?/m', '', $m[1] ?? ''), PHP_EOL;
    exit(1);
};
if (count($argvList) < 2) {
    $usage();
}
$cmd = array_shift($argvList);

if ($cmd === 'restore') {
    [$backup, $siteArg] = $argvList + [null, null];
    if (!is_file((string) $backup)) {
        fail("备份文件不存在: {$backup}");
    }
    if (!is_dir((string) $siteArg)) {
        fail("站点目录不存在: {$siteArg}");
    }
    $zip = new ZipArchive();
    if ($zip->open($backup) !== true) {
        fail("无法打开备份: {$backup}");
    }
    info("从 {$backup} 还原到 {$siteArg}");
    $zip->extractTo($siteArg);
    $zip->close();
    info('还原完成');
    exit(0);
}

$opt = ['target' => '8.5', 'php' => PHP_BINARY, 'strict-null' => false, 'include-vendor' => false, 'exclude' => '',
    'from' => '7.3', 'keep-short-tags' => false, 'yes' => false, 'reports' => KIT . '/reports',
    'server-root' => ''];
$siteArg = array_shift($argvList);
while ($argvList !== []) {
    $a = array_shift($argvList);
    $name = ltrim((string) $a, '-');
    if ($a === '-y') {
        $name = 'yes';
    }
    if (!array_key_exists($name, $opt)) {
        fail("未知选项: {$a}");
    }
    $opt[$name] = is_bool($opt[$name]) ? true : (string) array_shift($argvList);
}
if (!in_array($opt['target'], ['8.2', '8.3', '8.4', '8.5'], true)) {
    fail('--target 只支持 8.2 / 8.3 / 8.4 / 8.5');
}
if (!is_dir((string) $siteArg)) {
    fail("站点目录不存在: {$siteArg}");
}
if (!in_array($cmd, ['scan', 'preview', 'apply'], true)) {
    $usage();
}
$SITE = norm((string) realpath($siteArg));
$SITE_NAME = basename($SITE);
$PHP = $opt['php'];

// ---------------------------------------------------------------------------
// 识别常见程序，自动排除“数据/缓存”目录
// ---------------------------------------------------------------------------
$profile = '通用';
$autoExclude = [];
if (is_file("{$SITE}/e/class/connect.php")) {
    $profile = '帝国CMS (EmpireCMS)';
    $autoExclude = array_merge($autoExclude, ['d/txt', 'e/data/tmp']);
}
if (is_dir("{$SITE}/thinkphp") || is_file("{$SITE}/think")) {
    $profile = 'ThinkPHP';
    $autoExclude[] = 'runtime';
}
$excludeGlobs = array_map(static fn ($d) => "*/{$d}/*", $autoExclude);
foreach (array_filter(array_map('trim', explode(',', $opt['exclude']))) as $p) {
    $excludeGlobs[] = $p;
}
if (!$opt['include-vendor']) {
    $excludeGlobs[] = '*/vendor/*';
}
$EXCLUDE_CSV = implode(',', $excludeGlobs);

// ---------------------------------------------------------------------------
// 环境检查
// ---------------------------------------------------------------------------
if (PHP_VERSION_ID < 80100) {
    fail('运行本工具需要 PHP 8.1+（当前 ' . PHP_VERSION . '）');
}
foreach (['tokenizer', 'json', 'mbstring'] as $ext) {
    if (!extension_loaded($ext)) {
        fail("PHP 缺少 {$ext} 扩展");
    }
}
if (in_array($cmd, ['preview', 'apply'], true) && !class_exists(ZipArchive::class)) {
    fail('PHP 缺少 zip 扩展，无法生成备份和升级包（请在 php.ini 中启用 extension=zip）');
}
$RECTOR = KIT . '/vendor/rector/rector/bin/rector';
$PHPCS = KIT . '/vendor/squizlabs/php_codesniffer/bin/phpcs';
if (!is_file($RECTOR) || !is_file($PHPCS)) {
    fail('依赖未安装：工具目录下缺少 vendor/，请使用完整的工具包，或在工具目录执行 composer install');
}
// 每次都按本机路径注册 PHPCompatibility 规则集（工具目录可能被拷到别的地方）
run([PHP_BINARY, $PHPCS, '--config-set', 'installed_paths',
    KIT . '/vendor/phpcompatibility/php-compatibility,' . KIT . '/vendor/phpcsstandards/phpcsutils'], [], null, null);

info("识别为：{$profile}" . ($autoExclude ? '（自动跳过数据/缓存目录：' . implode(' ', $autoExclude) . '）' : ''));
$STAMP = date('Ymd-His');
$OUT = norm($opt['reports']) . "/{$SITE_NAME}-{$cmd}-{$STAMP}";
mkdir($OUT, 0777, true);

$ENV = ['PHPUP_TARGET' => $opt['target'], 'PHPUP_STRICT_NULL' => $opt['strict-null'] ? '1' : '0',
    'PHPUP_INCLUDE_VENDOR' => $opt['include-vendor'] ? '1' : '0', 'PHPUP_EXCLUDE' => $EXCLUDE_CSV,
    'PHPUP_CACHE_DIR' => "{$OUT}/.rector-cache"];

// ---------------------------------------------------------------------------
// 各步骤
// ---------------------------------------------------------------------------
$runPhpcs = static function (string $dir) use ($opt, $PHPCS, $EXCLUDE_CSV, $OUT): void {
    info("检测与 PHP {$opt['target']} 不兼容的代码（PHPCompatibility）...");
    $ignore = '*/node_modules/*,*/templates_c/*,*/cache/*,*/runtime/*,*/storage/framework/*,*/.git/*' . ($EXCLUDE_CSV !== '' ? ",{$EXCLUDE_CSV}" : '');
    $args = [PHP_BINARY, '-d', 'memory_limit=-1', $PHPCS, '-q', '--standard=PHPCompatibility', '--runtime-set', 'testVersion', $opt['target'],
        '--extensions=php,inc,phtml', "--ignore={$ignore}", '--report=json', "--report-file={$OUT}/phpcs.json"];
    if (PHP_OS_FAMILY !== 'Windows') {
        $args[] = '--parallel=4';
    }
    $args[] = $dir;
    run($args);
    if (!is_file("{$OUT}/phpcs.json") || filesize("{$OUT}/phpcs.json") === 0) {
        fail('PHPCompatibility 扫描失败，未生成报告');
    }
};

$runRector = static function (string $dir) use ($opt, $RECTOR, $OUT, $ENV): void {
    info("自动修复（Rector，目标 PHP {$opt['target']}）...");
    run([PHP_BINARY, '-d', 'memory_limit=-1', $RECTOR, 'process', '--config', KIT . '/rector.php', '--output-format', 'json', '--no-progress-bar'],
        $ENV + ['PHPUP_PATHS' => $dir], "{$OUT}/rector.json", "{$OUT}/rector-stderr.log");
    if (json_decode((string) @file_get_contents("{$OUT}/rector.json"), true) === null) {
        fail("Rector 运行失败，详情见 {$OUT}/rector-stderr.log");
    }
};

$runPrefix = static function (string $dir) use ($opt, $OUT, $EXCLUDE_CSV): void {
    info('预处理：短标签 <? 和 =& new...');
    $args = [PHP_BINARY, '-d', 'short_open_tag=1', KIT . '/lib/prefix_tokens.php', $dir, "{$OUT}/prefix.json", $EXCLUDE_CSV];
    if ($opt['keep-short-tags']) {
        $args[] = '--keep-short-tags';
    }
    run($args);
};

$runStaticCalls = static function (string $dir) use ($OUT, $EXCLUDE_CSV): void {
    info('检查以静态方式调用的非静态方法...');
    run([PHP_BINARY, '-d', 'memory_limit=-1', KIT . '/lib/static_calls.php', $dir, "{$OUT}/staticcalls.json", $EXCLUDE_CSV]);
};

$runPhp4Ctor = static function (string $dir) use ($OUT, $EXCLUDE_CSV): void {
    run([PHP_BINARY, '-d', 'memory_limit=-1', KIT . '/lib/php4_ctor_calls.php', $dir, "{$OUT}/php4ctor.json", $EXCLUDE_CSV]);
    // 框架补丁（ThinkPHP 把 Deprecated 当致命错误、时区旧别名），框架目录不受 --exclude 影响
    run([PHP_BINARY, KIT . '/lib/framework_patches.php', $dir, "{$OUT}/framework.json"]);
    // 运行时类型：'' 参与运算、除以 0、count/in_array 收到 null、没加引号的常量（PHP 8 起是致命错误）
    info('运行时类型检查（空字符串运算、除以 0、数组函数收到 null、未加引号的常量）...');
    run([PHP_BINARY, '-d', 'memory_limit=-1', KIT . '/lib/runtime_types.php', $dir, "{$OUT}/runtime.json", $EXCLUDE_CSV]);
};

/** 帝国CMS 模板缓存 e/data/tmp：从 $src 读，修好的写到 $dst（preview 时是临时副本） */
$runTmpCache = static function (string $src, string $dst) use ($OUT): void {
    if (is_file("{$src}/e/class/connect.php")) {
        run([PHP_BINARY, '-d', 'memory_limit=-1', KIT . '/lib/tmpcache.php', $src, "{$OUT}/tmpcache.json", $dst]);
    }
};

/** 语法检查。修改前就有同样错误（或属于 PHP 7 就会报的编译错误）的，记为“现网本来就无法加载” */
$runLint = static function (string $dir, callable $original) use ($PHP, $OUT): void {
    [, $ver] = capture([$PHP, '-r', 'echo PHP_VERSION;']);
    info("语法检查（{$ver}）...");
    $files = [];
    $rector = json_decode((string) file_get_contents("{$OUT}/rector.json"), true) ?: [];
    foreach ($rector['changed_files'] ?? [] as $f) {
        $files[] = norm($f);
    }
    foreach (['prefix.json', 'staticcalls.json', 'php4ctor.json', 'framework.json', 'runtime.json'] as $j) {
        $x = json_decode((string) @file_get_contents("{$OUT}/{$j}"), true) ?: [];
        foreach (array_keys($x['fixed'] ?? $x) as $rel) {
            $files[] = "{$dir}/{$rel}";
        }
    }
    $php7Fatal = '/must take exactly \d+ argument|cannot take arguments|Cannot redeclare|Cannot re-assign \$this|Cannot use temporary expression in write context/';
    $key = static function (string $out): string {
        foreach (explode("\n", $out) as $l) {
            if (stripos($l, 'error') !== false && !str_starts_with($l, 'Errors parsing')) {
                return (string) preg_replace('/ in .* on line \d+|on line \d+/', '', trim($l));
            }
        }
        return '';
    };
    $lint = $pre = '';
    foreach (array_unique($files) as $f) {
        if (!is_file($f)) {
            continue;
        }
        [$rc, $out] = capture([$PHP, '-l', $f]);
        if ($rc === 0) {
            continue;
        }
        $orig = $original(substr($f, strlen($dir) + 1));
        if (preg_match($php7Fatal, $out) || ($orig !== null && $orig !== '' && $key($orig) === $key($out))) {
            $pre .= trim($out) . "\n";
        } else {
            $lint .= trim($out) . "\n";
        }
    }
    file_put_contents("{$OUT}/lint.txt", $lint);
    file_put_contents("{$OUT}/lint-preexisting.txt", $pre);
};

/** 对原文件做语法检查（短标签按旧服务器的 On），返回错误输出；无错误返回 ''，拿不到原文件返回 null */
$lintFile = static function (?string $path) use ($PHP): ?string {
    if ($path === null || !is_file($path)) {
        return null;
    }
    [$rc, $out] = capture([$PHP, '-d', 'short_open_tag=1', '-l', $path]);
    return $rc === 0 ? '' : $out;
};

$runExtraChecks = static function (string $dir) use ($OUT, $EXCLUDE_CSV, $SITE): void {
    info('检查短标签 <? 和第三方库目录...');
    run([PHP_BINARY, '-d', 'short_open_tag=0', KIT . '/lib/shorttags.php', $dir, "{$OUT}/shorttags.json", $EXCLUDE_CSV]);
    $vendors = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        if ($f->isDir() && in_array($f->getFilename(), ['vendor', 'vender'], true)) {
            $rel = substr(norm($f->getPathname()), strlen($dir) + 1);
            if (!array_filter($vendors, static fn ($v) => str_starts_with($rel, $v . '/'))) {
                $vendors[] = $rel;
            }
        }
    }
    file_put_contents("{$OUT}/vendor-dirs.txt", implode("\n", $vendors) . ($vendors ? "\n" : ''));
    info('安全检查（上传目录里的 PHP 文件、伪装成 PHP 的图片、常见后门写法）...');
    run([PHP_BINARY, KIT . '/lib/security.php', $SITE, "{$OUT}/security.json", $EXCLUDE_CSV]);
};

$makeReport = static function (string $mode, string $root) use ($opt, $OUT, $SITE, $profile, $autoExclude): void {
    foreach (['prefix.json', 'staticcalls.json', 'php4ctor.json', 'framework.json', 'runtime.json'] as $j) {
        if (!is_file("{$OUT}/{$j}")) {
            file_put_contents("{$OUT}/{$j}", '{}');
        }
    }
    foreach (['lint.txt', 'lint-preexisting.txt'] as $t) {
        if (!is_file("{$OUT}/{$t}")) {
            file_put_contents("{$OUT}/{$t}", '');
        }
    }
    // 上线配置：兼容层 + 建议的 php.ini
    @mkdir("{$OUT}/上线配置", 0777, true);
    copy(KIT . '/lib/compat/phpup_compat.php', "{$OUT}/上线配置/phpup_compat.php");
    file_put_contents("{$OUT}/上线配置/php-phpup.ini", strtr((string) file_get_contents(KIT . '/lib/compat/php-phpup.ini'),
        ['@SITE@' => $SITE, '@SHORT@' => $opt['keep-short-tags'] ? 'On' : 'Off']));
    $args = [PHP_BINARY, KIT . '/lib/report.php', "--mode={$mode}", "--site={$SITE}", "--target={$opt['target']}", "--out={$OUT}",
        "--phpcs={$OUT}/phpcs.json", "--rector={$OUT}/rector.json", "--lint={$OUT}/lint.txt", "--lintpre={$OUT}/lint-preexisting.txt",
        "--root={$root}", "--from={$opt['from']}", "--profile={$profile}", "--shorttags={$OUT}/shorttags.json",
        "--vendors={$OUT}/vendor-dirs.txt", '--excluded=' . implode(',', $autoExclude),
        "--prefix={$OUT}/prefix.json", "--php4ctor={$OUT}/php4ctor.json", "--framework={$OUT}/framework.json", "--runtime={$OUT}/runtime.json", "--staticcalls={$OUT}/staticcalls.json", "--security={$OUT}/security.json"];
    if ($opt['keep-short-tags']) {
        $args[] = '--keep-short-tags';
    }
    run($args);
};

/**
 * 生成升级包：只放有变更的文件（保持站点里的目录结构），加上兼容层、php.ini 设置、变更清单和上线步骤。
 * $changed: 相对路径 => 修复后文件的完整路径
 */
$makePackage = static function (array $changed) use ($opt, $OUT, $SITE_NAME, $STAMP): void {
    ksort($changed, SORT_STRING);
    $pkg = "{$OUT}/升级包-{$SITE_NAME}-{$STAMP}.zip";
    $serverRoot = rtrim(trim($opt['server-root']), '/\\');
    $root = $serverRoot !== '' ? $serverRoot : '/改成服务器上网站根目录';
    $zip = new ZipArchive();
    if ($zip->open($pkg, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fail("无法创建升级包: {$pkg}");
    }
    foreach ($changed as $rel => $path) {
        $zip->addFile($path, "{$SITE_NAME}/{$rel}");
    }
    $zip->addFile(KIT . '/lib/compat/phpup_compat.php', "{$SITE_NAME}/phpup_compat.php");
    $zip->addFromString('上线配置/php-phpup.ini', strtr((string) file_get_contents(KIT . '/lib/compat/php-phpup.ini'),
        ['@SITE@' => $root, '@SHORT@' => $opt['keep-short-tags'] ? 'On' : 'Off']));
    $zip->addFromString('变更文件清单.txt', "共 " . count($changed) . " 个文件有变更（另加新增的 phpup_compat.php）：\r\n\r\n"
        . implode("\r\n", array_keys($changed)) . "\r\n");
    $steps = [
        "{$SITE_NAME} 升级包（目标 PHP {$opt['target']}，请使用 8.5.12 / 8.4.27 / 8.3.36 / 8.2.35 及以上）",
        '',
        "1. 先在服务器上备份整个网站目录，例如：",
        "     tar czf ~/{$SITE_NAME}-backup-{$STAMP}.tar.gz -C " . dirname($root) . ' ' . basename($root),
        "2. 把本压缩包里「{$SITE_NAME}/」目录下的全部内容，用 Xftp 上传覆盖到服务器网站根目录（{$root}）。",
        '   只包含有变更的文件，目录结构与网站一致；上传时选择“覆盖”。',
        '3. 把「上线配置/php-phpup.ini」的内容追加到新版 PHP 的 php.ini（或放进 conf.d 目录），'
            . ($serverRoot === '' ? '并把里面 auto_prepend_file 的路径改成服务器上 phpup_compat.php 的实际位置，' : '')
            . '然后重启 php-fpm / Apache。',
        '4. 打开网站主要页面（首页、登录、后台、支付/接口等）确认正常，同时查看 PHP 错误日志。',
        '5. 如需回退：用第 1 步的备份覆盖回去，并把 PHP 切回原版本。',
        '',
        '注意：不要把本压缩包里的 txt 和「上线配置」目录上传到网站目录里。',
    ];
    $zip->addFromString('上线步骤.txt', implode("\r\n", $steps) . "\r\n");
    if ($zip->close() !== true) {
        fail("升级包写入失败: {$pkg}");
    }
    file_put_contents("{$OUT}/报告.md", "\n\n## 附：升级包\n\n修复后有变更的 " . count($changed)
        . " 个文件已打包为 `" . basename($pkg) . "`（与本报告在同一目录），内含上线步骤.txt，按步骤上传到正式环境即可。\n", FILE_APPEND);
    info('升级包: ' . $pkg . '（' . count($changed) . ' 个变更文件）');
};

/** 生成统一格式的差异文件（与 diff -ruN 相同的格式） */
$writeDiff = static function (string $origDir, string $newDir, array $files) use ($OUT): void {
    $differFile = KIT . '/vendor/rector/rector/vendor/sebastian/diff/src/Differ.php';
    preg_match('/^namespace\s+([^;]+);/m', (string) file_get_contents($differFile), $m);
    $ns = $m[1];
    require_once KIT . '/vendor/rector/rector/vendor/autoload.php';
    if (!function_exists('array_any')) {     // PHP 8.4 新函数，diff 库会用到；在 8.1~8.3 上补一个
        function array_any(array $array, callable $callback): bool
        {
            foreach ($array as $k => $v) {
                if ($callback($v, $k)) {
                    return true;
                }
            }
            return false;
        }
    }
    $fp = fopen("{$OUT}/changes.diff", 'w');
    foreach ($files as $rel) {
        $a = (string) @file_get_contents("{$origDir}/{$rel}");
        $b = (string) @file_get_contents("{$newDir}/{$rel}");
        if ($a === $b) {
            continue;
        }
        $builderClass = $ns . '\\Output\\StrictUnifiedDiffOutputBuilder';
        $differClass = $ns . '\\Differ';
        $builder = new $builderClass(['fromFile' => "a/{$rel}", 'toFile' => "b/{$rel}", 'collapseRanges' => true, 'contextLines' => 3, 'commonLineThreshold' => 6]);
        fwrite($fp, "diff -ruN a/{$rel} b/{$rel}\n" . (new $differClass($builder))->diff($a, $b));
    }
    fclose($fp);
};

// ---------------------------------------------------------------------------
// 命令
// ---------------------------------------------------------------------------
switch ($cmd) {
    case 'scan':
        $runPhpcs($SITE);
        $runExtraChecks($SITE);
        file_put_contents("{$OUT}/lint.txt", '');
        file_put_contents("{$OUT}/rector.json", '{}');
        $makeReport('scan', $SITE);
        break;

    case 'preview':
        $WORK = "{$OUT}/work/{$SITE_NAME}";
        $ORIG = "{$OUT}/work/.orig";
        info("复制代码到临时副本: {$WORK}（原代码不会被修改）");
        $files = code_files($SITE, $autoExclude);
        foreach ($files as $rel) {
            copy_file("{$SITE}/{$rel}", "{$WORK}/{$rel}");
            copy_file("{$SITE}/{$rel}", "{$ORIG}/{$rel}");
        }
        $runPrefix($WORK);
        $runStaticCalls($WORK);
        $runRector($WORK);
        $runPhp4Ctor($WORK);
        $runTmpCache($SITE, $WORK);
        $runLint($WORK, static fn (string $rel) => $lintFile("{$ORIG}/{$rel}"));
        info('生成差异文件 changes.diff...');
        $writeDiff($ORIG, $WORK, $files);
        $runPhpcs($WORK);
        $runExtraChecks($WORK);
        $makeReport('preview', $WORK);
        $changed = [];
        foreach ($files as $rel) {
            if (is_file("{$WORK}/{$rel}") && @file_get_contents("{$ORIG}/{$rel}") !== file_get_contents("{$WORK}/{$rel}")) {
                $changed[$rel] = "{$WORK}/{$rel}";
            }
        }
        $tc = json_decode((string) @file_get_contents("{$OUT}/tmpcache.json"), true) ?: [];
        foreach (array_keys($tc['fixed'] ?? []) as $rel) {
            $changed[$rel] = "{$WORK}/{$rel}";
        }
        $makePackage($changed);
        rrmdir($ORIG);
        break;

    case 'apply':
        if (!$opt['yes']) {
            echo "即将直接修改 {$SITE} 下的代码（目标 PHP {$opt['target']}）。建议先运行 preview 确认差异。", PHP_EOL;
            echo '确认继续? 输入 yes: ';
            if (trim((string) fgets(STDIN)) !== 'yes') {
                fail('已取消');
            }
        }
        if (!class_exists(ZipArchive::class)) {
            fail('PHP 缺少 zip 扩展，无法备份，已停止（请在 php.ini 中启用 extension=zip）');
        }
        $BACKUP = "{$OUT}/backup-{$SITE_NAME}-{$STAMP}.zip";
        info("备份代码文件到 {$BACKUP}");
        $zip = new ZipArchive();
        $zip->open($BACKUP, ZipArchive::CREATE);
        $files = code_files($SITE);
        foreach ($files as $rel) {
            $zip->addFile("{$SITE}/{$rel}", $rel);
        }
        if ($zip->close() !== true) {
            fail('备份失败，已停止，代码没有被修改');
        }
        $origDir = "{$OUT}/.orig-lint";
        $runPrefix($SITE);
        $runStaticCalls($SITE);
        $runRector($SITE);
        $runPhp4Ctor($SITE);
        $runTmpCache($SITE, $SITE);
        $runLint($SITE, static function (string $rel) use ($BACKUP, $origDir, $lintFile): ?string {
            $z = new ZipArchive();
            if ($z->open($BACKUP) !== true || $z->locateName($rel) === false) {
                return null;
            }
            $z->extractTo($origDir, $rel);
            $z->close();
            return $lintFile("{$origDir}/{$rel}");
        });
        rrmdir($origDir);
        copy(KIT . '/lib/compat/phpup_compat.php', "{$SITE}/phpup_compat.php");
        $runPhpcs($SITE);
        $runExtraChecks($SITE);
        $makeReport('apply', $SITE);
        $changed = [];
        $z = new ZipArchive();
        $z->open($BACKUP);
        foreach ($files as $rel) {
            if (is_file("{$SITE}/{$rel}") && $z->getFromName($rel) !== file_get_contents("{$SITE}/{$rel}")) {
                $changed[$rel] = "{$SITE}/{$rel}";
            }
        }
        $z->close();
        $makePackage($changed);
        echo "如需撤销:  php phpup.php restore \"{$BACKUP}\" \"{$SITE}\"", PHP_EOL;
        break;
}

file_put_contents(norm($opt['reports']) . '/last-report.txt', "{$OUT}/报告.md");
info("完成。报告: {$OUT}/报告.md");
