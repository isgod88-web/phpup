# 第三方组件 / Third-party components

本工具本身以 MIT 许可证发布（见 [LICENSE](LICENSE)）。运行时依赖以下开源项目，它们不包含在本仓库的源码中，
由 `composer install` 下载，或随 Release 里的完整工具包一起分发，各自遵循自己的许可证：

| 组件 | 版本 | 许可证 | 主页 |
|---|---|---|---|
| rector/rector | 2.7.0 | MIT | https://github.com/rectorphp/rector |
| phpstan/phpstan | 2.3.1 | MIT | https://github.com/phpstan/phpstan |
| phpcompatibility/php-compatibility | 10.0.0-alpha2 | LGPL-3.0-or-later | https://github.com/PHPCompatibility/PHPCompatibility |
| squizlabs/php_codesniffer | 3.13.6 | BSD-3-Clause | https://github.com/PHPCSStandards/PHP_CodeSniffer |
| phpcsstandards/phpcsutils | 1.2.3 | LGPL-3.0-or-later | https://github.com/PHPCSStandards/PHPCSUtils |
| dealerdirect/phpcodesniffer-composer-installer | 1.2.1 | MIT | https://github.com/PHPCSStandards/composer-installer |

Windows 一键脚本首次运行时会从 https://windows.php.net 下载官方 PHP（PHP License），
缺少 VC++ 运行库时会从微软官方地址下载安装。这些都不随本仓库分发。
