<?php
/**
 * Support Septian — Shared Footer, Toast Container & Confirmation Modal
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

$extraScripts = $extraScripts ?? [];
?>
        </main>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer" aria-live="polite" aria-atomic="true"></div>

<!-- Universal Confirmation Modal (wajib sebelum kirim email / hapus permanen) -->
<div class="modal-backdrop" id="confirmModal" hidden>
    <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="confirmModalTitle">
        <div class="modal-header">
            <h3 id="confirmModalTitle">Konfirmasi Tindakan</h3>
            <button type="button" class="icon-btn" id="confirmModalClose" aria-label="Tutup">&times;</button>
        </div>
        <div class="modal-body" id="confirmModalBody"></div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="confirmModalCancel">Batal</button>
            <button type="button" class="btn btn-primary" id="confirmModalOk">Ya, Lanjutkan</button>
        </div>
    </div>
</div>

<script src="<?= e(base_url('assets/js/app.js')) ?>"></script>
<?php foreach ($extraScripts as $script): ?>
    <script src="<?= e(base_url('assets/js/' . $script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
