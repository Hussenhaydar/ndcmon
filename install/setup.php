<?php
// =================================================================
// FILE: install/setup.php - مفقود
// =================================================================
?>
<!DOCTYPE html>
<html dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تثبيت DNC MON System</title>
    <style>
        body { font-family: Arial; margin: 2rem; background: #f5f5f5; }
        .installer { max-width: 600px; margin: 0 auto; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .btn { padding: 10px 20px; background: #1a237e; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .success { color: green; } .error { color: red; }
        input { width: 100%; padding: 8px; margin: 5px 0; border: 1px solid #ddd; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="installer">
        <h1>🚀 تثبيت DNC MON System</h1>
        
        <div class="step">
            <h3>المتطلبات:</h3>
            <?php
            $checks = [
                'PHP 7.4+' => version_compare(PHP_VERSION, '7.4.0', '>='),
                'PDO MySQL' => extension_loaded('pdo_mysql'),
                'SNMP' => extension_loaded('snmp'),
                'GD' => extension_loaded('gd')
            ];
            
            foreach ($checks as $check => $status) {
                echo "<p class='" . ($status ? 'success' : 'error') . "'>";
                echo ($status ? '✓' : '✗') . " $check</p>";
            }
            ?>
        </div>
        
        <form method="post">
            <h3>إعدادات قاعدة البيانات:</h3>
            <input type="text" name="db_host" placeholder="خادم قاعدة البيانات" value="localhost">
            <input type="text" name="db_name" placeholder="اسم قاعدة البيانات" value="dnc_mon_system">
            <input type="text" name="db_user" placeholder="المستخدم" value="root">
            <input type="password" name="db_pass" placeholder="كلمة المرور">
            
            <button type="submit" name="install" class="btn">🔧 بدء التثبيت</button>
        </form>
        
        <?php
        if (isset($_POST['install'])) {
            try {
                $dsn = "mysql:host={$_POST['db_host']};charset=utf8mb4";
                $pdo = new PDO($dsn, $_POST['db_user'], $_POST['db_pass']);
                
                // إنشاء قاعدة البيانات
                $pdo->exec("CREATE DATABASE IF NOT EXISTS {$_POST['db_name']} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE {$_POST['db_name']}");
                
                // تشغيل سكريبت قاعدة البيانات (مبسط)
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS users (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        username VARCHAR(50) UNIQUE NOT NULL,
                        password VARCHAR(255) NOT NULL,
                        full_name VARCHAR(100) NOT NULL,
                        email VARCHAR(100) UNIQUE NOT NULL,
                        role ENUM('admin', 'operator', 'viewer') DEFAULT 'viewer',
                        is_active BOOLEAN DEFAULT TRUE,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    )
                ");
                
                // إضافة مستخدم افتراضي
                $hashedPassword = password_hash('admin123', PASSWORD_BCRYPT);
                $pdo->prepare("
                    INSERT IGNORE INTO users (username, password, full_name, email, role) 
                    VALUES ('admin', ?, 'مدير النظام', 'admin@dncmon.com', 'admin')
                ")->execute([$hashedPassword]);
                
                echo "<div class='success'><h3>✅ تم التثبيت بنجاح!</h3>";
                echo "<p>يمكنك الآن <a href='../login.php'>تسجيل الدخول</a></p>";
                echo "<p><strong>المستخدم:</strong> admin<br><strong>كلمة المرور:</strong> admin123</p></div>";
                
            } catch (Exception $e) {
                echo "<div class='error'><h3>❌ خطأ في التثبيت:</h3><p>" . $e->getMessage() . "</p></div>";
            }
        }
        ?>
    </div>
</body>
</html>