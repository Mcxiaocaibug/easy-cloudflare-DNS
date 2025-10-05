<?php
/**
 * 安全防护功能类
 */

class Security {
    private static $max_attempts = 5; // 最大尝试次数
    private static $lockout_time = 900; // 锁定时间（15分钟）
    
    /**
     * 记录登录失败
     */
    public static function recordFailedLogin($ip, $username, $type = 'user') {
        $db = Database::getInstance()->getConnection();
        
        // 创建登录失败记录表
        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL,
            username TEXT NOT NULL,
            type TEXT NOT NULL,
            attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        // 记录失败尝试
        $stmt = $db->prepare("INSERT INTO login_attempts (ip_address, username, type) VALUES (?, ?, ?)");
        $stmt->bindValue(1, $ip, SQLITE3_TEXT);
        $stmt->bindValue(2, $username, SQLITE3_TEXT);
        $stmt->bindValue(3, $type, SQLITE3_TEXT);
        $stmt->execute();
        
        // 清理过期记录（保留24小时内的记录）
        $db->exec("DELETE FROM login_attempts WHERE attempt_time < datetime('now', '-24 hours')");
    }
    
    /**
     * 检查IP是否被锁定
     */
    public static function isIpLocked($ip, $type = 'user') {
        $db = Database::getInstance()->getConnection();
        
        // 检查表是否存在
        $table_exists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='login_attempts'");
        if (!$table_exists) {
            return false;
        }
        
        // 计算锁定时间点
        $lockout_start = date('Y-m-d H:i:s', time() - self::$lockout_time);
        
        // 查询指定时间内的失败次数
        $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND type = ? AND attempt_time > ?");
        $stmt->bindValue(1, $ip, SQLITE3_TEXT);
        $stmt->bindValue(2, $type, SQLITE3_TEXT);
        $stmt->bindValue(3, $lockout_start, SQLITE3_TEXT);
        $result = $stmt->execute();
        $count = $result->fetchArray(SQLITE3_NUM)[0];
        
        return $count >= self::$max_attempts;
    }
    
    /**
     * 获取剩余锁定时间
     */
    public static function getRemainingLockTime($ip, $type = 'user') {
        $db = Database::getInstance()->getConnection();
        
        // 检查表是否存在
        $table_exists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='login_attempts'");
        if (!$table_exists) {
            return 0;
        }
        
        // 获取最近一次失败尝试的时间
        $stmt = $db->prepare("SELECT attempt_time FROM login_attempts WHERE ip_address = ? AND type = ? ORDER BY attempt_time DESC LIMIT 1");
        $stmt->bindValue(1, $ip, SQLITE3_TEXT);
        $stmt->bindValue(2, $type, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$row) {
            return 0;
        }
        
        $last_attempt = strtotime($row['attempt_time']);
        $unlock_time = $last_attempt + self::$lockout_time;
        $remaining = $unlock_time - time();
        
        return max(0, $remaining);
    }
    
    /**
     * 清除IP的失败记录（登录成功后调用）
     */
    public static function clearFailedAttempts($ip, $type = 'user') {
        $db = Database::getInstance()->getConnection();
        
        // 检查表是否存在
        $table_exists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='login_attempts'");
        if (!$table_exists) {
            return;
        }
        
        $stmt = $db->prepare("DELETE FROM login_attempts WHERE ip_address = ? AND type = ?");
        $stmt->bindValue(1, $ip, SQLITE3_TEXT);
        $stmt->bindValue(2, $type, SQLITE3_TEXT);
        $stmt->execute();
    }
    
    /**
     * 格式化剩余时间
     */
    public static function formatRemainingTime($seconds) {
        if ($seconds <= 0) {
            return '0秒';
        }
        
        $minutes = floor($seconds / 60);
        $seconds = $seconds % 60;
        
        if ($minutes > 0) {
            return $minutes . '分' . $seconds . '秒';
        } else {
            return $seconds . '秒';
        }
    }
}
?>