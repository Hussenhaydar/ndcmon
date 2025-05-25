<?php
require_once 'config.php';

// التحقق من تسجيل الدخول
requireLogin();

// الحصول على إحصائيات النظام
$stats = getSystemStats();

// الحصول على قائمة أجهزة UPS
$db = getDB();
$stmt = $db->prepare("
    SELECT 
        u.*,
        m.model_name,
        t.phase_type,
        ls.status,
        ls.input_voltage,
        ls.output_voltage,
        ls.load_percentage,
        ls.battery_percentage,
        ls.recorded_at
    FROM ups_devices u
    LEFT JOIN ups_models m ON u.model_id = m.id
    LEFT JOIN ups_types t ON m.type_id = t.id
    LEFT JOIN ups_latest_status ls ON u.id = ls.id
    WHERE u.is_active = 1
    ORDER BY u.name
");
$stmt->execute();
$devices = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم - DNC MON System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <i class="fas fa-server"></i>
                <span>DNC MON System</span>
            </div>
            <div class="user-menu">
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo $_SESSION['full_name']; ?></span>
                </div>
                <button class="btn btn-secondary" onclick="logout()">
                    <i class="fas fa-sign-out-alt"></i>
                    تسجيل خروج
                </button>
            </div>
        </div>
    </header>

    <!-- Navigation -->
    <div class="nav-container">
        <nav class="nav">
            <div class="nav-item active" data-section="dashboard">
                <i class="fas fa-tachometer-alt"></i>
                لوحة التحكم
            </div>
            <div class="nav-item" data-section="devices">
                <i class="fas fa-server"></i>
                الأجهزة
            </div>
            <div class="nav-item" data-section="reports">
                <i class="fas fa-chart-line"></i>
                التقارير
            </div>
            <?php if (hasPermission('view_users')): ?>
            <div class="nav-item" data-section="users">
                <i class="fas fa-users"></i>
                المستخدمين
            </div>
            <?php endif; ?>
            <?php if (hasPermission('manage_settings')): ?>
            <div class="nav-item" data-section="settings">
                <i class="fas fa-cog"></i>
                الإعدادات
            </div>
            <?php endif; ?>
        </nav>
    </div>

    <!-- Main Content -->
    <main class="container">
        <!-- Dashboard Section -->
        <section id="dashboard-section" class="section active">
            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon total">
                        <i class="fas fa-server"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $stats['total_devices']; ?></h3>
                        <p>إجمالي الأجهزة</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon online">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $stats['online_count']; ?></h3>
                        <p>أجهزة متصلة</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon battery">
                        <i class="fas fa-battery-half"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $stats['battery_count']; ?></h3>
                        <p>وضع البطارية</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon offline">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $stats['offline_count']; ?></h3>
                        <p>أجهزة غير متصلة</p>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-header">
                    <h3>
                        <i class="fas fa-filter"></i>
                        فلتر البحث المتقدم
                    </h3>
                    <?php if (hasPermission('add_ups')): ?>
                    <button class="btn btn-success" onclick="showAddModal()">
                        <i class="fas fa-plus"></i>
                        إضافة جهاز جديد
                    </button>
                    <?php endif; ?>
                </div>
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>اسم الجهاز</label>
                        <input type="text" placeholder="ابحث بالاسم..." id="filterName">
                    </div>
                    <div class="filter-group">
                        <label>IP Address</label>
                        <input type="text" placeholder="192.168.x.x" id="filterIP">
                    </div>
                    <div class="filter-group">
                        <label>الحالة</label>
                        <select id="filterStatus">
                            <option value="">جميع الحالات</option>
                            <option value="online">متصل</option>
                            <option value="battery">وضع البطارية</option>
                            <option value="offline">غير متصل</option>
                            <option value="fault">عطل</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>النوع</label>
                        <select id="filterType">
                            <option value="">جميع الأنواع</option>
                            <option value="single">Single Phase</option>
                            <option value="three">Three Phase</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>الموقع</label>
                        <input type="text" placeholder="الموقع..." id="filterLocation">
                    </div>
                    <div class="filter-group">
                        <label>الموديل</label>
                        <select id="filterModel">
                            <option value="">جميع الموديلات</option>
                            <?php
                            $models = $db->query("SELECT DISTINCT model_name FROM ups_models ORDER BY model_name")->fetchAll();
                            foreach ($models as $model):
                            ?>
                            <option value="<?php echo $model['model_name']; ?>"><?php echo $model['model_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="filter-actions">
                    <button class="btn btn-secondary" onclick="resetFilters()">
                        <i class="fas fa-redo"></i>
                        إعادة تعيين
                    </button>
                    <button class="btn btn-primary" onclick="applyFilters()">
                        <i class="fas fa-search"></i>
                        بحث
                    </button>
                </div>
            </div>

            <!-- UPS Grid -->
            <div class="ups-grid" id="upsGrid">
                <?php foreach ($devices as $device): ?>
                <div class="ups-card <?php echo $device['status'] ?? 'offline'; ?>" 
                     data-id="<?php echo $device['id']; ?>"
                     data-name="<?php echo strtolower($device['name']); ?>"
                     data-ip="<?php echo $device['ip_address']; ?>"
                     data-status="<?php echo $device['status'] ?? 'offline'; ?>"
                     data-type="<?php echo $device['phase_type'] ?? ''; ?>"
                     data-location="<?php echo strtolower($device['location'] ?? ''); ?>"
                     data-model="<?php echo $device['model_name'] ?? ''; ?>">
                    <div class="ups-header">
                        <div class="status-indicator <?php echo $device['status'] ?? 'offline'; ?>"></div>
                        <?php if ($device['status'] == 'fault' || $device['battery_percentage'] < 50): ?>
                        <div class="fault-indicator">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <?php endif; ?>
                        <div class="ups-image">
                            <?php if ($device['image_path']): ?>
                            <img src="uploads/<?php echo $device['image_path']; ?>" alt="<?php echo $device['name']; ?>">
                            <?php else: ?>
                            <i class="fas fa-server"></i>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="ups-info">
                        <div class="ups-name"><?php echo $device['name']; ?></div>
                        <div class="ups-details">
                            <div class="detail-item">
                                <span class="detail-label">Input Voltage</span>
                                <span class="detail-value"><?php echo $device['input_voltage'] ?? '---'; ?>V</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Output Voltage</span>
                                <span class="detail-value"><?php echo $device['output_voltage'] ?? '---'; ?>V</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Load</span>
                                <span class="detail-value"><?php echo $device['load_percentage'] ?? '---'; ?>%</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Battery</span>
                                <span class="detail-value"><?php echo $device['battery_percentage'] ?? '---'; ?>%</span>
                            </div>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">IP Address</span>
                            <span class="detail-value"><?php echo $device['ip_address']; ?></span>
                        </div>
                        <?php if ($device['recorded_at']): ?>
                        <div class="detail-item">
                            <span class="detail-label">آخر تحديث</span>
                            <span class="detail-value"><?php echo timeAgo($device['recorded_at']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="ups-actions">
                        <button class="btn btn-sm btn-primary" onclick="viewDetails(<?php echo $device['id']; ?>)">
                            <i class="fas fa-eye"></i>
                            عرض التفاصيل
                        </button>
                        <?php if (hasPermission('edit_ups')): ?>
                        <button class="btn btn-sm btn-secondary" onclick="editUPS(<?php echo $device['id']; ?>)">
                            <i class="fas fa-edit"></i>
                            تعديل
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Other sections will be loaded dynamically -->
    </main>

    <!-- Add/Edit UPS Modal -->
    <div class="modal" id="upsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>
                    <i class="fas fa-server"></i>
                    <span id="modalTitle">إضافة جهاز جديد</span>
                </h2>
                <button class="modal-close" onclick="closeModal('upsModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="upsForm">
                    <input type="hidden" id="ups_id" name="id">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>اسم الجهاز *</label>
                            <input type="text" name="name" id="ups_name" required class="form-control">
                        </div>

                        <div class="form-group">
                            <label>عنوان IP *</label>
                            <input type="text" name="ip_address" id="ups_ip" required pattern="^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$" class="form-control">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>SNMP Community</label>
                                <input type="text" name="snmp_community" id="ups_community" value="public" class="form-control">
                            </div>

                            <div class="form-group">
                                <label>SNMP Version</label>
                                <select name="snmp_version" id="ups_version" class="form-control">
                                    <option value="1">v1</option>
                                    <option value="2c" selected>v2c</option>
                                    <option value="3">v3</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>الموديل *</label>
                            <select name="model_id" id="ups_model" required class="form-control">
                                <option value="">اختر الموديل</option>
                                <?php
                                $models = $db->query("
                                    SELECT m.id, m.model_name, t.name as type_name, t.phase_type 
                                    FROM ups_models m 
                                    JOIN ups_types t ON m.type_id = t.id 
                                    ORDER BY t.name, m.model_name
                                ")->fetchAll();
                                
                                foreach ($models as $model):
                                ?>
                                <option value="<?php echo $model['id']; ?>">
                                    <?php echo $model['model_name']; ?> (<?php echo $model['type_name']; ?> - <?php echo ucfirst($model['phase_type']); ?> Phase)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>الموقع</label>
                            <input type="text" name="location" id="ups_location" class="form-control">
                        </div>

                        <div class="form-group">
                            <label>الوصف</label>
                            <textarea name="description" id="ups_description" rows="3" class="form-control"></textarea>
                        </div>

                        <div class="form-group">
                            <label>صورة الجهاز</label>
                            <input type="file" name="image" id="ups_image" accept="image/*" class="form-control">
                            <small class="text-muted">الصيغ المسموحة: JPG, PNG, GIF</small>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('upsModal')">
                    <i class="fas fa-times"></i>
                    إلغاء
                </button>
                <button class="btn btn-primary" onclick="saveUPS()">
                    <i class="fas fa-save"></i>
                    حفظ
                </button>
            </div>
        </div>
    </div>

    <script src="assets/js/main.js"></script>
</body>
</html>