<?php
// =================================================================
// FILE: sections/settings.php - مفقود
// =================================================================
require_once '../config.php';
requireLogin();
requirePermission('manage_settings');
?>
<div class="settings-section">
    <h2><i class="fas fa-cog"></i> إعدادات النظام</h2>
    
    <div class="settings-form">
        <div class="form-group">
            <label>اسم النظام</label>
            <input type="text" value="DNC MON System" name="site_name">
        </div>
        
        <div class="form-group">
            <label>فترة التحديث (ثانية)</label>
            <input type="number" value="30" name="refresh_interval">
        </div>
        
        <button class="btn btn-primary" onclick="saveSettings()">حفظ الإعدادات</button>
    </div>
</div>