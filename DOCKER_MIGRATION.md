# Docker 容器化迁移说明

## 📋 概述

本项目已成功实现 Docker 容器化，从 SQLite 数据库迁移到 MySQL 数据库，并支持通过环境变量进行所有配置。

## 🎯 主要改进

### 1. **Docker 容器化**
- ✅ 创建 `Dockerfile` - PHP 8.1 + Apache 环境
- ✅ 创建 `docker-compose.yml` - 多服务编排
- ✅ 创建 `docker-entrypoint.sh` - 自动初始化脚本
- ✅ 创建 `.dockerignore` - 优化镜像大小
- ✅ 创建 `.htaccess` - Apache 安全配置

### 2. **数据库迁移**
- ✅ 从 SQLite3 迁移到 MySQL 8.0
- ✅ 完整的 MySQL 表结构定义
- ✅ 自动创建所有必需的表和索引
- ✅ 支持外部 MySQL 数据库连接
- ✅ 数据持久化（Docker Volumes）

### 3. **环境变量配置**
- ✅ 所有配置通过环境变量管理
- ✅ 创建 `env.example` 配置模板
- ✅ 支持以下配置项：
  - 数据库连接信息
  - 管理员账户信息
  - 系统基础配置
  - 邀请系统配置
  - SMTP 邮件配置

### 4. **自动化部署**
- ✅ 创建 `start.sh` 一键启动脚本
- ✅ 自动检测 Docker 环境
- ✅ 自动创建配置文件
- ✅ 自动初始化数据库
- ✅ 自动创建管理员账户

### 5. **文档完善**
- ✅ 创建 `README.Docker.md` - Docker 部署完整指南
- ✅ 更新主 `README.md` - 添加 Docker 部署说明
- ✅ 创建 `DOCKER_MIGRATION.md` - 本迁移说明文档

## 📁 新增文件清单

```
cloudflare-DNS-main/
├── Dockerfile                 # Docker 镜像定义
├── docker-compose.yml         # Docker Compose 配置
├── docker-entrypoint.sh       # 容器启动脚本
├── env.example                # 环境变量配置模板
├── init.sql                   # MySQL 初始化脚本
├── start.sh                   # 快速启动脚本
├── .dockerignore              # Docker 构建忽略文件
├── .htaccess                  # Apache 配置
├── README.Docker.md           # Docker 部署文档
└── DOCKER_MIGRATION.md        # 本文件
```

## 🔄 数据库配置变化

### 旧配置 (SQLite)
```php
// config/database.php
$db_file = __DIR__ . '/../data/cloudflare_dns.db';
$this->db = new SQLite3($db_file);
```

### 新配置 (MySQL)
```php
// config/database.php (Docker 环境)
$dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset={$db_charset}";
$this->conn = new PDO($dsn, $db_user, $db_password, [...]);
```

## 🚀 快速开始

### 方式 1: 使用一键启动脚本（推荐）

```bash
# 1. 克隆项目
git clone https://github.com/976853694/cloudflare-DNS.git
cd cloudflare-DNS

# 2. 运行启动脚本
./start.sh
```

### 方式 2: 手动启动

```bash
# 1. 克隆项目
git clone https://github.com/976853694/cloudflare-DNS.git
cd cloudflare-DNS

# 2. 复制环境配置
cp env.example .env

# 3. 编辑配置（可选）
nano .env

# 4. 启动服务
docker-compose up -d

# 5. 查看日志
docker-compose logs -f
```

## 🌐 环境变量配置

### 核心环境变量

| 变量名 | 默认值 | 说明 |
|--------|--------|------|
| `DB_HOST` | `mysql` | 数据库主机 |
| `DB_PORT` | `3306` | 数据库端口 |
| `DB_NAME` | `cloudflare_dns` | 数据库名称 |
| `DB_USER` | `cloudflare` | 数据库用户 |
| `DB_PASSWORD` | `cloudflare_password_123` | 数据库密码 |
| `ADMIN_USERNAME` | `admin` | 管理员用户名 |
| `ADMIN_PASSWORD` | `admin123456` | 管理员密码 |
| `APP_PORT` | `8080` | 应用端口 |
| `AUTO_INSTALL` | `1` | 自动安装 |

### SMTP 配置示例

#### QQ 邮箱
```env
SMTP_ENABLED=1
SMTP_HOST=smtp.qq.com
SMTP_PORT=465
SMTP_USERNAME=your_email@qq.com
SMTP_PASSWORD=your_authorization_code
SMTP_SECURE=ssl
```

#### Gmail
```env
SMTP_ENABLED=1
SMTP_HOST=smtp.gmail.com
SMTP_PORT=465
SMTP_USERNAME=your_email@gmail.com
SMTP_PASSWORD=your_app_password
SMTP_SECURE=ssl
```

## 🔌 使用外部 MySQL 数据库

### 1. 修改 docker-compose.yml

注释或删除 `mysql` 服务：

```yaml
services:
  # mysql:  # 注释掉内置 MySQL
  #   ...
  
  app:
    # ...
```

### 2. 配置环境变量

```env
DB_TYPE=external_mysql
DB_HOST=your-external-mysql.com
DB_PORT=3306
DB_NAME=cloudflare_dns
DB_USER=your_db_user
DB_PASSWORD=your_db_password
```

### 3. 启动应用

```bash
docker-compose up -d app
```

## 📊 数据库表结构

系统会自动创建以下 MySQL 表：

| 表名 | 说明 |
|------|------|
| `users` | 用户表 |
| `admins` | 管理员表 |
| `domains` | 域名表 |
| `dns_records` | DNS 记录表 |
| `settings` | 系统设置表 |
| `card_keys` | 卡密表 |
| `card_key_usage` | 卡密使用记录 |
| `action_logs` | 操作日志 |
| `dns_record_types` | DNS 记录类型 |
| `invitations` | 邀请记录 |
| `invitation_uses` | 邀请使用记录 |
| `announcements` | 公告表 |
| `user_announcement_views` | 用户公告查看记录 |
| `blocked_prefixes` | 禁用前缀表 |
| `login_attempts` | 登录尝试记录 |
| `user_groups` | 用户组表 |
| `user_group_domains` | 用户组域名权限表 |
| `cloudflare_accounts` | Cloudflare 账户表 |
| `rainbow_accounts` | 彩虹 DNS 账户表 |
| `database_versions` | 数据库版本表 |

## 🛠️ 常用操作

### 查看服务状态
```bash
docker-compose ps
```

### 查看日志
```bash
# 所有服务日志
docker-compose logs -f

# 应用日志
docker-compose logs -f app

# MySQL 日志
docker-compose logs -f mysql
```

### 重启服务
```bash
docker-compose restart
```

### 停止服务
```bash
docker-compose stop
```

### 完全清理（删除所有数据）
```bash
docker-compose down -v
```

### 备份数据库
```bash
# 导出数据库
docker-compose exec mysql mysqldump -u root -p$DB_ROOT_PASSWORD cloudflare_dns > backup.sql

# 恢复数据库
docker-compose exec -T mysql mysql -u root -p$DB_ROOT_PASSWORD cloudflare_dns < backup.sql
```

### 进入容器
```bash
# 进入应用容器
docker-compose exec app bash

# 进入 MySQL 容器
docker-compose exec mysql bash

# 连接 MySQL 数据库
docker-compose exec mysql mysql -u cloudflare -p
```

## 🔐 安全建议

1. **修改默认密码**
   - 在生产环境中务必修改 `.env` 中的所有默认密码
   
2. **使用强密码**
   ```bash
   # 生成随机密码
   openssl rand -base64 32
   ```

3. **限制端口访问**
   - 在防火墙中限制数据库端口（3306）的访问
   - 只暴露必要的应用端口（8080）

4. **启用 HTTPS**
   - 在生产环境使用 Nginx 反向代理并配置 SSL 证书

5. **定期备份**
   - 设置定时任务定期备份数据库

6. **更新镜像**
   - 定期更新 Docker 镜像以获取安全补丁

## 📈 性能优化

### MySQL 配置优化

在 `docker-compose.yml` 中添加 MySQL 配置：

```yaml
mysql:
  command:
    - --default-authentication-plugin=mysql_native_password
    - --character-set-server=utf8mb4
    - --collation-server=utf8mb4_unicode_ci
    - --max_connections=1000
    - --innodb_buffer_pool_size=1G
```

### PHP 配置优化

在 `Dockerfile` 中调整 PHP 配置：

```dockerfile
RUN echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "max_execution_time = 600" >> /usr/local/etc/php/conf.d/uploads.ini
```

## ❓ 常见问题

### 1. 无法连接到 MySQL

**解决方案**:
- 检查 MySQL 容器是否正在运行: `docker-compose ps`
- 查看 MySQL 日志: `docker-compose logs mysql`
- 确认环境变量配置正确

### 2. 端口已被占用

**解决方案**:
- 修改 `.env` 文件中的 `APP_PORT` 或 `DB_PORT`
- 或停止占用端口的服务

### 3. 权限问题

**解决方案**:
```bash
docker-compose exec app chown -R www-data:www-data /var/www/html/data
docker-compose exec app chmod -R 777 /var/www/html/data
```

### 4. 数据丢失

**预防措施**:
- 使用 Docker Volumes 持久化数据
- 定期备份数据库
- 不要使用 `docker-compose down -v` 除非确定要删除所有数据

## 📚 相关文档

- [README.md](./README.md) - 项目主文档
- [README.Docker.md](./README.Docker.md) - Docker 部署详细指南
- [env.example](./env.example) - 环境变量配置模板

## 🤝 贡献

如果您在使用过程中遇到问题或有改进建议，欢迎：

1. 提交 Issue: https://github.com/976853694/cloudflare-DNS/issues
2. 提交 Pull Request
3. 加入 QQ 交流群: 1044379774

## 📄 许可证

本项目采用非商业许可证，仅供学习和个人使用，禁止商业用途。

---

**Made with ❤️ by 六趣M**

感谢您的使用和支持！

