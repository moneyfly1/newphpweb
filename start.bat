@echo off
REM 一键启动脚本 - ThinkPHP 管理后台 (Windows)
REM 使用方式: start.bat [port]
REM 示例: start.bat 8000

setlocal enabledelayedexpansion
set PORT=%1
if "%PORT%"=="" set PORT=8000

echo ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo     🚀 ThinkPHP 管理后台 - 一键启动脚本
echo ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo.

REM 获取项目目录
set PROJECT_DIR=%cd%
set DB_FILE=%PROJECT_DIR%\runtime\cboard.sqlite

REM 第一步: 检查 PHP
echo [1/6] 检查 PHP 环境...
php -v >nul 2>&1
if errorlevel 1 (
    echo ✗ 未找到 PHP，请先安装 PHP 8.0+
    exit /b 1
)
for /f "tokens=*" %%a in ('php -r "echo PHP_VERSION;"') do set PHP_VERSION=%%a
echo ✓ PHP 版本: %PHP_VERSION%

REM 第二步: 检查 Composer
echo.
echo [2/6] 检查 Composer 依赖...
if not exist "%PROJECT_DIR%\vendor" (
    echo   → 首次运行，正在安装依赖...
    call composer install
    echo ✓ 依赖安装成功
) else (
    echo ✓ 依赖已安装
)

REM 第三步: 创建运行时目录
echo.
echo [3/6] 创建运行时目录...
if not exist "%PROJECT_DIR%\runtime\log" mkdir "%PROJECT_DIR%\runtime\log"
if not exist "%PROJECT_DIR%\runtime\session" mkdir "%PROJECT_DIR%\runtime\session"
if not exist "%PROJECT_DIR%\runtime\temp" mkdir "%PROJECT_DIR%\runtime\temp"
echo ✓ 运行时目录就绪

REM 第四步: 初始化数据库
echo.
echo [4/6] 检查数据库...
if not exist "%DB_FILE%" (
    echo   → 首次运行，正在初始化数据库...
    sqlite3 "%DB_FILE%" < "%PROJECT_DIR%\database\sqlite_dev.sql"
    echo ✓ 数据库初始化完成
    echo.
    echo ━━ 📋 测试账户 ━━
    echo Email: admin@example.com
    echo Password: admin123
    echo.
) else (
    echo ✓ 数据库已就绪
)

REM 第五步: 检查 .env 配置
echo.
echo [5/6] 检查环境配置...
if exist "%PROJECT_DIR%\.env" (
    echo ✓ 环境配置文件存在
) else (
    echo ✗ 未找到 .env 文件
    exit /b 1
)

REM 第六步: 启动服务器
echo.
echo [6/6] 启动开发服务器...
echo.
echo ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo ✓ 一切就绪！
echo.
echo   📌 访问地址: http://localhost:%PORT%
echo   🔐 登录页面: http://localhost:%PORT%/login
echo   👥 用户管理: http://localhost:%PORT%/admin/users
echo   📦 订阅管理: http://localhost:%PORT%/admin/subscriptions
echo.
echo   ⚠️  按 Ctrl+C 停止服务器
echo ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo.

REM 启动服务器
cd /d %PROJECT_DIR%
php think server --host 0.0.0.0 --port %PORT%

pause
