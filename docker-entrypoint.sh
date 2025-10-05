#!/bin/bash
set -e

echo "=========================================="
echo "Cloudflare DNS 管理系统启动中..."
echo "=========================================="

# 解析数据库连接信息（支持 DATABASE_URL 或分离变量）
if [ -n "${DATABASE_URL}" ]; then
    echo "使用 DATABASE_URL 配置数据库连接"
    # 从 DATABASE_URL 解析连接信息
    # 格式: mysql://username:password@host:port/database
    DB_USER=$(echo $DATABASE_URL | sed -n 's|.*://\([^:]*\):.*|\1|p')
    DB_PASSWORD=$(echo $DATABASE_URL | sed -n 's|.*://[^:]*:\([^@]*\)@.*|\1|p')
    DB_HOST=$(echo $DATABASE_URL | sed -n 's|.*@\([^:]*\):.*|\1|p')
    DB_PORT=$(echo $DATABASE_URL | sed -n 's|.*:\([0-9]*\)/.*|\1|p')
    DB_NAME=$(echo $DATABASE_URL | sed -n 's|.*/\([^?]*\).*|\1|p')
    
    echo "  数据库主机: ${DB_HOST}"
    echo "  数据库端口: ${DB_PORT}"
    echo "  数据库名称: ${DB_NAME}"
    echo "  数据库用户: ${DB_USER}"
fi

# 等待MySQL数据库就绪
if [ "${DB_TYPE}" = "mysql" ] || [ "${DB_TYPE}" = "external_mysql" ] || [ -n "${DATABASE_URL}" ]; then
    echo "等待MySQL数据库就绪..."
    
    max_attempts=30
    attempt=0
    
    until mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASSWORD}" -e "SELECT 1" >/dev/null 2>&1; do
        attempt=$((attempt + 1))
        if [ $attempt -ge $max_attempts ]; then
            echo "错误: 无法连接到MySQL数据库"
            exit 1
        fi
        echo "等待MySQL连接... ($attempt/$max_attempts)"
        sleep 2
    done
    
    echo "✓ MySQL数据库连接成功"
fi

# 创建数据目录
mkdir -p /var/www/html/data
chown -R www-data:www-data /var/www/html/data
chmod -R 777 /var/www/html/data

# 生成数据库配置文件
echo "生成数据库配置文件..."
cat > /var/www/html/config/database.php << 'EOFDB'
<?php
/**
 * MySQL数据库配置文件（Docker环境）
 * 支持 DATABASE_URL (DSN格式) 或分离的环境变量
 */

class Database {
    private static $instance = null;
    private $conn;
    
    private function __construct() {
        // 优先使用 DATABASE_URL (DSN格式)
        $database_url = getenv('DATABASE_URL');
        
        if ($database_url) {
            // 解析 DATABASE_URL
            // 格式: mysql://username:password@host:port/database
            $parsed = parse_url($database_url);
            
            $db_host = $parsed['host'] ?? 'mysql';
            $db_port = $parsed['port'] ?? 3306;
            $db_name = ltrim($parsed['path'] ?? '/cloudflare_dns', '/');
            $db_user = $parsed['user'] ?? 'cloudflare';
            $db_password = $parsed['pass'] ?? '';
            $db_charset = 'utf8mb4';
            
            // 支持查询参数中的字符集
            if (isset($parsed['query'])) {
                parse_str($parsed['query'], $query_params);
                if (isset($query_params['charset'])) {
                    $db_charset = $query_params['charset'];
                }
            }
        } else {
            // 使用分离的环境变量
            $db_type = getenv('DB_TYPE') ?: 'mysql';
            $db_host = getenv('DB_HOST') ?: 'mysql';
            $db_port = getenv('DB_PORT') ?: '3306';
            $db_name = getenv('DB_NAME') ?: 'cloudflare_dns';
            $db_user = getenv('DB_USER') ?: 'cloudflare';
            $db_password = getenv('DB_PASSWORD') ?: 'cloudflare_password_123';
            $db_charset = getenv('DB_CHARSET') ?: 'utf8mb4';
        }
        
        try {
            $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset={$db_charset}";
            $this->conn = new PDO($dsn, $db_user, $db_password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$db_charset}"
            ]);
            
            $this->initTables();
            
            // 自动执行数据库升级（安装时）
            if (function_exists('autoUpgradeOnInstall')) {
                autoUpgradeOnInstall();
            }
        } catch (PDOException $e) {
            error_log("数据库连接失败: " . $e->getMessage());
            throw new Exception("数据库连接失败: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->conn;
    }
    
    private function initTables() {
        // 创建用户表
        $this->conn->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(255),
            points INT DEFAULT 100,
            status INT DEFAULT 1,
            group_id INT DEFAULT 1,
            group_changed_at TIMESTAMP NULL DEFAULT NULL,
            group_changed_by INT DEFAULT NULL,
            github_id VARCHAR(255),
            github_username VARCHAR(255),
            avatar_url TEXT,
            oauth_provider VARCHAR(50),
            github_bonus_received INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_group_id (group_id),
            INDEX idx_github_id (github_id),
            INDEX idx_oauth_provider (oauth_provider)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 创建管理员表
        $this->conn->exec("CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 创建域名表
        $this->conn->exec("CREATE TABLE IF NOT EXISTS domains (
            id INT AUTO_INCREMENT PRIMARY KEY,
            domain_name VARCHAR(255) NOT NULL UNIQUE,
            api_key TEXT NOT NULL,
            email VARCHAR(255) NOT NULL,
            zone_id VARCHAR(255) NOT NULL,
            proxied_default BOOLEAN DEFAULT 1,
            status INT DEFAULT 1,
            provider_type VARCHAR(50) DEFAULT 'cloudflare',
            provider_uid VARCHAR(255) DEFAULT '',
            api_base_url VARCHAR(500) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_provider_type (provider_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 创建DNS记录表
        $this->conn->exec("CREATE TABLE IF NOT EXISTS dns_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            domain_id INT,
            subdomain VARCHAR(255) NOT NULL,
            type VARCHAR(50) NOT NULL,
            content TEXT NOT NULL,
            proxied INT DEFAULT 0,
            cloudflare_id VARCHAR(255),
            status INT DEFAULT 1,
            is_system INT DEFAULT 0,
            remark TEXT,
            ttl INT DEFAULT 1,
            priority INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
            INDEX idx_domain_id (domain_id),
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 创建系统设置表
        $this->conn->exec("CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(255) NOT NULL UNIQUE,
            setting_value TEXT,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 创建卡密表
        $this->conn->exec("CREATE TABLE IF NOT EXISTS card_keys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            card_key VARCHAR(255) NOT NULL UNIQUE,
            points INT NOT NULL,
            max_uses INT DEFAULT 1,
            used_count INT DEFAULT 0,
            status INT DEFAULT 1,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 创建卡密使用记录表
        $this->conn->exec("CREATE TABLE IF NOT EXISTS card_key_usage (
            id INT AUTO_INCREMENT PRIMARY KEY,
            card_key_id INT,
            user_id INT,
            points_added INT,
            used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (card_key_id) REFERENCES card_keys(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 创建操作日志表
        $this->conn->exec("CREATE TABLE IF NOT EXISTS action_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_type VARCHAR(50) NOT NULL,
            user_id INT NOT NULL,
            action VARCHAR(255) NOT NULL,
            details TEXT,
            ip_address VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 创建DNS记录类型表
        $this->conn->exec("CREATE TABLE IF NOT EXISTS dns_record_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type_name VARCHAR(50) NOT NULL UNIQUE,
            description TEXT,
            enabled INT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 创建邀请记录表
        $this->conn->exec("CREATE TABLE IF NOT EXISTS invitations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            inviter_id INT NOT NULL,
            invitation_code VARCHAR(255) NOT NULL UNIQUE,
            reward_points INT DEFAULT 0,
            use_count INT DEFAULT 0,
            total_rewards INT DEFAULT 0,
            is_active INT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_used_at TIMESTAMP NULL DEFAULT NULL,
            FOREIGN KEY (inviter_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 创建邀请使用记录表
        $this->conn->exec("CREATE TABLE IF NOT EXISTS invitation_uses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invitation_id INT NOT NULL,
            invitee_id INT NOT NULL,
            reward_points INT DEFAULT 0,
            used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (invitation_id) REFERENCES invitations(id) ON DELETE CASCADE,
            FOREIGN KEY (invitee_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 创建公告表
        $this->conn->exec("CREATE TABLE IF NOT EXISTS announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            type VARCHAR(50) DEFAULT 'info',
            is_active INT DEFAULT 1,
            show_frequency VARCHAR(50) DEFAULT 'once',
            interval_hours INT DEFAULT 24,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 创建用户公告查看记录表
        $this->conn->exec("CREATE TABLE IF NOT EXISTS user_announcement_views (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            announcement_id INT NOT NULL,
            last_viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            view_count INT DEFAULT 1,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
            UNIQUE KEY unique_user_announcement (user_id, announcement_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 创建禁用前缀表
        $this->conn->exec("CREATE TABLE IF NOT EXISTS blocked_prefixes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            prefix VARCHAR(255) NOT NULL UNIQUE,
            description TEXT,
            is_active INT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 创建登录尝试记录表
        $this->conn->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(100) NOT NULL,
            username VARCHAR(255) NOT NULL,
            type VARCHAR(50) NOT NULL,
            attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            success INT DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 创建用户组表
        $this->conn->exec("CREATE TABLE IF NOT EXISTS user_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_name VARCHAR(255) NOT NULL UNIQUE,
            display_name VARCHAR(255) NOT NULL,
            points_per_record INT DEFAULT 1,
            description TEXT,
            is_active INT DEFAULT 1,
            can_access_all_domains INT DEFAULT 0,
            max_records INT DEFAULT -1,
            priority INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 创建用户组域名权限表
        $this->conn->exec("CREATE TABLE IF NOT EXISTS user_group_domains (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id INT NOT NULL,
            domain_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE,
            FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
            UNIQUE KEY unique_group_domain (group_id, domain_id),
            INDEX idx_group_id (group_id),
            INDEX idx_domain_id (domain_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 创建Cloudflare账户表
        $this->conn->exec("CREATE TABLE IF NOT EXISTS cloudflare_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_name VARCHAR(255) NOT NULL,
            api_token TEXT NOT NULL,
            email VARCHAR(255),
            status INT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 创建彩虹DNS账户表
        $this->conn->exec("CREATE TABLE IF NOT EXISTS rainbow_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_name VARCHAR(255) NOT NULL,
            api_key TEXT NOT NULL,
            uid VARCHAR(255) NOT NULL,
            api_base_url VARCHAR(500),
            status INT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 创建版本表
        $this->conn->exec("CREATE TABLE IF NOT EXISTS database_versions (
            version VARCHAR(50) PRIMARY KEY,
            upgraded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 插入默认设置
        $this->insertDefaultSettings();
        
        // 插入默认DNS记录类型
        $this->insertDefaultDNSTypes();
        
        // 插入默认用户组
        $this->insertDefaultUserGroups();
    }
    
    private function insertDefaultSettings() {
        $default_settings = [
            ['points_per_record', getenv('POINTS_PER_RECORD') ?: '1', '每条DNS记录消耗积分'],
            ['default_user_points', getenv('DEFAULT_USER_POINTS') ?: '100', '新用户默认积分'],
            ['site_name', getenv('SITE_NAME') ?: 'Cloudflare DNS管理系统', '网站名称'],
            ['allow_registration', getenv('ALLOW_REGISTRATION') ?: '1', '是否允许用户注册'],
            ['invitation_enabled', getenv('INVITATION_ENABLED') ?: '1', '是否启用邀请系统'],
            ['invitation_reward_points', getenv('INVITATION_REWARD_POINTS') ?: '10', '邀请成功奖励积分'],
            ['invitee_bonus_points', getenv('INVITEE_BONUS_POINTS') ?: '5', '被邀请用户额外积分'],
            ['smtp_enabled', getenv('SMTP_ENABLED') ?: '0', '是否启用SMTP邮件发送'],
            ['smtp_host', getenv('SMTP_HOST') ?: 'smtp.example.com', 'SMTP服务器地址'],
            ['smtp_port', getenv('SMTP_PORT') ?: '465', 'SMTP服务器端口'],
            ['smtp_username', getenv('SMTP_USERNAME') ?: '', 'SMTP用户名'],
            ['smtp_password', getenv('SMTP_PASSWORD') ?: '', 'SMTP密码或授权码'],
            ['smtp_secure', getenv('SMTP_SECURE') ?: 'ssl', 'SMTP安全连接类型'],
            ['smtp_from_name', getenv('SMTP_FROM_NAME') ?: '六趣DNS', '发件人显示名称'],
            ['smtp_debug', getenv('SMTP_DEBUG') ?: '0', 'SMTP调试模式']
        ];
        
        foreach ($default_settings as $setting) {
            $stmt = $this->conn->prepare("INSERT IGNORE INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
            $stmt->execute($setting);
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
            $stmt = $this->conn->prepare("INSERT IGNORE INTO dns_record_types (type_name, description, enabled) VALUES (?, ?, ?)");
            $stmt->execute($type);
        }
    }
    
    private function insertDefaultUserGroups() {
        $default_groups = [
            ['default', '默认组', 1, '普通用户，基础权限', 1, 0, 100, 0],
            ['vip', 'VIP组', 1, 'VIP用户，享受更多域名权限', 1, 0, 500, 10],
            ['svip', 'SVIP组', 0, '超级VIP用户，免积分解析，全域名权限', 1, 1, -1, 20]
        ];
        
        foreach ($default_groups as $group) {
            $stmt = $this->conn->prepare("
                INSERT IGNORE INTO user_groups 
                (group_name, display_name, points_per_record, description, is_active, can_access_all_domains, max_records, priority) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute($group);
        }
    }
}

/**
 * 自动数据库升级
 */
function autoUpgradeOnInstall() {
    // 占位函数，用于兼容性
}
EOFDB

echo "✓ 数据库配置文件生成成功"

# 自动安装（如果启用）
if [ "${AUTO_INSTALL}" = "1" ] && [ ! -f "/var/www/html/data/install.lock" ]; then
    echo "开始自动安装..."
    
    # 等待数据库表创建完成
    sleep 3
    
    # 创建管理员账户
    mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" <<-EOSQL
        DELETE FROM admins WHERE username = 'admin';
        INSERT INTO admins (username, password, email) 
        VALUES (
            '${ADMIN_USERNAME}', 
            '$(php -r "echo password_hash('${ADMIN_PASSWORD}', PASSWORD_DEFAULT);")', 
            '${ADMIN_EMAIL}'
        );
EOSQL
    
    # 创建安装锁定文件
    touch /var/www/html/data/install.lock
    echo "$(date '+%Y-%m-%d %H:%M:%S')" > /var/www/html/data/install.lock
    
    echo "✓ 自动安装完成"
    echo "  管理员用户名: ${ADMIN_USERNAME}"
    echo "  管理员密码: ${ADMIN_PASSWORD}"
    echo "  管理员邮箱: ${ADMIN_EMAIL}"
fi

echo "=========================================="
echo "✓ 启动完成！"
echo "=========================================="
echo "访问地址: http://localhost:${APP_PORT:-8080}"
echo "管理后台: http://localhost:${APP_PORT:-8080}/admin/login.php"
echo "用户前台: http://localhost:${APP_PORT:-8080}/user/login.php"
echo "=========================================="

# 执行原始命令
exec "$@"

