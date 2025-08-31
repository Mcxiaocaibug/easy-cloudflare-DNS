<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

checkAdminLogin();

$db = Database::getInstance()->getConnection();
$messages = getMessages();

// 处理修改DNS记录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_record'])) {
    $record_id = (int)getPost('record_id');
    $subdomain = trim(getPost('subdomain'));
    $type = getPost('type');
    $content = trim(getPost('content'));
    $remark = trim(getPost('remark'));
    $proxied = isset($_POST['proxied']) ? 1 : 0;
    
    $record = $db->querySingle("SELECT dr.*, d.* FROM dns_records dr JOIN domains d ON dr.domain_id = d.id WHERE dr.id = $record_id", true);
    if ($record) {
        try {
            require_once '../config/cloudflare.php';
            $cf = new CloudflareAPI($record['api_key'], $record['email']);
            
            // 更新Cloudflare DNS记录
            $cf->updateDNSRecord($record['zone_id'], $record['cloudflare_id'], [
                'type' => $type,
                'name' => $subdomain . '.' . $record['domain_name'],
                'content' => $content,
                'proxied' => (bool)$proxied
            ]);
            
            // 更新本地数据库
            $stmt = $db->prepare("UPDATE dns_records SET subdomain = ?, type = ?, content = ?, remark = ?, proxied = ? WHERE id = ?");
            $stmt->bindValue(1, $subdomain, SQLITE3_TEXT);
            $stmt->bindValue(2, $type, SQLITE3_TEXT);
            $stmt->bindValue(3, $content, SQLITE3_TEXT);
            $stmt->bindValue(4, $remark, SQLITE3_TEXT);
            $stmt->bindValue(5, $proxied, SQLITE3_INTEGER);
            $stmt->bindValue(6, $record_id, SQLITE3_INTEGER);
            $stmt->execute();
            
            logAction('admin', $_SESSION['admin_id'], 'edit_dns_record', "修改DNS记录ID: $record_id - {$subdomain}.{$record['domain_name']}");
            showSuccess('DNS记录修改成功！');
        } catch (Exception $e) {
            showError('修改失败: ' . $e->getMessage());
        }
    } else {
        showError('记录不存在！');
    }
    redirect('dns_records.php');
}

// 处理删除DNS记录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_record'])) {
    $record_id = (int)getPost('record_id');
    
    $record = $db->querySingle("SELECT * FROM dns_records WHERE id = $record_id", true);
    if ($record) {
        $db->exec("DELETE FROM dns_records WHERE id = $record_id");
        logAction('admin', $_SESSION['admin_id'], 'delete_dns_record', "删除DNS记录ID: $record_id");
        showSuccess('DNS记录删除成功！');
    } else {
        showError('记录不存在！');
    }
    redirect('dns_records.php');
}

// 获取所有DNS记录（包括系统记录和用户记录）
$dns_records = [];
$result = $db->query("
    SELECT dr.*, 
           CASE 
               WHEN dr.user_id IS NULL OR dr.is_system = 1 THEN '系统所属'
               ELSE COALESCE(u.username, '未知用户')
           END as username,
           d.domain_name,
           CASE 
               WHEN dr.user_id IS NULL OR dr.is_system = 1 THEN 1
               ELSE 0
           END as is_system_record
    FROM dns_records dr 
    LEFT JOIN users u ON dr.user_id = u.id AND dr.user_id IS NOT NULL AND (dr.is_system = 0 OR dr.is_system IS NULL)
    JOIN domains d ON dr.domain_id = d.id 
    ORDER BY dr.is_system DESC, dr.created_at DESC
");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $dns_records[] = $row;
}

$page_title = 'DNS记录管理';
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">DNS记录管理</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="input-group">
                        <input type="text" class="form-control" placeholder="搜索记录..." id="searchInput" onkeyup="searchTable('searchInput', 'recordsTable')">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                    </div>
                </div>
            </div>
            
            <!-- 消息提示 -->
            <?php if (isset($messages['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?php echo htmlspecialchars($messages['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($messages['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?php echo htmlspecialchars($messages['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- 记录类型说明 -->
            <div class="alert alert-info">
                <h6><i class="fas fa-info-circle me-2"></i>记录类型说明</h6>
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-1"><span class="badge bg-success"><i class="fas fa-user me-1"></i>用户记录</span> - 用户通过前台创建的DNS记录</p>
                        <small class="text-muted">• 可以编辑和删除 • 消耗用户积分 • 用户可管理</small>
                    </div>
                    <div class="col-md-6">
                        <p class="mb-1"><span class="badge bg-info"><i class="fas fa-server me-1"></i>系统记录</span> - 通过批量同步导入的DNS记录</p>
                        <small class="text-muted">• 只读，不可编辑 • 不消耗积分 • 管理员专属</small>
                    </div>
                </div>
            </div>
            
            <!-- DNS记录列表 -->
            <div class="card shadow">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">所有DNS记录 
                        <small class="text-muted">(系统记录优先显示)</small>
                    </h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered" id="recordsTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>用户</th>
                                    <th>完整域名</th>
                                    <th>类型</th>
                                    <th>内容</th>
                                    <th>备注</th>
                                    <th>代理状态</th>
                                    <th>创建时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dns_records as $record): ?>
                                <?php 
                                // 构建完整域名
                                $full_domain = htmlspecialchars($record['subdomain']) . '.' . htmlspecialchars($record['domain_name']);
                                // 构建跳转URL（根据记录类型决定协议）
                                $protocol = in_array($record['type'], ['A', 'AAAA', 'CNAME']) ? 'https://' : '';
                                $jump_url = $protocol . $record['subdomain'] . '.' . $record['domain_name'];
                                ?>
                                <tr>
                                    <td><?php echo $record['id']; ?></td>
                                    <td>
                                        <?php if ($record['is_system_record']): ?>
                                            <span class="badge bg-info">
                                                <i class="fas fa-server me-1"></i>系统所属
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success">
                                                <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($record['username']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (in_array($record['type'], ['A', 'AAAA', 'CNAME'])): ?>
                                            <a href="<?php echo $jump_url; ?>" target="_blank" class="domain-link text-decoration-none" title="点击访问 <?php echo $full_domain; ?>，右键复制域名">
                                                <code class="text-primary"><?php echo $full_domain; ?></code>
                                                <i class="fas fa-external-link-alt ms-1 external-link-icon" style="font-size: 0.8em;"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="domain-link" title="右键复制域名">
                                                <code><?php echo $full_domain; ?></code>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-info"><?php echo $record['type']; ?></span></td>
                                    <td>
                                        <?php 
                                        $content = htmlspecialchars($record['content']);
                                        $truncated = mb_strlen($content) > 50 ? mb_substr($content, 0, 50) . '...' : $content;
                                        ?>
                                        <span title="<?php echo $content; ?>" style="cursor: help;">
                                            <?php echo $truncated; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($record['remark'])): ?>
                                            <span class="text-muted" title="<?php echo htmlspecialchars($record['remark']); ?>">
                                                <i class="fas fa-comment-alt me-1 text-info"></i>
                                                <?php echo htmlspecialchars(mb_strlen($record['remark']) > 20 ? mb_substr($record['remark'], 0, 20) . '...' : $record['remark']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($record['proxied']): ?>
                                            <span class="badge bg-warning">已代理</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">仅DNS</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatTime($record['created_at']); ?></td>
                                    <td>
                                        <?php if (!$record['is_system_record']): ?>
                                        <button type="button" class="btn btn-sm btn-primary me-1" 
                                                onclick="editRecord(<?php echo htmlspecialchars(json_encode($record)); ?>)"
                                                title="修改记录">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                            <button type="submit" name="delete_record" class="btn btn-sm btn-danger" 
                                                    onclick="return confirmDelete('确定要删除这条DNS记录吗？')"
                                                    title="删除记录">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <span class="text-muted">
                                            <i class="fas fa-lock me-1"></i>系统记录
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<style>
/* 完整域名链接样式 */
.domain-link {
    transition: all 0.2s ease;
    border-radius: 4px;
    padding: 2px 4px;
}

.domain-link:hover {
    background-color: #f8f9fa;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.domain-link code {
    font-size: 0.9em;
    font-weight: 500;
}

.external-link-icon {
    opacity: 0.6;
    transition: opacity 0.2s ease;
}

.domain-link:hover .external-link-icon {
    opacity: 1;
}

/* 表格行悬停效果 */
#recordsTable tbody tr:hover {
    background-color: #f8f9fa;
}

/* 响应式表格优化 */
@media (max-width: 768px) {
    .domain-link code {
        font-size: 0.8em;
        word-break: break-all;
    }
}
</style>

<script>
// 搜索功能
function searchTable(inputId, tableId) {
    const input = document.getElementById(inputId);
    const filter = input.value.toUpperCase();
    const table = document.getElementById(tableId);
    const tr = table.getElementsByTagName("tr");

    for (let i = 1; i < tr.length; i++) {
        let td = tr[i].getElementsByTagName("td");
        let found = false;
        
        for (let j = 0; j < td.length; j++) {
            if (td[j]) {
                let txtValue = td[j].textContent || td[j].innerText;
                if (txtValue.toUpperCase().indexOf(filter) > -1) {
                    found = true;
                    break;
                }
            }
        }
        
        tr[i].style.display = found ? "" : "none";
    }
}

// 确认删除
function confirmDelete(message) {
    return confirm(message);
}

// 复制域名到剪贴板
function copyDomain(domain) {
    navigator.clipboard.writeText(domain).then(function() {
        // 显示复制成功提示
        const toast = document.createElement('div');
        toast.className = 'toast align-items-center text-white bg-success border-0 position-fixed';
        toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999;';
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    <i class="fas fa-check me-2"></i>域名已复制到剪贴板
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        document.body.appendChild(toast);
        
        const bsToast = new bootstrap.Toast(toast);
        bsToast.show();
        
        // 3秒后自动移除
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 3000);
    }).catch(function(err) {
        console.error('复制失败: ', err);
    });
}

// 为域名链接添加右键复制功能
document.addEventListener('DOMContentLoaded', function() {
    const domainLinks = document.querySelectorAll('.domain-link');
    domainLinks.forEach(function(link) {
        link.addEventListener('contextmenu', function(e) {
            e.preventDefault();
            const domain = this.querySelector('code').textContent;
            copyDomain(domain);
        });
    });
});

// 修改DNS记录函数
function editRecord(record) {
    // 填充表单数据
    document.getElementById('edit_record_id').value = record.id;
    document.getElementById('edit_subdomain').value = record.subdomain;
    document.getElementById('edit_type').value = record.type;
    document.getElementById('edit_content').value = record.content;
    document.getElementById('edit_remark').value = record.remark || '';
    document.getElementById('edit_proxied').checked = record.proxied == 1;
    
    // 更新域名后缀显示
    document.getElementById('edit_domain_suffix').textContent = '.' + record.domain_name;
    
    // 更新内容占位符和帮助文本
    updateEditContentPlaceholder();
    
    // 显示模态框
    var modal = new bootstrap.Modal(document.getElementById('editRecordModal'));
    modal.show();
}

function updateEditContentPlaceholder() {
    const typeSelect = document.getElementById('edit_type');
    const contentInput = document.getElementById('edit_content');
    const contentHelp = document.getElementById('edit-content-help');
    const proxiedSection = document.getElementById('edit-proxied-section');
    
    const type = typeSelect.value;
    
    // 定义记录类型的配置
    const typeConfigs = {
        'A': {
            placeholder: '192.168.1.1',
            help: '请输入IPv4地址',
            showProxied: true
        },
        'AAAA': {
            placeholder: '2001:db8::1',
            help: '请输入IPv6地址',
            showProxied: true
        },
        'CNAME': {
            placeholder: 'example.com',
            help: '请输入目标域名',
            showProxied: true
        },
        'MX': {
            placeholder: '10 mail.example.com',
            help: '请输入优先级和邮件服务器地址',
            showProxied: false
        },
        'NS': {
            placeholder: 'ns1.example.com',
            help: '请输入域名服务器地址',
            showProxied: false
        },
        'TXT': {
            placeholder: 'v=spf1 include:_spf.google.com ~all',
            help: '请输入文本内容',
            showProxied: false
        },
        'SRV': {
            placeholder: '10 5 443 target.example.com',
            help: '请输入优先级 权重 端口 目标',
            showProxied: false
        },
        'PTR': {
            placeholder: 'example.com',
            help: '请输入反向解析的域名',
            showProxied: false
        },
        'CAA': {
            placeholder: '0 issue "letsencrypt.org"',
            help: '请输入CAA记录值',
            showProxied: false
        }
    };
    
    const config = typeConfigs[type];
    if (config) {
        contentInput.placeholder = config.placeholder;
        contentHelp.textContent = config.help;
        proxiedSection.style.display = config.showProxied ? 'block' : 'none';
    } else {
        contentInput.placeholder = '';
        contentHelp.textContent = '请输入对应记录类型的值';
        proxiedSection.style.display = 'none';
    }
}
</script>

<!-- 修改DNS记录模态框 -->
<div class="modal fade" id="editRecordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">修改DNS记录</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" id="edit_record_id" name="record_id">
                    <div class="mb-3">
                        <label for="edit_subdomain" class="form-label">子域名</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="edit_subdomain" name="subdomain" placeholder="www" required>
                            <span class="input-group-text" id="edit_domain_suffix">.domain.com</span>
                        </div>
                        <div class="form-text">输入 @ 表示根域名</div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_type" class="form-label">记录类型</label>
                        <select class="form-select" id="edit_type" name="type" required onchange="updateEditContentPlaceholder()">
                            <option value="A">A - IPv4地址</option>
                            <option value="AAAA">AAAA - IPv6地址</option>
                            <option value="CNAME">CNAME - 别名记录</option>
                            <option value="MX">MX - 邮件交换记录</option>
                            <option value="NS">NS - 域名服务器记录</option>
                            <option value="TXT">TXT - 文本记录</option>
                            <option value="SRV">SRV - 服务记录</option>
                            <option value="PTR">PTR - 反向解析记录</option>
                            <option value="CAA">CAA - 证书颁发机构授权记录</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_content" class="form-label">记录值</label>
                        <input type="text" class="form-control" id="edit_content" name="content" placeholder="192.168.1.1" required>
                        <div class="form-text" id="edit-content-help">
                            请输入对应记录类型的值
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_remark" class="form-label">备注 <span class="text-muted">(可选)</span></label>
                        <input type="text" class="form-control" id="edit_remark" name="remark" placeholder="例如：网站主页、API接口、邮件服务器等" maxlength="100">
                        <div class="form-text">添加备注可以帮助您区分不同解析记录的用途</div>
                    </div>
                    <div class="mb-3" id="edit-proxied-section">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="edit_proxied" name="proxied" value="1">
                            <label class="form-check-label" for="edit_proxied">
                                启用Cloudflare代理
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" name="edit_record" class="btn btn-primary">
                        保存修改
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>