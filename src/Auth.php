<?php
declare(strict_types=1);

/**
 * Auth — 认证与用户管理
 *
 * 功能：
 *   - Session 管理（安全配置：httponly/secure/samesite/strict_mode）
 *   - 登录 / 登出（bcrypt 验证 + session_regenerate_id 防固定攻击）
 *   - 首次设密（setup_token）
 *   - 忘记密码 / 重置密码（reset_token）
 *   - 路由守卫：requireLogin()、requireAdmin()
 *   - CSRF Token
 *   - 管理员：创建用户、重发开通邮件、禁用/启用账号
 *
 * 安全设计：
 *   S2  setup_token / reset_token 数据库仅存 SHA-256 哈希
 *   S8  Session Cookie 设置 httponly + secure + samesite=Lax
 *   F7  每次 requireLogin() 都从 DB 校验 status，disabled 用户实时失效
 *   FC1 忘记密码功能在 Phase 1 一并实现
 */
class Auth
{
    /** 用户信息缓存（同一请求内避免重复查询） */
    private static ?array $currentUser = null;

    /** 禁止实例化 */
    private function __construct() {}

    // ─────────────────────────────────────────────────────────────
    // Session
    // ─────────────────────────────────────────────────────────────

    /**
     * 安全启动 Session（S8）
     * 应在任何读写 $_SESSION 之前调用。
     * bootstrap.php 会自动调用，通常无需手动调用。
     */
    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $secure = (bool)(Database::config()['session_secure'] ?? true);

        session_set_cookie_params([
            'lifetime' => 0,          // 关闭浏览器即失效
            'path'     => '/',
            'secure'   => $secure,    // HTTPS only（生产环境）
            'httponly' => true,        // 禁止 JS 访问 Cookie
            'samesite' => 'Lax',      // 防 CSRF（架构设计要求）
        ]);

        // 严格模式：服务端未知的 Session ID 不被接受
        ini_set('session.use_strict_mode', '1');

        session_start();
    }

    // ─────────────────────────────────────────────────────────────
    // 登录 / 登出
    // ─────────────────────────────────────────────────────────────

    /**
     * 验证登录凭据，成功后创建 Session
     *
     * @return bool 验证成功返回 true
     */
    public static function login(string $email, string $password): bool
    {
        // 查询未被禁用的用户（pending 也允许查出，后续单独判断）
        $user = Database::fetchOne(
            'SELECT * FROM users WHERE email = ? AND status != ?',
            [$email, 'disabled']
        );

        if ($user === null) {
            // 防止计时侧信道：对虚构哈希执行一次 verify（耗时一致）
            password_verify($password, '$2y$12$invalidhashpadding...............................');
            return false;
        }

        if (!password_verify($password, (string)$user['password_hash'])) {
            return false;
        }

        // pending 用户尚未完成初次设密，不允许普通登录
        if ($user['status'] === 'pending') {
            return false;
        }

        // 防 Session 固定攻击（S8）
        session_regenerate_id(true);

        $_SESSION['user_id']   = (int)$user['id'];
        $_SESSION['user_role'] = $user['role'];
        self::$currentUser     = null; // 清空缓存，下次重新从 DB 读取

        return true;
    }

    /**
     * 登出：销毁 Session
     */
    public static function logout(): void
    {
        $_SESSION = [];

        // 清除 Cookie
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );

        session_destroy();
        self::$currentUser = null;
    }

    // ─────────────────────────────────────────────────────────────
    // 当前用户
    // ─────────────────────────────────────────────────────────────

    /**
     * 获取当前登录用户（从 DB 实时校验 status，F7）
     *
     * @return array|null 登录且 status=active 时返回用户行，否则 null
     */
    public static function getCurrentUser(): ?array
    {
        if (self::$currentUser !== null) {
            return self::$currentUser;
        }

        if (empty($_SESSION['user_id'])) {
            return null;
        }

        // 每次请求都从 DB 读取：确保 disabled 用户立即失效（F7）
        $user = Database::fetchOne(
            'SELECT * FROM users WHERE id = ? AND status = ?',
            [(int)$_SESSION['user_id'], 'active']
        );

        self::$currentUser = $user ?: null;
        return self::$currentUser;
    }

    // ─────────────────────────────────────────────────────────────
    // 路由守卫
    // ─────────────────────────────────────────────────────────────

    /**
     * 要求已登录，否则跳转到登录页
     */
    public static function requireLogin(): void
    {
        if (self::getCurrentUser() === null) {
            Helpers::redirect('/index.php');
        }
    }

    /**
     * 要求管理员身份，否则跳转到 Dashboard
     */
    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (self::getCurrentUser()['role'] !== 'admin') {
            Helpers::redirect('/dashboard.php');
        }
    }

    // ─────────────────────────────────────────────────────────────
    // 首次设密（Setup Token）
    // ─────────────────────────────────────────────────────────────

    /**
     * 校验 setup_token，查找待激活用户
     *
     * @return array|null 找到且有效时返回 user 行，否则 null
     */
    public static function findUserBySetupToken(string $token): ?array
    {
        $hash = Helpers::hashToken($token);
        return Database::fetchOne(
            'SELECT * FROM users
             WHERE setup_token_hash = ?
               AND setup_token_expires > NOW()
               AND status = ?',
            [$hash, 'pending']
        );
    }

    /**
     * 完成首次设密
     *
     * @param string $token    URL 中的原始令牌
     * @param string $password 用户输入的密码
     * @return bool 成功返回 true；令牌无效/过期或密码不合规返回 false
     */
    public static function setupPassword(string $token, string $password): bool
    {
        $user = self::findUserBySetupToken($token);
        if ($user === null) {
            return false;
        }

        if (!Helpers::validatePassword($password)) {
            return false;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        Database::query(
            'UPDATE users
             SET password_hash = ?,
                 status = ?,
                 setup_token_hash = NULL,
                 setup_token_expires = NULL
             WHERE id = ?',
            [$hash, 'active', (int)$user['id']]
        );

        return true;
    }

    // ─────────────────────────────────────────────────────────────
    // 忘记密码 / 重置密码（FC1）
    // ─────────────────────────────────────────────────────────────

    /**
     * 发送密码重置邮件
     *
     * 防用户枚举：无论邮箱是否存在，外部行为相同（无错误提示）。
     */
    public static function requestPasswordReset(string $email): void
    {
        $user = Database::fetchOne(
            'SELECT * FROM users WHERE email = ? AND status = ?',
            [$email, 'active']
        );

        if ($user === null) {
            // 防枚举：不返回错误，直接结束
            return;
        }

        $token   = Helpers::generateToken();
        $hash    = Helpers::hashToken($token);
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

        Database::query(
            'UPDATE users SET reset_token_hash = ?, reset_token_expires = ? WHERE id = ?',
            [$hash, $expires, (int)$user['id']]
        );

        Mailer::sendPasswordResetEmail($user, $token);
    }

    /**
     * 校验 reset_token 并查找用户
     *
     * @return array|null 令牌有效时返回 user 行，否则 null
     */
    public static function findUserByResetToken(string $token): ?array
    {
        $hash = Helpers::hashToken($token);
        return Database::fetchOne(
            'SELECT * FROM users
             WHERE reset_token_hash = ?
               AND reset_token_expires > NOW()
               AND status = ?',
            [$hash, 'active']
        );
    }

    /**
     * 执行密码重置
     *
     * @param string $token    URL 中的原始令牌
     * @param string $password 新密码
     * @return bool 成功返回 true；令牌无效/过期或密码不合规返回 false
     */
    public static function resetPassword(string $token, string $password): bool
    {
        $user = self::findUserByResetToken($token);
        if ($user === null) {
            return false;
        }

        if (!Helpers::validatePassword($password)) {
            return false;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        Database::query(
            'UPDATE users
             SET password_hash = ?,
                 reset_token_hash = NULL,
                 reset_token_expires = NULL
             WHERE id = ?',
            [$hash, (int)$user['id']]
        );

        return true;
    }

    // ─────────────────────────────────────────────────────────────
    // 管理员操作
    // ─────────────────────────────────────────────────────────────

    /**
     * 创建新用户并发送开通邮件
     *
     * @param string $name
     * @param string $email
     * @param string $role  'admin' | 'recipient'
     * @return array|null 成功返回新用户行（含 id），邮箱重复返回 null
     */
    public static function createUser(string $name, string $email, string $role = 'recipient'): ?array
    {
        $token   = Helpers::generateToken();
        $hash    = Helpers::hashToken($token);
        $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

        try {
            Database::query(
                'INSERT INTO users (name, email, role, status, setup_token_hash, setup_token_expires)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$name, $email, $role, 'pending', $hash, $expires]
            );
        } catch (\PDOException $e) {
            // 1062 = Duplicate entry（邮箱已存在）
            if ((string)($e->errorInfo[1] ?? '') === '1062') {
                return null;
            }
            throw $e;
        }

        $user = Database::fetchOne(
            'SELECT * FROM users WHERE id = ?',
            [Database::lastInsertId()]
        );

        Mailer::sendSetupEmail($user, $token);

        return $user;
    }

    /**
     * 重发开通邮件（更新 token，旧 token 立即失效）
     *
     * @param int $userId
     * @return bool 成功返回 true；用户不存在或已激活返回 false
     */
    public static function resendSetupEmail(int $userId): bool
    {
        $user = Database::fetchOne(
            'SELECT * FROM users WHERE id = ? AND status = ?',
            [$userId, 'pending']
        );

        if ($user === null) {
            return false;
        }

        $token   = Helpers::generateToken();
        $hash    = Helpers::hashToken($token);
        $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

        Database::query(
            'UPDATE users SET setup_token_hash = ?, setup_token_expires = ? WHERE id = ?',
            [$hash, $expires, $userId]
        );

        Mailer::sendSetupEmail($user, $token);

        return true;
    }

    /**
     * 禁用用户账号
     * 禁用后下次请求时 getCurrentUser() 返回 null，Session 自动失效（F7）
     */
    public static function disableUser(int $userId): void
    {
        Database::query(
            "UPDATE users SET status = 'disabled' WHERE id = ?",
            [$userId]
        );
    }

    /**
     * 启用已禁用的用户账号
     */
    public static function enableUser(int $userId): void
    {
        Database::query(
            "UPDATE users SET status = 'active' WHERE id = ?",
            [$userId]
        );
    }

    // ─────────────────────────────────────────────────────────────
    // CSRF
    // ─────────────────────────────────────────────────────────────

    /**
     * 获取（或生成）当前 Session 的 CSRF Token
     */
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * 验证 CSRF Token（使用时间安全比较，防计时攻击）
     */
    public static function verifyCsrf(string $token): bool
    {
        return !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }
}
