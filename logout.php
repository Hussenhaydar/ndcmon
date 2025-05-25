<?php
require_once 'config.php';

// تسجيل الحدث قبل تسجيل الخروج
if (isLoggedIn()) {
    logEvent('logout', 'تسجيل خروج');
    
    // حذف جلسة المستخدم من قاعدة البيانات
    if (isset($_SESSION['session_token'])) {
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM user_sessions WHERE session_token = ?");
        $stmt->execute([$_SESSION['session_token']]);
    }
}

// إنهاء الجلسة
session_destroy();

// توجيه للصفحة الرئيسية
header('Location: login.php?message=logged_out');
exit();
?>