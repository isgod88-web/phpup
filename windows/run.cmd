@echo off
rem 公共启动脚本：由上一级目录的 .bat 调用。参数: %1=命令(preview/apply/restore) %2=拖进来的文件夹（可空）
chcp 65001 >nul
setlocal EnableExtensions
set "KIT=%~dp0.."
set "PHPDIR=%KIT%\php"
set "PHP=%PHPDIR%\php.exe"
set "CMD=%~1"

if not exist "%PHP%" (
  echo ==^> 首次运行：下载 Windows 版 PHP（约 30MB，只需一次）...
  powershell -NoProfile -ExecutionPolicy Bypass -File "%KIT%\windows\get-php.ps1" -Dest "%PHPDIR%"
  if errorlevel 1 (
    echo.
    echo 下载 PHP 失败。可以手动从 https://windows.php.net/download/ 下载 PHP 8.5 的 "VS17 x64 Non Thread Safe" zip，
    echo 解压到 "%PHPDIR%" 后再运行。
    pause
    exit /b 1
  )
)

if /i "%CMD%"=="restore" goto :restore

set "SITE=%~2"
if "%SITE%"=="" (
  for /f "usebackq delims=" %%P in (`powershell -NoProfile -ExecutionPolicy Bypass -File "%KIT%\windows\pick.ps1" -Mode folder -Title "请选择站点代码所在的文件夹"`) do set "SITE=%%P"
)
if "%SITE%"=="" ( echo 没有选择文件夹，已退出。 & pause & exit /b 1 )
echo 站点目录: %SITE%

set "FROM="
set /p FROM=这个站点现在运行的 PHP 版本（直接回车 = 7.3；8.1 的站点请输入 8.1）: 
if "%FROM%"=="" set "FROM=7.3"
set "SROOT="
set /p SROOT=服务器上的网站根目录（如 /var/www/example.com，写进升级包的 php.ini 设置；不确定直接回车）: 

"%PHP%" -c "%PHPDIR%\php.ini" "%KIT%\phpup.php" %CMD% "%SITE%" --from %FROM% --php "%PHP%" --server-root "%SROOT%"
if errorlevel 1 ( echo. & echo 运行出错，请把上面的信息截图发给我。 & pause & exit /b 1 )

set "REPORT="
for /f "usebackq delims=" %%R in ("%KIT%\reports\last-report.txt") do set "REPORT=%%R"
if not "%REPORT%"=="" (
  echo.
  echo 报告: %REPORT%
  start "" notepad "%REPORT%"
  for %%D in ("%REPORT%") do start "" explorer "%%~dpD"
)
echo.
echo 完成。
pause
exit /b 0

:restore
set "ZIP="
for /f "usebackq delims=" %%P in (`powershell -NoProfile -ExecutionPolicy Bypass -File "%KIT%\windows\pick.ps1" -Mode zip -Title "请选择要还原的备份文件（reports 目录下的 backup-*.zip）"`) do set "ZIP=%%P"
if "%ZIP%"=="" ( echo 没有选择备份文件，已退出。 & pause & exit /b 1 )
set "SITE="
for /f "usebackq delims=" %%P in (`powershell -NoProfile -ExecutionPolicy Bypass -File "%KIT%\windows\pick.ps1" -Mode folder -Title "请选择要还原到的站点文件夹"`) do set "SITE=%%P"
if "%SITE%"=="" ( echo 没有选择文件夹，已退出。 & pause & exit /b 1 )
"%PHP%" -c "%PHPDIR%\php.ini" "%KIT%\phpup.php" restore "%ZIP%" "%SITE%"
pause
exit /b 0
