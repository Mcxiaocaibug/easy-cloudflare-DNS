# GitHub Container Registry 快速发布指南

## 🚀 三步发布到 GHCR

### 步骤 1: 启用 GitHub Actions 和 Packages

1. 进入 GitHub 仓库 → `Settings` → `Actions` → `General`
2. 选择 `Read and write permissions`
3. 保存

### 步骤 2: 推送代码

```bash
git add .
git commit -m "Add Docker support"
git push origin main
```

### 步骤 3: 创建版本标签（可选）

```bash
git tag v1.0.0
git push origin v1.0.0
```

✅ **完成！** GitHub Actions 会自动构建并推送镜像到 ghcr.io

---

## 📦 查看发布的镜像

访问: `https://github.com/YOUR_USERNAME/REPO_NAME/pkgs/container/REPO_NAME`

---

## 🎯 使用发布的镜像

### 直接使用

修改 `docker-compose.yml`：

```yaml
services:
  app:
    # 注释掉 build
    # build: .
    
    # 使用镜像
    image: ghcr.io/YOUR_USERNAME/REPO_NAME:latest
```

### 在新服务器部署

```bash
# 1. 创建配置文件
cat > docker-compose.yml << 'EOF'
version: '3.8'
services:
  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: root123
      MYSQL_DATABASE: cloudflare_dns
      MYSQL_USER: cloudflare
      MYSQL_PASSWORD: pass123
    volumes:
      - mysql_data:/var/lib/mysql

  app:
    image: ghcr.io/YOUR_USERNAME/REPO_NAME:latest
    depends_on:
      - mysql
    environment:
      DATABASE_URL: mysql://cloudflare:pass123@mysql:3306/cloudflare_dns
      ADMIN_USERNAME: admin
      ADMIN_PASSWORD: admin123456
    ports:
      - "8080:80"
    volumes:
      - app_data:/var/www/html/data

volumes:
  mysql_data:
  app_data:
EOF

# 2. 启动
docker-compose up -d
```

---

## 🔑 手动发布（可选）

### 1. 创建 GitHub Token

访问: https://github.com/settings/tokens

权限: `write:packages` + `read:packages`

### 2. 登录 GHCR

```bash
echo YOUR_TOKEN | docker login ghcr.io -u YOUR_USERNAME --password-stdin
```

### 3. 构建并推送

```bash
# 构建
docker build -t ghcr.io/YOUR_USERNAME/REPO_NAME:latest .

# 推送
docker push ghcr.io/YOUR_USERNAME/REPO_NAME:latest
```

---

## 🏷️ 可用标签

| 标签 | 说明 |
|------|------|
| `latest` | 最新主分支 |
| `v1.0.0` | 特定版本 |
| `v1.0` | 主次版本 |
| `v1` | 主版本 |

---

## 🌐 设置为公开镜像

1. 进入包页面
2. `Package settings` → `Change visibility`
3. 选择 `Public`
4. 确认

---

## 🔍 故障排除

### 问题: Actions 构建失败

**解决**:
1. 检查 `Settings` → `Actions` → 权限
2. 确保选择了 `Read and write permissions`

### 问题: 无法拉取镜像

**解决**:
```bash
# 如果是私有镜像，需要先登录
docker login ghcr.io
```

### 问题: 推送被拒绝

**解决**:
1. 确认 Token 权限正确
2. 确认镜像名称格式: `ghcr.io/username/repo:tag`

---

## 📚 完整文档

详细说明请查看: [GHCR_PUBLISH_GUIDE.md](./GHCR_PUBLISH_GUIDE.md)

---

**快速开始完成！** 🎉

