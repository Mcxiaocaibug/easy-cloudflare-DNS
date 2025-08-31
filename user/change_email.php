<?php
session_start();

// 检查是否已安装
if (!file_exists('../data/install.lock')) {
    header('Location: ../install.php');
    exit;
}

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/captcha.php';

// 检查用户是否登录
checkUserLogin();

$error = '';
$success = '';

$db = Database::getInstance()->getConnection();
$user_id = $_SESSION['user_id'];

// 获取当前用户信息
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
$result = $stmt->execute();
$user = $result->fetchArray(SQLITE3_ASSOC);

// 处理更换邮箱
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_email'])) {
    $new_email = getPost('new_email');
    $password = getPost('password');
    $captcha_code = getPost('captcha_code');
    
    $captcha = new Captcha();
    
    if (!$new_email || !$password || !$captcha_code) {
        $error = '请填写完整信息';
    } elseif (!$captcha->verify($captcha_code)) {
        $error = '验证码错误或已过期';
    } elseif (!isValidEmail($new_email)) {
        $error = '请输入有效的邮箱地址';
    } elseif ($new_email === $user['email']) {
        $error = '新邮箱不能与当前邮箱相同';
    } elseif (!password_verify($password, $user['password'])) {
        $error = '当前密码错误';
    } else {
        // 检查新邮箱是否已被使用
        $exists = $db->querySingle("SELECT COUNT(*) FROM users WHERE email = '$new_email' AND id != $user_id");
        if ($exists) {
            $error = '该邮箱已被其他用户使用';
        } else {
            // 更新邮箱
            $stmt = $db->prepare("UPDATE users SET email = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->bindValue(1, $new_email, SQLITE3_TEXT);
            $stmt->bindValue(2, $user_id, SQLITE3_INTEGER);
            
            if ($stmt->execute()) {
                // 更新session中的邮箱
                $_SESSION['user_email'] = $new_email;
                
                logAction('user', $user_id, 'change_email', "邮箱从 {$user['email']} 更换为 {$new_email}");
                $success = '邮箱更换成功！';
                
                // 重新获取用户信息
                $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
                $result = $stmt->execute();
                $user = $result->fetchArray(SQLITE3_ASSOC);
            } else {
                $error = '邮箱更换失败，请重试';
            }
        }
    }
}

$page_title = '更换邮箱';
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">更换邮箱</h1>
            </div>
            
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">更换邮箱地址</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($error): ?>
                                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                            <?php endif; ?>
                            <?php if ($success): ?>
                                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <label class="form-label">当前邮箱</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? '未设置'); ?>" readonly>
                            </div>
                            
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="new_email" class="form-label">新邮箱地址</label>
                                    <input type="email" class="form-control" id="new_email" name="new_email" required placeholder="请输入新的邮箱地址">
                                </div>
                                <div class="mb-3">
                                    <label for="password" class="form-label">当前密码</label>
                                    <input type="password" class="form-control" id="password" name="password" required placeholder="请输入当前密码">
                                    <div class="form-text">为了安全，请输入您的当前密码</div>
                                </div>
                                <div class="mb-3">
                                    <label for="captcha_code" class="form-label">验证码</label>
                                    <div class="row">
                                        <div class="col-6">
                                            <input type="text" class="form-control" id="captcha_code" name="captcha_code" required placeholder="请输入验证码">
                                        </div>
                                        <div class="col-6">
                                            <img src="../captcha_image.php" alt="验证码" class="img-fluid border rounded" 
                                                 id="captcha_img" style="height: 38px; cursor: pointer;" 
                                                 onclick="refreshCaptcha()" title="点击刷新验证码">
                                        </div>
                                    </div>
                                    <small class="form-text text-muted">点击图片可刷新验证码</small>
                                </div>
                                <div class="d-grid">
                                    <button type="submit" name="change_email" class="btn btn-success">
                                        <i class="fas fa-check me-1"></i>更换邮箱
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
    // 刷新验证码
    function refreshCaptcha() {
        document.getElementById('captcha_img').src = '../captcha_image.php?t=' + new Date().getTime();
    }
    
    // 页面加载时初始化验证码
    document.addEventListener('DOMContentLoaded', function() {
        refreshCaptcha();
    });
</script>

<?php include 'includes/footer.php'; ?>