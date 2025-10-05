<?php
/**
 * MySQL 配置示例
 * 复制为 .env 或设置为环境变量：
 *
 * MYSQL_DSN="mysql:host=127.0.0.1;port=3306;dbname=cloudflare_dns;charset=utf8mb4"
 * MYSQL_USER="root"
 * MYSQL_PASSWORD="password"
 *
 * 或 URL 格式：
 * MYSQL_DSN="mysql://root:password@127.0.0.1:3306/cloudflare_dns?charset=utf8mb4"
 *
 * Aiven TLS 示例：
 * MYSQL_DSN="mysql://user:pass@host:port/db?charset=utf8mb4&ssl_ca=/certs/ca.pem&ssl_verify=1"
 */

// 本文件仅作示例，实际运行请使用 config/database.php（已内置环境变量读取）

require_once __DIR__ . '/database.php';

// 使用：Database::getInstance()->getConnection();
