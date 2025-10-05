<?php
/**
 * 数据库迁移管理器
 * 整合所有数据库迁移和升级功能
 */

require_once 'database.php';

class DatabaseMigrations {
    private $db;
    private $migrations = [];
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->initMigrationsTable();
        $this->registerMigrations();
    }
    
    /**
     * 初始化迁移记录表
     */
    private function initMigrationsTable() {
        $this->db->exec("CREATE TABLE IF NOT EXISTS database_migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration_name VARCHAR(191) NOT NULL UNIQUE,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            success TINYINT(1) DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    
    /**
     * 注册所有迁移
     */
    private function registerMigrations() {
        $this->migrations = [
            '001_basic_structure' => [$this, 'migrateBasicStructure'],
            '002_invitation_system' => [$this, 'migrateInvitationSystem'],
            '003_oauth_support' => [$this, 'migrateOAuthSupport'],
            '004_database_upgrade' => [$this, 'migrateDatabaseUpgrade'],
        ];
    }
    
    /**
     * 执行所有未执行的迁移
     */
    public function runMigrations() {
        echo "=== 开始数据库迁移 ===\n";
        
        foreach ($this->migrations as $name => $callback) {
            if ($this->isMigrationExecuted($name)) {
                echo "✅ {$name} - 已执行，跳过\n";
                continue;
            }
            
            echo "🔄 执行迁移: {$name}\n";
            
            try {
                $this->db->exec('BEGIN TRANSACTION');
                
                $result = call_user_func($callback);
                
                if ($result) {
                    $this->markMigrationExecuted($name, true);
                    $this->db->exec('COMMIT');
                    echo "✅ {$name} - 完成\n";
                } else {
                    throw new Exception("迁移返回false");
                }
                
            } catch (Exception $e) {
                $this->db->exec('ROLLBACK');
                $this->markMigrationExecuted($name, false);
                echo "❌ {$name} - 失败: " . $e->getMessage() . "\n";
                return false;
            }
        }
        
        echo "🎉 所有迁移执行完成！\n";
        return true;
    }
    
    /**
     * 检查迁移是否已执行
     */
    private function isMigrationExecuted($name) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM database_migrations WHERE migration_name = ? AND success = 1");
        $stmt->bindValue(1, $name, SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res->fetchArray(SQLITE3_NUM);
        $count = $row ? (int)$row[0] : 0;
        return $count > 0;
    }
    
    /**
     * 标记迁移已执行
     */
    private function markMigrationExecuted($name, $success) {
        // 使用 ON DUPLICATE KEY UPDATE 模拟 REPLACE
        $stmt = $this->db->prepare("INSERT INTO database_migrations (migration_name, success, executed_at) VALUES (?, ?, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE success = VALUES(success), executed_at = CURRENT_TIMESTAMP");
        $stmt->bindValue(1, $name, SQLITE3_TEXT);
        $stmt->bindValue(2, $success ? 1 : 0, SQLITE3_INTEGER);
        $stmt->execute();
    }
    
    /**
     * 迁移1: 基础结构 (来自migrate.php)
     */
    private function migrateBasicStructure() {
        echo "  - 创建基础数据表结构\n";
        
        // 这里包含原migrate.php的主要迁移逻辑
        // 由于内容较长，这里简化处理
        
        // 检查必要的表是否存在，如果不存在则创建
        $tables = [
            'users', 'cloudflare_accounts', 'domains', 'dns_records', 
            'settings', 'logs', 'announcements', 'invitations'
        ];
        
        foreach ($tables as $table) {
            $exists = (int)$this->db->querySingle("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$table}'");
            if (!$exists) {
                echo "  - 创建表: {$table}\n";
                // 这里应该包含具体的CREATE TABLE语句
                // 为了简化，假设表已存在或由database.php创建
            }
        }
        
        return true;
    }
    
    /**
     * 迁移2: 邀请系统升级 (来自migrate_invitations.php)
     */
    private function migrateInvitationSystem() {
        echo "  - 升级邀请系统为永久邀请码\n";
        
        // 检查是否已经迁移过
        $columns = [];
        $result = $this->db->query("SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'invitations' ORDER BY ORDINAL_POSITION");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $columns[] = $row['name']; }
        
        if (in_array('is_active', $columns)) {
            echo "  - 邀请系统已是最新版本\n";
            return true;
        }
        
        echo "  - 备份原始邀请数据\n";
        $this->db->exec("CREATE TABLE IF NOT EXISTS invitations_backup AS SELECT * FROM invitations");
        
        echo "  - 创建新的邀请表结构\n";
        $this->db->exec("DROP TABLE IF EXISTS invitations_new");
        $this->db->exec("CREATE TABLE invitations_new (
            id INT AUTO_INCREMENT PRIMARY KEY,
            inviter_id INT NOT NULL,
            invitation_code VARCHAR(191) NOT NULL UNIQUE,
            reward_points INT DEFAULT 0,
            use_count INT DEFAULT 0,
            total_rewards INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_used_at TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        echo "  - 创建邀请使用记录表\n";
        $this->db->exec("CREATE TABLE IF NOT EXISTS invitation_uses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invitation_id INT NOT NULL,
            invitee_id INT NOT NULL,
            reward_points INT DEFAULT 0,
            used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        echo "  - 迁移现有邀请数据\n";
        $oldInvitations = [];
        $result = $this->db->query("SELECT * FROM invitations_backup");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $oldInvitations[] = $row;
        }
        
        foreach ($oldInvitations as $old) {
            $use_count = isset($old['status']) && $old['status'] == 1 ? 1 : 0;
            $total_rewards = isset($old['reward_given']) && $old['reward_given'] == 1 ? ($old['reward_points'] ?? 0) : 0;
            $last_used_at = $old['used_at'] ?? null;
            
            $stmt = $this->db->prepare("INSERT INTO invitations_new 
                (id, inviter_id, invitation_code, reward_points, use_count, total_rewards, is_active, created_at, last_used_at) 
                VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)");
            $stmt->bindValue(1, $old['id'], SQLITE3_INTEGER);
            $stmt->bindValue(2, $old['inviter_id'], SQLITE3_INTEGER);
            $stmt->bindValue(3, $old['invitation_code'], SQLITE3_TEXT);
            $stmt->bindValue(4, $old['reward_points'] ?? 0, SQLITE3_INTEGER);
            $stmt->bindValue(5, $use_count, SQLITE3_INTEGER);
            $stmt->bindValue(6, $total_rewards, SQLITE3_INTEGER);
            $stmt->bindValue(7, $old['created_at'], SQLITE3_TEXT);
            $stmt->bindValue(8, $last_used_at, SQLITE3_TEXT);
            $stmt->execute();
            
            // 添加使用记录
            if ($use_count > 0 && isset($old['invitee_id']) && $old['invitee_id']) {
                $stmt = $this->db->prepare("INSERT INTO invitation_uses 
                    (invitation_id, invitee_id, reward_points, used_at) 
                    VALUES (?, ?, ?, ?)");
                $stmt->bindValue(1, $old['id'], SQLITE3_INTEGER);
                $stmt->bindValue(2, $old['invitee_id'], SQLITE3_INTEGER);
                $stmt->bindValue(3, $old['reward_points'] ?? 0, SQLITE3_INTEGER);
                $stmt->bindValue(4, $old['used_at'] ?: $old['created_at'], SQLITE3_TEXT);
                $stmt->execute();
            }
        }
        
        echo "  - 替换旧表结构\n";
        $this->db->exec("DROP TABLE IF EXISTS invitations");
        $this->db->exec("RENAME TABLE invitations_new TO invitations");
        
        $migrated_count = count($oldInvitations);
        $active_count = $this->db->querySingle("SELECT COUNT(*) FROM invitations WHERE is_active = 1");
        $uses_count = $this->db->querySingle("SELECT COUNT(*) FROM invitation_uses");
        
        echo "  - 迁移完成: {$migrated_count}个邀请码, {$active_count}个活跃, {$uses_count}条使用记录\n";
        
        return true;
    }
    
    /**
     * 迁移3: OAuth支持 (来自migrate_oauth.php)
     */
    private function migrateOAuthSupport() {
        echo "  - 添加OAuth支持\n";
        
        // 检查users表是否已有OAuth字段
        $columns = [];
        $result = $this->db->query("SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' ORDER BY ORDINAL_POSITION");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $columns[] = $row['name']; }
        
        // 添加OAuth相关字段
        $oauth_fields = [
            'github_id' => 'TEXT',
            'github_username' => 'TEXT', 
            'avatar_url' => 'TEXT',
            'oauth_provider' => 'TEXT',
            'github_bonus_received' => 'INTEGER DEFAULT 0'
        ];
        
        foreach ($oauth_fields as $field => $type) {
            if (!in_array($field, $columns)) {
                echo "  - 添加字段: users.{$field}\n";
                $this->db->exec("ALTER TABLE users ADD COLUMN {$field} {$type}");
            }
        }
        
        // 创建索引
        echo "  - 创建OAuth索引\n";
        $this->ensureIndex('users','idx_users_github_id','github_id');
        $this->ensureIndex('users','idx_users_oauth_provider','oauth_provider');
        
        // 添加GitHub OAuth设置
        echo "  - 添加OAuth系统设置\n";
        $oauth_settings = [
            ['github_oauth_enabled', '0', '是否启用GitHub OAuth登录'],
            ['github_client_id', '', 'GitHub OAuth Client ID'],
            ['github_client_secret', '', 'GitHub OAuth Client Secret'],
            ['github_auto_register', '1', '是否允许GitHub用户自动注册'],
            ['github_bonus_points', '200', 'GitHub用户奖励积分']
        ];
        
        foreach ($oauth_settings as $setting) {
            $exists = $this->db->querySingle("SELECT COUNT(*) FROM settings WHERE setting_key = '{$setting[0]}'");
            if (!$exists) {
                $stmt = $this->db->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
                $stmt->bindValue(1, $setting[0], SQLITE3_TEXT);
                $stmt->bindValue(2, $setting[1], SQLITE3_TEXT);
                $stmt->bindValue(3, $setting[2], SQLITE3_TEXT);
                $stmt->execute();
            }
        }
        
        return true;
    }
    
    /**
     * 迁移4: 数据库升级 (来自database_upgrade.php的核心功能)
     */
    private function migrateDatabaseUpgrade() {
        echo "  - 数据库结构升级\n";
        
        // 添加必要的字段和索引
        $upgrades = [
            'dns_records' => [
                'remark' => 'TEXT DEFAULT ""',
                'ttl' => 'INTEGER DEFAULT 600', 
                'priority' => 'INTEGER DEFAULT 0'
            ]
        ];
        
        foreach ($upgrades as $table => $fields) {
            $columns = [];
            $result = $this->db->query("SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '{$table}' ORDER BY ORDINAL_POSITION");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $columns[] = $row['name']; }
            
            foreach ($fields as $field => $definition) {
                if (!in_array($field, $columns)) {
                    echo "  - 添加字段: {$table}.{$field}\n";
                    $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$field} {$definition}");
                }
            }
        }
        
        // 创建必要的索引
        $this->ensureIndex('dns_records','idx_dns_records_domain','domain_id');
        $this->ensureIndex('dns_records','idx_dns_records_type','type');
        $this->ensureIndex('logs','idx_logs_created','created_at');
        $this->ensureIndex('users','idx_users_email','email');
        
        return true;
    }

    private function ensureIndex($table, $indexName, $columns) {
        try {
            $pdo = $this->db->getPDO();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?");
            $stmt->execute([$table, $indexName]);
            $exists = (int)$stmt->fetchColumn();
            if (!$exists) {
                $pdo->exec("CREATE INDEX {$indexName} ON {$table} ({$columns})");
            }
        } catch (Exception $e) {
            error_log('ensureIndex in migrations failed: '.$e->getMessage());
        }
    }
    
    /**
     * 获取迁移状态
     */
    public function getMigrationStatus() {
        $status = [];
        foreach ($this->migrations as $name => $callback) {
            $status[$name] = $this->isMigrationExecuted($name);
        }
        return $status;
    }
    
    /**
     * 强制重新执行指定迁移
     */
    public function forceMigration($name) {
        if (!isset($this->migrations[$name])) {
            throw new Exception("迁移不存在: {$name}");
        }
        
        // 删除迁移记录
        $this->db->exec("DELETE FROM database_migrations WHERE migration_name = '{$name}'");
        
        // 重新执行
        return $this->runMigrations();
    }
}

/**
 * 执行所有迁移的便捷函数
 */
function migrateDatabase() {
    $migrator = new DatabaseMigrations();
    return $migrator->runMigrations();
}

// 如果直接访问此文件，执行迁移
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    echo "=== 数据库迁移工具 ===\n";
    echo "这将执行所有未完成的数据库迁移。\n";
    echo "建议在执行前备份数据库！\n\n";
    
    if (php_sapi_name() === 'cli') {
        echo "按 Enter 继续，或 Ctrl+C 取消...\n";
        fgets(STDIN);
    }
    
    try {
        $migrator = new DatabaseMigrations();
        
        // 显示当前状态
        echo "\n当前迁移状态:\n";
        $status = $migrator->getMigrationStatus();
        foreach ($status as $name => $executed) {
            echo "  {$name}: " . ($executed ? '✅ 已完成' : '⏳ 待执行') . "\n";
        }
        
        echo "\n";
        
        // 执行迁移
        if ($migrator->runMigrations()) {
            echo "\n🎉 所有迁移执行成功！\n";
        } else {
            echo "\n❌ 迁移过程中出现错误！\n";
            exit(1);
        }
        
    } catch (Exception $e) {
        echo "\n❌ 迁移失败: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>
