/**
 * Elegant ShortURL & Share - Frontend JS
 * Modal Controller & One-Click Copy functionality
 */

document.addEventListener('DOMContentLoaded', function () {
    // 1. เปิด Modal เมื่อกดปุ่ม Trigger
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('.wpsu-share-trigger-btn');
        if (trigger) {
            e.preventDefault();
            var targetSelector = trigger.getAttribute('data-target-modal');
            var modal = document.querySelector(targetSelector);
            if (modal) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden'; // ป้องกัน background scroll
            }
        }
    });

    // 2. ปิด Modal เมื่อกดปุ่ม X หรือคลิก Backdrop พื้นหลัง
    document.addEventListener('click', function (e) {
        if (e.target.closest('.wpsu-modal-close') || e.target.classList.contains('wpsu-modal-overlay')) {
            var openModals = document.querySelectorAll('.wpsu-modal-overlay');
            openModals.forEach(function (modal) {
                modal.style.display = 'none';
            });
            document.body.style.overflow = '';
        }
    });

    // 3. ปิดด้วยปุ่ม Escape บนคีย์บอร์ด
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var openModals = document.querySelectorAll('.wpsu-modal-overlay');
            openModals.forEach(function (modal) {
                modal.style.display = 'none';
            });
            document.body.style.overflow = '';
        }
    });

    // 4. คัดลอก URL เมื่อคลิกที่ปุ่มวงกลม "คัดลอก URL"
    document.addEventListener('click', function (e) {
        var copyBtn = e.target.closest('.wpsu-btn-copy-circle');
        if (!copyBtn) return;

        e.preventDefault();
        var urlToCopy = copyBtn.getAttribute('data-url');
        if (!urlToCopy) return;

        copyToClipboard(urlToCopy, function (success) {
            if (success) {
                var modal = copyBtn.closest('.wpsu-modal-box');
                var toast = modal ? modal.querySelector('.wpsu-toast') : null;
                var iconLink = copyBtn.querySelector('.wpsu-icon-link-default');
                var iconCheck = copyBtn.querySelector('.wpsu-icon-check-done');
                var label = copyBtn.querySelector('.wpsu-label-copy-text');

                copyBtn.classList.add('wpsu-copied');
                if (iconLink) iconLink.style.display = 'none';
                if (iconCheck) iconCheck.style.display = 'block';
                if (label) label.textContent = 'คัดลอกแล้ว!';

                if (toast) {
                    toast.style.display = 'inline-flex';
                }

                setTimeout(function () {
                    copyBtn.classList.remove('wpsu-copied');
                    if (iconLink) iconLink.style.display = 'block';
                    if (iconCheck) iconCheck.style.display = 'none';
                    if (label) label.textContent = 'คัดลอก URL';
                    if (toast) toast.style.display = 'none';
                }, 2200);
            } else {
                window.prompt('คัดลอกลิงก์:', urlToCopy);
            }
        });
    });

    /**
     * ฟังก์ชันคัดลอกลง Clipboard รองรับทั้ง navigator.clipboard และ execCommand
     */
    function copyToClipboard(text, callback) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () {
                callback(true);
            }).catch(function () {
                fallbackCopy(text, callback);
            });
        } else {
            fallbackCopy(text, callback);
        }
    }

    function fallbackCopy(text, callback) {
        var textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.top = '-9999px';
        textArea.style.left = '-9999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();

        try {
            var successful = document.execCommand('copy');
            document.body.removeChild(textArea);
            callback(successful);
        } catch (err) {
            document.body.removeChild(textArea);
            callback(false);
        }
    }
});
