# Cloudflare DNS 管理系统 - Docker 部署指南

这是 Cloudflare DNS 管理系统的 Docker 容器化版本，支持使用 MySQL 数据库，所有配置通过环境变量进行管理。

## 📋 目录

- [快速开始](#快速开始)
- [环境变量配置](#环境变量配置)
- [使用外部 MySQL 数据库](#使用外部-mysql-数据库)
- [数据持久化](#数据持久化)
- [常用命令](#常用命令)
- [故障排除](#故障排除)

## 🚀 快速开始

### 方式一：使用内置 MySQL 数据库（推荐新手）

1. **克隆项目**
   ```bash
   git clone https://github.com/976853694/cloudflare-DNS.git
   cd cloudflare-DNS
   ```

2. **复制环境变量配置文件**
   ```bash
   cp .env.example .env
   ```

3. **编辑 `.env` 文件（可选）**
   ```bash
   nano .env
   # 或使用其他编辑器修改配置
   ```

4. **启动服务**
   ```bash
   docker-compose up -d
   ```

5. **访问系统**
   - 应用地址: http://localhost:8080
   - 管理后台: http://localhost:8080/admin/login.php
   - 默认管理员账号: admin / admin123456

### 方式二：使用外部 MySQL 数据库

如果您已经有 MySQL 数据库服务，可以不使用内置的 MySQL 容器。

1. **修改 `docker-compose.yml`**
   
   注释或删除 `mysql` 服务部分，只保留 `app` 服务。

2. **配置环境变量**
   
   在 `.env` 文件中配置外部数据库连接：
   ```env
   DB_TYPE=external_mysql
   DB_HOST=your-mysql-host.com
   DB_PORT=3306
   DB_NAME=cloudflare_dns
   DB_USER=your_db_user
   DB_PASSWORD=your_db_password
   ```

3. **启动服务**
   ```bash
   docker-compose up -d app
   ```

## ⚙️ 环境变量配置

所有配置都通过 `.env` 文件进行管理，以下是主要配置项：

### 数据库配置

| 变量名 | 默认值 | 说明 |
|--------|--------|------|
| `DB_TYPE` | `mysql` | 数据库类型（mysql 或 external_mysql） |
| `DB_HOST` | `mysql` | 数据库主机地址 |
| `DB_PORT` | `3306` | 数据库端口 |
| `DB_NAME` | `cloudflare_dns` | 数据库名称 |
| `DB_USER` | `cloudflare` | 数据库用户名 |
| `DB_PASSWORD` | `cloudflare_password_123` | 数据库密码 |
| `DB_ROOT_PASSWORD` | `root_password_123` | 数据库 root 密码（仅内置MySQL） |

### 管理员配置

| 变量名 | 默认值 | 说明 |
|--------|--------|------|
| `ADMIN_USERNAME` | `admin` | 管理员用户名 |
| `ADMIN_PASSWORD` | `admin123456` | 管理员密码 |
| `ADMIN_EMAIL` | `admin@example.com` | 管理员邮箱 |

### 系统配置

| 变量名 | 默认值 | 说明 |
|--------|--------|------|
| `SITE_NAME` | `Cloudflare DNS管理系统` | 网站名称 |
| `POINTS_PER_RECORD` | `1` | 每条DNS记录消耗积分 |
| `DEFAULT_USER_POINTS` | `100` | 新用户默认积分 |
| `ALLOW_REGISTRATION` | `1` | 是否允许用户注册 |

### SMTP 邮件配置

| 变量名 | 默认值 | 说明 |
|--------|--------|------|
| `SMTP_ENABLED` | `0` | 是否启用SMTP |
| `SMTP_HOST` | `smtp.example.com` | SMTP服务器地址 |
| `SMTP_PORT` | `465` | SMTP端口 |
| `SMTP_USERNAME` | - | SMTP用户名 |
| `SMTP_PASSWORD` | - | SMTP密码 |
| `SMTP_SECURE` | `ssl` | 加密方式（ssl/tls） |

### 应用配置

| 变量名 | 默认值 | 说明 |
|--------|--------|------|
| `APP_PORT` | `8080` | 应用对外端口 |
| `AUTO_INSTALL` | `1` | 是否自动安装 |

## 🗄️ 使用外部 MySQL 数据库

### 示例 1：连接到本地 MySQL

```env
DB_TYPE=external_mysql
DB_HOST=host.docker.internal  # Docker for Mac/Windows
# DB_HOST=172.17.0.1          # Docker for Linux
DB_PORT=3306
DB_NAME=cloudflare_dns
DB_USER=cloudflare_user
DB_PASSWORD=your_secure_password
```

### 示例 2：连接到远程 MySQL 服务器

```env
DB_TYPE=external_mysql
DB_HOST=mysql.example.com
DB_PORT=3306
DB_NAME=cloudflare_dns
DB_USER=cloudflare_user
DB_PASSWORD=your_secure_password
```

### 示例 3：连接到云数据库（如阿里云RDS）

```env
DB_TYPE=external_mysql
DB_HOST=rm-xxxxx.mysql.rds.aliyuncs.com
DB_PORT=3306
DB_NAME=cloudflare_dns
DB_USER=cloudflare_user
DB_PASSWORD=your_secure_password
```

## 💾 数据持久化

### 持久化数据位置

- **MySQL 数据**: 存储在 Docker Volume `mysql_data`
- **应用数据**: 存储在 Docker Volume `app_data`

### 查看持久化卷

```bash
docker volume ls | grep cloudflare-dns
```

### 备份数据

#### 备份 MySQL 数据库

```bash
# 备份到文件
docker-compose exec mysql mysqldump -u root -p${DB_ROOT_PASSWORD} cloudflare_dns > backup.sql

# 或使用环境变量中的密码
docker-compose exec mysql sh -c 'mysqldump -u root -p$MYSQL_ROOT_PASSWORD $MYSQL_DATABASE' > backup.sql
```

#### 恢复 MySQL 数据库

```bash
docker-compose exec -T mysql mysql -u root -p${DB_ROOT_PASSWORD} cloudflare_dns < backup.sql
```

### 导出和导入 Docker Volumes

```bash
# 导出
docker run --rm -v cloudflare-dns-main_mysql_data:/data -v $(pwd):/backup ubuntu tar czf /backup/mysql_backup.tar.gz -C /data .

# 导入
docker run --rm -v cloudflare-dns-main_mysql_data:/data -v $(pwd):/backup ubuntu tar xzf /backup/mysql_backup.tar.gz -C /data
```

## 📝 常用命令

### 启动服务

```bash
# 后台启动
docker-compose up -d

# 前台启动（查看日志）
docker-compose up

# 只启动应用（不启动MySQL）
docker-compose up -d app
```

### 停止服务

```bash
# 停止所有服务
docker-compose stop

# 停止并删除容器
docker-compose down

# 停止并删除容器和卷（⚠️ 会删除所有数据）
docker-compose down -v
```

### 查看日志

```bash
# 查看所有服务日志
docker-compose logs

# 查看特定服务日志
docker-compose logs app
docker-compose logs mysql

# 实时查看日志
docker-compose logs -f

# 查看最近100行日志
docker-compose logs --tail=100
```

### 重启服务

```bash
# 重启所有服务
docker-compose restart

# 重启特定服务
docker-compose restart app
docker-compose restart mysql
```

### 进入容器

```bash
# 进入应用容器
docker-compose exec app bash

# 进入MySQL容器
docker-compose exec mysql bash

# 连接MySQL客户端
docker-compose exec mysql mysql -u cloudflare -p
```

### 更新镜像

```bash
# 拉取最新代码
git pull

# 重新构建镜像
docker-compose build --no-cache

# 重启服务
docker-compose down
docker-compose up -d
```

## 🔧 故障排除

### 1. 无法连接到 MySQL

**问题**: 应用无法连接到 MySQL 数据库

**解决方案**:
```bash
# 检查 MySQL 容器是否运行
docker-compose ps

# 查看 MySQL 日志
docker-compose logs mysql

# 测试数据库连接
docker-compose exec mysql mysql -u cloudflare -p
```

### 2. 端口已被占用

**问题**: 启动时提示端口已被占用

**解决方案**:
```bash
# 修改 .env 文件中的端口
APP_PORT=8081
DB_PORT=3307

# 或者停止占用端口的服务
sudo lsof -i :8080
sudo kill -9 <PID>
```

### 3. 权限问题

**问题**: 无法写入文件或创建目录

**解决方案**:
```bash
# 修复权限
docker-compose exec app chown -R www-data:www-data /var/www/html/data
docker-compose exec app chmod -R 777 /var/www/html/data
```

### 4. 忘记管理员密码

**解决方案**:
```bash
# 进入MySQL容器
docker-compose exec mysql mysql -u cloudflare -p

# 在MySQL中执行
USE cloudflare_dns;
UPDATE admins SET password='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' WHERE username='admin';
# 这会将密码重置为：password
```

### 5. 清除所有数据重新开始

```bash
# 停止并删除所有容器和数据
docker-compose down -v

# 删除生成的配置文件
rm -rf data/

# 重新启动
docker-compose up -d
```

### 6. 查看详细的启动日志

```bash
# 查看应用容器的详细日志
docker-compose logs -f app

# 进入容器查看PHP错误日志
docker-compose exec app tail -f /var/log/apache2/error.log
```

## 🔐 安全建议

1. **修改默认密码**
   - 在生产环境中，务必修改 `.env` 文件中的所有默认密码

2. **使用强密码**
   ```env
   DB_PASSWORD=$(openssl rand -base64 32)
   ADMIN_PASSWORD=$(openssl rand -base64 16)
   ```

3. **限制数据库访问**
   - 如果使用外部数据库，确保数据库只允许应用服务器IP访问

4. **启用HTTPS**
   - 在生产环境中使用反向代理（如 Nginx）并配置 SSL 证书

5. **定期备份**
   - 设置定时任务，定期备份数据库

6. **更新镜像**
   - 定期更新 Docker 镜像以获取安全补丁

## 📚 更多信息

- [项目主页](https://github.com/976853694/cloudflare-DNS)
- [原版 README](./README.md)
- [在线演示](https://dns.6qu.cc/)
- [QQ交流群](https://qm.qq.com/q/qYN7MywxO0): 1044379774

## 📄 许可证

本项目采用非商业许可证，仅供学习和个人使用，禁止商业用途。

---

**Made with ❤️ by 六趣M**

如有问题，请加入QQ群或提交 Issue。

