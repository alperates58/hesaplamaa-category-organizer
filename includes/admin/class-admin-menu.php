<?php
namespace HCO\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Admin_Menu {

    private static ?Admin_Menu $instance = null;
    public static function get_instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {}

    public function register(): void {
        add_menu_page(
            __( 'Category Organizer', 'hesaplamaa-category-organizer' ),
            __( 'Cat Organizer', 'hesaplamaa-category-organizer' ),
            'manage_categories',
            'hco-dashboard',
            [ $this, 'render_app' ],
            $this->get_svg_icon(),
            25
        );

        $pages = [
            [ 'hco-dashboard',    __( 'Dashboard', 'hesaplamaa-category-organizer' ) ],
            [ 'hco-ai-assistant', __( 'AI Assistant', 'hesaplamaa-category-organizer' ) ],
            [ 'hco-suggestions',  __( 'Suggestions', 'hesaplamaa-category-organizer' ) ],
            [ 'hco-clusters',     __( 'SEO Clusters', 'hesaplamaa-category-organizer' ) ],
            [ 'hco-audit-log',    __( 'Audit Log', 'hesaplamaa-category-organizer' ) ],
            [ 'hco-settings',     __( 'Settings', 'hesaplamaa-category-organizer' ) ],
            [ 'hco-github',       __( 'GitHub Güncelle', 'hesaplamaa-category-organizer' ) ],
        ];

        foreach ( $pages as [ $slug, $title ] ) {
            add_submenu_page(
                'hco-dashboard',
                $title,
                $title,
                'manage_categories',
                $slug,
                [ $this, 'render_app' ]
            );
        }

        add_submenu_page(
            'hco-dashboard',
            __( 'Toplu Analiz & Onay', 'hesaplamaa-category-organizer' ),
            __( '⚡ Toplu Analiz & Onay', 'hesaplamaa-category-organizer' ),
            'manage_categories',
            'hco-bulk-review',
            [ $this, 'render_bulk_review' ]
        );

        add_submenu_page(
            'hco-dashboard',
            __( 'Excel İçe Aktar', 'hesaplamaa-category-organizer' ),
            __( '📥 Excel İçe Aktar', 'hesaplamaa-category-organizer' ),
            'manage_categories',
            'hco-excel-import',
            [ $this, 'render_excel_import' ]
        );
    }

    public function render_app(): void {
        echo '<div id="hco-root" class="hco-app-root"></div>';
    }

    public function render_bulk_review(): void {
        $nonce    = wp_create_nonce( 'wp_rest' );
        $rest_url = esc_js( rest_url( 'hco/v1' ) );
        ?>
        <style>
        .hbr{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:1200px;padding:20px 0}
        .hbr h1{font-size:22px;font-weight:700;margin-bottom:6px;color:#1d2327}
        .hbr .sub{color:#646970;font-size:14px;margin:0 0 24px}
        .hbr-box{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:24px;margin-bottom:20px}
        .hbr-box h2{font-size:15px;font-weight:700;margin:0 0 16px;color:#1d2327}
        .hbr-radio{display:flex;flex-direction:column;gap:10px}
        .hbr-radio label{display:flex;align-items:flex-start;gap:12px;padding:14px 16px;border:1px solid #dcdcde;border-radius:6px;cursor:pointer;transition:border-color .15s,background .15s}
        .hbr-radio label:hover{border-color:#2271b1;background:#f0f6fc}
        .hbr-radio input[type=radio]{margin-top:2px;flex-shrink:0}
        .hbr-radio input[type=radio]:checked + .hbr-radio-text{color:#1d2327}
        .hbr-radio label:has(input:checked){border-color:#2271b1;background:#f0f6fc}
        .hbr-radio-title{font-weight:600;font-size:14px;color:#1d2327}
        .hbr-radio-desc{font-size:12px;color:#646970;margin-top:2px}
        #hbr-start-btn{margin-top:18px;padding:10px 24px;background:#2271b1;border:none;border-radius:4px;color:#fff;font-size:14px;font-weight:600;cursor:pointer}
        #hbr-start-btn:hover{background:#135e96}
        #hbr-start-btn:disabled{background:#c3c4c7;cursor:not-allowed}
        .hbr-progress-wrap{margin-top:20px}
        .hbr-prog-label{font-size:14px;color:#1d2327;margin-bottom:8px;font-weight:600}
        .hbr-prog-sub{font-size:12px;color:#646970;margin-bottom:12px}
        .hbr-prog-bar{height:8px;background:#dcdcde;border-radius:4px;overflow:hidden}
        .hbr-prog-bar-inner{height:100%;background:#2271b1;border-radius:4px;transition:width .4s ease}
        .hbr-spinner{display:inline-block;width:14px;height:14px;border:2px solid #dcdcde;border-top-color:#2271b1;border-radius:50%;animation:hbr-spin .7s linear infinite;vertical-align:middle;margin-right:6px}
        @keyframes hbr-spin{to{transform:rotate(360deg)}}
        .hbr-toolbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
        .hbr-toolbar select,.hbr-toolbar input[type=range]{border:1px solid #dcdcde;border-radius:4px;padding:5px 10px;font-size:13px}
        .hbr-toolbar label{font-size:13px;color:#646970}
        .hbr-sel-btn{padding:5px 12px;border:1px solid #dcdcde;background:#fff;border-radius:4px;font-size:12px;cursor:pointer}
        .hbr-sel-btn:hover{background:#f6f7f7}
        .hbr-count-label{margin-left:auto;font-size:13px;color:#646970}
        .hbr-table-wrap{max-height:520px;overflow-y:auto;border:1px solid #dcdcde;border-radius:6px}
        .hbr-table{width:100%;border-collapse:collapse;font-size:13px}
        .hbr-table th{background:#f6f7f7;padding:9px 12px;text-align:left;border-bottom:1px solid #dcdcde;position:sticky;top:0;z-index:1;font-weight:600;white-space:nowrap}
        .hbr-table th.chk{width:40px;text-align:center}
        .hbr-table td{padding:8px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
        .hbr-table tr:last-child td{border-bottom:none}
        .hbr-table tr.hidden-row{display:none}
        .hbr-table td.chk{text-align:center}
        .conf-bar{display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
        .conf-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
        .conf-high{background:#00a32a}.conf-mid{background:#dba617}.conf-low{background:#d63638}
        .cat-arrow{color:#646970;margin:0 4px}
        .hbr-apply-bar{display:flex;align-items:center;gap:14px;margin-top:18px;flex-wrap:wrap}
        #hbr-apply-btn{padding:10px 24px;background:#00a32a;border:none;border-radius:4px;color:#fff;font-size:14px;font-weight:600;cursor:pointer}
        #hbr-apply-btn:hover{background:#007017}
        #hbr-apply-btn:disabled{background:#c3c4c7;cursor:not-allowed}
        .hbr-result{padding:16px 20px;border-radius:6px;font-size:14px;line-height:1.7}
        .hbr-result.ok{background:#d1e7dd;color:#0a3622;border:1px solid #a3cfbb}
        .hbr-result.err{background:#f8d7da;color:#58151c;border:1px solid #f1aeb5}
        .hbr-new-btn{margin-top:14px;padding:8px 16px;background:#fff;border:1px solid #dcdcde;border-radius:4px;font-size:13px;cursor:pointer}
        .hbr-empty{padding:40px;text-align:center;color:#646970;font-size:14px}
        </style>

        <div class="hbr wrap">
            <h1>⚡ Toplu Analiz & Onay</h1>
            <p class="sub">Yazılarınızı AI ile analiz edin, önerileri gözden geçirip onaylayın.</p>

            <!-- Fix Parents Banner -->
            <div id="hbr-fix-banner" class="hbr-box" style="border-left:4px solid #dba617;display:none">
                <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
                    <div style="flex:1">
                        <strong style="color:#1d2327">⚠️ Üst Kategori Eksikliği Tespit Edildi</strong>
                        <p id="hbr-fix-desc" style="margin:4px 0 0;font-size:13px;color:#646970"></p>
                    </div>
                    <button id="hbr-fix-btn" style="padding:8px 18px;background:#dba617;border:none;border-radius:4px;color:#fff;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap">
                        Üst Kategorileri Ekle
                    </button>
                    <span id="hbr-fix-status" style="font-size:13px;color:#646970"></span>
                </div>
            </div>

            <!-- Step 1: Setup -->
            <div id="hbr-setup" class="hbr-box">
                <h2>İş Türü Seçin</h2>
                <div class="hbr-radio">
                    <label>
                        <input type="radio" name="job_type" value="categorize_uncategorized" checked>
                        <div class="hbr-radio-text">
                            <div class="hbr-radio-title">Kategorisiz Yazıları Kategorile</div>
                            <div class="hbr-radio-desc">Varsayılan/kategorisiz kategorideki yazıları analiz et</div>
                        </div>
                    </label>
                    <label>
                        <input type="radio" name="job_type" value="recategorize_all">
                        <div class="hbr-radio-text">
                            <div class="hbr-radio-title">Tüm Yazıları Yeniden Kategorile</div>
                            <div class="hbr-radio-desc">Yayınlanmış tüm yazıları yeniden analiz et</div>
                        </div>
                    </label>
                    <label>
                        <input type="radio" name="job_type" value="detect_orphans">
                        <div class="hbr-radio-text">
                            <div class="hbr-radio-title">Sahipsiz Yazıları Tespit Et</div>
                            <div class="hbr-radio-desc">Hiç kategorisi atanmamış yazıları bul ve kategorile</div>
                        </div>
                    </label>
                </div>
                <button id="hbr-start-btn">▶ Analizi Başlat</button>
            </div>

            <!-- Step 2: Progress -->
            <div id="hbr-progress" class="hbr-box" style="display:none">
                <h2><span class="hbr-spinner"></span> Analiz Ediliyor</h2>
                <div class="hbr-prog-label" id="hbr-prog-label">Başlatılıyor...</div>
                <div class="hbr-prog-sub" id="hbr-prog-sub">Bu işlem birkaç dakika sürebilir.</div>
                <div class="hbr-prog-bar"><div class="hbr-prog-bar-inner" id="hbr-prog-inner" style="width:0%"></div></div>
            </div>

            <!-- Step 3: Review -->
            <div id="hbr-review" class="hbr-box" style="display:none">
                <h2 id="hbr-review-title">Öneriler</h2>

                <div class="hbr-toolbar">
                    <button class="hbr-sel-btn" id="hbr-sel-all">✓ Tümünü Seç</button>
                    <button class="hbr-sel-btn" id="hbr-sel-none">✗ Seçimi Kaldır</button>
                    <label>Güven ≥ <strong id="hbr-conf-val">70</strong>%
                        <input type="range" id="hbr-conf-range" min="0" max="100" value="70" style="width:100px;vertical-align:middle">
                    </label>
                    <label>Ana Kategori:
                        <select id="hbr-parent-filter"><option value="">Tümü</option></select>
                    </label>
                    <span class="hbr-count-label" id="hbr-count-label"></span>
                </div>

                <div class="hbr-table-wrap">
                    <table class="hbr-table">
                        <thead>
                            <tr>
                                <th class="chk"><input type="checkbox" id="hbr-chk-master" checked></th>
                                <th>Yazı Başlığı</th>
                                <th>Mevcut Kategori</th>
                                <th>Önerilen Kategori</th>
                                <th>Güven</th>
                                <th>SEO Niyet</th>
                            </tr>
                        </thead>
                        <tbody id="hbr-tbody"></tbody>
                    </table>
                </div>

                <div class="hbr-apply-bar">
                    <button id="hbr-apply-btn" disabled>Seçilenleri Uygula</button>
                    <span id="hbr-apply-label" style="font-size:13px;color:#646970"></span>
                </div>
            </div>

            <!-- Step 4: Result -->
            <div id="hbr-result-box" style="display:none">
                <div id="hbr-result-msg"></div>
                <button class="hbr-new-btn" onclick="location.reload()">← Yeni Analiz Başlat</button>
            </div>
        </div>

        <script>
        (function(){
            const NONCE    = '<?php echo esc_js( $nonce ); ?>';
            const REST_URL = '<?php echo $rest_url; ?>';

            const $ = id => document.getElementById(id);
            let jobId      = null;

            // ── Fix Parents Banner ────────────────────────────────────────
            fetch(REST_URL + '/bulk/fix-parents/preview', { headers: { 'X-WP-Nonce': NONCE } })
            .then(r => r.json())
            .then(data => {
                if (data.affected > 0) {
                    $('hbr-fix-desc').textContent =
                        data.affected + ' yazı sadece alt kategoride kayıtlı — üst kategorileri eksik.';
                    $('hbr-fix-banner').style.display = 'block';
                }
            }).catch(() => {});

            $('hbr-fix-btn').addEventListener('click', () => {
                $('hbr-fix-btn').disabled = true;
                $('hbr-fix-status').textContent = 'Düzeltiliyor...';
                fetch(REST_URL + '/bulk/fix-parents/apply', {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
                    body: JSON.stringify({}),
                })
                .then(r => r.json())
                .then(res => {
                    $('hbr-fix-banner').style.borderColor = '#00a32a';
                    $('hbr-fix-btn').style.display = 'none';
                    $('hbr-fix-desc').textContent  = '✅ ' + res.fixed + ' yazıya üst kategori eklendi.';
                    $('hbr-fix-status').textContent = '';
                })
                .catch(e => { $('hbr-fix-status').textContent = 'Hata: ' + e.message; $('hbr-fix-btn').disabled=false; });
            });
            let pollTimer  = null;
            let allRows    = [];   // { id, post_title, current, parent, child, confidence, intent, visible }

            // ── Step 1: Start Job ────────────────────────────────────────
            $('hbr-start-btn').addEventListener('click', () => {
                const jobType = document.querySelector('input[name="job_type"]:checked').value;
                $('hbr-start-btn').disabled = true;

                fetch(REST_URL + '/bulk/jobs', {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
                    body: JSON.stringify({ job_type: jobType }),
                })
                .then(r => r.json())
                .then(data => {
                    if (!data.job_id) { alert('İş oluşturulamadı.'); $('hbr-start-btn').disabled=false; return; }
                    jobId = data.job_id;
                    $('hbr-setup').style.display    = 'none';
                    $('hbr-progress').style.display = 'block';
                    pollTimer = setInterval(pollJob, 3000);
                    pollJob();
                })
                .catch(e => { alert('Hata: ' + e.message); $('hbr-start-btn').disabled=false; });
            });

            // ── Step 2: Poll Job Status ───────────────────────────────────
            function pollJob() {
                fetch(REST_URL + '/bulk/jobs/' + jobId, { headers: { 'X-WP-Nonce': NONCE } })
                .then(r => r.json())
                .then(job => {
                    const total     = parseInt(job.total_items) || 0;
                    const processed = parseInt(job.processed_items) || 0;
                    const pct       = total > 0 ? Math.round((processed / total) * 100) : 0;

                    $('hbr-prog-inner').style.width = pct + '%';
                    $('hbr-prog-label').textContent =
                        processed + ' / ' + total + ' yazı analiz edildi (' + pct + '%)';
                    $('hbr-prog-sub').textContent =
                        job.status === 'running' ? 'İşleniyor...' :
                        job.status === 'queued'  ? 'Kuyrukta bekliyor...' :
                        job.status === 'completed' ? 'Tamamlandı!' : job.status;

                    if (job.status === 'completed') {
                        clearInterval(pollTimer);
                        loadSuggestions();
                    } else if (job.status === 'failed') {
                        clearInterval(pollTimer);
                        $('hbr-prog-label').textContent = '❌ İş başarısız oldu.';
                    }
                })
                .catch(() => {});
            }

            // ── Step 3: Load & Render Suggestions ─────────────────────────
            function loadSuggestions() {
                fetch(REST_URL + '/bulk/jobs/' + jobId + '/suggestions', { headers: { 'X-WP-Nonce': NONCE } })
                .then(r => r.json())
                .then(data => {
                    $('hbr-progress').style.display = 'none';

                    if (!data.suggestions || data.suggestions.length === 0) {
                        $('hbr-review').style.display = 'block';
                        $('hbr-tbody').innerHTML =
                            '<tr><td colspan="6" class="hbr-empty">Onaylanacak öneri bulunamadı. Tüm yazılar zaten kategorize edilmiş olabilir.</td></tr>';
                        return;
                    }

                    // Build rows
                    allRows = data.suggestions.map(s => ({
                        id:         parseInt(s.id),
                        post_title: s.post_title || '(Başlıksız)',
                        post_url:   s.post_url   || '#',
                        current:    s.current_cat_name || '—',
                        parent:     s.suggested_parent_name_resolved || s.suggested_parent_name || '—',
                        child:      s.suggested_cat_name_resolved    || s.suggested_cat_name    || '—',
                        conf:       parseInt(s.confidence) || 0,
                        intent:     s.seo_intent   || '',
                        reasoning:  s.ai_reasoning || '',
                    }));

                    // Populate parent filter
                    const parents = [...new Set(allRows.map(r => r.parent))].sort();
                    const sel = $('hbr-parent-filter');
                    parents.forEach(p => {
                        const o = document.createElement('option');
                        o.value = p; o.textContent = p;
                        sel.appendChild(o);
                    });

                    $('hbr-review-title').textContent =
                        'Öneriler — ' + allRows.length + ' yazı analiz edildi';
                    $('hbr-review').style.display = 'block';

                    renderTable();
                    updateApplyBtn();
                });
            }

            function renderTable() {
                const minConf  = parseInt($('hbr-conf-range').value);
                const parentF  = $('hbr-parent-filter').value;
                const tbody    = $('hbr-tbody');
                tbody.innerHTML = '';
                let visible = 0;

                allRows.forEach(row => {
                    const show = row.conf >= minConf && (!parentF || row.parent === parentF);
                    const confCls = row.conf >= 80 ? 'conf-high' : row.conf >= 60 ? 'conf-mid' : 'conf-low';
                    const tr = document.createElement('tr');
                    tr.dataset.id   = row.id;
                    tr.dataset.conf = row.conf;
                    if (!show) tr.classList.add('hidden-row');
                    else visible++;

                    tr.innerHTML =
                        `<td class="chk"><input type="checkbox" class="hbr-chk" data-id="${row.id}" ${show ? 'checked' : ''}></td>
                         <td><a href="${row.post_url}" target="_blank" style="color:#2271b1;text-decoration:none" title="${row.reasoning}">${row.post_title}</a></td>
                         <td style="color:#646970">${row.current}</td>
                         <td><strong>${row.parent}</strong><span class="cat-arrow">›</span>${row.child}</td>
                         <td><span class="conf-bar"><span class="conf-dot ${confCls}"></span>${row.conf}%</span></td>
                         <td style="color:#646970;font-size:12px">${row.intent}</td>`;
                    tbody.appendChild(tr);
                });

                $('hbr-count-label').textContent = visible + ' öneri gösteriliyor';
                updateApplyBtn();
            }

            function updateApplyBtn() {
                const checked = document.querySelectorAll('.hbr-chk:checked').length;
                $('hbr-apply-btn').disabled   = checked === 0;
                $('hbr-apply-btn').textContent = checked > 0
                    ? checked + ' Yazıyı Kategorile'
                    : 'Seçilenleri Uygula';
                $('hbr-apply-label').textContent = checked > 0
                    ? checked + ' yazı seçili'
                    : 'Uygulamak için en az bir yazı seçin.';
            }

            // Filters
            $('hbr-conf-range').addEventListener('input', e => {
                $('hbr-conf-val').textContent = e.target.value;
                renderTable();
            });
            $('hbr-parent-filter').addEventListener('change', renderTable);

            // Master checkbox
            $('hbr-chk-master').addEventListener('change', e => {
                document.querySelectorAll('.hbr-chk').forEach(c => {
                    if (!c.closest('tr').classList.contains('hidden-row')) c.checked = e.target.checked;
                });
                updateApplyBtn();
            });

            // Select all / none buttons
            $('hbr-sel-all').addEventListener('click', () => {
                document.querySelectorAll('.hbr-chk').forEach(c => {
                    if (!c.closest('tr').classList.contains('hidden-row')) c.checked = true;
                });
                updateApplyBtn();
            });
            $('hbr-sel-none').addEventListener('click', () => {
                document.querySelectorAll('.hbr-chk').forEach(c => c.checked = false);
                updateApplyBtn();
            });

            document.addEventListener('change', e => {
                if (e.target.classList.contains('hbr-chk')) updateApplyBtn();
            });

            // ── Step 4: Apply ─────────────────────────────────────────────
            $('hbr-apply-btn').addEventListener('click', () => {
                const ids = [...document.querySelectorAll('.hbr-chk:checked')].map(c => parseInt(c.dataset.id));
                if (!ids.length) return;

                $('hbr-apply-btn').disabled    = true;
                $('hbr-apply-label').textContent = 'Uygulanıyor...';

                fetch(REST_URL + '/bulk/jobs/' + jobId + '/apply', {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ids }),
                })
                .then(r => r.json())
                .then(res => {
                    $('hbr-review').style.display = 'none';
                    const ok  = !res.error;
                    const msg = ok
                        ? `<strong>✅ ${res.applied} yazı başarıyla kategorilendi!</strong><br>` +
                          (res.skipped > 0 ? res.skipped + ' yazı atlandı.' : '')
                        : `<strong>❌ Hata:</strong> ${res.error}`;
                    $('hbr-result-msg').innerHTML = `<div class="hbr-result ${ok?'ok':'err'}">${msg}</div>`;
                    $('hbr-result-box').style.display = 'block';
                })
                .catch(e => { alert('Hata: ' + e.message); $('hbr-apply-btn').disabled = false; });
            });
        })();
        </script>
        <?php
    }

    public function render_excel_import(): void {
        $nonce    = wp_create_nonce( 'wp_rest' );
        $rest_url = esc_js( rest_url( 'hco/v1' ) );
        ?>
        <style>
        .hco-imp{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:1100px;padding:20px 0}
        .hco-imp h1{font-size:22px;font-weight:700;margin-bottom:24px;color:#1d2327}
        #hco-drop{border:2px dashed #c3c4c7;border-radius:8px;padding:48px 24px;text-align:center;background:#f9f9f9;cursor:pointer;transition:border-color .2s,background .2s}
        #hco-drop.drag-over,#hco-drop:hover{border-color:#2271b1;background:#f0f6fc}
        #hco-drop svg{display:block;margin:0 auto 12px}
        #hco-drop p{margin:4px 0;color:#646970;font-size:14px}
        #hco-file-btn{margin-top:14px;display:inline-block;padding:8px 18px;background:#2271b1;color:#fff;border-radius:4px;font-size:13px;cursor:pointer}
        #hco-file-btn:hover{background:#135e96}
        #hco-file-input{display:none}
        .hco-spinner{display:flex;align-items:center;gap:12px;padding:24px;color:#646970;font-size:14px}
        .hco-spinner svg{animation:hco-spin 1s linear infinite}
        @keyframes hco-spin{to{transform:rotate(360deg)}}
        .hco-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:20px 0}
        .hco-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px;text-align:center}
        .hco-card .num{font-size:28px;font-weight:700;line-height:1}
        .hco-card .lbl{font-size:12px;color:#646970;margin-top:4px}
        .hco-card.c-new .num{color:#00a32a}
        .hco-card.c-exists .num{color:#646970}
        .hco-card.c-moved .num{color:#dba617}
        .hco-card.c-parent .num{color:#2271b1}
        .hco-filter{display:flex;gap:8px;margin:16px 0}
        .hco-filter button{padding:5px 14px;border:1px solid #dcdcde;border-radius:20px;background:#fff;font-size:13px;cursor:pointer;color:#1d2327}
        .hco-filter button.active{background:#2271b1;border-color:#2271b1;color:#fff}
        .hco-table-wrap{max-height:480px;overflow-y:auto;border:1px solid #dcdcde;border-radius:6px}
        .hco-table{width:100%;border-collapse:collapse;font-size:13px}
        .hco-table th{background:#f6f7f7;padding:9px 14px;text-align:left;border-bottom:1px solid #dcdcde;position:sticky;top:0;z-index:1;font-weight:600;color:#1d2327}
        .hco-table td{padding:8px 14px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
        .hco-table tr:last-child td{border-bottom:none}
        .hco-group-row td{background:#f0f6fc;font-weight:600;color:#1d2327;padding:7px 14px}
        .badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
        .badge-new{background:#d1e7dd;color:#0a3622}
        .badge-exists{background:#e8e8e8;color:#3c434a}
        .badge-moved{background:#fff3cd;color:#664d03}
        .badge-parent-new{background:#cfe2ff;color:#084298}
        .badge-parent-exists{background:#e8e8e8;color:#3c434a}
        .hco-actions{margin-top:20px;display:flex;align-items:center;gap:14px}
        #hco-import-btn{padding:10px 24px;background:#00a32a;border:none;border-radius:4px;color:#fff;font-size:14px;font-weight:600;cursor:pointer}
        #hco-import-btn:hover{background:#007017}
        #hco-import-btn:disabled{background:#c3c4c7;cursor:not-allowed}
        .hco-progress-bar{height:6px;background:#dcdcde;border-radius:3px;overflow:hidden;flex:1}
        .hco-progress-bar-inner{height:100%;background:#00a32a;width:0;transition:width .3s}
        .hco-result{padding:16px 20px;border-radius:6px;margin-top:20px;font-size:14px;line-height:1.7}
        .hco-result.success{background:#d1e7dd;color:#0a3622;border:1px solid #a3cfbb}
        .hco-result.error{background:#f8d7da;color:#58151c;border:1px solid #f1aeb5}
        .hco-result ul{margin:6px 0 0 18px}
        .hco-new-upload{margin-top:16px;padding:8px 16px;background:#fff;border:1px solid #dcdcde;border-radius:4px;font-size:13px;cursor:pointer}
        </style>

        <div class="hco-imp wrap">
            <h1>📥 Excel'den Kategori İçe Aktar</h1>

            <!-- Upload zone -->
            <div id="hco-upload-section">
                <div id="hco-drop">
                    <svg width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="#c3c4c7" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 16v-8m0 0-3 3m3-3 3 3M4 16v1a2 2 0 002 2h12a2 2 0 002-2v-1"/>
                    </svg>
                    <p style="font-size:15px;font-weight:600;color:#1d2327">Excel dosyasını buraya sürükleyin</p>
                    <p>veya</p>
                    <label id="hco-file-btn" for="hco-file-input">Dosya Seç (.xlsx)</label>
                    <input type="file" id="hco-file-input" accept=".xlsx">
                    <p style="margin-top:10px;font-size:12px">Beklenen format: "Ana Kategori" ve "Alt Kategori" sütunları</p>
                </div>
            </div>

            <!-- Loading -->
            <div id="hco-loading" style="display:none">
                <div class="hco-spinner">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2271b1" stroke-width="2.5">
                        <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                    </svg>
                    Analiz ediliyor, lütfen bekleyin...
                </div>
            </div>

            <!-- Preview -->
            <div id="hco-preview" style="display:none">
                <h2 style="font-size:16px;font-weight:600;margin:0 0 4px">Önizleme</h2>
                <p id="hco-file-label" style="font-size:13px;color:#646970;margin:0 0 16px"></p>
                <div class="hco-cards" id="hco-cards"></div>

                <div class="hco-filter" id="hco-filter">
                    <button class="active" data-filter="all">Tümü</button>
                    <button data-filter="new">Yeni</button>
                    <button data-filter="exists">Mevcut</button>
                    <button data-filter="moved">Taşınacak</button>
                </div>

                <div class="hco-table-wrap">
                    <table class="hco-table">
                        <thead>
                            <tr>
                                <th>Ana Kategori</th>
                                <th>Alt Kategori</th>
                                <th>Durum</th>
                                <th>Not</th>
                                <th style="width:60px;text-align:center">Uygula</th>
                            </tr>
                        </thead>
                        <tbody id="hco-tbody"></tbody>
                    </table>
                </div>

                <div class="hco-actions">
                    <button id="hco-import-btn">İçe Aktar</button>
                    <div class="hco-progress-bar" id="hco-prog-wrap" style="display:none">
                        <div class="hco-progress-bar-inner" id="hco-prog"></div>
                    </div>
                    <span id="hco-prog-label" style="font-size:13px;color:#646970"></span>
                </div>
            </div>

            <!-- Result -->
            <div id="hco-result" style="display:none"></div>
        </div>

        <script>
        (function(){
            const NONCE    = '<?php echo esc_js( $nonce ); ?>';
            const REST_URL = '<?php echo $rest_url; ?>';

            let diffData   = null;
            let activeFilter = 'all';

            const $  = id => document.getElementById(id);
            const el = (tag, cls) => { const e = document.createElement(tag); if(cls) e.className=cls; return e; };

            // ── Drag & Drop ──────────────────────────────────────────────
            const drop = $('hco-drop');
            drop.addEventListener('dragover',  e => { e.preventDefault(); drop.classList.add('drag-over'); });
            drop.addEventListener('dragleave', () => drop.classList.remove('drag-over'));
            drop.addEventListener('drop', e => {
                e.preventDefault(); drop.classList.remove('drag-over');
                const f = e.dataTransfer.files[0];
                if (f) uploadFile(f);
            });
            $('hco-file-input').addEventListener('change', e => {
                if (e.target.files[0]) uploadFile(e.target.files[0]);
            });

            // ── Upload & Preview ─────────────────────────────────────────
            function uploadFile(file) {
                if (!file.name.endsWith('.xlsx')) { alert('Lütfen .xlsx dosyası seçin.'); return; }
                $('hco-upload-section').style.display = 'none';
                $('hco-loading').style.display = 'block';

                const fd = new FormData();
                fd.append('excel_file', file);

                fetch(REST_URL + '/import/preview', {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': NONCE },
                    body: fd,
                })
                .then(r => r.json())
                .then(data => {
                    $('hco-loading').style.display = 'none';
                    if (data.error) { showError(data.error); return; }
                    diffData = data;
                    renderPreview(file.name, data);
                })
                .catch(err => { $('hco-loading').style.display='none'; showError(err.message); });
            }

            // ── Render Preview ───────────────────────────────────────────
            function renderPreview(filename, data) {
                const s = data.summary;
                $('hco-file-label').textContent = filename + ' — ' +
                    (s.new_parents + s.existing_parents) + ' ana kategori, ' +
                    (s.new_children + s.exists_children + s.moved_children) + ' alt kategori';

                // Cards
                const cards = [
                    { num: s.new_parents,     lbl: 'Yeni Ana Kategori',    cls: 'c-parent' },
                    { num: s.existing_parents, lbl: 'Mevcut Ana Kategori', cls: 'c-exists'  },
                    { num: s.new_children,    lbl: 'Yeni Alt Kategori',    cls: 'c-new'    },
                    { num: s.exists_children, lbl: 'Zaten Mevcut',         cls: 'c-exists' },
                    { num: s.moved_children,  lbl: 'Taşınacak',            cls: 'c-moved'  },
                ];
                const cardsEl = $('hco-cards');
                cardsEl.innerHTML = '';
                cards.forEach(c => {
                    cardsEl.innerHTML += `<div class="hco-card ${c.cls}">
                        <div class="num">${c.num}</div><div class="lbl">${c.lbl}</div></div>`;
                });

                // Filter buttons - hide "Taşınacak" if none
                if (s.moved_children === 0) {
                    $('hco-filter').querySelector('[data-filter="moved"]').style.display = 'none';
                }

                renderTable(data, 'all');

                $('hco-preview').style.display = 'block';
                updateImportBtn();
            }

            function renderTable(data, filter) {
                const tbody = $('hco-tbody');
                tbody.innerHTML = '';
                let rowCount = 0;

                Object.entries(data.parents).forEach(([parentName, parentInfo]) => {
                    const children = data.children[parentName] || [];
                    const visible  = children.filter(c => filter === 'all' || c.status === filter);
                    if (visible.length === 0) return;

                    // Parent group header
                    const pBadge = parentInfo.exists
                        ? `<span class="badge badge-parent-exists">✓ Mevcut</span>`
                        : `<span class="badge badge-parent-new">+ Yeni</span>`;
                    const gtr = document.createElement('tr');
                    gtr.className = 'hco-group-row';
                    gtr.dataset.parent = parentName;
                    gtr.innerHTML = `<td colspan="5">${parentName} &nbsp;${pBadge}</td>`;
                    tbody.appendChild(gtr);

                    // Children
                    visible.forEach(child => {
                        const tr = document.createElement('tr');
                        tr.dataset.status = child.status;
                        tr.dataset.parent = parentName;

                        let badge = '', note = '', chk = '';
                        if (child.status === 'new') {
                            badge = `<span class="badge badge-new">+ Yeni</span>`;
                        } else if (child.status === 'exists') {
                            badge = `<span class="badge badge-exists">✓ Mevcut</span>`;
                        } else if (child.status === 'moved') {
                            badge = `<span class="badge badge-moved">⇄ Taşınacak</span>`;
                            note  = `<span style="color:#646970">Şu an: <strong>${child.current_parent}</strong></span>`;
                            chk   = `<input type="checkbox" data-parent="${parentName}" data-child="${child.name}"
                                        data-term-id="${child.term_id}" class="hco-move-chk" checked
                                        title="İşaretli: taşı / İşaretsiz: geç">`;
                        }

                        tr.innerHTML = `<td></td><td>${child.name}</td><td>${badge}</td><td>${note}</td>
                            <td style="text-align:center">${chk}</td>`;
                        tbody.appendChild(tr);
                        rowCount++;
                    });
                });

                if (rowCount === 0) {
                    tbody.innerHTML = `<tr><td colspan="5" style="padding:20px;text-align:center;color:#646970">
                        Bu filtrede gösterilecek kayıt yok.</td></tr>`;
                }
            }

            // ── Filter ───────────────────────────────────────────────────
            document.querySelectorAll('.hco-filter button').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.hco-filter button').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    activeFilter = btn.dataset.filter;
                    if (diffData) renderTable(diffData, activeFilter);
                });
            });

            // ── Build actions from UI ─────────────────────────────────────
            function buildActions() {
                if (!diffData) return [];
                const actions = [];
                const movedOverrides = {};

                document.querySelectorAll('.hco-move-chk').forEach(chk => {
                    movedOverrides[chk.dataset.parent + '|' + chk.dataset.child] = chk.checked;
                });

                Object.entries(diffData.parents).forEach(([parentName]) => {
                    const children = diffData.children[parentName] || [];
                    children.forEach(child => {
                        const key = parentName + '|' + child.name;
                        if (child.status === 'new') {
                            actions.push({ parent: parentName, child: child.name, action: 'create' });
                        } else if (child.status === 'exists') {
                            actions.push({ parent: parentName, child: child.name, action: 'skip' });
                        } else if (child.status === 'moved') {
                            const doMove = movedOverrides.hasOwnProperty(key) ? movedOverrides[key] : true;
                            actions.push({ parent: parentName, child: child.name,
                                action: doMove ? 'move' : 'skip', term_id: child.term_id });
                        }
                    });
                });
                return actions;
            }

            function updateImportBtn() {
                if (!diffData) return;
                const s = diffData.summary;
                const total = s.new_parents + s.new_children + s.moved_children;
                const btn = $('hco-import-btn');
                if (total === 0) {
                    btn.textContent = 'Tüm kategoriler zaten mevcut';
                    btn.disabled = true;
                } else {
                    btn.textContent = `İçe Aktar (${total} işlem)`;
                    btn.disabled = false;
                }
            }

            // ── Import ────────────────────────────────────────────────────
            $('hco-import-btn').addEventListener('click', () => {
                const actions = buildActions();
                const toProcess = actions.filter(a => a.action !== 'skip');
                if (toProcess.length === 0) { alert('Yapılacak işlem yok.'); return; }

                $('hco-import-btn').disabled = true;
                $('hco-prog-wrap').style.display = 'block';
                $('hco-prog-label').textContent  = 'İçe aktarılıyor...';

                // Animate progress bar while waiting
                let fakeP = 0;
                const tick = setInterval(() => {
                    fakeP = Math.min(fakeP + 4, 85);
                    $('hco-prog').style.width = fakeP + '%';
                }, 120);

                fetch(REST_URL + '/import/execute', {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
                    body: JSON.stringify({ actions }),
                })
                .then(r => r.json())
                .then(res => {
                    clearInterval(tick);
                    $('hco-prog').style.width = '100%';
                    setTimeout(() => showResult(res), 300);
                })
                .catch(err => { clearInterval(tick); showError(err.message); });
            });

            function showResult(res) {
                $('hco-preview').style.display = 'none';
                const hasErr = res.errors && res.errors.length > 0;
                let html = `<div class="hco-result ${hasErr ? 'error' : 'success'}">`;
                if (!hasErr) {
                    html += `<strong>✅ İçe aktarma tamamlandı!</strong><br>
                        ${res.created} kategori oluşturuldu · ${res.moved} kategori taşındı · ${res.skipped} zaten mevcuttu`;
                } else {
                    html += `<strong>⚠️ Bazı hatalar oluştu:</strong>
                        (${res.created} oluşturuldu, ${res.moved} taşındı)<ul>`;
                    res.errors.forEach(e => html += `<li>${e}</li>`);
                    html += '</ul>';
                }
                html += `</div>
                    <button class="hco-new-upload" onclick="location.reload()">← Yeni Excel Yükle</button>`;
                $('hco-result').innerHTML = html;
                $('hco-result').style.display = 'block';
            }

            function showError(msg) {
                $('hco-upload-section').style.display = 'block';
                alert('Hata: ' + msg);
            }
        })();
        </script>
        <?php
    }

    private function get_svg_icon(): string {
        return 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white">
              <path d="M4 6h16M4 12h10M4 18h7"/>
              <circle cx="19" cy="17" r="3"/>
              <path d="M17.5 17h3M19 15.5v3"/>
            </svg>'
        );
    }
}
