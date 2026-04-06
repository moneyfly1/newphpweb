# ⚡ 快速启动指南 (仅需 3 步)

## macOS / Linux 用户

```bash
# 第1步: 进入项目目录
cd /Users/apple/Desktop/未命名文件夹

# 第2步: 运行启动脚本
bash start.sh

# 第3步: 打开浏览器
# http://localhost:6000
```

**登录凭证:**
- 邮箱：`admin@example.com`
- 密码：`admin123`

---

## Windows 用户

```cmd
REM 第1步: 打开命令提示符，进入项目目录
cd C:\Users\YourName\Desktop\未命名文件夹

REM 第2步: 运行启动脚本
start.bat

REM 第3步: 打开浏览器
REM http://localhost:8000
```

**登录凭证:**
- 邮箱：`admin@example.com`
- 密码：`admin123`

---

## 📍 关键页面 URL

访问服务器运行后 (`http://localhost:8000`)：

| 页面 | URL | 说明 |
|------|-----|------|
| 首页 | `http://localhost:6000/` | 应用首页 |
| 登录 | `http://localhost:6000/login` | 管理员登录 |
| 仪表板 | `http://localhost:6000/admin/dashboard` | 管理员仪表板 |
| 👥 **用户管理** | `http://localhost:6000/admin/users` | ✨ 新增功能 |
| 📦 **订阅管理** | `http://localhost:6000/admin/subscriptions` | ✨ 新增功能 |

---

## ✨ 新增功能体验

### 用户管理 (`/admin/users`)

✅ **6个统计卡片**
- 总用户数
- 活跃用户
- 今日新增
- 总余额
- 总充值
- 待验证邮箱

✅ **7个过滤条件**
- 邮箱搜索
- 昵称搜索
- 用户ID搜索
- 用户角色筛选
- 账户状态筛选
- 邮箱验证状态筛选
- 日期范围筛选

✅ **核心功能**
- 📝 添加用户备注（输入后自动保存）
- 🔘 状态切换（启用/禁用）
- 👫 用户详情查看
- 📊 批量操作
- 📥 导出为 CSV

### 订阅管理 (`/admin/subscriptions`)

✅ **6个统计卡片**
- 总订阅数
- 活跃订阅
- 过期订阅
- 总设备数
- 平均使用率
- 本月新增

✅ **核心功能**
- ⏳ **在线编辑到期时间** - 直接点击日期修改
- 🎯 **快速延期** - 快速按钮：+30天、+6月、+1年、+2年
- 🔗 **复制订阅链接** - 一键复制
- 🖥️ **修改设备限制** - 在线编辑
- 🔄 **重置链接** - 生成新分享链接
- ❌ **清除设备** - 清空已连接设备
- 📊 **批量操作**
- 📥 **导出为 CSV**

---

## 🆘 故障排除

**Q: 端口 8000 被占用**

```bash
# macOS/Linux: 使用其他端口
bash start.sh 8001

# Windows
start.bat 8001
```

**Q: 访问 http://localhost:8000 显示 502/503 错误**

```bash
# 检查错误日志
tail -f runtime/log/2026_04/*.log

# 或重新初始化数据库
rm runtime/cboard.sqlite
bash start.sh
```

**Q: 数据库连接错误**

```bash
# macOS/Linux: 检查SQLite权限
chmod 666 runtime/cboard.sqlite

# 验证数据库
sqlite3 runtime/cboard.sqlite ".tables"
```

**Q: 静态资源无法加载（CSS/JS 404）**

```bash
# 确保文件存在
ls public/static/js/admin-ui.js

# 检查权限
chmod -R 755 public/
```

---

## 📚 更多信息

- **完整指南**: 查看 [LOCAL_RUN_GUIDE.md](LOCAL_RUN_GUIDE.md)
- **功能文档**: 查看 [ADMIN_FEATURES_UPGRADE.md](ADMIN_FEATURES_UPGRADE.md)
- **快速参考**: 查看 [ADMIN_QUICK_REFERENCE.md](ADMIN_QUICK_REFERENCE.md)
- **文件索引**: 查看 [FILE_INDEX.md](FILE_INDEX.md)

---

## 🎯 第一次登录后的步骤

1. 打开 http://localhost:8000
2. 使用 `admin@example.com` / `admin123` 登录
3. 访问 `/admin/users` 查看用户管理功能
4. 访问 `/admin/subscriptions` 查看订阅管理功能
5. 在浏览器按 F12 打开开发者工具，查看 Console 标签确认库已加载

---

## 💡 开发服务器热重启

修改代码后无需手动重启：

```bash
# stop: Ctrl+C
# start: bash start.sh (or start.bat)
```

---

**🎉 开享受改进的管理后台吧！**

需要帮助？遇到任何问题，查看 [LOCAL_RUN_GUIDE.md](LOCAL_RUN_GUIDE.md) 中的详细排查步骤。

