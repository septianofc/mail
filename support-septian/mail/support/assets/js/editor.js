/**
 * Support Septian — Compose, Reply, Reply All & Forward Editor Controller
 * Path: /mail/support/assets/js/editor.js
 */

(function () {
    'use strict';

    const App = window.SupportApp;
    if (!App) return;

    document.addEventListener('DOMContentLoaded', () => {
        initThreadActions();
        initEmailEditor();
    });

    function initThreadActions() {
        const bar = document.getElementById('threadActionBar');
        if (!bar) return;

        const messageId = bar.dataset.messageId;
        bar.querySelectorAll('[data-single-action]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const action = btn.dataset.singleAction;
                if (action === 'trash') {
                    const ok = await App.confirmModal({
                        title: 'Pindahkan ke Sampah',
                        body: 'Pindahkan pesan ini ke folder Sampah Gmail?',
                        okText: 'Pindahkan ke Sampah',
                        danger: true
                    });
                    if (!ok) return;
                }

                try {
                    const res = await App.apiPost('api/actions.php', { action, ids: [messageId] });
                    App.showToast(res.message || 'Aksi berhasil.', 'success');
                    if (['trash', 'archive', 'spam'].includes(action)) {
                        setTimeout(() => { window.location.href = App.buildUrl('inbox.php'); }, 650);
                    } else if (action === 'star' || action === 'unstar') {
                        btn.dataset.singleAction = action === 'star' ? 'unstar' : 'star';
                        btn.textContent = action === 'star' ? 'Hapus Bintang' : 'Beri Bintang';
                    }
                } catch (err) {
                    App.showToast(err.message, 'error');
                }
            });
        });
    }

    function initEmailEditor() {
        const form = document.getElementById('emailEditorForm');
        const richEditor = document.getElementById('richEditor');
        const hiddenBody = document.getElementById('bodyHtmlHidden');
        const tplSelect  = document.getElementById('templateSelector');
        const toInput    = document.getElementById('editorTo');
        const ccInput    = document.getElementById('editorCc');
        const subjInput  = document.getElementById('editorSubject');
        const recNameEl  = document.getElementById('recipientNameInput');
        const attInput   = document.getElementById('editorAttachments');
        const attPreview = document.getElementById('attachmentPreviewList');
        const btnSend    = document.getElementById('btnSendEmail');
        const btnDraft   = document.getElementById('btnSaveDraft');
        const btnPreview = document.getElementById('btnPreviewEmail');

        if (!form || !richEditor) return;

        function applyTemplateOption(option, updateSubject = false) {
            if (!option || !option.value) return;
            const rawBody = option.dataset.body || '';
            const rawSubj = option.dataset.subject || '';
            const recName = (recNameEl && recNameEl.value.trim()) || form.dataset.replyName || 'Pelanggan';
            const origSubj = form.dataset.origSubject || (subjInput ? subjInput.value : '');

            const hydrated = rawBody
                .replaceAll('{nama_penerima}', App.escapeHtml(recName))
                .replaceAll('{subjek}', App.escapeHtml(origSubj))
                .replaceAll('{nama_pengirim}', App.escapeHtml(form.dataset.adminName || 'Support Septian F. R.'))
                .replaceAll('{nama_perusahaan}', 'Support Septian F. R.')
                .replaceAll('{website}', 'https://septianfr.web.id')
                .replaceAll('{email_support}', 'support.septianfr@gmail.com')
                .replaceAll('{isi_pesan}', 'Tulis pesan Anda di sini...');

            richEditor.innerHTML = hydrated;

            if (updateSubject && subjInput && rawSubj) {
                if (form.dataset.mode === 'compose' && subjInput.value.trim() !== '') {
                    return;
                }
                const resolvedSubj = rawSubj.replaceAll('{subjek}', origSubj.replace(/^(re|fwd):\s*/i, ''));
                if (resolvedSubj.trim() !== '') {
                    subjInput.value = resolvedSubj;
                }
            }
        }

        function loadDefaultTemplateForMode(mode) {
            if (!tplSelect) return;
            const options = Array.from(tplSelect.options);
            const match = options.find((o) => o.dataset.category === mode && o.dataset.default === '1')
                || options.find((o) => o.dataset.category === mode);

            if (match) {
                tplSelect.value = match.value;
                applyTemplateOption(match, false);
            }
        }

        // Muat template default otomatis saat halaman dibuka
        loadDefaultTemplateForMode(form.dataset.mode || 'compose');

        if (tplSelect) {
            tplSelect.addEventListener('change', () => {
                const selected = tplSelect.options[tplSelect.selectedIndex];
                applyTemplateOption(selected, form.dataset.mode !== 'compose');
            });
        }

        // Reply / Reply All / Forward mode tabs pada view-email.php
        document.querySelectorAll('[data-reply-mode]').forEach((tab) => {
            tab.addEventListener('click', () => {
                const mode = tab.dataset.replyMode;
                document.querySelectorAll('[data-reply-mode]').forEach((t) => t.classList.remove('is-active'));
                tab.classList.add('is-active');

                form.dataset.mode = mode;
                const origSubj = (form.dataset.origSubject || '').replace(/^(re|fwd):\s*/i, '');

                if (mode === 'reply') {
                    form.dataset.endpoint = App.buildUrl('api/reply.php');
                    if (toInput) toInput.value = form.dataset.replyTo || '';
                    if (ccInput) ccInput.value = '';
                    if (subjInput) subjInput.value = 'Re: ' + origSubj;
                    if (btnSend) btnSend.textContent = 'Kirim Balasan';
                } else if (mode === 'reply_all') {
                    form.dataset.endpoint = App.buildUrl('api/reply-all.php');
                    if (toInput) toInput.value = form.dataset.replyAllTo || form.dataset.replyTo || '';
                    if (ccInput) ccInput.value = form.dataset.replyAllCc || '';
                    if (subjInput) subjInput.value = 'Re: ' + origSubj;
                    if (btnSend) btnSend.textContent = 'Kirim Balas Semua';
                } else if (mode === 'forward') {
                    form.dataset.endpoint = App.buildUrl('api/forward.php');
                    if (toInput) { toInput.value = ''; toInput.focus(); }
                    if (ccInput) ccInput.value = '';
                    if (subjInput) subjInput.value = 'Fwd: ' + origSubj;
                    if (btnSend) btnSend.textContent = 'Teruskan Email';
                }

                loadDefaultTemplateForMode(mode);
            });
        });

        // Rich Text Formatting Buttons
        document.querySelectorAll('.tool-btn[data-cmd]').forEach((btn) => {
            btn.addEventListener('click', () => {
                richEditor.focus();
                document.execCommand(btn.dataset.cmd, false, null);
            });
        });

        const btnLink = document.getElementById('btnInsertLink');
        if (btnLink) {
            btnLink.addEventListener('click', () => {
                const url = prompt('Masukkan URL tautan (harus diawali https:// atau http://):', 'https://septianfr.web.id');
                if (url && /^https?:\/\//i.test(url.trim())) {
                    richEditor.focus();
                    document.execCommand('createLink', false, url.trim());
                } else if (url) {
                    App.showToast('URL harus diawali https:// atau http://', 'warning');
                }
            });
        }

        // Dynamic Variable Quick Insert
        document.querySelectorAll('[data-insert-var]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const token = btn.dataset.insertVar;
                richEditor.focus();
                document.execCommand('insertText', false, token);
            });
        });

        // Attachment File Preview
        if (attInput && attPreview) {
            attInput.addEventListener('change', () => {
                const files = Array.from(attInput.files || []);
                if (!files.length) {
                    attPreview.innerHTML = '';
                    return;
                }
                attPreview.innerHTML = files.map((f) => {
                    const kb = (f.size / 1024).toFixed(1);
                    return `<span>${App.escapeHtml(f.name)} (${kb} KB)</span>`;
                }).join(' &middot; ');
            });
        }

        // Live Preview Email HTML
        const previewModal = document.getElementById('emailPreviewModal');
        const previewFrame = document.getElementById('emailPreviewFrame');
        const closePreview = () => { if (previewModal) previewModal.hidden = true; };

        const closeBtn1 = document.getElementById('closePreviewModal');
        const closeBtn2 = document.getElementById('closePreviewModalBtn');
        if (closeBtn1) closeBtn1.addEventListener('click', closePreview);
        if (closeBtn2) closeBtn2.addEventListener('click', closePreview);

        if (btnPreview && previewModal && previewFrame) {
            btnPreview.addEventListener('click', async () => {
                try {
                    const res = await App.apiPost('api/templates.php', {
                        action: 'preview',
                        subject: subjInput ? subjInput.value : 'Preview Email',
                        body_html: richEditor.innerHTML,
                        nama_penerima: (recNameEl && recNameEl.value.trim()) || 'Pelanggan',
                        email_penerima: toInput ? toInput.value.trim() : ''
                    });
                    previewFrame.srcdoc = res.html;
                    previewModal.hidden = false;
                } catch (err) {
                    App.showToast(err.message, 'error');
                }
            });
        }

        // Save Draft to Gmail
        if (btnDraft) {
            btnDraft.addEventListener('click', async () => {
                hiddenBody.value = richEditor.innerHTML;
                const fd = new FormData(form);
                btnDraft.disabled = true;
                try {
                    const res = await App.apiPostForm('api/drafts.php', fd);
                    const draftInput = document.getElementById('draftIdInput');
                    if (draftInput && res.draft && res.draft.draft_id) {
                        draftInput.value = res.draft.draft_id;
                    }
                    App.showToast(res.message || 'Draft disimpan ke Gmail.', 'success');
                } catch (err) {
                    App.showToast(err.message, 'error');
                } finally {
                    btnDraft.disabled = false;
                }
            });
        }

        // Submit Send Email (WAJIB Konfirmasi Admin)
        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            hiddenBody.value = richEditor.innerHTML;

            const toVal = toInput ? toInput.value.trim() : '';
            const subjVal = subjInput ? subjInput.value.trim() : '';

            if (!toVal || !subjVal) {
                App.showToast('Penerima (Kepada) dan Subjek email wajib diisi.', 'warning');
                return;
            }

            const confirmed = await App.confirmModal({
                title: 'Konfirmasi Pengiriman Email',
                body: `
                    <p style="margin:0 0 8px 0;">Pastikan informasi email berikut sudah benar sebelum dikirim melalui akun resmi <strong>support.septianfr@gmail.com</strong>:</p>
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;font-size:13px;">
                        <div><strong>Kepada:</strong> ${App.escapeHtml(toVal)}</div>
                        ${ccInput && ccInput.value.trim() ? '<div><strong>Cc:</strong> ' + App.escapeHtml(ccInput.value.trim()) + '</div>' : ''}
                        <div><strong>Subjek:</strong> ${App.escapeHtml(subjVal)}</div>
                    </div>
                `,
                okText: 'Ya, Kirim Sekarang'
            });

            if (!confirmed) return;

            const fd = new FormData(form);
            fd.set('confirmed', '1');

            const endpoint = form.dataset.endpoint || App.buildUrl('api/send.php');
            const relativeEndpoint = endpoint.replace(App.BASE_PATH + '/', '');

            if (btnSend) {
                btnSend.disabled = true;
                btnSend.dataset.origText = btnSend.textContent;
                btnSend.textContent = 'Mengirim...';
            }

            try {
                const res = await App.apiPostForm(relativeEndpoint, fd);
                App.showToast(res.message || 'Email berhasil dikirim!', 'success', 5000);
                setTimeout(() => {
                    window.location.href = App.buildUrl('sent.php');
                }, 900);
            } catch (err) {
                App.showToast(err.message, 'error', 6000);
            } finally {
                if (btnSend) {
                    btnSend.disabled = false;
                    btnSend.textContent = btnSend.dataset.origText || 'Kirim Email';
                }
            }
        });
    }
})();
