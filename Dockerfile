# 使用PHP官方镜像作为基础镜像
FROM php:8.1-apache

# 设置工作目录
WORKDIR /var/www/html

# 安装系统依赖和PHP扩展
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libonig-dev \
    libssl-dev \
    libcurl4-openssl-dev \
    default-mysql-client \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_mysql \
    mysqli \
    gd \
    zip \
    opcache \
    curl \
    mbstring \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# 启用Apache模块
RUN a2enmod rewrite headers expires

# 配置Apache
RUN echo '<Directory /var/www/html/>' > /etc/apache2/conf-available/cloudflare-dns.conf && \
    echo '    Options Indexes FollowSymLinks' >> /etc/apache2/conf-available/cloudflare-dns.conf && \
    echo '    AllowOverride All' >> /etc/apache2/conf-available/cloudflare-dns.conf && \
    echo '    Require all granted' >> /etc/apache2/conf-available/cloudflare-dns.conf && \
    echo '</Directory>' >> /etc/apache2/conf-available/cloudflare-dns.conf && \
    a2enconf cloudflare-dns

# 设置PHP配置
RUN echo "upload_max_filesize = 10M" > /usr/local/etc/php/conf.d/uploads.ini && \
    echo "post_max_size = 10M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini

# 复制应用代码
COPY . /var/www/html/

# 创建必要的目录并设置权限
RUN mkdir -p /var/www/html/data && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html && \
    chmod -R 777 /var/www/html/data

# 复制入口脚本
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# 暴露端口
EXPOSE 80

# 设置入口点
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]

