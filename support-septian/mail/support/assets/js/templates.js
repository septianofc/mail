/**
 * Support Septian — Template Management & Live Preview Controller
 * Path: /mail/support/assets/js/templates.js
 */

(function () {
    'use strict';

    const App = window.SupportApp;
    if (!App) return;

    document.addEventListener('DOMContentLoaded', () => {
        initTemplateManager();
        initStandalonePreview();
    });

    function initTemplateManager() {
        const form = document.getElementById('templateForm');
        if (!form) return;

        const headingEl = document.getElementById('templateFormHeading');
        const idEl      = document.getElementById('tplId');
        const actionEl  = document.getElementById('tplAction');
        const nameEl    = document.getElementById('tplName');
        const catEl     = document.getElementById('tplCategory');
        const subjEl    = document.getElementById('tplSubject');
        const descEl    = document.getElementById('tplDescription');
        const bodyEl    = document.getElementById('tplBodyHtml');
        const defEl     = document.getElementById('tplIsDefault');
        const btnNew    = document.getElementById('btnNewTemplate');
        const btnReset  = document.getElementById('btnResetTemplateForm');
        const btnPrev   = document.getElementById('btnLivePreviewTemplate');

        function resetForm() {
            idEl.value = '';
            actionEl.value = 'create';
            headingEl.textContent = 'Buat Template Baru';
            nameEl.value = '';
            catEl.value = 'reply';
            subjEl.value = 'Re: {subjek}';
            descEl.value = '';
            bodyEl.value = '<p>Halo <strong>{nama_penerima}</strong>,</p>\n<p>Terima kasih telah menghubungi <strong>{nama_perusahaan}</strong>.</p>\n<p>{isi_pesan}</p>\n<p>Apabila masih ada hal lain yang dapat kami bantu, silakan balas email ini.</p>';
            defEl.checked = false;
        }

        if (btnNew) {
            btnNew.addEventListener('click', () => {
                resetForm();
                nameEl.focus();
                document.getElementById('templateEditorCard')?.scrollIntoView({ behavior: 'smooth' });
            });
        }
        if (btnReset) btnReset.addEventListener('click', resetForm);

        // Sisipkan token variabel ke posisi kursor textarea
        document.querySelectorAll('[data-insert-tpl-var]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const token = btn.dataset.insertTplVar;
                const start = bodyEl.selectionStart || bodyEl.value.length;
                const end   = bodyEl.selectionEnd || bodyEl.value.length;
                bodyEl.value = bodyEl.value.substring(0, start) + token + bodyEl.value.substring(end);
                bodyEl.focus();
                bodyEl.setSelectionRange(start + token.length, start + token.length);
            });
        });

        // Edit template
        document.querySelectorAll('[data-edit-template]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const id = btn.dataset.editTemplate;
                try {
                    const res = await App.apiGet('api/templates.php?id=' + encodeURIComponent(id));
                    const t = res.template;
                    idEl.value = t.id;
                    actionEl.value = 'update';
                    headingEl.textContent = 'Edit Template: ' + t.name;
                    nameEl.value = t.name;
                    catEl.value = t.category;
                    subjEl.value = t.subject_template || '';
                    descEl.value = t.description || '';
                    bodyEl.value = t.body_html || '';
                    defEl.checked = Number(t.is_default) === 1;
                    document.getElementById('templateEditorCard')?.scrollIntoView({ behavior: 'smooth' });
                } catch (err) {
                    App.showToast(err.message, 'error');
                }
            });
        });

        // Duplicate template
        document.querySelectorAll('[data-duplicate-template]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                try {
                    const res = await App.apiPost('api/templates.php', {
                        action: 'duplicate',
                        id: btn.dataset.duplicateTemplate
                    });
                    App.showToast(res.message, 'success');
                    setTimeout(() => window.location.reload(), 550);
                } catch (err) {
                    App.showToast(err.message, 'error');
                }
            });
        });

        // Set Default template
        document.querySelectorAll('[data-default-template]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                try {
                    const res = await App.apiPost('api/templates.php', {
                        action: 'set_default',
                        id: btn.dataset.defaultTemplate
                    });
                    App.showToast(res.message, 'success');
                    setTimeout(() => window.location.reload(), 550);
                } catch (err) {
                    App.showToast(err.message, 'error');
                }
            });
        });

        // Delete template
        document.querySelectorAll('[data-delete-template]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const name = btn.dataset.tplName || 'template ini';
                const ok = await App.confirmModal({
                    title: 'Hapus Template Email',
                    body: `Apakah Anda yakin ingin menghapus template <strong>${App.escapeHtml(name)}</strong>?`,
                    okText: 'Hapus Template',
                    danger: true
                });
                if (!ok) return;

                try {
                    const res = await App.apiPost('api/templates.php', {
                        action: 'delete',
                        id: btn.dataset.deleteTemplate
                    });
                    App.showToast(res.message, 'success');
                    setTimeout(() => window.location.reload(), 550);
                } catch (err) {
                    App.showToast(err.message, 'error');
                }
            });
        });

        // Live preview modal
        const modal = document.getElementById('tplPreviewModal');
        const frame = document.getElementById('tplPreviewFrame');
        const closeModal = () => { if (modal) modal.hidden = true; };
        document.getElementById('closeTplPreviewModal')?.addEventListener('click', closeModal);
        document.getElementById('closeTplPreviewBtn')?.addEventListener('click', closeModal);

        if (btnPrev && modal && frame) {
            btnPrev.addEventListener('click', async () => {
                try {
                    const res = await App.apiPost('api/templates.php', {
                        action: 'preview',
                        subject: subjEl.value || 'Preview Support Septian',
                        body_html: bodyEl.value
                    });
                    frame.srcdoc = res.html;
                    modal.hidden = false;
                } catch (err) {
                    App.showToast(err.message, 'error');
                }
            });
        }

        // Submit Create / Update
        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            try {
                const res = await App.apiPost('api/templates.php', {
                    action: actionEl.value,
                    id: idEl.value,
                    name: nameEl.value.trim(),
                    category: catEl.value,
                    subject_template: subjEl.value.trim(),
                    description: descEl.value.trim(),
                    body_html: bodyEl.value,
                    is_default: defEl.checked ? 1 : 0
                });
                App.showToast(res.message || 'Template disimpan.', 'success');
                setTimeout(() => window.location.reload(), 600);
            } catch (err) {
                App.showToast(err.message, 'error');
            }
        });
    }

    function initStandalonePreview() {
        const select = document.getElementById('previewTemplateSelect');
        const frame  = document.getElementById('standalonePreviewFrame');
        const stage  = document.getElementById('previewStage');
        const subjOut= document.getElementById('previewRenderedSubject');
        const btnRef = document.getElementById('btnRefreshPreviewPage');

        if (!select || !frame) return;

        async function updatePreview() {
            const opt = select.options[select.selectedIndex];
            if (!opt) return;

            try {
                const res = await App.apiPost('api/templates.php', {
                    action: 'preview',
                    subject: opt.dataset.subject || document.getElementById('simSubjek').value,
                    body_html: opt.dataset.body || '',
                    nama_penerima: document.getElementById('simNamaPenerima').value,
                    email_penerima: document.getElementById('simEmailPenerima').value,
                    isi_pesan: document.getElementById('simIsiPesan').value
                });
                if (subjOut) subjOut.textContent = res.subject;
                frame.srcdoc = res.html;
            } catch (err) {
                App.showToast(err.message, 'error');
            }
        }

        select.addEventListener('change', updatePreview);
        if (btnRef) btnRef.addEventListener('click', updatePreview);

        document.querySelectorAll('#viewportToggle [data-viewport]').forEach((btn) => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('#viewportToggle [data-viewport]').forEach((b) => b.classList.remove('is-active'));
                btn.classList.add('is-active');
                if (stage) {
                    stage.classList.toggle('is-mobile', btn.dataset.viewport === 'mobile');
                }
            });
        });
    }
})();
