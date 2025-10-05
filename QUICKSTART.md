# 快速开始 / Quick Start

[中文](#中文) | [English](#english)

---

## 中文

### 🚀 五分钟快速部署

#### 前置要求
- 已安装 Docker
- 已安装 Docker Compose

#### 步骤 1: 获取代码
```bash
git clone https://github.com/976853694/cloudflare-DNS.git
cd cloudflare-DNS
```

#### 步骤 2: 配置环境变量
```bash
# 复制配置模板
cp env.example .env

# （可选）编辑配置文件
nano .env
```

#### 步骤 3: 启动服务
```bash
# 方式 1: 使用一键启动脚本
./start.sh

# 方式 2: 手动启动
docker-compose up -d
```

#### 步骤 4: 访问系统
- 应用地址: http://localhost:8080
- 管理后台: http://localhost:8080/admin/login.php
- 默认账号: admin / admin123456

### 🎯 核心功能

#### 1. DNS 记录管理
- 支持 A、AAAA、CNAME、TXT、MX 等记录类型
- 一键添加/删除/修改 DNS 记录
- 实时生效

#### 2. 多域名管理
- 支持 Cloudflare、彩虹 DNS 等多个 DNS 提供商
- 批量导入域名
- 独立管理每个域名

#### 3. 用户系统
- 用户注册登录
- 积分系统
- 用户组权限管理

#### 4. 邀请系统
- 生成邀请码
- 邀请奖励
- 邀请记录追踪

### ⚙️ 环境变量配置

#### 必须配置的环境变量

```env
# 数据库密码（建议修改）
DB_PASSWORD=your_secure_password
DB_ROOT_PASSWORD=your_root_password

# 管理员账户
ADMIN_USERNAME=admin
ADMIN_PASSWORD=your_admin_password
```

#### 可选配置

```env
# 系统设置
SITE_NAME=您的网站名称
POINTS_PER_RECORD=1
DEFAULT_USER_POINTS=100
ALLOW_REGISTRATION=1

# SMTP 邮件（可选）
SMTP_ENABLED=1
SMTP_HOST=smtp.qq.com
SMTP_PORT=465
SMTP_USERNAME=your_email@qq.com
SMTP_PASSWORD=your_authorization_code
```

### 🔧 常用命令

```bash
# 查看服务状态
docker-compose ps

# 查看日志
docker-compose logs -f

# 重启服务
docker-compose restart

# 停止服务
docker-compose stop

# 删除服务（保留数据）
docker-compose down

# 删除服务和数据
docker-compose down -v
```

### 📊 数据备份

```bash
# 备份数据库
docker-compose exec mysql mysqldump -u cloudflare -p cloudflare_dns > backup-$(date +%Y%m%d).sql

# 恢复数据库
docker-compose exec -T mysql mysql -u cloudflare -p cloudflare_dns < backup-20250101.sql
```

### 🆘 遇到问题？

1. 查看日志: `docker-compose logs -f`
2. 检查服务状态: `docker-compose ps`
3. 查看详细文档: [README.Docker.md](./README.Docker.md)
4. 加入 QQ 群: 1044379774

---

## English

### 🚀 5-Minute Quick Deploy

#### Prerequisites
- Docker installed
- Docker Compose installed

#### Step 1: Get the Code
```bash
git clone https://github.com/976853694/cloudflare-DNS.git
cd cloudflare-DNS
```

#### Step 2: Configure Environment
```bash
# Copy configuration template
cp env.example .env

# (Optional) Edit configuration
nano .env
```

#### Step 3: Start Services
```bash
# Option 1: Use quick start script
./start.sh

# Option 2: Manual start
docker-compose up -d
```

#### Step 4: Access System
- Application: http://localhost:8080
- Admin Panel: http://localhost:8080/admin/login.php
- Default Account: admin / admin123456

### 🎯 Core Features

#### 1. DNS Record Management
- Support A, AAAA, CNAME, TXT, MX and more record types
- One-click add/delete/modify DNS records
- Real-time updates

#### 2. Multi-Domain Management
- Support multiple DNS providers (Cloudflare, Rainbow DNS, etc.)
- Batch import domains
- Independent management for each domain

#### 3. User System
- User registration and login
- Points system
- User group permissions

#### 4. Invitation System
- Generate invitation codes
- Invitation rewards
- Invitation history tracking

### ⚙️ Environment Variables

#### Required Variables

```env
# Database passwords (recommended to change)
DB_PASSWORD=your_secure_password
DB_ROOT_PASSWORD=your_root_password

# Admin account
ADMIN_USERNAME=admin
ADMIN_PASSWORD=your_admin_password
```

#### Optional Configuration

```env
# System settings
SITE_NAME=Your Site Name
POINTS_PER_RECORD=1
DEFAULT_USER_POINTS=100
ALLOW_REGISTRATION=1

# SMTP Email (optional)
SMTP_ENABLED=1
SMTP_HOST=smtp.gmail.com
SMTP_PORT=465
SMTP_USERNAME=your_email@gmail.com
SMTP_PASSWORD=your_app_password
```

### 🔧 Common Commands

```bash
# Check service status
docker-compose ps

# View logs
docker-compose logs -f

# Restart services
docker-compose restart

# Stop services
docker-compose stop

# Remove services (keep data)
docker-compose down

# Remove services and data
docker-compose down -v
```

### 📊 Data Backup

```bash
# Backup database
docker-compose exec mysql mysqldump -u cloudflare -p cloudflare_dns > backup-$(date +%Y%m%d).sql

# Restore database
docker-compose exec -T mysql mysql -u cloudflare -p cloudflare_dns < backup-20250101.sql
```

### 🆘 Need Help?

1. Check logs: `docker-compose logs -f`
2. Check service status: `docker-compose ps`
3. Read detailed docs: [README.Docker.md](./README.Docker.md)
4. Join QQ Group: 1044379774

---

## 📚 Documentation

- [README.md](./README.md) - Main documentation
- [README.Docker.md](./README.Docker.md) - Docker deployment guide
- [DOCKER_MIGRATION.md](./DOCKER_MIGRATION.md) - Migration details

## 📞 Contact

- GitHub: https://github.com/976853694/cloudflare-DNS
- QQ Group: 1044379774
- Demo: https://dns.6qu.cc/

## 📄 License

Non-commercial use only. See [LICENSE](./LICENSE) for details.

---

**Made with ❤️ by 六趣M**

