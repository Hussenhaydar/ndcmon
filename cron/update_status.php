<?php
/**
 * DNC MON System - Automatic Status Update Cron Job
 * سكريبت التحديث التلقائي لحالة أجهزة UPS
 * 
 * يتم تشغيله كل دقيقة عبر Cron Job
 * * * * * * php /path/to/dnc/cron/update_status.php
 */

// تعيين مسار النظام
define('SYSTEM_ROOT', dirname(__DIR__));
require_once SYSTEM_ROOT . '/config.php';

// التأكد من تشغيل السكريبت من command line فقط
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

// إعداد متغيرات السكريبت
$startTime = microtime(true);
$processedDevices = 0;
$errorDevices = 0;
$alerts = [];
$logMessages = [];

try {
    $db = getDB();
    
    // الحصول على جميع الأجهزة النشطة
    $stmt = $db->query("
        SELECT 
            u.*,
            t.name as type_name,
            t.phase_type,
            t.mib_file,
            m.model_name
        FROM ups_devices u
        LEFT JOIN ups_models m ON u.model_id = m.id
        LEFT JOIN ups_types t ON m.type_id = t.id
        WHERE u.is_active = 1
        ORDER BY u.id
    ");
    
    $devices = $stmt->fetchAll();
    
    if (empty($devices)) {
        logMessage("No active devices found");
        exit(0);
    }
    
    logMessage("Starting status update for " . count($devices) . " devices");
    
    foreach ($devices as $device) {
        try {
            processDevice($device);
            $processedDevices++;
            
        } catch (Exception $e) {
            $errorDevices++;
            logMessage("Error processing device {$device['name']}: " . $e->getMessage(), 'ERROR');
        }
        
        // توقف قصير لتجنب الحمل الزائد
        usleep(500000); // 0.5 ثانية
    }
    
    // تحديث إحصائيات النظام
    updateSystemStats();
    
    // معالجة التنبيهات
    processAlerts();
    
    // تنظيف البيانات القديمة (كل ساعة)
    if (date('i') == '00') {
        cleanupOldData();
    }
    
    $executionTime = round(microtime(true) - $startTime, 2);
    
    logMessage("Status update completed in {$executionTime}s. Processed: {$processedDevices}, Errors: {$errorDevices}");
    
} catch (Exception $e) {
    logMessage("Critical error in cron job: " . $e->getMessage(), 'CRITICAL');
    exit(1);
}

/**
 * معالجة جهاز واحد
 */
function processDevice($device) {
    global $db, $alerts;
    
    $deviceId = $device['id'];
    $deviceName = $device['name'];
    $deviceIP = $device['ip_address'];
    
    logMessage("Processing device: {$deviceName} ({$deviceIP})");
    
    // اختبار الاتصال أولاً
    if (!pingDevice($deviceIP)) {
        logMessage("Device {$deviceName} is not reachable", 'WARNING');
        insertStatusRecord($deviceId, ['status' => 'offline']);
        createAlert($deviceId, 'warning', "الجهاز {$deviceName} غير قابل للوصول");
        return;
    }
    
    // جمع بيانات SNMP
    $statusData = collectDeviceStatus($device);
    
    if ($statusData) {
        // إدخال البيانات في قاعدة البيانات
        insertStatusRecord($deviceId, $statusData);
        
        // تحديث آخر ظهور للجهاز
        updateLastSeen($deviceId);
        
        // فحص التنبيهات
        checkDeviceAlerts($device, $statusData);
        
        logMessage("Successfully updated device: {$deviceName}");
    } else {
        logMessage("Failed to collect SNMP data from device: {$deviceName}", 'WARNING');
        insertStatusRecord($deviceId, ['status' => 'offline']);
    }
}

/**
 * اختبار ping للجهاز
 */
function pingDevice($ip) {
    $command = "ping -c 1 -W 2 " . escapeshellarg($ip) . " > /dev/null 2>&1";
    exec($command, $output, $returnCode);
    return $returnCode === 0;
}

/**
 * جمع بيانات SNMP من الجهاز
 */
function collectDeviceStatus($device) {
    global $db;
    
    $ip = $device['ip_address'];
    $community = $device['snmp_community'] ?: 'public';
    $version = $device['snmp_version'] ?: '2c';
    
    try {
        // الحصول على OIDs المطلوبة حسب نوع الجهاز
        $stmt = $db->prepare("
            SELECT oid_name, oid_value, data_type, unit
            FROM snmp_oids o
            JOIN ups_models m ON o.ups_type_id = m.type_id
            WHERE m.id = ?
        ");
        $stmt->execute([$device['model_id']]);
        $oids = $stmt->fetchAll();
        
        if (empty($oids)) {
            logMessage("No OIDs found for device model: " . $device['model_name'], 'WARNING');
            return false;
        }
        
        $snmpData = [];
        
        // جمع البيانات من كل OID
        foreach ($oids as $oid) {
            try {
                $value = getSNMPValue($ip, $community, $oid['oid_value'], $version);
                if ($value !== false) {
                    $snmpData[$oid['oid_name']] = $value;
                }
            } catch (Exception $e) {
                logMessage("SNMP error for OID {$oid['oid_name']}: " . $e->getMessage(), 'DEBUG');
            }
        }
        
        if (empty($snmpData)) {
            return false;
        }
        
        // تحويل البيانات إلى الصيغة المطلوبة
        return mapSNMPToStatus($snmpData);
        
    } catch (Exception $e) {
        logMessage("Error collecting SNMP data: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * الحصول على قيمة SNMP واحدة
 */
function getSNMPValue($ip, $community, $oid, $version = '2c') {
    try {
        // تعيين timeout و retries
        $timeout = 2000000; // 2 ثانية
        $retries = 2;
        
        switch ($version) {
            case '1':
                $result = @snmpget($ip, $community, $oid, $timeout, $retries);
                break;
            case '2c':
                $result = @snmp2_get($ip, $community, $oid, $timeout, $retries);
                break;
            case '3':
                // يحتاج معاملات إضافية للإصدار 3
                $result = false;
                break;
            default:
                $result = @snmp2_get($ip, $community, $oid, $timeout, $retries);
        }
        
        if ($result === false) {
            return false;
        }
        
        // تنظيف القيمة المستلمة
        $value = cleanSNMPValue($result);
        return $value;
        
    } catch (Exception $e) {
        return false;
    }
}

/**
 * تنظيف قيم SNMP
 */
function cleanSNMPValue($value) {
    // إزالة النصوص الإضافية من SNMP
    $value = str_replace([
        'INTEGER: ', 'STRING: ', 'Gauge32: ', 'Counter32: ',
        'Counter64: ', 'TimeTicks: ', 'IpAddress: ', 'OID: ',
        '"', "'", '(', ')'
    ], '', $value);
    
    $value = trim($value);
    
    // تحويل للرقم إذا كان رقمياً
    if (is_numeric($value)) {
        return (float)$value;
    }
    
    return $value;
}

/**
 * تحويل بيانات SNMP إلى تنسيق قاعدة البيانات
 */
function mapSNMPToStatus($snmpData) {
    $status = [
        'status' => 'online',
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
        'runtime_remaining' => null,
        'alarm_status' => null
    ];
    
    // تحويل حالة UPS
    if (isset($snmpData['ups_status'])) {
        $upsStatus = (int)$snmpData['ups_status'];
        $statusMap = [
            1 => 'offline',
            2 => 'online',
            3 => 'battery',
            4 => 'standby',
            5 => 'fault',
            6 => 'fault'
        ];
        $status['status'] = $statusMap[$upsStatus] ?? 'unknown';
    }
    
    // تحويل حالة البطارية
    if (isset($snmpData['battery_status'])) {
        $batteryStatus = (int)$snmpData['battery_status'];
        if ($batteryStatus === 3) { // batteryLow
            $status['status'] = 'battery';
        }
    }
    
    // تحويل القيم الرقمية
    $fieldMapping = [
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
    
    foreach ($fieldMapping as $snmpField => $statusField) {
        if (isset($snmpData[$snmpField]) && is_numeric($snmpData[$snmpField])) {
            $value = (float)$snmpData[$snmpField];
            
            // تطبيق عوامل التحويل إذا لزم الأمر
            switch ($snmpField) {
                case 'input_voltage':
                case 'output_voltage':
                case 'battery_voltage':
                    // بعض الأجهزة ترسل بوحدة 0.1V
                    if ($value > 5000) {
                        $value = $value / 10;
                    }
                    break;
                case 'input_frequency':
                case 'output_frequency':
                    // بعض الأجهزة ترسل بوحدة 0.1Hz
                    if ($value > 1000) {
                        $value = $value / 10;
                    }
                    break;
            }
            
            $status[$statusField] = $value;
        }
    }
    
    return $status;
}

/**
 * إدخال سجل حالة جديد
 */
function insertStatusRecord($deviceId, $statusData) {
    global $db;
    
    try {
        $stmt = $db->prepare("
            INSERT INTO ups_status (
                ups_id, status, input_voltage, input_current, input_frequency,
                output_voltage, output_current, output_frequency, load_percentage,
                battery_voltage, battery_percentage, battery_temperature, 
                runtime_remaining, alarm_status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $deviceId,
            $statusData['status'] ?? 'offline',
            $statusData['input_voltage'] ?? null,
            $statusData['input_current'] ?? null,
            $statusData['input_frequency'] ?? null,
            $statusData['output_voltage'] ?? null,
            $statusData['output_current'] ?? null,
            $statusData['output_frequency'] ?? null,
            $statusData['load_percentage'] ?? null,
            $statusData['battery_voltage'] ?? null,
            $statusData['battery_percentage'] ?? null,
            $statusData['battery_temperature'] ?? null,
            $statusData['runtime_remaining'] ?? null,
            $statusData['alarm_status'] ?? null
        ]);
        
    } catch (Exception $e) {
        logMessage("Error inserting status record: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * تحديث آخر ظهور للجهاز
 */
function updateLastSeen($deviceId) {
    global $db;
    
    try {
        $stmt = $db->prepare("UPDATE ups_devices SET last_seen = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$deviceId]);
    } catch (Exception $e) {
        logMessage("Error updating last seen: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * فحص التنبيهات للجهاز
 */
function checkDeviceAlerts($device, $statusData) {
    $deviceId = $device['id'];
    $deviceName = $device['name'];
    
    // فحص حالة UPS
    if ($statusData['status'] === 'fault') {
        createAlert($deviceId, 'critical', "عطل في الجهاز {$deviceName}");
    } elseif ($statusData['status'] === 'battery') {
        createAlert($deviceId, 'warning', "الجهاز {$deviceName} يعمل على البطارية");
    } elseif ($statusData['status'] === 'offline') {
        createAlert($deviceId, 'warning', "الجهاز {$deviceName} غير متصل");
    }
    
    // فحص مستوى البطارية
    if (isset($statusData['battery_percentage'])) {
        $batteryLevel = $statusData['battery_percentage'];
        if ($batteryLevel < 20) {
            createAlert($deviceId, 'critical', "مستوى البطارية منخفض في {$deviceName}: {$batteryLevel}%");
        } elseif ($batteryLevel < 50) {
            createAlert($deviceId, 'warning', "مستوى البطارية متوسط في {$deviceName}: {$batteryLevel}%");
        }
    }
    
    // فحص الحمولة
    if (isset($statusData['load_percentage'])) {
        $loadLevel = $statusData['load_percentage'];
        if ($loadLevel > 90) {
            createAlert($deviceId, 'critical', "حمولة عالية في {$deviceName}: {$loadLevel}%");
        } elseif ($loadLevel > 80) {
            createAlert($deviceId, 'warning', "حمولة مرتفعة في {$deviceName}: {$loadLevel}%");
        }
    }
    
    // فحص درجة الحرارة
    if (isset($statusData['battery_temperature'])) {
        $temperature = $statusData['battery_temperature'];
        if ($temperature > 45) {
            createAlert($deviceId, 'critical', "درجة حرارة عالية في {$deviceName}: {$temperature}°C");
        } elseif ($temperature > 40) {
            createAlert($deviceId, 'warning', "درجة حرارة مرتفعة في {$deviceName}: {$temperature}°C");
        }
    }
    
    // فحص الجهد
    if (isset($statusData['input_voltage'])) {
        $voltage = $statusData['input_voltage'];
        if ($voltage < 200 || $voltage > 250) {
            createAlert($deviceId, 'warning', "جهد دخل غير طبيعي في {$deviceName}: {$voltage}V");
        }
    }
}

/**
 * إنشاء تنبيه
 */
function createAlert($deviceId, $type, $message) {
    global $db, $alerts;
    
    try {
        // فحص إذا كان التنبيه موجود مسبقاً (تجنب التكرار)
        $stmt = $db->prepare("
            SELECT id FROM ups_alerts 
            WHERE ups_id = ? AND message = ? AND is_resolved = 0 
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([$deviceId, $message]);
        
        if ($stmt->fetch()) {
            return; // التنبيه موجود مسبقاً
        }
        
        // إدخال التنبيه الجديد
        $stmt = $db->prepare("
            INSERT INTO ups_alerts (ups_id, alert_type, message) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$deviceId, $type, $message]);
        
        $alerts[] = [
            'device_id' => $deviceId,
            'type' => $type,
            'message' => $message
        ];
        
        logMessage("Alert created: $message", 'ALERT');
        
    } catch (Exception $e) {
        logMessage("Error creating alert: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * تحديث إحصائيات النظام
 */
function updateSystemStats() {
    global $db;
    
    try {
        // يتم تحديث الإحصائيات تلقائياً عبر VIEW في قاعدة البيانات
        logMessage("System stats updated");
    } catch (Exception $e) {
        logMessage("Error updating system stats: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * معالجة التنبيهات
 */
function processAlerts() {
    global $alerts;
    
    if (empty($alerts)) {
        return;
    }
    
    logMessage("Processing " . count($alerts) . " alerts");
    
    // يمكن إضافة إرسال إيميلات أو SMS هنا
    foreach ($alerts as $alert) {
        // إرسال تنبيه عبر البريد الإلكتروني (اختياري)
        sendEmailAlert($alert);
    }
}

/**
 * إرسال تنبيه عبر البريد الإلكتروني
 */
function sendEmailAlert($alert) {
    // يمكن تطبيق هذه الوظيفة لاحقاً
    // mail($to, $subject, $message, $headers);
}

/**
 * تنظيف البيانات القديمة
 */
function cleanupOldData() {
    global $db;
    
    try {
        // حذف بيانات الحالة أقدم من أسبوع
        $stmt = $db->prepare("DELETE FROM ups_status WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 1 WEEK)");
        $deletedStatus = $stmt->execute() ? $stmt->rowCount() : 0;
        
        // حذف التنبيهات المحلولة أقدم من 3 أيام
        $stmt = $db->prepare("DELETE FROM ups_alerts WHERE is_resolved = 1 AND resolved_at < DATE_SUB(NOW(), INTERVAL 3 DAY)");
        $deletedAlerts = $stmt->execute() ? $stmt->rowCount() : 0;
        
        if ($deletedStatus > 0 || $deletedAlerts > 0) {
            logMessage("Cleanup: Deleted $deletedStatus status records and $deletedAlerts alerts");
        }
        
    } catch (Exception $e) {
        logMessage("Error during cleanup: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * تسجيل رسالة في السجل
 */
function logMessage($message, $level = 'INFO') {
    global $logMessages;
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message";
    
    // طباعة على الشاشة (CLI)
    echo $logEntry . "\n";
    
    // حفظ في ملف السجل
    $logFile = SYSTEM_ROOT . '/logs/cron.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logEntry . "\n", FILE_APPEND | LOCK_EX);
    
    $logMessages[] = $logEntry;
    
    // حفظ في قاعدة البيانات للأحداث المهمة
    if (in_array($level, ['ERROR', 'CRITICAL', 'ALERT'])) {
        try {
            global $db;
            $stmt = $db->prepare("
                INSERT INTO event_logs (event_type, description) 
                VALUES (?, ?)
            ");
            $stmt->execute(['cron_' . strtolower($level), $message]);
        } catch (Exception $e) {
            // تجنب infinite loop
        }
    }
}

/**
 * إرسال تقرير يومي (اختياري)
 */
function sendDailyReport() {
    global $processedDevices, $errorDevices, $alerts;
    
    $report = [
        'date' => date('Y-m-d'),
        'processed_devices' => $processedDevices,
        'error_devices' => $errorDevices,
        'alerts_count' => count($alerts),
        'execution_time' => round(microtime(true) - $GLOBALS['startTime'], 2)
    ];
    
    // يمكن حفظ التقرير في ملف أو إرساله عبر البريد
    $reportFile = SYSTEM_ROOT . '/logs/daily_report_' . date('Y-m-d') . '.json';
    file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// إرسال تقرير يومي في نهاية اليوم (23:59)
if (date('H:i') == '23:59') {
    sendDailyReport();
}

exit(0);
?>