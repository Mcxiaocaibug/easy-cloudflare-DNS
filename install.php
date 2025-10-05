<?php
/**
 * 系统安装页面
 */
session_start();

// 检查是否已经安装
if (file_exists('data/install.lock')) {
    // 显示安全警告页面
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>安全警告 - 系统已安装</title>
        <link href="assets/css/bootstrap.min.css" rel="stylesheet">
        <link href="assets/css/fontawesome.min.css" rel="stylesheet">
        <style>
            body {
                background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
            }
            .warning-container {
                background: white;
                border-radius: 15px;
                box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
                overflow: hidden;
                margin: 2rem auto;
                max-width: 600px;
            }
            .warning-header {
                background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
                color: white;
                padding: 2rem;
                text-align: center;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="warning-container">
                <div class="warning-header">
                    <h1><i class="fas fa-exclamation-triangle me-2"></i>安全警告</h1>
                    <p class="mb-0">系统已安装完成</p>
                </div>
                <div class="p-4">
                    <div class="alert alert-danger">
                        <h5><i class="fas fa-shield-alt me-2"></i>重要安全提示</h5>
                        <p>检测到系统已经安装完成，但安装文件 <code>install.php</code> 仍然存在。</p>
                        <p class="mb-0">为了系统安全，请立即删除或重命名此文件！</p>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-terminal me-2"></i>删除命令</h6>
                        </div>
                        <div class="card-body">
                            <p>在服务器上执行以下命令删除安装文件：</p>
                            <code class="d-block bg-dark text-light p-2 rounded">rm install.php</code>
                            <p class="mt-2 mb-0">或者将文件重命名：</p>
                            <code class="d-block bg-dark text-light p-2 rounded">mv install.php install.php.bak</code>
                        </div>
                    </div>
                    
                    <div class="text-center mt-4">
                        <a href="admin/login.php" class="btn btn-primary me-2">
                            <i class="fas fa-sign-in-alt me-2"></i>管理员登录
                        </a>
                        <a href="user/login.php" class="btn btn-outline-primary">
                            <i class="fas fa-users me-2"></i>用户登录
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';
$success = '';

// 环境变量自动安装（容器/无交互场景）
if (getenv('AUTO_INSTALL') === '1' || getenv('INSTALL_AUTO') === '1') {
    try {
        require_once 'config/database.php';
        $db = Database::getInstance()->getConnection();

        $admin_username = getenv('ADMIN_USERNAME') ?: 'admin';
        $admin_password = getenv('ADMIN_PASSWORD') ?: 'admin123456';
        $admin_email    = getenv('ADMIN_EMAIL') ?: 'admin@example.com';

        $db->exec("DELETE FROM admins WHERE username = 'admin'");
        $hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO admins (username, password, email) VALUES (?, ?, ?)");
        $stmt->bindValue(1, $admin_username, SQLITE3_TEXT);
        $stmt->bindValue(2, $hashed_password, SQLITE3_TEXT);
        $stmt->bindValue(3, $admin_email, SQLITE3_TEXT);
        $stmt->execute();

        $site_name = getenv('SITE_NAME') ?: '六趣DNS域名分发系统';
        $points_per_record = (string) (getenv('POINTS_PER_RECORD') !== false ? getenv('POINTS_PER_RECORD') : '1');
        $default_user_points = (string) (getenv('DEFAULT_USER_POINTS') !== false ? getenv('DEFAULT_USER_POINTS') : '100');
        $allow_registration = (string) (getenv('ALLOW_REGISTRATION') !== false ? (int)getenv('ALLOW_REGISTRATION') : 1);

        $settings = [
            'site_name' => $site_name,
            'points_per_record' => $points_per_record,
            'default_user_points' => $default_user_points,
            'allow_registration' => $allow_registration,
        ];
        foreach ($settings as $key => $value) {
            $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->bindValue(1, $value, SQLITE3_TEXT);
            $stmt->bindValue(2, $key, SQLITE3_TEXT);
            $stmt->execute();
        }

        if (!is_dir('data')) @mkdir('data', 0755, true);
        file_put_contents('data/install.lock', date('Y-m-d H:i:s'));
        header('Location: install.php?step=6');
        exit;
    } catch (Throwable $e) {
        $error = '自动安装失败: ' . $e->getMessage();
        $step = 1;
    }
}

// 处理安装步骤
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($step) {
        case 1:
            // 环境检查完成，进入下一步
            header('Location: install.php?step=2');
            exit;
            
        case 2:
            // 数据库配置（MySQL DSN）
            $mysql_dsn_input  = trim($_POST['mysql_dsn'] ?? (getenv('MYSQL_DSN') ?: ''));
            $mysql_user_input = trim($_POST['mysql_user'] ?? (getenv('MYSQL_USER') ?: ''));
            $mysql_pass_input = trim($_POST['mysql_password'] ?? (getenv('MYSQL_PASSWORD') ?: ''));
            if (!$mysql_dsn_input) {
                $error = '请填写或通过环境变量提供 MYSQL_DSN';
                break;
            }
            // 兼容 URL 风格 mysql://user:pass@host:port/dbname?charset=utf8mb4
            $dsnTest = $mysql_dsn_input;
            $userTest = $mysql_user_input;
            $passTest = $mysql_pass_input;
            if (preg_match('/^mysql:\/\//i', $mysql_dsn_input)) {
                $parts = @parse_url($mysql_dsn_input);
                if ($parts && !empty($parts['host'])) {
                    $host = $parts['host'];
                    $port = isset($parts['port']) ? $parts['port'] : '3306';
                    $dbname = isset($parts['path']) ? ltrim($parts['path'], '/') : '';
                    $queryParams = [];
                    if (!empty($parts['query'])) parse_str($parts['query'], $queryParams);
                    if (!$userTest && isset($parts['user'])) $userTest = urldecode($parts['user']);
                    if (!$passTest && isset($parts['pass'])) $passTest = urldecode($parts['pass']);
                    $charset = $queryParams['charset'] ?? 'utf8mb4';
                    $dsnTest = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
                }
            }
            try {
                $pdo = new PDO($dsnTest, $userTest, $passTest, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                // 持久化到 .env（保留原始 DSN 字符串，支持 URL 格式）
                $env = "MYSQL_DSN=\"{$mysql_dsn_input}\"\nMYSQL_USER=\"{$mysql_user_input}\"\nMYSQL_PASSWORD=\"{$mysql_pass_input}\"\n";
                file_put_contents('.env', $env);
                header('Location: install.php?step=3');
                exit;
            } catch (Throwable $e) {
                $error = '无法连接 MySQL，请检查 DSN/用户名/密码: ' . $e->getMessage();
            }
            break;
            
        case 3:
            // 管理员账户配置
            $admin_username = trim($_POST['admin_username'] ?? '');
            $admin_password = $_POST['admin_password'] ?? '';
            $admin_email = trim($_POST['admin_email'] ?? '');
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            if (!$admin_username || !$admin_password) {
                $error = '请填写管理员用户名和密码';
            } elseif (strlen($admin_username) < 3) {
                $error = '用户名至少需要3个字符';
            } elseif (strlen($admin_password) < 6) {
                $error = '密码至少需要6个字符';
            } elseif ($admin_password !== $confirm_password) {
                $error = '两次输入的密码不一致';
            } elseif ($admin_email && !filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
                $error = '邮箱格式不正确';
            } else {
                $_SESSION['install_admin'] = [
                    'username' => $admin_username,
                    'password' => $admin_password,
                    'email' => $admin_email
                ];
                header('Location: install.php?step=4');
                exit;
            }
            break;
            
        case 4:
            // 系统配置
            $site_name = trim($_POST['site_name'] ?? '');
            $points_per_record = (int)($_POST['points_per_record'] ?? 1);
            $default_user_points = (int)($_POST['default_user_points'] ?? 100);
            $allow_registration = isset($_POST['allow_registration']) ? 1 : 0;
            
            if (!$site_name) {
                $error = '请填写网站名称';
            } elseif ($points_per_record < 1) {
                $error = '每条记录消耗积分必须大于0';
            } elseif ($default_user_points < 0) {
                $error = '新用户默认积分不能为负数';
            } else {
                $_SESSION['install_config'] = [
                    'site_name' => $site_name,
                    'points_per_record' => $points_per_record,
                    'default_user_points' => $default_user_points,
                    'allow_registration' => $allow_registration
                ];
                header('Location: install.php?step=5');
                exit;
            }
            break;
            
        case 5:
            // 执行安装
            try {
                require_once 'config/database.php';
                
                // 获取配置
                $admin_config = $_SESSION['install_admin'] ?? [];
                $system_config = $_SESSION['install_config'] ?? [];
                
                if (empty($admin_config) || empty($system_config)) {
                    throw new Exception('安装配置丢失，请重新开始安装');
                }
                
                // 初始化数据库（这会创建所有表）
                $db = Database::getInstance()->getConnection();
                
                // 删除默认管理员（如果存在）
                $db->exec("DELETE FROM admins WHERE username = 'admin'");
                
                // 创建新管理员
                $hashed_password = password_hash($admin_config['password'], PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO admins (username, password, email) VALUES (?, ?, ?)");
                $stmt->bindValue(1, $admin_config['username'], SQLITE3_TEXT);
                $stmt->bindValue(2, $hashed_password, SQLITE3_TEXT);
                $stmt->bindValue(3, $admin_config['email'], SQLITE3_TEXT);
                $stmt->execute();
                
                // 更新系统设置
                $settings = [
                    'site_name' => $system_config['site_name'],
                    'points_per_record' => $system_config['points_per_record'],
                    'default_user_points' => $system_config['default_user_points'],
                    'allow_registration' => $system_config['allow_registration']
                ];
                
                foreach ($settings as $key => $value) {
                    $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
                    $stmt->bindValue(1, $value, SQLITE3_TEXT);
                    $stmt->bindValue(2, $key, SQLITE3_TEXT);
                    $stmt->execute();
                }
                
                // 创建安装锁定文件
                file_put_contents('data/install.lock', date('Y-m-d H:i:s'));
                
                // 清除安装会话
                unset($_SESSION['install_admin']);
                unset($_SESSION['install_config']);
                
                header('Location: install.php?step=6');
                exit;
                
            } catch (Exception $e) {
                $error = '安装失败: ' . $e->getMessage();
            }
            break;
    }
}

// 环境检查
function checkEnvironment() {
    $checks = [
        'PHP版本 >= 7.4' => version_compare(PHP_VERSION, '7.4.0', '>='),
        'PDO MySQL扩展' => extension_loaded('pdo_mysql'),
        'cURL扩展' => extension_loaded('curl'),
        'OpenSSL扩展' => extension_loaded('openssl'),
        'data目录可写' => is_writable('.') || is_writable('data'),
    ];
    return $checks;
}

$env_checks = checkEnvironment();
$env_ok = !in_array(false, $env_checks);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统安装 - Cloudflare DNS管理系统</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/fontawesome.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .install-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin: 2rem auto;
            max-width: 800px;
        }
        .install-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .install-body {
            padding: 2rem;
        }
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
        }
        .step {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 10px;
            font-weight: bold;
            position: relative;
        }
        .step.active {
            background: #667eea;
            color: white;
        }
        .step.completed {
            background: #28a745;
            color: white;
        }
        .step.pending {
            background: #e9ecef;
            color: #6c757d;
        }
        .step:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 100%;
            width: 20px;
            height: 2px;
            background: #e9ecef;
            transform: translateY(-50%);
        }
        .step.completed:not(:last-child)::after {
            background: #28a745;
        }
        .check-item {
            display: flex;
            align-items: center;
            padding: 0.5rem 0;
        }
        .check-item i {
            margin-right: 0.5rem;
            width: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="install-container">
            <div class="install-header">
                <h1><i class="fas fa-cloud me-2"></i>Cloudflare DNS管理系统</h1>
                <p class="mb-0">欢迎使用系统安装向导</p>
            </div>
            
            <div class="install-body">
                <!-- 步骤指示器 -->
                <div class="step-indicator">
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                    <div class="step <?php 
                        if ($i < $step) echo 'completed';
                        elseif ($i == $step) echo 'active';
                        else echo 'pending';
                    ?>">
                        <?php if ($i < $step): ?>
                            <i class="fas fa-check"></i>
                        <?php else: ?>
                            <?php echo $i; ?>
                        <?php endif; ?>
                    </div>
                    <?php endfor; ?>
                </div>
                
                <!-- 错误提示 -->
                <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>
                
                <!-- 成功提示 -->
                <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                </div>
                <?php endif; ?>
                
                <?php if ($step == 1): ?>
                <!-- 步骤1: 环境检查 -->
                <div class="text-center mb-4">
                    <h3><i class="fas fa-server me-2"></i>环境检查</h3>
                    <p class="text-muted">使用 MySQL 数据库，请准备 MYSQL_DSN / MYSQL_USER / MYSQL_PASSWORD</p>
                </div>
                
                <div class="row justify-content-center">
                    <div class="col-md-8">
                        <?php foreach ($env_checks as $name => $status): ?>
                        <div class="check-item">
                            <i class="fas <?php echo $status ? 'fa-check-circle text-success' : 'fa-times-circle text-danger'; ?>"></i>
                            <span><?php echo $name; ?></span>
                        </div>
                        <?php endforeach; ?>
                        
                        <div class="mt-4 text-center">
                            <?php if ($env_ok): ?>
                            <form method="POST">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-arrow-right me-2"></i>下一步
                                </button>
                            </form>
                            <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                请解决上述环境问题后刷新页面继续安装
                            </div>
                            <button type="button" class="btn btn-secondary" onclick="location.reload()">
                                <i class="fas fa-redo me-2"></i>重新检查
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <?php elseif ($step == 2): ?>
                <!-- 步骤2: 数据库配置 -->
                <div class="text-center mb-4">
                    <h3><i class="fas fa-database me-2"></i>数据库配置</h3>
                    <p class="text-muted">配置 MySQL 连接（可通过环境变量或此处填写）</p>
                </div>
                
                <div class="row justify-content-center">
                    <div class="col-md-6">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">MYSQL_DSN</label>
                                <input type="text" class="form-control" name="mysql_dsn" placeholder="mysql:host=127.0.0.1;port=3306;dbname=cloudflare_dns;charset=utf8mb4 或 mysql://user:pass@host:3306/dbname?charset=utf8mb4" value="<?php echo htmlspecialchars($_POST['mysql_dsn'] ?? (getenv('MYSQL_DSN') ?: '')); ?>" required>
                                <div class="form-text">支持两种格式：<br>1) PDO DSN：mysql:host=127.0.0.1;port=3306;dbname=cloudflare_dns;charset=utf8mb4<br>2) URL DSN：mysql://user:pass@host:3306/dbname?charset=utf8mb4</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">MYSQL_USER</label>
                                <input type="text" class="form-control" name="mysql_user" value="<?php echo htmlspecialchars($_POST['mysql_user'] ?? (getenv('MYSQL_USER') ?: '')); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">MYSQL_PASSWORD</label>
                                <input type="password" class="form-control" name="mysql_password" value="<?php echo htmlspecialchars($_POST['mysql_password'] ?? (getenv('MYSQL_PASSWORD') ?: '')); ?>">
                            </div>
                            <div class="text-center">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-database me-2"></i>保存并测试连接
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <?php elseif ($step == 3): ?>
                <!-- 步骤3: 管理员账户 -->
                <div class="text-center mb-4">
                    <h3><i class="fas fa-user-shield me-2"></i>管理员账户</h3>
                    <p class="text-muted">创建系统管理员账户</p>
                </div>
                
                <div class="row justify-content-center">
                    <div class="col-md-6">
                        <form method="POST">
                            <div class="mb-3">
                                <label for="admin_username" class="form-label">管理员用户名</label>
                                <input type="text" class="form-control" id="admin_username" name="admin_username" 
                                       value="<?php echo htmlspecialchars($_POST['admin_username'] ?? ''); ?>" required>
                                <div class="form-text">至少3个字符</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="admin_email" class="form-label">邮箱地址</label>
                                <input type="email" class="form-control" id="admin_email" name="admin_email" 
                                       value="<?php echo htmlspecialchars($_POST['admin_email'] ?? ''); ?>">
                                <div class="form-text">可选，用于找回密码</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="admin_password" class="form-label">密码</label>
                                <input type="password" class="form-control" id="admin_password" name="admin_password" required>
                                <div class="form-text">至少6个字符</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">确认密码</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                            </div>
                            
                            <div class="text-center">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-arrow-right me-2"></i>下一步
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <?php elseif ($step == 4): ?>
                <!-- 步骤4: 系统配置 -->
                <div class="text-center mb-4">
                    <h3><i class="fas fa-cogs me-2"></i>系统配置</h3>
                    <p class="text-muted">配置系统基本参数</p>
                </div>
                
                <div class="row justify-content-center">
                    <div class="col-md-8">
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="site_name" class="form-label">网站名称</label>
                                        <input type="text" class="form-control" id="site_name" name="site_name" 
                                               value="<?php echo htmlspecialchars($_POST['site_name'] ?? 'Cloudflare DNS管理系统'); ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="points_per_record" class="form-label">每条记录消耗积分</label>
                                        <input type="number" class="form-control" id="points_per_record" name="points_per_record" 
                                               value="<?php echo (int)($_POST['points_per_record'] ?? 1); ?>" min="1" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="default_user_points" class="form-label">新用户默认积分</label>
                                        <input type="number" class="form-control" id="default_user_points" name="default_user_points" 
                                               value="<?php echo (int)($_POST['default_user_points'] ?? 100); ?>" min="0" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="allow_registration" name="allow_registration" 
                                                   <?php echo isset($_POST['allow_registration']) ? 'checked' : 'checked'; ?>>
                                            <label class="form-check-label" for="allow_registration">
                                                允许用户注册
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="text-center">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-arrow-right me-2"></i>下一步
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <?php elseif ($step == 5): ?>
                <!-- 步骤5: 执行安装 -->
                <div class="text-center mb-4">
                    <h3><i class="fas fa-download me-2"></i>执行安装</h3>
                    <p class="text-muted">正在安装系统，请稍候...</p>
                </div>
                
                <div class="row justify-content-center">
                    <div class="col-md-6">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            系统正在初始化数据库并配置相关设置，请不要关闭浏览器。
                        </div>
                        
                        <form method="POST">
                            <div class="text-center">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-play me-2"></i>开始安装
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <?php elseif ($step == 6): ?>
                <!-- 步骤6: 安装完成 -->
                <div class="text-center mb-4">
                    <h3><i class="fas fa-check-circle text-success me-2"></i>安装完成</h3>
                    <p class="text-muted">系统安装成功！</p>
                </div>
                
                <div class="row justify-content-center">
                    <div class="col-md-8">
                        <div class="alert alert-success">
                            <h5><i class="fas fa-party-horn me-2"></i>恭喜！</h5>
                            <p>Cloudflare DNS管理系统已成功安装。您现在可以开始使用系统了。</p>
                        </div>
                        
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>重要信息</h6>
                            </div>
                            <div class="card-body">
                                <ul class="mb-0">
                                    <li>管理员用户名: <strong><?php echo htmlspecialchars($_SESSION['install_admin']['username'] ?? ''); ?></strong></li>
                                    <li>请妥善保管管理员密码</li>
                                    <li>建议删除或重命名 <code>install.php</code> 文件以提高安全性</li>
                                    <li>首次使用前请在管理后台配置Cloudflare域名</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="text-center mt-4">
                            <a href="admin/login.php" class="btn btn-primary btn-lg me-2">
                                <i class="fas fa-sign-in-alt me-2"></i>管理员登录
                            </a>
                            <a href="user/login.php" class="btn btn-outline-primary btn-lg">
                                <i class="fas fa-users me-2"></i>用户登录
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
