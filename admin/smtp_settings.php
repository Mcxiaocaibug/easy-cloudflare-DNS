<?php
/**
 * SMTP邮件设置管理
 */
session_start();
require_once '../config/database.php';
require_once '../config/smtp.php';
require_once '../includes/functions.php';

checkAdminLogin();

$db = Database::getInstance()->getConnection();
$messages = getMessages();

// 处理设置更新
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_smtp'])) {
    $smtp_settings = [
        'smtp_enabled' => getPost('smtp_enabled') ? '1' : '0',
        'smtp_host' => trim(getPost('smtp_host')),
        'smtp_port' => trim(getPost('smtp_port')),
        'smtp_username' => trim(getPost('smtp_username')),
        'smtp_password' => trim(getPost('smtp_password')),
        'smtp_secure' => trim(getPost('smtp_secure')),
        'smtp_from_name' => trim(getPost('smtp_from_name')),
        'smtp_debug' => trim(getPost('smtp_debug'))
    ];
    
    $success = true;
    
    foreach ($smtp_settings as $key => $value) {
        try {
            // 先检查设置是否存在
            $exists = $db->querySingle("SELECT COUNT(*) FROM settings WHERE setting_key = '$key'");
            
            if ($exists > 0) {
                // 更新现有设置
                $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
                $stmt->bindValue(1, $value, SQLITE3_TEXT);
                $stmt->bindValue(2, $key, SQLITE3_TEXT);
            } else {
                // 插入新设置
                $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
                $stmt->bindValue(1, $key, SQLITE3_TEXT);
                $stmt->bindValue(2, $value, SQLITE3_TEXT);
                $descriptions = [
                    'smtp_enabled' => '是否启用SMTP邮件发送',
                    'smtp_host' => 'SMTP服务器地址',
                    'smtp_port' => 'SMTP服务器端口',
                    'smtp_username' => 'SMTP用户名（发件邮箱）',
                    'smtp_password' => 'SMTP密码或授权码',
                    'smtp_secure' => 'SMTP安全连接类型（ssl/tls）',
                    'smtp_from_name' => '发件人显示名称',
                    'smtp_debug' => 'SMTP调试模式（0-3）'
                ];
                $stmt->bindValue(3, $descriptions[$key] ?? '', SQLITE3_TEXT);
            }
            
            if (!$stmt->execute()) {
                $success = false;
                error_log("Failed to update SMTP setting: $key = $value");
                break;
            }
        } catch (Exception $e) {
            $success = false;
            error_log("SMTP setting update error for $key: " . $e->getMessage());
            break;
        }
    }
    
    if ($success) {
        logAction('admin', $_SESSION['admin_id'], 'update_smtp_settings', 'SMTP设置更新');
        showSuccess('SMTP设置更新成功！');
    } else {
        showError('SMTP设置更新失败！');
    }
    
    redirect('smtp_settings.php');
}

// 处理邮件模板更新
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_templates'])) {
    $templates = [
        'registration' => trim($_POST['registration_template'] ?? ''),
        'password_reset' => trim($_POST['password_reset_template'] ?? ''),
        'password_change' => trim($_POST['password_change_template'] ?? ''),
        'email_change' => trim($_POST['email_change_template'] ?? ''),
        'test_email' => trim($_POST['test_email_template'] ?? '')
    ];
    
    try {
        // 读取当前的smtp.php文件
        $smtp_file = '../config/smtp.php';
        $content = file_get_contents($smtp_file);
        
        if ($content === false) {
            throw new Exception('无法读取SMTP配置文件');
        }
        
        // 更新每个模板
        foreach ($templates as $template_type => $template_content) {
            if (!empty($template_content)) {
                $content = updateEmailTemplate($content, $template_type, $template_content);
            }
        }
        
        // 写回文件
        if (file_put_contents($smtp_file, $content) === false) {
            throw new Exception('无法写入SMTP配置文件');
        }
        
        logAction('admin', $_SESSION['admin_id'], 'update_email_templates', '更新邮件模板');
        showSuccess('邮件模板更新成功！');
        
    } catch (Exception $e) {
        showError('邮件模板更新失败：' . $e->getMessage());
    }
    
    redirect('smtp_settings.php');
}

// 处理测试邮件发送
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_email'])) {
    $test_email = trim(getPost('test_email'));
    
    if (empty($test_email)) {
        showError('请输入测试邮箱地址');
    } elseif (!filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
        showError('请输入有效的邮箱地址');
    } else {
        try {
            $emailService = new EmailService();
            
            // 发送测试邮件
            $emailService->sendTestEmail($test_email);
            showSuccess('测试邮件发送成功！请检查邮箱');
        } catch (Exception $e) {
            showError('测试邮件发送失败：' . $e->getMessage());
        }
    }
    
    redirect('smtp_settings.php');
}

// 获取当前SMTP设置
$current_settings = [];
$result = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $current_settings[$row['setting_key']] = $row['setting_value'];
}

// 模板更新函数
function updateEmailTemplate($content, $template_type, $new_template) {
    $template_patterns = [
        'registration' => '/private function getRegistrationEmailTemplate\(\$username, \$code\) \{.*?return ".*?";.*?\}/s',
        'password_reset' => '/private function getPasswordResetEmailTemplate\(\$username, \$code\) \{.*?return ".*?";.*?\}/s', 
        'password_change' => '/private function getPasswordChangeNotificationTemplate\(\$username\) \{.*?return ".*?";.*?\}/s',
        'email_change' => '/private function getEmailChangeVerificationTemplate\(\$username, \$code\) \{.*?return ".*?";.*?\}/s',
        'test_email' => '/private function getTestEmailTemplate\(\) \{.*?return ".*?";.*?\}/s'
    ];
    
    $template_replacements = [
        'registration' => 'private function getRegistrationEmailTemplate($username, $code) {
        return "' . addslashes($new_template) . '";
    }',
        'password_reset' => 'private function getPasswordResetEmailTemplate($username, $code) {
        return "' . addslashes($new_template) . '";
    }',
        'password_change' => 'private function getPasswordChangeNotificationTemplate($username) {
        $change_time = date(\'Y-m-d H:i:s\');
        return "' . addslashes($new_template) . '";
    }',
        'email_change' => 'private function getEmailChangeVerificationTemplate($username, $code) {
        return "' . addslashes($new_template) . '";
    }',
        'test_email' => 'private function getTestEmailTemplate() {
        $test_time = date(\'Y-m-d H:i:s\');
        return "' . addslashes($new_template) . '";
    }'
    ];
    
    if (isset($template_patterns[$template_type]) && isset($template_replacements[$template_type])) {
        $content = preg_replace($template_patterns[$template_type], $template_replacements[$template_type], $content);
    }
    
    return $content;
}

// 获取当前模板内容
function getCurrentTemplate($template_type) {
    $smtp_file = '../config/smtp.php';
    $content = file_get_contents($smtp_file);
    
    $template_patterns = [
        'registration' => '/private function getRegistrationEmailTemplate\(\$username, \$code\) \{.*?return "(.*?)";.*?\}/s',
        'password_reset' => '/private function getPasswordResetEmailTemplate\(\$username, \$code\) \{.*?return "(.*?)";.*?\}/s',
        'password_change' => '/private function getPasswordChangeNotificationTemplate\(\$username\) \{.*?return "(.*?)";.*?\}/s',
        'email_change' => '/private function getEmailChangeVerificationTemplate\(\$username, \$code\) \{.*?return "(.*?)";.*?\}/s',
        'test_email' => '/private function getTestEmailTemplate\(\) \{.*?return "(.*?)";.*?\}/s'
    ];
    
    if (isset($template_patterns[$template_type])) {
        if (preg_match($template_patterns[$template_type], $content, $matches)) {
            return stripslashes($matches[1]);
        }
    }
    
    return '';
}

$page_title = 'SMTP邮件设置';
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="fas fa-envelope me-2"></i>SMTP邮件设置
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#testEmailModal">
                            <i class="fas fa-paper-plane me-1"></i>发送测试邮件
                        </button>
                        <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#templateModal">
                            <i class="fas fa-edit me-1"></i>邮件模板
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- 消息提示 -->
            <?php if (!empty($messages)): ?>
                <?php foreach ($messages as $type => $message): ?>
                    <div class="alert alert-<?php echo $type; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <div class="row">
                <!-- SMTP配置 -->
                <div class="col-lg-8">
                    <div class="card shadow">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-primary">SMTP服务器配置</h6>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="smtp_enabled" name="smtp_enabled" 
                                                       <?php echo ($current_settings['smtp_enabled'] ?? '1') ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="smtp_enabled">
                                                    <strong>启用SMTP邮件发送</strong>
                                                </label>
                                            </div>
                                            <div class="form-text">关闭后将无法发送任何邮件</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="smtp_debug" class="form-label">调试模式</label>
                                            <select class="form-select" id="smtp_debug" name="smtp_debug">
                                                <option value="0" <?php echo ($current_settings['smtp_debug'] ?? '0') == '0' ? 'selected' : ''; ?>>关闭</option>
                                                <option value="1" <?php echo ($current_settings['smtp_debug'] ?? '0') == '1' ? 'selected' : ''; ?>>客户端消息</option>
                                                <option value="2" <?php echo ($current_settings['smtp_debug'] ?? '0') == '2' ? 'selected' : ''; ?>>客户端和服务器</option>
                                                <option value="3" <?php echo ($current_settings['smtp_debug'] ?? '0') == '3' ? 'selected' : ''; ?>>详细连接</option>
                                            </select>
                                            <div class="form-text">仅在测试时开启</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="smtp_host" class="form-label">SMTP服务器</label>
                                            <input type="text" class="form-control" id="smtp_host" name="smtp_host" 
                                                   value="<?php echo htmlspecialchars($current_settings['smtp_host'] ?? 'smtp.qq.com'); ?>" required>
                                            <div class="form-text">如：smtp.qq.com, smtp.gmail.com</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="smtp_port" class="form-label">端口</label>
                                            <input type="number" class="form-control" id="smtp_port" name="smtp_port" 
                                                   value="<?php echo htmlspecialchars($current_settings['smtp_port'] ?? '465'); ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="smtp_secure" class="form-label">加密方式</label>
                                            <select class="form-select" id="smtp_secure" name="smtp_secure" required>
                                                <option value="ssl" <?php echo ($current_settings['smtp_secure'] ?? 'ssl') == 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                                <option value="tls" <?php echo ($current_settings['smtp_secure'] ?? 'ssl') == 'tls' ? 'selected' : ''; ?>>TLS</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="smtp_username" class="form-label">SMTP用户名</label>
                                            <input type="email" class="form-control" id="smtp_username" name="smtp_username" 
                                                   value="<?php echo htmlspecialchars($current_settings['smtp_username'] ?? ''); ?>" required>
                                            <div class="form-text">必须是有效的邮箱地址格式</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="smtp_password" class="form-label">SMTP密码</label>
                                            <input type="password" class="form-control" id="smtp_password" name="smtp_password" 
                                                   value="<?php echo htmlspecialchars($current_settings['smtp_password'] ?? ''); ?>" required>
                                            <div class="form-text">邮箱密码或授权码</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="smtp_from_name" class="form-label">发件人名称</label>
                                    <input type="text" class="form-control" id="smtp_from_name" name="smtp_from_name" 
                                           value="<?php echo htmlspecialchars($current_settings['smtp_from_name'] ?? '六趣DNS'); ?>" required>
                                    <div class="form-text">收件人看到的发件人名称</div>
                                </div>
                                
                                <div class="d-grid">
                                    <button type="submit" name="update_smtp" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i>保存设置
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- 配置说明 -->
                <div class="col-lg-4">
                    <div class="card shadow">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-info">常用SMTP配置</h6>
                        </div>
                        <div class="card-body">
                            <h6 class="text-primary">QQ邮箱</h6>
                            <ul class="list-unstyled small">
                                <li><strong>服务器:</strong> smtp.qq.com</li>
                                <li><strong>端口:</strong> 465 (SSL) / 587 (TLS)</li>
                                <li><strong>用户名:</strong> 完整QQ邮箱地址</li>
                                <li><strong>密码:</strong> 邮箱授权码</li>
                            </ul>
                            
                            <h6 class="text-primary">Gmail</h6>
                            <ul class="list-unstyled small">
                                <li><strong>服务器:</strong> smtp.gmail.com</li>
                                <li><strong>端口:</strong> 465 (SSL) / 587 (TLS)</li>
                                <li><strong>用户名:</strong> 完整Gmail地址</li>
                                <li><strong>密码:</strong> 应用专用密码</li>
                            </ul>
                            
                            <h6 class="text-primary">163邮箱</h6>
                            <ul class="list-unstyled small">
                                <li><strong>服务器:</strong> smtp.163.com</li>
                                <li><strong>端口:</strong> 465 (SSL) / 994 (TLS)</li>
                                <li><strong>用户名:</strong> 完整163邮箱地址</li>
                                <li><strong>密码:</strong> 邮箱授权码</li>
                            </ul>
                            
                            <div class="alert alert-warning mt-3">
                                <small>
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    <strong>注意:</strong> 大多数邮箱服务商需要开启SMTP服务并使用授权码，而不是登录密码。
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- 测试邮件模态框 -->
<div class="modal fade" id="testEmailModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">发送测试邮件</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="test_email" class="form-label">测试邮箱地址</label>
                        <input type="email" class="form-control" id="test_email" name="test_email" required>
                        <div class="form-text">将向此邮箱发送测试邮件以验证SMTP配置</div>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        测试邮件将使用当前保存的SMTP设置发送，请确保已保存最新配置。
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" name="test_email" class="btn btn-success">
                        <i class="fas fa-paper-plane me-1"></i>发送测试邮件
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 邮件模板编辑模态框 -->
<div class="modal fade" id="templateModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit me-2"></i>邮件模板管理
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>注意：</strong>修改模板将直接更新 config/smtp.php 文件中的邮件模板。请谨慎操作，建议先备份文件。
                    </div>
                    
                    <!-- 模板选择标签页 -->
                    <ul class="nav nav-tabs" id="templateTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="registration-tab" data-bs-toggle="tab" data-bs-target="#registration" type="button" role="tab">
                                <i class="fas fa-user-plus me-1"></i>注册验证
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="password-reset-tab" data-bs-toggle="tab" data-bs-target="#password-reset" type="button" role="tab">
                                <i class="fas fa-key me-1"></i>密码重置
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="password-change-tab" data-bs-toggle="tab" data-bs-target="#password-change" type="button" role="tab">
                                <i class="fas fa-shield-alt me-1"></i>密码修改通知
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="email-change-tab" data-bs-toggle="tab" data-bs-target="#email-change" type="button" role="tab">
                                <i class="fas fa-envelope-open me-1"></i>邮箱更换
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="test-email-tab" data-bs-toggle="tab" data-bs-target="#test-email" type="button" role="tab">
                                <i class="fas fa-flask me-1"></i>测试邮件
                            </button>
                        </li>
                    </ul>
                    
                    <!-- 模板编辑内容 -->
                    <div class="tab-content mt-3" id="templateTabsContent">
                        <!-- 注册验证模板 -->
                        <div class="tab-pane fade show active" id="registration" role="tabpanel">
                            <div class="mb-3">
                                <label for="registration_template" class="form-label">注册验证邮件模板</label>
                                <div class="form-text mb-2">
                                    可用变量：<code>{$username}</code> (用户名), <code>{$code}</code> (验证码)
                                </div>
                                <textarea class="form-control" id="registration_template" name="registration_template" rows="15" style="font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars(getCurrentTemplate('registration')); ?></textarea>
                            </div>
                        </div>
                        
                        <!-- 密码重置模板 -->
                        <div class="tab-pane fade" id="password-reset" role="tabpanel">
                            <div class="mb-3">
                                <label for="password_reset_template" class="form-label">密码重置邮件模板</label>
                                <div class="form-text mb-2">
                                    可用变量：<code>{$username}</code> (用户名), <code>{$code}</code> (验证码)
                                </div>
                                <textarea class="form-control" id="password_reset_template" name="password_reset_template" rows="15" style="font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars(getCurrentTemplate('password_reset')); ?></textarea>
                            </div>
                        </div>
                        
                        <!-- 密码修改通知模板 -->
                        <div class="tab-pane fade" id="password-change" role="tabpanel">
                            <div class="mb-3">
                                <label for="password_change_template" class="form-label">密码修改通知邮件模板</label>
                                <div class="form-text mb-2">
                                    可用变量：<code>{$username}</code> (用户名), <code>{$change_time}</code> (修改时间)
                                </div>
                                <textarea class="form-control" id="password_change_template" name="password_change_template" rows="15" style="font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars(getCurrentTemplate('password_change')); ?></textarea>
                            </div>
                        </div>
                        
                        <!-- 邮箱更换模板 -->
                        <div class="tab-pane fade" id="email-change" role="tabpanel">
                            <div class="mb-3">
                                <label for="email_change_template" class="form-label">邮箱更换验证邮件模板</label>
                                <div class="form-text mb-2">
                                    可用变量：<code>{$username}</code> (用户名), <code>{$code}</code> (验证码)
                                </div>
                                <textarea class="form-control" id="email_change_template" name="email_change_template" rows="15" style="font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars(getCurrentTemplate('email_change')); ?></textarea>
                            </div>
                        </div>
                        
                        <!-- 测试邮件模板 -->
                        <div class="tab-pane fade" id="test-email" role="tabpanel">
                            <div class="mb-3">
                                <label for="test_email_template" class="form-label">测试邮件模板</label>
                                <div class="form-text mb-2">
                                    可用变量：<code>{$test_time}</code> (测试时间), <code>{$this->smtp_host}</code> (SMTP服务器), <code>{$this->smtp_username}</code> (SMTP用户名)
                                </div>
                                <textarea class="form-control" id="test_email_template" name="test_email_template" rows="15" style="font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars(getCurrentTemplate('test_email')); ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-lightbulb me-2"></i>
                        <strong>提示：</strong>
                        <ul class="mb-0 mt-2">
                            <li>模板使用HTML格式，支持内联CSS样式</li>
                            <li>变量会在发送时自动替换为实际值</li>
                            <li>建议保持邮件的专业性和可读性</li>
                            <li>修改后会立即生效，无需重启服务</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" name="update_templates" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>保存模板
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>