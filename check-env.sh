#!/bin/bash

# Cloudflare DNS 管理系统 - 环境检查脚本

echo "=========================================="
echo "Cloudflare DNS 管理系统"
echo "环境检查工具"
echo "=========================================="
echo ""

# 颜色定义
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 检查结果统计
PASSED=0
FAILED=0
WARNINGS=0

# 检查函数
check() {
    local name=$1
    local command=$2
    
    if eval "$command" > /dev/null 2>&1; then
        echo -e "${GREEN}✓${NC} $name"
        ((PASSED++))
        return 0
    else
        echo -e "${RED}✗${NC} $name"
        ((FAILED++))
        return 1
    fi
}

check_version() {
    local name=$1
    local command=$2
    local version=$3
    
    if command -v $command &> /dev/null; then
        local current_version=$($command --version 2>&1 | head -n1)
        echo -e "${GREEN}✓${NC} $name - $current_version"
        ((PASSED++))
        return 0
    else
        echo -e "${RED}✗${NC} $name - 未安装"
        ((FAILED++))
        return 1
    fi
}

warning() {
    local message=$1
    echo -e "${YELLOW}⚠${NC} $message"
    ((WARNINGS++))
}

echo "📋 检查系统环境..."
echo ""

# 1. 检查 Docker
echo "1. Docker 环境"
if check_version "   Docker" "docker" ""; then
    # 检查 Docker 是否运行
    if docker ps > /dev/null 2>&1; then
        echo -e "${GREEN}   Docker 服务运行正常${NC}"
    else
        echo -e "${RED}   Docker 服务未运行${NC}"
        warning "   请启动 Docker 服务"
    fi
else
    echo -e "${RED}   请安装 Docker: https://docs.docker.com/get-docker/${NC}"
fi
echo ""

# 2. 检查 Docker Compose
echo "2. Docker Compose"
if check_version "   Docker Compose" "docker-compose" ""; then
    :
else
    echo -e "${RED}   请安装 Docker Compose: https://docs.docker.com/compose/install/${NC}"
fi
echo ""

# 3. 检查配置文件
echo "3. 配置文件"
if [ -f .env ]; then
    echo -e "${GREEN}✓${NC} .env 文件存在"
    ((PASSED++))
    
    # 检查关键配置
    echo ""
    echo "   关键配置检查:"
    
    # 检查数据库密码
    DB_PASSWORD=$(grep "^DB_PASSWORD=" .env | cut -d '=' -f2)
    if [ "$DB_PASSWORD" = "cloudflare_password_123" ]; then
        warning "   数据库密码使用默认值，建议修改"
    else
        echo -e "${GREEN}   ✓${NC} 数据库密码已自定义"
    fi
    
    # 检查管理员密码
    ADMIN_PASSWORD=$(grep "^ADMIN_PASSWORD=" .env | cut -d '=' -f2)
    if [ "$ADMIN_PASSWORD" = "admin123456" ]; then
        warning "   管理员密码使用默认值，建议修改"
    else
        echo -e "${GREEN}   ✓${NC} 管理员密码已自定义"
    fi
    
    # 检查端口
    APP_PORT=$(grep "^APP_PORT=" .env | cut -d '=' -f2)
    APP_PORT=${APP_PORT:-8080}
    
    # 检查端口是否被占用
    if lsof -Pi :$APP_PORT -sTCP:LISTEN -t >/dev/null 2>&1; then
        warning "   端口 $APP_PORT 已被占用，请修改 APP_PORT"
    else
        echo -e "${GREEN}   ✓${NC} 端口 $APP_PORT 可用"
    fi
    
else
    echo -e "${RED}✗${NC} .env 文件不存在"
    ((FAILED++))
    echo -e "${YELLOW}   运行以下命令创建配置文件:${NC}"
    echo "   cp env.example .env"
fi
echo ""

# 4. 检查磁盘空间
echo "4. 磁盘空间"
AVAILABLE_SPACE=$(df -h . | awk 'NR==2 {print $4}')
echo -e "${GREEN}✓${NC} 可用空间: $AVAILABLE_SPACE"
((PASSED++))
if [ -d "$(pwd)" ]; then
    warning "   建议至少保留 2GB 空间用于数据存储"
fi
echo ""

# 5. 检查网络连接
echo "5. 网络连接"
if ping -c 1 hub.docker.com > /dev/null 2>&1; then
    echo -e "${GREEN}✓${NC} Docker Hub 连接正常"
    ((PASSED++))
else
    echo -e "${YELLOW}⚠${NC} 无法连接到 Docker Hub"
    ((WARNINGS++))
    warning "   首次运行可能需要下载镜像，请确保网络连接"
fi
echo ""

# 6. 检查已存在的容器
echo "6. 容器状态"
if docker-compose ps 2>/dev/null | grep -q "Up"; then
    echo -e "${GREEN}✓${NC} 检测到运行中的容器"
    docker-compose ps
    ((PASSED++))
else
    echo -e "${YELLOW}⚠${NC} 未检测到运行中的容器"
    ((WARNINGS++))
fi
echo ""

# 7. 检查 Docker Volumes
echo "7. 数据卷"
if docker volume ls | grep -q "cloudflare-dns"; then
    echo -e "${GREEN}✓${NC} 检测到已存在的数据卷"
    docker volume ls | grep "cloudflare-dns"
    ((PASSED++))
    warning "   如需重新安装，请先删除数据卷: docker-compose down -v"
else
    echo -e "${YELLOW}⚠${NC} 未检测到数据卷（首次安装正常）"
    ((WARNINGS++))
fi
echo ""

# 总结
echo "=========================================="
echo "检查完成"
echo "=========================================="
echo -e "通过: ${GREEN}$PASSED${NC}"
echo -e "失败: ${RED}$FAILED${NC}"
echo -e "警告: ${YELLOW}$WARNINGS${NC}"
echo ""

if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}✓ 环境检查通过，可以开始部署！${NC}"
    echo ""
    echo "下一步操作:"
    echo "  1. 检查并编辑 .env 配置文件"
    echo "  2. 运行 ./start.sh 启动服务"
    echo "  3. 访问 http://localhost:${APP_PORT:-8080}"
else
    echo -e "${RED}✗ 环境检查未通过，请解决上述问题后重试${NC}"
    exit 1
fi

if [ $WARNINGS -gt 0 ]; then
    echo ""
    echo -e "${YELLOW}注意: 有 $WARNINGS 个警告项，建议检查并处理${NC}"
fi

echo ""

