# 📚 六趣DNS Wiki 文档

<div align="center">
  <img src="https://img.shields.io/badge/📚-Wiki%20文档-blue?style=for-the-badge" alt="Wiki">
  <img src="https://img.shields.io/badge/🔄-持续更新-green?style=for-the-badge" alt="持续更新">
  <img src="https://img.shields.io/badge/📖-详细指南-orange?style=for-the-badge" alt="详细指南">
</div>

---

## 📋 目录导航

### 🚀 快速开始
- [系统要求](wiki.md#系统要求)
- [安装部署](wiki.md#安装部署)
- [首次配置](wiki.md#首次配置)
- [常见问题](wiki.md#常见问题)

### 👤 用户指南
- [用户注册](wiki.md#用户注册)
- [DNS记录管理](wiki.md#dns记录管理)
- [积分系统](wiki.md#积分系统)
- [邀请系统](wiki.md#邀请系统)

### 🛠️ 管理指南
- [管理员设置](wiki.md#管理员设置)
- [用户管理](wiki.md#用户管理)
- [域名管理](wiki.md#域名管理)
- [系统配置](wiki.md#系统配置)

### 🔧 高级配置
- [SMTP邮件配置](wiki.md#smtp邮件配置)
- [DNS提供商配置](wiki.md#dns提供商配置)
- [安全设置](wiki.md#安全设置)
- [性能优化](wiki.md#性能优化)

### 🐛 故障排除
- [常见错误](wiki.md#常见错误)
- [日志分析](wiki.md#日志分析)
- [性能监控](wiki.md#性能监控)
- [备份恢复](wiki.md#备份恢复)

---

## 🚀 快速开始

### 系统要求

<div align="center">

| 组件 | 最低版本 | 推荐版本 | 说明 |
|------|---------|---------|------|
| **PHP** | 7.4 | 8.0+ | 服务端运行环境 |
| **SQLite3** | 3.0 | 3.35+ | 数据库扩展 |
| **cURL** | 7.0 | 7.80+ | HTTP客户端 |
| **OpenSSL** | 1.0 | 3.0+ | 加密支持 |
| **Web服务器** | - | - | Apache/Nginx |
| **内存** | 128MB | 256MB+ | 推荐配置 |
| **磁盘空间** | 100MB | 500MB+ | 包含数据库和日志 |

</div>

### 安装部署

#### 1️⃣ 环境准备

**Ubuntu/Debian 系统：**
```bash
# 更新系统包
sudo apt update && sudo apt upgrade -y

# 安装PHP和扩展
sudo apt install php8.0 php8.0-sqlite3 php8.0-curl php8.0-gd php8.0-mbstring -y

# 安装Nginx
sudo apt install nginx -y

# 启动服务
sudo systemctl start nginx php8.0-fpm
sudo systemctl enable nginx php8.0-fpm
```

**CentOS/RHEL 系统：**
```bash
# 安装EPEL仓库
sudo yum install epel-release -y

# 安装PHP和扩展
sudo yum install php80 php80-php-sqlite3 php80-php-curl php80-php-gd -y

# 安装Nginx
sudo yum install nginx -y

# 启动服务
sudo systemctl start nginx php80-php-fpm
sudo systemctl enable nginx php80-php-fpm
```

#### 2️⃣ 项目部署

```bash
# 克隆项目
git clone https://github.com/976853694/cloudflare-DNS.git
cd cloudflare-DNS

# 设置权限
sudo chown -R www-data:www-data .
sudo chmod -R 755 .
sudo chmod 666 data/cloudflare_dns.db

# 配置Nginx
sudo nano /etc/nginx/sites-available/cloudflare-dns
```

**Nginx 配置文件：**
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/cloudflare-DNS;
    index index.php;
    
    # 安全防护
    location ~* \.(db|sqlite|sql|bak|backup|log)$ {
        return 301 /;
    }
    
    # PHP处理
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
    }
    
    # 静态资源缓存
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

#### 3️⃣ 完成安装

1. 访问 `http://your-domain.com/install.php`
2. 按照安装向导完成配置
3. 创建管理员账户
4. 删除 `install.php` 文件

### 首次配置

#### 基本系统设置

1. **登录管理后台**
   - 使用安装时创建的管理员账户登录
   - 访问 `/admin/` 目录

2. **配置系统参数**
   - 站点名称：设置您的DNS管理系统名称
   - 默认积分：新用户注册时获得的积分数量
   - 邀请奖励：邀请新用户获得的积分奖励

3. **配置SMTP邮件**
   - 进入"SMTP设置"页面
   - 填写邮件服务器信息
   - 发送测试邮件验证配置

#### DNS提供商配置

**Cloudflare 配置：**
1. 登录 [Cloudflare](https://dash.cloudflare.com/)
2. 进入 "My Profile" → "API Tokens"
3. 创建 "Custom token"
4. 权限设置：Zone:Read, DNS:Edit
5. 在后台"管理CF账户"中添加账户

**彩虹DNS 配置：**
1. 登录彩虹DNS控制台
2. 获取API密钥和用户ID
3. 在后台"管理彩虹账户"中添加账户

---

## 👤 用户指南

### 用户注册

#### 注册流程

1. **访问注册页面**
   - 访问 `/user/login.php`
   - 点击"立即注册"按钮

2. **填写注册信息**
   - 用户名：3-20个字符，支持字母、数字、下划线
   - 邮箱：有效的邮箱地址，用于接收验证码
   - 密码：至少6个字符，建议包含字母和数字

3. **邮箱验证**
   - 系统发送验证码到注册邮箱
   - 输入验证码完成注册
   - 验证码5分钟内有效

#### 注册注意事项

- 用户名一旦注册不可修改
- 邮箱用于接收系统通知和验证码
- 建议使用强密码保护账户安全
- 注册成功后自动获得初始积分

### DNS记录管理

#### 添加DNS记录

1. **选择域名**
   - 在用户面板选择要管理的域名
   - 确保域名已添加到系统中

2. **填写记录信息**
   - 记录类型：A、AAAA、CNAME、TXT、MX等
   - 主机记录：子域名前缀
   - 记录值：目标地址或内容
   - TTL：记录生存时间（可选）

3. **提交记录**
   - 系统验证记录格式
   - 扣除相应积分
   - 记录生效时间：1-5分钟

#### 记录类型说明

| 记录类型 | 用途 | 示例 | 积分消耗 |
|---------|------|------|---------|
| **A** | IPv4地址 | 192.168.1.1 | 1积分 |
| **AAAA** | IPv6地址 | 2001:db8::1 | 1积分 |
| **CNAME** | 别名记录 | www.example.com | 1积分 |
| **TXT** | 文本记录 | "v=spf1 include:_spf.google.com ~all" | 1积分 |
| **MX** | 邮件交换 | mail.example.com | 2积分 |

#### 记录管理操作

- **编辑记录**：修改记录内容
- **删除记录**：删除不需要的记录
- **暂停记录**：临时禁用记录
- **批量操作**：同时管理多个记录

### 积分系统

#### 积分获取方式

1. **注册奖励**
   - 新用户注册获得初始积分
   - 具体数量由管理员设置

2. **邀请奖励**
   - 邀请新用户注册获得积分
   - 被邀请用户也获得额外积分

3. **管理员充值**
   - 管理员可以为用户充值积分
   - 支持批量充值操作

4. **卡密充值**
   - 购买充值卡密
   - 在个人中心输入卡密充值

#### 积分消耗规则

- **DNS记录**：根据记录类型消耗积分
- **用户组差异**：不同用户组消耗不同
- **VIP用户**：享受积分优惠
- **SVIP用户**：部分记录免费

#### 积分查询

- 用户面板显示当前积分
- 积分变动记录可查看
- 支持积分使用统计

### 邀请系统

#### 邀请码获取

1. **生成邀请码**
   - 每个用户自动获得唯一邀请码
   - 邀请码永久有效，可重复使用

2. **分享邀请码**
   - 复制邀请链接分享给朋友
   - 支持多种分享方式

#### 邀请奖励

- **邀请者奖励**：朋友注册成功后获得积分
- **被邀请者奖励**：使用邀请码注册获得额外积分
- **奖励数量**：由管理员在后台设置

#### 邀请统计

- 查看邀请成功数量
- 查看邀请奖励记录
- 查看邀请用户列表

---

## 🛠️ 管理指南

### 管理员设置

#### 管理员权限

- **用户管理**：查看、编辑、删除用户
- **域名管理**：添加、删除、管理域名
- **系统设置**：配置系统参数
- **日志查看**：查看操作日志
- **数据统计**：查看使用统计

#### 管理员操作

1. **登录管理后台**
   - 访问 `/admin/` 目录
   - 使用管理员账户登录

2. **系统概览**
   - 查看用户数量
   - 查看DNS记录数量
   - 查看系统状态

3. **功能管理**
   - 用户管理
   - 域名管理
   - 系统设置

### 用户管理

#### 用户列表

- **用户信息**：用户名、邮箱、注册时间
- **积分信息**：当前积分、积分变动
- **用户组**：默认组、VIP组、SVIP组
- **状态管理**：启用、禁用用户

#### 用户操作

1. **查看用户详情**
   - 基本信息
   - DNS记录列表
   - 操作日志

2. **编辑用户信息**
   - 修改邮箱
   - 调整积分
   - 修改用户组

3. **用户组管理**
   - 分配用户组
   - 设置组权限
   - 批量操作

#### 用户组功能

**默认组：**
- 基础权限
- 标准积分消耗
- 有限域名访问

**VIP组：**
- 更多域名权限
- 积分优惠
- 优先支持

**SVIP组：**
- 全域名权限
- 部分记录免费
- 专属客服

### 域名管理

#### 添加域名

1. **通过DNS提供商添加**
   - Cloudflare：使用API Token
   - 彩虹DNS：使用API密钥
   - 自动获取域名列表

2. **手动添加域名**
   - 填写域名信息
   - 选择DNS提供商
   - 设置域名状态

#### 域名配置

- **域名状态**：启用、禁用
- **用户组权限**：设置哪些用户组可以访问
- **记录限制**：设置记录数量限制
- **积分设置**：设置积分消耗规则

#### 域名监控

- **DNS记录统计**：各类型记录数量
- **用户使用情况**：哪些用户在使用
- **性能监控**：解析速度和可用性

### 系统配置

#### 基本设置

1. **站点信息**
   - 站点名称
   - 站点描述
   - 联系信息

2. **用户设置**
   - 注册开关
   - 默认积分
   - 邀请奖励

3. **安全设置**
   - 登录尝试限制
   - 密码强度要求
   - 验证码设置

#### 邮件配置

1. **SMTP设置**
   - 邮件服务器
   - 端口和加密
   - 认证信息

2. **邮件模板**
   - 注册验证邮件
   - 密码重置邮件
   - 系统通知邮件

#### 积分设置

- **积分规则**：各记录类型消耗
- **用户组差异**：不同组不同消耗
- **充值设置**：充值卡密生成

---

## 🔧 高级配置

### SMTP邮件配置

#### 邮件服务商配置

**QQ邮箱配置：**
```
服务器：smtp.qq.com
端口：465
加密：SSL
用户名：完整QQ邮箱地址
密码：邮箱授权码
```

**Gmail配置：**
```
服务器：smtp.gmail.com
端口：465
加密：SSL
用户名：完整Gmail地址
密码：应用专用密码
```

**163邮箱配置：**
```
服务器：smtp.163.com
端口：465
加密：SSL
用户名：完整163邮箱地址
密码：邮箱授权码
```

#### 邮件模板自定义

1. **进入模板编辑**
   - 管理后台 → SMTP设置
   - 点击"邮件模板"按钮

2. **编辑模板内容**
   - 支持HTML格式
   - 使用变量替换
   - 实时预览效果

3. **模板变量**
   - `{$username}` - 用户名
   - `{$code}` - 验证码
   - `{$time}` - 时间

### DNS提供商配置

#### Cloudflare API配置

1. **获取API Token**
   - 登录Cloudflare控制台
   - My Profile → API Tokens
   - 创建Custom token

2. **权限设置**
   ```
   Zone:Read - 读取域名信息
   DNS:Edit - 编辑DNS记录
   ```

3. **添加账户**
   - 后台 → 管理CF账户
   - 填写Token信息
   - 测试连接

#### 彩虹DNS API配置

1. **获取API信息**
   - 登录彩虹DNS控制台
   - 获取API密钥和用户ID
   - 记录API基础URL

2. **配置账户**
   - 后台 → 管理彩虹账户
   - 填写API信息
   - 验证连接

### 安全设置

#### 访问控制

1. **IP白名单**
   - 限制管理员IP访问
   - 防止未授权访问

2. **登录保护**
   - 登录失败锁定
   - 验证码保护
   - 强密码要求

#### 数据安全

1. **数据库安全**
   - 定期备份
   - 访问权限控制
   - 加密存储

2. **文件安全**
   - 敏感文件保护
   - 上传文件检查
   - 目录权限设置

### 性能优化

#### 服务器优化

1. **PHP优化**
   ```ini
   memory_limit = 256M
   max_execution_time = 30
   upload_max_filesize = 10M
   ```

2. **数据库优化**
   - 定期清理日志
   - 优化查询语句
   - 建立索引

#### 缓存优化

1. **静态资源缓存**
   - CSS/JS文件缓存
   - 图片资源缓存
   - CDN加速

2. **数据库缓存**
   - 查询结果缓存
   - 配置信息缓存
   - 会话数据缓存

---

## 🐛 故障排除

### 常见错误

#### 安装问题

**问题：无法访问安装页面**
```
解决方案：
1. 检查Web服务器配置
2. 确认PHP扩展已安装
3. 检查文件权限
```

**问题：数据库初始化失败**
```
解决方案：
1. 检查data目录权限
2. 确认SQLite3扩展
3. 查看错误日志
```

#### 邮件问题

**问题：邮件发送失败**
```
解决方案：
1. 检查SMTP配置
2. 验证邮箱认证信息
3. 检查防火墙设置
4. 查看邮件日志
```

**问题：验证码收不到**
```
解决方案：
1. 检查垃圾邮件文件夹
2. 验证邮箱地址正确性
3. 检查邮件模板配置
```

#### DNS问题

**问题：DNS记录不生效**
```
解决方案：
1. 检查DNS提供商配置
2. 验证API权限
3. 检查记录格式
4. 等待DNS传播
```

**问题：API连接失败**
```
解决方案：
1. 检查网络连接
2. 验证API密钥
3. 检查服务器防火墙
4. 查看API日志
```

### 日志分析

#### 系统日志

**位置：** `data/logs/`
- `system.log` - 系统运行日志
- `error.log` - 错误日志
- `access.log` - 访问日志

#### 日志级别

- **DEBUG** - 调试信息
- **INFO** - 一般信息
- **WARNING** - 警告信息
- **ERROR** - 错误信息
- **CRITICAL** - 严重错误

#### 日志分析工具

```bash
# 查看最新错误
tail -f data/logs/error.log

# 搜索特定错误
grep "SMTP" data/logs/system.log

# 统计错误数量
grep -c "ERROR" data/logs/system.log
```

### 性能监控

#### 系统监控

1. **服务器资源**
   - CPU使用率
   - 内存使用情况
   - 磁盘空间
   - 网络连接

2. **应用性能**
   - 响应时间
   - 并发用户数
   - 数据库查询时间
   - 缓存命中率

#### 监控工具

```bash
# 系统资源监控
htop
iostat -x 1

# 网络连接监控
netstat -an | grep :80
ss -tuln

# 数据库监控
sqlite3 data/cloudflare_dns.db "SELECT COUNT(*) FROM users;"
```

### 备份恢复

#### 数据备份

1. **数据库备份**
   ```bash
   cp data/cloudflare_dns.db data/backup/cloudflare_dns_$(date +%Y%m%d).db
   ```

2. **文件备份**
   ```bash
   tar -czf backup_$(date +%Y%m%d).tar.gz .
   ```

3. **自动备份脚本**
   ```bash
   #!/bin/bash
   DATE=$(date +%Y%m%d_%H%M%S)
   cp data/cloudflare_dns.db data/backup/cloudflare_dns_$DATE.db
   find data/backup/ -name "*.db" -mtime +7 -delete
   ```

#### 数据恢复

1. **数据库恢复**
   ```bash
   cp data/backup/cloudflare_dns_20240101.db data/cloudflare_dns.db
   chmod 666 data/cloudflare_dns.db
   ```

2. **文件恢复**
   ```bash
   tar -xzf backup_20240101.tar.gz
   ```

#### 备份策略

- **每日备份**：数据库和配置文件
- **每周备份**：完整系统备份
- **每月备份**：长期归档备份
- **异地备份**：重要数据异地存储

---

## 📞 技术支持

### 获取帮助

| 帮助方式 | 响应时间 | 适用场景 |
|---------|---------|---------|
| **📖 文档查询** | 即时 | 基础配置问题 |
| **💬 QQ群交流** | 1-2小时 | 技术讨论和问题 |
| **🐛 Issue报告** | 1-3天 | Bug报告和功能请求 |
| **📧 邮件支持** | 1-2天 | 商业技术支持 |

### 联系方式

- **GitHub仓库**：[cloudflare-DNS](https://github.com/976853694/cloudflare-DNS)
- **QQ交流群**：[六趣M技术群](https://qm.qq.com/q/qYN7MywxO0) (群号: 1044379774)
- **在线演示**：[dns.6qu.cc](https://dns.6qu.cc/)

### 贡献指南

我们欢迎所有形式的贡献：

- 🐛 **Bug报告** - 在GitHub Issues提交问题
- ✨ **功能建议** - 提出新功能想法
- 📝 **文档改进** - 完善项目文档
- 💻 **代码贡献** - 提交Pull Request

---

<div align="center">
  <p><strong>📚 六趣DNS Wiki 文档</strong></p>
  <p>持续更新中，如有问题请及时反馈</p>
  <p>Made with ❤️ by 六趣M</p>
</div>
