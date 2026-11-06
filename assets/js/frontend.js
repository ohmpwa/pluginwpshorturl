/**
 * Elegant ShortURL & Share - Frontend JavaScript
 * High Performance, Zero External Dependencies (Vanilla JS)
 */

document.addEventListener('DOMContentLoaded', function () {
    // 1. จัดการปุ่มคลิกคัดลอก (Copy to Clipboard)
    var copyButtons = document.querySelectorAll('.wpsu-btn-copy');

    copyButtons.forEach(function (button) {
        button.addEventListener('click', function (e) {
            e.preventDefault();
            var urlToCopy = button.getAttribute('data-url');
            if (!urlToCopy) {
                var input = button.closest('.wpsu-copy-group').querySelector('.wpsu-input');
                if (input) {
                    urlToCopy = input.value;
                }
            }

            if (!urlToCopy) return;

            copyToClipboard(urlToCopy, function (success) {
                if (success) {
                    var defaultSpan = button.querySelector('.wpsu-btn-copy-default');
                    var successSpan = button.querySelector('.wpsu-btn-copy-success');

                    button.classList.add('wpsu-copied');
                    if (defaultSpan) defaultSpan.style.display = 'none';
                    if (successSpan) successSpan.style.display = 'inline-flex';

                    setTimeout(function () {
                        button.classList.remove('wpsu-copied');
                        if (defaultSpan) defaultSpan.style.display = 'inline-flex';
                        if (successSpan) successSpan.style.display = 'none';
                    }, 2500);
                } else {
                    alert('กรุณาคัดลอกลิงก์ด้วยตนเอง: ' + urlToCopy);
                }
            });
        });
    });

    // 2. จัดการคลิกที่ช่อง input เพื่อเลือกข้อความทั้งหมดอัตโนมัติ (Select All)
    var inputs = document.querySelectorAll('.wpsu-input');
    inputs.forEach(function (input) {
        input.addEventListener('click', function () {
            this.select();
        });
    });

    // 3. จัดการ Popup QR Code
    var qrButtons = document.querySelectorAll('.wpsu-share-qr');
    qrButtons.forEach(function (qrBtn) {
        qrBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var card = qrBtn.closest('.wpsu-card');
            if (!card) return;

            var modal = card.querySelector('.wpsu-qr-modal');
            var img = modal.querySelector('.wpsu-qr-img');
            var url = qrBtn.getAttribute('data-qr-url');

            if (modal && img && url) {
                // สร้าง QR Code จาก Quickchart API / Google Chart API ที่เชื่อถือได้และเร็ว
                img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + encodeURIComponent(url);
                modal.style.display = 'flex';
            }
        });
    });

    // ปิด QR Modal เมื่อคลิกปุ่มปิด หรือคลิกด้านนอก
    document.addEventListener('click', function (e) {
        if (e.target.matches('.wpsu-qr-close') || e.target.classList.contains('wpsu-qr-modal')) {
            var openModals = document.querySelectorAll('.wpsu-qr-modal');
            openModals.forEach(function (m) {
                m.style.display = 'none';
            });
        }
    });

    // ปิดเมื่อกด Esc
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var openModals = document.querySelectorAll('.wpsu-qr-modal');
            openModals.forEach(function (m) {
                m.style.display = 'none';
            });
        }
    });

    /**
     * Helper สำหรับคัดลอกข้อความ รองรับทั้ง modern navigator.clipboard และ fallback
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
