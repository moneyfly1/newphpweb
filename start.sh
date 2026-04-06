#!/bin/bash

###############################################################################
# 🚀 一键启动脚本 - ThinkPHP 管理后台
# 使用方式: bash start.sh [port]
# 示例: bash start.sh 8000
###############################################################################

set -e  # 遇到错误立即退出

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 端口配置
PORT=${1:-8000}
PROJECT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
DB_FILE="$PROJECT_DIR/runtime/cboard.sqlite"

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}    🚀 ThinkPHP 管理后台 - 一键启动脚本${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

# 第一步: 检查 PHP
echo -e "${YELLOW}[1/6] 检查 PHP 环境...${NC}"
if ! command -v php &> /dev/null; then
    echo -e "${RED}✗ 未找到 PHP，请先安装 PHP >= 8.0${NC}"
    exit 1
fi

PHP_VERSION=$(php -r 'echo PHP_VERSION;')
echo -e "${GREEN}✓ PHP 版本: $PHP_VERSION${NC}"

# 第二步: 检查 Composer
echo ""
echo -e "${YELLOW}[2/6] 检查 Composer 依赖...${NC}"
if [ ! -d "$PROJECT_DIR/vendor" ]; then
    echo -e "${YELLOW}  → 首次运行，正在安装依赖...${NC}"
    cd "$PROJECT_DIR"
    composer install --quiet
    echo -e "${GREEN}✓ 依赖安装成功${NC}"
else
    echo -e "${GREEN}✓ 依赖已安装${NC}"
fi

# 第三步: 创建运行时目录
echo ""
echo -e "${YELLOW}[3/6] 创建运行时目录...${NC}"
mkdir -p "$PROJECT_DIR/runtime/log"
mkdir -p "$PROJECT_DIR/runtime/session"
mkdir -p "$PROJECT_DIR/runtime/temp"
chmod -R 777 "$PROJECT_DIR/runtime" 2>/dev/null || true
chmod -R 755 "$PROJECT_DIR/public" 2>/dev/null || true
echo -e "${GREEN}✓ 运行时目录就绪${NC}"

# 第四步: 初始化数据库
echo ""
echo -e "${YELLOW}[4/6] 检查数据库...${NC}"
if [ ! -f "$DB_FILE" ]; then
    echo -e "${YELLOW}  → 首次运行，正在初始化数据库...${NC}"
    sqlite3 "$DB_FILE" < "$PROJECT_DIR/database/install.sql" 2>/dev/null
    echo -e "${GREEN}✓ 数据库初始化完成${NC}"
    
    # 显示测试账户
    echo ""
    echo -e "${BLUE}━━ 📋 测试账户 ━━${NC}"
    echo -e "Email: ${YELLOW}admin@example.com${NC}"
    echo -e "Password: ${YELLOW}admin123${NC}"
    echo ""
else
    # 检查表是否存在
    TABLE_COUNT=$(sqlite3 "$DB_FILE" "SELECT COUNT(*) FROM sqlite_master WHERE type='table';" 2>/dev/null || echo 0)
    if [ "$TABLE_COUNT" -gt 0 ]; then
        echo -e "${GREEN}✓ 数据库已就绪 ($(sqlite3 "$DB_FILE" 'SELECT COUNT(*) FROM cb_users;') 个用户)${NC}"
    else
        echo -e "${YELLOW}⚠ 数据库为空，重新初始化...${NC}"
        sqlite3 "$DB_FILE" < "$PROJECT_DIR/database/install.sql" 2>/dev/null
        echo -e "${GREEN}✓ 数据库初始化完成${NC}"
    fi
fi

# 第五步: 检查 .env 配置
echo ""
echo -e "${YELLOW}[5/6] 检查环境配置...${NC}"
if [ -f "$PROJECT_DIR/.env" ]; then
    echo -e "${GREEN}✓ 环境配置文件存在${NC}"
else
    echo -e "${RED}✗ 未找到 .env 文件${NC}"
    exit 1
fi

# 第六步: 启动服务器
echo ""
echo -e "${YELLOW}[6/6] 启动开发服务器...${NC}"
echo ""
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}✓ 一切就绪！${NC}"
echo ""
echo -e "  📌 访问地址: ${BLUE}http://localhost:$PORT${NC}"
echo -e "  🔐 登录页面: ${BLUE}http://localhost:$PORT/login${NC}"
echo -e "  👥 用户管理: ${BLUE}http://localhost:$PORT/admin/users${NC}"
echo -e "  📦 订阅管理: ${BLUE}http://localhost:$PORT/admin/subscriptions${NC}"
echo ""
echo -e "  ⚠️  按 ${YELLOW}Ctrl+C${NC} 停止服务器"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

# 启动服务器
cd "$PROJECT_DIR"
echo ""
echo -e "${YELLOW}提示: 生产环境请添加定时任务到 crontab:${NC}"
echo -e "  * * * * * cd $PROJECT_DIR && php think schedule:run >> /dev/null 2>&1"
echo ""
echo -e "${BLUE}启动中...${NC}"
php -S 0.0.0.0:$PORT -t public

