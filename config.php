<?php
// DNC MON System Configuration File
// ملف إعدادات النظام

// إعدادات قاعدة البيانات
define('DB_HOST', 'localhostw');
define('DB_NAME', 'dnc_mon_system');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// إعدادات النظام
define('SITE_NAME', 'DNC MON System');
define('SITE_URL', 'http://localhost/dnc');
define('UPLOAD_PATH', __DIR__ . '/uploads/');
define('SESSION_LIFETIME', 3600); // ساعة واحدة
define('TIMEZONE', 'Asia/Baghdad');

// إعدادات SNMP
define('SNMP_TIMEOUT', 1000000); // 1 ثانية
define('SNMP_RETRIES', 3);
define('SNMP_VERSION', '2c');
define('SNMP_COMMUNITY', 'public');

// إعدادات الأمان
define('SALT', 'DNC_MON_2024_SECURE_SALT');
define('SESSION_NAME', 'DNCMON_SESSION');

// وضع التطوير
define('DEBUG_MODE', true);

// تعيين المنطقة الزمنية
date_default_timezone_set(TIMEZONE);

// معالجة الأخطاء
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// بدء الجلسة
if (session_status() == PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

// دالة للاتصال بقاعدة البيانات
function getDB() {
    static $db = null;
    
    if ($db === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            
            $db = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            if (DEBUG_MODE) {
                die("Database connection failed: " . $e->getMessage());
            } else {
                die("System error. Please contact administrator.");
            }
        }
    }
    
    return $db;
}

// دوال مساعدة
function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

function hasPermission($permission) {
    if (!isLoggedIn()) return false;
    
    // المدير له كل الصلاحيات
    if ($_SESSION['user_role'] == 'admin') return true;
    
    $db = getDB();
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM user_permissions up
        JOIN permissions p ON up.permission_id = p.id
        WHERE up.user_id = ? AND p.name = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $permission]);
    
    return $stmt->fetchColumn() > 0;
}

function requirePermission($permission) {
    if (!hasPermission($permission)) {
        http_response_code(403);
        die("Access denied. You don't have permission to access this resource.");
    }
}

function logEvent($event_type, $description, $ups_id = null) {
    $db = getDB();
    $user_id = $_SESSION['user_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    
    $stmt = $db->prepare("
        INSERT INTO event_logs (ups_id, user_id, event_type, description, ip_address)
        VALUES (?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([$ups_id, $user_id, $event_type, $description, $ip]);
}

// دالة للتحقق من حالة UPS عن طريق SNMP
function checkUPSStatus($ip, $community = SNMP_COMMUNITY) {
    try {
        // هذه دالة مبسطة - في الإنتاج ستحتاج مكتبة SNMP
        $status = @snmpget($ip, $community, "1.3.6.1.4.1.318.1.1.1.4.1.1.0", SNMP_TIMEOUT);
        
        if ($status !== false) {
            // تحويل قيمة SNMP إلى حالة
            $statusMap = [
                1 => 'offline',
                2 => 'online',
                3 => 'battery',
                4 => 'standby',
                5 => 'fault'
            ];
            
            return $statusMap[$status] ?? 'unknown';
        }
    } catch (Exception $e) {
        logEvent('snmp_error', 'SNMP Error: ' . $e->getMessage());
    }
    
    return 'offline';
}

// دالة لتنسيق الوقت
function formatTime($timestamp) {
    return date('Y-m-d H:i:s', strtotime($timestamp));
}

// دالة لتنسيق الوقت النسبي
function timeAgo($timestamp) {
    $time = strtotime($timestamp);
    $diff = time() - $time;
    
    if ($diff < 60) return 'منذ ثواني';
    if ($diff < 3600) return 'منذ ' . floor($diff / 60) . ' دقيقة';
    if ($diff < 86400) return 'منذ ' . floor($diff / 3600) . ' ساعة';
    if ($diff < 2592000) return 'منذ ' . floor($diff / 86400) . ' يوم';
    
    return formatTime($timestamp);
}

// دالة للحصول على لون الحالة
function getStatusColor($status) {
    $colors = [
        'online' => '#4caf50',
        'battery' => '#ff9800',
        'offline' => '#757575',
        'standby' => '#2196f3',
        'fault' => '#f44336'
    ];
    
    return $colors[$status] ?? '#757575';
}

// دالة للحصول على اسم الحالة بالعربي
function getStatusName($status) {
    $names = [
        'online' => 'متصل',
        'battery' => 'وضع البطارية',
        'offline' => 'غير متصل',
        'standby' => 'وضع الاستعداد',
        'fault' => 'عطل'
    ];
    
    return $names[$status] ?? 'غير معروف';
}

// دالة لتحميل الصور
function uploadImage($file, $folder = 'ups') {
    $allowed = ['jpg', 'jpeg', 'png', 'gif'];
    $filename = $file['name'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    if (!in_array($ext, $allowed)) {
        return ['success' => false, 'message' => 'نوع الملف غير مسموح'];
    }
    
    $newName = uniqid() . '.' . $ext;
    $path = UPLOAD_PATH . $folder . '/' . $newName;
    
    if (!is_dir(UPLOAD_PATH . $folder)) {
        mkdir(UPLOAD_PATH . $folder, 0777, true);
    }
    
    if (move_uploaded_file($file['tmp_name'], $path)) {
        return ['success' => true, 'filename' => $folder . '/' . $newName];
    }
    
    return ['success' => false, 'message' => 'فشل رفع الملف'];
}

// دالة لإرسال استجابة JSON
function sendJSON($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

// دالة للتحقق من طلبات AJAX
function isAjax() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

// دالة للحصول على إحصائيات النظام
function getSystemStats() {
    $db = getDB();
    
    $stats = $db->query("SELECT * FROM ups_statistics")->fetch();
    
    return $stats ?: [
        'total_devices' => 0,
        'online_count' => 0,
        'battery_count' => 0,
        'offline_count' => 0,
        'fault_count' => 0
    ];
}
?>