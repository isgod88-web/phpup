@echo off
chcp 65001 >nul
rem 还原：用正式修复前自动生成的备份 zip 还原站点代码。
call "%~dp0windows\run.cmd" restore
