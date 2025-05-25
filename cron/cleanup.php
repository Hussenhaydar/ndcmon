<?php
// =================================================================
// FILE: cron/cleanup.php - مفقود
// =================================================================
require_once dirname(__DIR__) . '/config.php';

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

try {
    $db = getDB();
    
    // حذف البيانات القديمة
    $stmt = $db->prepare("DELETE FROM ups_status WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)");
    $deletedStatus = $stmt->execute() ? $stmt->rowCount() : 0;
    
    $stmt = $db->prepare("DELETE FROM event_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 3 MONTH)");
    $deletedEvents = $stmt->execute() ? $stmt->rowCount() : 0;
    
    $stmt = $db->prepare("DELETE FROM user_sessions WHERE expires_at < NOW()");
    $deletedSessions = $stmt->execute() ? $stmt->rowCount() : 0;
    
    echo "تنظيف مكتمل:\n";
    echo "- سجلات الحالة: $deletedStatus\n";
    echo "- سجلات الأحداث: $deletedEvents\n";
    echo "- الجلسات: $deletedSessions\n";
    
} catch (Exception $e) {
    echo "خطأ في التنظيف: " . $e->getMessage() . "\n";
    exit(1);
}

// =================================================================
// FILE: database/schema.sql - مفقود (منفصل)
// =================================================================
?>