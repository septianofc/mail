import { useMemo, useState } from 'react';
import {
  Check,
  Copy,
  Download,
  FileCode2,
  FolderOpen,
  Mail,
  Monitor,
  Search,
  ShieldCheck,
  Smartphone,
} from 'lucide-react';
import manifestData from './projectManifest.json';

interface ProjectFile {
  path: string;
  name: string;
  ext: string;
  size: number;
  lines: number;
  binary: boolean;
  content: string;
}

interface Manifest {
  zipUrl: string;
  zipSize: number;
  fileCount: number;
  phpCount: number;
  totalLines: number;
  files: ProjectFile[];
}

const manifest = manifestData as Manifest;

interface EmailTemplateSample {
  id: string;
  name: string;
  category: string;
  isDefault: boolean;
  subject: string;
  bodyHtml: string;
}

const BUILTIN_TEMPLATES: EmailTemplateSample[] = [
  {
    id: 'balasan-standar',
    name: 'Balasan Standar',
    category: 'Balas Pesan',
    isDefault: true,
    subject: 'Re: {subjek}',
    bodyHtml:
      '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Terima kasih telah menghubungi <strong>{nama_perusahaan}</strong>.</p><p>{isi_pesan}</p><p>Apabila masih ada pertanyaan atau kendala lain yang dapat kami bantu, silakan balas langsung email ini.</p>',
  },
  {
    id: 'konfirmasi-penerimaan',
    name: 'Konfirmasi Penerimaan Pesan',
    category: 'Balas Pesan',
    isDefault: false,
    subject: 'Re: {subjek} — Pesan Anda Telah Kami Terima',
    bodyHtml:
      '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Kami mengonfirmasi bahwa pesan Anda dengan subjek <strong>&ldquo;{subjek}&rdquo;</strong> telah kami terima dengan baik pada {tanggal}.</p><p>{isi_pesan}</p><p>Tim Support Septian F. R. akan meninjau permintaan Anda dan segera memberikan tindak lanjut.</p>',
  },
  {
    id: 'sedang-diproses',
    name: 'Informasi Sedang Diproses',
    category: 'Balas Pesan',
    isDefault: false,
    subject: 'Re: {subjek} — Permintaan Anda Sedang Diproses',
    bodyHtml:
      '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Kami ingin menginformasikan bahwa laporan atau permintaan Anda terkait <strong>&ldquo;{subjek}&rdquo;</strong> saat ini sedang dalam tahap penanganan oleh tim kami.</p><p>{isi_pesan}</p><p>Kami akan segera menghubungi Anda kembali begitu terdapat pembaruan terbaru. Terima kasih atas kesabaran Anda.</p>',
  },
  {
    id: 'permintaan-info',
    name: 'Permintaan Informasi Tambahan',
    category: 'Balas Pesan',
    isDefault: false,
    subject: 'Re: {subjek} — Mohon Informasi Tambahan',
    bodyHtml:
      '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Terima kasih telah menghubungi <strong>{nama_perusahaan}</strong>. Untuk mempercepat proses pemeriksaan terkait <strong>&ldquo;{subjek}&rdquo;</strong>, kami memerlukan beberapa informasi tambahan berikut:</p><p>{isi_pesan}</p><p>Silakan balas email ini beserta detail yang dibutuhkan agar kami dapat segera melanjutkan penanganan.</p>',
  },
  {
    id: 'penyelesaian-masalah',
    name: 'Penyelesaian Masalah',
    category: 'Balas Semua',
    isDefault: true,
    subject: 'Re: {subjek} — Kendala Telah Diselesaikan',
    bodyHtml:
      '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Kami informasikan bahwa kendala terkait <strong>&ldquo;{subjek}&rdquo;</strong> telah berhasil diselesaikan oleh tim <strong>{nama_perusahaan}</strong>.</p><p>{isi_pesan}</p><p>Mohon lakukan pengecekan kembali di sisi Anda. Jika masih ditemukan kendala, jangan ragu untuk membalas email ini.</p>',
  },
  {
    id: 'ucapan-terima-kasih',
    name: 'Ucapan Terima Kasih',
    category: 'Balas Pesan',
    isDefault: false,
    subject: 'Re: {subjek} — Terima Kasih',
    bodyHtml:
      '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Terima kasih atas kepercayaan dan kerja sama Anda bersama <strong>{nama_perusahaan}</strong>.</p><p>{isi_pesan}</p><p>Kami selalu siap membantu Anda kapan pun dibutuhkan melalui {email_support} atau situs web kami di {website}.</p>',
  },
  {
    id: 'balasan-umum-teruskan',
    name: 'Balasan Umum (Teruskan)',
    category: 'Teruskan Pesan',
    isDefault: true,
    subject: 'Fwd: {subjek}',
    bodyHtml:
      '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Berikut kami teruskan informasi terkait <strong>&ldquo;{subjek}&rdquo;</strong> dari layanan <strong>{nama_perusahaan}</strong> untuk dapat ditindaklanjuti.</p><p>{isi_pesan}</p><p>Apabila membutuhkan informasi lanjutan, silakan hubungi kami melalui {email_support}.</p>',
  },
  {
    id: 'pesan-baru',
    name: 'Pesan Baru',
    category: 'Pesan Baru',
    isDefault: true,
    subject: '{subjek}',
    bodyHtml:
      '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Perkenalkan, kami dari tim resmi <strong>{nama_perusahaan}</strong> menghubungi Anda terkait hal berikut:</p><p>{isi_pesan}</p><p>Jika ada hal yang ingin dikonfirmasi, silakan balas langsung email ini atau kunjungi {website}.</p>',
  },
];

function escapeHtml(value: string): string {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

function buildOfficialEmailHtml(
  tpl: EmailTemplateSample,
  vars: {
    nama_penerima: string;
    email_penerima: string;
    subjek: string;
    nama_pengirim: string;
    isi_pesan: string;
  }
): { subject: string; html: string } {
  const tanggal = new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date()) + ' WIB';

  const map: Record<string, string> = {
    '{nama_penerima}': escapeHtml(vars.nama_penerima),
    '{email_penerima}': escapeHtml(vars.email_penerima),
    '{subjek}': escapeHtml(vars.subjek),
    '{tanggal}': escapeHtml(tanggal),
    '{nama_pengirim}': escapeHtml(vars.nama_pengirim),
    '{isi_pesan}': escapeHtml(vars.isi_pesan).replaceAll('\n', '<br>'),
    '{nama_perusahaan}': 'Support Septian F. R.',
    '{website}': 'https://septianfr.web.id',
    '{email_support}': 'support.septianfr@gmail.com',
  };

  let subject = tpl.subject;
  let body = tpl.bodyHtml;
  for (const [k, v] of Object.entries(map)) {
    subject = subject.replaceAll(k, k === '{subjek}' ? vars.subjek : v);
    body = body.replaceAll(k, v);
  }

  const html = `<!DOCTYPE html>
<html lang="id">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background-color:#f5f7fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:#202124;">
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f5f7fa;padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px;background-color:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
          <tr>
            <td style="padding:26px 30px 18px 30px;border-bottom:1px solid #eceff1;background-color:#ffffff;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                  <td valign="middle">
                    <img src="https://septianfr.web.id/logo.png" onerror="this.style.display='none'" alt="Support Septian" width="38" height="38" style="display:inline-block;vertical-align:middle;border-radius:8px;margin-right:10px;">
                    <span style="display:inline-block;vertical-align:middle;font-size:19px;font-weight:700;color:#111827;">Support Septian</span>
                  </td>
                  <td align="right" valign="middle" style="font-size:12px;color:#5f6368;">Layanan Resmi</td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:28px 30px;font-size:15px;line-height:1.7;color:#202124;">
              <div>${body}</div>
              <div style="margin-top:24px;padding-top:16px;border-top:1px solid #e8eaed;font-size:14px;line-height:1.6;color:#3c4043;">
                <p style="margin:0 0 4px 0;font-weight:600;color:#111827;">Hormat kami,</p>
                <p style="margin:0 0 2px 0;font-weight:600;color:#1a73e8;">Tim Support Septian F. R.</p>
                <p style="margin:0;font-size:13px;color:#5f6368;">Layanan Bantuan Resmi Septian F. R.</p>
              </div>
            </td>
          </tr>
          <tr><td style="padding:0 30px;"><hr style="border:0;border-top:1px solid #e8eaed;margin:0;"></td></tr>
          <tr>
            <td style="padding:22px 30px 26px 30px;background-color:#fafbfc;font-size:12.5px;line-height:1.65;color:#5f6368;">
              <div style="margin-bottom:10px;">
                <strong style="font-size:14px;color:#111827;">Support Septian F. R.</strong>
              </div>
              <div style="margin-bottom:8px;color:#3c4043;">
                Website: <a href="https://septianfr.web.id" target="_blank" style="color:#1a73e8;text-decoration:none;">https://septianfr.web.id</a><br>
                Email: <a href="mailto:support.septianfr@gmail.com" style="color:#1a73e8;text-decoration:none;">support.septianfr@gmail.com</a>
              </div>
              <div style="margin-bottom:10px;color:#5f6368;">
                <strong style="color:#3c4043;">Alamat:</strong><br>
                H4GM+4XX, Jl. PUK Muntur, Ranjeng, Kec. Losarang, Kabupaten Indramayu, Jawa Barat 45253
              </div>
              <div style="padding-top:10px;border-top:1px solid #e8eaed;font-size:11.5px;color:#80868b;">
                Email ini dikirim melalui sistem layanan resmi Support Septian F. R. Mohon tidak membagikan informasi sensitif seperti kata sandi melalui email.
              </div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>`;

  return { subject, html };
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1048576) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / 1048576).toFixed(2)} MB`;
}

type ActiveTab = 'explorer' | 'templates' | 'deploy';
type FileCategory = 'all' | 'pages' | 'api' | 'oauth' | 'includes' | 'assets' | 'docs';

export default function App() {
  const [activeTab, setActiveTab] = useState<ActiveTab>('explorer');
  const [fileFilter, setFileFilter] = useState<FileCategory>('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedPath, setSelectedPath] = useState<string>('mail/support/oauth/callback.php');
  const [copiedId, setCopiedId] = useState<string | null>(null);

  // Template Preview state
  const [selectedTplId, setSelectedTplId] = useState<string>(BUILTIN_TEMPLATES[0].id);
  const [viewport, setViewport] = useState<'desktop' | 'mobile'>('desktop');
  const [simVars, setSimVars] = useState({
    nama_penerima: 'Budi Santoso',
    email_penerima: 'budi.santoso@contoh.id',
    subjek: 'Konfirmasi Status Layanan Website',
    nama_pengirim: 'Septian F. R.',
    isi_pesan:
      'Tim Support Septian F. R. telah menerima pesan Anda dan melakukan pemeriksaan menyeluruh. Seluruh pengaturan akun telah aktif dan dapat digunakan kembali.',
  });

  const filteredFiles = useMemo(() => {
    const q = searchQuery.trim().toLowerCase();
    return manifest.files.filter((file) => {
      if (q && !file.path.toLowerCase().includes(q)) return false;
      if (fileFilter === 'all') return true;
      if (fileFilter === 'api') return file.path.startsWith('mail/support/api/');
      if (fileFilter === 'oauth') return file.path.startsWith('mail/support/oauth/');
      if (fileFilter === 'includes') {
        return file.path.startsWith('mail/support/includes/') || file.path.startsWith('mail/support/templates/');
      }
      if (fileFilter === 'assets') return file.path.startsWith('mail/support/assets/');
      if (fileFilter === 'docs') {
        return (
          file.path.endsWith('.md') ||
          file.path.endsWith('.sql') ||
          file.path.endsWith('.json') ||
          file.name === '.htaccess'
        );
      }
      // pages
      return (
        file.path.startsWith('mail/support/') &&
        file.path.endsWith('.php') &&
        file.path.split('/').length === 3
      );
    });
  }, [fileFilter, searchQuery]);

  const activeFile = useMemo(
    () => manifest.files.find((f) => f.path === selectedPath) ?? manifest.files[0],
    [selectedPath]
  );

  const selectedTemplate = useMemo(
    () => BUILTIN_TEMPLATES.find((t) => t.id === selectedTplId) ?? BUILTIN_TEMPLATES[0],
    [selectedTplId]
  );

  const renderedPreview = useMemo(
    () => buildOfficialEmailHtml(selectedTemplate, simVars),
    [selectedTemplate, simVars]
  );

  const handleCopy = async (text: string, id: string) => {
    try {
      await navigator.clipboard.writeText(text);
      setCopiedId(id);
      setTimeout(() => setCopiedId(null), 2000);
    } catch {
      // Fallback jika clipboard diblokir browser
    }
  };

  return (
    <div className="min-h-screen flex flex-col bg-slate-50 text-slate-900">
      {/* Top Bar — 3 Zones */}
      <header className="sticky top-0 z-30 h-16 bg-white border-b border-slate-200 px-6 flex items-center justify-between gap-8">
        <a
          href="#top"
          onClick={(e) => {
            e.preventDefault();
            setActiveTab('explorer');
          }}
          className="text-lg font-bold tracking-tight text-slate-900 whitespace-nowrap shrink-0"
        >
          Support Septian
        </a>

        <nav className="flex items-center gap-6 text-sm font-medium text-slate-600 overflow-x-auto">
          <button
            type="button"
            onClick={() => setActiveTab('explorer')}
            className={`py-1 transition-colors whitespace-nowrap shrink-0 cursor-pointer border-b-2 ${
              activeTab === 'explorer'
                ? 'border-blue-600 text-slate-900 font-semibold'
                : 'border-transparent hover:text-slate-900'
            }`}
          >
            Source Code PHP
          </button>
          <button
            type="button"
            onClick={() => setActiveTab('templates')}
            className={`py-1 transition-colors whitespace-nowrap shrink-0 cursor-pointer border-b-2 ${
              activeTab === 'templates'
                ? 'border-blue-600 text-slate-900 font-semibold'
                : 'border-transparent hover:text-slate-900'
            }`}
          >
            Preview Template Email
          </button>
          <button
            type="button"
            onClick={() => setActiveTab('deploy')}
            className={`py-1 transition-colors whitespace-nowrap shrink-0 cursor-pointer border-b-2 ${
              activeTab === 'deploy'
                ? 'border-blue-600 text-slate-900 font-semibold'
                : 'border-transparent hover:text-slate-900'
            }`}
          >
            Deploy &amp; Gmail Setup
          </button>
        </nav>

        <div className="shrink-0">
          <a
            href={manifest.zipUrl}
            download="support-septian.zip"
            className="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors whitespace-nowrap shrink-0"
          >
            <Download className="w-4 h-4" />
            <span>Unduh ZIP ({formatBytes(manifest.zipSize)})</span>
          </a>
        </div>
      </header>

      {/* Summary Strip */}
      <div className="bg-white border-b border-slate-200 px-6 py-4">
        <div className="max-w-[1400px] mx-auto flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <h1 className="text-xl font-bold tracking-tight text-slate-900">
              Paket Source Code PHP 8.1+ &amp; MySQL PDO — Support Septian F. R.
            </h1>
            <p className="text-sm text-slate-500 mt-0.5">
              HTML5 · CSS3 · JavaScript Vanilla · PHP 8.1+ · MySQL PDO · Google Gmail API (OAuth 2.0) · Google App Password (SMTP/IMAP)
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-6 text-sm tabular-nums">
            <div>
              <div className="text-xs text-slate-500">Total File Paket</div>
              <div className="font-bold text-slate-900">{manifest.fileCount} file</div>
            </div>
            <div className="h-7 w-px bg-slate-200" />
            <div>
              <div className="text-xs text-slate-500">Modul PHP</div>
              <div className="font-bold text-slate-900">{manifest.phpCount} file .php</div>
            </div>
            <div className="h-7 w-px bg-slate-200" />
            <div>
              <div className="text-xs text-slate-500">Total Baris Kode</div>
              <div className="font-bold text-slate-900">{manifest.totalLines.toLocaleString('id-ID')} baris</div>
            </div>
            <div className="h-7 w-px bg-slate-200" />
            <div>
              <div className="text-xs text-slate-500">Base Path Produksi</div>
              <div className="font-mono text-xs font-semibold text-blue-700">/mail/support</div>
            </div>
          </div>
        </div>
      </div>

      {/* Main Content */}
      <main className="flex-1 max-w-[1400px] w-full mx-auto p-6">
        {activeTab === 'explorer' && (
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
            {/* Left File Tree */}
            <aside className="lg:col-span-4 bg-white border border-slate-200 rounded-xl overflow-hidden flex flex-col">
              <div className="p-4 border-b border-slate-200 space-y-3">
                <div className="relative">
                  <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                  <input
                    type="search"
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    placeholder="Cari file (mis. callback.php, database.sql)..."
                    className="w-full pl-9 pr-3 py-2 text-sm bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:border-blue-600 focus:bg-white"
                  />
                </div>
                <div className="flex flex-wrap gap-1 p-1 bg-slate-100 rounded-lg">
                  {(
                    [
                      ['all', 'Semua'],
                      ['pages', 'Halaman'],
                      ['oauth', 'OAuth'],
                      ['api', 'API'],
                      ['includes', 'Includes'],
                      ['assets', 'CSS/JS'],
                      ['docs', 'SQL/Docs'],
                    ] as [FileCategory, string][]
                  ).map(([key, label]) => (
                    <button
                      key={key}
                      type="button"
                      onClick={() => setFileFilter(key)}
                      className={`px-2.5 py-1 text-xs font-medium rounded-md transition-colors cursor-pointer ${
                        fileFilter === key
                          ? 'bg-white text-slate-900 shadow-xs'
                          : 'text-slate-600 hover:text-slate-900'
                      }`}
                    >
                      {label}
                    </button>
                  ))}
                </div>
              </div>

              <div className="max-h-[680px] overflow-y-auto divide-y divide-slate-100">
                {filteredFiles.map((file) => {
                  const isSelected = activeFile?.path === file.path;
                  return (
                    <button
                      key={file.path}
                      type="button"
                      onClick={() => setSelectedPath(file.path)}
                      className={`w-full text-left px-4 py-2.5 flex items-center justify-between gap-2 transition-colors cursor-pointer ${
                        isSelected ? 'bg-blue-50 text-blue-700' : 'hover:bg-slate-50 text-slate-700'
                      }`}
                    >
                      <div className="flex items-center gap-2.5 min-w-0">
                        <FileCode2
                          className={`w-4 h-4 shrink-0 ${
                            isSelected ? 'text-blue-600' : 'text-slate-400'
                          }`}
                        />
                        <span className="font-mono text-xs truncate">{file.path}</span>
                      </div>
                      <span className="text-[11px] text-slate-400 tabular-nums shrink-0">
                        {file.binary ? formatBytes(file.size) : `${file.lines}L`}
                      </span>
                    </button>
                  );
                })}
                {filteredFiles.length === 0 && (
                  <div className="p-8 text-center text-sm text-slate-500">
                    Tidak ada file yang cocok dengan pencarian Anda.
                  </div>
                )}
              </div>
            </aside>

            {/* Right Code Viewer */}
            <section className="lg:col-span-8 bg-white border border-slate-200 rounded-xl overflow-hidden">
              {activeFile && (
                <>
                  <div className="px-5 py-3.5 border-b border-slate-200 bg-slate-50 flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-2 min-w-0">
                      <FolderOpen className="w-4 h-4 text-blue-600 shrink-0" />
                      <span className="font-mono text-sm font-semibold text-slate-900 truncate">
                        support-septian/{activeFile.path}
                      </span>
                    </div>
                    <div className="flex items-center gap-4 text-xs text-slate-500 tabular-nums">
                      <span>{formatBytes(activeFile.size)}</span>
                      {!activeFile.binary && <span>{activeFile.lines} baris</span>}
                      {!activeFile.binary && (
                        <button
                          type="button"
                          onClick={() => handleCopy(activeFile.content, activeFile.path)}
                          className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 rounded-md cursor-pointer"
                        >
                          {copiedId === activeFile.path ? (
                            <>
                              <Check className="w-3.5 h-3.5 text-emerald-600" />
                              <span>Tersalin</span>
                            </>
                          ) : (
                            <>
                              <Copy className="w-3.5 h-3.5" />
                              <span>Salin Kode</span>
                            </>
                          )}
                        </button>
                      )}
                    </div>
                  </div>

                  {activeFile.binary ? (
                    <div className="p-12 text-center">
                      <p className="text-sm font-semibold text-slate-900">Aset Gambar PNG Biner</p>
                      <p className="text-xs text-slate-500 mt-1">
                        File <code>{activeFile.path}</code> ({formatBytes(activeFile.size)}) sudah termasuk di dalam paket{' '}
                        <code>support-septian.zip</code>.
                      </p>
                    </div>
                  ) : (
                    <div className="max-h-[700px] overflow-auto bg-slate-950 text-slate-100 p-4 text-xs leading-relaxed">
                      <pre className="m-0">
                        <code>{activeFile.content}</code>
                      </pre>
                    </div>
                  )}
                </>
              )}
            </section>
          </div>
        )}

        {activeTab === 'templates' && (
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            {/* Controls */}
            <section className="lg:col-span-4 bg-white border border-slate-200 rounded-xl p-5 space-y-4">
              <div>
                <h2 className="text-base font-bold text-slate-900">Simulator Template Email Resmi</h2>
                <p className="text-xs text-slate-500 mt-1">
                  Desain inline CSS bergaya Google (lebar maks. 600px, background putih, responsif Android/iPhone) yang dihasilkan oleh{' '}
                  <code>email-renderer.php</code>.
                </p>
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-600 mb-1.5">
                  Pilih Template (8 Template Awal)
                </label>
                <select
                  value={selectedTplId}
                  onChange={(e) => setSelectedTplId(e.target.value)}
                  className="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg bg-white focus:outline-none focus:border-blue-600"
                >
                  {BUILTIN_TEMPLATES.map((tpl) => (
                    <option key={tpl.id} value={tpl.id}>
                      [{tpl.category}] {tpl.name}
                      {tpl.isDefault ? ' (Default)' : ''}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-600 mb-1">{'{nama_penerima}'}</label>
                <input
                  type="text"
                  value={simVars.nama_penerima}
                  onChange={(e) => setSimVars((s) => ({ ...s, nama_penerima: e.target.value }))}
                  className="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-blue-600"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-600 mb-1">{'{email_penerima}'}</label>
                <input
                  type="email"
                  value={simVars.email_penerima}
                  onChange={(e) => setSimVars((s) => ({ ...s, email_penerima: e.target.value }))}
                  className="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-blue-600"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-600 mb-1">{'{subjek}'}</label>
                <input
                  type="text"
                  value={simVars.subjek}
                  onChange={(e) => setSimVars((s) => ({ ...s, subjek: e.target.value }))}
                  className="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-blue-600"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-600 mb-1">{'{isi_pesan}'}</label>
                <textarea
                  rows={4}
                  value={simVars.isi_pesan}
                  onChange={(e) => setSimVars((s) => ({ ...s, isi_pesan: e.target.value }))}
                  className="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-blue-600"
                />
              </div>

              <button
                type="button"
                onClick={() => handleCopy(renderedPreview.html, 'rendered-email-html')}
                className="w-full inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors cursor-pointer"
              >
                {copiedId === 'rendered-email-html' ? (
                  <>
                    <Check className="w-4 h-4 text-emerald-600" />
                    <span>HTML Email Tersalin</span>
                  </>
                ) : (
                  <>
                    <Copy className="w-4 h-4" />
                    <span>Salin HTML Inline CSS</span>
                  </>
                )}
              </button>
            </section>

            {/* Live Stage */}
            <section className="lg:col-span-8 bg-white border border-slate-200 rounded-xl p-5">
              <div className="flex flex-wrap items-center justify-between gap-3 pb-4 mb-4 border-b border-slate-200">
                <div className="text-sm space-y-0.5">
                  <div className="text-slate-600">
                    <strong className="text-slate-900">Dari:</strong> Support Septian F. R. &lt;support.septianfr@gmail.com&gt;
                  </div>
                  <div className="text-slate-600">
                    <strong className="text-slate-900">Subjek:</strong> {renderedPreview.subject}
                  </div>
                </div>
                <div className="flex items-center gap-1 p-1 bg-slate-100 rounded-lg">
                  <button
                    type="button"
                    onClick={() => setViewport('desktop')}
                    className={`inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-md cursor-pointer ${
                      viewport === 'desktop' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600'
                    }`}
                  >
                    <Monitor className="w-3.5 h-3.5" />
                    <span>Desktop (600px)</span>
                  </button>
                  <button
                    type="button"
                    onClick={() => setViewport('mobile')}
                    className={`inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-md cursor-pointer ${
                      viewport === 'mobile' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600'
                    }`}
                  >
                    <Smartphone className="w-3.5 h-3.5" />
                    <span>Mobile (375px)</span>
                  </button>
                </div>
              </div>

              <div className="bg-slate-100 rounded-xl p-5 flex justify-center overflow-x-auto">
                <iframe
                  title="Preview Template Email Support Septian"
                  srcDoc={renderedPreview.html}
                  sandbox="allow-same-origin"
                  className={`bg-white border border-slate-200 rounded-lg h-[720px] transition-all ${
                    viewport === 'mobile' ? 'w-[375px]' : 'w-full max-w-[660px]'
                  }`}
                />
              </div>
            </section>
          </div>
        )}

        {activeTab === 'deploy' && (
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {/* Google OAuth 2.0 Spec */}
            <section className="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
              <div className="flex items-center gap-2.5">
                <Mail className="w-5 h-5 text-blue-600" />
                <h2 className="text-base font-bold text-slate-900">
                  1. Konfigurasi Google Gmail API (OAuth 2.0 Web Application)
                </h2>
              </div>
              <p className="text-sm text-slate-600">
                Masukkan konfigurasi berikut pada Google Cloud Console &rarr; APIs &amp; Services &rarr; Credentials:
              </p>

              <div className="divide-y divide-slate-200 border border-slate-200 rounded-lg bg-slate-50 px-4 text-sm">
                {[
                  ['Akun Gmail Resmi', 'support.septianfr@gmail.com'],
                  ['Authorized JavaScript Origin', 'https://septianfr.web.id'],
                  ['Authorized Redirect URI (Wajib)', 'https://septianfr.web.id/mail/support/oauth/callback.php'],
                  ['Scope 1', 'https://www.googleapis.com/auth/gmail.readonly'],
                  ['Scope 2', 'https://www.googleapis.com/auth/gmail.send'],
                  ['Scope 3', 'https://www.googleapis.com/auth/gmail.modify'],
                ].map(([label, val]) => (
                  <div key={label} className="py-2.5 flex items-center justify-between gap-3">
                    <span className="text-xs font-medium text-slate-500">{label}</span>
                    <div className="flex items-center gap-2 min-w-0">
                      <code className="text-xs text-slate-900 truncate">{val}</code>
                      <button
                        type="button"
                        onClick={() => handleCopy(val, label)}
                        className="text-slate-400 hover:text-blue-600 cursor-pointer shrink-0"
                        title="Salin"
                      >
                        {copiedId === label ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Copy className="w-3.5 h-3.5" />}
                      </button>
                    </div>
                  </div>
                ))}
              </div>

              <div className="pt-2">
                <h3 className="text-sm font-bold text-slate-900 mb-2">2. Alternatif Google App Password (SMTP + IMAP)</h3>
                <p className="text-sm text-slate-600 leading-relaxed">
                  Sudah tersedia di <code>install.php</code> dan <code>gmail-connection.php</code>. Aktifkan{' '}
                  <strong>2-Step Verification</strong> pada <code>support.septianfr@gmail.com</code>, buat 16-digit{' '}
                  <strong>App Password</strong>, masukkan pada panel <strong>Google App Password</strong> (disimpan terenkripsi
                  AES-256-GCM), lalu tekan tombol <strong>Uji Koneksi SMTP &amp; IMAP</strong>.
                </p>
              </div>
            </section>

            {/* Deployment & Security Checklist */}
            <section className="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
              <div className="flex items-center gap-2.5">
                <ShieldCheck className="w-5 h-5 text-blue-600" />
                <h2 className="text-base font-bold text-slate-900">Langkah Pemasangan di Hosting PHP (cPanel)</h2>
              </div>

              <ol className="list-decimal list-inside space-y-3 text-sm text-slate-700 leading-relaxed">
                <li>
                  <strong>Unduh &amp; Ekstrak ZIP:</strong> Klik tombol <strong>Unduh ZIP</strong> di kanan atas, lalu ekstrak folder{' '}
                  <code>support-septian/</code>.
                </li>
                <li>
                  <strong>Instal Google API PHP Client:</strong> Jalankan perintah berikut di root folder{' '}
                  <code>support-septian/</code> agar folder <code>mail/support/vendor/</code> terbentuk:
                  <div className="mt-1.5 flex items-center justify-between gap-2 bg-slate-950 text-slate-100 rounded-lg px-3 py-2 font-mono text-xs">
                    <span>composer install --no-dev --optimize-autoloader</span>
                    <button
                      type="button"
                      onClick={() => handleCopy('composer install --no-dev --optimize-autoloader', 'composer-cmd')}
                      className="text-slate-400 hover:text-white cursor-pointer"
                    >
                      {copiedId === 'composer-cmd' ? <Check className="w-3.5 h-3.5 text-emerald-400" /> : <Copy className="w-3.5 h-3.5" />}
                    </button>
                  </div>
                </li>
                <li>
                  <strong>Unggah ke Hosting:</strong> Unggah seluruh isi <code>mail/support/</code> (termasuk folder{' '}
                  <code>vendor/</code>) ke <code>public_html/mail/support/</code>.
                </li>
                <li>
                  <strong>Jalankan Installer Web:</strong> Buka{' '}
                  <code>https://septianfr.web.id/mail/support/install.php</code> untuk menghubungkan MySQL PDO, mengimpor skema tabel +
                  8 template profesional awal, membuat akun admin, dan mengisi kredensial OAuth / App Password.
                </li>
                <li>
                  <strong>Hardening Pasca-Instalasi:</strong> Hapus <code>install.php</code> &amp; <code>database.sql</code>, dan
                  pindahkan <code>storage/app-config.php</code> ke <code>~/support-septian-private/config.php</code> (chmod 0600).
                </li>
              </ol>
            </section>
          </div>
        )}
      </main>
    </div>
  );
}
