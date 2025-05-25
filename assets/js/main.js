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
    updateNoResultsMessage(visibleCount);
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
    refreshInterval = setInterval(() => {
        if (currentSection === 'dashboard') {
            refreshDashboard();
        }
    }, 30000);
}

// تحديث لوحة التحكم
async function refreshDashboard() {
    try {
        const [statsResponse, statusResponse] = await Promise.all([
            fetch('api/ups.php?action=stats'),
            fetch('api/ups.php?action=status')
        ]);
        
        const statsData = await statsResponse.json();
        const statusData = await statusResponse.json();
        
        if (statsData.success) updateStats(statsData.data);
        if (statusData.success) updateDeviceStatus(statusData.data);
        
    } catch (error) {
        console.error('Error refreshing dashboard:', error);
    }
}

// تحديث الإحصائيات
function updateStats(stats) {
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
            updateDeviceCard(card, device);
        }
    });
}

// تحديث بطاقة الجهاز
function updateDeviceCard(card, device) {
    // تحديث الحالة
    card.className = `ups-card ${device.status || 'offline'}`;
    card.dataset.status = device.status || 'offline';
    
    // تحديث مؤشر الحالة
    const statusIndicator = card.querySelector('.status-indicator');
    if (statusIndicator) {
        statusIndicator.className = `status-indicator ${device.status || 'offline'}`;
    }
    
    // تحديث القيم
    updateCardValue(card, 'input-voltage', device.input_voltage, 'V');
    updateCardValue(card, 'output-voltage', device.output_voltage, 'V');
    updateCardValue(card, 'load', device.load_percentage, '%');
    updateCardValue(card, 'battery', device.battery_percentage, '%');
    
    // تحديث آخر تحديث
    const lastUpdate = card.querySelector('.last-update');
    if (lastUpdate && device.recorded_at) {
        lastUpdate.textContent = timeAgo(device.recorded_at);
    }
    
    // إضافة مؤشر العطل إذا لزم الأمر
    updateFaultIndicator(card, device);
}

// تحديث قيمة في البطاقة
function updateCardValue(card, field, value, unit = '') {
    const element = card.querySelector(`[data-field="${field}"]`);
    if (element) {
        const displayValue = value !== null ? value + unit : '---';
        element.textContent = displayValue;
    }
}

// تحديث مؤشر العطل
function updateFaultIndicator(card, device) {
    let faultIndicator = card.querySelector('.fault-indicator');
    
    const hasFault = device.status === 'fault' || 
                    (device.battery_percentage && device.battery_percentage < 20) ||
                    (device.load_percentage && device.load_percentage > 90);
    
    if (hasFault && !faultIndicator) {
        faultIndicator = document.createElement('div');
        faultIndicator.className = 'fault-indicator';
        faultIndicator.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
        card.querySelector('.ups-header').appendChild(faultIndicator);
    } else if (!hasFault && faultIndicator) {
        faultIndicator.remove();
    }
}

// عرض تفاصيل الجهاز
async function viewDetails(id) {
    try {
        showLoading();
        const response = await fetch(`api/ups.php?action=get&id=${id}`);
        const data = await response.json();
        
        if (data.success) {
            showDeviceDetailsModal(data.data, data.alerts, data.chartData);
        } else {
            showNotification(data.error || 'خطأ في تحميل البيانات', 'error');
        }
        hideLoading();
    } catch (error) {
        console.error('Error loading device details:', error);
        showNotification('خطأ في تحميل تفاصيل الجهاز', 'error');
        hideLoading();
    }
}

// عرض نافذة تفاصيل الجهاز
function showDeviceDetailsModal(device, alerts, chartData) {
    const modal = createModal('device-details-modal', 'تفاصيل الجهاز');
    
    const content = `
        <div class="device-details">
            <div class="details-header">
                <h2>${device.name}</h2>
                <span class="status-badge ${device.status || 'offline'}">${getStatusText(device.status)}</span>
            </div>
            
            <div class="details-grid">
                <div class="detail-section">
                    <h3><i class="fas fa-info-circle"></i> معلومات عامة</h3>
                    <div class="detail-row">
                        <span>الموديل:</span>
                        <span>${device.model_name || 'غير محدد'}</span>
                    </div>
                    <div class="detail-row">
                        <span>النوع:</span>
                        <span>${device.phase_type ? device.phase_type + ' Phase' : 'غير محدد'}</span>
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
                    ${alerts.length > 0 ? alerts.map(alert => `
                        <div class="alert-item ${alert.alert_type}">
                            <i class="fas fa-${getAlertIcon(alert.alert_type)}"></i>
                            <span>${alert.message}</span>
                            <small>${timeAgo(alert.created_at)}</small>
                        </div>
                    `).join('') : '<p>لا توجد تنبيهات</p>'}
                </div>
            </div>
            
            <div class="detail-section">
                <h3><i class="fas fa-chart-line"></i> الرسم البياني</h3>
                <canvas id="deviceChart" width="400" height="200"></canvas>
            </div>
        </div>
    `;
    
    modal.querySelector('.modal-body').innerHTML = content;
    showModal(modal);
    
    // رسم الرسم البياني
    if (chartData.length > 0) {
        drawDeviceChart(chartData);
    }
}

// إضافة/تعديل جهاز UPS
function showAddModal() {
    currentEditingDevice = null;
    resetUPSForm();
    document.getElementById('modalTitle').textContent = 'إضافة جهاز جديد';
    showModal('upsModal');
}

function editUPS(id) {
    currentEditingDevice = id;
    loadDeviceData(id);
    document.getElementById('modalTitle').textContent = 'تعديل الجهاز';
    showModal('upsModal');
}

// تحميل بيانات الجهاز للتعديل
async function loadDeviceData(id) {
    try {
        const response = await fetch(`api/ups.php?action=get&id=${id}`);
        const data = await response.json();
        
        if (data.success) {
            fillUPSForm(data.data);
        }
    } catch (error) {
        console.error('Error loading device data:', error);
        showNotification('خطأ في تحميل بيانات الجهاز', 'error');
    }
}

// ملء نموذج UPS
function fillUPSForm(device) {
    document.getElementById('ups_id').value = device.id || '';
    document.getElementById('ups_name').value = device.name || '';
    document.getElementById('ups_ip').value = device.ip_address || '';
    document.getElementById('ups_community').value = device.snmp_community || 'public';
    document.getElementById('ups_version').value = device.snmp_version || '2c';
    document.getElementById('ups_model').value = device.model_id || '';
    document.getElementById('ups_location').value = device.location || '';
    document.getElementById('ups_description').value = device.description || '';
}

// إعادة تعيين نموذج UPS
function resetUPSForm() {
    document.getElementById('upsForm').reset();
    document.getElementById('ups_id').value = '';
    document.getElementById('ups_community').value = 'public';
    document.getElementById('ups_version').value = '2c';
}

// حفظ جهاز UPS
async function saveUPS() {
    const form = document.getElementById('upsForm');
    const formData = new FormData(form);
    
    const url = currentEditingDevice ? 
        'api/ups.php?action=update' : 
        'api/ups.php?action=add';
    
    if (currentEditingDevice) {
        formData.append('id', currentEditingDevice);
    }
    
    try {
        showButtonLoading('saveUPSBtn');
        
        const response = await fetch(url, {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            showNotification(
                currentEditingDevice ? 'تم تحديث الجهاز بنجاح' : 'تم إضافة الجهاز بنجاح',
                'success'
            );
            closeModal('upsModal');
            refreshDashboard();
        } else {
            showNotification(data.error || 'خطأ في العملية', 'error');
        }
        
        hideButtonLoading('saveUPSBtn');
        
    } catch (error) {
        console.error('Error saving device:', error);
        showNotification('خطأ في حفظ البيانات', 'error');
        hideButtonLoading('saveUPSBtn');
    }
}

// حذف جهاز UPS
async function deleteUPS(id, name) {
    if (!confirm(`هل أنت متأكد من حذف الجهاز "${name}"؟`)) {
        return;
    }
    
    try {
        const response = await fetch(`api/ups.php?action=delete&id=${id}`);
        const data = await response.json();
        
        if (data.success) {
            showNotification('تم حذف الجهاز بنجاح', 'success');
            // إزالة البطاقة من DOM
            const card = document.querySelector(`.ups-card[data-id="${id}"]`);
            if (card) {
                card.remove();
            }
        } else {
            showNotification(data.error || 'خطأ في حذف الجهاز', 'error');
        }
    } catch (error) {
        console.error('Error deleting device:', error);
        showNotification('خطأ في حذف الجهاز', 'error');
    }
}

// تسجيل الخروج
function logout() {
    if (confirm('هل أنت متأكد من تسجيل الخروج؟')) {
        window.location.href = 'logout.php';
    }
}

// دوال مساعدة
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

function animateValue(elementId, endValue) {
    const element = document.getElementById(elementId);
    if (!element) return;
    
    const startValue = parseInt(element.textContent) || 0;
    const duration = 1000;
    const startTime = Date.now();
    
    const animate = () => {
        const elapsed = Date.now() - startTime;
        const progress = Math.min(elapsed / duration, 1);
        const currentValue = Math.floor(startValue + (endValue - startValue) * progress);
        
        element.textContent = currentValue;
        
        if (progress < 1) {
            requestAnimationFrame(animate);
        }
    };
    
    animate();
}

function timeAgo(timestamp) {
    const now = new Date();
    const time = new Date(timestamp);
    const diffInSeconds = Math.floor((now - time) / 1000);
    
    if (diffInSeconds < 60) return 'منذ ثواني';
    if (diffInSeconds < 3600) return `منذ ${Math.floor(diffInSeconds / 60)} دقيقة`;
    if (diffInSeconds < 86400) return `منذ ${Math.floor(diffInSeconds / 3600)} ساعة`;
    return `منذ ${Math.floor(diffInSeconds / 86400)} يوم`;
}

function getStatusText(status) {
    const statusMap = {
        'online': 'متصل',
        'battery': 'وضع البطارية',
        'offline': 'غير متصل',
        'standby': 'وضع الاستعداد',
        'fault': 'عطل'
    };
    return statusMap[status] || 'غير معروف';
}

function getAlertIcon(type) {
    const iconMap = {
        'critical': 'exclamation-triangle',
        'warning': 'exclamation-circle',
        'info': 'info-circle'
    };
    return iconMap[type] || 'exclamation-circle';
}

function capitalize(string) {
    return string.charAt(0).toUpperCase() + string.slice(1);
}

// دوال النوافذ المنبثقة
function showModal(modalId) {
    const modal = typeof modalId === 'string' ? 
        document.getElementById(modalId) : modalId;
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = typeof modalId === 'string' ? 
        document.getElementById(modalId) : modalId;
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

function createModal(id, title) {
    const modal = document.createElement('div');
    modal.id = id;
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h2>${title}</h2>
                <button class="modal-close" onclick="closeModal('${id}')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body"></div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('${id}')">
                    إغلاق
                </button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    return modal;
}

// دوال التنبيهات
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.innerHTML = `
        <i class="fas fa-${getNotificationIcon(type)}"></i>
        <span>${message}</span>
        <button class="notification-close" onclick="this.parentElement.remove()">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    document.body.appendChild(notification);
    
    // إزالة التنبيه تلقائياً بعد 5 ثواني
    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 5000);
}

function getNotificationIcon(type) {
    const iconMap = {
        'success': 'check-circle',
        'error': 'exclamation-triangle',
        'warning': 'exclamation-circle',
        'info': 'info-circle'
    };
    return iconMap[type] || 'info-circle';
}

// دوال التحميل
function showLoading() {
    let loader = document.getElementById('loading-overlay');
    if (!loader) {
        loader = document.createElement('div');
        loader.id = 'loading-overlay';
        loader.className = 'loading';
        loader.innerHTML = '<div class="spinner"></div><span>جارٍ التحميل...</span>';
        document.body.appendChild(loader);
    }
    loader.style.display = 'flex';
}

function hideLoading() {
    const loader = document.getElementById('loading-overlay');
    if (loader) {
        loader.style.display = 'none';
    }
}

function showButtonLoading(buttonId) {
    const button = document.getElementById(buttonId);
    if (button) {
        button.disabled = true;
        button.innerHTML = '<div class="spinner"></div> جارٍ الحفظ...';
    }
}

function hideButtonLoading(buttonId) {
    const button = document.getElementById(buttonId);
    if (button) {
        button.disabled = false;
        button.innerHTML = '<i class="fas fa-save"></i> حفظ';
    }
}

// تهيئة الأحداث
function initializeEvents() {
    // إغلاق النوافذ المنبثقة عند النقر خارجها
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal')) {
            closeModal(e.target);
        }
    });
    
    // إغلاق النوافذ المنبثقة عند الضغط على Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const activeModal = document.querySelector('.modal.active');
            if (activeModal) {
                closeModal(activeModal);
            }
        }
    });
}

function initializeNotifications() {
    // إزالة التنبيهات القديمة تلقائياً
    setInterval(() => {
        const notifications = document.querySelectorAll('.notification');
        notifications.forEach(notification => {
            if (Date.now() - parseInt(notification.dataset.created || 0) > 10000) {
                notification.remove();
            }
        });
    }, 5000);
}

function initializeForms() {
    // التحقق من صحة عناوين IP
    const ipInputs = document.querySelectorAll('input[type="text"][name="ip_address"]');
    ipInputs.forEach(input => {
        input.addEventListener('blur', function() {
            const ip = this.value;
            if (ip && !isValidIP(ip)) {
                showNotification('تنسيق عنوان IP غير صحيح', 'warning');
                this.focus();
            }
        });
    });
}

function isValidIP(ip) {
    const regex = /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
    return regex.test(ip);
}

function updateNoResultsMessage(visibleCount) {
    let noResults = document.getElementById('noResults');
    if (!noResults) {
        noResults = document.createElement('div');
        noResults.id = 'noResults';
        noResults.className = 'no-results';
        noResults.innerHTML = '<i class="fas fa-search"></i><p>لم يتم العثور على نتائج</p>';
        document.querySelector('.ups-grid').appendChild(noResults);
    }
    noResults.style.display = visibleCount === 0 ? 'block' : 'none';
}

// رسم الرسم البياني
function drawDeviceChart(data) {
    const canvas = document.getElementById('deviceChart');
    if (!canvas || !data.length) return;
    
    const ctx = canvas.getContext('2d');
    const width = canvas.width;
    const height = canvas.height;
    
    // مسح الرسم السابق
    ctx.clearRect(0, 0, width, height);
    
    // إعداد البيانات
    const times = data.map(d => d.time);
    const voltages = data.map(d => parseFloat(d.output_voltage) || 0);
    const loads = data.map(d => parseFloat(d.load_percentage) || 0);
    
    // رسم الخطوط
    drawLine(ctx, times, voltages, 'blue', width, height, Math.max(...voltages));
    drawLine(ctx, times, loads, 'red', width, height, 100);
    
    // إضافة التسميات
    ctx.fillStyle = 'black';
    ctx.font = '12px Arial';
    ctx.fillText('الجهد (أزرق) - الحمولة (أحمر)', 10, 20);
}

function drawLine(ctx, times, values, color, width, height, maxValue) {
    if (!values.length) return;
    
    ctx.strokeStyle = color;
    ctx.lineWidth = 2;
    ctx.beginPath();
    
    const stepX = width / (values.length - 1);
    const stepY = (height - 40) / maxValue;
    
    values.forEach((value, index) => {
        const x = index * stepX;
        const y = height - 20 - (value * stepY);
        
        if (index === 0) {
            ctx.moveTo(x, y);
        } else {
            ctx.lineTo(x, y);
        }
    });
    
    ctx.stroke();
}