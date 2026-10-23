@echo off
chcp 65001 >nul
rem 正式修复：先把站点代码备份成 zip，再直接修改站点代码。请先运行预演确认。
call "%~dp0windows\run.cmd" apply "%~1"
