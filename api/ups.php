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
        
    case 'test_connection':
        testConnection();
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
        $filters[] = "t.phase_type = ?";
        $params[] = $_GET['type'];
    }
    
    if (!empty($_GET['location'])) {
        $filters[] = "u.location LIKE ?";
        $params[] = '%' . $_GET['location'] . '%';
    }
    
    if (!empty($_GET['model'])) {
        $filters[] = "m.id = ?";
        $params[] = $_GET['model'];
    }
    
    $whereClause = !empty($filters) ? 'WHERE u.is_active = 1 AND ' . implode(' AND ', $filters) : 'WHERE u.is_active = 1';
    
    $sql = "
        SELECT 
            u.*,
            m.model_name,
            m.manufacturer,
            t.name as type_name,
            t.phase_type,
            ls.status,
            ls.input_voltage,
            ls.output_voltage,
            ls.load_percentage,
            ls.battery_percentage,
            ls.recorded_at,
            (SELECT COUNT(*) FROM ups_alerts WHERE ups_id = u.id AND is_resolved = 0) as alert_count
        FROM ups_devices u
        LEFT JOIN ups_models m ON u.model_id = m.id
        LEFT JOIN ups_types t ON m.type_id = t.id
        LEFT JOIN ups_latest_status ls ON u.id = ls.id
        $whereClause
        ORDER BY u.name
    ";
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $devices = $stmt->fetchAll();
        
        sendJSON([
            'success' => true,
            'data' => $devices,
            'count' => count($devices)
        ]);
    } catch (Exception $e) {
        sendJSON(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// دالة للحصول على تفاصيل جهاز واحد
function getDevice() {
    $id = $_GET['id'] ?? 0;
    
    if (!$id) {
        sendJSON(['error' => 'Device ID is required'], 400);
    }
    
    $db = getDB();
    
    try {
        // الحصول على تفاصيل الجهاز
        $stmt = $db->prepare("
            SELECT 
                u.*,
                m.model_name,
                m.manufacturer,
                m.specifications,
                t.name as type_name,
                t.phase_type,
                t.mib_file
            FROM ups_devices u
            LEFT JOIN ups_models m ON u.model_id = m.id
            LEFT JOIN ups_types t ON m.type_id = t.id
            WHERE u.id = ? AND u.is_active = 1
        ");
        $stmt->execute([$id]);
        $device = $stmt->fetch();
        
        if (!$device) {
            sendJSON(['error' => 'Device not found'], 404);
        }
        
        // الحصول على آخر حالة
        $stmt = $db->prepare("
            SELECT * FROM ups_status 
            WHERE ups_id = ? 
            ORDER BY recorded_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $status = $stmt->fetch();
        
        // الحصول على التنبيهات النشطة
        $stmt = $db->prepare("
            SELECT * FROM ups_alerts 
            WHERE ups_id = ? AND is_resolved = 0
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$id]);
        $alerts = $stmt->fetchAll();
        
        // الحصول على بيانات الرسم البياني (آخر 24 ساعة)
        $stmt = $db->prepare("
            SELECT 
                DATE_FORMAT(recorded_at, '%H:%i') as time,
                input_voltage,
                output_voltage,
                load_percentage,
                battery_percentage
            FROM ups_status 
            WHERE ups_id = ? AND recorded_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY recorded_at ASC
        ");
        $stmt->execute([$id]);
        $chartData = $stmt->fetchAll();
        
        $response = [
            'success' => true,
            'data' => array_merge($device, $status ?: []),
            'alerts' => $alerts,
            'chartData' => $chartData
        ];
        
        sendJSON($response);
        
    } catch (Exception $e) {
        sendJSON(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// دالة لإضافة جهاز جديد
function addDevice() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJSON(['error' => 'POST method required'], 405);
    }
    
    $db = getDB();
    
    try {
        // التحقق من صحة البيانات
        $name = sanitize($_POST['name'] ?? '');
        $ip_address = sanitize($_POST['ip_address'] ?? '');
        $model_id = intval($_POST['model_id'] ?? 0);
        $snmp_community = sanitize($_POST['snmp_community'] ?? 'public');
        $snmp_version = sanitize($_POST['snmp_version'] ?? '2c');
        $location = sanitize($_POST['location'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        
        if (empty($name) || empty($ip_address) || !$model_id) {
            sendJSON(['error' => 'Name, IP address and model are required'], 400);
        }
        
        // التحقق من صحة IP
        if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
            sendJSON(['error' => 'Invalid IP address format'], 400);
        }
        
        // التحقق من عدم تكرار IP
        $stmt = $db->prepare("SELECT id FROM ups_devices WHERE ip_address = ? AND is_active = 1");
        $stmt->execute([$ip_address]);
        if ($stmt->fetch()) {
            sendJSON(['error' => 'IP address already exists'], 400);
        }
        
        // رفع الصورة إذا وجدت
        $image_path = null;
        if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload_result = uploadImage($_FILES['image'], 'ups');
            if ($upload_result['success']) {
                $image_path = $upload_result['filename'];
            } else {
                sendJSON(['error' => $upload_result['message']], 400);
            }
        }
        
        // إدخال الجهاز الجديد
        $stmt = $db->prepare("
            INSERT INTO ups_devices (
                name, ip_address, snmp_community, snmp_version, 
                model_id, location, description, image_path
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $name, $ip_address, $snmp_community, $snmp_version,
            $model_id, $location, $description, $image_path
        ]);
        
        if ($result) {
            $device_id = $db->lastInsertId();
            
            // تسجيل الحدث
            logEvent('device_added', "تم إضافة جهاز جديد: $name", $device_id);
            
            // اختبار الاتصال
            $connection_status = testDeviceConnection($ip_address, $snmp_community);
            
            sendJSON([
                'success' => true,
                'message' => 'Device added successfully',
                'device_id' => $device_id,
                'connection_status' => $connection_status
            ]);
        } else {
            sendJSON(['error' => 'Failed to add device'], 500);
        }
        
    } catch (Exception $e) {
        sendJSON(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// دالة لتحديث جهاز
function updateDevice() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJSON(['error' => 'POST method required'], 405);
    }
    
    $id = intval($_POST['id'] ?? 0);
    if (!$id) {
        sendJSON(['error' => 'Device ID is required'], 400);
    }
    
    $db = getDB();
    
    try {
        // التحقق من وجود الجهاز
        $stmt = $db->prepare("SELECT * FROM ups_devices WHERE id = ? AND is_active = 1");
        $stmt->execute([$id]);
        $device = $stmt->fetch();
        
        if (!$device) {
            sendJSON(['error' => 'Device not found'], 404);
        }
        
        // التحقق من صحة البيانات
        $name = sanitize($_POST['name'] ?? $device['name']);
        $ip_address = sanitize($_POST['ip_address'] ?? $device['ip_address']);
        $model_id = intval($_POST['model_id'] ?? $device['model_id']);
        $snmp_community = sanitize($_POST['snmp_community'] ?? $device['snmp_community']);
        $snmp_version = sanitize($_POST['snmp_version'] ?? $device['snmp_version']);
        $location = sanitize($_POST['location'] ?? $device['location']);
        $description = sanitize($_POST['description'] ?? $device['description']);
        
        if (empty($name) || empty($ip_address) || !$model_id) {
            sendJSON(['error' => 'Name, IP address and model are required'], 400);
        }
        
        // التحقق من صحة IP
        if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
            sendJSON(['error' => 'Invalid IP address format'], 400);
        }
        
        // التحقق من عدم تكرار IP (باستثناء الجهاز الحالي)
        $stmt = $db->prepare("SELECT id FROM ups_devices WHERE ip_address = ? AND id != ? AND is_active = 1");
        $stmt->execute([$ip_address, $id]);
        if ($stmt->fetch()) {
            sendJSON(['error' => 'IP address already exists'], 400);
        }
        
        // رفع الصورة الجديدة إذا وجدت
        $image_path = $device['image_path'];
        if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload_result = uploadImage($_FILES['image'], 'ups');
            if ($upload_result['success']) {
                // حذف الصورة القديمة
                if ($image_path && file_exists(UPLOAD_PATH . $image_path)) {
                    unlink(UPLOAD_PATH . $image_path);
                }
                $image_path = $upload_result['filename'];
            } else {
                sendJSON(['error' => $upload_result['message']], 400);
            }
        }
        
        // تحديث الجهاز
        $stmt = $db->prepare("
            UPDATE ups_devices SET
                name = ?, ip_address = ?, snmp_community = ?, snmp_version = ?,
                model_id = ?, location = ?, description = ?, image_path = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        $result = $stmt->execute([
            $name, $ip_address, $snmp_community, $snmp_version,
            $model_id, $location, $description, $image_path, $id
        ]);
        
        if ($result) {
            // تسجيل الحدث
            logEvent('device_updated', "تم تحديث الجهاز: $name", $id);
            
            sendJSON([
                'success' => true,
                'message' => 'Device updated successfully'
            ]);
        } else {
            sendJSON(['error' => 'Failed to update device'], 500);
        }
        
    } catch (Exception $e) {
        sendJSON(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// دالة لحذف جهاز
function deleteDevice() {
    $id = $_GET['id'] ?? 0;
    
    if (!$id) {
        sendJSON(['error' => 'Device ID is required'], 400);
    }
    
    $db = getDB();
    
    try {
        // الحصول على تفاصيل الجهاز
        $stmt = $db->prepare("SELECT name, image_path FROM ups_devices WHERE id = ? AND is_active = 1");
        $stmt->execute([$id]);
        $device = $stmt->fetch();
        
        if (!$device) {
            sendJSON(['error' => 'Device not found'], 404);
        }
        
        // حذف الجهاز (soft delete)
        $stmt = $db->prepare("UPDATE ups_devices SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $result = $stmt->execute([$id]);
        
        if ($result) {
            // حذف الصورة
            if ($device['image_path'] && file_exists(UPLOAD_PATH . $device['image_path'])) {
                unlink(UPLOAD_PATH . $device['image_path']);
            }
            
            // تسجيل الحدث
            logEvent('device_deleted', "تم حذف الجهاز: " . $device['name'], $id);
            
            sendJSON([
                'success' => true,
                'message' => 'Device deleted successfully'
            ]);
        } else {
            sendJSON(['error' => 'Failed to delete device'], 500);
        }
        
    } catch (Exception $e) {
        sendJSON(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// دالة للحصول على حالة الأجهزة
function getStatus() {
    $db = getDB();
    
    try {
        $stmt = $db->query("SELECT * FROM ups_latest_status WHERE status IS NOT NULL");
        $devices = $stmt->fetchAll();
        
        sendJSON([
            'success' => true,
            'data' => $devices,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        sendJSON(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// دالة لتحديث حالة جميع الأجهزة
function refreshStatus() {
    $db = getDB();
    
    try {
        // الحصول على جميع الأجهزة النشطة
        $stmt = $db->query("
            SELECT u.*, t.name as type_name, t.mib_file 
            FROM ups_devices u
            LEFT JOIN ups_models m ON u.model_id = m.id
            LEFT JOIN ups_types t ON m.type_id = t.id
            WHERE u.is_active = 1
        ");
        $devices = $stmt->fetchAll();
        
        $updated_count = 0;
        $errors = [];
        
        foreach ($devices as $device) {
            try {
                $status_data = collectDeviceData($device);
                
                if ($status_data) {
                    // إدخال البيانات الجديدة
                    $stmt = $db->prepare("
                        INSERT INTO ups_status (
                            ups_id, status, input_voltage, input_current, input_frequency,
                            output_voltage, output_current, output_frequency, load_percentage,
                            battery_voltage, battery_percentage, battery_temperature, runtime_remaining
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $device['id'],
                        $status_data['status'],
                        $status_data['input_voltage'],
                        $status_data['input_current'],
                        $status_data['input_frequency'],
                        $status_data['output_voltage'],
                        $status_data['output_current'],
                        $status_data['output_frequency'],
                        $status_data['load_percentage'],
                        $status_data['battery_voltage'],
                        $status_data['battery_percentage'],
                        $status_data['battery_temperature'],
                        $status_data['runtime_remaining']
                    ]);
                    
                    // تحديث آخر ظهور للجهاز
                    $stmt = $db->prepare("UPDATE ups_devices SET last_seen = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$device['id']]);
                    
                    $updated_count++;
                } else {
                    $errors[] = "Failed to collect data from {$device['name']} ({$device['ip_address']})";
                }
                
            } catch (Exception $e) {
                $errors[] = "Error with {$device['name']}: " . $e->getMessage();
            }
        }
        
        sendJSON([
            'success' => true,
            'message' => "Updated $updated_count devices",
            'updated_count' => $updated_count,
            'total_devices' => count($devices),
            'errors' => $errors,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        sendJSON(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// دالة للحصول على الإحصائيات
function getStats() {
    try {
        $stats = getSystemStats();
        
        sendJSON([
            'success' => true,
            'data' => $stats,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        sendJSON(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// دالة لاختبار الاتصال
function testConnection() {
    $ip = $_GET['ip'] ?? '';
    $community = $_GET['community'] ?? 'public';
    
    if (!$ip) {
        sendJSON(['error' => 'IP address is required'], 400);
    }
    
    $result = testDeviceConnection($ip, $community);
    
    sendJSON([
        'success' => true,
        'data' => $result
    ]);
}

// دالة لاختبار اتصال الجهاز
function testDeviceConnection($ip, $community = 'public') {
    try {
        // محاولة ping أولاً
        $ping_result = ping($ip);
        
        if (!$ping_result) {
            return [
                'status' => 'offline',
                'ping' => false,
                'snmp' => false,
                'message' => 'Device is not reachable'
            ];
        }
        
        // محاولة SNMP
        $snmp_result = testSNMP($ip, $community);
        
        return [
            'status' => $snmp_result ? 'online' : 'reachable',
            'ping' => $ping_result,
            'snmp' => $snmp_result,
            'message' => $snmp_result ? 'Device is online and responding to SNMP' : 'Device is reachable but SNMP failed'
        ];
        
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'ping' => false,
            'snmp' => false,
            'message' => 'Connection test failed: ' . $e->getMessage()
        ];
    }
}

// دالة ping بسيطة
function ping($ip) {
    $command = "ping -c 1 -W 1 $ip";
    exec($command, $output, $return_code);
    return $return_code === 0;
}

// دالة لاختبار SNMP
function testSNMP($ip, $community) {
    try {
        // محاولة قراءة OID بسيط (sysDescr)
        $result = @snmpget($ip, $community, "1.3.6.1.2.1.1.1.0", SNMP_TIMEOUT);
        return $result !== false;
    } catch (Exception $e) {
        return false;
    }
}

// دالة لجمع بيانات الجهاز عبر SNMP
function collectDeviceData($device) {
    try {
        $ip = $device['ip_address'];
        $community = $device['snmp_community'];
        
        // الحصول على OIDs المطلوبة حسب نوع الجهاز
        $db = getDB();
        $stmt = $db->prepare("
            SELECT oid_name, oid_value, data_type, unit
            FROM snmp_oids o
            JOIN ups_models m ON o.ups_type_id = m.type_id
            WHERE m.id = ?
        ");
        $stmt->execute([$device['model_id']]);
        $oids = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        if (empty($oids)) {
            return false;
        }
        
        $data = [];
        
        // جمع البيانات
        foreach ($oids as $name => $oid) {
            try {
                $value = @snmpget($ip, $community, $oid, SNMP_TIMEOUT);
                if ($value !== false) {
                    // تنظيف القيمة
                    $value = trim(str_replace(['"', 'INTEGER:', 'STRING:'], '', $value));
                    $data[$name] = is_numeric($value) ? floatval($value) : $value;
                }
            } catch (Exception $e) {
                // تجاهل الأخطاء في OIDs فردية
                continue;
            }
        }
        
        // تحويل البيانات إلى الصيغة المطلوبة
        return mapSNMPData($data);
        
    } catch (Exception $e) {
        return false;
    }
}

// دالة لتحويل بيانات SNMP
function mapSNMPData($snmpData) {
    $mapped = [
        'status' => 'offline',
        'input_voltage' => null,
        'input_current' => null,
        'input_frequency' => null,
        'output_voltage' => null,
        'output_current' => null,
        'output_frequency' => null,
        'load_percentage' => null,
        'battery_voltage' => null,
        'battery_percentage' => null,
        'battery_temperature' => null,
        'runtime_remaining' => null
    ];
    
    // تحويل حالة UPS
    if (isset($snmpData['ups_status'])) {
        $statusMap = [
            1 => 'offline',
            2 => 'online',
            3 => 'battery',
            4 => 'standby',
            5 => 'fault'
        ];
        $mapped['status'] = $statusMap[$snmpData['ups_status']] ?? 'offline';
    }
    
    // تحويل القيم الأخرى
    $fieldMap = [
        'input_voltage' => 'input_voltage',
        'input_current' => 'input_current', 
        'input_frequency' => 'input_frequency',
        'output_voltage' => 'output_voltage',
        'output_current' => 'output_current',
        'output_frequency' => 'output_frequency',
        'output_load' => 'load_percentage',
        'battery_voltage' => 'battery_voltage',
        'battery_capacity' => 'battery_percentage',
        'battery_temperature' => 'battery_temperature'
    ];
    
    foreach ($fieldMap as $snmpField => $dbField) {
        if (isset($snmpData[$snmpField]) && is_numeric($snmpData[$snmpField])) {
            $mapped[$dbField] = $snmpData[$snmpField];
        }
    }
    
    return $mapped;
}

// دالة مساعدة لتحويل أول حرف إلى كبير
function capitalize($string) {
    return ucfirst($string);
}
?>