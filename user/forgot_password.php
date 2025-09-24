<?php
/**
 * 忘记密码页面
 */
session_start();
require_once '../config/database.php';
require_once '../config/smtp.php';
require_once '../includes/functions.php';

$messages = [];
$step = isset($_GET['step']) ? $_GET['step'] : 'email';

// 处理发送重置邮件
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reset'])) {
    $email = trim($_POST['email']);
    
    if (empty($email)) {
        $messages['error'] = '邮箱地址不能为空';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $messages['error'] = '邮箱格式不正确';
    } else {
        // 检查邮箱是否存在
        $db = Database::getInstance()->getConnection();
        $user = $db->querySingle("SELECT id, username FROM users WHERE email = '$email'", true);
        
        if (!$user) {
            $messages['error'] = '该邮箱未注册';
        } else {
            // 发送重置邮件
            try {
                $emailService = new EmailService();
                $emailService->sendPasswordReset($email, $user['username'], $user['id']);
                $_SESSION['reset_email'] = $email;
                $_SESSION['reset_user_id'] = $user['id'];
                $messages['success'] = '密码重置邮件已发送，请查收';
                $step = 'verify';
            } catch (Exception $e) {
                $messages['error'] = '邮件发送失败：' . $e->getMessage();
                error_log("Password reset failed for $email: " . $e->getMessage());
            }
        }
    }
}

// 处理密码重置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
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
        $verification = $emailService->verifyCode($_SESSION['reset_email'], $code, 'password_reset');
        
        if ($verification['valid']) {
            // 更新密码
            $db = Database::getInstance()->getConnection();
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bindValue(1, $hashed_password, SQLITE3_TEXT);
            $stmt->bindValue(2, $_SESSION['reset_user_id'], SQLITE3_INTEGER);
            
            if ($stmt->execute()) {
                // 发送密码修改通知
                $user = $db->querySingle("SELECT username FROM users WHERE id = {$_SESSION['reset_user_id']}", true);
                $emailService->sendPasswordChangeNotification($_SESSION['reset_email'], $user['username']);
                
                // 清除会话数据
                unset($_SESSION['reset_email']);
                unset($_SESSION['reset_user_id']);
                
                $messages['success'] = '密码重置成功！请使用新密码登录';
                $step = 'success';
            } else {
                $messages['error'] = '密码重置失败，请重试';
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
    <title>找回密码 - 六趣DNS</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/fontawesome.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .reset-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="reset-card">
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
                                <i class="fas fa-key text-danger me-2"></i>
                                找回密码
                            </h4>
                            
                            <p class="text-muted text-center mb-4">
                                请输入您注册时使用的邮箱地址，我们将发送密码重置邮件给您。
                            </p>
                            
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="email" class="form-label">邮箱地址</label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                                           placeholder="your@email.com" required>
                                </div>
                                
                                <button type="submit" name="send_reset" class="btn btn-danger w-100">
                                    <i class="fas fa-paper-plane me-2"></i>
                                    发送重置邮件
                                </button>
                            </form>
                            
                        <?php elseif ($step === 'verify'): ?>
                            <!-- 步骤2: 验证并重置密码 -->
                            <h4 class="text-center mb-4">
                                <i class="fas fa-shield-alt text-danger me-2"></i>
                                重置密码
                            </h4>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                重置邮件已发送到: <strong><?php echo htmlspecialchars($_SESSION['reset_email']); ?></strong>
                            </div>
                            
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="code" class="form-label">验证码</label>
                                    <input type="text" class="form-control" id="code" name="code" 
                                           placeholder="请输入6位验证码" maxlength="6" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="password" class="form-label">新密码</label>
                                    <input type="password" class="form-control" id="password" name="password" 
                                           placeholder="至少6位字符" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">确认新密码</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                           placeholder="再次输入新密码" required>
                                </div>
                                
                                <button type="submit" name="reset_password" class="btn btn-success w-100">
                                    <i class="fas fa-check me-2"></i>
                                    重置密码
                                </button>
                            </form>
                            
                            <div class="text-center mt-3">
                                <a href="?step=email" class="btn btn-link">
                                    <i class="fas fa-arrow-left me-1"></i>
                                    重新发送邮件
                                </a>
                            </div>
                            
                        <?php else: ?>
                            <!-- 步骤3: 重置成功 -->
                            <div class="text-center">
                                <div class="mb-4">
                                    <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
                                </div>
                                <h4 class="text-success mb-3">密码重置成功！</h4>
                                <p class="text-muted mb-4">
                                    您的密码已成功重置，请使用新密码登录您的账户。
                                </p>
                                <a href="login.php" class="btn btn-primary">
                                    <i class="fas fa-sign-in-alt me-2"></i>
                                    立即登录
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <div class="text-center mt-4">
                            <p class="text-muted">
                                记起密码了？ <a href="login.php" class="text-decoration-none">立即登录</a> |
                                <a href="register_verify.php" class="text-decoration-none">注册账户</a>
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