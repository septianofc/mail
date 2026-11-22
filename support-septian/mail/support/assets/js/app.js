/**
 * Support Septian — Core Frontend Utilities (Vanilla JavaScript)
 * Path: /mail/support/assets/js/app.js
 */

(function () {
    'use strict';

    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const baseMeta = document.querySelector('meta[name="base-path"]');

    const BASE_PATH = baseMeta ? baseMeta.getAttribute('content').replace(/\/$/, '') : '/mail/support';
    const CSRF_TOKEN = csrfMeta ? csrfMeta.getAttribute('content') : '';

    function buildUrl(relativePath) {
        const clean = String(relativePath || '').replace(/^\/+/, '');
        return BASE_PATH + '/' + clean;
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = String(str ?? '');
        return div.innerHTML;
    }

    function showToast(message, type = 'info', duration = 4200) {
        const container = document.getElementById('toastContainer');
        if (!container) return;

        const el = document.createElement('div');
        el.className = 'toast toast-' + type;
        el.textContent = message;
        container.appendChild(el);

        setTimeout(() => {
            el.style.opacity = '0';
            el.style.transition = 'opacity 0.2s ease';
            setTimeout(() => el.remove(), 220);
        }, duration);
    }

    function confirmModal({ title = 'Konfirmasi', body = 'Apakah Anda yakin?', okText = 'Ya, Lanjutkan', danger = false }) {
        return new Promise((resolve) => {
            const modal  = document.getElementById('confirmModal');
            const titleEl= document.getElementById('confirmModalTitle');
            const bodyEl = document.getElementById('confirmModalBody');
            const okBtn  = document.getElementById('confirmModalOk');
            const cancelBtn = document.getElementById('confirmModalCancel');
            const closeBtn  = document.getElementById('confirmModalClose');

            if (!modal) {
                resolve(true);
                return;
            }

            titleEl.textContent = title;
            bodyEl.innerHTML = body;
            okBtn.textContent = okText;
            okBtn.className = danger ? 'btn btn-danger-outline' : 'btn btn-primary';
            modal.hidden = false;

            const cleanup = (result) => {
                modal.hidden = true;
                okBtn.removeEventListener('click', onOk);
                cancelBtn.removeEventListener('click', onCancel);
                closeBtn.removeEventListener('click', onCancel);
                resolve(result);
            };

            const onOk = () => cleanup(true);
            const onCancel = () => cleanup(false);

            okBtn.addEventListener('click', onOk);
            cancelBtn.addEventListener('click', onCancel);
            closeBtn.addEventListener('click', onCancel);
        });
    }

    async function apiGet(relativePath) {
        const res = await fetch(buildUrl(relativePath), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        const data = await res.json().catch(() => ({ ok: false, message: 'Respons server tidak valid.' }));
        if (!res.ok && data.ok !== true) {
            throw new Error(data.message || 'Permintaan gagal (' + res.status + ')');
        }
        return data;
    }

    async function apiPost(relativePath, payload) {
        const res = await fetch(buildUrl(relativePath), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload || {})
        });
        const data = await res.json().catch(() => ({ ok: false, message: 'Respons server tidak valid.' }));
        if (!res.ok && data.ok !== true) {
            throw new Error(data.message || 'Permintaan gagal (' + res.status + ')');
        }
        return data;
    }

    async function apiPostForm(relativePath, formData) {
        if (!formData.has('csrf_token')) {
            formData.append('csrf_token', CSRF_TOKEN);
        }
        const res = await fetch(buildUrl(relativePath), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        });
        const data = await res.json().catch(() => ({ ok: false, message: 'Respons server tidak valid.' }));
        if (!res.ok && data.ok !== true) {
            throw new Error(data.message || 'Permintaan gagal (' + res.status + ')');
        }
        return data;
    }

    // Mobile Sidebar Drawer
    document.addEventListener('DOMContentLoaded', () => {
        const menuBtn  = document.getElementById('mobileMenuBtn');
        const sidebar  = document.getElementById('appSidebar');
        const backdrop = document.getElementById('sidebarBackdrop');

        const closeDrawer = () => {
            if (sidebar) sidebar.classList.remove('is-open');
            if (backdrop) backdrop.classList.remove('is-open');
        };

        if (menuBtn && sidebar && backdrop) {
            menuBtn.addEventListener('click', () => {
                sidebar.classList.toggle('is-open');
                backdrop.classList.toggle('is-open');
            });
            backdrop.addEventListener('click', closeDrawer);
        }
    });

    window.SupportApp = {
        BASE_PATH,
        CSRF_TOKEN,
        buildUrl,
        escapeHtml,
        showToast,
        confirmModal,
        apiGet,
        apiPost,
        apiPostForm
    };
})();
