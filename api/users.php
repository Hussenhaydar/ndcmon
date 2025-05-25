<?php
require_once '../config.php';

// التحقق من تسجيل الدخول
if (!isLoggedIn()) {
    sendJSON(['error' => 'Unauthorized'], 401);
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        requirePermission('view_users');
        listUsers();
        break;
        
    case 'get':
        requirePermission('view_users');
        getUser();
        break;
        
    case 'add':
        requirePermission('manage_users');
        addUser();
        break;
        
    case 'update':
        requirePermission('manage_users');
        updateUser();
        break;
        
    case 'toggle':
        requirePermission('manage_users');
        toggleUserStatus();
        break;
        
    case 'delete':
        requirePermission('manage_users');
        deleteUser();
        break;
        
    case 'permissions':
        requirePermission('manage_users');
        getUserPermissions();
        break;
        
    case 'set_permissions':
        requirePermission('manage_users');
        setUserPermissions();
        break;
        
    default:
        sendJSON(['error' => 'Invalid action'], 400);
}

function listUsers() {
    $db = getDB();
    
    try {
        $stmt = $db->query("
            SELECT 
                u.*,
                (SELECT COUNT(*) FROM user_sessions s WHERE s.user_id = u.id AND s.expires_at > NOW()) as active_sessions,
                (SELECT MAX(created_at) FROM event_logs e WHERE e.user_id = u.id) as last_activity
            FROM users u
            ORDER BY u.created_at DESC
        ");
        
        $users = $stmt->fetchAll();
        
        // إخفاء كلمات المرور
        foreach ($users as &$user) {
            unset($user['password']);
        } catch (Exception $e) {
        sendJSON(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

function updateUser() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJSON(['error' => 'POST method required'], 405);
    }
    
    $id = intval($_POST['id'] ?? 0);
    if (!$id) {
        sendJSON(['error' => 'User ID is required'], 400);
    }
    
    $db = getDB();
    
    try {
        // التحقق من وجود المستخدم
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            sendJSON(['error' => 'User not found'], 404);
        }
        
        $username = sanitize($_POST['username'] ?? $user['username']);
        $fullName = sanitize($_POST['full_name'] ?? $user['full_name']);
        $email = sanitize($_POST['email'] ?? $user['email']);
        $role = sanitize($_POST['role'] ?? $user['role']);
        
        // التحقق من صحة البيانات
        if (empty($username) || empty($fullName) || empty($email)) {
            sendJSON(['error' => 'Required fields cannot be empty'], 400);
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendJSON(['error' => 'Invalid email format'], 400);
        }
        
        if (!in_array($role, ['admin', 'operator', 'viewer'])) {
            sendJSON(['error' => 'Invalid role'], 400);
        }
        
        // التحقق من عدم تكرار اسم المستخدم (باستثناء المستخدم الحالي)
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$username, $id]);
        if ($stmt->fetch()) {
            sendJSON(['error' => 'Username already exists'], 400);
        }
        
        // التحقق من عدم تكرار البريد الإلكتروني (باستثناء المستخدم الحالي)
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $id]);
        if ($stmt->fetch()) {
            sendJSON(['error' => 'Email already exists'], 400);
        }
        
        $updateFields = [
            'username' => $username,
            'full_name' => $fullName,
            'email' => $email,
            'role' => $role,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        // تحديث كلمة المرور إذا تم إرسالها
        if (!empty($_POST['password'])) {
            $updateFields['password'] = hashPassword($_POST['password']);
        }
        
        $setClause = implode(', ', array_map(fn($field) => "$field = ?", array_keys($updateFields)));
        $values = array_values($updateFields);
        $values[] = $id;
        
        $stmt = $db->prepare("UPDATE users SET $setClause WHERE id = ?");
        $result = $stmt->execute($values);
        
        if ($result) {
            // تحديث الصلاحيات إذا تغير الدور
            if ($role !== $user['role']) {
                updateUserPermissions($id, $role);
            }
            
            // تسجيل الحدث
            logEvent('user_updated', "تم تحديث المستخدم: $username");
            
            sendJSON([
                'success' => true,
                'message' => 'User updated successfully'
            ]);
        } else {
            sendJSON(['error' => 'Failed to update user'], 500);
        }
        
    } catch (Exception $e) {
        sendJSON(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

function toggleUserStatus() {
    $id = $_GET['id'] ?? 0;
    
    if (!$id) {
        sendJSON(['error' => 'User ID is required'], 400);
    }
    
    $db = getDB();
    
    try {
        // التحقق من وجود المستخدم
        $stmt = $db->prepare("SELECT username, is_active FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            sendJSON(['error' => 'User not found'], 404);
        }
        
        // منع إلغاء تفعيل المستخدم الحالي
        if ($id == $_SESSION['user_id']) {
            sendJSON(['error' => 'Cannot deactivate your own account'], 400);
        }
        
        $newStatus = !$user['is_active'];
        
        $stmt = $db->prepare("UPDATE users SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $result = $stmt->execute([$newStatus, $id]);
        
        if ($result) {
            // إنهاء جلسات المستخدم إذا تم إلغاء تفعيله
            if (!$newStatus) {
                $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
                $stmt->execute([$id]);
            }
            
            $action = $newStatus ? 'تفعيل' : 'إلغاء تفعيل';
            logEvent('user_status_changed', "$action المستخدم: {$user['username']}");
            
            sendJSON([
                'success' => true,
                'message' => 'User status updated successfully',
                'new_status' => $newStatus
            ]);
        } else {
            sendJSON(['error' => 'Failed to update user status'], 500);
        }
        
    } catch (Exception $e) {
        sendJSON(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

function deleteUser() {
    $id = $_GET['id'] ?? 0;
    
    if (!$id) {
        sendJSON(['error' => 'User ID is required'], 400);
    }
    
    $db = getDB();
    
    try {
        // التحقق من وجود المستخدم
        $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            sendJSON(['error' => 'User not found'], 404);
        }
        
        // منع حذف المستخدم الحالي
        if ($id == $_SESSION['user_id']) {
            sendJSON(['error' => 'Cannot delete your own account'], 400);
        }
        
        $db->beginTransaction();
        
        try {
            // حذف صلاحيات المستخدم
            $stmt = $db->prepare("DELETE FROM user_permissions WHERE user_id = ?");
            $stmt->execute([$id]);
            
            // حذف جلسات المستخدم
            $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
            $stmt->execute([$id]);
            
            // تنظيف مراجع المستخدم من event_logs (تعيين NULL)
            $stmt = $db->prepare("UPDATE event_logs SET user_id = NULL WHERE user_id = ?");
            $stmt->execute([$id]);
            
            // حذف المستخدم
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);
            
            $db->commit();
            
            // تسجيل الحدث
            logEvent('user_deleted', "تم حذف المستخدم: {$user['username']}");
            
            sendJSON([
                'success' => true,
                'message' => 'User deleted successfully'
            ]);
            
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        
    } catch (Exception $e) {
        sendJSON(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

function getUserPermissions() {
    $id = $_GET['id'] ?? 0;
    
    if (!$id) {
        sendJSON(['error' => 'User ID is required'], 400);
    }
    
    $db = getDB();
    
    try {
        // الحصول على جميع الصلاحيات المتاحة
        $stmt = $db->query("SELECT * FROM permissions ORDER BY name");
        $allPermissions = $stmt->fetchAll();
        
        // الحصول على صلاحيات المستخدم الحالية
        $stmt = $db->prepare("
            SELECT permission_id
            FROM user_permissions
            WHERE user_id = ?
        ");
        $stmt->execute([$id]);
        $userPermissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        sendJSON([
            'success' => true,
            'data' => [
                'all_permissions' => $allPermissions,
                'user_permissions' => $userPermissions
            ]
        ]);
        
    } catch (Exception $e) {
        sendJSON(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

function setUserPermissions() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJSON(['error' => 'POST method required'], 405);
    }
    
    $userId = intval($_POST['user_id'] ?? 0);
    $permissions = $_POST['permissions'] ?? [];
    
    if (!$userId) {
        sendJSON(['error' => 'User ID is required'], 400);
    }
    
    if (!is_array($permissions)) {
        sendJSON(['error' => 'Permissions must be an array'], 400);
    }
    
    $db = getDB();
    
    try {
        // التحقق من وجود المستخدم
        $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            sendJSON(['error' => 'User not found'], 404);
        }
        
        $db->beginTransaction();
        
        try {
            // حذف الصلاحيات الحالية
            $stmt = $db->prepare("DELETE FROM user_permissions WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            // إضافة الصلاحيات الجديدة
            if (!empty($permissions)) {
                $stmt = $db->prepare("INSERT INTO user_permissions (user_id, permission_id) VALUES (?, ?)");
                
                foreach ($permissions as $permissionId) {
                    $stmt->execute([$userId, intval($permissionId)]);
                }
            }
            
            $db->commit();
            
            // تسجيل الحدث
            logEvent('user_permissions_updated', "تم تحديث صلاحيات المستخدم: {$user['username']}");
            
            sendJSON([
                'success' => true,
                'message' => 'User permissions updated successfully'
            ]);
            
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        
    } catch (Exception $e) {
        sendJSON(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// دوال مساعدة
function setDefaultPermissions($userId, $role) {
    global $db;
    
    $defaultPermissions = [
        'viewer' => ['view_ups', 'view_reports'],
        'operator' => ['view_ups', 'add_ups', 'edit_ups', 'view_reports'],
        'admin' => [] // المدير له كل الصلاحيات تلقائياً
    ];
    
    if ($role === 'admin') {
        return; // المدير لا يحتاج صلاحيات محددة
    }
    
    $permissions = $defaultPermissions[$role] ?? [];
    
    if (!empty($permissions)) {
        $stmt = $db->prepare("
            INSERT INTO user_permissions (user_id, permission_id)
            SELECT ?, id FROM permissions WHERE name = ?
        ");
        
        foreach ($permissions as $permission) {
            $stmt->execute([$userId, $permission]);
        }
    }
}

function updateUserPermissions($userId, $newRole) {
    global $db;
    
    // حذف الصلاحيات الحالية
    $stmt = $db->prepare("DELETE FROM user_permissions WHERE user_id = ?");
    $stmt->execute([$userId]);
    
    // إضافة الصلاحيات الجديدة حسب الدور
    setDefaultPermissions($userId, $newRole);
}
?>
        
        sendJSON([
            'success' => true,
            'data' => $users
        ]);
        
    } catch (Exception $e) {
        sendJSON(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

function getUser() {
    $id = $_GET['id'] ?? 0;
    
    if (!$id) {
        sendJSON(['error' => 'User ID is required'], 400);
    }
    
    $db = getDB();
    
    try {
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            sendJSON(['error' => 'User not found'], 404);
        }
        
        unset($user['password']);
        
        // الحصول على صلاحيات المستخدم
        $stmt = $db->prepare("
            SELECT p.name, p.description
            FROM user_permissions up
            JOIN permissions p ON up.permission_id = p.id
            WHERE up.user_id = ?
        ");
        $stmt->execute([$id]);
        $permissions = $stmt->fetchAll();
        
        sendJSON([
            'success' => true,
            'data' => $user,
            'permissions' => $permissions
        ]);
        
    } catch (Exception $e) {
        sendJSON(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

function addUser() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJSON(['error' => 'POST method required'], 405);
    }
    
    $db = getDB();
    
    try {
        $username = sanitize($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $fullName = sanitize($_POST['full_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $role = sanitize($_POST['role'] ?? 'viewer');
        
        // التحقق من صحة البيانات
        if (empty($username) || empty($password) || empty($fullName) || empty($email)) {
            sendJSON(['error' => 'All fields are required'], 400);
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendJSON(['error' => 'Invalid email format'], 400);
        }
        
        if (!in_array($role, ['admin', 'operator', 'viewer'])) {
            sendJSON(['error' => 'Invalid role'], 400);
        }
        
        // التحقق من عدم تكرار اسم المستخدم
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            sendJSON(['error' => 'Username already exists'], 400);
        }
        
        // التحقق من عدم تكرار البريد الإلكتروني
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            sendJSON(['error' => 'Email already exists'], 400);
        }
        
        // تشفير كلمة المرور
        $hashedPassword = hashPassword($password);
        
        // إدخال المستخدم الجديد
        $stmt = $db->prepare("
            INSERT INTO users (username, password, full_name, email, role)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([$username, $hashedPassword, $fullName, $email, $role]);
        
        if ($result) {
            $userId = $db->lastInsertId();
            
            // إضافة الصلاحيات الافتراضية حسب الدور
            setDefaultPermissions($userId, $role);
            
            // تسجيل الحدث
            logEvent('user_created', "تم إنشاء مستخدم جديد: $username");
            
            sendJSON([
                'success' => true,
                'message' => 'User created successfully',
                'user_id' => $userId
            ]);
        } else {
            sendJSON(['error' => 'Failed to create user'], 500);
        }
        
    }