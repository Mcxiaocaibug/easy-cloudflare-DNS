# 项目结构说明

## 📁 Docker 容器化后的完整目录结构

```
cloudflare-DNS-main/
│
├── 📄 Docker 相关文件
│   ├── Dockerfile                      # Docker 镜像构建文件
│   ├── docker-compose.yml              # Docker Compose 配置文件
│   ├── docker-entrypoint.sh            # 容器启动入口脚本
│   ├── .dockerignore                   # Docker 构建忽略文件
│   └── init.sql                        # MySQL 初始化脚本
│
├── 📄 配置文件
│   ├── env.example                     # 环境变量配置模板
│   └── .htaccess                       # Apache 配置文件
│
├── 📄 自动化脚本
│   ├── start.sh                        # 一键启动脚本
│   └── check-env.sh                    # 环境检查脚本
│
├── 📄 文档
│   ├── README.md                       # 项目主文档（已更新）
│   ├── README.Docker.md                # Docker 部署完整指南
│   ├── DOCKER_MIGRATION.md             # 迁移说明文档
│   ├── QUICKSTART.md                   # 快速开始指南（中英文）
│   ├── SUMMARY.md                      # 项目总结
│   ├── PROJECT_STRUCTURE.md            # 本文件
│   └── LICENSE                         # 许可证
│
├── 📁 应用核心目录
│   ├── admin/                          # 管理后台
│   │   ├── includes/                   # 后台公共组件
│   │   ├── api/                        # API 接口
│   │   └── *.php                       # 管理功能页面
│   │
│   ├── user/                           # 用户前台
│   │   ├── includes/                   # 前台公共组件
│   │   ├── api/                        # API 接口
│   │   └── *.php                       # 用户功能页面
│   │
│   ├── config/                         # 配置文件目录
│   │   ├── database.php                # 数据库配置（自动生成）
│   │   ├── database.example.php        # 数据库配置示例
│   │   ├── cloudflare.php              # Cloudflare API
│   │   ├── rainbow_dns.php             # 彩虹DNS API
│   │   ├── dnspod.php                  # DNSPod API
│   │   ├── smtp.php                    # SMTP 配置
│   │   └── *.php                       # 其他配置
│   │
│   ├── includes/                       # 公共功能
│   │   ├── functions.php               # 通用函数
│   │   ├── captcha.php                 # 验证码
│   │   ├── security.php                # 安全函数
│   │   ├── user_groups.php             # 用户组管理
│   │   └── PHPMailer/                  # 邮件库
│   │
│   ├── assets/                         # 静态资源
│   │   ├── css/                        # 样式文件
│   │   │   ├── bootstrap.min.css
│   │   │   ├── fontawesome.min.css
│   │   │   └── *.css
│   │   ├── js/                         # JavaScript 文件
│   │   │   ├── jquery.min.js
│   │   │   ├── bootstrap.bundle.min.js
│   │   │   └── *.js
│   │   └── fonts/                      # 字体文件
│   │
│   ├── data/                           # 数据目录（Docker Volume）
│   │   └── install.lock                # 安装锁定文件
│   │
│   ├── docs/                           # 文档目录
│   │   ├── README.md
│   │   ├── Wiki.md
│   │   └── *.md
│   │
│   ├── index.php                       # 项目入口文件
│   ├── install.php                     # 安装向导
│   └── upgrade.php                     # 升级脚本
│
└── 📁 Git 相关
    ├── .git/                           # Git 仓库
    └── .gitignore                      # Git 忽略文件
```

## 🐳 Docker 容器架构

```
┌─────────────────────────────────────────────────────────────┐
│                     Docker Compose                          │
│                                                              │
│  ┌────────────────────────┐    ┌──────────────────────────┐│
│  │   App Container        │    │   MySQL Container        ││
│  │   (PHP 8.1 + Apache)   │◄──►│   (MySQL 8.0)            ││
│  │                        │    │                          ││
│  │  - PHP-FPM             │    │  - cloudflare_dns (DB)  ││
│  │  - Apache 2.4          │    │  - utf8mb4 charset      ││
│  │  - PDO MySQL           │    │  - InnoDB engine        ││
│  │  - GD, Curl, etc.      │    │  - 3306 port            ││
│  │  - Port: 8080          │    │                          ││
│  │                        │    │                          ││
│  │  Volume: app_data      │    │  Volume: mysql_data     ││
│  └────────────────────────┘    └──────────────────────────┘│
│           │                              │                  │
│           └──────────────┬───────────────┘                  │
│                          │                                   │
│                  cloudflare-dns-network                     │
└─────────────────────────────────────────────────────────────┘
                            │
                            │ Port Mapping
                            │
                    ┌───────▼────────┐
                    │  Host Machine  │
                    │  Port: 8080    │
                    └────────────────┘
```

## 🔄 数据流向

### 1. 请求处理流程

```
用户浏览器
    │
    │ HTTP Request (Port 8080)
    ▼
Docker Host
    │
    │ Port Mapping
    ▼
App Container (Apache)
    │
    │ PHP Processing
    ▼
PDO MySQL Driver
    │
    │ MySQL Protocol (Port 3306)
    ▼
MySQL Container
    │
    │ InnoDB Engine
    ▼
MySQL Data Volume
    │
    │ Persistent Storage
    ▼
Host File System
```

### 2. 配置加载流程

```
容器启动
    │
    ▼
docker-entrypoint.sh
    │
    ├─► 读取环境变量 (.env)
    │
    ├─► 等待 MySQL 就绪
    │
    ├─► 生成 database.php
    │
    ├─► 检查 install.lock
    │
    ├─► 执行自动安装
    │   ├─► 创建数据库表
    │   ├─► 插入默认数据
    │   └─► 创建管理员账户
    │
    └─► 启动 Apache
```

### 3. 数据持久化

```
┌──────────────────────────────────────────────┐
│           Docker Volumes                      │
│                                               │
│  mysql_data/                                  │
│  ├── ibdata1          (InnoDB 系统表空间)     │
│  ├── cloudflare_dns/  (数据库文件)           │
│  ├── mysql/           (系统数据库)            │
│  └── performance_schema/                      │
│                                               │
│  app_data/                                    │
│  ├── install.lock     (安装锁定文件)         │
│  └── logs/            (应用日志)              │
└──────────────────────────────────────────────┘
         │
         │ 映射到
         ▼
  Host File System
  (物理存储)
```

## 📊 数据库结构

### 核心表关系图

```
                    users (用户表)
                       │
                       │ user_id
        ┌──────────────┼──────────────┬───────────────┐
        │              │              │               │
        ▼              ▼              ▼               ▼
   dns_records   invitations   card_key_usage   action_logs
   (DNS记录)     (邀请记录)    (卡密使用)       (操作日志)
        │
        │ domain_id
        ▼
    domains (域名表)
        │
        │ provider_type
        ├──► cloudflare_accounts
        └──► rainbow_accounts

                    admins (管理员表)
                       │
        ┌──────────────┼──────────────┐
        │              │              │
        ▼              ▼              ▼
   card_keys    announcements   settings
   (卡密表)      (公告表)       (设置表)

              user_groups (用户组表)
                    │
                    │ group_id
        ┌───────────┼───────────┐
        │           │           │
        ▼           ▼           ▼
     users   user_group_domains
            (用户组域名权限)
                    │
                    │ domain_id
                    ▼
                 domains
```

## 🔧 环境变量分类

### 数据库相关 (8个)
```env
DB_TYPE
DB_HOST
DB_PORT
DB_NAME
DB_USER
DB_PASSWORD
DB_ROOT_PASSWORD
DB_CHARSET
```

### 应用相关 (2个)
```env
APP_PORT
AUTO_INSTALL
```

### 管理员相关 (3个)
```env
ADMIN_USERNAME
ADMIN_PASSWORD
ADMIN_EMAIL
```

### 系统设置 (4个)
```env
SITE_NAME
POINTS_PER_RECORD
DEFAULT_USER_POINTS
ALLOW_REGISTRATION
```

### 邀请系统 (3个)
```env
INVITATION_ENABLED
INVITATION_REWARD_POINTS
INVITEE_BONUS_POINTS
```

### SMTP邮件 (8个)
```env
SMTP_ENABLED
SMTP_HOST
SMTP_PORT
SMTP_USERNAME
SMTP_PASSWORD
SMTP_SECURE
SMTP_FROM_NAME
SMTP_DEBUG
```

**总计: 28 个环境变量**

## 📁 关键文件说明

### Docker 相关

| 文件 | 大小 | 说明 |
|------|------|------|
| `Dockerfile` | ~2KB | 定义 PHP 环境和应用部署 |
| `docker-compose.yml` | ~3KB | 定义多服务编排 |
| `docker-entrypoint.sh` | ~15KB | 容器启动和初始化脚本 |
| `.dockerignore` | ~0.5KB | 优化构建效率 |
| `init.sql` | ~0.3KB | MySQL 初始化 |

### 配置相关

| 文件 | 大小 | 说明 |
|------|------|------|
| `env.example` | ~4KB | 环境变量配置模板 |
| `.htaccess` | ~1KB | Apache 安全配置 |

### 脚本相关

| 文件 | 大小 | 说明 |
|------|------|------|
| `start.sh` | ~2KB | 一键启动脚本 |
| `check-env.sh` | ~5KB | 环境检查脚本 |

### 文档相关

| 文件 | 大小 | 说明 |
|------|------|------|
| `README.Docker.md` | ~25KB | Docker 部署完整指南 |
| `DOCKER_MIGRATION.md` | ~15KB | 迁移说明文档 |
| `QUICKSTART.md` | ~8KB | 快速开始指南 |
| `SUMMARY.md` | ~12KB | 项目总结 |
| `PROJECT_STRUCTURE.md` | ~10KB | 本文件 |

## 🎯 核心组件版本

| 组件 | 版本 | 说明 |
|------|------|------|
| PHP | 8.1 | 服务端语言 |
| Apache | 2.4 | Web 服务器 |
| MySQL | 8.0 | 关系型数据库 |
| Bootstrap | 5.x | 前端框架 |
| jQuery | 3.x | JavaScript 库 |
| FontAwesome | 6.x | 图标库 |
| PHPMailer | 6.x | 邮件库 |
| Docker | 20.10+ | 容器平台 |
| Docker Compose | 2.0+ | 容器编排 |

## 🚀 部署流程图

```
┌─────────────┐
│ 克隆代码    │
└──────┬──────┘
       │
       ▼
┌─────────────┐
│ 复制配置    │
│ .env.example│
│  ➜ .env     │
└──────┬──────┘
       │
       ▼
┌─────────────┐
│ 编辑配置    │
│ (可选)      │
└──────┬──────┘
       │
       ▼
┌─────────────┐
│ 检查环境    │
│ check-env.sh│
└──────┬──────┘
       │
       ▼
┌─────────────┐
│ 启动服务    │
│ start.sh    │
└──────┬──────┘
       │
       ▼
┌─────────────┐
│ Docker      │
│ Compose     │
└──────┬──────┘
       │
       ├─► 拉取镜像
       │
       ├─► 构建应用镜像
       │
       ├─► 创建网络
       │
       ├─► 创建卷
       │
       ├─► 启动 MySQL
       │
       ├─► 等待 MySQL 就绪
       │
       ├─► 启动应用容器
       │
       ├─► 执行 entrypoint 脚本
       │   ├─► 生成配置文件
       │   ├─► 初始化数据库
       │   └─► 创建管理员
       │
       └─► 启动 Apache
           │
           ▼
     ┌─────────────┐
     │ 服务就绪    │
     │ Port: 8080  │
     └─────────────┘
```

## 📈 性能优化点

### MySQL 优化
- ✅ 使用 InnoDB 引擎
- ✅ utf8mb4 字符集
- ✅ 合理的索引设计
- ✅ 外键约束
- ✅ 连接池

### PHP 优化
- ✅ OPcache 启用
- ✅ 内存限制 256MB
- ✅ 最大执行时间 300s
- ✅ PDO 预处理语句

### Apache 优化
- ✅ mod_rewrite 启用
- ✅ 静态资源缓存
- ✅ Gzip 压缩
- ✅ Keep-Alive 连接

### Docker 优化
- ✅ 多阶段构建（未使用）
- ✅ 层缓存优化
- ✅ .dockerignore 减小镜像
- ✅ 健康检查

## 🔒 安全特性

### 应用层安全
- ✅ SQL 注入防护（PDO）
- ✅ XSS 防护（htmlspecialchars）
- ✅ CSRF 防护（Token）
- ✅ 密码哈希（password_hash）
- ✅ 会话安全

### 文件系统安全
- ✅ 禁止访问 .db 文件
- ✅ 禁止访问 .env 文件
- ✅ 禁止目录浏览
- ✅ 限制文件权限

### 网络安全
- ✅ 内部网络隔离
- ✅ 端口最小化暴露
- ✅ MySQL 密码保护
- ✅ Apache 安全头

## 📊 监控和日志

### 日志位置
```
/var/log/apache2/
  ├── access.log      # 访问日志
  └── error.log       # 错误日志

/var/www/html/data/
  └── logs/           # 应用日志
```

### 查看日志
```bash
# Docker 日志
docker-compose logs -f

# Apache 日志
docker-compose exec app tail -f /var/log/apache2/error.log

# MySQL 日志
docker-compose logs mysql
```

## 🎉 总结

这个项目结构清晰、模块化、易于维护。Docker 容器化使得部署变得简单快捷，MySQL 数据库提供了企业级的性能和可靠性。

**主要优势:**
- 📦 容器化部署，环境一致
- 🚀 一键启动，快速部署
- 🔧 环境变量配置，灵活便捷
- 💾 数据持久化，安全可靠
- 📖 文档完善，易于上手

---

**Made with ❤️ by 六趣M**

