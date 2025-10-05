<?php
/**
 * 用户注册验证页面
 */
session_start();
require_once '../config/database.php';
require_once '../config/smtp.php';
require_once '../includes/functions.php';

$messages = [];
$step = isset($_GET['step']) ? $_GET['step'] : 'email';

// 处理发送验证码
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_code'])) {
    $email = trim($_POST['email']);
    $username = trim($_POST['username']);
    
    if (empty($email) || empty($username)) {
        $messages['error'] = '邮箱和用户名不能为空';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $messages['error'] = '邮箱格式不正确';
    } else {
        // 检查用户名和邮箱是否已注册
        $db = Database::getInstance()->getConnection();
        $username_exists = $db->querySingle("SELECT COUNT(*) FROM users WHERE username = '$username'");
        $email_exists = $db->querySingle("SELECT COUNT(*) FROM users WHERE email = '$email'");
        
        if ($username_exists > 0) {
            $messages['error'] = '该用户名已被注册，请更换用户名';
        } elseif ($email_exists > 0) {
            $messages['error'] = '该邮箱已被注册';
        } else {
            // 发送验证码
            try {
                $emailService = new EmailService();
                $emailService->sendRegistrationVerification($email, $username);
                $_SESSION['registration_email'] = $email;
                $_SESSION['registration_username'] = $username;
                $messages['success'] = '验证码已发送到您的邮箱，请查收';
                $step = 'verify';
            } catch (Exception $e) {
                $messages['error'] = '验证码发送失败：' . $e->getMessage();
                error_log("Registration verification failed for $email: " . $e->getMessage());
            }
        }
    }
}

// 处理验证码验证
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_code'])) {
    $code = trim($_POST['code']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    
    if (empty($code) || empty($password) || empty($confirm_password)) {
        $messages['error'] = '所有字段都必须填写';
    } elseif ($password !== $confirm_password) {
        $messages['error'] = '两次输入的密码不一致';
    } elseif (strlen($password) < 6) {
        $messages['error'] = '密码长度至少6位';
    } else {
        $emailService = new EmailService();
        $verification = $emailService->verifyCode($_SESSION['registration_email'], $code, 'registration');
        
        if ($verification['valid']) {
            // 再次检查用户名和邮箱是否已存在（避免并发注册）
            $db = Database::getInstance()->getConnection();
            
            $username_exists = $db->querySingle("SELECT COUNT(*) FROM users WHERE username = '{$_SESSION['registration_username']}'");
            $email_exists = $db->querySingle("SELECT COUNT(*) FROM users WHERE email = '{$_SESSION['registration_email']}'");
            
            if ($username_exists > 0) {
                $messages['error'] = '该用户名已被注册，请重新选择';
            } elseif ($email_exists > 0) {
                $messages['error'] = '该邮箱已被注册';
            } else {
                // 创建用户账户
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $db->prepare("INSERT INTO users (username, email, password, credits, created_at) VALUES (?, ?, ?, 100, datetime('now'))");
                $stmt->bindValue(1, $_SESSION['registration_username'], SQLITE3_TEXT);
                $stmt->bindValue(2, $_SESSION['registration_email'], SQLITE3_TEXT);
                $stmt->bindValue(3, $hashed_password, SQLITE3_TEXT);
                
                if ($stmt->execute()) {
                // 清除会话数据
                unset($_SESSION['registration_email']);
                unset($_SESSION['registration_username']);
                
                    $messages['success'] = '注册成功！您已获得100积分，请登录您的账户';
                    $step = 'success';
                } else {
                    $messages['error'] = '注册失败，请重试';
                }
            }
        } else {
            $messages['error'] = '验证码错误或已过期';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>用户注册验证 - 六趣DNS</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/fontawesome.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .register-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .step-indicator {
            background: #f8f9fa;
            padding: 20px;
            border-bottom: 1px solid #dee2e6;
        }
        .step {
            display: inline-block;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #dee2e6;
            color: #6c757d;
            text-align: center;
            line-height: 30px;
            margin: 0 10px;
        }
        .step.active {
            background: #667eea;
            color: white;
        }
        .step.completed {
            background: #28a745;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="register-card">
                    <!-- 步骤指示器 -->
                    <div class="step-indicator text-center">
                        <span class="step <?php echo ($step === 'email') ? 'active' : (in_array($step, ['verify', 'success']) ? 'completed' : ''); ?>">1</span>
                        <span class="step <?php echo ($step === 'verify') ? 'active' : ($step === 'success' ? 'completed' : ''); ?>">2</span>
                        <span class="step <?php echo ($step === 'success') ? 'active' : ''; ?>">3</span>
                        <div class="mt-2">
                            <small class="text-muted">
                                <?php if ($step === 'email'): ?>
                                    输入邮箱 → 验证邮箱 → 完成注册
                                <?php elseif ($step === 'verify'): ?>
                                    输入邮箱 → <strong>验证邮箱</strong> → 完成注册
                                <?php else: ?>
                                    输入邮箱 → 验证邮箱 → <strong>完成注册</strong>
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                    
                    <!-- 消息提示 -->
                    <?php if (!empty($messages)): ?>
                        <?php foreach ($messages as $type => $message): ?>
                            <div class="alert alert-<?php echo $type; ?> alert-dismissible fade show m-3" role="alert">
                                <?php echo htmlspecialchars($message); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <div class="p-4">
                        <?php if ($step === 'email'): ?>
                            <!-- 步骤1: 输入邮箱 -->
                            <h4 class="text-center mb-4">
                                <i class="fas fa-user-plus text-primary me-2"></i>
                                用户注册
                            </h4>
                            
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="username" class="form-label">用户名</label>
                                    <input type="text" class="form-control" id="username" name="username" 
                                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" 
                                           required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="email" class="form-label">邮箱地址</label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                                           required>
                                    <div class="form-text">我们将向此邮箱发送验证码</div>
                                </div>
                                
                                <button type="submit" name="send_code" class="btn btn-primary w-100">
                                    <i class="fas fa-paper-plane me-2"></i>
                                    发送验证码
                                </button>
                            </form>
                            
                        <?php elseif ($step === 'verify'): ?>
                            <!-- 步骤2: 验证邮箱 -->
                            <h4 class="text-center mb-4">
                                <i class="fas fa-envelope-open text-primary me-2"></i>
                                验证邮箱
                            </h4>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                验证码已发送到: <strong><?php echo htmlspecialchars($_SESSION['registration_email']); ?></strong>
                            </div>
                            
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="code" class="form-label">验证码</label>
                                    <input type="text" class="form-control" id="code" name="code" 
                                           placeholder="请输入6位验证码" maxlength="6" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="password" class="form-label">设置密码</label>
                                    <input type="password" class="form-control" id="password" name="password" 
                                           placeholder="至少6位字符" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">确认密码</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                           placeholder="再次输入密码" required>
                                </div>
                                
                                <button type="submit" name="verify_code" class="btn btn-success w-100">
                                    <i class="fas fa-check me-2"></i>
                                    完成注册
                                </button>
                            </form>
                            
                            <div class="text-center mt-3">
                                <a href="?step=email" class="btn btn-link">
                                    <i class="fas fa-arrow-left me-1"></i>
                                    重新发送验证码
                                </a>
                            </div>
                            
                        <?php else: ?>
                            <!-- 步骤3: 注册成功 -->
                            <div class="text-center">
                                <div class="mb-4">
                                    <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
                                </div>
                                <h4 class="text-success mb-3">注册成功！</h4>
                                <p class="text-muted mb-4">
                                    恭喜您成功注册六趣DNS！<br>
                                    您已获得 <strong>100积分</strong> 的新用户奖励。
                                </p>
                                <a href="login.php" class="btn btn-primary">
                                    <i class="fas fa-sign-in-alt me-2"></i>
                                    立即登录
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <div class="text-center mt-4">
                            <p class="text-muted">
                                已有账户？ <a href="login.php" class="text-decoration-none">立即登录</a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>