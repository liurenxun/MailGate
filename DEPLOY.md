# MailGate 部署操作说明

> 适用环境：Xserver / Apache + PHP 8.3 + MySQL 5.7

---

## 目录

1. [环境要求](#1-环境要求)
2. [获取代码](#2-获取代码)
3. [配置 .htaccess（PHP 8.3 + 目录保护）](#3-配置-htaccessphp-83--目录保护)
4. [安装 Composer 依赖](#4-安装-composer-依赖)
5. [创建数据库](#5-创建数据库)
6. [创建配置文件](#6-创建配置文件)
7. [设置目录权限](#7-设置目录权限)
8. [配置 Web 服务器（DocumentRoot）](#8-配置-web-服务器documentroot)
9. [初始化管理员账号](#9-初始化管理员账号)
10. [配置 Cron 定时任务](#10-配置-cron-定时任务)
11. [登录后台完成 SMTP 配置](#11-登录后台完成-smtp-配置)
12. [验证清单](#12-验证清单)
13. [常见问题](#13-常见问题)

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

## 2. 获取代码

根据实际情况选择以下两种方式之一。

> **不要**将 `vendor/` 和 `config/config.php` 纳入 Git 仓库，服务器上需分别通过 `composer install` 和手动创建来生成。

### 方式 A：SSH 直接从 GitHub 克隆（推荐）

```bash
# SSH 登录服务器
ssh username@sv*****.xserver.jp

# 进入域名目录
cd ~/onestep-t.co.jp/public_html

# 克隆仓库到子域名目录
git clone https://github.com/your-org/MailGate.git mailgate.onestep-t.co.jp
```

后续更新时：

```bash
cd ~/onestep-t.co.jp/public_html/mailgate.onestep-t.co.jp
git pull origin main
```

### 方式 B：本地打包后 SCP 上传

**本地执行（两种打包方式选其一）：**

```bash
# 方法 1：用 git archive 打包（自动排除 .git 目录）
cd /path/to/local/MailGate
git archive --format=zip --output=mailgate.zip HEAD

# 方法 2：直接从 GitHub 页面下载 ZIP
# 访问 https://github.com/your-org/MailGate → Code → Download ZIP
```

**上传到服务器：**

```bash
scp mailgate.zip username@sv*****.xserver.jp:~/onestep-t.co.jp/public_html/
```

**SSH 登录后解压：**

```bash
ssh username@sv*****.xserver.jp
cd ~/onestep-t.co.jp/public_html

unzip mailgate.zip
mv MailGate-main mailgate.onestep-t.co.jp   # GitHub 下载的 zip 解压后目录名含分支名，按实际调整
rm mailgate.zip
```

**最终目录结构应为：**

```
~/onestep-t.co.jp/public_html/
├── .htaccess                        ← WordPress（原有，勿动）
├── index.php                        ← WordPress（原有，勿动）
├── wp-admin/
├── wp-content/
└── mailgate.onestep-t.co.jp/       ← 本项目根目录（子域名 DocumentRoot）
    ├── .htaccess                    ← 重写规则：将请求转发到 public/
    ├── cron/
    ├── public/                      ← 实际响应 HTTP 请求的目录
    │   └── .htaccess                ← 安全响应头等
    ├── sql/
    ├── src/
    ├── storage/
    ├── config/                      ← config.php 需手动创建，不含于 Git
    ├── composer.json
    └── setup-admin.php
```

---

## 3. 配置 .htaccess（PHP 8.3 + 目录保护）

Xserver 的 PHP 版本是**域名级别**的设定（当前 `onestep-t.co.jp` 为 PHP 7.4），
本项目需要 PHP 8.3，必须通过子目录的 `.htaccess` 覆盖。

### 根据部署方式选择对应方案

---

#### 方案 A：子目录部署（`public_html/mailgate/`）

在项目根目录创建 `.htaccess`：

```bash
vi ~/onestep-t.co.jp/public_html/mailgate/.htaccess
```

写入以下内容（`RewriteBase` 需与实际子目录名一致）：

```apache
# ── PHP 版本切换为 8.3（覆盖域名默认 7.4）──────────────────────
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php83
</FilesMatch>

# ── 禁止目录列表 ────────────────────────────────────────────────
Options -Indexes

# ── URL 重写与敏感目录保护 ───────────────────────────────────────
RewriteEngine On
RewriteBase /mailgate/

# 阻止通过 HTTP 直接访问非公开目录
RewriteRule ^(config|src|sql|cron|vendor|storage)(/|$) - [F,L]

# 将所有请求转发到 public/ 子目录（已在 public/ 内的请求直接放行）
RewriteRule ^public/ - [L]
RewriteRule ^(.*)$ public/$1 [L]
```

访问地址：`https://onestep-t.co.jp/mailgate/`
`config.php` 中 `base_url` 填写：`https://onestep-t.co.jp/mailgate`

---

#### 方案 B：子域名部署（`mailgate.onestep-t.co.jp`）※ 当前采用方案

Xserver 控制面板新增子域名后，会自动创建 `public_html/mailgate.onestep-t.co.jp/` 目录，
将项目文件上传至该目录（即子域名 DocumentRoot = 项目根目录）。

在**项目根目录**创建 `.htaccess`：

```bash
vi ~/onestep-t.co.jp/public_html/mailgate.onestep-t.co.jp/.htaccess
```

写入：

```apache
# PHP バージョンを 8.3 に切り替え
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php83
</FilesMatch>

Options -Indexes

RewriteEngine On

# config/src/sql/cron/vendor/storage への直接アクセス禁止
RewriteRule ^(config|src|sql|cron|vendor|storage)(/|$) - [F,L]

# すべてのリクエストを public/ に転送
RewriteRule ^public/ - [L]
RewriteRule ^(.*)$ public/$1 [L]
```

`public/.htaccess`（プロジェクトに同梱済み）は安全ヘッダーのみ担当。

访问地址：`https://mailgate.onestep-t.co.jp/`
`config.php` 中 `base_url` 填写：`https://mailgate.onestep-t.co.jp`

---

### 验证 .htaccess 是否生效

```bash
# 访问以下 URL，若返回 403 则配置正确
curl -o /dev/null -s -w "%{http_code}\n" \
  https://mailgate.onestep-t.co.jp/config/config.php
# 预期输出：403
```

---

## 4. 安装 Composer 依赖

> **注意：** Xserver 系统自带的 `/usr/bin/composer` 版本为 1.9.1（2019 年），过于陈旧，
> 会导致 `phpmailer/phpmailer` 和 `zbateson/mail-mime-parser` 无法解析。
> 必须在项目目录内下载最新版 `composer.phar` 来使用。

```bash
cd ~/onestep-t.co.jp/public_html/mailgate.onestep-t.co.jp

# 下载最新版 composer.phar
/usr/bin/php8.3 -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
/usr/bin/php8.3 composer-setup.php
/usr/bin/php8.3 -r "unlink('composer-setup.php');"

# 用下载的 composer.phar 安装依赖
/usr/bin/php8.3 composer.phar install --no-dev --optimize-autoloader
```

完成后会生成 `vendor/` 目录，包含 PHPMailer 和 zbateson/mail-mime-parser。

> `zbateson/mail-mime-parser` 为纯 PHP 实现，无需安装任何 PECL 扩展（原 `php-mime-mail-parser` 需要 `mailparse` C 扩展，已替换）。

`composer.phar` 无需纳入 Git 仓库：

```bash
echo "composer.phar" >> .gitignore
```

---

## 5. 创建数据库

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
mysql -u mailgate_user -p mailgate \
  < ~/onestep-t.co.jp/public_html/mailgate.onestep-t.co.jp/sql/schema.sql
```

或在 phpMyAdmin 中选择 `mailgate` 数据库 → 导入 → 选择 `sql/schema.sql`。

---

## 6. 创建配置文件

```bash
cp ~/onestep-t.co.jp/public_html/mailgate.onestep-t.co.jp/config/config.example.php \
   ~/onestep-t.co.jp/public_html/mailgate.onestep-t.co.jp/config/config.php
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
    'base_url' => 'https://mailgate.onestep-t.co.jp',

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

## 7. 设置目录权限

Cron 脚本和 Web 进程需要写入 `storage/attachments/`：

```bash
chmod 755 ~/onestep-t.co.jp/public_html/mailgate.onestep-t.co.jp/storage
chmod 755 ~/onestep-t.co.jp/public_html/mailgate.onestep-t.co.jp/storage/attachments
```

确认 Web 用户（通常是 `www-data` 或 `nobody`）对该目录有写权限。在 Xserver 上通常无需额外操作，PHP 以当前用户权限运行。

---

## 8. 配置 Web 服务器（DocumentRoot）

**子目录部署（方案 A）** 无需修改服务器配置，`.htaccess` 已处理路由。

**子域名部署（方案 B）** 在 Xserver 控制面板中：

1. 「サーバー管理」→「ドメイン設定」→「子ドメイン追加」
2. 子域名：`mailgate`（完整地址 `mailgate.onestep-t.co.jp`）
3. 文档根目录设为（Xserver 通常自动生成）：`/home/{user}/onestep-t.co.jp/public_html/mailgate.onestep-t.co.jp`
4. PHP バージョン設定 → 选择 **PHP 8.3**（子域名可单独设置，此时第 3 步的 `.htaccess` PHP 切换也可省略）

> DocumentRoot 指向**项目根目录**（不含 `/public`），由根目录的 `.htaccess` 负责将请求转发到 `public/`。

**验证：** 访问 `https://mailgate.onestep-t.co.jp` 应显示登录页，访问 `https://mailgate.onestep-t.co.jp/config/config.php` 应返回 403。

---

## 9. 初始化管理员账号

> 前提：第 6 步配置文件已填写完毕，且 SMTP（或 PHP mail）能正常发信。
> 如尚未配置 SMTP，可暂时在 `Mailer.php` 中使用 `mail()` 模式，
> 或先跳过此步，待 SMTP 配置完成后再执行。

```bash
/usr/bin/php8.3 ~/onestep-t.co.jp/public_html/mailgate.onestep-t.co.jp/setup-admin.php
```

按提示输入管理员姓名和邮箱。脚本会向该邮箱发送设密邮件。

**管理员收到邮件后点击链接设置密码，账号即激活。**

完成后立即删除脚本（安全要求）：

```bash
rm ~/onestep-t.co.jp/public_html/mailgate.onestep-t.co.jp/setup-admin.php
```

---

## 10. 配置 Cron 定时任务

**Xserver 控制面板操作：**

1. 「サーバー管理」→「Cron設定」
2. 添加以下任务（每 5 分钟执行一次）：

```
*/5 * * * * /usr/bin/php8.3 ~/onestep-t.co.jp/public_html/mailgate.onestep-t.co.jp/cron/fetch.php >> ~/logs/mailgate.log 2>&1
```

**手动测试 Cron 脚本：**

```bash
/usr/bin/php8.3 ~/onestep-t.co.jp/public_html/mailgate.onestep-t.co.jp/cron/fetch.php
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

## 11. 登录后台完成 SMTP 配置

访问 `https://mailgate.onestep-t.co.jp`，用管理员账号登录后：

### 11-1. 配置 SMTP 发信

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

### 11-2. 添加监控邮箱

导航到「管理 → メールボックス管理」→「追加」。

填写 IMAP 连接信息：
- 主机：例 `imap.your-mail-server.com`
- 端口：SSL `993`（推荐），TLS `143`
- 账号 / 密码
- 监控文件夹：默认 `INBOX`

保存后可点击「接続テスト」验证 IMAP 连接。

### 11-3. 添加员工账号并分配订阅

1. 「管理 → ユーザー管理」→「追加」，填写姓名和邮箱
2. 系统自动发送设密邮件，员工收邮件后设置密码
3. 「管理 → 購読管理」→ 选择邮箱 → 将员工加入订阅

### 11-4. 配置全局规则（可选）

「管理 → ルール管理」→「グローバルルール」可设置全员适用的筛选规则。

---

## 12. 验证清单

部署完成后逐项验证：

- [ ] 访问 `https://mailgate.onestep-t.co.jp` 显示登录页
- [ ] `config/config.php` 直接访问返回 403
- [ ] PHP 版本确认为 8.3（可在登录后的管理页面或通过 `phpinfo()` 临时文件确认）
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

## 13. 常见问题

**Q: 访问页面提示 500 错误**
- 检查 `config/config.php` 是否存在且格式正确
- 检查数据库连接信息是否正确
- 查看 PHP 错误日志（Xserver: 「PHPエラーログ」）

**Q: 页面显示 PHP 语法错误（PHP 7.4 特有）**
- 说明 `.htaccess` 的 PHP 8.3 切换未生效
- 确认 `SetHandler application/x-httpd-php83` 写法正确
- 用 `curl -I https://mailgate.onestep-t.co.jp` 查看 `X-Powered-By` 响应头确认版本

**Q: 访问子域名返回 404 或目录列表**
- 检查项目根目录的 `.htaccess` 中 `RewriteEngine On` 是否存在
- 确认 `mod_rewrite` 已启用（Xserver 默认启用）

**Q: 设密邮件/通知邮件收不到**
- 确认「システム設定」中 SMTP 配置正确
- 使用「テスト送信」功能测试
- 若使用 Gmail SMTP，需开启"应用专用密码"

**Q: IMAP 连接失败**
- 确认 IMAP 端口未被防火墙拦截
- Xserver 出口 IP 可能需要在邮件服务商白名单中添加
- 检查管理后台「メールボックス管理」列表中的「最後のエラー」字段

**Q: Cron 日志显示 `vendor/autoload.php not found`**
- 未执行 `composer install`，按第 4 步操作
- 注意必须使用项目目录内下载的 `composer.phar`，系统 `/usr/bin/composer`（1.9.1）版本过旧会导致安装失败

**Q: 附件下载失败**
- 检查 `storage/attachments/` 目录权限（需 PHP 用户可写）

**Q: 部署后更换了 `encryption_key`**
- 已存储的 IMAP 和 SMTP 密码将无法解密
- 需在管理后台重新输入所有密码保存
