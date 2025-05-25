// DNC MON System - Main JavaScript File

// متغيرات عامة
let currentSection = 'dashboard';
let refreshInterval = null;
let charts = {};

// تهيئة الصفحة عند التحميل
document.addEventListener('DOMContentLoaded', function() {
    initializeApp();
});

// دالة التهيئة الرئيسية
function initializeApp() {
    // تهيئة التنقل
    initializeNavigation();
    
    // تهيئة الفلاتر
    initializeFilters();
    
    // بدء التحديث التلقائي
    startAutoRefresh();
    
    // تهيئة الرسوم البيانية
    initializeCharts();
    
    // تهيئة الأحداث
    initializeEvents();
}

// تهيئة التنقل بين الأقسام
function initializeNavigation() {
    const navItems = document.querySelectorAll('.nav-item');
    
    navItems.forEach(item => {
        item.addEventListener('click', function() {
            const section = this.dataset.section;
            showSection(section);
        });
    });
}

// عرض قسم معين
function showSection(section) {
    // تحديث التنقل النشط
    document.querySelectorAll('.nav-item').forEach(item => {
        item.classList.remove('active');
        if (item.dataset.section === section) {
            item.classList.add('active');
        }
    });
    
    // إخفاء جميع الأقسام
    document.querySelectorAll('.section').forEach(sec => {
        sec.classList.remove('active');
    });
    
    // عرض القسم المطلوب
    let sectionElement = document.getElementById(section + '-section');
    
    if (!sectionElement) {
        // تحميل القسم إذا لم يكن موجود
        loadSection(section);
    } else {
        sectionElement.classList.add('active');
    }
    
    currentSection = section;
}

// تحميل قسم من الخادم
async function loadSection(section) {
    try {
        const response = await fetch(`sections/${section}.php`);
        const html = await response.text();
        
        // إنشاء عنصر القسم
        const sectionDiv = document.createElement('div');
        sectionDiv.id = section + '-section';
        sectionDiv.className = 'section active';
        sectionDiv.innerHTML = html;
        
        document.querySelector('.container').appendChild(sectionDiv);
        
        // تهيئة أي سكريبتات خاصة بالقسم
        if (typeof window[`init${capitalize(section)}`] === 'function') {
            window[`init${capitalize(section)}`]();
        }
    } catch (error) {
        console.error('Error loading section:', error);
        showNotification('خطأ في تحميل القسم', 'error');
    }
}

// تهيئة الفلاتر
function initializeFilters() {
    const filterInputs = document.querySelectorAll('.filter-section input, .filter-section select');
    
    filterInputs.forEach(input => {
        input.addEventListener('input', debounce(applyFilters, 500));
    });
}

// تطبيق الفلاتر
function applyFilters() {
    const filters = {
        name: document.getElementById('filterName')?.value.toLowerCase() || '',
        ip: document.getElementById('filterIP')?.value || '',
        status: document.getElementById('filterStatus')?.value || '',
        type: document.getElementById('filterType')?.value || '',
        location: document.getElementById('filterLocation')?.value.toLowerCase() || '',
        model: document.getElementById('filterModel')?.value || ''
    };
    
    const cards = document.querySelectorAll('.ups-card');
    let visibleCount = 0;
    
    cards.forEach(card => {
        let show = true;
        
        if (filters.name && !card.dataset.name.includes(filters.name)) show = false;
        if (filters.ip && !card.dataset.ip.includes(filters.ip)) show = false;
        if (filters.status && card.dataset.status !== filters.status) show = false;
        if (filters.type && card.dataset.type !== filters.type) show = false;
        if (filters.location && !card.dataset.location.includes(filters.location)) show = false;
        if (filters.model && card.dataset.model !== filters.model) show = false;
        
        card.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });
    
    // عرض رسالة إذا لم توجد نتائج
    const noResults = document.getElementById('noResults');
    if (noResults) {
        noResults.style.display = visibleCount === 0 ? 'block' : 'none';
    }
}

// إعادة تعيين الفلاتر
function resetFilters() {
    document.querySelectorAll('.filter-section input, .filter-section select').forEach(input => {
        input.value = '';
    });
    
    applyFilters();
}

// بدء التحديث التلقائي
function startAutoRefresh() {
    // تحديث كل 30 ثانية
    refreshInterval = setInterval(() => {
        if (currentSection === 'dashboard') {
            refreshDashboard();
        }
    }, 30000);
}

// إيقاف التحديث التلقائي
function stopAutoRefresh() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
        refreshInterval = null;
    }
}

// تحديث لوحة التحكم
async function refreshDashboard() {
    try {
        // تحديث الإحصائيات
        const statsResponse = await fetch('api/ups.php?action=stats');
        const statsData = await statsResponse.json();
        
        if (statsData.success) {
            updateStats(statsData.data);
        }
        
        // تحديث حالة الأجهزة
        const statusResponse = await fetch('api/ups.php?action=status');
        const statusData = await statusResponse.json();
        
        if (statusData.success) {
            updateDeviceStatus(statusData.data);
        }
    } catch (error) {
        console.error('Error refreshing dashboard:', error);
    }
}

// تحديث الإحصائيات
function updateStats(stats) {
    // تحديث الأرقام مع تأثير متحرك
    animateValue('stat-total', stats.total_devices);
    animateValue('stat-online', stats.online_count);
    animateValue('stat-battery', stats.battery_count);
    animateValue('stat-offline', stats.offline_count);
}

// تحديث حالة الأجهزة
function updateDeviceStatus(devices) {
    devices.forEach(device => {
        const card = document.querySelector(`.ups-card[data-id="${device.id}"]`);
        if (card) {
            // تحديث الحالة
            card.className = `ups-card ${device.status || 'offline'}`;
            card.dataset.status = device.status || 'offline';
            
            // تحديث مؤشر الحالة
            const statusIndicator = card.querySelector('.status-indicator');
            if (statusIndicator) {
                statusIndicator.className = `status-indicator ${device.status || 'offline'}`;
            }
            
            // تحديث القيم
            updateCardValue(card, 'input-voltage', device.input_voltage);
            updateCardValue(card, 'output-voltage', device.output_voltage);
            updateCardValue(card, 'load', device.load_percentage);
            updateCardValue(card, 'battery', device.battery_percentage);
            
            // تحديث آخر تحديث
            const lastUpdate = card.querySelector('.last-update');
            if (lastUpdate && device.recorded_at) {
                lastUpdate.textContent = timeAgo(device.recorded_at);
            }
        }
    });
}

// تحديث قيمة في البطاقة
function updateCardValue(card, field, value) {
    const element = card.querySelector(`[data-field="${field}"]`);
    if (element) {
        element.textContent = value !== null ? value : '---';
    }
}

// عرض تفاصيل الجهاز
async function viewDetails(id) {
    try {
        const response = await fetch(`api/ups.php?action=get&id=${id}`);
        const data = await response.json();
        
        if (data.success) {
            showDeviceDetails(data.data);
        }
    } catch (error) {
        console.error('Error loading device details:', error);
        showNotification('خطأ في تحميل تفاصيل الجهاز', 'error');
    }
}

// عرض نافذة تفاصيل الجهاز
function showDeviceDetails(device) {
    // إنشاء محتوى التفاصيل
    const content = `
        <div class="device-details">
            <div class="details-header">
                <h2>${device.name}</h2>
                <span class="status-badge ${device.status}">${getStatusText(device.status)}</span>
            </div>
            
            <div class="details-grid">
                <div class="detail-section">
                    <h3><i class="fas fa-info-circle"></i> معلومات عامة</h3>
                    <div class="detail-row">
                        <span>الموديل:</span>
                        <span>${device.model_name}</span>
                    </div>
                    <div class="detail-row">
                        <span>النوع:</span>
                        <span>${device.phase_type} Phase</span>
                    </div>
                    <div class="detail-row">
                        <span>IP Address:</span>
                        <span>${device.ip_address}</span>
                    </div>
                    <div class="detail-row">
                        <span>الموقع:</span>
                        <span>${device.location || 'غير محدد'}</span>
                    </div>
                </div>
                
                <div class="detail-section">
                    <h3><i class="fas fa-bolt"></i> القراءات الحالية</h3>
                    <div class="detail-row">
                        <span>Input Voltage:</span>
                        <span>${device.input_voltage || '---'} V</span>
                    </div>
                    <div class="detail-row">
                        <span>Output Voltage:</span>
                        <span>${device.output_voltage || '---'} V</span>
                    </div>
                    <div class="detail-row">
                        <span>Load:</span>
                        <span>${device.load_percentage || '---'} %</span>
                    </div>
                    <div class="detail-row">
                        <span>Battery:</span>
                        <span>${device.battery_percentage || '---'} %</span>
                    </div>
                </div>
            </div>
            
            <div class="detail-section">
                <h3><i class="fas fa-exclamation-triangle"></i> آخر التنبيهات</h3>
                <div class="alerts-list">
                    ${device.alerts.map(alert => `
                        <div class="alert-item ${alert.alert_type}">
                            <i class="fas fa-${getAlertIcon(alert.alert_type)}"></i>
                            <span>${alert.message}</span>
                            <small>${timeAgo(alert.created_at)}</small>
                        </div>
                    `).join('')}
                </div>
            </div>
            
            <div class="detail-section">
                <h3><i class="fas fa-chart-line"></i> الرسم البياني</h3>
                <canvas id="deviceChart"></canvas>
            </div>
        </div>
    `;
    
    //