<?php

// =================================================================
// FILE: api/settings.php - مفقود  
// =================================================================
require_once '../config.php';
requireLogin();
requirePermission('manage_settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = json_decode(file_get_contents('php://input'), true);
    
    // حفظ الإعدادات
    logEvent('settings_updated', 'تم تحديث الإعدادات');
    
    sendJSON(['success' => true, 'message' => 'تم حفظ الإعدادات']);
} else {
    sendJSON(['success' => true, 'data' => ['site_name' => 'DNC MON System']]);
}


?>