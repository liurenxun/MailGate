# MailGate — Architecture Design (v2)

## 系统定位

监控公司**共用邮箱**（如 info@, sales@, support@），
根据管理员设定的基础规则 + 员工自定义规则，
将匹配的邮件通知给对应员工，员工登录 Web 查看详情。

> MailGate **不拦截**原始邮件，只是读取并转发通知。

```
[外部来件]
    ↓
[公用邮箱 IMAP]  ←── 管理员配置 IMAP 凭证
    ↓
[Cron: Fetcher]  ←── 每 5 分钟拉取
    ↓
[规则引擎]
  ├─ 每个员工是否有自定规则？
  │     有 → 用员工自定规则
  │     无 → 用管理员基础规则
  └─ 命中 → 加入通知队列
    ↓
[Notifier: 发送通知邮件]
    ↓
[员工登录 MailGate 查看邮件原文]
```

---

## 用户角色

| 角色 | 权限 |
|---|---|
| `admin` | 管理监控邮箱、管理员工账号、设定基础规则 |
| `recipient` | 查看被通知的邮件、设定自己的覆盖规则 |

---

## 目录结构

```
MailGate/
├── public/                      # Apache DocumentRoot
│   ├── index.php                # 登录页
│   ├── dashboard.php            # 收件通知列表（员工首页）
│   ├── mail.php                 # 邮件详情
│   ├── my-rules.php             # 员工：自定义规则管理
│   ├── my-settings.php          # 员工：账户设置
│   │
│   ├── admin/
│   │   ├── mailboxes.php        # 管理监控邮箱
│   │   ├── users.php            # 管理员工账号
│   │   ├── base-rules.php       # 基础规则管理
│   │   ├── subscriptions.php    # 分配：哪个员工订阅哪个邮箱
│   │   └── settings.php        # 系统设置（SMTP等）
│   │
│   └── assets/
│       ├── css/
│       └── js/
│
├── src/
│   ├── Auth.php                 # 登录/Session/初次设密码
│   ├── Fetcher.php              # IMAP 拉取邮件
│   ├── Classifier.php           # 规则匹配引擎
│   ├── Notifier.php             # 发送通知邮件
│   ├── Mailer.php               # 邮件发送封装（支持 mail() / SMTP）
│   ├── Database.php             # PDO 封装
│   └── Helpers.php
│
├── cron/
│   └── fetch.php                # Cron 入口
│
├── config/
│   └── config.php               # DB / 加密密钥 / 全局配置（不入 Git）
│
├── config/
│   └── config.example.php       # 配置模板（入 Git）
│
├── sql/
│   └── schema.sql               # DB 初始化脚本
│
├── storage/
│   └── attachments/             # 附件存储（在 public 目录外）
│
└── ARCHITECTURE.md
```

---

## 数据库设计

### `users` — 系统用户

| 列 | 类型 | 说明 |
|---|---|---|
| `id` | INT PK | |
| `name` | VARCHAR(100) | 姓名 |
| `email` | VARCHAR(255) UNIQUE | 登录账号 / 通知邮箱 |
| `password_hash` | VARCHAR(255) NULL | 首次登录前为 NULL |
| `role` | ENUM('admin','recipient') | |
| `status` | ENUM('pending','active','disabled') | pending=未完成初次设密 |
| `setup_token_hash` | VARCHAR(64) NULL | 初次访问令牌的 SHA-256 哈希（原始 token 仅发送给用户，数据库不存明文）|
| `setup_token_expires` | DATETIME NULL | |
| `reset_token_hash` | VARCHAR(64) NULL | 忘记密码令牌的 SHA-256 哈希 |
| `reset_token_expires` | DATETIME NULL | |
| `notify_email` | VARCHAR(255) NULL | 通知送达地址（为空则用 email 字段）|
| `created_at` | DATETIME | |

### `monitored_mailboxes` — 被监控的共用邮箱

| 列 | 类型 | 说明 |
|---|---|---|
| `id` | INT PK | |
| `label` | VARCHAR(100) | 显示名称（如「営業問合せ」）|
| `email_address` | VARCHAR(255) | 邮箱地址 |
| `imap_host` | VARCHAR(255) | |
| `imap_port` | SMALLINT | 默认 993 |
| `imap_encryption` | ENUM('ssl','tls','none') | 默认 ssl |
| `imap_user` | VARCHAR(255) | |
| `imap_pass_enc` | TEXT | AES-256 加密 |
| `is_active` | TINYINT | |
| `last_fetched_at` | DATETIME NULL | |
| `last_fetched_uid` | INT UNSIGNED NULL | 最后一个已处理的 IMAP UID，用于精确增量拉取 |
| `created_at` | DATETIME | |

### `subscriptions` — 员工订阅关系（管理员分配）

| 列 | 类型 | 说明 |
|---|---|---|
| `id` | INT PK | |
| `mailbox_id` | INT FK → monitored_mailboxes | |
| `user_id` | INT FK → users | |
| `created_at` | DATETIME | |
| UNIQUE KEY | (mailbox_id, user_id) | |

### `rules` — 规则

| 列 | 类型 | 说明 |
|---|---|---|
| `id` | INT PK | |
| `mailbox_id` | INT FK → monitored_mailboxes | |
| `scope` | ENUM('global','personal') | global=适用该邮箱所有订阅者；personal=针对特定员工 |
| `user_id` | INT FK → users NULL | scope=global 时为 NULL；personal 时为目标员工 |
| `match_field` | ENUM('from_address','from_domain','subject','any') | |
| `match_pattern` | VARCHAR(255) | 支持 `*` 通配，如 `*@client.co.jp`、`*请款*` |
| `action` | ENUM('notify','ignore') | notify=发通知，ignore=静默 |
| `priority` | INT | 数字小优先，同 scope 内按此排序 |
| `created_by` | INT FK → users | 操作者（管理员或员工本人）|
| `created_at` | DATETIME | |

**规则解析逻辑：**
```
对于某封邮件 M，判断是否通知员工 U（已订阅该邮箱）：

Step 1 — 个人规则
  查找 rules WHERE mailbox_id=M.mailbox_id AND scope='personal' AND user_id=U
  按 priority ASC 依次匹配
  → 命中 → 按该规则的 action 执行，结束

  ⚠️ 语义说明：个人规则是"逐条命中即止"，不是"有任何个人规则就整体替换全局规则"。
  例：员工有个人规则 A（subject=*invoice* → notify），来了一封来自 client.co.jp
  但 subject 不含 invoice 的邮件，规则 A 不命中，流程落入 Step 2 执行全局规则。
  这是"规则级回退"语义，管理员的全局 ignore 规则对员工仍然有效。

Step 2 — 全局规则（Step 1 无命中时才到这里）
  查找 rules WHERE mailbox_id=M.mailbox_id AND scope='global'
  按 priority ASC 依次匹配
  → 命中 → 按该规则的 action 执行，结束

Step 3 — 兜底
  以上均无命中 → 默认 action: notify
```

**编辑权限：**

| 操作 | 管理员 | 员工 |
|---|---|---|
| 全局规则（global） | ✅ 增删改 | ❌ 不可见 |
| 任意员工的个人规则（personal） | ✅ 增删改 | ❌ |
| 自己的个人规则（personal） | ✅ | ✅ 增删改 |

> 管理员可直接为员工设定个人规则（代为操作），员工自己的修改与管理员设定的规则处于同一优先级池，均按 priority 排序，无需区分"谁设的"。

### `mails` — 从监控邮箱拉取的邮件

| 列 | 类型 | 说明 |
|---|---|---|
| `id` | INT PK | |
| `mailbox_id` | INT FK → monitored_mailboxes | 来自哪个监控邮箱 |
| `message_id` | VARCHAR(255) NULL | 原始 Message-ID（部分邮件可能无此头）|
| `from_address` | VARCHAR(255) | |
| `from_name` | VARCHAR(255) | |
| `subject` | VARCHAR(500) | |
| `body_text` | LONGTEXT | |
| `body_html` | LONGTEXT | |
| `received_at` | DATETIME | 邮件原始时间 |
| `fetched_at` | DATETIME | 系统拉取时间 |
| UNIQUE KEY | (mailbox_id, message_id) | message_id 为 NULL 时允许重复，由 imap_uid 兜底去重 |

### `notifications` — 通知记录（员工 × 邮件）

| 列 | 类型 | 说明 |
|---|---|---|
| `id` | INT PK | |
| `mail_id` | INT FK → mails | |
| `user_id` | INT FK → users | 被通知的员工 |
| `is_read` | TINYINT DEFAULT 0 | |
| `read_at` | DATETIME NULL | |
| `notified_at` | DATETIME | 通知记录创建时间 |
| `email_sent_at` | DATETIME NULL | 通知邮件发送时间（NULL=未发或发送失败）|
| `email_retry_count` | TINYINT DEFAULT 0 | 发送重试次数（超过 3 次不再重试）|
| UNIQUE KEY | (mail_id, user_id) | 防止重复通知 |

### `attachments` — 附件

| 列 | 类型 | 说明 |
|---|---|---|
| `id` | INT PK | |
| `mail_id` | INT FK → mails | |
| `filename` | VARCHAR(255) | |
| `mime_type` | VARCHAR(100) | |
| `size` | INT | bytes |
| `storage_path` | VARCHAR(500) | storage/attachments/ 下的相对路径 |

### `system_settings` — 系统配置

| 列 | 类型 | 说明 |
|---|---|---|
| `key` | VARCHAR(100) PK | |
| `value` | TEXT | |

> 包含 SMTP 配置：`smtp_host`, `smtp_port`, `smtp_user`, `smtp_pass_enc`, `smtp_from_address`, `smtp_from_name`, `smtp_encryption`, `use_php_mail`（1=用 mail() / 0=用 SMTP）

---

## 核心流程详解

### A. 管理员添加员工

```
1. 管理员填写员工姓名 + 邮箱
2. 系统生成 setup_token（64位随机，24小时有效）
   - 数据库存储 hash('sha256', $token)，即 setup_token_hash
   - 原始 token 仅出现在邮件链接中，数据库泄露无法直接利用
3. 发送开通通知邮件：
   ────────────────────────────────
   件名: 【MailGate】アカウントが作成されました

   {name} 様、アカウントが作成されました。
   以下のリンクから初回ログインしてパスワードを設定してください。

   ▶ {base_url}/index.php?setup={token}

   このリンクの有効期限は24時間です。
   ────────────────────────────────
4. 员工点链接 → 校验 hash($token) == setup_token_hash 且未过期
   → 设置密码（最少8位，需含字母和数字）→ status 改为 active，setup_token_hash 清空
```

### B. 忘记密码

```
1. 员工在登录页点击「パスワードを忘れた方」，输入邮箱
2. 若邮箱存在且 status=active：
   - 生成 reset_token（64位随机，1小时有效）
   - 数据库存 hash('sha256', $token) 到 reset_token_hash
   - 发送重置邮件（链接含原始 token）
3. 若邮箱不存在：返回相同提示（防止用户枚举）
4. 员工点链接 → 校验 hash($token) == reset_token_hash 且未过期
   → 设置新密码 → reset_token_hash 清空
```

### C. Cron 拉取与通知（每5分钟）

```
cron/fetch.php
  // 并发锁：防止多个 Cron 实例同时运行
  $lock = fopen('/tmp/mailgate_fetch.lock', 'c');
  if (!flock($lock, LOCK_EX | LOCK_NB)) exit;  // 已有实例运行，直接退出

  // Step 1: 补发上次失败的通知邮件（重试机制）
  查询 notifications WHERE email_sent_at IS NULL AND email_retry_count < 3
    AND notified_at > NOW() - INTERVAL 24 HOUR
  for each pending_notification:
    Notifier::send(user, mail)
    成功 → 更新 email_sent_at = NOW()
    失败 → email_retry_count += 1

  // Step 2: 拉取新邮件
  for each active monitored_mailbox:
    Fetcher::connect(imap credentials)

    // 使用 IMAP UID 精确增量拉取（不依赖日期，精度到单封邮件）
    if last_fetched_uid IS NULL:
      拉取最近 N 封（fetch_limit，防止首次拉取积压）
    else:
      UID FETCH (last_fetched_uid+1):* — 只取 UID 大于上次的邮件

    for each mail:
      // 原子去重：依赖数据库 UNIQUE KEY (mailbox_id, message_id)
      INSERT IGNORE INTO mails ...
      if 插入行数 == 0 → skip（已存在）

      // 找出该邮箱的所有订阅员工
      for each subscribed user:
        action = Classifier::resolve(mail, user, mailbox)
        if action == 'notify':
          INSERT IGNORE INTO notifications(mail_id, user_id, notified_at)
          // UNIQUE KEY (mail_id, user_id) 保证幂等
          Notifier::send(user, mail)
          成功 → 更新 email_sent_at = NOW()
          失败 → email_retry_count = 1，下次 Cron 重试

    更新 last_fetched_uid = 本次最大 UID
    更新 last_fetched_at = NOW()

  flock($lock, LOCK_UN);
```

### D. 通知邮件格式

```
件名: 【MailGate / {mailbox.label}】{mail.subject}

{user.name} 様

「{mailbox.label}」({mailbox.email_address}) に新しいメールが届いています。

差出人 : {from_name} <{from_address}>
件名   : {subject}
受信日 : {received_at}

─ 本文（冒頭）──────────────────────
{body_preview}
────────────────────────────────

▶ 詳細を確認する
  {base_url}/mail.php?n={notification_id}

────────────────────────────────
このメールは MailGate システムから自動送信されています。
```

> - `body_preview`：从 `body_text` 中提取前约 100 字符，去除多余空白行后截断，末尾若有截断则追加 `…`。
> - HTML-only 邮件（`body_text` 为空）则从 `body_html` strip_tags 后再提取。
> - 链接使用 `notification_id` 而非 `mail_id`，确保只有被通知到的员工才能打开。

---

## Web 界面说明

### 员工界面

| 页面 | 功能 |
|---|---|
| `dashboard.php` | 通知列表，可按**监控邮箱**分类筛选、已读/未读筛选、关键词搜索 |
| `mail.php?n={nid}` | 邮件详情（校验当前用户是否在该通知记录中），HTML 正文用 iframe sandbox |
| `my-rules.php` | 自定义规则管理（针对每个已订阅的邮箱分别设置）|
| `my-settings.php` | 修改通知邮件地址、修改密码 |

### 管理员界面

| 页面 | 功能 |
|---|---|
| `admin/mailboxes.php` | 添加/编辑/删除监控邮箱，手动触发测试连接 |
| `admin/users.php` | 添加员工、重发开通邮件、禁用账号 |
| `admin/subscriptions.php` | 分配员工 ↔ 监控邮箱的订阅关系 |
| `admin/rules.php` | 全局规则管理（per 邮箱）；切换到指定员工可管理其个人规则 |
| `admin/settings.php` | SMTP 配置、系统通知邮件发件人等 |

---

## 规则匹配示例

| 场景 | 配置 |
|---|---|
| 只有来自指定客户的邮件才通知 | `from_domain = client.co.jp` → notify |
| 屏蔽某个发件人 | `from_address = spam@example.com` → ignore，priority=1 |
| subject 含关键词才通知 | `subject = *urgent*` → notify |
| 全部通知（兜底） | `any = *` → notify，priority=99 |

---

## 安全设计

| 项目 | 措施 |
|---|---|
| IMAP / SMTP 密码 | AES-256-CBC 加密；密钥在 `config.php`（不入 Git）；每次加密生成随机 IV，IV 与密文拼接存储（格式：`base64(iv + ciphertext)`）|
| Web 登录 | bcrypt + PHP Session + HTTPS；登录成功后调用 `session_regenerate_id(true)` 防 Session 固定攻击；Cookie 设置 `httponly`、`secure`、`samesite=Lax` |
| 邮件详情访问 | 通过 `notification_id` 鉴权，确保跨用户无法互看；每次请求校验用户 `status != disabled` |
| CSRF | 所有 POST 表单附 token；Ajax 请求通过自定义请求头携带 token |
| HTML 邮件 | `<iframe sandbox>` 纯沙箱展示（无 allow-same-origin、无 allow-scripts），防止邮件内容访问父页面 |
| SQL | 全部使用 PDO Prepared Statement |
| 附件下载 | 文件在 `storage/` 目录（public 外），PHP 鉴权后 `readfile()` 输出；存储路径使用 UUID 命名，不使用原始文件名 |
| Setup / Reset Token | 数据库只存 SHA-256 哈希；原始 token 仅出现在邮件链接中；使用后立即清除哈希 |

---

## Cron 设置（Xserver 控制面板 → Cron 設定）

```
*/5 * * * * /usr/bin/php8.3 /home/onestept/{domain}/cron/fetch.php >> /home/onestept/logs/mailgate.log 2>&1
```

---

## 技术选型

| 项目 | 选择 |
|---|---|
| PHP | 8.3 |
| IMAP 拉取 | PHP `imap_*` 内置函数（Xserver 已预装）|
| MIME 解析 | `php-mime-mail-parser`（Composer）|
| 前端 | Bootstrap 5 + 原生 JS |
| 数据库 | MySQL 5.7（PDO）|
| 邮件发送 | 封装为 `Mailer` 类，配置切换 `mail()` 或 PHPMailer SMTP |

---

## 开发阶段规划

### Phase 1 — 基础框架
- [ ] `sql/schema.sql` 数据库初始化
- [ ] `config/config.php` 配置文件
- [ ] `Database.php` PDO 封装
- [ ] `Auth.php` 登录 / Setup Token 首次设密 / 忘记密码重置

### Phase 2 — 拉取与通知
- [ ] `Fetcher.php` IMAP 拉取
- [ ] `Classifier.php` 规则引擎
- [ ] `Mailer.php` 邮件发送封装
- [ ] `Notifier.php` 通知逻辑
- [ ] `cron/fetch.php` Cron 入口

### Phase 3 — Web 界面（员工）
- [ ] 登录 / 首次设密页面
- [ ] Dashboard（通知列表 + 按邮箱分类）
- [ ] 邮件详情页（HTML 正文隔离 + 附件）
- [ ] 自定义规则管理

### Phase 4 — Web 界面（管理员）
- [ ] 监控邮箱管理
- [ ] 员工账号管理
- [ ] 订阅关系分配
- [ ] 基础规则管理
- [ ] 系统设置（SMTP）

### Phase 5 — 完善
- [ ] 附件下载
- [ ] 通知邮件去重（同一封邮件不重复通知）
- [ ] 操作日志
- [ ] 已读/未读统计 Badge
