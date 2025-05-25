<?php
// =================================================================
// FILE: sections/users.php - مفقود
// =================================================================
require_once '../config.php';
requireLogin();
requirePermission('view_users');

$db = getDB();
$users = $db->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();
?>
<div class="users-section">
    <div class="users-header">
        <h2><i class="fas fa-users"></i> إدارة المستخدمين</h2>
        <?php if (hasPermission('manage_users')): ?>
        <button class="btn btn-success" onclick="showAddUserModal()">
            <i class="fas fa-user-plus"></i> إضافة مستخدم
        </button>
        <?php endif; ?>
    </div>
    
    <div class="users-table">
        <table>
            <thead>
                <tr>
                    <th>المستخدم</th>
                    <th>البريد</th>
                    <th>الدور</th>
                    <th>الحالة</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td><?php echo $user['full_name']; ?></td>
                    <td><?php echo $user['email']; ?></td>
                    <td><?php echo $user['role']; ?></td>
                    <td><?php echo $user['is_active'] ? 'نشط' : 'معطل'; ?></td>
                    <td>
                        <button class="btn btn-sm btn-primary" onclick="editUser(<?php echo $user['id']; ?>)">تعديل</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
