# MailGate 部署操作说明

> 适用环境：Xserver / Apache + PHP 8.3 + MySQL 5.7

---

## 目录

1. [环境要求](#1-环境要求)
2. [上传文件](#2-上传文件)
3. [安装 Composer 依赖](#3-安装-composer-依赖)
4. [创建数据库](#4-创建数据库)
5. [创建配置文件](#5-创建配置文件)
6. [设置目录权限](#6-设置目录权限)
7. [配置 Web 服务器（DocumentRoot）](#7-配置-web-服务器documentroot)
8. [初始化管理员账号](#8-初始化管理员账号)
9. [配置 Cron 定时任务](#9-配置-cron-定时任务)
10. [登录后台完成 SMTP 配置](#10-登录后台完成-smtp-配置)
11. [验证清单](#11-验证清单)
12. [常见问题](#12-常见问题)

---

## 1. 环境要求

| 项目 | 要求 |
|---|---|
| PHP | 8.3（需启用 `imap`、`mbstring`、`openssl`、`pdo_mysql` 扩展） |
| MySQL | 5.7 或以上 |
| Web 服务器 | Apache（需 `mod_headers`，用于安全响应头） |
| Composer | 2.x |
| HTTPS | 生产环境必须（Session secure cookie 依赖） |

在 Xserver 上确认 PHP 版本：

```bash
/usr/bin/php8.3 -v
```

---

## 2. 上传文件

将以下目录/文件上传到服务器（例如 `/home/{user}/{domain}/`），**不要**上传 `vendor/` 和 `config/config.php`：

```
/home/{user}/{domain}/
├── cron/
├── public/          ← Web 根目录（见第 7 步）
├── sql/
├── src/
├── storage/
├── composer.json
└── setup-admin.php
```

> `config/config.php` 含密码，不入 Git，需在服务器上手动创建（见第 5 步）。

---

## 3. 安装 Composer 依赖

```bash
cd /home/{user}/{domain}
composer install --no-dev --optimize-autoloader
```

完成后会生成 `vendor/` 目录，包含 PHPMailer 和 php-mime-mail-parser。

---

## 4. 创建数据库

在 MySQL 中执行（Xserver 可通过 phpMyAdmin 操作）：

```sql
-- 创建数据库和用户
CREATE DATABASE mailgate CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mailgate_user'@'localhost' IDENTIFIED BY '你的数据库密码';
GRANT ALL PRIVILEGES ON mailgate.* TO 'mailgate_user'@'localhost';
FLUSH PRIVILEGES;
```

然后导入建表脚本：

```bash
mysql -u mailgate_user -p mailgate < /home/{user}/{domain}/sql/schema.sql
```

或在 phpMyAdmin 中选择 `mailgate` 数据库 → 导入 → 选择 `sql/schema.sql`。

---

## 5. 创建配置文件

```bash
cp /home/{user}/{domain}/config/config.example.php \
   /home/{user}/{domain}/config/config.php
```

用编辑器打开 `config/config.php`，填写以下内容：

```php
return [

    // 数据库
    'db' => [
        'host'     => 'localhost',
        'dbname'   => 'mailgate',
        'user'     => 'mailgate_user',
        'password' => '你的数据库密码',
        'charset'  => 'utf8mb4',
    ],

    // AES-256 加密密钥（用于加密 IMAP/SMTP 密码）
    // 生成命令：
    //   /usr/bin/php8.3 -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
    // 警告：部署后切勿更换，否则无法解密已存储的密码
    'encryption_key' => '在此填入64位十六进制字符串',

    // Web 访问地址（不含末尾斜杠）
    'base_url' => 'https://your-domain.com',

    // 生产环境必须为 true（依赖 HTTPS）
    'session_secure' => true,

    // 显示在页面和通知邮件中的应用名称
    'app_name' => 'MailGate',

];
```

**生成加密密钥：**

```bash
/usr/bin/php8.3 -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

将输出的 64 位十六进制字符串填入 `encryption_key`。

---

## 6. 设置目录权限

Cron 脚本和 Web 进程需要写入 `storage/attachments/`：

```bash
chmod 755 /home/{user}/{domain}/storage
chmod 755 /home/{user}/{domain}/storage/attachments
```

确认 Web 用户（通常是 `www-data` 或 `nobody`）对该目录有写权限。在 Xserver 上通常无需额外操作，PHP 以当前用户权限运行。

---

## 7. 配置 Web 服务器（DocumentRoot）

**将 DocumentRoot 指向 `public/` 子目录**，而非项目根目录。

这样 `src/`、`config/`、`sql/`、`storage/` 对外完全不可访问。

**Xserver 操作步骤：**

1. 登录 Xserver 控制面板
2. 「サーバー管理」→「ドメイン設定」→ 选择对应域名
3. 将文档根目录设为 `/home/{user}/{domain}/public`

**验证：** 访问 `https://your-domain.com` 应显示登录页，访问 `https://your-domain.com/../config/config.php` 应返回 403。

---

## 8. 初始化管理员账号

> 前提：第 5 步配置文件已填写完毕，且 SMTP（或 PHP mail）能正常发信。
> 如尚未配置 SMTP，可暂时在 `Mailer.php` 中使用 `mail()` 模式，
> 或先跳过此步，待 SMTP 配置完成后再执行。

```bash
/usr/bin/php8.3 /home/{user}/{domain}/setup-admin.php
```

按提示输入管理员姓名和邮箱。脚本会向该邮箱发送设密邮件。

**管理员收到邮件后点击链接设置密码，账号即激活。**

完成后立即删除脚本（安全要求）：

```bash
rm /home/{user}/{domain}/setup-admin.php
```

---

## 9. 配置 Cron 定时任务

**Xserver 控制面板操作：**

1. 「サーバー管理」→「Cron設定」
2. 添加以下任务（每 5 分钟执行一次）：

```
*/5 * * * * /usr/bin/php8.3 /home/{user}/{domain}/cron/fetch.php >> /home/{user}/logs/mailgate.log 2>&1
```

**手动测试 Cron 脚本：**

```bash
/usr/bin/php8.3 /home/{user}/{domain}/cron/fetch.php
```

正常输出示例：

```
[2026-02-20 10:00:01] === Fetch cycle start ===
[2026-02-20 10:00:01]   Retry: 0 pending notification(s) processed.
[2026-02-20 10:00:01]   No active mailboxes configured.
[2026-02-20 10:00:01] === Fetch cycle complete ===
```

> `No active mailboxes configured.` 是正常的，表示尚未在管理后台添加监控邮箱。

---

## 10. 登录后台完成 SMTP 配置

访问 `https://your-domain.com`，用管理员账号登录后：

### 10-1. 配置 SMTP 发信

导航到「管理 → システム設定」，填写 SMTP 信息并保存。

| 字段 | 说明 |
|---|---|
| SMTP ホスト | 例：`smtp.gmail.com` |
| ポート | TLS: `587`，SSL: `465` |
| 暗号化 | 推荐 `TLS` |
| ユーザー名 | SMTP 账号 |
| パスワード | SMTP 密码（AES-256 加密存储） |
| 差出人アドレス | 通知邮件的发件人地址 |

填写后点击「テスト送信」确认发信正常。

### 10-2. 添加监控邮箱

导航到「管理 → メールボックス管理」→「追加」。

填写 IMAP 连接信息：
- 主机：例 `imap.your-mail-server.com`
- 端口：SSL `993`（推荐），TLS `143`
- 账号 / 密码
- 监控文件夹：默认 `INBOX`

保存后可点击「接続テスト」验证 IMAP 连接。

### 10-3. 添加员工账号并分配订阅

1. 「管理 → ユーザー管理」→「追加」，填写姓名和邮箱
2. 系统自动发送设密邮件，员工收邮件后设置密码
3. 「管理 → 購読管理」→ 选择邮箱 → 将员工加入订阅

### 10-4. 配置全局规则（可选）

「管理 → ルール管理」→「グローバルルール」可设置全员适用的筛选规则。

---

## 11. 验证清单

部署完成后逐项验证：

- [ ] 访问 `https://your-domain.com` 显示登录页
- [ ] 管理员可正常登录
- [ ] 「システム設定」→ 测试邮件发送成功
- [ ] 添加监控邮箱后 IMAP 连接测试通过
- [ ] 手动运行 `cron/fetch.php` 无报错
- [ ] Cron 任务已在控制面板中设置
- [ ] 员工账号可收到设密邮件并正常登录
- [ ] 员工在 dashboard 可查看通知和邮件详情
- [ ] 附件可正常下载
- [ ] `setup-admin.php` 已删除

---

## 12. 常见问题

**Q: 访问页面提示 500 错误**
- 检查 `config/config.php` 是否存在且格式正确
- 检查数据库连接信息是否正确
- 查看 PHP 错误日志（Xserver: 「PHPエラーログ」）

**Q: 设密邮件/通知邮件收不到**
- 确认「システム設定」中 SMTP 配置正确
- 使用「テスト送信」功能测试
- 若使用 Gmail SMTP，需开启"应用专用密码"

**Q: IMAP 连接失败**
- 确认 IMAP 端口未被防火墙拦截
- Xserver 出口 IP 可能需要在邮件服务商白名单中添加
- 检查管理后台「メールボックス管理」列表中的「最後のエラー」字段

**Q: Cron 日志显示 `vendor/autoload.php not found`**
- 未执行 `composer install`，按第 3 步操作

**Q: 附件下载失败**
- 检查 `storage/attachments/` 目录权限（需 PHP 用户可写）

**Q: 部署后更换了 `encryption_key`**
- 已存储的 IMAP 和 SMTP 密码将无法解密
- 需在管理后台重新输入所有密码保存
