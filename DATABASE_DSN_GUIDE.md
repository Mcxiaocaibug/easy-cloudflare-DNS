# MySQL DSN 配置指南

本项目支持使用 MySQL DSN (Data Source Name) 格式的环境变量来配置数据库连接。

## 📋 DSN 格式说明

### 标准格式

```
mysql://username:password@host:port/database
```

### 完整格式（带查询参数）

```
mysql://username:password@host:port/database?charset=utf8mb4
```

## 🎯 配置方式

### 方式 1: 使用 DATABASE_URL（推荐）

在 `.env` 文件中设置 `DATABASE_URL`：

```env
# 内置 MySQL 容器
DATABASE_URL=mysql://cloudflare:cloudflare_password_123@mysql:3306/cloudflare_dns

# 外部 MySQL 服务器
DATABASE_URL=mysql://myuser:mypassword@192.168.1.100:3306/cloudflare_dns

# 云数据库（如阿里云RDS）
DATABASE_URL=mysql://cloudflare:SecureP@ss123@rm-xxxxx.mysql.rds.aliyuncs.com:3306/cloudflare_dns

# 带字符集参数
DATABASE_URL=mysql://cloudflare:password@mysql:3306/cloudflare_dns?charset=utf8mb4
```

### 方式 2: 使用分离的环境变量

如果不设置 `DATABASE_URL`，系统会使用分离的环境变量：

```env
DB_HOST=mysql
DB_PORT=3306
DB_NAME=cloudflare_dns
DB_USER=cloudflare
DB_PASSWORD=cloudflare_password_123
DB_CHARSET=utf8mb4
```

## 📝 DSN 组成部分

| 部分 | 说明 | 示例 |
|------|------|------|
| `mysql://` | 协议标识符 | `mysql://` |
| `username` | 数据库用户名 | `cloudflare` |
| `password` | 数据库密码 | `mypassword` |
| `host` | 数据库主机地址 | `mysql` 或 `192.168.1.100` |
| `port` | 数据库端口 | `3306` |
| `database` | 数据库名称 | `cloudflare_dns` |
| `?charset=xxx` | 字符集（可选） | `?charset=utf8mb4` |

## 🔧 实际示例

### 示例 1: 本地开发（使用 Docker Compose 内置 MySQL）

```env
DATABASE_URL=mysql://cloudflare:cloudflare_password_123@mysql:3306/cloudflare_dns
```

**说明**: 
- 主机使用 `mysql` （Docker Compose 服务名）
- 端口 3306
- 用户名和密码与 MySQL 容器配置一致

### 示例 2: 连接到局域网 MySQL 服务器

```env
DATABASE_URL=mysql://myuser:MyP@ssw0rd@192.168.1.100:3306/cloudflare_dns
```

**说明**:
- 主机使用实际 IP 地址
- 密码中包含特殊字符需要 URL 编码（见下文）

### 示例 3: 阿里云 RDS MySQL

```env
DATABASE_URL=mysql://cloudflare_user:Secure_Pass_2024@rm-bp1xxxxx.mysql.rds.aliyuncs.com:3306/cloudflare_dns
```

### 示例 4: 腾讯云 MySQL

```env
DATABASE_URL=mysql://cloudflare_user:MyPassword123@cdb-xxxxx.tencentcdb.com:10123/cloudflare_dns
```

### 示例 5: AWS RDS MySQL

```env
DATABASE_URL=mysql://admin:MyPassword123@myinstance.123456789012.us-east-1.rds.amazonaws.com:3306/cloudflare_dns
```

### 示例 6: 本机 MySQL（Docker for Mac/Windows）

```env
DATABASE_URL=mysql://root:rootpassword@host.docker.internal:3306/cloudflare_dns
```

**说明**: Docker for Mac/Windows 使用 `host.docker.internal` 访问主机

### 示例 7: 本机 MySQL（Docker for Linux）

```env
DATABASE_URL=mysql://root:rootpassword@172.17.0.1:3306/cloudflare_dns
```

**说明**: Docker for Linux 使用 `172.17.0.1` 访问主机

## 🔐 密码中的特殊字符处理

### 需要 URL 编码的特殊字符

如果密码中包含特殊字符，需要进行 URL 编码：

| 特殊字符 | URL 编码 |
|---------|---------|
| `@` | `%40` |
| `:` | `%3A` |
| `/` | `%2F` |
| `?` | `%3F` |
| `#` | `%23` |
| `[` | `%5B` |
| `]` | `%5D` |
| `!` | `%21` |
| `$` | `%24` |
| `&` | `%26` |
| `'` | `%27` |
| `(` | `%28` |
| `)` | `%29` |
| `*` | `%2A` |
| `+` | `%2B` |
| `,` | `%2C` |
| `;` | `%3B` |
| `=` | `%3D` |
| `%` | `%25` |
| ` ` (空格) | `%20` |

### 编码示例

**原始密码**: `P@ssw0rd!2024`  
**URL 编码后**: `P%40ssw0rd%212024`  
**完整 DSN**: `mysql://user:P%40ssw0rd%212024@host:3306/database`

### 在线 URL 编码工具

您可以使用以下命令或在线工具进行 URL 编码：

```bash
# PHP 命令行编码
php -r "echo urlencode('P@ssw0rd!2024');"

# Python 命令行编码
python3 -c "import urllib.parse; print(urllib.parse.quote('P@ssw0rd!2024'))"

# 在线工具
# https://www.urlencoder.org/
```

## 🚀 配置步骤

### 步骤 1: 准备 DSN

根据您的 MySQL 服务器信息，构建 DSN 字符串：

```
mysql://[用户名]:[密码]@[主机]:[端口]/[数据库名]
```

### 步骤 2: 编辑 .env 文件

```bash
# 复制配置模板
cp env.example .env

# 编辑配置文件
nano .env
```

### 步骤 3: 设置 DATABASE_URL

```env
DATABASE_URL=mysql://cloudflare:your_password@mysql:3306/cloudflare_dns
```

### 步骤 4: 启动服务

```bash
docker-compose up -d
```

### 步骤 5: 验证连接

```bash
# 查看日志，确认数据库连接成功
docker-compose logs app

# 应该看到类似输出：
# ✓ MySQL数据库连接成功
```

## 🔍 故障排除

### 问题 1: 连接失败

**症状**: `错误: 无法连接到MySQL数据库`

**解决方案**:
1. 检查 DSN 格式是否正确
2. 确认数据库服务器地址可访问
3. 验证用户名和密码是否正确
4. 检查端口是否开放

```bash
# 测试连接（在宿主机上）
mysql -h [主机] -P [端口] -u [用户名] -p
```

### 问题 2: 密码包含特殊字符

**症状**: 密码正确但无法连接

**解决方案**: 对密码进行 URL 编码

```bash
# 使用 PHP 编码密码
php -r "echo urlencode('your_password');"
```

### 问题 3: 主机地址错误

**症状**: `Unknown MySQL server host`

**解决方案**:
- Docker 内部使用服务名（如 `mysql`）
- 连接外部使用 IP 或域名
- Docker for Mac/Windows 使用 `host.docker.internal`
- Docker for Linux 使用 `172.17.0.1`

### 问题 4: 端口不可访问

**症状**: `Can't connect to MySQL server`

**解决方案**:
1. 确认 MySQL 端口已开放
2. 检查防火墙规则
3. 验证 MySQL 允许远程连接

```bash
# 检查端口
telnet [主机] [端口]
```

## 📊 DSN vs 分离变量对比

| 特性 | DATABASE_URL (DSN) | 分离的环境变量 |
|------|-------------------|--------------|
| 配置方式 | 单个字符串 | 多个变量 |
| 易读性 | 中等 | 高 |
| 易用性 | 高（一行配置） | 中等（多行配置） |
| 迁移便利性 | 高（复制粘贴） | 中等 |
| 兼容性 | 现代框架常用 | 传统方式 |
| 推荐场景 | 生产环境、云部署 | 开发环境 |

## 🌐 云服务商 DSN 示例

### 阿里云 RDS

```env
# 格式
DATABASE_URL=mysql://[用户名]:[密码]@rm-[实例ID].mysql.rds.aliyuncs.com:3306/[数据库名]

# 示例
DATABASE_URL=mysql://cloudflare:SecurePass123@rm-bp1a2b3c4d5e6f7g.mysql.rds.aliyuncs.com:3306/cloudflare_dns
```

### 腾讯云 MySQL

```env
# 格式
DATABASE_URL=mysql://[用户名]:[密码]@cdb-[实例ID].tencentcdb.com:[端口]/[数据库名]

# 示例
DATABASE_URL=mysql://cloudflare:SecurePass123@cdb-abc123xyz.tencentcdb.com:10123/cloudflare_dns
```

### AWS RDS

```env
# 格式
DATABASE_URL=mysql://[用户名]:[密码]@[实例标识符].[随机字符串].[区域].rds.amazonaws.com:3306/[数据库名]

# 示例
DATABASE_URL=mysql://admin:MyPassword123@myinstance.123456789012.us-east-1.rds.amazonaws.com:3306/cloudflare_dns
```

### Google Cloud SQL

```env
# 格式（通过 Cloud SQL Proxy）
DATABASE_URL=mysql://[用户名]:[密码]@127.0.0.1:3306/[数据库名]

# 示例
DATABASE_URL=mysql://cloudflare:MyPassword123@127.0.0.1:3306/cloudflare_dns
```

## 📚 相关文档

- [README.Docker.md](./README.Docker.md) - Docker 完整部署指南
- [QUICKSTART.md](./QUICKSTART.md) - 快速开始指南
- [env.example](./env.example) - 环境变量配置模板

## ❓ 常见问题

### Q: DATABASE_URL 和分离变量可以同时使用吗？

A: 可以，但 `DATABASE_URL` 优先级更高。如果设置了 `DATABASE_URL`，系统会忽略分离的数据库变量（DB_HOST, DB_PORT 等）。

### Q: 如何在 DSN 中指定字符集？

A: 在 DSN 末尾添加查询参数：
```env
DATABASE_URL=mysql://user:pass@host:3306/database?charset=utf8mb4
```

### Q: 密码中有 @ 符号怎么办？

A: 将 `@` 编码为 `%40`：
```env
# 原密码: P@ssword
DATABASE_URL=mysql://user:P%40ssword@host:3306/database
```

### Q: 如何连接到本地 MySQL（非 Docker）？

A: 使用特殊主机名：
```env
# Docker for Mac/Windows
DATABASE_URL=mysql://root:password@host.docker.internal:3306/cloudflare_dns

# Docker for Linux
DATABASE_URL=mysql://root:password@172.17.0.1:3306/cloudflare_dns
```

### Q: 可以使用域名吗？

A: 可以，只要域名能解析：
```env
DATABASE_URL=mysql://user:pass@db.example.com:3306/cloudflare_dns
```

## 💡 最佳实践

1. **使用强密码**: 包含大小写字母、数字和特殊字符
2. **妥善保管**: 不要将 `.env` 文件提交到版本控制
3. **定期更换**: 定期更换数据库密码
4. **限制访问**: 配置数据库防火墙规则
5. **使用 SSL**: 生产环境建议启用 SSL 连接
6. **备份连接信息**: 安全保存一份 DSN 备份

## 🔒 安全建议

1. **不要在代码中硬编码**: 始终使用环境变量
2. **限制数据库权限**: 只授予必要的权限
3. **启用防火墙**: 限制数据库访问 IP
4. **使用专用账户**: 不要使用 root 账户
5. **监控连接**: 定期检查数据库连接日志

---

**Made with ❤️ by 六趣M**

如有问题，请查看 [README.Docker.md](./README.Docker.md) 或加入 QQ 群：1044379774

