<?php
declare(strict_types=1);

/**
 * Database — PDO 单例封装
 *
 * 用法：
 *   $pdo = Database::get();
 *   $stmt = Database::get()->prepare('SELECT ...');
 */
class Database
{
    private static ?PDO $pdo = null;

    /** 禁止实例化 */
    private function __construct() {}

    /**
     * 获取 PDO 实例（懒加载，全局单例）
     */
    public static function get(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect();
        }
        return self::$pdo;
    }

    /**
     * 读取配置并建立连接
     */
    private static function connect(): PDO
    {
        $cfg = self::config();
        $db  = $cfg['db'];

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $db['host'],
            $db['dbname'],
            $db['charset']
        );

        return new PDO($dsn, $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    /**
     * 读取配置文件（带静态缓存，避免重复 require）
     */
    public static function config(): array
    {
        static $cfg = null;
        if ($cfg === null) {
            $cfg = require __DIR__ . '/../config/config.php';
        }
        return $cfg;
    }

    /**
     * 执行带参数的查询，返回 PDOStatement
     */
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * 查询单行，不存在时返回 null
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * 查询多行
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * 返回上次 INSERT 的自增 ID
     */
    public static function lastInsertId(): int
    {
        return (int) self::get()->lastInsertId();
    }
}
