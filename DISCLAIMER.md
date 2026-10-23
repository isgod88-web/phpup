# 免责声明 / Disclaimer / 免責事項

## 中文

1. **公益目的**：本工具是为了帮助更多仍在使用 PHP 7.x / 8.0 / 8.1 等旧版本的站点，尽快升级到已修复安全漏洞的 PHP 版本而免费公开的。作者不收取任何费用，也不提供任何形式的商业支持或服务承诺。
2. **按“现状”提供，不作任何保证**：本工具按“现状”（AS IS）提供。作者不保证本工具没有错误或缺陷，不保证自动修改后的代码在所有环境下都能正确运行、行为与修改前完全一致，也不保证能发现或修复所有不兼容问题和安全问题。
3. **不承担损失责任**：因使用、无法使用或错误使用本工具（包括但不限于：根据本工具修改代码、使用本工具生成的升级包、兼容层 `phpup_compat.php`、php.ini 配置模板）而直接或间接导致的任何损失，包括但不限于数据丢失或损坏、网站无法访问或功能异常、支付或订单错误、业务中断、收入或利润损失、安全事件以及第三方索赔，作者及贡献者均不承担任何责任。
4. **使用者自行负责**：是否使用本工具、如何使用，由使用者自行判断并承担全部风险。强烈建议：
   - 修改前完整备份站点代码和数据库；
   - 先用“预演”模式查看报告和 `changes.diff`，逐项确认改动；
   - 先在测试环境完整验证登录、支付、上传、后台等关键功能，再部署到生产环境；
   - 保留旧版本 PHP 环境，以便出现问题时快速回退。
5. **不是安全审计**：本工具的“安全提示”只是常见可疑写法的简单检查，不能代替专业的安全审计，没有提示不代表站点是安全的。升级 PHP 版本只能修复 PHP 本身的漏洞，不能修复站点代码自身的漏洞。
6. **第三方组件**：本工具依赖的 Rector、PHPCompatibility、PHP_CodeSniffer 等第三方开源项目，以及自动下载的 PHP 程序，均遵循各自的许可证（见 [NOTICE.md](NOTICE.md)），其问题与本工具作者无关。
7. **商标**：文中提到的软件、框架、平台名称（如 PHP、帝国CMS、ThinkPHP、WordPress 等）均为其各自权利人所有，仅用于说明兼容性，不代表任何关联或认可。
8. 使用本工具即表示你已阅读并同意本声明。本声明以及 [LICENSE](LICENSE)（MIT 许可证）中的免责条款同时适用；如有冲突，以对作者保护范围更大的条款为准。

## English

This tool is published free of charge to help sites running old PHP versions move to a patched PHP release. It is provided **"AS IS", without warranty of any kind**, express or implied. The authors do not guarantee that the tool is free of bugs, that modified code will behave exactly as before, or that it will find or fix every incompatibility or security issue.

In no event shall the authors or contributors be liable for any direct or indirect loss or damage arising from the use of, or inability to use, this tool, its generated upgrade packages, the compatibility layer or the configuration templates — including but not limited to data loss, downtime, payment or order errors, business interruption, lost profits, security incidents or third-party claims. You use it entirely at your own risk. Always back up code and databases, review the preview report and diff, and test in a staging environment before deploying to production. The security hints are not a security audit. Third-party components are governed by their own licenses (see [NOTICE.md](NOTICE.md)). See also the MIT [LICENSE](LICENSE).

## 日本語

本ツールは、古い PHP バージョンを使用しているサイトが脆弱性修正済みの PHP へ移行できるよう、無償で公開しているものです。本ツールは**「現状有姿」で提供され、いかなる保証もありません**。不具合がないこと、変更後のコードが変更前と同じ動作をすること、すべての非互換や脆弱性を検出・修正できることを保証しません。

本ツール、生成されたアップグレードパッケージ、互換レイヤー、設定テンプレートの使用または使用不能により生じた直接・間接のいかなる損害（データ消失、サイト停止、決済・注文の誤り、業務中断、逸失利益、セキュリティ事故、第三者からの請求等を含みますがこれらに限りません）についても、作者および貢献者は一切責任を負いません。ご利用は自己責任でお願いします。本番環境へ反映する前に、必ずコードとデータベースをバックアップし、プレビューのレポートと差分を確認し、テスト環境で動作検証を行ってください。
