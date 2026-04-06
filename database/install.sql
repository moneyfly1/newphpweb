-- =============================================
-- CBoard 完整数据库 (SQLite)
-- 包含所有表、索引、初始数据
-- =============================================

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- ==================== 用户系统 ====================

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  nickname TEXT,
  role INTEGER NOT NULL DEFAULT 0,
  status INTEGER NOT NULL DEFAULT 1,
  balance REAL NOT NULL DEFAULT 0.00,
  invite_code TEXT,
  referred_by INTEGER,
  last_login_at TEXT,
  last_login_ip TEXT,
  login_count INTEGER NOT NULL DEFAULT 0,
  last_checkin_at TEXT,
  total_consumption REAL NOT NULL DEFAULT 0.00,
  user_level_id INTEGER DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_users_email ON users(email);

CREATE TABLE IF NOT EXISTS user_settings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  item_key TEXT NOT NULL,
  item_value TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_user_settings_pair ON user_settings(user_id, item_key);

CREATE TABLE IF NOT EXISTS user_login_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  ip_address TEXT NOT NULL,
  user_agent TEXT,
  device_type TEXT,
  country TEXT,
  city TEXT,
  status TEXT NOT NULL DEFAULT 'success',
  failed_reason TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_login_user ON user_login_logs(user_id);
CREATE INDEX IF NOT EXISTS idx_login_ip ON user_login_logs(ip_address);

CREATE TABLE IF NOT EXISTS user_notification_preferences (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  email_order INTEGER NOT NULL DEFAULT 1,
  email_subscription INTEGER NOT NULL DEFAULT 1,
  email_ticket INTEGER NOT NULL DEFAULT 1,
  email_announcement INTEGER NOT NULL DEFAULT 1,
  notification_frequency TEXT NOT NULL DEFAULT 'immediate',
  in_app_notifications INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_notif_user ON user_notification_preferences(user_id);

CREATE TABLE IF NOT EXISTS verification_codes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL,
  scene TEXT NOT NULL,
  code TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  expires_at TEXT NOT NULL,
  verified_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_verify_email ON verification_codes(email, scene);

-- ==================== 套餐与订单 ====================

CREATE TABLE IF NOT EXISTS packages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  description TEXT,
  price_monthly REAL NOT NULL DEFAULT 0.00,
  price_quarterly REAL NOT NULL DEFAULT 0.00,
  price_yearly REAL NOT NULL DEFAULT 0.00,
  device_limit INTEGER NOT NULL DEFAULT 1,
  traffic_limit_gb INTEGER NOT NULL DEFAULT 0,
  speed_limit_mbps INTEGER NOT NULL DEFAULT 0,
  features_json TEXT,
  is_active INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  no TEXT NOT NULL,
  user_id INTEGER NOT NULL,
  package_id INTEGER,
  subscription_id INTEGER,
  type TEXT NOT NULL,
  upgrade_type TEXT,
  status TEXT NOT NULL DEFAULT 'pending',
  device_count INTEGER NOT NULL DEFAULT 0,
  month_count INTEGER NOT NULL DEFAULT 0,
  amount_original REAL NOT NULL DEFAULT 0.00,
  discount_amount REAL NOT NULL DEFAULT 0.00,
  amount_payable REAL NOT NULL DEFAULT 0.00,
  coupon_code TEXT,
  payment_method TEXT,
  payment_no TEXT,
  meta_json TEXT,
  callback_status TEXT DEFAULT 'pending',
  callback_attempts INTEGER NOT NULL DEFAULT 0,
  callback_error TEXT,
  pay_deadline_at TEXT,
  paid_at TEXT,
  cancelled_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_orders_no ON orders(no);
CREATE INDEX IF NOT EXISTS idx_orders_user_status ON orders(user_id, status);

-- ==================== 支付系统 ====================

CREATE TABLE IF NOT EXISTS payments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  channel TEXT NOT NULL,
  trade_no TEXT NOT NULL,
  channel_trade_no TEXT,
  amount REAL NOT NULL DEFAULT 0.00,
  status TEXT NOT NULL DEFAULT 'pending',
  qrcode_url TEXT,
  callback_payload TEXT,
  paid_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_payments_trade_no ON payments(trade_no);
CREATE INDEX IF NOT EXISTS idx_payments_order ON payments(order_id);

CREATE TABLE IF NOT EXISTS payment_methods (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL,
  label TEXT NOT NULL,
  hint TEXT,
  is_enabled INTEGER NOT NULL DEFAULT 0,
  need_config INTEGER NOT NULL DEFAULT 0,
  config_json TEXT,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_payment_methods_code ON payment_methods(code);

CREATE TABLE IF NOT EXISTS balance_recharges (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  order_id INTEGER,
  amount REAL NOT NULL,
  payment_method TEXT NOT NULL,
  trade_no TEXT NOT NULL,
  channel_trade_no TEXT,
  status TEXT NOT NULL DEFAULT 'pending',
  callback_payload TEXT,
  paid_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_recharges_trade ON balance_recharges(trade_no);
CREATE INDEX IF NOT EXISTS idx_recharges_user ON balance_recharges(user_id);

CREATE TABLE IF NOT EXISTS alipay_callbacks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  trade_no TEXT NOT NULL,
  callback_payload TEXT NOT NULL,
  verify_result TEXT NOT NULL,
  error_message TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS coupons (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL,
  name TEXT,
  discount_type TEXT NOT NULL,
  discount_value REAL NOT NULL DEFAULT 0.00,
  max_discount_amount REAL,
  min_order_amount REAL NOT NULL DEFAULT 0.00,
  total_limit INTEGER NOT NULL DEFAULT 0,
  used_count INTEGER NOT NULL DEFAULT 0,
  user_limit INTEGER NOT NULL DEFAULT 1,
  status INTEGER NOT NULL DEFAULT 1,
  start_at TEXT,
  end_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_coupons_code ON coupons(code);

CREATE TABLE IF NOT EXISTS invite_records (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  inviter_user_id INTEGER NOT NULL,
  invited_user_id INTEGER NOT NULL,
  order_id INTEGER,
  reward_amount REAL NOT NULL DEFAULT 0.00,
  status TEXT NOT NULL DEFAULT 'pending',
  settled_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_invite_inviter ON invite_records(inviter_user_id);

-- ==================== 订阅系统 ====================

CREATE TABLE IF NOT EXISTS subscriptions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  package_id INTEGER NOT NULL,
  source_order_id INTEGER,
  status TEXT NOT NULL DEFAULT 'active',
  sub_token TEXT NOT NULL,
  sub_url TEXT NOT NULL,
  preferred_format TEXT DEFAULT 'clash',
  device_limit INTEGER NOT NULL DEFAULT 1,
  used_devices INTEGER NOT NULL DEFAULT 0,
  traffic_total_gb INTEGER NOT NULL DEFAULT 0,
  traffic_used_gb INTEGER NOT NULL DEFAULT 0,
  reset_count INTEGER NOT NULL DEFAULT 0,
  config_json TEXT,
  start_at TEXT,
  expire_at TEXT,
  last_reset_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_sub_token ON subscriptions(sub_token);
CREATE INDEX IF NOT EXISTS idx_sub_user_status ON subscriptions(user_id, status);

CREATE TABLE IF NOT EXISTS subscription_format_configs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  format_code TEXT NOT NULL,
  format_name TEXT NOT NULL,
  description TEXT,
  supported_protocols TEXT,
  features TEXT,
  is_enabled INTEGER NOT NULL DEFAULT 1,
  priority INTEGER NOT NULL DEFAULT 0,
  config_json TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_format_code ON subscription_format_configs(format_code);

CREATE TABLE IF NOT EXISTS subscription_access_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  subscription_id INTEGER NOT NULL,
  format TEXT NOT NULL,
  client_ip TEXT,
  user_agent TEXT,
  response_bytes INTEGER DEFAULT 0,
  response_time_ms INTEGER DEFAULT 0,
  status_code INTEGER NOT NULL DEFAULT 200,
  accessed_at TEXT NOT NULL DEFAULT (datetime('now')),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_sub_access ON subscription_access_logs(subscription_id);

-- ==================== 设备系统 ====================

CREATE TABLE IF NOT EXISTS devices (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  subscription_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  device_fingerprint TEXT NOT NULL,
  device_hash TEXT,
  device_ua TEXT,
  ip_address TEXT,
  location TEXT,
  software_name TEXT,
  software_version TEXT,
  os_name TEXT,
  os_version TEXT,
  device_model TEXT,
  device_brand TEXT,
  subscription_type TEXT,
  is_active INTEGER NOT NULL DEFAULT 1,
  is_allowed INTEGER NOT NULL DEFAULT 1,
  first_seen TEXT NOT NULL DEFAULT (datetime('now')),
  last_access TEXT NOT NULL DEFAULT (datetime('now')),
  last_seen TEXT NOT NULL DEFAULT (datetime('now')),
  access_count INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_devices_sub ON devices(subscription_id);
CREATE INDEX IF NOT EXISTS idx_devices_user ON devices(user_id);
CREATE INDEX IF NOT EXISTS idx_devices_fingerprint ON devices(device_fingerprint);
CREATE INDEX IF NOT EXISTS idx_devices_active ON devices(is_active);

-- ==================== 节点系统 ====================

CREATE TABLE IF NOT EXISTS proxy_nodes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  subscription_id INTEGER,
  remote_source_url TEXT,
  protocol TEXT NOT NULL,
  name TEXT NOT NULL,
  server TEXT NOT NULL,
  port INTEGER NOT NULL,
  method TEXT,
  password TEXT,
  uuid TEXT,
  alter_id INTEGER,
  security TEXT,
  network TEXT,
  host TEXT,
  path TEXT,
  sni TEXT,
  obfs TEXT,
  obfs_param TEXT,
  protocol_param TEXT,
  flow TEXT,
  tls INTEGER NOT NULL DEFAULT 0,
  alpn TEXT,
  fingerprint TEXT,
  client_fingerprint TEXT,
  reality_public_key TEXT,
  reality_short_id TEXT,
  settings_json TEXT,
  raw_link TEXT,
  parse_status TEXT DEFAULT 'success',
  parse_error TEXT,
  is_active INTEGER NOT NULL DEFAULT 1,
  priority INTEGER NOT NULL DEFAULT 0,
  last_check_at TEXT,
  check_status TEXT,
  latency_ms INTEGER,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_nodes_sub ON proxy_nodes(subscription_id);
CREATE INDEX IF NOT EXISTS idx_nodes_protocol ON proxy_nodes(protocol);
CREATE INDEX IF NOT EXISTS idx_nodes_active ON proxy_nodes(is_active);

CREATE TABLE IF NOT EXISTS node_sources (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  source_url TEXT NOT NULL,
  source_type TEXT NOT NULL DEFAULT 'subscription',
  format TEXT,
  filter_regex TEXT,
  priority INTEGER NOT NULL DEFAULT 0,
  is_enabled INTEGER NOT NULL DEFAULT 1,
  last_fetch_at TEXT,
  last_fetch_node_count INTEGER,
  last_error TEXT,
  fetch_interval_minutes INTEGER NOT NULL DEFAULT 60,
  timeout_seconds INTEGER NOT NULL DEFAULT 30,
  auth_header TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS node_parse_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  source_id INTEGER,
  parsed_node_count INTEGER,
  successful_count INTEGER,
  failed_count INTEGER,
  start_time TEXT,
  end_time TEXT,
  duration_ms INTEGER,
  errors TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ==================== 工单系统 ====================

CREATE TABLE IF NOT EXISTS tickets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  no TEXT NOT NULL,
  user_id INTEGER NOT NULL,
  subject TEXT NOT NULL,
  content TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'open',
  priority TEXT NOT NULL DEFAULT 'normal',
  admin_id INTEGER,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_tickets_no ON tickets(no);
CREATE INDEX IF NOT EXISTS idx_tickets_user_status ON tickets(user_id, status);

CREATE TABLE IF NOT EXISTS ticket_replies (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_id INTEGER NOT NULL,
  replier_type TEXT NOT NULL,
  replier_id INTEGER NOT NULL,
  content TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_replies_ticket ON ticket_replies(ticket_id);

-- ==================== 系统日志与设置 ====================

CREATE TABLE IF NOT EXISTS balance_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  type TEXT NOT NULL,
  amount REAL NOT NULL DEFAULT 0.00,
  balance_before REAL NOT NULL DEFAULT 0.00,
  balance_after REAL NOT NULL DEFAULT 0.00,
  ref_type TEXT,
  ref_id INTEGER,
  remark TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_balance_user ON balance_logs(user_id);

CREATE TABLE IF NOT EXISTS audit_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  actor_user_id INTEGER,
  actor_role TEXT NOT NULL DEFAULT 'system',
  action TEXT NOT NULL,
  target_type TEXT,
  target_id INTEGER,
  ip TEXT,
  user_agent TEXT,
  detail_json TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_audit_actor ON audit_logs(actor_user_id);

CREATE TABLE IF NOT EXISTS system_settings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  group_key TEXT NOT NULL,
  item_key TEXT NOT NULL,
  item_value TEXT,
  autoload INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_settings_group_item ON system_settings(group_key, item_key);

CREATE TABLE IF NOT EXISTS email_queue (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER,
  to_email TEXT NOT NULL,
  subject TEXT NOT NULL,
  body TEXT NOT NULL,
  body_html TEXT,
  type TEXT DEFAULT 'notification',
  status TEXT NOT NULL DEFAULT 'pending',
  attempts INTEGER NOT NULL DEFAULT 0,
  max_attempts INTEGER NOT NULL DEFAULT 3,
  error_message TEXT,
  sent_at TEXT,
  scheduled_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_email_status ON email_queue(status);

CREATE TABLE IF NOT EXISTS webhooks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  url TEXT NOT NULL,
  events TEXT,
  headers TEXT,
  secret TEXT,
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS webhook_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  webhook_id INTEGER NOT NULL,
  event_type TEXT NOT NULL,
  payload TEXT,
  status_code INTEGER,
  response_body TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ==================== 初始数据 ====================

-- 管理员: admin@cboard.local / admin123
-- 用户: user@cboard.local / user123
INSERT OR IGNORE INTO users (id, email, password_hash, nickname, role, status, balance, invite_code) VALUES
  (1, 'admin@cboard.local', '$2y$12$JbA1RYJ5Qi9M2x9KDHj9meVbduq87Pp099EumW4tK3Gachv7LdMre', 'Admin', 1, 1, 0.00, 'ADMIN001'),
  (2, 'user@cboard.local', '$2y$12$BQs5GvfoEkPUrfxKRc/7eeHNMBJts9XC8wLqT0tB9uQ/ozqfeEdYe', 'Luna', 0, 1, 128.40, 'LUNA001');

INSERT OR IGNORE INTO packages (id, name, description, price_monthly, price_quarterly, price_yearly, device_limit, traffic_limit_gb, speed_limit_mbps, features_json, is_active, sort_order) VALUES
  (1, '轻量基础套餐', '适合单人日常使用', 49.00, 138.00, 498.00, 3, 120, 200, '["移动端友好","基础客服","3台设备"]', 1, 1),
  (2, '旗舰多端套餐', '适合多设备切换', 62.00, 176.00, 668.00, 6, 600, 500, '["6台设备","优先线路","工单优先"]', 1, 2),
  (3, '团队协作套餐', '适合小团队共用', 109.00, 309.00, 1188.00, 12, 2048, 800, '["12台设备","团队标签","专属客服"]', 1, 3);

INSERT OR IGNORE INTO subscriptions (id, user_id, package_id, status, sub_token, sub_url, device_limit, used_devices, traffic_total_gb, traffic_used_gb, start_at, expire_at) VALUES
  (1, 2, 2, 'active', 'luna-9f2b8c41', 'https://panel.example.com/sub/luna-9f2b8c41', 6, 0, 600, 138, datetime('now'), datetime('now', '+120 days'));

INSERT OR IGNORE INTO coupons (id, code, name, discount_type, discount_value, min_order_amount, total_limit, used_count, user_limit, status) VALUES
  (1, 'WELCOME10', '首购9折', 'percent', 10, 10, 500, 0, 1, 1),
  (2, 'DEVICE50', '扩容立减50', 'fixed', 50, 100, 100, 0, 1, 1);

INSERT OR IGNORE INTO payment_methods (code, label, hint, is_enabled, need_config, sort_order) VALUES
  ('balance', '余额支付', '实时扣款', 1, 0, 1),
  ('alipay', '支付宝', '扫码支付', 0, 1, 2),
  ('wechat', '微信支付', '扫码支付', 0, 1, 3),
  ('usdt', 'USDT-TRC20', '加密货币', 0, 1, 4),
  ('manual', '人工核销', '离线打款', 0, 1, 5);

INSERT OR IGNORE INTO system_settings (group_key, item_key, item_value, autoload) VALUES
  ('site', 'app_name', 'CBoard', 1),
  ('site', 'base_url', 'https://panel.example.com', 1),
  ('site', 'landing_headline', '低资源部署友好的代理服务平台', 1),
  ('site', 'landing_blurb', 'PHP + SQLite 轻量架构，0.5G VPS 即可运行。', 1),
  ('site', 'login_notice', '轻量 VPS 部署的真实控制台。', 1),
  ('site', 'support_email', 'support@example.com', 1),
  ('business', 'checkin_reward', '1', 1),
  ('business', 'extra_device_price', '8', 1),
  ('business', 'balance_convert_rate', '1', 1),
  ('payment', 'enabled_methods', '["balance","manual"]', 1);

INSERT OR IGNORE INTO subscription_format_configs (format_code, format_name, description, supported_protocols, features, is_enabled, priority) VALUES
  ('clash', 'Clash YAML', 'Clash 配置', '["vmess","vless","trojan","ss","ssr","hysteria","tuic"]', '["规则分组","自动选择"]', 1, 100),
  ('base64', 'Base64', '通用 Base64 格式', '["vmess","vless","trojan","ss","ssr"]', '["通用兼容"]', 1, 90),
  ('v2rayn', 'V2RayN', 'V2RayN 订阅', '["vmess","vless","trojan","ss"]', '["Windows"]', 1, 88),
  ('singbox', 'Sing-Box', 'Sing-Box JSON', '["vmess","vless","trojan","ss","hysteria","tuic"]', '["多协议"]', 1, 85),
  ('hiddify', 'Hiddify', 'Hiddify 配置', '["vmess","vless","trojan","ss","hysteria","tuic"]', '["Hiddify"]', 1, 83),
  ('shadowrocket', 'Shadowrocket', 'Shadowrocket JSON', '["vmess","vless","trojan","ss"]', '["iOS"]', 1, 72),
  ('surge', 'Surge', 'Surge 配置', '["vmess","vless","trojan","ss"]', '["iOS","规则引擎"]', 1, 70),
  ('quantumultx', 'Quantumult X', 'QX 格式', '["vmess","vless","trojan","ss"]', '["QX"]', 1, 75),
  ('quantumult', 'Quantumult', 'Quantumult 格式', '["vmess","trojan","ss"]', '["兼容"]', 1, 60),
  ('loon', 'Loon', 'Loon 格式', '["vmess","vless","trojan","ss"]', '["Loon"]', 1, 65),
  ('ssr', 'SSR', 'SSR 格式', '["ssr"]', '["SSR"]', 1, 50),
  ('unicode', 'Unicode', 'Unicode 转义', '["vmess","vless","trojan","ss","ssr"]', '["防污染"]', 1, 40),
  ('usable', '原始链接', '纯链接列表', '["vmess","vless","trojan","ss","ssr"]', '["通用"]', 1, 30);

INSERT OR IGNORE INTO user_settings (user_id, item_key, item_value) VALUES
  (2, 'telegram', '@lunawire'),
  (2, 'timezone', 'Asia/Shanghai'),
  (2, 'email_notify', 'true');

-- ==================== 用户等级系统 ====================

CREATE TABLE IF NOT EXISTS user_levels (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  level_order INTEGER NOT NULL DEFAULT 0,
  min_consumption REAL NOT NULL DEFAULT 0.00,
  discount_rate REAL NOT NULL DEFAULT 1.00,
  color TEXT DEFAULT '#667eea',
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

INSERT OR IGNORE INTO user_levels (id, name, level_order, min_consumption, discount_rate, color, is_active) VALUES
  (1, '普通用户', 0, 0, 1.0, '#667eea', 1),
  (2, '白银会员', 1, 200, 0.95, '#90a4ae', 1),
  (3, '黄金会员', 2, 500, 0.90, '#ffc107', 1),
  (4, '钻石会员', 3, 1000, 0.85, '#e040fb', 1);