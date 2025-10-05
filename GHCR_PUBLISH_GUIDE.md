# 发布 Docker 镜像到 GitHub Container Registry 指南

本指南介绍如何将 Cloudflare DNS 管理系统构建为 Docker 镜像并发布到 GitHub Container Registry (ghcr.io)。

## 📋 前提条件

1. **GitHub 账户**: 需要有 GitHub 账户
2. **Git 仓库**: 项目已推送到 GitHub
3. **权限设置**: 启用 GitHub Actions 和 Packages 权限

## 🚀 方式一：自动发布（推荐）

使用 GitHub Actions 自动构建和发布镜像。

### 1. 启用 GitHub Actions

确保仓库设置中启用了 GitHub Actions：

1. 进入 GitHub 仓库
2. 点击 `Settings` → `Actions` → `General`
3. 选择 `Allow all actions and reusable workflows`
4. 保存设置

### 2. 配置 Packages 权限

1. 进入 `Settings` → `Actions` → `General`
2. 向下滚动到 `Workflow permissions`
3. 选择 `Read and write permissions`
4. 勾选 `Allow GitHub Actions to create and approve pull requests`
5. 保存设置

### 3. 推送代码触发构建

项目已包含 `.github/workflows/docker-publish.yml` 工作流文件，会在以下情况自动触发：

#### 触发条件

- **推送到主分支**: 推送到 `main` 或 `master` 分支
- **创建标签**: 推送格式为 `v*.*.*` 的标签（如 `v1.0.0`）
- **Pull Request**: 创建或更新 PR（仅构建不推送）
- **手动触发**: 在 Actions 页面手动运行

#### 推送代码

```bash
# 推送到主分支（触发 latest 标签）
git add .
git commit -m "Add Docker support"
git push origin main

# 推送版本标签（触发版本标签）
git tag v1.0.0
git push origin v1.0.0
```

### 4. 查看构建进度

1. 进入 GitHub 仓库
2. 点击 `Actions` 标签
3. 查看最新的工作流运行状态
4. 点击工作流查看详细日志

### 5. 查看发布的镜像

1. 进入 GitHub 仓库首页
2. 右侧栏找到 `Packages` 部分
3. 点击包名称查看详情
4. 查看可用的标签和版本

## 🛠️ 方式二：手动发布

手动构建和推送 Docker 镜像到 ghcr.io。

### 1. 登录 GitHub Container Registry

首先需要创建 Personal Access Token (PAT)：

#### 创建 Token

1. 进入 GitHub 设置: https://github.com/settings/tokens
2. 点击 `Generate new token` → `Generate new token (classic)`
3. 设置权限:
   - 勾选 `write:packages`
   - 勾选 `read:packages`
   - 勾选 `delete:packages` (可选)
4. 点击 `Generate token`
5. **复制并保存 Token**（只显示一次）

#### 登录 GHCR

```bash
# 使用 Token 登录
echo YOUR_TOKEN | docker login ghcr.io -u YOUR_GITHUB_USERNAME --password-stdin

# 或者交互式登录
docker login ghcr.io
# Username: YOUR_GITHUB_USERNAME
# Password: YOUR_TOKEN
```

### 2. 构建 Docker 镜像

```bash
# 进入项目目录
cd cloudflare-DNS-main

# 构建镜像（替换 YOUR_GITHUB_USERNAME 和 REPO_NAME）
docker build -t ghcr.io/YOUR_GITHUB_USERNAME/REPO_NAME:latest .

# 构建多平台镜像（可选）
docker buildx build --platform linux/amd64,linux/arm64 \
  -t ghcr.io/YOUR_GITHUB_USERNAME/REPO_NAME:latest .
```

**示例**:
```bash
# 如果您的 GitHub 用户名是 johndoe，仓库名是 cloudflare-dns
docker build -t ghcr.io/johndoe/cloudflare-dns:latest .
```

### 3. 推送镜像

```bash
# 推送 latest 标签
docker push ghcr.io/YOUR_GITHUB_USERNAME/REPO_NAME:latest

# 推送特定版本
docker tag ghcr.io/YOUR_GITHUB_USERNAME/REPO_NAME:latest \
     ghcr.io/YOUR_GITHUB_USERNAME/REPO_NAME:v1.0.0
docker push ghcr.io/YOUR_GITHUB_USERNAME/REPO_NAME:v1.0.0
```

### 4. 设置镜像为公开（可选）

默认情况下，包是私有的。要设置为公开：

1. 进入包页面: https://github.com/users/YOUR_USERNAME/packages/container/REPO_NAME
2. 点击 `Package settings`
3. 向下滚动到 `Danger Zone`
4. 点击 `Change visibility`
5. 选择 `Public`
6. 输入仓库名称确认
7. 点击 `I understand, change package visibility`

## 📦 使用发布的镜像

### 方式 1: 修改 docker-compose.yml

将本地构建改为使用发布的镜像：

```yaml
services:
  app:
    # 注释掉 build 行
    # build: .
    
    # 使用发布的镜像
    image: ghcr.io/YOUR_GITHUB_USERNAME/REPO_NAME:latest
    
    container_name: cloudflare-dns-app
    restart: always
    # ... 其他配置保持不变
```

### 方式 2: 直接运行容器

```bash
docker run -d \
  --name cloudflare-dns-app \
  -p 8080:80 \
  -e DATABASE_URL=mysql://user:pass@mysql:3306/cloudflare_dns \
  -e ADMIN_USERNAME=admin \
  -e ADMIN_PASSWORD=admin123456 \
  ghcr.io/YOUR_GITHUB_USERNAME/REPO_NAME:latest
```

### 方式 3: 在其他服务器上部署

```bash
# 1. 拉取镜像
docker pull ghcr.io/YOUR_GITHUB_USERNAME/REPO_NAME:latest

# 2. 创建 docker-compose.yml
cat > docker-compose.yml << 'EOF'
version: '3.8'

services:
  mysql:
    image: mysql:8.0
    container_name: cloudflare-dns-mysql
    restart: always
    environment:
      MYSQL_ROOT_PASSWORD: root_password_123
      MYSQL_DATABASE: cloudflare_dns
      MYSQL_USER: cloudflare
      MYSQL_PASSWORD: cloudflare_password_123
    volumes:
      - mysql_data:/var/lib/mysql
    networks:
      - cloudflare-dns-network

  app:
    image: ghcr.io/YOUR_GITHUB_USERNAME/REPO_NAME:latest
    container_name: cloudflare-dns-app
    restart: always
    depends_on:
      - mysql
    environment:
      DATABASE_URL: mysql://cloudflare:cloudflare_password_123@mysql:3306/cloudflare_dns
      ADMIN_USERNAME: admin
      ADMIN_PASSWORD: admin123456
      SITE_NAME: Cloudflare DNS管理系统
    ports:
      - "8080:80"
    volumes:
      - app_data:/var/www/html/data
    networks:
      - cloudflare-dns-network

networks:
  cloudflare-dns-network:
    driver: bridge

volumes:
  mysql_data:
  app_data:
EOF

# 3. 启动服务
docker-compose up -d
```

## 🏷️ 版本标签说明

GitHub Actions 会自动创建以下标签：

| 标签格式 | 说明 | 示例 |
|---------|------|------|
| `latest` | 最新的主分支构建 | `latest` |
| `v1.0.0` | 完整版本号 | `v1.0.0` |
| `v1.0` | 主次版本号 | `v1.0` |
| `v1` | 主版本号 | `v1` |
| `main` | 主分支名称 | `main` |

### 使用特定版本

```bash
# 使用最新版本
docker pull ghcr.io/YOUR_USERNAME/REPO_NAME:latest

# 使用特定版本
docker pull ghcr.io/YOUR_USERNAME/REPO_NAME:v1.0.0

# 使用主版本
docker pull ghcr.io/YOUR_USERNAME/REPO_NAME:v1
```

## 🔄 更新镜像

### 发布新版本

```bash
# 1. 修改代码
git add .
git commit -m "Update feature"

# 2. 推送到主分支（触发 latest）
git push origin main

# 3. 创建版本标签（触发版本构建）
git tag v1.0.1
git push origin v1.0.1
```

### 更新已部署的服务

```bash
# 1. 拉取最新镜像
docker pull ghcr.io/YOUR_USERNAME/REPO_NAME:latest

# 2. 重启服务
docker-compose down
docker-compose up -d

# 或使用 pull 和 up
docker-compose pull
docker-compose up -d
```

## 🔍 故障排除

### 问题 1: 权限被拒绝

**错误**: `denied: permission_denied`

**解决方案**:
1. 确认已登录 ghcr.io
2. 确认 Token 有 `write:packages` 权限
3. 确认仓库名称正确

### 问题 2: 镜像不存在

**错误**: `manifest unknown`

**解决方案**:
1. 确认镜像已成功推送
2. 检查镜像名称和标签是否正确
3. 如果是私有镜像，确认已登录

### 问题 3: Actions 构建失败

**解决方案**:
1. 查看 Actions 日志
2. 确认 Dockerfile 语法正确
3. 确认仓库权限设置正确

### 问题 4: 无法拉取私有镜像

**解决方案**:
```bash
# 创建 Token 并登录
echo YOUR_TOKEN | docker login ghcr.io -u YOUR_USERNAME --password-stdin

# 然后拉取镜像
docker pull ghcr.io/YOUR_USERNAME/REPO_NAME:latest
```

## 📊 完整示例

假设您的 GitHub 用户名是 `johndoe`，仓库名是 `cloudflare-dns`：

### 1. 推送代码和标签

```bash
# 克隆或进入仓库
cd cloudflare-dns

# 添加所有文件
git add .
git commit -m "Add Docker support and CI/CD"

# 推送到主分支
git push origin main

# 创建并推送版本标签
git tag v1.0.0
git push origin v1.0.0
```

### 2. 等待 Actions 完成

访问: https://github.com/johndoe/cloudflare-dns/actions

### 3. 在新服务器上部署

```bash
# 创建部署目录
mkdir cloudflare-dns-deploy
cd cloudflare-dns-deploy

# 创建 docker-compose.yml
cat > docker-compose.yml << 'EOF'
version: '3.8'

services:
  mysql:
    image: mysql:8.0
    restart: always
    environment:
      MYSQL_ROOT_PASSWORD: SecureRootPass123
      MYSQL_DATABASE: cloudflare_dns
      MYSQL_USER: cloudflare
      MYSQL_PASSWORD: SecurePass123
    volumes:
      - mysql_data:/var/lib/mysql

  app:
    image: ghcr.io/johndoe/cloudflare-dns:latest
    restart: always
    depends_on:
      - mysql
    environment:
      DATABASE_URL: mysql://cloudflare:SecurePass123@mysql:3306/cloudflare_dns
      ADMIN_USERNAME: admin
      ADMIN_PASSWORD: MySecureAdminPass123
      SITE_NAME: My DNS Manager
    ports:
      - "8080:80"
    volumes:
      - app_data:/var/www/html/data

volumes:
  mysql_data:
  app_data:
EOF

# 启动服务
docker-compose up -d

# 查看日志
docker-compose logs -f
```

### 4. 访问系统

打开浏览器访问: http://your-server-ip:8080

## 📚 相关资源

- [GitHub Container Registry 文档](https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-container-registry)
- [GitHub Actions 文档](https://docs.github.com/en/actions)
- [Docker Hub vs GHCR 对比](https://docs.github.com/en/packages/learn-github-packages/introduction-to-github-packages)

## 💡 最佳实践

1. **使用版本标签**: 生产环境使用特定版本标签，避免使用 `latest`
2. **定期更新**: 定期拉取最新镜像更新安全补丁
3. **多阶段构建**: 优化 Dockerfile 减小镜像大小
4. **安全扫描**: 使用 GitHub Security 扫描漏洞
5. **文档更新**: 在 README 中说明如何使用发布的镜像

## 🔒 安全提示

1. **保护 Token**: 不要将 Personal Access Token 提交到代码
2. **私有镜像**: 敏感项目使用私有镜像
3. **定期轮换**: 定期更换 Access Token
4. **最小权限**: Token 只授予必要权限
5. **审计日志**: 定期检查包的访问日志

## 📝 更新 README

建议在项目 README.md 中添加镜像使用说明：

```markdown
## 使用预构建镜像

无需手动构建，直接使用发布的镜像：

### 快速启动

\`\`\`bash
# 创建 docker-compose.yml
wget https://raw.githubusercontent.com/YOUR_USERNAME/cloudflare-dns/main/docker-compose.yml

# 修改镜像地址
sed -i 's|build: .|image: ghcr.io/YOUR_USERNAME/cloudflare-dns:latest|' docker-compose.yml

# 启动服务
docker-compose up -d
\`\`\`

### 可用标签

- \`latest\` - 最新稳定版
- \`v1.0.0\` - 特定版本
- \`main\` - 主分支构建

查看所有标签: https://github.com/YOUR_USERNAME/cloudflare-dns/pkgs/container/cloudflare-dns
```

---

**Made with ❤️ by 六趣M**

如有问题，请提交 Issue 或加入 QQ 群：1044379774

