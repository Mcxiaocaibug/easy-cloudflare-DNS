# 🌐 Cloudflare DNS管理系统

一个基于PHP开发的专业DNS记录管理平台，支持Cloudflare API集成、用户积分系统、批量同步等功能。

## ✨ 主要特性

### 🎯 **核心功能**
- **智能DNS管理** - 支持A、AAAA、CNAME、TXT等多种记录类型
- **前缀查询系统** - 实时检查子域名前缀可用性
- **多域名支持** - 统一管理多个Cloudflare域名
- **用户积分系统** - 基于积分的DNS记录创建机制
- **批量同步** - 从Cloudflare批量导入现有DNS记录

### 🎨 **用户体验**
- **科技风格界面** - 现代化深色主题设计
- **响应式布局** - 完美适配桌面端和移动端
- **一键添加解析** - 从主页直接跳转到添加页面
- **实时状态反馈** - 动态显示操作结果和系统状态

### 🛡️ **安全特性**
- **用户权限管理** - 完善的用户和管理员权限体系
- **操作日志记录** - 详细记录所有系统操作
- **前缀黑名单** - 防止恶意或不当的子域名注册
- **登录保护** - 验证码和登录尝试限制

### ⚙️ **管理功能**
- **用户管理** - 用户注册、积分管理、状态控制
- **域名管理** - Cloudflare账户配置、域名状态管理
- **卡密系统** - 积分充值卡密生成和管理
- **系统设置** - 灵活的系统参数配置
- **批量同步** - 管理员专用的DNS记录批量导入

## 🚀 快速开始

### 📋 **系统要求**
- PHP 7.4+ 或 8.0+
- SQLite3 扩展
- cURL 扩展
- 支持 .htaccess 的Web服务器

### 📦 **安装步骤**

1. **克隆项目**
```bash
git clone https://github.com/976853694/cloudflare-DNS.git
cd cloudflare-dns-manager
```

2. **设置权限**
```bash
chmod 755 data/
chmod 666 data/cloudflare_dns.db
```

3. **配置Web服务器**
- 将项目目录设置为网站根目录
- 确保支持 .htaccess 重写规则

4. **访问安装页面**
```
http://yourdomain.com/install.php
```

5. **完成安装向导**
- 设置管理员账户
- 配置系统参数
- 添加Cloudflare域名

### ⚙️ **配置说明**

#### **Cloudflare API配置**
1. 登录 Cloudflare 控制台
2. 获取 Global API Key 或创建 API Token
3. 在系统中添加域名配置：
   - 域名名称
   - Zone ID
   - API Key/Token
   - 邮箱地址

#### **系统设置**
- **站点名称** - 自定义系统名称
- **用户注册** - 开启/关闭用户注册
- **默认积分** - 新用户初始积分
- **记录消耗** - 创建DNS记录所需积分

## 📖 使用指南

### 👤 **用户功能**

#### **前缀查询**
1. 在主页输入想要的子域名前缀
2. 系统显示在所有域名下的可用性
3. 点击"添加"按钮直接创建DNS记录

#### **DNS记录管理**
1. 登录用户控制台
2. 选择目标域名
3. 添加/编辑/删除DNS记录
4. 实时同步到Cloudflare

#### **积分充值**
1. 获取充值卡密
2. 在用户中心输入卡密
3. 积分自动充值到账户

### 👨‍💼 **管理员功能**

#### **用户管理**
- 查看所有用户信息
- 调整用户积分余额
- 启用/禁用用户账户
- 查看用户操作历史

#### **域名管理**
- 添加/编辑Cloudflare域名配置
- 测试API连接状态
- 管理域名启用状态
- 单域名DNS记录同步

#### **批量同步**
- 选择多个域名进行批量同步
- 从Cloudflare导入现有DNS记录
- 系统记录与用户记录分离管理
- 详细的同步结果报告

#### **系统维护**
- 生成和管理充值卡密
- 配置系统参数
- 查看操作日志
- 管理公告和前缀黑名单

## 🏗️ 技术架构

### **后端技术**
- **PHP** - 核心开发语言
- **SQLite3** - 轻量级数据库
- **Cloudflare API** - DNS记录管理

### **前端技术**
- **Bootstrap 5** - 响应式UI框架
- **FontAwesome** - 图标库
- **jQuery** - JavaScript库
- **自定义CSS** - 科技风格主题

### **数据库设计**
- **用户系统** - users, admins, invitations
- **DNS管理** - domains, dns_records, dns_record_types
- **积分系统** - card_keys, card_key_usage
- **系统功能** - settings, action_logs, announcements

## 🔧 开发指南

### **项目结构**
```
├── admin/              # 管理员后台
├── user/               # 用户前台
├── config/             # 配置文件
├── includes/           # 公共函数库
├── assets/             # 静态资源
├── data/               # 数据库文件
├── index.php           # 主页
├── install.php         # 安装程序
└── upgrade.php         # 升级程序
```

### **核心类库**
- **Database** - 数据库连接和操作
- **CloudflareAPI** - Cloudflare API封装
- **Security** - 安全验证和保护
- **Functions** - 通用工具函数

### **开发规范**
- 遵循PSR编码标准
- 使用预处理语句防止SQL注入
- 实施输入验证和输出转义
- 记录详细的操作日志

## 🤝 贡献指南

欢迎提交Issue和Pull Request来改进项目！

### **贡献流程**
1. Fork 项目
2. 创建功能分支 (`git checkout -b feature/AmazingFeature`)
3. 提交更改 (`git commit -m 'Add some AmazingFeature'`)
4. 推送到分支 (`git push origin feature/AmazingFeature`)
5. 创建 Pull Request


## 📄 许可证

本项目采用 MIT 许可证 - 查看 [LICENSE](LICENSE) 文件了解详情。

## 🙏 致谢

- [Cloudflare](https://www.cloudflare.com/) - 提供强大的DNS API
- [Bootstrap](https://getbootstrap.com/) - 优秀的前端框架
- [FontAwesome](https://fontawesome.com/) - 丰富的图标库

## 📞 支持

如果您在使用过程中遇到问题，请：

1. 查看 [Wiki](https://github.com/976853694/cloudflare-DNSwiki)
2. 搜索 [Issues](https://github.com/976853694/cloudflare-DNS/issues)
3. 创建新的 [Issue](https://github.com/976853694/cloudflare-DNS/issues/new)

---

⭐ 如果这个项目对您有帮助，请给它一个星标！
