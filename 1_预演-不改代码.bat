@echo off
chcp 65001 >nul
rem 预演：在副本上自动修复并生成报告，不修改原代码。可以把站点文件夹直接拖到这个文件上。
call "%~dp0windows\run.cmd" preview "%~1"
