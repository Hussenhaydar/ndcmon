<?php
require_once '../config.php';

// التحقق من تسجيل الدخول
if (!isLoggedIn()) {
    sendJSON(['error' => 'Unauthorized'], 401);
}

// معالجة الطلبات
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        listDevices();
        break;
        
    case 'get':
        getDevice();
        break;
        
    case 'add':
        requirePermission('add_ups');
        addDevice();
        break;
        
    case 'update':
        requirePermission('edit_ups');
        updateDevice();
        break;
        
    case 'delete':
        requirePermission('delete_ups');
        deleteDevice();
        break;
        
    case 'status':
        getStatus();
        break;
        
    case 'refresh':
        refreshStatus();
        break;
        
    case 'stats':
        getStats();
        break;
        
    default:
        sendJSON(['error' => 'Invalid action'], 400);
}

// دالة لعرض قائمة الأجهزة
function listDevices() {
    $db = getDB();
    
    $filters = [];
    $params = [];
    
    // تطبيق الفلاتر
    if (!empty($_GET['name'])) {
        $filters[] = "u.name LIKE ?";
        $params[] = '%' . $_GET['name'] . '%';
    }
    
    if (!empty($_GET['ip'])) {
        $filters[] = "u.ip_address LIKE ?";
        $params[] = '%' . $_GET['ip'] . '%';
    }
    
    if (!empty($_GET['status'])) {
        $filters[] = "ls.status = ?";
        $params[] = $_GET['status'];
    }
    
    if (!empty($_GET['type'])) {
        $filters