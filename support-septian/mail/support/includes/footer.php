<?php
/**
 * Skyzz Mail — Shared Footer, Legal Bar, Account Deletion Access (Desktop & Android Mini) & Confirmation Modal
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

$extraScripts = $extraScripts ?? [];
$footerAppName = get_setting('app_name', DEFAULT_APP_NAME);
?>
            <footer class="app-legal-footer">
                <div class="legal-footer-links">
                    <a href="<?= e(base_url('legal.php?doc=privacy')) ?>">Privacy Policy</a>
                    <a href="<?= e(base_url('legal.php?doc=terms')) ?>">Terms &amp; Conditions</a>
                    <a href="<?= e(base_url('legal.php?doc=disclaimer')) ?>">Disclaimer</a>
                    <a href="<?= e(base_url('legal.php?doc=refund')) ?>">Refund Policy</a>
                    <a href="<?= e(base_url('legal.php?doc=aup')) ?>">Acceptable Use</a>
                    <a href="<?= e(base_url('legal.php?doc=data-safety')) ?>">Data Safety</a>
                    <a href="<?= e(base_url('legal.php?doc=account-deletion')) ?>">Account Deletion</a>
                    <a href="<?= e(base_url('legal.php?doc=contact')) ?>">Contact &amp; Support</a>
                    <a href="<?= e(base_url('delete-account.php')) ?>" class="legal-link-danger">Hapus Akun</a>
                </div>
                <div class="legal-footer-copy">&copy; <?= date('Y') ?> <?= e($footerAppName) ?></div>
            </footer>
        </main>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer" aria-live="polite" aria-atomic="true"></div>

<!-- Universal Confirmation Modal -->
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
