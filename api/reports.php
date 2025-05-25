<?php
// =================================================================
// FILE: api/reports.php - مفقود
// =================================================================
require_once '../config.php';
requireLogin();
requirePermission('view_reports');

$db = getDB();

try {
    $statsData = $db->query("
        SELECT 
            status, 
            COUNT(*) as count 
        FROM ups_latest_status 
        GROUP BY status
    ")->fetchAll();
    
    sendJSON([
        'success' => true,
        'data' => $statsData
    ]);
    
} catch (Exception $e) {
    sendJSON(['error' => $e->getMessage()], 500);
}