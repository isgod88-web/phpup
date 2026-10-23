# phpup：老 PHP 站点一键升级到 PHP 8.5

> ⚠️ **使用前请先阅读 [免责声明](DISCLAIMER.md)。** 本工具按“现状”免费提供，不保证没有问题，也不对使用后造成的任何损失负责。务必先备份、先预演、先在测试环境验证。

2026 年 10 月公开的 PHP 远程代码执行漏洞（PHP2Shell）只在 PHP 8.2 / 8.3 / 8.4 / 8.5 的新版本中修复（8.5.12、8.4.27、8.3.36、8.2.35），**PHP 8.1 及更早的版本不会再有修复**。
很多老站点（PHP 7.x 时代的 CMS、框架、自己写的代码）直接换 PHP 8.5 会大量报错甚至整站 500，迟迟不敢升级。

phpup 把这件事尽量自动化：

- **扫描**：找出与 PHP 8.5 不兼容的写法，生成中文报告；
- **预演**：在副本上自动修复，给出完整差异 `changes.diff`，**不改原代码**；
- **修复**：确认后正式修改（修改前自动备份，可一键还原）；
- **升级包**：只打包有变更的文件 + 兼容层 + php.ini 模板 + 上线步骤，直接覆盖到服务器即可。

只处理版本迁移（已删除/已弃用的函数和语法），不做代码风格调整，不改业务逻辑。

## 能自动处理的内容（部分）

- 短标签 `<?` → `<?php`、`=& new`、PHP4 风格构造函数、静态调用非静态方法
- `each()`、`create_function()`、`$str{0}`、`(real)`、`implode` 参数反序、嵌套三元、`${}` 插值等已删除写法
- `.` 与 `+`/`-` 的优先级变化（按 PHP 7 的计算顺序补括号，结果不变）
- 未加引号的数组键 `$r[title]`（PHP 8 起是致命错误），包括模板 `eval()` 和模板缓存文件里运行时才出现的代码
- 对 null / 空字符串做运算、`count(null)` 等 PHP 8 会抛 TypeError 的地方
- ThinkPHP 5 把 Deprecated 当致命错误、时区旧别名（PRC 等）
- 帝国CMS 模板缓存（`e/data/tmp`）和模板 eval
- 反向代理（负载均衡做 HTTPS 卸载）后 `HTTPS` / `SERVER_PORT` / `SERVER_NAME` 不正确导致的回调验签失败
- 兼容层 `phpup_compat.php`：补回已删除但无法安全改写的函数（如 `money_format`、`get_magic_quotes_gpc`、`fgetss` 等）

已用 WordPress 4.9、ThinkPHP 5、帝国CMS 7.x 等真实老站点验证。改不了的会列进报告的“需人工处理”。

## 快速开始

### Windows（推荐普通用户）

1. 到 [Releases](../../releases) 下载完整工具包（已包含依赖），解压到**路径里没有中文和空格**的目录，例如 `D:\phpup\`；
2. 把站点代码下载到本机；
3. 双击 `1_预演-不改代码.bat`，选择站点文件夹，按提示操作。第一次运行会自动下载 Windows 版 PHP；
4. 看完报告确认无误，再用 `2_正式修复-会先备份.bat`，或直接使用报告文件夹里的 `升级包-*.zip` 部署。

### Linux / macOS

```bash
git clone <本仓库地址> phpup && cd phpup
composer install
chmod +x phpup.sh
./phpup.sh preview /path/to/站点目录 --target 8.5 --from 7.3
```

也可以用 PHP 版主程序：`php phpup.php preview /path/to/站点目录`。

详细说明、各项选项、报告分类、兼容层说明见 **[使用说明.md](使用说明.md)**。
可以先用自带的样例试跑：`./phpup.sh preview samples/legacy-site`。

## 上线前务必

1. 完整备份站点代码和数据库；
2. 看报告和 `changes.diff`；
3. 在装好 PHP 8.5.12+ 的测试环境里把登录、支付、上传、后台等关键流程跑一遍，同时看错误日志；
4. 保留旧版本 PHP 环境以便回退。

## 隐私提示

`reports/` 目录里是**你自己站点的代码、差异和备份**，已在 `.gitignore` 中排除。提交 issue 时请不要附上整个报告或站点代码，只贴相关的几行，并去掉域名、密码、密钥等信息。

## 致谢

本工具由维护者与 AI 编程助手 Claude（Anthropic）共同开发和测试。底层使用了 [Rector](https://getrector.com/)、[PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility)、[PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer) 等优秀的开源项目，详见 [NOTICE.md](NOTICE.md)。

## 许可证

[MIT](LICENSE)。使用即表示同意 [免责声明](DISCLAIMER.md)。

---

**English summary**: phpup automatically migrates legacy PHP 7.x/8.0/8.1 sites to PHP 8.5 (scan → preview with full diff → apply with backup → deployable upgrade package), built on Rector and PHPCompatibility, with a runtime compatibility layer. Reports and docs are in Chinese. Provided AS IS with no warranty; see [DISCLAIMER.md](DISCLAIMER.md).
