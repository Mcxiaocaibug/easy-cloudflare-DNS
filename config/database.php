<?php
/**
 * MySQL 数据库配置与兼容层（使用 PDO）
 * - 使用环境变量 MYSQL_DSN / MYSQL_USER / MYSQL_PASSWORD 连接外部 MySQL
 * - 提供 SQLite3 兼容 API，尽量不修改其它业务代码
 * - 支持项目根目录 .env 文件加载环境变量
 */

// 定义 SQLite3 兼容常量
if (!defined('SQLITE3_ASSOC')) define('SQLITE3_ASSOC', 1);
if (!defined('SQLITE3_NUM')) define('SQLITE3_NUM', 2);
if (!defined('SQLITE3_BOTH')) define('SQLITE3_BOTH', 3);
if (!defined('SQLITE3_TEXT')) define('SQLITE3_TEXT', 1);
if (!defined('SQLITE3_INTEGER')) define('SQLITE3_INTEGER', 2);
if (!defined('SQLITE3_FLOAT')) define('SQLITE3_FLOAT', 3);
if (!defined('SQLITE3_BLOB')) define('SQLITE3_BLOB', 4);

class SQLite3ResultCompatible {
    private $stmt;
    public function __construct(PDOStatement $stmt) { $this->stmt = $stmt; }
    public function fetchArray($mode = SQLITE3_BOTH) {
        $fetchMode = PDO::FETCH_BOTH;
        if ($mode === SQLITE3_ASSOC) $fetchMode = PDO::FETCH_ASSOC;
        elseif ($mode === SQLITE3_NUM) $fetchMode = PDO::FETCH_NUM;
        $row = $this->stmt->fetch($fetchMode);
        return $row === false ? false : $row;
    }
}

class SQLite3StmtCompatible {
    private $pdo;
    private $stmt;
    public function __construct(PDO $pdo, PDOStatement $stmt) { $this->pdo = $pdo; $this->stmt = $stmt; }
    public function bindValue($param, $value, $type = null) {
        $pdoType = PDO::PARAM_STR;
        if ($type === SQLITE3_INTEGER) $pdoType = PDO::PARAM_INT;
        elseif ($type === SQLITE3_FLOAT) $pdoType = PDO::PARAM_STR; // PDO 无 float 类型
        elseif ($type === SQLITE3_BLOB) $pdoType = PDO::PARAM_LOB;
        return $this->stmt->bindValue($param, $value, $pdoType);
    }
    public function execute() {
        $this->stmt->execute();
        return new SQLite3ResultCompatible($this->stmt);
    }
}

class PDOCompatDB {
    private $pdo;
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
    public function enableExceptions($enable) { /* PDO 默认异常模式 */ }
    public function getPDO() { return $this->pdo; }
    public function exec($sql) {
        $trim = trim($sql);
        if (stripos($trim, 'PRAGMA') === 0) return true; // 忽略 SQLite PRAGMA
        if (preg_match('/^BEGIN(\s+TRANSACTION)?$/i', $trim)) return $this->pdo->beginTransaction();
        if (preg_match('/^COMMIT$/i', $trim)) return $this->pdo->commit();
        if (preg_match('/^ROLLBACK$/i', $trim)) return $this->pdo->rollBack();
        return $this->pdo->exec($sql);
    }
    public function prepare($sql) { return new SQLite3StmtCompatible($this->pdo, $this->pdo->prepare($sql)); }
    public function query($sql) {
        // 兼容 SQLite 元数据查询
        if (preg_match("/SELECT\\s+name\\s+FROM\\s+sqlite_master\\s+WHERE\\s+type=\\'table\\'\\s+AND\\s+name=\\'([^\\']+)\\'/i", $sql, $m)) {
            $table = $m[1];
            $exists = $this->tableExists($table);
            $stmt = $this->pdo->query("SELECT '" . ($exists ? $table : '') . "' AS name");
            return new SQLite3ResultCompatible($stmt);
        }
        if (preg_match('/SELECT\\s+COUNT\\(\\*\\)\\s+FROM\\s+sqlite_master\\s+WHERE\\s+type=\\'table\\'/i', $sql)) {
            $dbName = $this->currentDatabase();
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '".$dbName."'");
            return new SQLite3ResultCompatible($stmt);
        }
        if (preg_match('/PRAGMA\\s+table_info\\(([^\\)]+)\\)/i', $sql, $m)) {
            $table = trim($m[1], '`"\'');
            $dbName = $this->currentDatabase();
            $stmt = $this->pdo->prepare("SELECT COLUMN_NAME as name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ORDINAL_POSITION");
            $stmt->execute([$dbName, $table]);
            return new SQLite3ResultCompatible($stmt);
        }
        $stmt = $this->pdo->query($sql);
        return new SQLite3ResultCompatible($stmt);
    }
    public function querySingle($sql, $entireRow = false) {
        $stmt = $this->pdo->query($sql);
        if (!$stmt) return null;
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return $entireRow ? $row : array_values($row)[0];
    }
    public function lastInsertRowID() { return (int)$this->pdo->lastInsertId(); }
    public function escapeString($str) { return substr($this->pdo->quote($str), 1, -1); }
    private function currentDatabase() { return $this->pdo->query('SELECT DATABASE()')->fetchColumn(); }
    private function tableExists($table) {
        $dbName = $this->currentDatabase();
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
        $stmt->execute([$dbName, $table]);
        return $stmt->fetchColumn() > 0;
    }
}

class Database {
    private static $instance = null;
    private $db; // PDOCompatDB

    public static function getInstance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->loadEnvFile();
        $dsn = getenv('MYSQL_DSN');
        $user = getenv('MYSQL_USER');
        $pass = getenv('MYSQL_PASSWORD');
        if (!$dsn) {
            $host = getenv('MYSQL_HOST') ?: 'localhost';
            $port = getenv('MYSQL_PORT') ?: '3306';
            $dbname = getenv('MYSQL_DATABASE') ?: 'cloudflare_dns';
            $charset = getenv('MYSQL_CHARSET') ?: 'utf8mb4';
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
        }
        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4'
            ]);
        } catch (Throwable $e) {
            throw new Exception('MySQL 连接失败，请检查环境变量 MYSQL_DSN/MYSQL_USER/MYSQL_PASSWORD: ' . $e->getMessage());
        }
        $this->db = new PDOCompatDB($pdo);

        // 确保 data 目录用于 install.lock
        $dataDir = dirname(__DIR__) . '/data';
        if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);

        $this->initTables();
    }

    public function getConnection() { return $this->db; }

    private function loadEnvFile() {
        $envPath = __DIR__ . '/../.env';
        if (!file_exists($envPath)) return;
        $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return;
        foreach ($lines as $line) {
            if (strpos(ltrim($line), '#') === 0) continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            $val = trim($val, "\"' ");
            if ($key !== '') putenv("{$key}={$val}");
        }
    }

    private function initTables() {
        $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $this->db->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(191) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(255) NULL,
            points INT DEFAULT 100,
            status TINYINT(1) DEFAULT 1,
            github_id VARCHAR(255) NULL,
            github_username VARCHAR(255) NULL,
            avatar_url VARCHAR(512) NULL,
            oauth_provider VARCHAR(64) NULL,
            github_bonus_received TINYINT(1) DEFAULT 0,
            group_id INT DEFAULT 1,
            group_changed_at TIMESTAMP NULL DEFAULT NULL,
            group_changed_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) $engine");

        $this->db->exec("CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(191) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) $engine");

        $this->db->exec("CREATE TABLE IF NOT EXISTS domains (
            id INT AUTO_INCREMENT PRIMARY KEY,
            domain_name VARCHAR(191) NOT NULL UNIQUE,
            api_key TEXT NOT NULL,
            email VARCHAR(255) NOT NULL,
            zone_id VARCHAR(255) NOT NULL,
            proxied_default TINYINT(1) DEFAULT 1,
            status TINYINT(1) DEFAULT 1,
            provider_type VARCHAR(64) DEFAULT 'cloudflare',
            provider_uid VARCHAR(255) DEFAULT '',
            api_base_url VARCHAR(255) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) $engine");

        $this->db->exec("CREATE TABLE IF NOT EXISTS dns_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            domain_id INT NULL,
            subdomain VARCHAR(255) NOT NULL,
            type VARCHAR(32) NOT NULL,
            content TEXT NOT NULL,
            proxied TINYINT(1) DEFAULT 0,
            cloudflare_id VARCHAR(255) NULL,
            status TINYINT(1) DEFAULT 1,
            is_system TINYINT(1) DEFAULT 0,
            remark TEXT DEFAULT NULL,
            ttl INT DEFAULT 1,
            priority INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_dns_records_domain_id(domain_id),
            INDEX idx_dns_records_user_id(user_id)
        ) $engine");

        $this->db->exec("CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(191) NOT NULL UNIQUE,
            setting_value TEXT NULL,
            description TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) $engine");

        $this->db->exec("CREATE TABLE IF NOT EXISTS card_keys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            card_key VARCHAR(191) NOT NULL UNIQUE,
            points INT NOT NULL,
            max_uses INT DEFAULT 1,
            used_count INT DEFAULT 0,
            status TINYINT(1) DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) $engine");

        $this->db->exec("CREATE TABLE IF NOT EXISTS card_key_usage (
            id INT AUTO_INCREMENT PRIMARY KEY,
            card_key_id INT NULL,
            user_id INT NULL,
            points_added INT,
            used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) $engine");

        $this->db->exec("CREATE TABLE IF NOT EXISTS action_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_type VARCHAR(32) NOT NULL,
            user_id INT NOT NULL,
            action VARCHAR(191) NOT NULL,
            details TEXT NULL,
            ip_address VARCHAR(64) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) $engine");

        $this->db->exec("CREATE TABLE IF NOT EXISTS dns_record_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type_name VARCHAR(32) NOT NULL UNIQUE,
            description TEXT NULL,
            enabled TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) $engine");

        $this->db->exec("CREATE TABLE IF NOT EXISTS invitations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            inviter_id INT NOT NULL,
            invitation_code VARCHAR(191) NOT NULL UNIQUE,
            reward_points INT DEFAULT 0,
            use_count INT DEFAULT 0,
            total_rewards INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_used_at TIMESTAMP NULL DEFAULT NULL
        ) $engine");

        $this->db->exec("CREATE TABLE IF NOT EXISTS invitation_uses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invitation_id INT NOT NULL,
            invitee_id INT NOT NULL,
            reward_points INT DEFAULT 0,
            used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) $engine");

        $this->db->exec("CREATE TABLE IF NOT EXISTS announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            type VARCHAR(32) DEFAULT 'info',
            is_active TINYINT(1) DEFAULT 1,
            show_frequency VARCHAR(32) DEFAULT 'once',
            interval_hours INT DEFAULT 24,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) $engine");

        $this->db->exec("CREATE TABLE IF NOT EXISTS user_announcement_views (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            announcement_id INT NOT NULL,
            last_viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            view_count INT DEFAULT 1,
            UNIQUE KEY uniq_user_announce (user_id, announcement_id)
        ) $engine");

        $this->db->exec("CREATE TABLE IF NOT EXISTS blocked_prefixes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            prefix VARCHAR(191) NOT NULL UNIQUE,
            description TEXT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) $engine");

        $this->db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(64) NOT NULL,
            username VARCHAR(191) NOT NULL,
            type VARCHAR(32) NOT NULL,
            attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            success TINYINT(1) DEFAULT 0,
            ip VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL
        ) $engine");

        $this->db->exec("CREATE TABLE IF NOT EXISTS user_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_name VARCHAR(64) NOT NULL UNIQUE,
            display_name VARCHAR(128) NOT NULL,
            points_per_record INT DEFAULT 1,
            description TEXT DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            can_access_all_domains TINYINT(1) DEFAULT 0,
            max_records INT DEFAULT -1,
            priority INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) $engine");

        $this->db->exec("CREATE TABLE IF NOT EXISTS user_group_domains (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id INT NOT NULL,
            domain_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_group_domain (group_id, domain_id),
            INDEX idx_ugd_group (group_id),
            INDEX idx_ugd_domain (domain_id)
        ) $engine");

        // 索引
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_users_github_id ON users(github_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_users_oauth_provider ON users(oauth_provider)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_domains_provider_type ON domains(provider_type)");

        // 创建默认管理员（如果未安装）
        if (!file_exists(__DIR__ . '/../data/install.lock')) {
            $exists = $this->db->querySingle("SELECT COUNT(*) FROM admins WHERE username = 'admin'");
            if (!$exists) {
                $password = password_hash('admin123456', PASSWORD_DEFAULT);
                $stmt = $this->db->prepare("INSERT INTO admins (username, password, email) VALUES (?, ?, ?)");
                $stmt->bindValue(1, 'admin', SQLITE3_TEXT);
                $stmt->bindValue(2, $password, SQLITE3_TEXT);
                $stmt->bindValue(3, 'admin@example.com', SQLITE3_TEXT);
                $stmt->execute();
            }
        }

        $this->insertDefaultSettings();
        $this->insertDefaultDNSTypes();
        $this->insertDefaultUserGroups();
    }

    private function insertDefaultSettings() {
        $default_settings = [
            ['points_per_record', '1', '每条DNS记录消耗积分'],
            ['default_user_points', '5', '新用户默认积分'],
            ['site_name', '六趣DNS域名分发系统', '网站名称'],
            ['allow_registration', '1', '是否允许用户注册'],
            ['invitation_enabled', '1', '是否启用邀请系统'],
            ['invitation_reward_points', '10', '邀请成功奖励积分'],
            ['invitee_bonus_points', '5', '被邀请用户额外积分'],
            ['smtp_enabled', '1', '是否启用SMTP邮件发送'],
            ['smtp_host', 'smtp.qq.com', 'SMTP服务器地址'],
            ['smtp_port', '465', 'SMTP服务器端口'],
            ['smtp_username', '邮箱', 'SMTP用户名（发件邮箱）'],
            ['smtp_password', '授权码', 'SMTP密码或授权码'],
            ['smtp_secure', 'ssl', 'SMTP安全连接类型（ssl/tls）'],
            ['smtp_from_name', '六趣DNS', '发件人显示名称'],
            ['smtp_debug', '0', 'SMTP调试模式（0-3）']
        ];
        foreach ($default_settings as $setting) {
            $key = $setting[0];
            $exists = $this->db->querySingle("SELECT COUNT(*) FROM settings WHERE setting_key = '".$this->db->escapeString($key)."'");
            if (!$exists) {
                $stmt = $this->db->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
                $stmt->bindValue(1, $setting[0], SQLITE3_TEXT);
                $stmt->bindValue(2, $setting[1], SQLITE3_TEXT);
                $stmt->bindValue(3, $setting[2], SQLITE3_TEXT);
                $stmt->execute();
            }
        }
    }

    private function insertDefaultDNSTypes() {
        $default_types = [
            ['A', 'IPv4地址记录', 1],
            ['AAAA', 'IPv6地址记录', 1],
            ['CNAME', '别名记录', 1],
            ['MX', '邮件交换记录', 1],
            ['TXT', '文本记录', 1],
            ['NS', '名称服务器记录', 0],
            ['PTR', '反向解析记录', 0],
            ['SRV', '服务记录', 0],
            ['CAA', '证书颁发机构授权记录', 0]
        ];
        foreach ($default_types as $type) {
            $exists = $this->db->querySingle("SELECT COUNT(*) FROM dns_record_types WHERE type_name = '".$this->db->escapeString($type[0])."'");
            if (!$exists) {
                $stmt = $this->db->prepare("INSERT INTO dns_record_types (type_name, description, enabled) VALUES (?, ?, ?)");
                $stmt->bindValue(1, $type[0], SQLITE3_TEXT);
                $stmt->bindValue(2, $type[1], SQLITE3_TEXT);
                $stmt->bindValue(3, $type[2], SQLITE3_INTEGER);
                $stmt->execute();
            }
        }
    }

    private function insertDefaultUserGroups() {
        $default_groups = [
            ['default', '默认组', 1, '普通用户，基础权限', 0, 0, 100],
            ['vip', 'VIP组', 1, 'VIP用户，享受更多域名权限', 10, 0, 500],
            ['svip', 'SVIP组', 0, '超级VIP用户，免积分解析，全域名权限', 20, 1, -1]
        ];
        foreach ($default_groups as $group) {
            $exists = $this->db->querySingle("SELECT COUNT(*) FROM user_groups WHERE group_name = '".$this->db->escapeString($group[0])."'");
            if (!$exists) {
                $stmt = $this->db->prepare("INSERT INTO user_groups (group_name, display_name, points_per_record, description, priority, can_access_all_domains, max_records) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bindValue(1, $group[0], SQLITE3_TEXT);
                $stmt->bindValue(2, $group[1], SQLITE3_TEXT);
                $stmt->bindValue(3, $group[2], SQLITE3_INTEGER);
                $stmt->bindValue(4, $group[3], SQLITE3_TEXT);
                $stmt->bindValue(5, $group[4], SQLITE3_INTEGER);
                $stmt->bindValue(6, $group[5], SQLITE3_INTEGER);
                $stmt->bindValue(7, $group[6], SQLITE3_INTEGER);
                $stmt->execute();
            }
        }
    }
}

// 保持外部调用语义（占位实现，以免其它文件直接调用出错）
function migrateDatabase() { return true; }
function repairDatabase() { return true; }
function autoUpgradeDatabase() { return true; }
function autoUpgradeOnInstall() { return true; }
