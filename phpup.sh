#!/usr/bin/env bash
# =============================================================================
# phpup.sh —— PHP 版本升级代码自动迁移工具
#
# 用法:
#   ./phpup.sh scan    <站点目录> [选项]   只检测，列出与目标版本不兼容的代码
#   ./phpup.sh preview <站点目录> [选项]   预演：在副本上自动修复，输出差异和人工清单（不改原代码）
#   ./phpup.sh apply   <站点目录> [选项]   正式写入：先备份，再自动修复，语法检查，输出人工清单
#   ./phpup.sh restore <备份文件.tar.gz> <站点目录>   从备份还原
#
# 选项:
#   --target 8.5         目标 PHP 版本（8.2 / 8.3 / 8.4 / 8.5，默认 8.5；服务器请装 8.5.12 及以上，已修复 PHP2Shell）
#   --php /usr/bin/php8.5  用于语法检查的 PHP 可执行文件（建议用目标版本的 PHP）
#   --strict-null        同时修复“把 null 传给字符串参数”（8.1 弃用项，会加较多 (string) 转换）
#   --from 7.3           站点当前运行的 PHP 版本（默认 7.3）。在该版本就已删除的写法会单独列为“现网已失效代码”
#   --include-vendor     连 vendor/ 第三方库一起改、一起检测（默认都跳过，建议用 composer 升级第三方库）
#   --exclude a,b        额外排除的路径（通配符写法，如 */old/*,*/backup/*）
#   --keep-short-tags    不把短标签 <? 改成 <?php（默认会改；不改则新服务器必须开 short_open_tag）
#   --yes                apply 时不再询问确认
#   --reports <目录>      报告输出目录（默认 ./reports）
# =============================================================================
set -euo pipefail

KIT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="8.5"
LINT_PHP="php"
STRICT_NULL=0
INCLUDE_VENDOR=0
EXCLUDE=""
FROM="7.3"
ASSUME_YES=0
KEEP_SHORT=0
REPORTS_ROOT="$KIT_DIR/reports"
CODE_EXT_FIND=( -name '*.php' -o -name '*.inc' -o -name '*.phtml' )
PHPCS_IGNORE='*/node_modules/*,*/templates_c/*,*/cache/*,*/runtime/*,*/storage/framework/*,*/.git/*'

die()  { echo "错误: $*" >&2; exit 1; }
info() { echo "==> $*"; }

usage() { sed -n '2,25p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 1; }

# ---------------------------------------------------------------------------
# 参数解析
# ---------------------------------------------------------------------------
[ $# -ge 2 ] || usage
CMD="$1"; shift
if [ "$CMD" = "restore" ]; then
  [ $# -ge 2 ] || usage
  BACKUP="$1"; SITE="$2"
  [ -f "$BACKUP" ] || die "备份文件不存在: $BACKUP"
  [ -d "$SITE" ] || die "站点目录不存在: $SITE"
  info "从 $BACKUP 还原到 $SITE"
  tar -xzf "$BACKUP" -C "$SITE"
  info "还原完成"
  exit 0
fi

SITE_ARG="$1"; shift
while [ $# -gt 0 ]; do
  case "$1" in
    --target)          TARGET="$2"; shift 2 ;;
    --php)             LINT_PHP="$2"; shift 2 ;;
    --strict-null)     STRICT_NULL=1; shift ;;
    --include-vendor)  INCLUDE_VENDOR=1; shift ;;
    --exclude)         EXCLUDE="$2"; shift 2 ;;
    --from)            FROM="$2"; shift 2 ;;
    --yes|-y)          ASSUME_YES=1; shift ;;
    --keep-short-tags) KEEP_SHORT=1; shift ;;
    --reports)         REPORTS_ROOT="$2"; shift 2 ;;
    *) die "未知选项: $1" ;;
  esac
done

case "$TARGET" in 8.2|8.3|8.4|8.5) ;; *) die "--target 只支持 8.2 / 8.3 / 8.4 / 8.5" ;; esac
[ -d "$SITE_ARG" ] || die "站点目录不存在: $SITE_ARG"
SITE="$(cd "$SITE_ARG" && pwd)"
SITE_NAME="$(basename "$SITE")"

# ---------------------------------------------------------------------------
# 识别常见程序，自动排除“数据/缓存”目录（里面是 .php 后缀的数据或自动生成的文件，不是代码）
# ---------------------------------------------------------------------------
PROFILE="通用"
AUTO_EXCLUDE=()
if [ -f "$SITE/e/class/connect.php" ]; then
  PROFILE="帝国CMS (EmpireCMS)"
  # d/txt: 文章正文存储；e/data/tmp: 由后台模板自动生成的缓存（要改请改后台模板后重新生成）
  AUTO_EXCLUDE+=("d/txt" "e/data/tmp")
fi
if [ -d "$SITE/thinkphp" ] || [ -f "$SITE/think" ]; then
  PROFILE="ThinkPHP"
  AUTO_EXCLUDE+=("runtime")
fi
EXCLUDE_GLOBS=()                      # 形如 */d/txt/*，供 Rector / PHPCS / 短标签检查共用
for d in "${AUTO_EXCLUDE[@]}"; do EXCLUDE_GLOBS+=("*/$d/*"); done
IFS=',' read -r -a _user_ex <<< "$EXCLUDE"
for p in "${_user_ex[@]}"; do [ -n "$p" ] && EXCLUDE_GLOBS+=("$p"); done
[ "$INCLUDE_VENDOR" = 1 ] || EXCLUDE_GLOBS+=("*/vendor/*")
EXCLUDE_CSV="$(IFS=','; echo "${EXCLUDE_GLOBS[*]}")"

# ---------------------------------------------------------------------------
# 环境检查
# ---------------------------------------------------------------------------
command -v php >/dev/null || die "找不到 php 命令。运行本工具需要 PHP 8.1 以上的命令行版本"
php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || die "运行本工具需要 PHP 8.1+（当前 $(php -r 'echo PHP_VERSION;')）。站点服务器上的版本无所谓，可以在任意一台装了新 PHP 的机器上运行"
[ -x "$KIT_DIR/vendor/bin/rector" ] && [ -x "$KIT_DIR/vendor/bin/phpcs" ] || die "依赖未安装，请先在 $KIT_DIR 下执行: composer install"
command -v "$LINT_PHP" >/dev/null || die "找不到语法检查用的 PHP: $LINT_PHP"

RECTOR="$KIT_DIR/vendor/bin/rector"
PHPCS="$KIT_DIR/vendor/bin/phpcs"

# 首次使用时注册 PHPCompatibility 规则集
if ! "$PHPCS" -i | grep -q PHPCompatibility; then
  "$PHPCS" --config-set installed_paths \
    "$KIT_DIR/vendor/phpcompatibility/php-compatibility,$KIT_DIR/vendor/phpcsstandards/phpcsutils" >/dev/null
fi

info "识别为：$PROFILE${AUTO_EXCLUDE[*]:+（自动跳过数据/缓存目录：${AUTO_EXCLUDE[*]}）}"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="$REPORTS_ROOT/$SITE_NAME-$CMD-$STAMP"
mkdir -p "$OUT"

export PHPUP_TARGET="$TARGET"
export PHPUP_STRICT_NULL="$STRICT_NULL"
export PHPUP_INCLUDE_VENDOR="$INCLUDE_VENDOR"
export PHPUP_EXCLUDE="$EXCLUDE_CSV"
export PHPUP_CACHE_DIR="$OUT/.rector-cache"

# ---------------------------------------------------------------------------
# 公共步骤
# ---------------------------------------------------------------------------

# 检测：PHPCompatibility 扫描 → JSON
run_phpcs() {  # $1=目录 $2=输出json
  info "检测与 PHP $TARGET 不兼容的代码（PHPCompatibility）..."
  "$PHPCS" -q -d memory_limit=2G \
    --standard=PHPCompatibility --runtime-set testVersion "$TARGET" \
    --extensions=php,inc,phtml --ignore="$PHPCS_IGNORE${EXCLUDE_CSV:+,$EXCLUDE_CSV}" \
    --report=json --report-file="$2" --parallel=4 "$1" || true
  [ -s "$2" ] || die "PHPCompatibility 扫描失败，未生成报告"
}

# 自动修复：Rector → JSON（$2=1 时为 dry-run）
run_rector() {  # $1=目录 $2=dry(0/1) $3=输出json
  info "自动修复（Rector，目标 PHP $TARGET）..."
  local args=(process --config "$KIT_DIR/rector.php" --output-format json --no-progress-bar)
  [ "$2" = "1" ] && args+=(--dry-run)
  PHPUP_PATHS="$1" "$RECTOR" "${args[@]}" > "$3" 2> "$OUT/rector-stderr.log" || true
  php -r 'json_decode(file_get_contents($argv[1]), true) !== null || exit(1);' "$3" \
    || die "Rector 运行失败，详情见 $OUT/rector-stderr.log 和 $3"
}

# 词法级预处理：短标签 <? → <?php、=& new → = new（必须在 Rector 之前，否则这些文件 Rector 无法解析、会被整个跳过）
run_prefix() {  # $1=目录
  info "预处理：短标签 <? 和 =& new..."
  local extra=()
  [ "$KEEP_SHORT" = 1 ] && extra+=(--keep-short-tags)
  php -d short_open_tag=1 "$KIT_DIR/lib/prefix_tokens.php" "$1" "$OUT/prefix.json" "$EXCLUDE_CSV" "${extra[@]}"
}

# 静态方式调用非静态方法 → 给方法加 static（PHP 8 起这种调用直接报错）
run_static_calls() {  # $1=目录
  info "检查以静态方式调用的非静态方法..."
  php "$KIT_DIR/lib/static_calls.php" "$1" "$OUT/staticcalls.json" "$EXCLUDE_CSV"
}

# Rector 把父类的 PHP4 构造函数改名后，补改其他文件里子类的 parent::父类名() 调用
run_php4_ctor_calls() {  # $1=目录
  php "$KIT_DIR/lib/php4_ctor_calls.php" "$1" "$OUT/php4ctor.json" "$EXCLUDE_CSV"
  # 框架补丁（ThinkPHP 把 Deprecated 当致命错误、时区旧别名）
  php "$KIT_DIR/lib/framework_patches.php" "$1" "$OUT/framework.json"
  info "运行时类型检查（空字符串运算、除以 0、数组函数收到 null、未加引号的常量）..."
  php -d memory_limit=-1 "$KIT_DIR/lib/runtime_types.php" "$1" "$OUT/runtime.json" "$EXCLUDE_CSV"
}

# 语法检查：对改过的文件（Rector + 预处理 + static 修复）逐个 php -l
run_lint() {  # $1=rector json $2=输出txt $3=目录
  info "语法检查（$("$LINT_PHP" -r 'echo PHP_VERSION;')）..."
  : > "$2"
  : > "$OUT/lint-preexisting.txt"
  php -r '
    $d = json_decode(file_get_contents($argv[1]), true);
    $files = $d["changed_files"] ?? [];
    foreach ([$argv[2], $argv[3], $argv[5], $argv[6], $argv[7]] as $j) {
        $x = json_decode((string) @file_get_contents($j), true) ?: [];
        foreach (array_keys($x["fixed"] ?? $x) as $rel) { $files[] = $argv[4] . "/" . $rel; }
    }
    foreach (array_unique($files) as $f) echo $f, "\n";' "$1" "$OUT/prefix.json" "$OUT/staticcalls.json" "$3" "$OUT/php4ctor.json" "$OUT/framework.json" "$OUT/runtime.json" |
  while IFS= read -r f; do
    [ -f "$f" ] || continue
    if ! out="$("$LINT_PHP" -l "$f" 2>&1)"; then
      # 修改前的原文件也报同样的错误 → 不是工具改坏的，是现网本来就无法加载的文件
      # 或者是 PHP 7 就会在编译时报的致命错误（魔术方法参数个数不对、重复定义等），工具的改写不会引起这类错误
      if printf '%s' "$out" | grep -Eq "$PHP7_FATAL" ||
         { orig_err="$(lint_original "${f#"$3"/}")" && [ -n "$orig_err" ] && [ "$(lint_key "$out" "$f")" = "$(lint_key "$orig_err" "$f")" ]; }; then
        printf '%s\n' "$out" >> "$OUT/lint-preexisting.txt"
      else
        printf '%s\n' "$out" >> "$2"
      fi
    fi
  done
}

PHP7_FATAL='must take exactly [0-9]+ argument|cannot take arguments|Cannot redeclare|Cannot re-assign \$this|Cannot use temporary expression in write context'

# 去掉路径和行号，只留错误内容，用来比较修改前后是不是同一个错误
lint_key() {  # $1=php -l 输出 $2=文件路径
  printf '%s\n' "$1" | grep -i 'error' | grep -v '^Errors parsing' | sed -e 's# in /.* on line [0-9]*##' -e 's#on line [0-9]*##' | head -1
}

# 对修改前的原文件做语法检查（短标签按旧服务器的 On 处理），输出错误信息；原文件没问题时输出为空
lint_original() {  # $1=相对路径
  local src
  if [ -n "${ORIG_DIR:-}" ]; then
    src="$ORIG_DIR/$1"
  elif [ -n "${BACKUP:-}" ]; then
    mkdir -p "$OUT/.orig-lint"
    tar -xzf "$BACKUP" -C "$OUT/.orig-lint" "./$1" 2>/dev/null || return 1
    src="$OUT/.orig-lint/$1"
  else
    return 1
  fi
  [ -f "$src" ] || return 1
  "$LINT_PHP" -d short_open_tag=1 -l "$src" 2>&1 | grep -qi 'error' || return 0
  "$LINT_PHP" -d short_open_tag=1 -l "$src" 2>&1
}

# 复制站点中的 PHP 代码文件（保留目录结构，跳过自动排除的数据/缓存目录）
copy_code() {  # $1=源 $2=目标
  mkdir -p "$2"
  local prune=()
  for d in "${AUTO_EXCLUDE[@]}"; do prune+=(-path "./$d" -prune -o); done
  (cd "$1" && find . "${prune[@]}" -type f \( "${CODE_EXT_FIND[@]}" \) -print0 | tar --null -cf - -T -) | (cd "$2" && tar -xf -)
}

# 短标签 <? 检查 + 列出 vendor 目录
run_extra_checks() {  # $1=目录
  info "检查短标签 <? 和第三方库目录..."
  php -d short_open_tag=0 "$KIT_DIR/lib/shorttags.php" "$1" "$OUT/shorttags.json" "$EXCLUDE_CSV"
  info "安全检查（上传目录里的 PHP 文件、伪装成 PHP 的图片、常见后门写法）..."
  php "$KIT_DIR/lib/security.php" "$SITE" "$OUT/security.json" "$EXCLUDE_CSV"
  (cd "$1" && find . -type d \( -name vendor -o -name vender \) -prune -print | sed 's#^\./##') > "$OUT/vendor-dirs.txt" || true
}

# 上线配置：兼容层 + 建议的 php.ini
write_deploy_files() {
  mkdir -p "$OUT/上线配置"
  cp "$KIT_DIR/lib/compat/phpup_compat.php" "$OUT/上线配置/"
  sed -e "s#@SITE@#$SITE#g" -e "s#@SHORT@#$([ "$KEEP_SHORT" = 1 ] && echo On || echo Off)#g" \
    "$KIT_DIR/lib/compat/php-phpup.ini" > "$OUT/上线配置/php-phpup.ini"
}

make_report() {  # $1=mode
  local extra=()
  [ "$KEEP_SHORT" = 1 ] && extra+=(--keep-short-tags)
  [ -f "$OUT/prefix.json" ] || echo '{}' > "$OUT/prefix.json"
  [ -f "$OUT/staticcalls.json" ] || echo '{}' > "$OUT/staticcalls.json"
  [ -f "$OUT/php4ctor.json" ] || echo '{}' > "$OUT/php4ctor.json"
  [ -f "$OUT/framework.json" ] || echo '{}' > "$OUT/framework.json"
  [ -f "$OUT/runtime.json" ] || echo '{}' > "$OUT/runtime.json"
  write_deploy_files
  php "$KIT_DIR/lib/report.php" "${extra[@]}" \
    --prefix="$OUT/prefix.json" --php4ctor="$OUT/php4ctor.json" --framework="$OUT/framework.json" --runtime="$OUT/runtime.json" --staticcalls="$OUT/staticcalls.json" --security="$OUT/security.json" \
    --mode="$1" --site="$SITE" --target="$TARGET" --out="$OUT" \
    --phpcs="$OUT/phpcs.json" --rector="$OUT/rector.json" --lint="$OUT/lint.txt" --lintpre="$OUT/lint-preexisting.txt" \
    --root="$2" --from="$FROM" --profile="$PROFILE" \
    --shorttags="$OUT/shorttags.json" --vendors="$OUT/vendor-dirs.txt" \
    --excluded="$(IFS=','; echo "${AUTO_EXCLUDE[*]}")"
}

# ---------------------------------------------------------------------------
# 命令
# ---------------------------------------------------------------------------
case "$CMD" in
  scan)
    run_phpcs "$SITE" "$OUT/phpcs.json"
    run_extra_checks "$SITE"
    : > "$OUT/lint.txt"; echo '{}' > "$OUT/rector.json"
    make_report scan "$SITE"
    ;;

  preview)
    WORK="$OUT/work/$SITE_NAME"
    info "复制代码到临时副本: $WORK（原代码不会被修改）"
    copy_code "$SITE" "$WORK"
    cp -a "$WORK" "$OUT/work/.orig"
    ORIG_DIR="$OUT/work/.orig"
    run_prefix "$WORK"
    run_static_calls "$WORK"
    run_rector "$WORK" 0 "$OUT/rector.json"
    run_php4_ctor_calls "$WORK"
    run_lint "$OUT/rector.json" "$OUT/lint.txt" "$WORK"
    (cd "$OUT/work" && diff -ruN ".orig" "$SITE_NAME" > "$OUT/changes.diff") || true
    sed -i.bak -e "s#^--- \.orig/#--- a/#" -e "s#^+++ $SITE_NAME/#+++ b/#" "$OUT/changes.diff" && rm -f "$OUT/changes.diff.bak"
    run_phpcs "$WORK" "$OUT/phpcs.json"
    run_extra_checks "$WORK"
    make_report preview "$WORK"
    rm -rf "$OUT/work/.orig"
    ;;

  apply)
    if [ "$ASSUME_YES" != 1 ]; then
      echo "即将直接修改 $SITE 下的代码（目标 PHP $TARGET）。建议先运行 preview 确认差异。"
      read -r -p "确认继续? 输入 yes: " ans
      [ "$ans" = "yes" ] || die "已取消"
    fi
    BACKUP="$OUT/backup-$SITE_NAME-$STAMP.tar.gz"
    info "备份代码文件到 $BACKUP"
    (cd "$SITE" && find . -type f \( "${CODE_EXT_FIND[@]}" \) -print0 | tar --null -czf "$BACKUP" -T -)
    if [ -d "$SITE/.git" ]; then
      info "检测到 git 仓库，也可以用 git diff / git checkout 查看或撤销修改"
    fi
    run_prefix "$SITE"
    run_static_calls "$SITE"
    run_rector "$SITE" 0 "$OUT/rector.json"
    run_php4_ctor_calls "$SITE"
    run_lint "$OUT/rector.json" "$OUT/lint.txt" "$SITE"
    cp "$KIT_DIR/lib/compat/phpup_compat.php" "$SITE/phpup_compat.php"
    run_phpcs "$SITE" "$OUT/phpcs.json"
    run_extra_checks "$SITE"
    make_report apply "$SITE"
    echo "如需撤销:  $0 restore \"$BACKUP\" \"$SITE\""
    ;;

  *) usage ;;
esac

info "完成。报告: $OUT/报告.md"
