#!/bin/bash
# ============================================================
#  CBoard 交互式安装 & 管理脚本 (宝塔面板版)
#  适用于: CentOS / Ubuntu / Debian + 宝塔面板
#  数据库: SQLite (无需 MySQL)
# ============================================================

set -e

# ==================== 颜色与样式 ====================
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
BOLD='\033[1m'
NC='\033[0m'

# ==================== 全局变量 ====================
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BT_WWW="/www/wwwroot"
BT_VHOST_DIR="/www/server/panel/vhost"
NGINX_VHOST_DIR="/www/server/panel/vhost/nginx"
PHP_BIN=""
COMPOSER_BIN=""
SITE_DIR=""
DOMAIN=""
GITHUB_REPO=""
INSTALL_LOG="/tmp/cboard_install_$(date +%Y%m%d_%H%M%S).log"

# ==================== 工具函数 ====================
print_banner() {
    clear
    echo -e "${CYAN}"
    echo "  ╔══════════════════════════════════════════════════╗"
    echo "  ║                                                  ║"
    echo "  ║       CBoard 代理服务平台 - 安装管理工具        ║"
    echo "  ║                                                  ║"
    echo "  ║       宝塔面板一键部署  |  SQLite 轻量架构       ║"
    echo "  ║                                                  ║"
    echo "  ╚══════════════════════════════════════════════════╝"
    echo -e "${NC}"
}

info()    { echo -e "  ${GREEN}[✓]${NC} $1"; }
warn()    { echo -e "  ${YELLOW}[!]${NC} $1"; }
error()   { echo -e "  ${RED}[✗]${NC} $1"; }
step()    { echo -e "\n  ${BLUE}${BOLD}▸ $1${NC}"; }
ask()     { echo -ne "  ${MAGENTA}[?]${NC} $1"; }
divider() { echo -e "  ${CYAN}──────────────────────────────────────────────${NC}"; }

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$INSTALL_LOG"; }

confirm() {
    local msg="$1"
    local default="${2:-y}"
    if [[ "$default" == "y" ]]; then
        ask "$msg [Y/n]: "
    else
        ask "$msg [y/N]: "
    fi
    read -r choice
    choice="${choice:-$default}"
    [[ "$choice" =~ ^[Yy]$ ]]
}

press_enter() {
    echo ""
    ask "按 Enter 继续..."
    read -r
}

# ==================== 环境检测 ====================
check_root() {
    if [[ $EUID -ne 0 ]]; then
        error "请使用 root 用户运行此脚本"
        echo -e "  使用: ${YELLOW}sudo bash install.sh${NC}"
        exit 1
    fi
}

check_bt_panel() {
    step "检测宝塔面板"
    if [[ ! -d "/www/server/panel" ]]; then
        error "未检测到宝塔面板，请先安装宝塔面板"
        echo -e "  安装地址: ${YELLOW}https://www.bt.cn/new/download.html${NC}"
        exit 1
    fi
    info "宝塔面板已安装"

    # 检测 Nginx
    if [[ -f "/www/server/nginx/sbin/nginx" ]]; then
        info "Nginx 已安装"
    else
        error "未检测到 Nginx，请在宝塔面板中安装 Nginx"
        exit 1
    fi
}

detect_php() {
    step "检测 PHP 环境"
    local php_versions=("83" "82" "81" "80")
    PHP_BIN=""

    for ver in "${php_versions[@]}"; do
        local php_path="/www/server/php/${ver}/bin/php"
        if [[ -x "$php_path" ]]; then
            local full_ver
            full_ver=$("$php_path" -r 'echo PHP_VERSION;' 2>/dev/null)
            info "找到 PHP ${full_ver} (${php_path})"
            PHP_BIN="$php_path"
            break
        fi
    done

    if [[ -z "$PHP_BIN" ]]; then
        error "未找到 PHP >= 8.0，请在宝塔面板中安装 PHP 8.0+"
        exit 1
    fi

    # 检查必要扩展
    local required_exts=("pdo_sqlite" "sqlite3" "mbstring" "openssl" "json" "tokenizer" "fileinfo")
    local missing_exts=()

    for ext in "${required_exts[@]}"; do
        if ! $PHP_BIN -m 2>/dev/null | grep -qi "^${ext}$"; then
            missing_exts+=("$ext")
        fi
    done

    if [[ ${#missing_exts[@]} -gt 0 ]]; then
        warn "缺少 PHP 扩展: ${missing_exts[*]}"
        echo -e "  请在宝塔面板 > PHP 管理 > 安装扩展 中安装以上扩展"
        if ! confirm "扩展安装完成后继续？" "n"; then
            exit 1
        fi
    else
        info "PHP 扩展检查通过"
    fi
}

detect_composer() {
    step "检测 Composer"
    if command -v composer &>/dev/null; then
        COMPOSER_BIN="composer"
        info "Composer 已安装: $(composer --version 2>/dev/null | head -1)"
    elif [[ -f "/usr/local/bin/composer" ]]; then
        COMPOSER_BIN="/usr/local/bin/composer"
        info "Composer 已安装"
    else
        warn "未检测到 Composer，正在安装..."
        install_composer
    fi
}

install_composer() {
    local expected_sig
    expected_sig=$(curl -sS https://composer.github.io/installer.sig)
    $PHP_BIN -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
    local actual_sig
    actual_sig=$($PHP_BIN -r "echo hash_file('sha384', '/tmp/composer-setup.php');")

    if [[ "$expected_sig" == "$actual_sig" ]]; then
        $PHP_BIN /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
        COMPOSER_BIN="/usr/local/bin/composer"
        info "Composer 安装成功"
    else
        error "Composer 安装包校验失败，请手动安装"
        rm -f /tmp/composer-setup.php
        exit 1
    fi
    rm -f /tmp/composer-setup.php
}

check_git() {
    step "检测 Git"
    if command -v git &>/dev/null; then
        info "Git 已安装: $(git --version)"
    else
        warn "Git 未安装，正在安装..."
        if command -v yum &>/dev/null; then
            yum install -y git >> "$INSTALL_LOG" 2>&1
        elif command -v apt-get &>/dev/null; then
            apt-get install -y git >> "$INSTALL_LOG" 2>&1
        fi
        if command -v git &>/dev/null; then
            info "Git 安装成功"
        else
            error "Git 安装失败，请手动安装"
            exit 1
        fi
    fi
}

# ==================== 主菜单 ====================
show_main_menu() {
    print_banner
    echo -e "  ${BOLD}请选择操作:${NC}\n"
    echo -e "  ${GREEN}1)${NC}  全新安装 (从 GitHub 克隆)"
    echo -e "  ${GREEN}2)${NC}  初始化已有代码 (代码已在目录中)"
    echo -e "  ${GREEN}3)${NC}  更新代码 (从 GitHub 拉取最新)"
    echo -e "  ${GREEN}4)${NC}  配置域名 & Nginx"
    echo -e "  ${GREEN}5)${NC}  配置 .env 环境变量"
    echo -e "  ${GREEN}6)${NC}  初始化数据库"
    echo -e "  ${GREEN}7)${NC}  管理员账号管理"
    echo -e "  ${GREEN}8)${NC}  设置目录权限"
    echo -e "  ${GREEN}9)${NC}  重启服务 (Nginx / PHP)"
    echo -e "  ${GREEN}10)${NC} SSL 证书配置"
    echo -e "  ${GREEN}11)${NC} 系统状态检查"
    echo -e "  ${GREEN}12)${NC} 完整一键安装 (推荐首次使用)"
    divider
    echo -e "  ${RED}0)${NC}  退出"
    echo ""
    ask "请输入选项 [0-12]: "
    read -r choice
    echo ""
    case "$choice" in
        1)  do_clone_install ;;
        2)  do_init_existing ;;
        3)  do_update_code ;;
        4)  do_configure_domain ;;
        5)  do_configure_env ;;
        6)  do_init_database ;;
        7)  do_admin_manage ;;
        8)  do_fix_permissions ;;
        9)  do_restart_services ;;
        10) do_ssl_config ;;
        11) do_status_check ;;
        12) do_full_install ;;
        0)  echo -e "  ${GREEN}再见！${NC}"; exit 0 ;;
        *)  warn "无效选项"; press_enter; show_main_menu ;;
    esac
}

# ==================== 1. 从 GitHub 克隆安装 ====================
do_clone_install() {
    step "从 GitHub 克隆代码"

    ask "请输入 GitHub 仓库地址 (如 https://github.com/user/cboard.git): "
    read -r GITHUB_REPO
    if [[ -z "$GITHUB_REPO" ]]; then
        error "仓库地址不能为空"
        press_enter; show_main_menu; return
    fi

    ask "请输入域名 (如 panel.example.com): "
    read -r DOMAIN
    if [[ -z "$DOMAIN" ]]; then
        error "域名不能为空"
        press_enter; show_main_menu; return
    fi

    SITE_DIR="${BT_WWW}/${DOMAIN}"

    if [[ -d "$SITE_DIR" ]]; then
        warn "目录 ${SITE_DIR} 已存在"
        if ! confirm "是否清空并重新克隆？" "n"; then
            press_enter; show_main_menu; return
        fi
        rm -rf "$SITE_DIR"
    fi

    info "正在克隆仓库到 ${SITE_DIR} ..."
    git clone "$GITHUB_REPO" "$SITE_DIR" 2>&1 | tee -a "$INSTALL_LOG"

    if [[ $? -eq 0 ]]; then
        info "代码克隆成功"
        log "Cloned $GITHUB_REPO to $SITE_DIR"
    else
        error "克隆失败，请检查仓库地址和网络"
        press_enter; show_main_menu; return
    fi

    # 选择分支
    ask "是否切换分支？(默认 main) [y/N]: "
    read -r switch_branch
    if [[ "$switch_branch" =~ ^[Yy]$ ]]; then
        cd "$SITE_DIR"
        echo ""
        info "可用分支:"
        git branch -a 2>/dev/null | sed 's/^/    /'
        echo ""
        ask "请输入分支名: "
        read -r branch_name
        if [[ -n "$branch_name" ]]; then
            git checkout "$branch_name" 2>&1 | tee -a "$INSTALL_LOG"
            info "已切换到分支: $branch_name"
        fi
    fi

    # 继续安装依赖
    do_install_deps
    press_enter; show_main_menu
}

# ==================== 2. 初始化已有代码 ====================
do_init_existing() {
    step "初始化已有代码"

    # 列出宝塔网站目录
    echo ""
    info "宝塔网站目录 (${BT_WWW}):"
    echo ""
    local i=1
    local dirs=()
    for d in "$BT_WWW"/*/; do
        if [[ -d "$d" ]]; then
            local dirname
            dirname=$(basename "$d")
            dirs+=("$d")
            echo -e "    ${GREEN}${i})${NC} ${dirname}"
            ((i++))
        fi
    done

    if [[ ${#dirs[@]} -eq 0 ]]; then
        warn "未找到网站目录"
        ask "请手动输入项目完整路径: "
        read -r SITE_DIR
    else
        echo ""
        ask "请选择目录编号 (或输入完整路径): "
        read -r dir_choice
        if [[ "$dir_choice" =~ ^[0-9]+$ ]] && [[ "$dir_choice" -ge 1 ]] && [[ "$dir_choice" -le ${#dirs[@]} ]]; then
            SITE_DIR="${dirs[$((dir_choice-1))]}"
            SITE_DIR="${SITE_DIR%/}"
        else
            SITE_DIR="$dir_choice"
        fi
    fi

    if [[ ! -f "${SITE_DIR}/think" ]]; then
        error "目录 ${SITE_DIR} 不是有效的 CBoard 项目 (未找到 think 文件)"
        press_enter; show_main_menu; return
    fi

    DOMAIN=$(basename "$SITE_DIR")
    info "项目目录: ${SITE_DIR}"
    info "域名: ${DOMAIN}"

    do_install_deps
    press_enter; show_main_menu
}

# ==================== 安装 Composer 依赖 ====================
do_install_deps() {
    step "安装 Composer 依赖"

    if [[ -z "$SITE_DIR" ]] || [[ ! -d "$SITE_DIR" ]]; then
        error "项目目录未设置或不存在"
        return 1
    fi

    cd "$SITE_DIR"

    if [[ -d "vendor" ]] && [[ -f "vendor/autoload.php" ]]; then
        info "vendor 目录已存在"
        if ! confirm "是否重新安装依赖？"; then
            return 0
        fi
    fi

    info "正在安装依赖 (可能需要几分钟) ..."
    $PHP_BIN $COMPOSER_BIN install --no-dev --optimize-autoloader 2>&1 | tee -a "$INSTALL_LOG"

    if [[ -f "vendor/autoload.php" ]]; then
        info "依赖安装成功"
    else
        error "依赖安装失败，请检查日志: ${INSTALL_LOG}"
        return 1
    fi
}

# ==================== 3. 更新代码 ====================
do_update_code() {
    step "从 GitHub 更新代码"
    select_site_dir || return

    cd "$SITE_DIR"

    if [[ ! -d ".git" ]]; then
        error "该目录不是 Git 仓库，无法拉取更新"
        press_enter; show_main_menu; return
    fi

    info "当前分支: $(git branch --show-current 2>/dev/null)"
    info "最近提交: $(git log --oneline -1 2>/dev/null)"
    echo ""

    # 检查是否有未提交的更改
    if [[ -n "$(git status --porcelain 2>/dev/null)" ]]; then
        warn "检测到未提交的本地更改:"
        git status --short | head -20 | sed 's/^/    /'
        echo ""
        echo -e "  ${GREEN}1)${NC} 暂存更改后拉取 (git stash)"
        echo -e "  ${GREEN}2)${NC} 强制覆盖本地更改"
        echo -e "  ${GREEN}3)${NC} 取消更新"
        ask "请选择 [1-3]: "
        read -r update_choice
        case "$update_choice" in
            1)
                git stash 2>&1 | tee -a "$INSTALL_LOG"
                info "本地更改已暂存"
                ;;
            2)
                git checkout -- . 2>&1 | tee -a "$INSTALL_LOG"
                git clean -fd 2>&1 | tee -a "$INSTALL_LOG"
                warn "本地更改已丢弃"
                ;;
            3)
                press_enter; show_main_menu; return
                ;;
        esac
    fi

    info "正在拉取最新代码..."
    git pull origin "$(git branch --show-current)" 2>&1 | tee -a "$INSTALL_LOG"

    if [[ $? -eq 0 ]]; then
        info "代码更新成功"
        info "最新提交: $(git log --oneline -1 2>/dev/null)"

        if confirm "是否更新 Composer 依赖？"; then
            $PHP_BIN $COMPOSER_BIN install --no-dev --optimize-autoloader 2>&1 | tee -a "$INSTALL_LOG"
            info "依赖更新完成"
        fi

        # 清除运行时缓存
        if confirm "是否清除运行时缓存？"; then
            rm -rf "${SITE_DIR}/runtime/cache"/*
            rm -rf "${SITE_DIR}/runtime/temp"/*
            info "缓存已清除"
        fi
    else
        error "代码更新失败"
    fi

    press_enter; show_main_menu
}

# ==================== 4. 配置域名 & Nginx ====================
do_configure_domain() {
    step "配置域名 & Nginx"
    select_site_dir || return

    ask "请输入域名 (如 panel.example.com): "
    read -r DOMAIN
    if [[ -z "$DOMAIN" ]]; then
        error "域名不能为空"
        press_enter; show_main_menu; return
    fi

    local nginx_conf="${NGINX_VHOST_DIR}/${DOMAIN}.conf"

    # 检测 PHP 版本号用于 socket
    local php_ver_short
    php_ver_short=$(echo "$PHP_BIN" | grep -oP '\d{2}' | head -1)
    local php_socket="/tmp/php-cgi-${php_ver_short}.sock"

    if [[ ! -S "$php_socket" ]]; then
        # 尝试其他常见路径
        php_socket="/www/server/php/${php_ver_short}/var/run/php-fpm.sock"
        if [[ ! -S "$php_socket" ]]; then
            php_socket="127.0.0.1:9000"
            warn "未找到 PHP-FPM socket，使用默认: ${php_socket}"
        fi
    fi

    info "生成 Nginx 配置..."
    cat > "$nginx_conf" << NGINX_EOF
server {
    listen 80;
    server_name ${DOMAIN};
    index index.php index.html;
    root ${SITE_DIR}/public;

    # 日志
    access_log /www/wwwlogs/${DOMAIN}.log;
    error_log  /www/wwwlogs/${DOMAIN}.error.log;

    # 禁止访问隐藏文件
    location ~ /\\.(?!well-known) {
        deny all;
    }

    # 禁止直接访问 runtime 目录
    location ~ ^/runtime/ {
        deny all;
    }

    # 静态资源缓存
    location ~ \\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        access_log off;
        add_header Cache-Control "public, immutable";
    }

    # ThinkPHP 伪静态 (核心)
    location / {
        if (!-e \$request_filename) {
            rewrite ^(.*)$ /index.php\$1 last;
        }
    }

    # PHP 处理
    location ~ \\.php(.*)$ {
        fastcgi_pass unix:${php_socket};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param PATH_INFO \$1;
        include fastcgi_params;
        fastcgi_param PHP_VALUE "
            open_basedir=${SITE_DIR}/:/tmp/:/proc/
            upload_max_filesize=50M
            post_max_size=50M
            max_execution_time=300
        ";
    }
}
NGINX_EOF

    info "Nginx 配置已写入: ${nginx_conf}"

    # 测试配置
    local nginx_test
    nginx_test=$(/www/server/nginx/sbin/nginx -t 2>&1)
    if echo "$nginx_test" | grep -q "successful"; then
        info "Nginx 配置测试通过"
        /www/server/nginx/sbin/nginx -s reload
        info "Nginx 已重载"
    else
        error "Nginx 配置有误:"
        echo "$nginx_test" | sed 's/^/    /'
        warn "请手动检查: ${nginx_conf}"
    fi

    press_enter; show_main_menu
}

# ==================== 辅助: 选择站点目录 ====================
select_site_dir() {
    if [[ -n "$SITE_DIR" ]] && [[ -d "$SITE_DIR" ]]; then
        if confirm "使用当前目录 ${SITE_DIR}？"; then
            return 0
        fi
    fi

    echo ""
    info "宝塔网站目录:"
    echo ""
    local i=1
    local dirs=()
    for d in "$BT_WWW"/*/; do
        if [[ -d "$d" ]] && [[ -f "${d}think" ]]; then
            local dirname
            dirname=$(basename "$d")
            dirs+=("$d")
            echo -e "    ${GREEN}${i})${NC} ${dirname}"
            ((i++))
        fi
    done

    if [[ ${#dirs[@]} -eq 0 ]]; then
        warn "未找到 CBoard 项目目录"
        ask "请输入项目完整路径: "
        read -r SITE_DIR
        if [[ ! -f "${SITE_DIR}/think" ]]; then
            error "无效的 CBoard 项目目录"
            return 1
        fi
    else
        echo ""
        ask "请选择 [1-${#dirs[@]}] 或输入完整路径: "
        read -r dir_choice
        if [[ "$dir_choice" =~ ^[0-9]+$ ]] && [[ "$dir_choice" -ge 1 ]] && [[ "$dir_choice" -le ${#dirs[@]} ]]; then
            SITE_DIR="${dirs[$((dir_choice-1))]}"
            SITE_DIR="${SITE_DIR%/}"
        else
            SITE_DIR="$dir_choice"
        fi
    fi

    DOMAIN=$(basename "$SITE_DIR")
    return 0
}

# __PLACEHOLDER_PART2__

# ==================== 5. 配置 .env ====================
do_configure_env() {
    step "配置 .env 环境变量"
    select_site_dir || return

    local env_file="${SITE_DIR}/.env"

    if [[ -f "$env_file" ]]; then
        warn "已存在 .env 文件，当前内容:"
        echo ""
        cat "$env_file" | sed 's/^/    /'
        echo ""
        if ! confirm "是否重新配置？"; then
            press_enter; show_main_menu; return
        fi
    fi

    echo ""
    info "开始配置环境变量"
    divider

    # APP_DEBUG
    echo ""
    echo -e "  ${BOLD}运行模式:${NC}"
    echo -e "    ${GREEN}1)${NC} 生产模式 (推荐)"
    echo -e "    ${GREEN}2)${NC} 调试模式 (开发用)"
    ask "请选择 [1-2]: "
    read -r debug_choice
    local app_debug="false"
    [[ "$debug_choice" == "2" ]] && app_debug="true"

    # 数据库驱动
    echo ""
    echo -e "  ${BOLD}数据库类型:${NC}"
    echo -e "    ${GREEN}1)${NC} SQLite (推荐，轻量无需额外配置)"
    echo -e "    ${GREEN}2)${NC} MySQL"
    ask "请选择 [1-2]: "
    read -r db_choice

    local db_driver="sqlite"
    local db_host="" db_port="" db_name="" db_user="" db_pass=""

    if [[ "$db_choice" == "2" ]]; then
        db_driver="mysql"
        ask "MySQL 主机 [127.0.0.1]: "
        read -r db_host; db_host="${db_host:-127.0.0.1}"
        ask "MySQL 端口 [3306]: "
        read -r db_port; db_port="${db_port:-3306}"
        ask "数据库名 [cboard]: "
        read -r db_name; db_name="${db_name:-cboard}"
        ask "数据库用户名 [root]: "
        read -r db_user; db_user="${db_user:-root}"
        ask "数据库密码: "
        read -rs db_pass; echo ""
    fi

    # 站点名称
    echo ""
    ask "站点名称 [CBoard 代理服务平台]: "
    read -r app_name
    app_name="${app_name:-CBoard 代理服务平台}"

    # 站点 URL
    ask "站点 URL (如 https://panel.example.com): "
    read -r base_url
    if [[ -z "$base_url" ]]; then
        base_url="https://${DOMAIN}"
    fi

    # 写入 .env
    {
        echo "APP_DEBUG=${app_debug}"
        echo "APP_TRACE=false"
        echo ""
        echo "DB_DRIVER=${db_driver}"
        if [[ "$db_driver" == "mysql" ]]; then
            echo "DB_HOST=${db_host}"
            echo "DB_PORT=${db_port}"
            echo "DB_NAME=${db_name}"
            echo "DB_USER=${db_user}"
            echo "DB_PASS=${db_pass}"
            echo "DB_PREFIX="
        fi
        echo ""
        echo "CB_APP_NAME=${app_name}"
        echo "CB_BASE_URL=${base_url}"
    } > "$env_file"

    info ".env 配置完成"
    echo ""
    cat "$env_file" | sed 's/^/    /'

    press_enter; show_main_menu
}

# __PLACEHOLDER_PART3__

# ==================== 6. 初始化数据库 ====================
do_init_database() {
    step "初始化数据库"
    select_site_dir || return

    local sql_file="${SITE_DIR}/database/install.sql"
    local db_file="${SITE_DIR}/runtime/cboard.sqlite"

    if [[ ! -f "$sql_file" ]]; then
        error "未找到数据库脚本: ${sql_file}"
        press_enter; show_main_menu; return
    fi

    # 读取 .env 判断数据库类型
    local db_driver="sqlite"
    if [[ -f "${SITE_DIR}/.env" ]]; then
        db_driver=$(grep -E "^DB_DRIVER=" "${SITE_DIR}/.env" | cut -d= -f2 | tr -d '[:space:]')
        db_driver="${db_driver:-sqlite}"
    fi

    if [[ "$db_driver" == "sqlite" ]]; then
        init_sqlite_db "$sql_file" "$db_file"
    else
        init_mysql_db
    fi

    press_enter; show_main_menu
}

init_sqlite_db() {
    local sql_file="$1"
    local db_file="$2"

    # 检查 sqlite3 命令
    if ! command -v sqlite3 &>/dev/null; then
        warn "sqlite3 未安装，正在安装..."
        if command -v yum &>/dev/null; then
            yum install -y sqlite >> "$INSTALL_LOG" 2>&1
        elif command -v apt-get &>/dev/null; then
            apt-get install -y sqlite3 >> "$INSTALL_LOG" 2>&1
        fi
    fi

    if [[ -f "$db_file" ]]; then
        local table_count
        table_count=$(sqlite3 "$db_file" "SELECT count(*) FROM sqlite_master WHERE type='table';" 2>/dev/null || echo "0")
        if [[ "$table_count" -gt 0 ]]; then
            warn "数据库已存在 (${table_count} 张表)"
            echo ""
            echo -e "    ${GREEN}1)${NC} 跳过 (保留现有数据)"
            echo -e "    ${GREEN}2)${NC} 备份后重新初始化"
            echo -e "    ${GREEN}3)${NC} 直接覆盖 (数据将丢失)"
            ask "请选择 [1-3]: "
            read -r db_action
            case "$db_action" in
                1) info "跳过数据库初始化"; return 0 ;;
                2)
                    local backup="${db_file}.bak.$(date +%Y%m%d_%H%M%S)"
                    cp "$db_file" "$backup"
                    info "已备份到: ${backup}"
                    rm -f "$db_file"
                    ;;
                3) rm -f "$db_file" ;;
                *) return 0 ;;
            esac
        fi
    fi

    mkdir -p "$(dirname "$db_file")"

    info "正在初始化 SQLite 数据库..."
    if sqlite3 "$db_file" < "$sql_file" >> "$INSTALL_LOG" 2>&1; then
        local table_count
        table_count=$(sqlite3 "$db_file" "SELECT count(*) FROM sqlite_master WHERE type='table';" 2>/dev/null)
        info "数据库初始化成功 (${table_count} 张表)"
        chmod 664 "$db_file"
        chown www:www "$db_file"
        info "数据库文件权限已设置"
    else
        error "数据库初始化失败，请查看日志: ${INSTALL_LOG}"
    fi
}

init_mysql_db() {
    warn "当前 install.sql 为 SQLite 格式"
    info "MySQL 用户请在宝塔面板 > 数据库 中创建数据库后手动导入"
    info "或联系开发者获取 MySQL 版本的建表脚本"
}

# __PLACEHOLDER_PART4__

# ==================== 7. 管理员账号管理 ====================
do_admin_manage() {
    step "管理员账号管理"
    select_site_dir || return

    echo ""
    echo -e "  ${BOLD}请选择操作:${NC}"
    echo ""
    echo -e "    ${GREEN}1)${NC} 创建管理员账号"
    echo -e "    ${GREEN}2)${NC} 修改管理员密码"
    echo -e "    ${GREEN}3)${NC} 修改管理员邮箱"
    echo -e "    ${GREEN}4)${NC} 查看所有管理员"
    echo -e "    ${GREEN}5)${NC} 重置为默认管理员"
    echo -e "    ${GREEN}0)${NC} 返回主菜单"
    echo ""
    ask "请选择 [0-5]: "
    read -r admin_choice

    case "$admin_choice" in
        1) admin_create ;;
        2) admin_change_password ;;
        3) admin_change_email ;;
        4) admin_list ;;
        5) admin_reset_default ;;
        0) show_main_menu; return ;;
        *) warn "无效选项" ;;
    esac

    press_enter; do_admin_manage
}

admin_create() {
    step "创建管理员账号"

    ask "管理员邮箱: "
    read -r admin_email
    if [[ -z "$admin_email" ]]; then
        error "邮箱不能为空"; return
    fi

    ask "管理员昵称 [Admin]: "
    read -r admin_nick
    admin_nick="${admin_nick:-Admin}"

    while true; do
        ask "管理员密码 (至少8位): "
        read -rs admin_pass; echo ""
        if [[ ${#admin_pass} -lt 8 ]]; then
            warn "密码至少8位，请重新输入"
            continue
        fi
        ask "确认密码: "
        read -rs admin_pass2; echo ""
        if [[ "$admin_pass" != "$admin_pass2" ]]; then
            warn "两次密码不一致，请重新输入"
            continue
        fi
        break
    done

    # 使用 PHP 生成密码哈希并插入数据库
    local hash
    hash=$($PHP_BIN -r "echo password_hash('${admin_pass}', PASSWORD_BCRYPT);")

    local db_driver="sqlite"
    if [[ -f "${SITE_DIR}/.env" ]]; then
        db_driver=$(grep -E "^DB_DRIVER=" "${SITE_DIR}/.env" | cut -d= -f2 | tr -d '[:space:]')
    fi

    if [[ "$db_driver" == "sqlite" ]]; then
        local db_file="${SITE_DIR}/runtime/cboard.sqlite"
        if [[ ! -f "$db_file" ]]; then
            error "数据库文件不存在，请先初始化数据库"; return
        fi

        # 检查邮箱是否已存在
        local exists
        exists=$(sqlite3 "$db_file" "SELECT count(*) FROM users WHERE email='${admin_email}';")
        if [[ "$exists" -gt 0 ]]; then
            error "邮箱 ${admin_email} 已存在"; return
        fi

        local invite_code
        invite_code=$(head -c 6 /dev/urandom | xxd -p | head -c 8 | tr '[:lower:]' '[:upper:]')

        sqlite3 "$db_file" "INSERT INTO users (email, password_hash, nickname, role, status, balance, invite_code) VALUES ('${admin_email}', '${hash}', '${admin_nick}', 1, 1, 0.00, '${invite_code}');"

        if [[ $? -eq 0 ]]; then
            info "管理员创建成功"
            info "邮箱: ${admin_email}"
            info "昵称: ${admin_nick}"
        else
            error "创建失败"
        fi
    else
        # 使用 think 命令
        cd "$SITE_DIR"
        $PHP_BIN think admin:reset-password "$admin_email" "$admin_pass" 2>&1
    fi
}

admin_change_password() {
    step "修改管理员密码"

    local db_driver="sqlite"
    if [[ -f "${SITE_DIR}/.env" ]]; then
        db_driver=$(grep -E "^DB_DRIVER=" "${SITE_DIR}/.env" | cut -d= -f2 | tr -d '[:space:]')
    fi

    # 先列出管理员
    admin_list

    echo ""
    ask "请输入要修改密码的管理员邮箱: "
    read -r admin_email

    if [[ -z "$admin_email" ]]; then
        error "邮箱不能为空"; return
    fi

    while true; do
        ask "新密码 (至少8位): "
        read -rs new_pass; echo ""
        if [[ ${#new_pass} -lt 8 ]]; then
            warn "密码至少8位"; continue
        fi
        ask "确认新密码: "
        read -rs new_pass2; echo ""
        if [[ "$new_pass" != "$new_pass2" ]]; then
            warn "两次密码不一致"; continue
        fi
        break
    done

    local hash
    hash=$($PHP_BIN -r "echo password_hash('${new_pass}', PASSWORD_BCRYPT);")

    if [[ "$db_driver" == "sqlite" ]]; then
        local db_file="${SITE_DIR}/runtime/cboard.sqlite"
        local updated
        updated=$(sqlite3 "$db_file" "UPDATE users SET password_hash='${hash}', updated_at=datetime('now') WHERE email='${admin_email}' AND role=1; SELECT changes();")
        if [[ "$updated" -gt 0 ]]; then
            info "密码修改成功"
        else
            error "未找到该管理员邮箱或修改失败"
        fi
    else
        cd "$SITE_DIR"
        $PHP_BIN think admin:reset-password "$admin_email" "$new_pass" 2>&1
    fi
}

# __PLACEHOLDER_PART5__

admin_change_email() {
    step "修改管理员邮箱"

    admin_list

    echo ""
    ask "请输入当前管理员邮箱: "
    read -r old_email
    ask "请输入新邮箱: "
    read -r new_email

    if [[ -z "$old_email" ]] || [[ -z "$new_email" ]]; then
        error "邮箱不能为空"; return
    fi

    local db_driver="sqlite"
    if [[ -f "${SITE_DIR}/.env" ]]; then
        db_driver=$(grep -E "^DB_DRIVER=" "${SITE_DIR}/.env" | cut -d= -f2 | tr -d '[:space:]')
    fi

    if [[ "$db_driver" == "sqlite" ]]; then
        local db_file="${SITE_DIR}/runtime/cboard.sqlite"

        # 检查新邮箱是否已被使用
        local exists
        exists=$(sqlite3 "$db_file" "SELECT count(*) FROM users WHERE email='${new_email}';")
        if [[ "$exists" -gt 0 ]]; then
            error "新邮箱 ${new_email} 已被使用"; return
        fi

        local updated
        updated=$(sqlite3 "$db_file" "UPDATE users SET email='${new_email}', updated_at=datetime('now') WHERE email='${old_email}' AND role=1; SELECT changes();")
        if [[ "$updated" -gt 0 ]]; then
            info "邮箱修改成功: ${old_email} -> ${new_email}"
        else
            error "未找到该管理员或修改失败"
        fi
    fi
}

admin_list() {
    local db_driver="sqlite"
    if [[ -f "${SITE_DIR}/.env" ]]; then
        db_driver=$(grep -E "^DB_DRIVER=" "${SITE_DIR}/.env" | cut -d= -f2 | tr -d '[:space:]')
    fi

    if [[ "$db_driver" == "sqlite" ]]; then
        local db_file="${SITE_DIR}/runtime/cboard.sqlite"
        if [[ ! -f "$db_file" ]]; then
            error "数据库文件不存在"; return
        fi

        echo ""
        info "当前管理员列表:"
        divider
        printf "    ${BOLD}%-4s %-30s %-15s %-10s${NC}\n" "ID" "邮箱" "昵称" "状态"
        divider
        sqlite3 -separator '|' "$db_file" "SELECT id, email, COALESCE(nickname,''), CASE status WHEN 1 THEN '正常' ELSE '禁用' END FROM users WHERE role=1;" 2>/dev/null | while IFS='|' read -r id email nick status; do
            printf "    %-4s %-30s %-15s %-10s\n" "$id" "$email" "$nick" "$status"
        done
        divider
    fi
}

admin_reset_default() {
    step "重置为默认管理员"
    warn "这将创建默认管理员: admin@cboard.local / admin123"

    if ! confirm "确定要重置？" "n"; then
        return
    fi

    local db_driver="sqlite"
    if [[ -f "${SITE_DIR}/.env" ]]; then
        db_driver=$(grep -E "^DB_DRIVER=" "${SITE_DIR}/.env" | cut -d= -f2 | tr -d '[:space:]')
    fi

    if [[ "$db_driver" == "sqlite" ]]; then
        local db_file="${SITE_DIR}/runtime/cboard.sqlite"
        local hash
        hash=$($PHP_BIN -r "echo password_hash('admin123', PASSWORD_BCRYPT);")

        local exists
        exists=$(sqlite3 "$db_file" "SELECT count(*) FROM users WHERE email='admin@cboard.local';")
        if [[ "$exists" -gt 0 ]]; then
            sqlite3 "$db_file" "UPDATE users SET password_hash='${hash}', role=1, status=1, updated_at=datetime('now') WHERE email='admin@cboard.local';"
        else
            sqlite3 "$db_file" "INSERT INTO users (email, password_hash, nickname, role, status, balance, invite_code) VALUES ('admin@cboard.local', '${hash}', 'Admin', 1, 1, 0.00, 'ADMIN001');"
        fi

        info "默认管理员已重置"
        warn "请登录后立即修改密码！"
    fi
}

# __PLACEHOLDER_PART6__

# ==================== 8. 设置目录权限 ====================
do_fix_permissions() {
    step "设置目录权限"
    select_site_dir || return

    info "设置文件所有者为 www..."
    chown -R www:www "$SITE_DIR"

    info "设置目录权限 755..."
    find "$SITE_DIR" -type d -exec chmod 755 {} \;

    info "设置文件权限 644..."
    find "$SITE_DIR" -type f -exec chmod 644 {} \;

    info "设置 runtime 目录可写..."
    chmod -R 775 "${SITE_DIR}/runtime"

    # SQLite 数据库文件特殊权限
    if [[ -f "${SITE_DIR}/runtime/cboard.sqlite" ]]; then
        chmod 664 "${SITE_DIR}/runtime/cboard.sqlite"
        info "SQLite 数据库文件权限已设置"
    fi

    # think 命令可执行
    if [[ -f "${SITE_DIR}/think" ]]; then
        chmod 755 "${SITE_DIR}/think"
    fi

    # .env 文件保护
    if [[ -f "${SITE_DIR}/.env" ]]; then
        chmod 600 "${SITE_DIR}/.env"
        chown www:www "${SITE_DIR}/.env"
        info ".env 文件权限已保护 (600)"
    fi

    info "权限设置完成"
    press_enter; show_main_menu
}

# ==================== 9. 重启服务 ====================
do_restart_services() {
    step "重启服务"

    echo ""
    echo -e "  ${BOLD}请选择要重启的服务:${NC}"
    echo ""
    echo -e "    ${GREEN}1)${NC} 重启 Nginx"
    echo -e "    ${GREEN}2)${NC} 重启 PHP-FPM"
    echo -e "    ${GREEN}3)${NC} 重启 Nginx + PHP-FPM"
    echo -e "    ${GREEN}4)${NC} 重载 Nginx 配置 (不中断服务)"
    echo -e "    ${GREEN}5)${NC} 清除运行时缓存"
    echo -e "    ${GREEN}6)${NC} 全部重启 + 清缓存"
    echo -e "    ${GREEN}0)${NC} 返回"
    echo ""
    ask "请选择 [0-6]: "
    read -r restart_choice

    case "$restart_choice" in
        1)
            /etc/init.d/nginx restart 2>&1
            info "Nginx 已重启"
            ;;
        2)
            restart_php_fpm
            ;;
        3)
            restart_php_fpm
            /etc/init.d/nginx restart 2>&1
            info "Nginx + PHP-FPM 已重启"
            ;;
        4)
            /www/server/nginx/sbin/nginx -s reload 2>&1
            info "Nginx 配置已重载"
            ;;
        5)
            do_clear_cache
            ;;
        6)
            do_clear_cache
            restart_php_fpm
            /etc/init.d/nginx restart 2>&1
            info "全部服务已重启，缓存已清除"
            ;;
        0) show_main_menu; return ;;
    esac

    press_enter; show_main_menu
}

restart_php_fpm() {
    local php_ver_short
    php_ver_short=$(echo "$PHP_BIN" | grep -oP '\d{2}' | head -1)
    local fpm_init="/etc/init.d/php-fpm-${php_ver_short}"

    if [[ -x "$fpm_init" ]]; then
        $fpm_init restart 2>&1
        info "PHP-FPM ${php_ver_short} 已重启"
    else
        # 尝试宝塔方式
        /etc/init.d/php-fpm-* restart 2>/dev/null
        info "PHP-FPM 已重启"
    fi
}

do_clear_cache() {
    if [[ -n "$SITE_DIR" ]] && [[ -d "$SITE_DIR" ]]; then
        rm -rf "${SITE_DIR}/runtime/cache"/* 2>/dev/null
        rm -rf "${SITE_DIR}/runtime/temp"/* 2>/dev/null
        rm -rf "${SITE_DIR}/runtime/log"/* 2>/dev/null
        info "运行时缓存已清除"
    else
        select_site_dir || return
        rm -rf "${SITE_DIR}/runtime/cache"/* 2>/dev/null
        rm -rf "${SITE_DIR}/runtime/temp"/* 2>/dev/null
        rm -rf "${SITE_DIR}/runtime/log"/* 2>/dev/null
        info "运行时缓存已清除"
    fi
}

# __PLACEHOLDER_PART7__

# ==================== 10. SSL 证书配置 ====================
do_ssl_config() {
    step "SSL 证书配置"

    echo ""
    echo -e "  ${BOLD}SSL 配置方式:${NC}"
    echo ""
    echo -e "    ${GREEN}1)${NC} 通过宝塔面板申请 Let's Encrypt (推荐)"
    echo -e "    ${GREEN}2)${NC} 手动指定证书文件路径"
    echo -e "    ${GREEN}3)${NC} 生成自签名证书 (仅测试用)"
    echo -e "    ${GREEN}0)${NC} 返回"
    echo ""
    ask "请选择 [0-3]: "
    read -r ssl_choice

    case "$ssl_choice" in
        1)
            echo ""
            info "请按以下步骤在宝塔面板中操作:"
            divider
            echo -e "    1. 登录宝塔面板"
            echo -e "    2. 进入 网站 > 你的站点 > SSL"
            echo -e "    3. 选择 Let's Encrypt"
            echo -e "    4. 勾选域名，点击申请"
            echo -e "    5. 开启 强制 HTTPS"
            divider
            info "申请完成后，Nginx 会自动配置 SSL"
            ;;
        2)
            ssl_manual_config
            ;;
        3)
            ssl_self_signed
            ;;
        0) show_main_menu; return ;;
    esac

    press_enter; show_main_menu
}

ssl_manual_config() {
    select_site_dir || return

    ask "请输入域名: "
    read -r DOMAIN
    ask "证书文件路径 (.pem/.crt): "
    read -r cert_path
    ask "私钥文件路径 (.key): "
    read -r key_path

    if [[ ! -f "$cert_path" ]] || [[ ! -f "$key_path" ]]; then
        error "证书或私钥文件不存在"; return
    fi

    local nginx_conf="${NGINX_VHOST_DIR}/${DOMAIN}.conf"
    if [[ ! -f "$nginx_conf" ]]; then
        error "未找到 Nginx 配置: ${nginx_conf}"
        warn "请先执行 配置域名 & Nginx"
        return
    fi

    # 在 server 块中添加 SSL 配置
    local ssl_conf="${NGINX_VHOST_DIR}/${DOMAIN}_ssl.conf"

    local php_ver_short
    php_ver_short=$(echo "$PHP_BIN" | grep -oP '\d{2}' | head -1)
    local php_socket="/tmp/php-cgi-${php_ver_short}.sock"

    cat > "$nginx_conf" << SSL_EOF
server {
    listen 80;
    server_name ${DOMAIN};
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl http2;
    server_name ${DOMAIN};
    index index.php index.html;
    root ${SITE_DIR}/public;

    # SSL
    ssl_certificate ${cert_path};
    ssl_certificate_key ${key_path};
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-RSA-AES128-GCM-SHA256:HIGH:!aNULL:!MD5:!RC4:!DHE;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;

    # 日志
    access_log /www/wwwlogs/${DOMAIN}.log;
    error_log  /www/wwwlogs/${DOMAIN}.error.log;

    # 安全头
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    location ~ /\\.(?!well-known) { deny all; }
    location ~ ^/runtime/ { deny all; }

    location ~ \\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        access_log off;
    }

    location / {
        if (!-e \$request_filename) {
            rewrite ^(.*)$ /index.php\$1 last;
        }
    }

    location ~ \\.php(.*)$ {
        fastcgi_pass unix:${php_socket};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param PATH_INFO \$1;
        include fastcgi_params;
    }
}
SSL_EOF

    # 测试并重载
    local nginx_test
    nginx_test=$(/www/server/nginx/sbin/nginx -t 2>&1)
    if echo "$nginx_test" | grep -q "successful"; then
        /www/server/nginx/sbin/nginx -s reload
        info "SSL 配置完成，已启用 HTTPS"
    else
        error "Nginx 配置有误:"
        echo "$nginx_test" | sed 's/^/    /'
    fi
}

ssl_self_signed() {
    select_site_dir || return

    ask "请输入域名: "
    read -r DOMAIN

    local ssl_dir="/www/server/panel/vhost/cert/${DOMAIN}"
    mkdir -p "$ssl_dir"

    info "正在生成自签名证书..."
    openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -keyout "${ssl_dir}/privkey.pem" \
        -out "${ssl_dir}/fullchain.pem" \
        -subj "/CN=${DOMAIN}" >> "$INSTALL_LOG" 2>&1

    if [[ $? -eq 0 ]]; then
        info "自签名证书已生成"
        info "证书: ${ssl_dir}/fullchain.pem"
        info "私钥: ${ssl_dir}/privkey.pem"
        warn "自签名证书仅用于测试，浏览器会提示不安全"

        if confirm "是否立即应用到 Nginx？"; then
            ssl_manual_config_with_paths "${ssl_dir}/fullchain.pem" "${ssl_dir}/privkey.pem"
        fi
    else
        error "证书生成失败"
    fi
}

ssl_manual_config_with_paths() {
    local cert_path="$1"
    local key_path="$2"
    # 复用手动配置逻辑，但跳过路径输入
    local nginx_conf="${NGINX_VHOST_DIR}/${DOMAIN}.conf"
    if [[ -f "$nginx_conf" ]]; then
        # 简单追加 SSL 指令到现有配置
        sed -i "s/listen 80;/listen 80;\n    listen 443 ssl http2;\n    ssl_certificate ${cert_path//\//\\/};\n    ssl_certificate_key ${key_path//\//\\/};/" "$nginx_conf"
        /www/server/nginx/sbin/nginx -s reload 2>/dev/null
        info "SSL 已应用"
    fi
}

# __PLACEHOLDER_PART8__

# ==================== 11. 系统状态检查 ====================
do_status_check() {
    step "系统状态检查"
    select_site_dir || return

    echo ""
    divider
    echo -e "  ${BOLD}环境信息${NC}"
    divider

    # 系统信息
    echo -e "    操作系统:   $(cat /etc/os-release 2>/dev/null | grep PRETTY_NAME | cut -d'"' -f2)"
    echo -e "    内核版本:   $(uname -r)"
    echo -e "    内存使用:   $(free -h 2>/dev/null | awk '/Mem:/{print $3"/"$2}')"
    echo -e "    磁盘使用:   $(df -h / 2>/dev/null | awk 'NR==2{print $3"/"$2" ("$5")"}')"

    echo ""
    divider
    echo -e "  ${BOLD}服务状态${NC}"
    divider

    # Nginx
    if pgrep -x nginx &>/dev/null; then
        echo -e "    Nginx:      ${GREEN}运行中${NC}"
    else
        echo -e "    Nginx:      ${RED}未运行${NC}"
    fi

    # PHP-FPM
    if pgrep -f php-fpm &>/dev/null; then
        local php_ver
        php_ver=$($PHP_BIN -r 'echo PHP_VERSION;' 2>/dev/null)
        echo -e "    PHP-FPM:    ${GREEN}运行中${NC} (PHP ${php_ver})"
    else
        echo -e "    PHP-FPM:    ${RED}未运行${NC}"
    fi

    echo ""
    divider
    echo -e "  ${BOLD}项目状态${NC}"
    divider

    # 项目目录
    echo -e "    项目路径:   ${SITE_DIR}"

    # .env
    if [[ -f "${SITE_DIR}/.env" ]]; then
        local db_drv
        db_drv=$(grep -E "^DB_DRIVER=" "${SITE_DIR}/.env" | cut -d= -f2)
        local base_url
        base_url=$(grep -E "^CB_BASE_URL=" "${SITE_DIR}/.env" | cut -d= -f2)
        local debug
        debug=$(grep -E "^APP_DEBUG=" "${SITE_DIR}/.env" | cut -d= -f2)
        echo -e "    .env:       ${GREEN}已配置${NC}"
        echo -e "    数据库:     ${db_drv}"
        echo -e "    站点URL:    ${base_url}"
        echo -e "    调试模式:   ${debug}"
    else
        echo -e "    .env:       ${RED}未配置${NC}"
    fi

    # vendor
    if [[ -f "${SITE_DIR}/vendor/autoload.php" ]]; then
        echo -e "    Composer:   ${GREEN}依赖已安装${NC}"
    else
        echo -e "    Composer:   ${RED}依赖未安装${NC}"
    fi

    # 数据库
    local db_driver="sqlite"
    if [[ -f "${SITE_DIR}/.env" ]]; then
        db_driver=$(grep -E "^DB_DRIVER=" "${SITE_DIR}/.env" | cut -d= -f2 | tr -d '[:space:]')
    fi

    if [[ "$db_driver" == "sqlite" ]]; then
        local db_file="${SITE_DIR}/runtime/cboard.sqlite"
        if [[ -f "$db_file" ]]; then
            local tbl_count
            tbl_count=$(sqlite3 "$db_file" "SELECT count(*) FROM sqlite_master WHERE type='table';" 2>/dev/null || echo "0")
            local user_count
            user_count=$(sqlite3 "$db_file" "SELECT count(*) FROM users;" 2>/dev/null || echo "0")
            local admin_count
            admin_count=$(sqlite3 "$db_file" "SELECT count(*) FROM users WHERE role=1;" 2>/dev/null || echo "0")
            local db_size
            db_size=$(du -h "$db_file" 2>/dev/null | cut -f1)
            echo -e "    SQLite:     ${GREEN}正常${NC} (${tbl_count}表, ${db_size})"
            echo -e "    用户数:     ${user_count} (管理员: ${admin_count})"
        else
            echo -e "    SQLite:     ${RED}数据库文件不存在${NC}"
        fi
    fi

    # 目录权限
    echo ""
    divider
    echo -e "  ${BOLD}目录权限${NC}"
    divider
    local runtime_owner
    runtime_owner=$(stat -c '%U:%G' "${SITE_DIR}/runtime" 2>/dev/null || stat -f '%Su:%Sg' "${SITE_DIR}/runtime" 2>/dev/null)
    local runtime_perm
    runtime_perm=$(stat -c '%a' "${SITE_DIR}/runtime" 2>/dev/null || stat -f '%Lp' "${SITE_DIR}/runtime" 2>/dev/null)
    echo -e "    runtime/:   ${runtime_owner} (${runtime_perm})"

    if [[ -f "${SITE_DIR}/.env" ]]; then
        local env_perm
        env_perm=$(stat -c '%a' "${SITE_DIR}/.env" 2>/dev/null || stat -f '%Lp' "${SITE_DIR}/.env" 2>/dev/null)
        if [[ "$env_perm" == "600" ]]; then
            echo -e "    .env:       ${GREEN}${env_perm} (安全)${NC}"
        else
            echo -e "    .env:       ${YELLOW}${env_perm} (建议设为 600)${NC}"
        fi
    fi

    # Nginx 配置
    echo ""
    divider
    echo -e "  ${BOLD}Nginx 配置${NC}"
    divider
    local domain_name
    domain_name=$(basename "$SITE_DIR")
    local nginx_conf="${NGINX_VHOST_DIR}/${domain_name}.conf"
    if [[ -f "$nginx_conf" ]]; then
        echo -e "    配置文件:   ${GREEN}${nginx_conf}${NC}"
        if grep -q "ssl_certificate" "$nginx_conf" 2>/dev/null; then
            echo -e "    SSL:        ${GREEN}已配置${NC}"
        else
            echo -e "    SSL:        ${YELLOW}未配置${NC}"
        fi
    else
        echo -e "    配置文件:   ${RED}未找到${NC}"
    fi

    # Git 信息
    if [[ -d "${SITE_DIR}/.git" ]]; then
        echo ""
        divider
        echo -e "  ${BOLD}Git 信息${NC}"
        divider
        cd "$SITE_DIR"
        echo -e "    分支:       $(git branch --show-current 2>/dev/null)"
        echo -e "    最新提交:   $(git log --oneline -1 2>/dev/null)"
        local behind
        behind=$(git rev-list HEAD..origin/$(git branch --show-current) --count 2>/dev/null || echo "?")
        if [[ "$behind" != "?" ]] && [[ "$behind" -gt 0 ]]; then
            echo -e "    远程更新:   ${YELLOW}落后 ${behind} 个提交${NC}"
        fi
    fi

    divider
    press_enter; show_main_menu
}

# __PLACEHOLDER_PART9__

# ==================== 12. 完整一键安装 ====================
do_full_install() {
    print_banner
    step "CBoard 完整一键安装"
    echo ""
    info "本向导将依次完成以下步骤:"
    echo -e "    1. 环境检测 (宝塔/PHP/Composer/Git)"
    echo -e "    2. 获取代码 (GitHub 克隆或使用已有代码)"
    echo -e "    3. 安装 Composer 依赖"
    echo -e "    4. 配置 .env 环境变量"
    echo -e "    5. 初始化数据库"
    echo -e "    6. 创建管理员账号"
    echo -e "    7. 配置域名 & Nginx"
    echo -e "    8. 设置目录权限"
    echo -e "    9. SSL 证书 (可选)"
    echo -e "   10. 重启服务"
    echo ""

    if ! confirm "开始安装？"; then
        show_main_menu; return
    fi

    # ---- Step 1: 环境检测 ----
    echo ""
    echo -e "  ${CYAN}━━━ 步骤 1/10: 环境检测 ━━━${NC}"
    check_bt_panel
    detect_php
    detect_composer
    check_git
    info "环境检测通过"

    # ---- Step 2: 获取代码 ----
    echo ""
    echo -e "  ${CYAN}━━━ 步骤 2/10: 获取代码 ━━━${NC}"
    echo ""
    echo -e "    ${GREEN}1)${NC} 从 GitHub 克隆代码"
    echo -e "    ${GREEN}2)${NC} 代码已在服务器上"
    ask "请选择 [1-2]: "
    read -r code_choice

    if [[ "$code_choice" == "1" ]]; then
        ask "GitHub 仓库地址: "
        read -r GITHUB_REPO
        ask "域名 (如 panel.example.com): "
        read -r DOMAIN
        SITE_DIR="${BT_WWW}/${DOMAIN}"

        if [[ -d "$SITE_DIR" ]] && [[ -f "${SITE_DIR}/think" ]]; then
            warn "目录已存在且包含 CBoard 代码"
            if confirm "使用已有代码？"; then
                info "使用已有代码: ${SITE_DIR}"
            else
                if confirm "清空并重新克隆？" "n"; then
                    rm -rf "$SITE_DIR"
                    git clone "$GITHUB_REPO" "$SITE_DIR" 2>&1 | tee -a "$INSTALL_LOG"
                else
                    show_main_menu; return
                fi
            fi
        else
            mkdir -p "$SITE_DIR" 2>/dev/null
            rm -rf "$SITE_DIR"
            info "正在克隆代码..."
            git clone "$GITHUB_REPO" "$SITE_DIR" 2>&1 | tee -a "$INSTALL_LOG"
        fi

        if [[ ! -f "${SITE_DIR}/think" ]]; then
            error "代码获取失败"; press_enter; show_main_menu; return
        fi
        info "代码就绪"
    else
        echo ""
        info "宝塔网站目录中的 CBoard 项目:"
        local i=1
        local dirs=()
        for d in "$BT_WWW"/*/; do
            if [[ -f "${d}think" ]]; then
                dirs+=("$d")
                echo -e "    ${GREEN}${i})${NC} $(basename "$d")"
                ((i++))
            fi
        done

        if [[ ${#dirs[@]} -eq 0 ]]; then
            ask "请输入项目完整路径: "
            read -r SITE_DIR
        else
            ask "请选择: "
            read -r dir_choice
            if [[ "$dir_choice" =~ ^[0-9]+$ ]] && [[ "$dir_choice" -le ${#dirs[@]} ]]; then
                SITE_DIR="${dirs[$((dir_choice-1))]%/}"
            else
                SITE_DIR="$dir_choice"
            fi
        fi

        DOMAIN=$(basename "$SITE_DIR")

        if [[ ! -f "${SITE_DIR}/think" ]]; then
            error "无效的 CBoard 项目目录"; press_enter; show_main_menu; return
        fi
        info "项目目录: ${SITE_DIR}"
    fi

    # ---- Step 3: Composer 依赖 ----
    echo ""
    echo -e "  ${CYAN}━━━ 步骤 3/10: 安装依赖 ━━━${NC}"
    do_install_deps

    # ---- Step 4: 配置 .env ----
    echo ""
    echo -e "  ${CYAN}━━━ 步骤 4/10: 配置环境变量 ━━━${NC}"
    full_install_env

    # ---- Step 5: 初始化数据库 ----
    echo ""
    echo -e "  ${CYAN}━━━ 步骤 5/10: 初始化数据库 ━━━${NC}"
    full_install_db

    # ---- Step 6: 创建管理员 ----
    echo ""
    echo -e "  ${CYAN}━━━ 步骤 6/10: 创建管理员账号 ━━━${NC}"
    full_install_admin

    # ---- Step 7: Nginx 配置 ----
    echo ""
    echo -e "  ${CYAN}━━━ 步骤 7/10: 配置 Nginx ━━━${NC}"
    full_install_nginx

    # ---- Step 8: 目录权限 ----
    echo ""
    echo -e "  ${CYAN}━━━ 步骤 8/10: 设置权限 ━━━${NC}"
    chown -R www:www "$SITE_DIR"
    find "$SITE_DIR" -type d -exec chmod 755 {} \;
    find "$SITE_DIR" -type f -exec chmod 644 {} \;
    chmod -R 775 "${SITE_DIR}/runtime"
    [[ -f "${SITE_DIR}/runtime/cboard.sqlite" ]] && chmod 664 "${SITE_DIR}/runtime/cboard.sqlite"
    [[ -f "${SITE_DIR}/think" ]] && chmod 755 "${SITE_DIR}/think"
    [[ -f "${SITE_DIR}/.env" ]] && chmod 600 "${SITE_DIR}/.env" && chown www:www "${SITE_DIR}/.env"
    info "权限设置完成"

    # __PLACEHOLDER_PART10__

    # ---- Step 9: SSL (可选) ----
    echo ""
    echo -e "  ${CYAN}━━━ 步骤 9/10: SSL 证书 ━━━${NC}"
    if confirm "是否现在配置 SSL？(可稍后配置)"; then
        do_ssl_config
    else
        info "跳过 SSL 配置，可稍后通过菜单配置"
    fi

    # ---- Step 10: 重启服务 ----
    echo ""
    echo -e "  ${CYAN}━━━ 步骤 10/10: 重启服务 ━━━${NC}"
    restart_php_fpm
    /www/server/nginx/sbin/nginx -s reload 2>/dev/null
    info "服务已重启"

    # ---- 安装完成 ----
    echo ""
    echo ""
    echo -e "  ${GREEN}╔══════════════════════════════════════════════════╗${NC}"
    echo -e "  ${GREEN}║                                                  ║${NC}"
    echo -e "  ${GREEN}║          CBoard 安装完成！                       ║${NC}"
    echo -e "  ${GREEN}║                                                  ║${NC}"
    echo -e "  ${GREEN}╚══════════════════════════════════════════════════╝${NC}"
    echo ""
    divider
    echo -e "    项目目录:   ${SITE_DIR}"
    echo -e "    站点域名:   ${DOMAIN}"

    local base_url
    base_url=$(grep -E "^CB_BASE_URL=" "${SITE_DIR}/.env" 2>/dev/null | cut -d= -f2)
    echo -e "    访问地址:   ${CYAN}${base_url:-https://${DOMAIN}}${NC}"
    echo -e "    后台地址:   ${CYAN}${base_url:-https://${DOMAIN}}/admin${NC}"
    divider
    echo ""
    echo -e "    安装日志:   ${INSTALL_LOG}"
    echo ""
    warn "请务必修改默认管理员密码！"
    echo ""

    press_enter; show_main_menu
}

# ==================== 一键安装子函数 ====================
full_install_env() {
    local env_file="${SITE_DIR}/.env"

    if [[ -f "$env_file" ]]; then
        info "已存在 .env 文件"
        cat "$env_file" | sed 's/^/    /'
        if ! confirm "是否重新配置？"; then
            return
        fi
    fi

    echo ""
    echo -e "  ${BOLD}数据库类型:${NC}"
    echo -e "    ${GREEN}1)${NC} SQLite (推荐)"
    echo -e "    ${GREEN}2)${NC} MySQL"
    ask "请选择 [1-2]: "
    read -r db_choice

    local db_driver="sqlite"
    local db_host="" db_port="" db_name="" db_user="" db_pass=""

    if [[ "$db_choice" == "2" ]]; then
        db_driver="mysql"
        ask "MySQL 主机 [127.0.0.1]: "
        read -r db_host; db_host="${db_host:-127.0.0.1}"
        ask "MySQL 端口 [3306]: "
        read -r db_port; db_port="${db_port:-3306}"
        ask "数据库名 [cboard]: "
        read -r db_name; db_name="${db_name:-cboard}"
        ask "用户名 [root]: "
        read -r db_user; db_user="${db_user:-root}"
        ask "密码: "
        read -rs db_pass; echo ""
    fi

    ask "站点名称 [CBoard 代理服务平台]: "
    read -r app_name
    app_name="${app_name:-CBoard 代理服务平台}"

    ask "站点 URL [https://${DOMAIN}]: "
    read -r base_url
    base_url="${base_url:-https://${DOMAIN}}"

    {
        echo "APP_DEBUG=false"
        echo "APP_TRACE=false"
        echo ""
        echo "DB_DRIVER=${db_driver}"
        if [[ "$db_driver" == "mysql" ]]; then
            echo "DB_HOST=${db_host}"
            echo "DB_PORT=${db_port}"
            echo "DB_NAME=${db_name}"
            echo "DB_USER=${db_user}"
            echo "DB_PASS=${db_pass}"
            echo "DB_PREFIX="
        fi
        echo ""
        echo "CB_APP_NAME=${app_name}"
        echo "CB_BASE_URL=${base_url}"
    } > "$env_file"

    info ".env 配置完成"
}

full_install_db() {
    local sql_file="${SITE_DIR}/database/install.sql"
    local db_file="${SITE_DIR}/runtime/cboard.sqlite"

    local db_driver="sqlite"
    if [[ -f "${SITE_DIR}/.env" ]]; then
        db_driver=$(grep -E "^DB_DRIVER=" "${SITE_DIR}/.env" | cut -d= -f2 | tr -d '[:space:]')
    fi

    if [[ "$db_driver" == "sqlite" ]]; then
        if [[ -f "$db_file" ]]; then
            local tc
            tc=$(sqlite3 "$db_file" "SELECT count(*) FROM sqlite_master WHERE type='table';" 2>/dev/null || echo "0")
            if [[ "$tc" -gt 0 ]]; then
                warn "数据库已存在 (${tc} 张表)"
                if ! confirm "重新初始化？(会备份旧数据)" "n"; then
                    info "保留现有数据库"; return
                fi
                cp "$db_file" "${db_file}.bak.$(date +%Y%m%d_%H%M%S)"
                info "旧数据库已备份"
                rm -f "$db_file"
            fi
        fi

        mkdir -p "$(dirname "$db_file")"
        if sqlite3 "$db_file" < "$sql_file" >> "$INSTALL_LOG" 2>&1; then
            local tc
            tc=$(sqlite3 "$db_file" "SELECT count(*) FROM sqlite_master WHERE type='table';" 2>/dev/null)
            info "数据库初始化成功 (${tc} 张表)"
        else
            error "数据库初始化失败"
        fi
    else
        init_mysql_db
    fi
}

# __PLACEHOLDER_PART11__

full_install_admin() {
    echo ""
    echo -e "  ${BOLD}管理员账号设置:${NC}"
    echo -e "    ${GREEN}1)${NC} 使用默认账号 (admin@cboard.local / admin123)"
    echo -e "    ${GREEN}2)${NC} 自定义管理员账号"
    ask "请选择 [1-2]: "
    read -r admin_choice

    if [[ "$admin_choice" == "2" ]]; then
        ask "管理员邮箱: "
        read -r admin_email
        ask "管理员昵称 [Admin]: "
        read -r admin_nick
        admin_nick="${admin_nick:-Admin}"

        while true; do
            ask "管理员密码 (至少8位): "
            read -rs admin_pass; echo ""
            if [[ ${#admin_pass} -lt 8 ]]; then
                warn "密码至少8位"; continue
            fi
            ask "确认密码: "
            read -rs admin_pass2; echo ""
            if [[ "$admin_pass" != "$admin_pass2" ]]; then
                warn "两次密码不一致"; continue
            fi
            break
        done

        local db_driver="sqlite"
        if [[ -f "${SITE_DIR}/.env" ]]; then
            db_driver=$(grep -E "^DB_DRIVER=" "${SITE_DIR}/.env" | cut -d= -f2 | tr -d '[:space:]')
        fi

        if [[ "$db_driver" == "sqlite" ]]; then
            local db_file="${SITE_DIR}/runtime/cboard.sqlite"
            local hash
            hash=$($PHP_BIN -r "echo password_hash('${admin_pass}', PASSWORD_BCRYPT);")
            local invite_code
            invite_code=$(head -c 6 /dev/urandom | xxd -p | head -c 8 | tr '[:lower:]' '[:upper:]')

            # 删除默认管理员，插入新管理员
            sqlite3 "$db_file" "DELETE FROM users WHERE email='admin@cboard.local' AND role=1;"
            sqlite3 "$db_file" "INSERT INTO users (email, password_hash, nickname, role, status, balance, invite_code) VALUES ('${admin_email}', '${hash}', '${admin_nick}', 1, 1, 0.00, '${invite_code}');"

            info "管理员账号创建成功"
            info "邮箱: ${admin_email}"
        fi
    else
        info "使用默认管理员: admin@cboard.local / admin123"
        warn "请登录后立即修改密码！"
    fi
}

full_install_nginx() {
    ask "站点域名 [${DOMAIN}]: "
    read -r input_domain
    DOMAIN="${input_domain:-$DOMAIN}"

    local nginx_conf="${NGINX_VHOST_DIR}/${DOMAIN}.conf"

    local php_ver_short
    php_ver_short=$(echo "$PHP_BIN" | grep -oP '\d{2}' | head -1)
    local php_socket="/tmp/php-cgi-${php_ver_short}.sock"
    if [[ ! -S "$php_socket" ]]; then
        php_socket="/www/server/php/${php_ver_short}/var/run/php-fpm.sock"
        [[ ! -S "$php_socket" ]] && php_socket="127.0.0.1:9000"
    fi

    cat > "$nginx_conf" << NGINX_EOF
server {
    listen 80;
    server_name ${DOMAIN};
    index index.php index.html;
    root ${SITE_DIR}/public;

    access_log /www/wwwlogs/${DOMAIN}.log;
    error_log  /www/wwwlogs/${DOMAIN}.error.log;

    location ~ /\\.(?!well-known) { deny all; }
    location ~ ^/runtime/ { deny all; }

    location ~ \\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        access_log off;
        add_header Cache-Control "public, immutable";
    }

    location / {
        if (!-e \$request_filename) {
            rewrite ^(.*)$ /index.php\$1 last;
        }
    }

    location ~ \\.php(.*)$ {
        fastcgi_pass unix:${php_socket};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param PATH_INFO \$1;
        include fastcgi_params;
        fastcgi_param PHP_VALUE "
            open_basedir=${SITE_DIR}/:/tmp/:/proc/
            upload_max_filesize=50M
            post_max_size=50M
            max_execution_time=300
        ";
    }
}
NGINX_EOF

    local nginx_test
    nginx_test=$(/www/server/nginx/sbin/nginx -t 2>&1)
    if echo "$nginx_test" | grep -q "successful"; then
        info "Nginx 配置完成"
    else
        error "Nginx 配置有误，请手动检查: ${nginx_conf}"
        echo "$nginx_test" | sed 's/^/    /'
    fi
}

# ==================== 启动入口 ====================
main() {
    check_root
    check_bt_panel
    detect_php
    detect_composer
    check_git
    show_main_menu
}

main
