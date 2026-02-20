<?php
declare(strict_types=1);

/**
 * Helpers — 通用工具函数集
 *
 * 涵盖：AES-256-CBC 加解密、安全令牌、密码校验、XSS 转义、重定向
 */
class Helpers
{
    // ─────────────────────────────────────────────────────────────
    // 加密 / 解密（AES-256-CBC）
    // 格式：base64( IV[16 bytes] + ciphertext )
    // 密钥来自 config.php['encryption_key']，通过 SHA-256 规范化为 32 字节
    // ─────────────────────────────────────────────────────────────

    /**
     * 加密明文字符串
     *
     * @throws RuntimeException 加密失败时
     */
    public static function encrypt(string $plaintext): string
    {
        $key        = self::derivedKey();
        $iv         = random_bytes(16);
        $ciphertext = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $ciphertext);
    }

    /**
     * 解密密文字符串
     *
     * @return string|false 成功返回明文，失败返回 false
     */
    public static function decrypt(string $encoded): string|false
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= 16) {
            return false;
        }

        $key        = self::derivedKey();
        $iv         = substr($raw, 0, 16);
        $ciphertext = substr($raw, 16);

        return openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    }

    /**
     * 从 config['encryption_key'] 派生标准 32 字节密钥
     * 无论配置值长短，都通过 SHA-256 规范化，避免 OpenSSL 自动截断/填充
     */
    private static function derivedKey(): string
    {
        $raw = Database::config()['encryption_key'];
        return hash('sha256', $raw, true); // binary = true → 32 bytes
    }

    // ─────────────────────────────────────────────────────────────
    // 安全令牌
    // ─────────────────────────────────────────────────────────────

    /**
     * 生成密码学安全的随机令牌（十六进制字符串）
     *
     * @param int $bytes 随机字节数，默认 32（生成 64 字符十六进制）
     */
    public static function generateToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * 对令牌进行 SHA-256 哈希（用于数据库存储）
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    // ─────────────────────────────────────────────────────────────
    // 密码校验
    // ─────────────────────────────────────────────────────────────

    /**
     * 校验密码强度：最少 8 位，且同时含字母和数字
     */
    public static function validatePassword(string $password): bool
    {
        return strlen($password) >= 8
            && preg_match('/[a-zA-Z]/', $password) === 1
            && preg_match('/[0-9]/', $password) === 1;
    }

    // ─────────────────────────────────────────────────────────────
    // XSS 防护
    // ─────────────────────────────────────────────────────────────

    /**
     * HTML 实体转义（用于在模板中输出变量）
     */
    public static function e(string $str): string
    {
        return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // ─────────────────────────────────────────────────────────────
    // HTTP 工具
    // ─────────────────────────────────────────────────────────────

    /**
     * 重定向并终止
     */
    public static function redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }

    /**
     * 返回 JSON 并终止（用于 Ajax 响应）
     */
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ─────────────────────────────────────────────────────────────
    // 邮件地址解析（Classifier 使用）
    // ─────────────────────────────────────────────────────────────

    /**
     * 从 "Display Name <user@domain.com>" 格式中提取纯地址
     * 处理各种非标准格式，安全地提取域名时使用 (T6)
     */
    public static function extractEmail(string $fromHeader): string
    {
        // 尝试匹配尖括号包裹的地址
        if (preg_match('/<([^>]+)>/', $fromHeader, $m)) {
            return strtolower(trim($m[1]));
        }
        return strtolower(trim($fromHeader));
    }

    /**
     * 从邮件地址中提取域名部分
     * 输入可以是完整 From 头（含显示名称）或纯地址
     */
    public static function extractDomain(string $fromHeader): string
    {
        $email = self::extractEmail($fromHeader);
        $at    = strrpos($email, '@');
        if ($at === false) {
            return '';
        }
        return substr($email, $at + 1);
    }

    // ─────────────────────────────────────────────────────────────
    // 通配符匹配（规则引擎使用）
    // ─────────────────────────────────────────────────────────────

    /**
     * 用 * 通配符匹配字符串（大小写不敏感）
     *
     * 示例：
     *   wildcardMatch('*@client.co.jp', 'sales@client.co.jp') → true
     *   wildcardMatch('*invoice*', 'Please check invoice #123') → true
     */
    public static function wildcardMatch(string $pattern, string $subject): bool
    {
        // 将通配符模式转为正则：先转义特殊字符，再还原 *
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/iu';
        return preg_match($regex, $subject) === 1;
    }
}
