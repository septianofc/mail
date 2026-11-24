/**
 * Skyzz Mail — Dashboard Stats & Mailbox Folder Controller with Fast Client Caching (Vanilla JS)
 * Path: /mail/support/assets/js/inbox.js
 */

(function () {
    'use strict';

    const App = window.SupportApp;
    if (!App) return;

    const CACHE_PREFIX = App.CACHE_PREFIX || 'skyzz_mail_cache_';
    const FOLDER_TTL_MS = 45 * 1000;
    const STATS_TTL_MS = 60 * 1000;

    function cacheRead(key) {
        try {
            const raw = sessionStorage.getItem(CACHE_PREFIX + key);
            if (!raw) return null;
            const parsed = JSON.parse(raw);
            if (!parsed || typeof parsed.exp !== 'number' || parsed.exp < Date.now()) {
                sessionStorage.removeItem(CACHE_PREFIX + key);
                return null;
            }
            return parsed.data;
        } catch (e) {
            return null;
        }
    }

    function cacheWrite(key, data, ttlMs) {
        try {
            sessionStorage.setItem(CACHE_PREFIX + key, JSON.stringify({
                exp: Date.now() + ttlMs,
                data: data
            }));
        } catch (e) {
            // Abaikan jika kuota sessionStorage penuh
        }
    }

    function setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = String(value ?? '0');
    }

    function renderDashboardStats(stats) {
        if (!stats) return;
        setText('statInboxTotal', stats.inbox_total);
        setText('statInboxUnread', stats.inbox_unread);
        setText('statSentTotal', stats.sent_total);
        setText('statDraftsTotal', stats.drafts_total);
        setText('statStarredTotal', stats.starred_total);

        const sidebarBadge = document.getElementById('sidebarInboxUnread');
        if (sidebarBadge && stats.inbox_unread > 0) {
            sidebarBadge.textContent = String(stats.inbox_unread);
        }
    }

    function renderDashboardRecent(recentBox, list) {
        if (!recentBox) return;
        if (!list || list.length === 0) {
            recentBox.innerHTML = '<div class="empty-state"><p class="muted">Kotak masuk kosong.</p></div>';
            return;
        }
        recentBox.innerHTML = list.map((m) => `
            <a href="${App.buildUrl('view-email.php?id=' + encodeURIComponent(m.id) + '&folder=inbox')}"
               class="mail-row-compact ${m.is_unread ? 'is-unread' : ''}">
                <div class="mail-row-compact-main">
                    <div class="mail-row-compact-top">
                        <span class="mail-row-compact-sender">${App.escapeHtml(m.from_name)}</span>
                        <span class="mail-row-compact-subject">${App.escapeHtml(m.subject)}</span>
                    </div>
                    <div class="mail-row-compact-snippet">${App.escapeHtml(m.snippet || m.from_email)}</div>
                </div>
                <span class="mail-date tabular-nums">${App.escapeHtml(m.date_short)}</span>
            </a>
        `).join('');
    }

    // ------------------------------------------------------------------------
    // 1. Dashboard Controller (Stale-While-Revalidate)
    // ------------------------------------------------------------------------
    async function loadDashboardData(forceRefresh = false) {
        const statsGrid = document.getElementById('dashboardStatsGrid');
        const recentBox = document.getElementById('dashboardRecentEmails');
        if (!statsGrid || statsGrid.dataset.connected !== '1') return;

        const mbId = statsGrid.dataset.mailboxId || '0';
        const statsKey = `dash_stats_mb_${mbId}`;
        const recentKey = `dash_recent_mb_${mbId}`;

        if (!forceRefresh) {
            const cachedStats = cacheRead(statsKey);
            const cachedRecent = cacheRead(recentKey);
            if (cachedStats) renderDashboardStats(cachedStats);
            if (cachedRecent) renderDashboardRecent(recentBox, cachedRecent);
            if (cachedStats && cachedRecent) {
                return;
            }
        }

        const qs = forceRefresh ? '&force=1' : '';
        try {
            const [statsRes, inboxRes] = await Promise.all([
                App.apiGet('api/messages.php?stats=1' + qs),
                App.apiGet('api/messages.php?folder=inbox&limit=8' + qs)
            ]);

            if (statsRes.ok && statsRes.stats) {
                cacheWrite(statsKey, statsRes.stats, STATS_TTL_MS);
                renderDashboardStats(statsRes.stats);
            }

            if (recentBox) {
                const list = inboxRes.messages || [];
                cacheWrite(recentKey, list, FOLDER_TTL_MS);
                renderDashboardRecent(recentBox, list);
            }
        } catch (err) {
            if (recentBox) {
                recentBox.innerHTML = `<div class="alert alert-danger"><span>${App.escapeHtml(err.message)}</span></div>`;
            }
            App.showToast(err.message, 'error');
        }
    }

    // ------------------------------------------------------------------------
    // 2. Mailbox Folder Controller (Inbox, Sent, Drafts, Starred, Spam, Trash)
    // ------------------------------------------------------------------------
    function initMailbox() {
        const container = document.getElementById('mailboxContainer');
        if (!container) return;

        const folder    = container.dataset.folder || 'inbox';
        const query     = container.dataset.query || '';
        const connected = container.dataset.connected === '1';
        const mbId      = container.dataset.mailboxId || '0';

        const listEl      = document.getElementById('mailboxList');
        const metaEl      = document.getElementById('mailboxMetaInfo');
        const selectAllEl = document.getElementById('selectAllMails');
        const btnPrev     = document.getElementById('btnPrevPage');
        const btnNext     = document.getElementById('btnNextPage');
        const btnRefresh  = document.getElementById('btnRefreshFolder');

        let tokenHistory = [''];
        let currentIndex = 0;
        let nextPageToken = null;

        if (!connected) {
            if (metaEl) metaEl.textContent = 'Belum terhubung';
            return;
        }

        function applyFolderResponse(res) {
            const msgs = res.messages || [];
            nextPageToken = res.next_page_token || null;

            if (metaEl) {
                metaEl.textContent = `Halaman ${currentIndex + 1} · ${msgs.length} pesan ditampilkan`;
            }
            if (btnPrev) btnPrev.disabled = currentIndex === 0;
            if (btnNext) btnNext.disabled = !nextPageToken;

            renderMessages(msgs);
        }

        async function fetchPage(pageToken, forceRefresh = false) {
            if (selectAllEl) selectAllEl.checked = false;

            const cacheKey = `folder_mb_${mbId}_${folder}_${query}_${pageToken || 'p1'}`;
            if (!forceRefresh) {
                const cached = cacheRead(cacheKey);
                if (cached) {
                    applyFolderResponse(cached);
                    return;
                }
            }

            if (listEl) {
                listEl.innerHTML = '<div class="loading-rows">' + '<div class="skeleton-row"></div>'.repeat(6) + '</div>';
            }

            const forceParam = forceRefresh ? '&force=1' : '';
            const endpoint = query !== ''
                ? `api/search.php?q=${encodeURIComponent(query)}&folder=${encodeURIComponent(folder)}${pageToken ? '&pageToken=' + encodeURIComponent(pageToken) : ''}${forceParam}`
                : `api/messages.php?folder=${encodeURIComponent(folder)}${pageToken ? '&pageToken=' + encodeURIComponent(pageToken) : ''}${forceParam}`;

            try {
                const res = await App.apiGet(endpoint);
                cacheWrite(cacheKey, res, FOLDER_TTL_MS);
                applyFolderResponse(res);
            } catch (err) {
                if (listEl) {
                    listEl.innerHTML = `<div class="empty-state"><p class="text-danger"><strong>Gagal memuat email</strong></p><p class="muted">${App.escapeHtml(err.message)}</p></div>`;
                }
                App.showToast(err.message, 'error');
            }
        }

        function renderMessages(messages) {
            if (!listEl) return;
            if (!messages.length) {
                listEl.innerHTML = '<div class="empty-state"><p><strong>Tidak ada email di folder ini.</strong></p><p class="muted">Semua pesan yang cocok akan muncul di sini.</p></div>';
                return;
            }

            listEl.innerHTML = messages.map((m) => {
                const targetUrl = folder === 'drafts' && m.draft_id
                    ? App.buildUrl(`compose.php?draft_id=${encodeURIComponent(m.draft_id)}&to=${encodeURIComponent(m.to)}&subject=${encodeURIComponent(m.subject)}`)
                    : App.buildUrl(`view-email.php?id=${encodeURIComponent(m.id)}&folder=${encodeURIComponent(folder)}`);

                const senderDisplay = folder === 'sent'
                    ? 'Kepada: ' + (m.to || m.from_name)
                    : m.from_name;

                return `
                    <div class="mail-row ${m.is_unread ? 'is-unread' : ''}" data-id="${App.escapeHtml(m.id)}" data-href="${targetUrl}">
                        <input type="checkbox" class="mail-select-cb" value="${App.escapeHtml(m.id)}" aria-label="Pilih email">
                        <button type="button" class="mail-star-btn ${m.is_starred ? 'is-starred' : ''}" data-star-id="${App.escapeHtml(m.id)}" data-starred="${m.is_starred ? '1' : '0'}" title="Bintang">${m.is_starred ? '★' : '☆'}</button>
                        <div class="mail-sender">${App.escapeHtml(senderDisplay)}</div>
                        <div class="mail-content-col">
                            <span class="mail-subject">${App.escapeHtml(m.subject)}</span>
                            <span class="mail-snippet">${m.snippet ? '— ' + App.escapeHtml(m.snippet) : ''}</span>
                        </div>
                        <div class="mail-date tabular-nums">${App.escapeHtml(m.date_short)}</div>
                    </div>
                `;
            }).join('');

            // Row click opens email detail
            listEl.querySelectorAll('.mail-row').forEach((row) => {
                row.addEventListener('click', (ev) => {
                    if (ev.target.closest('input[type="checkbox"]') || ev.target.closest('.mail-star-btn')) {
                        return;
                    }
                    window.location.href = row.dataset.href;
                });
            });

            // Inline star toggle
            listEl.querySelectorAll('.mail-star-btn').forEach((btn) => {
                btn.addEventListener('click', async (ev) => {
                    ev.stopPropagation();
                    const id = btn.dataset.starId;
                    const currentlyStarred = btn.dataset.starred === '1';
                    const action = currentlyStarred ? 'unstar' : 'star';
                    btn.disabled = true;
                    try {
                        await App.apiPost('api/actions.php', { action, ids: [id] });
                        btn.dataset.starred = currentlyStarred ? '0' : '1';
                        btn.classList.toggle('is-starred', !currentlyStarred);
                        btn.textContent = currentlyStarred ? '☆' : '★';
                        App.clearClientCache();
                    } catch (err) {
                        App.showToast(err.message, 'error');
                    } finally {
                        btn.disabled = false;
                    }
                });
            });
        }

        function getSelectedIds() {
            return Array.from(document.querySelectorAll('.mail-select-cb:checked')).map((cb) => cb.value);
        }

        if (selectAllEl) {
            selectAllEl.addEventListener('change', () => {
                document.querySelectorAll('.mail-select-cb').forEach((cb) => {
                    cb.checked = selectAllEl.checked;
                });
            });
        }

        // Bulk actions toolbar
        document.querySelectorAll('[data-bulk-action]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const action = btn.dataset.bulkAction;
                const ids = getSelectedIds();
                if (!ids.length) {
                    App.showToast('Pilih minimal satu email terlebih dahulu.', 'warning');
                    return;
                }

                if (action === 'delete') {
                    const ok = await App.confirmModal({
                        title: 'Hapus Permanen Email',
                        body: `Anda akan menghapus permanen <strong>${ids.length} email</strong>. Tindakan ini tidak dapat dibatalkan.`,
                        okText: 'Hapus Permanen',
                        danger: true
                    });
                    if (!ok) return;
                }

                try {
                    const res = await App.apiPost('api/actions.php', { action, ids });
                    App.clearClientCache();
                    App.showToast(res.message || 'Aksi berhasil.', 'success');
                    fetchPage(tokenHistory[currentIndex], true);
                } catch (err) {
                    App.showToast(err.message, 'error');
                }
            });
        });

        if (btnNext) {
            btnNext.addEventListener('click', () => {
                if (!nextPageToken) return;
                currentIndex++;
                tokenHistory[currentIndex] = nextPageToken;
                fetchPage(nextPageToken, false);
            });
        }

        if (btnPrev) {
            btnPrev.addEventListener('click', () => {
                if (currentIndex <= 0) return;
                currentIndex--;
                fetchPage(tokenHistory[currentIndex], false);
            });
        }

        if (btnRefresh) {
            btnRefresh.addEventListener('click', () => {
                tokenHistory = [''];
                currentIndex = 0;
                App.clearClientCache();
                fetchPage('', true);
                App.showToast('Menyegarkan daftar email langsung dari server...', 'info', 2000);
            });
        }

        fetchPage('', false);
    }

    document.addEventListener('DOMContentLoaded', () => {
        loadDashboardData(false);
        initMailbox();

        const btnDashRefresh = document.getElementById('btnRefreshDashboard');
        if (btnDashRefresh) {
            btnDashRefresh.addEventListener('click', () => {
                App.clearClientCache();
                loadDashboardData(true);
                App.showToast('Menyegarkan data email terbaru...', 'info', 2000);
            });
        }
    });
})();
