#!/bin/bash

# Cloudflare DNS 管理系统 - 快速启动脚本

set -e

echo "=========================================="
echo "Cloudflare DNS 管理系统 - Docker 部署"
echo "=========================================="
echo ""

# 检查 Docker 是否安装
if ! command -v docker &> /dev/null; then
    echo "❌ 错误: Docker 未安装"
    echo "请先安装 Docker: https://docs.docker.com/get-docker/"
    exit 1
fi

# 检查 Docker Compose 是否安装
if ! command -v docker-compose &> /dev/null; then
    echo "❌ 错误: Docker Compose 未安装"
    echo "请先安装 Docker Compose: https://docs.docker.com/compose/install/"
    exit 1
fi

echo "✓ Docker 和 Docker Compose 已安装"
echo ""

# 检查 .env 文件是否存在
if [ ! -f .env ]; then
    echo "📝 .env 文件不存在，正在从 env.example 创建..."
    cp env.example .env
    echo "✓ .env 文件已创建"
    echo ""
    echo "⚠️  请编辑 .env 文件，修改以下配置："
    echo "   - 数据库密码 (DB_PASSWORD, DB_ROOT_PASSWORD)"
    echo "   - 管理员账户 (ADMIN_USERNAME, ADMIN_PASSWORD)"
    echo "   - SMTP 邮件配置（如果需要邮件功能）"
    echo ""
    read -p "是否现在编辑 .env 文件？(y/N) " -n 1 -r
    echo ""
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        ${EDITOR:-nano} .env
    fi
fi

echo "🚀 正在启动服务..."
echo ""

# 构建并启动服务
docker-compose up -d

echo ""
echo "=========================================="
echo "✓ 服务启动成功！"
echo "=========================================="
echo ""

# 等待服务就绪
echo "⏳ 等待服务就绪..."
sleep 5

# 检查服务状态
docker-compose ps

echo ""
echo "📋 访问信息："
echo "=========================================="

# 从 .env 文件读取端口
APP_PORT=$(grep "^APP_PORT=" .env | cut -d '=' -f2)
APP_PORT=${APP_PORT:-8080}

ADMIN_USERNAME=$(grep "^ADMIN_USERNAME=" .env | cut -d '=' -f2)
ADMIN_USERNAME=${ADMIN_USERNAME:-admin}

echo "应用地址:     http://localhost:${APP_PORT}"
echo "管理后台:     http://localhost:${APP_PORT}/admin/login.php"
echo "用户前台:     http://localhost:${APP_PORT}/user/login.php"
echo ""
echo "默认管理员账号: ${ADMIN_USERNAME}"
echo "默认管理员密码: (请查看 .env 文件中的 ADMIN_PASSWORD)"
echo "=========================================="
echo ""
echo "💡 常用命令："
echo "  查看日志:     docker-compose logs -f"
echo "  停止服务:     docker-compose stop"
echo "  重启服务:     docker-compose restart"
echo "  完全清理:     docker-compose down -v"
echo ""
echo "📖 详细文档请查看: README.Docker.md"
echo ""

