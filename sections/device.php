<?php
require_once '../config.php';
requireLogin();

// الحصول على قائمة الأجهزة مع التفاصيل
$db = getDB();
$stmt = $db->prepare("
    SELECT 
        u.*,
        m.model_name,
        m.manufacturer,
        t.name as type_name,
        t.phase_type,
        ls.status,
        ls.load_percentage,
        ls.battery_percentage,
        ls.recorded_at,
        (SELECT COUNT(*) FROM ups_alerts WHERE ups_id = u.id AND is_resolved = 0) as alert_count
    FROM ups_devices u
    LEFT JOIN ups_models m ON u.model_id = m.id
    LEFT JOIN ups_types t ON m.type_id = t.id
    LEFT JOIN ups_latest_status ls ON u.id = ls.id
    WHERE u.is_active = 1
    ORDER BY u.name
");
$stmt->execute();
$devices = $stmt->fetchAll();

// الحصول على قائمة الموديلات للفلتر
$models = $db->query("SELECT id, model_name FROM ups_models ORDER BY model_name")->fetchAll();
?>

<div class="devices-section">
    <!-- إحصائيات سريعة -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon total">
                <i class="fas fa-server"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo count($devices); ?></h3>
                <p>إجمالي الأجهزة</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon online">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo count(array_filter($devices, fn($d) => $d['status'] === 'online')); ?></h3>
                <p>أجهزة متصلة</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon warning">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo array_sum(array_column($devices, 'alert_count')); ?></h3>
                <p>تنبيهات نشطة</p>
            </div>
        </div>
    </div>

    <!-- أدوات التحكم -->
    <div class="controls-section">
        <div class="controls-left">
            <h2><i class="fas fa-server"></i> إدارة الأجهزة</h2>
        </div>
        <div class="controls-right">
            <?php if (hasPermission('add_ups')): ?>
            <button class="btn btn-success" onclick="showAddModal()">
                <i class="fas fa-plus"></i>
                إضافة جهاز جديد
            </button>
            <?php endif; ?>
            <button class="btn btn-primary" onclick="refreshAllDevices()">
                <i class="fas fa-sync"></i>
                تحديث الحالة
            </button>
        </div>
    </div>

    <!-- فلاتر متقدمة -->
    <div class="filter-section collapsed" id="advancedFilters">
        <div class="filter-header" onclick="toggleFilters()">
            <h3><i class="fas fa-filter"></i> فلاتر البحث المتقدم</h3>
            <i class="fas fa-chevron-down toggle-icon"></i>
        </div>
        <div class="filter-content">
            <div class="filter-grid">
                <div class="filter-group">
                    <label>اسم الجهاز</label>
                    <input type="text" id="filterDeviceName" placeholder="ابحث بالاسم...">
                </div>
                <div class="filter-group">
                    <label>عنوان IP</label>
                    <input type="text" id="filterDeviceIP" placeholder="192.168.x.x">
                </div>
                <div class="filter-group">
                    <label>الحالة</label>
                    <select id="filterDeviceStatus">
                        <option value="">جميع الحالات</option>
                        <option value="online">متصل</option>
                        <option value="battery">وضع البطارية</option>
                        <option value="offline">غير متصل</option>
                        <option value="fault">عطل</option>
                        <option value="standby">وضع الاستعداد</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>الموديل</label>
                    <select id="filterDeviceModel">
                        <option value="">جميع الموديلات</option>
                        <?php foreach ($models as $model): ?>
                        <option value="<?php echo $model['id']; ?>"><?php echo $model['model_name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>الموقع</label>
                    <input type="text" id="filterDeviceLocation" placeholder="الموقع...">
                </div>
            </div>
            <div class="filter-actions">
                <button class="btn btn-secondary" onclick="resetDeviceFilters()">
                    <i class="fas fa-redo"></i>
                    إعادة تعيين
                </button>
                <button class="btn btn-primary" onclick="applyDeviceFilters()">
                    <i class="fas fa-search"></i>
                    بحث
                </button>
            </div>
        </div>
    </div>

    <!-- جدول الأجهزة -->
    <div class="devices-table-container">
        <table class="devices-table" id="devicesTable">
            <thead>
                <tr>
                    <th>الجهاز</th>
                    <th>IP Address</th>
                    <th>الموديل</th>
                    <th>الحالة</th>
                    <th>الحمولة</th>
                    <th>البطارية</th>
                    <th>التنبيهات</th>
                    <th>آخر تحديث</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($devices as $device): ?>
                <tr class="device-row" data-device-id="<?php echo $device['id']; ?>">
                    <td>
                        <div class="device-info">
                            <div class="device-image">
                                <?php if ($device['image_path']): ?>
                                <img src="uploads/<?php echo $device['image_path']; ?>" alt="<?php echo $device['name']; ?>">
                                <?php else: ?>
                                <i class="fas fa-server"></i>
                                <?php endif; ?>
                            </div>
                            <div class="device-details">
                                <strong><?php echo $device['name']; ?></strong>
                                <small><?php echo $device['location'] ?: 'غير محدد'; ?></small>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="ip-address"><?php echo $device['ip_address']; ?></span>
                    </td>
                    <td>
                        <div class="model-info">
                            <span><?php echo $device['model_name'] ?: 'غير محدد'; ?></span>
                            <small><?php echo $device['phase_type'] ? ucfirst($device['phase_type']) . ' Phase' : ''; ?></small>
                        </div>
                    </td>
                    <td>
                        <span class="status-badge <?php echo $device['status'] ?: 'offline'; ?>">
                            <i class="status-icon"></i>
                            <?php echo getStatusName($device['status'] ?: 'offline'); ?>
                        </span>
                    </td>
                    <td>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $device['load_percentage'] ?: 0; ?>%"></div>
                            <span><?php echo $device['load_percentage'] ?: '---'; ?>%</span>
                        </div>
                    </td>
                    <td>
                        <div class="battery-indicator">
                            <i class="fas fa-battery-<?php echo getBatteryIcon($device['battery_percentage']); ?>"></i>
                            <span><?php echo $device['battery_percentage'] ?: '---'; ?>%</span>
                        </div>
                    </td>
                    <td>
                        <?php if ($device['alert_count'] > 0): ?>
                        <span class="alert-badge">
                            <i class="fas fa-exclamation-triangle"></i>
                            <?php echo $device['alert_count']; ?>
                        </span>
                        <?php else: ?>
                        <span class="no-alerts">لا توجد تنبيهات</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="last-update">
                            <?php echo $device['recorded_at'] ? timeAgo($device['recorded_at']) : 'لم يتم التحديث'; ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <button class="btn btn-sm btn-primary" onclick="viewDeviceDetails(<?php echo $device['id']; ?>)" title="عرض التفاصيل">
                                <i class="fas fa-eye"></i>
                            </button>
                            <?php if (hasPermission('edit_ups')): ?>
                            <button class="btn btn-sm btn-secondary" onclick="editDevice(<?php echo $device['id']; ?>)" title="تعديل">
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-info" onclick="testDeviceConnection(<?php echo $device['id']; ?>, '<?php echo $device['ip_address']; ?>')" title="اختبار الاتصال">
                                <i class="fas fa-network-wired"></i>
                            </button>
                            <?php if (hasPermission('delete_ups')): ?>
                            <button class="btn btn-sm btn-danger" onclick="deleteDevice(<?php echo $device['id']; ?>, '<?php echo $device['name']; ?>')" title="حذف">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (empty($devices)): ?>
        <div class="no-devices">
            <i class="fas fa-server"></i>
            <h3>لا توجد أجهزة</h3>
            <p>لم يتم العثور على أي أجهزة UPS في النظام</p>
            <?php if (hasPermission('add_ups')): ?>
            <button class="btn btn-primary" onclick="showAddModal()">
                <i class="fas fa-plus"></i>
                إضافة جهاز جديد
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.devices-section {
    padding: 2rem;
}

.controls-section {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    padding: 1.5rem;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.controls-right {
    display: flex;
    gap: 1rem;
}

.devices-table-container {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.devices-table {
    width: 100%;
    border-collapse: collapse;
}

.devices-table th {
    background: linear-gradient(135deg, #1a237e, #3949ab);
    color: white;
    padding: 1rem;
    text-align: right;
    font-weight: 600;
}

.devices-table td {
    padding: 1rem;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
}

.device-info {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.device-image {
    width: 50px;
    height: 50px;
    border-radius: 8px;
    overflow: hidden;
    background: #f5f5f5;
    display: flex;
    align-items: center;
    justify-content: center;
}

.device-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.device-image i {
    font-size: 1.5rem;
    color: #9e9e9e;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 500;
}

.status-badge.online {
    background: #e8f5e8;
    color: #4caf50;
}

.status-badge.battery {
    background: #fff8e1;
    color: #ff9800;
}

.status-badge.offline {
    background: #f5f5f5;
    color: #9e9e9e;
}

.status-badge.fault {
    background: #ffebee;
    color: #f44336;
}

.progress-bar {
    width: 100px;
    height: 20px;
    background: #f0f0f0;
    border-radius: 10px;
    position: relative;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #4caf50, #8bc34a);
    border-radius: 10px;
    transition: width 0.3s ease;
}

.progress-bar span {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 0.8rem;
    font-weight: bold;
    color: #333;
}

.battery-indicator {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #4caf50;
}

.alert-badge {
    background: #ffebee;
    color: #f44336;
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    font-size: 0.8rem;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.no-alerts {
    color: #9e9e9e;
    font-size: 0.9rem;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
}

.filter-section.collapsed .filter-content {
    display: none;
}

.filter-header {
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.toggle-icon {
    transition: transform 0.3s ease;
}

.filter-section:not(.collapsed) .toggle-icon {
    transform: rotate(180deg);
}

.no-devices {
    text-align: center;
    padding: 4rem;
    color: #9e9e9e;
}

.no-devices i {
    font-size: 4rem;
    margin-bottom: 1rem;
}

@media (max-width: 768px) {
    .devices-table-container {
        overflow-x: auto;
    }
    
    .controls-section {
        flex-direction: column;
        gap: 1rem;
        align-items: stretch;
    }
    
    .action-buttons {
        flex-direction: column;
    }
}
</style>

<script>
// تهيئة صفحة الأجهزة
function initDevices() {
    initDeviceFilters();
    initDeviceTable();
}

function toggleFilters() {
    const filters = document.getElementById('advancedFilters');
    filters.classList.toggle('collapsed');
}

function initDeviceFilters() {
    const filterInputs = document.querySelectorAll('#advancedFilters input, #advancedFilters select');
    filterInputs.forEach(input => {
        input.addEventListener('input', debounce(applyDeviceFilters, 300));
    });
}

function applyDeviceFilters() {
    const filters = {
        name: document.getElementById('filterDeviceName').value.toLowerCase(),
        ip: document.getElementById('filterDeviceIP').value,
        status: document.getElementById('filterDeviceStatus').value,
        model: document.getElementById('filterDeviceModel').value,
        location: document.getElementById('filterDeviceLocation').value.toLowerCase()
    };
    
    const rows = document.querySelectorAll('.device-row');
    let visibleCount = 0;
    
    rows.forEach(row => {
        const deviceName = row.querySelector('.device-details strong').textContent.toLowerCase();
        const deviceIP = row.querySelector('.ip-address').textContent;
        const deviceStatus = row.querySelector('.status-badge').classList[1];
        const deviceLocation = row.querySelector('.device-details small').textContent.toLowerCase();
        
        let show = true;
        
        if (filters.name && !deviceName.includes(filters.name)) show = false;
        if (filters.ip && !deviceIP.includes(filters.ip)) show = false;
        if (filters.status && deviceStatus !== filters.status) show = false;
        if (filters.location && !deviceLocation.includes(filters.location)) show = false;
        
        row.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });
}

function resetDeviceFilters() {
    document.querySelectorAll('#advancedFilters input, #advancedFilters select').forEach(input => {
        input.value = '';
    });
    applyDeviceFilters();
}

async function refreshAllDevices() {
    showLoading();
    try {
        const response = await fetch('api/ups.php?action=refresh');
        const data = await response.json();
        
        if (data.success) {
            showNotification('تم تحديث حالة الأجهزة بنجاح', 'success');
            setTimeout(() => location.reload(), 2000);
        } else {
            showNotification(data.error || 'خطأ في تحديث الأجهزة', 'error');
        }
    } catch (error) {
        showNotification('خطأ في الاتصال', 'error');
    }
    hideLoading();
}

async function testDeviceConnection(deviceId, deviceIP) {
    showLoading();
    try {
        const response = await fetch(`api/ups.php?action=test_connection&ip=${deviceIP}`);
        const data = await response.json();
        
        if (data.success) {
            const result = data.data;
            const status = result.snmp ? 'نجح' : (result.ping ? 'جزئي' : 'فشل');
            const message = `اختبار الاتصال: ${status}\nPing: ${result.ping ? 'نجح' : 'فشل'}\nSNMP: ${result.snmp ? 'نجح' : 'فشل'}`;
            
            showNotification(message, result.snmp ? 'success' : 'warning');
        }
    } catch (error) {
        showNotification('خطأ في اختبار الاتصال', 'error');
    }
    hideLoading();
}

function initDeviceTable() {
    // إضافة ترتيب للجدول
    const headers = document.querySelectorAll('.devices-table th');
    headers.forEach((header, index) => {
        if (index < headers.length - 1) { // عدا عمود الإجراءات
            header.style.cursor = 'pointer';
            header.addEventListener('click', () => sortTable(index));
        }
    });
}

function sortTable(columnIndex) {
    const table = document.getElementById('devicesTable');
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    
    const sortedRows = rows.sort((a, b) => {
        const aText = a.cells[columnIndex].textContent.trim();
        const bText = b.cells[columnIndex].textContent.trim();
        
        if (isNumeric(aText) && isNumeric(bText)) {
            return parseFloat(aText) - parseFloat(bText);
        }
        
        return aText.localeCompare(bText, 'ar');
    });
    
    sortedRows.forEach(row => tbody.appendChild(row));
}

function isNumeric(str) {
    return !isNaN(str) && !isNaN(parseFloat(str));
}
</script>

<?php
function getBatteryIcon($percentage) {
    if ($percentage >= 75) return 'full';
    if ($percentage >= 50) return 'three-quarters';
    if ($percentage >= 25) return 'half';
    if ($percentage > 0) return 'quarter';
    return 'empty';
}
?>