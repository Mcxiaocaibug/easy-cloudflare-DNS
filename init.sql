-- Cloudflare DNS 管理系统 - MySQL 初始化脚本
-- 此文件由 docker-compose 自动执行，用于创建初始数据库

-- 设置字符集
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 数据库将由 docker-compose 自动创建，这里只需要确保使用正确的字符集
ALTER DATABASE cloudflare_dns CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 所有表的创建将由应用程序的 Database 类自动完成
-- 此文件仅用于初始化数据库连接和字符集设置

SET FOREIGN_KEY_CHECKS = 1;

