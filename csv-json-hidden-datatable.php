<?php
/**
 * Plugin Name: Certificados CSV JSON
 * Description: Metabox no CPT "certificado" para upload de CSV (nome,email,cpf opcional), mescla com JSON existente e exibe DataTable. Permite adicionar linha manualmente, selecionar linhas e excluir em lote. Exibe formulário via shortcode para buscar por e-mail/CPF e gerar PDF.
 * Version: 1.8.0
 * Author: Você
 */

if (!defined('ABSPATH')) exit;

class Certificados_CSV_JSON {
   const CPT          = 'certificado';
   const META_KEY     = 'certificados_csv_json';
   const NONCE_ACTION = 'certificados_csv_json';
   const AJAX_ACTION  = 'certificados_csv_json_parse';

   // Ação pública para gerar PDF (admin-post)
   const PUBLIC_PDF_ACTION = 'certificados_csv_json_public_pdf';

   public function __construct() {
      // Admin: metabox principal + salvar
      add_action('add_meta_boxes', [$this, 'add_metabox']);
      add_action('save_post',      [$this, 'save_post']);

      // Admin: assets + ajax parse
      add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
      add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_parse_csv']);

      // Admin: metabox instruções shortcode
      add_action('add_meta_boxes', [$this, 'add_shortcode_help_metabox']);

      // Shortcode do formulário
      add_shortcode('certificado_form', [$this, 'shortcode_certificado_form']);

      // Handler público PDF
      add_action('admin_post_nopriv_' . self::PUBLIC_PDF_ACTION, [$this, 'handle_public_pdf']);
      add_action('admin_post_'        . self::PUBLIC_PDF_ACTION, [$this, 'handle_public_pdf']);
   }

   /* =========================================================
    * Metabox principal CSV/JSON (admin)
    * ========================================================= */
   public function add_metabox() {
      add_meta_box(
         'certificados_csv_json_metabox',
         'Certificados: CSV → JSON',
         [$this, 'render_metabox'],
         self::CPT,
         'normal',
         'high'
      );
   }

   public function enqueue_assets($hook) {
      $screen = function_exists('get_current_screen') ? get_current_screen() : null;
      if (!$screen || $screen->post_type !== self::CPT) return;
      if (!in_array($screen->base, ['post', 'post-new'], true)) return;

      // DataTables (CDN)
      wp_enqueue_style('datatables-css', 'https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css', [], '1.13.8');
      wp_enqueue_script('datatables-js', 'https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js', ['jquery'], '1.13.8', true);

      // Script inline
      wp_register_script('certificados-csv-json', '', ['jquery', 'datatables-js'], '1.8.0', true);
      wp_enqueue_script('certificados-csv-json');

      wp_localize_script('certificados-csv-json', 'CertCSVJSON', [
         'ajaxUrl' => admin_url('admin-ajax.php'),
         'nonce'   => wp_create_nonce(self::NONCE_ACTION),
         'action'  => self::AJAX_ACTION,
      ]);

      // NOWDOC: evita interpolação de $ do PHP no JS
$js = <<<'JS'
(function($){
   let dt = null;

   function safeJsonParse(str){
      try { return JSON.parse(str); } catch(e){ return []; }
   }

   function escHtml(s){
      return $('<div>').text(s == null ? '' : String(s)).html();
   }

   function normalizeRows(rows){
      if (!Array.isArray(rows)) return [];
      return rows.map(r => ({
         nome:  (r && r.nome)  ? String(r.nome)  : '',
         email: (r && r.email) ? String(r.email).trim().toLowerCase() : '',
         cpf:   (r && r.cpf)   ? String(r.cpf)   : ''
      })).filter(r => r.email);
   }

   function getCurrentRows(){
      return normalizeRows(safeJsonParse($('#certificados_csv_json_data').val() || '[]'));
   }

   function setHiddenJson(rows){
      $('#certificados_csv_json_data').val(JSON.stringify(normalizeRows(rows)));
   }

   // Mescla por email (novo sobrescreve antigo)
   function mergeByEmail(oldRows, newRows){
      const map = {};
      oldRows = normalizeRows(oldRows);
      newRows = normalizeRows(newRows);

      oldRows.forEach(r => { map[r.email] = r; });
      newRows.forEach(r => { map[r.email] = r; });

      return Object.keys(map).sort().map(email => map[email]);
   }

   function initOrUpdateTable(rows){
      rows = normalizeRows(rows);

      if (!dt) {
         dt = $('#cert_csv_json_table').DataTable({
            data: rows,
            dom: "<'certcsv-top'f>rt<'certcsv-bottom'lip<'certcsv-actions'>>",
            language: {
               decimal: ",",
               thousands: ".",
               processing: "Processando...",
               search: "Buscar:",
               lengthMenu: "Mostrar _MENU_ registros",
               info: "Mostrando de _START_ até _END_ de _TOTAL_ registros",
               infoEmpty: "Mostrando 0 até 0 de 0 registros",
               infoFiltered: "(filtrado de _MAX_ registros no total)",
               loadingRecords: "Carregando...",
               zeroRecords: "Nenhum registro encontrado",
               emptyTable: "Nenhum dado disponível na tabela",
               paginate: { first: "Primeiro", previous: "Anterior", next: "Próximo", last: "Último" },
               aria: { sortAscending: ": ativar para ordenar a coluna em ordem crescente", sortDescending: ": ativar para ordenar a coluna em ordem decrescente" }
            },
            columns: [
               {
                  data: null,
                  orderable: false,
                  searchable: false,
                  width: "30px",
                  render: function(data, type, row){
                     const email = row && row.email ? String(row.email) : '';
                     return '<input type="checkbox" class="cert_csv_row_check" data-email="' + escHtml(email) + '">';
                  }
               },
               { data: 'nome',  defaultContent: '' },
               { data: 'email', defaultContent: '' },
               { data: 'cpf',   defaultContent: '' }
            ],
            pageLength: 25,
            lengthMenu: [10,25,50,100,200,500],
            order: [[1,'asc']],
            initComplete: function(){
               $('.certcsv-actions').html(
                  '<button type="button" class="button button-secondary" id="cert_csv_delete_selected_btn" style="margin-left:20px;">Excluir selecionados</button>'
               );
            }
         });
      } else {
         dt.clear();
         dt.rows.add(rows);
         dt.draw();
      }

      $('#cert_csv_json_total').text(rows.length);
      $('#cert_csv_selected_total').text('0');
      $('#cert_csv_check_all').prop('checked', false);
   }

   function clearAll(){
      setHiddenJson([]);
      initOrUpdateTable([]);
   }

   // init
   $(function(){
      initOrUpdateTable(getCurrentRows());
   });

   // contador selecionados
   function updateSelectedCount(){
      const n = $('.cert_csv_row_check:checked').length;
      $('#cert_csv_selected_total').text(String(n));
   }

   $(document).on('change', '.cert_csv_row_check', updateSelectedCount);

   // selecionar todos visíveis
   $(document).on('change', '#cert_csv_check_all', function(){
      const checked = $(this).is(':checked');
      $('#cert_csv_json_table tbody .cert_csv_row_check').prop('checked', checked);
      updateSelectedCount();
   });

   // ao redesenhar, limpa check-all
   $('#cert_csv_json_table').on('draw.dt', function(){
      $('#cert_csv_check_all').prop('checked', false);
      updateSelectedCount();
   });

   // Processar CSV (merge)
   $(document).on('click', '#cert_csv_process_btn', function(e){
      e.preventDefault();

      const fileInput = document.getElementById('cert_csv_file');
      if (!fileInput || !fileInput.files || !fileInput.files[0]) {
         alert('Selecione um arquivo CSV.');
         return;
      }

      const fd = new FormData();
      fd.append('action', CertCSVJSON.action);
      fd.append('nonce', CertCSVJSON.nonce);
      fd.append('csv_file', fileInput.files[0]);

      $('#cert_csv_status').text('Processando...');

      $.ajax({
         url: CertCSVJSON.ajaxUrl,
         method: 'POST',
         data: fd,
         processData: false,
         contentType: false
      })
      .done(function(resp){
         if (!resp || !resp.success) {
            $('#cert_csv_status').text('');
            alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Erro ao processar CSV.');
            return;
         }

         const current  = getCurrentRows();
         const incoming = normalizeRows(resp.data.rows || []);
         const merged   = mergeByEmail(current, incoming);

         setHiddenJson(merged);
         initOrUpdateTable(merged);

         $('#cert_csv_status').text('OK (mesclado)');
      })
      .fail(function(){
         $('#cert_csv_status').text('');
         alert('Falha na requisição AJAX.');
      });
   });

   // Limpar tudo
   $(document).on('click', '#cert_csv_clear_btn', function(e){
      e.preventDefault();
      $('#cert_csv_file').val('');
      $('#cert_csv_status').text('');
      clearAll();
   });

   // Excluir selecionados
   $(document).on('click', '#cert_csv_delete_selected_btn', function(e){
      e.preventDefault();

      const emails = $('.cert_csv_row_check:checked').map(function(){
         return String($(this).data('email') || '').trim().toLowerCase();
      }).get().filter(Boolean);

      if (!emails.length) {
         alert('Selecione pelo menos 1 linha para excluir.');
         return;
      }

      if (!confirm('Excluir ' + emails.length + ' selecionado(s)?')) return;

      const current = getCurrentRows();
      const emailSet = new Set(emails);
      const filtered = current.filter(r => !emailSet.has(String(r.email).toLowerCase()));

      setHiddenJson(filtered);
      initOrUpdateTable(filtered);
   });

   // Adicionar linha manualmente
   $(document).on('click', '#cert_manual_add_btn', function(e){
      e.preventDefault();

      const nome  = String($('#cert_manual_nome').val() || '').trim();
      const email = String($('#cert_manual_email').val() || '').trim().toLowerCase();
      const cpfRaw = String($('#cert_manual_cpf').val() || '').trim();
      const cpf = cpfRaw ? cpfRaw.replace(/\D+/g, '') : '';

      if (!email) { alert('Informe o e-mail.'); return; }

      const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
      if (!emailOk) { alert('E-mail inválido.'); return; }

      const incoming = [{ nome: nome, email: email, cpf: cpf }];
      const current  = getCurrentRows();
      const merged   = mergeByEmail(current, incoming);

      setHiddenJson(merged);
      initOrUpdateTable(merged);

      $('#cert_manual_nome').val('');
      $('#cert_manual_email').val('');
      $('#cert_manual_cpf').val('');
   });

})(jQuery);
JS;

      wp_add_inline_script('certificados-csv-json', $js);
   }

   public function render_metabox($post) {
      wp_nonce_field(self::NONCE_ACTION, 'certificados_csv_json_nonce');

      $json = get_post_meta($post->ID, self::META_KEY, true);
      if (!is_string($json) || $json === '') $json = '[]';

      echo '<style>
      .certcsv-bottom{margin-top:20px;}
      .meta-box-sortables select{min-width:50px;}
      </style>';

      echo '<div style="margin:12px 0; padding:10px; background:#fff; border:1px solid #dcdcde; border-radius:6px;">';
      echo '<strong>Importar dados</strong>';
      echo '<p>Envie um CSV com colunas: <strong>nome</strong>, <strong>email</strong>, <strong>cpf</strong> (cpf opcional). Cada novo upload será <strong>mesclado</strong> ao JSON atual (por e-mail).</p>';
      echo '   <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:10px 0;">';
      echo '     <input type="file" id="cert_csv_file" accept=".csv,text/csv" />';
      echo '     <button type="button" class="button button-primary" id="cert_csv_process_btn">Importar</button>';
      echo '     <button type="button" class="button" id="cert_csv_clear_btn">Limpar tabela</button>';
      echo '     <span id="cert_csv_status" style="margin-left:6px;"></span>';
      echo '   </div>';
      echo '</div>';

      echo '<div style="margin:12px 0; padding:10px; background:#fff; border:1px solid #dcdcde; border-radius:6px;">';
      echo '  <strong>Adicionar manualmente</strong>';
      echo '  <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:8px;">';
      echo '    <input type="text" id="cert_manual_nome" class="regular-text" placeholder="Nome" style="flex:2; min-width:220px;">';
      echo '    <input type="email" id="cert_manual_email" class="regular-text" placeholder="E-mail" style="flex:2; min-width:220px;">';
      echo '    <input type="text" id="cert_manual_cpf" class="regular-text" placeholder="CPF (opcional)" style="flex:1; min-width:160px;">';
      echo '    <button type="button" class="button button-primary" id="cert_manual_add_btn">Adicionar</button>';
      echo '  </div>';
      echo '  <p style="margin:6px 0 0;"><small><b>Dica:</b> se o e-mail já existir, os dados de "nome" e "cpf" serão atualizados (comparação por e-mail).<br><b>Lembre-se:</b> Os dados só serão salvos ao clicar em "atualizar".</small></p>';
      echo '</div>';

      // hidden JSON
      echo '<input type="hidden" id="certificados_csv_json_data" name="certificados_csv_json_data" value="' . esc_attr($json) . '">';

      echo '<p><strong>Total:</strong> <span id="cert_csv_json_total">0</span> &nbsp; | &nbsp; <strong>Selecionados:</strong> <span id="cert_csv_selected_total">0</span></p>';

      echo '<table id="cert_csv_json_table" class="display" style="width:100%">';
      echo '  <thead><tr><th style="width:30px;"><input type="checkbox" id="cert_csv_check_all" title="Selecionar todos desta página"></th><th>Nome</th><th>E-mail</th><th>CPF</th></tr></thead>';
      echo '  <tbody></tbody>';
      echo '</table>';

      echo '<p style="margin-top:10px;"><small>Obs: O JSON só é salvo no post quando você clicar em <strong>Atualizar/Publicar</strong>.</small></p>';
   }

   public function save_post($post_id) {
      if (get_post_type($post_id) !== self::CPT) return;

      if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
      if (wp_is_post_revision($post_id)) return;
      if (!current_user_can('edit_post', $post_id)) return;

      // Salvar JSON
      if (isset($_POST['certificados_csv_json_nonce']) && wp_verify_nonce($_POST['certificados_csv_json_nonce'], self::NONCE_ACTION)) {
         $json = isset($_POST['certificados_csv_json_data']) ? (string) $_POST['certificados_csv_json_data'] : '[]';
         $decoded = json_decode(wp_unslash($json), true);

         if (is_array($decoded)) {
            $normalized = [];
            $seen = [];

            foreach ($decoded as $r) {
               $nome  = isset($r['nome'])  ? $this->normalize_person_text($r['nome']) : '';
               $email = isset($r['email']) ? sanitize_email($r['email']) : '';
               $cpf   = isset($r['cpf'])   ? preg_replace('/\D+/', '', (string)$r['cpf']) : '';

               if (!$email || !is_email($email)) continue;

               $key = strtolower($email);
               if (isset($seen[$key])) continue;
               $seen[$key] = true;

               $normalized[] = [
                  'nome'  => $this->normalize_person_text($nome),
                  'email' => strtolower($email),
                  'cpf'   => $cpf ?: '',
               ];
            }

            update_post_meta($post_id, self::META_KEY, wp_json_encode($normalized, JSON_UNESCAPED_UNICODE));
         } else {
            delete_post_meta($post_id, self::META_KEY);
         }
      }
   }

   public function ajax_parse_csv() {
      if (!current_user_can('edit_posts')) {
         wp_send_json_error(['message' => 'Sem permissão.'], 403);
      }

      $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
      if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
         wp_send_json_error(['message' => 'Nonce inválido.'], 403);
      }

      if (empty($_FILES['csv_file']['tmp_name'])) {
         wp_send_json_error(['message' => 'Arquivo CSV não enviado.'], 400);
      }

      $rows = $this->parse_csv($_FILES['csv_file']['tmp_name']);

      wp_send_json_success([
         'rows'  => $rows,
         'total' => count($rows),
      ]);
   }

   /* =========================================================
    * Metabox: instruções do shortcode (admin)
    * ========================================================= */
   public function add_shortcode_help_metabox() {
      add_meta_box(
         'certificados_shortcode_help_metabox',
         'Como usar o shortcode',
         [$this, 'render_shortcode_help_metabox'],
         self::CPT,
         'side',
         'low'
      );
   }

   public function render_shortcode_help_metabox($post) {
      $id = (int) $post->ID;

      $short_force   = '[certificado_form cert_id="' . $id . '"]';
      $short_email   = '[certificado_form cert_id="' . $id . '" mode="email"]';
      $short_cpf     = '[certificado_form cert_id="' . $id . '" mode="cpf"]';
      $short_both    = '[certificado_form cert_id="' . $id . '" mode="both"]';

      echo '<p><strong>Objetivo:</strong> exibir o formulário público (busca por <em>e-mail</em> e/ou <em>CPF</em>) e gerar o PDF.</p>';
      echo '<p><small>Agora você controla em qual página o formulário aparece apenas colocando o shortcode no conteúdo.</small></p>';
      echo '<hr>';

      echo '<p><strong>Forçar este certificado pelo ID</strong><br>';
      echo '<small>Use o JSON do certificado <strong>ID ' . $id . '</strong>:</small></p>';
      echo '<code style="display:block; padding:8px; background:#f6f7f7;">' . esc_html($short_force) . '</code>';

      echo '<p style="margin-top:10px;"><strong>Escolher o campo do formulário (mode)</strong><br>';
      echo '<small>Escolha quais campos aparecem no formulário e qual validação será exigida:</small></p>';

      echo '<code style="display:block; padding:8px; background:#f6f7f7; margin-bottom:6px;">' . esc_html($short_email) . '</code>';
      echo '<code style="display:block; padding:8px; background:#f6f7f7; margin-bottom:6px;">' . esc_html($short_cpf) . '</code>';
      echo '<code style="display:block; padding:8px; background:#f6f7f7;">' . esc_html($short_both) . '</code>';

      echo '<p style="margin-top:10px;"><small><strong>Fonte dos dados:</strong> o PDF é gerado comparando o e-mail/CPF com os registros salvos em <code>' . esc_html(self::META_KEY) . '</code>.</small></p>';
   }

   /* =========================================================
    * Shortcode: [certificado_form]
    * ========================================================= */
   public function shortcode_certificado_form($atts = [], $content = null) {
      $atts = shortcode_atts([
         'cert_id'           => '',        // ID do post "certificado"
         'title'             => 'Emitir certificado',
         'mode'              => 'both',    // both | email | cpf
      ], $atts, 'certificado_form');

      $cert_id = (int) $atts['cert_id'];
      if (!$cert_id || get_post_type($cert_id) !== self::CPT) {
         return '<p><strong>Erro:</strong> informe um <code>cert_id</code> válido do post type "certificado". Ex: <code>[certificado_form cert_id="123"]</code></p>';
      }

      $mode = strtolower(trim((string)$atts['mode']));
      if (!in_array($mode, ['both', 'email', 'cpf'], true)) {
         $mode = 'both';
      }

      $title = sanitize_text_field($atts['title']);

      return $this->render_public_form_html($cert_id, $title, $mode);
   }

   private function render_public_form_html($cert_id, $title = 'Emitir certificado', $mode = 'both') {
      $action = esc_url(admin_url('admin-post.php'));
      $nonce  = wp_create_nonce(self::NONCE_ACTION . '_public_pdf');

      $mode = strtolower(trim((string)$mode));
      if (!in_array($mode, ['both', 'email', 'cpf'], true)) $mode = 'both';

      $html  = '<div data-cert-form="1" style="margin:20px 0; padding:16px; border:1px solid #ddd; border-radius:10px;">';

      $status = isset($_GET['certificado_status']) ? sanitize_text_field($_GET['certificado_status']) : '';

      if ($status === 'not_found') {
         $html .= '<div style="margin-bottom:12px; padding:10px; background:#ffecec; border:1px solid #ffb3b3; border-radius:6px; color:#a40000;">';
         $html .= 'Certificado não encontrado.';
         $html .= '</div>';

         // Remove o parâmetro da URL para não manter a mensagem ao recarregar
         $html .= '<script>
            (function(){
               try {
                  var url = new URL(window.location.href);
                  url.searchParams.delete("certificado_status");
                  window.history.replaceState({}, "", url.toString());
               } catch(e){}
            })();
         </script>';
      }

      $html .= '<h3 style="margin:0 0 10px;">' . esc_html($title) . '</h3>';

      $html .= '<form method="post" action="' . $action . '" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">';
      $html .= '  <input type="hidden" name="action" value="' . esc_attr(self::PUBLIC_PDF_ACTION) . '">';
      $html .= '  <input type="hidden" name="cert_id" value="' . (int)$cert_id . '">';
      $html .= '  <input type="hidden" name="nonce" value="' . esc_attr($nonce) . '">';
      $html .= '  <input type="hidden" name="mode" value="' . esc_attr($mode) . '">';

      if ($mode === 'both' || $mode === 'email') {
         $html .= '  <div style="flex:1; min-width:240px;">';
         $html .= '    <label style="display:block; font-size:12px; margin-bottom:4px;">E-mail</label>';
         $html .= '    <input type="email" name="email" placeholder="seuemail@exemplo.com" style="width:100%; padding:10px;">';
         $html .= '  </div>';
      }

      if ($mode === 'both' || $mode === 'cpf') {
         $html .= '  <div style="flex:1; min-width:200px;">';
         $html .= '    <label style="display:block; font-size:12px; margin-bottom:4px;">CPF</label>';
         $html .= '    <input type="text" name="cpf" placeholder="Somente números" style="width:100%; padding:10px;">';
         $html .= '  </div>';
      }

      $html .= '  <div>';
      $html .= '    <button type="submit" style="padding:10px 16px; cursor:pointer;">Baixar PDF</button>';
      $html .= '  </div>';

      $html .= '</form>';

      if ($mode === 'email') {
         $html .= '<p style="margin:10px 0 0; font-size:12px; color:#555;">Preencha o <strong>e-mail</strong> para emitir o PDF.</p>';
      } elseif ($mode === 'cpf') {
         $html .= '<p style="margin:10px 0 0; font-size:12px; color:#555;">Preencha o <strong>CPF</strong> para emitir o PDF.</p>';
      } else {
         $html .= '<p style="margin:10px 0 0; font-size:12px; color:#555;">Preencha <strong>e-mail</strong> ou <strong>CPF</strong>. O PDF será emitido conforme o registro encontrado.</p>';
      }

      $html .= '</div>';

      return $html;
   }

   /* =========================================================
    * Handler público do PDF (admin-post)
    * ========================================================= */
   public function handle_public_pdf() {
      $cert_id = isset($_POST['cert_id']) ? (int) $_POST['cert_id'] : 0;
      $nonce   = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';

      $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
      $cpf   = isset($_POST['cpf']) ? preg_replace('/\D+/', '', (string)$_POST['cpf']) : '';

      $mode = isset($_POST['mode']) ? strtolower(trim((string)$_POST['mode'])) : 'both';
      if (!in_array($mode, ['both', 'email', 'cpf'], true)) $mode = 'both';

      if (!$cert_id || get_post_type($cert_id) !== self::CPT) {
         wp_die('Certificado inválido.');
      }

      if (!wp_verify_nonce($nonce, self::NONCE_ACTION . '_public_pdf')) {
         wp_die('Nonce inválido.');
      }

      // Validação conforme mode
      if ($mode === 'email' && !$email) {
         wp_die('Informe o e-mail.');
      }
      if ($mode === 'cpf' && !$cpf) {
         wp_die('Informe o CPF.');
      }
      if ($mode === 'both' && !$email && !$cpf) {
         wp_die('Informe e-mail ou CPF.');
      }

      $row = null;

      if (($mode === 'both' || $mode === 'email') && $email && is_email($email)) {
         $row = $this->find_row_by_email($cert_id, $email);
      }

      if (!$row && ($mode === 'both' || $mode === 'cpf') && $cpf !== '') {
         $row = $this->find_row_by_cpf($cert_id, $cpf);
      }

      if (!$row) {
         $redirect = wp_get_referer();
         if (!$redirect) {
            $redirect = home_url('/');
         }

         $redirect = add_query_arg('certificado_status', 'not_found', $redirect);
         wp_safe_redirect($redirect);
         exit;
      }

      // Se veio email, usa ele; se veio CPF, usa o email do registro encontrado
      $final_email = ($email && is_email($email))
         ? strtolower(trim($email))
         : (isset($row['email']) ? strtolower(trim((string)$row['email'])) : '');

      if (!$final_email || !is_email($final_email)) {
         wp_die('Não foi possível determinar o e-mail do certificado.');
      }

      $nome = isset($row['nome']) && $row['nome'] !== '' ? $row['nome'] : 'Participante';

      // Exibe somente o nome no PDF, conforme requisito.
      $lines = [$nome];

      $filename = 'certificado-' . sanitize_file_name($final_email) . '.pdf';
      $background = $this->get_certificate_background_jpeg($cert_id);
      $nameHeightPx = get_post_meta($cert_id, 'altura_do_nome_no_certificado', true);
      $nameColor = get_post_meta($cert_id, 'cor_do_nome_no_certificado', true);
      $pdf = $this->build_simple_pdf($lines, $background, $nameHeightPx, $nameColor);

      nocache_headers();
      header('Content-Type: application/pdf');
      header('Content-Disposition: attachment; filename="' . $filename . '"');
      header('Content-Length: ' . strlen($pdf));
      echo $pdf;
      exit;
   }

   private function get_json_array($cert_id) {
      $json = get_post_meta($cert_id, self::META_KEY, true);
      if (!is_string($json) || $json === '') return [];

      $arr = json_decode($json, true);
      if (!is_array($arr)) return [];

      $normalized = [];
      foreach ($arr as $r) {
         if (!is_array($r)) continue;

         $nome = isset($r['nome']) ? $this->normalize_person_text($r['nome']) : '';
         $email = isset($r['email']) ? strtolower(trim((string)$r['email'])) : '';
         $cpf = isset($r['cpf']) ? preg_replace('/\D+/', '', (string)$r['cpf']) : '';

         if (!$email || !is_email($email)) continue;

         $normalized[] = [
            'nome' => $nome,
            'email' => $email,
            'cpf' => $cpf ?: '',
         ];
      }

      return $normalized;
   }

   private function find_row_by_email($cert_id, $email) {
      $arr = $this->get_json_array($cert_id);
      $needle = strtolower(trim($email));
      foreach ($arr as $r) {
         if (!is_array($r)) continue;
         $em = isset($r['email']) ? strtolower(trim((string)$r['email'])) : '';
         if ($em && $em === $needle) return $r;
      }
      return null;
   }

   private function find_row_by_cpf($cert_id, $cpf) {
      $arr = $this->get_json_array($cert_id);
      $needle = preg_replace('/\D+/', '', (string)$cpf);
      foreach ($arr as $r) {
         if (!is_array($r)) continue;
         $c = isset($r['cpf']) ? preg_replace('/\D+/', '', (string)$r['cpf']) : '';
         if ($c && $c === $needle) return $r;
      }
      return null;
   }

   /* =========================================================
    * PDF simples (sem libs externas)
    * ========================================================= */
   private function build_simple_pdf(array $lines, $background = null, $nameHeightPx = null, $nameColor = null) {
      $pageWidth = 842;
      $pageHeight = 595;
      $fontSize = 22;
      $leading = 24;
      $defaultTopOffset = 125;
      $topOffset = is_numeric($nameHeightPx) ? (float)$nameHeightPx : $defaultTopOffset;
      $topOffset = max(0, min($pageHeight, $topOffset));
      $rgb = $this->parse_name_color_for_pdf($nameColor);

      $content = '';
      if (is_array($background) && !empty($background['data'])) {
         $content .= "q\n{$pageWidth} 0 0 {$pageHeight} 0 0 cm\n/Im1 Do\nQ\n";
      }

      $horizontalCorrectionPx = $pageWidth * 0.05;

      $content .= "BT\n/F1 {$fontSize} Tf\n";
      $content .= sprintf('%.4F %.4F %.4F rg' . "\n", $rgb[0], $rgb[1], $rgb[2]);
      foreach ($lines as $i => $line) {
         $safe = $this->normalize_utf8_text($line);

         // Helvetica Type1 no PDF simples suporta Win-1252.
         if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $safe);
            if ($converted !== false) {
               $safe = $converted;
            }
         } elseif (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($safe, 'Windows-1252', 'UTF-8');
            if (is_string($converted) && $converted !== '') {
               $safe = $converted;
            }
         }

         $safe = str_replace("\\", "\\\\", $safe);
         $safe = str_replace("(", "\\(", $safe);
         $safe = str_replace(")", "\\)", $safe);
         $safe = str_replace(["\r", "\n"], " ", $safe);
         $lineY = ($pageHeight - $topOffset) - ($i * $leading);
         $textWidth = $this->estimate_pdf_text_width($line, $fontSize);
         $lineX = (($pageWidth - $textWidth) / 2) + $horizontalCorrectionPx;
         $lineX = max(20, min($pageWidth - 20, $lineX));
         $content .= "1 0 0 1 " . round($lineX, 2) . " " . round($lineY, 2) . " Tm\n";
         $content .= "($safe) Tj\n";
      }
      $content .= "ET\n";

      $len = strlen($content);

      $objs = [];
      $objs[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
      $objs[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";

      $resources = '<< /Font << /F1 4 0 R >>';
      if (is_array($background) && !empty($background['data'])) {
         $resources .= ' /XObject << /Im1 6 0 R >>';
      }
      $resources .= ' >>';

      $objs[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Resources {$resources} /Contents 5 0 R >>\nendobj\n";
      $objs[] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
      $objs[] = "5 0 obj\n<< /Length $len >>\nstream\n$content\nendstream\nendobj\n";

      if (is_array($background) && !empty($background['data'])) {
         $imgData = $background['data'];
         $imgLen = strlen($imgData);
         $imgW = max(1, (int)($background['width'] ?? 1));
         $imgH = max(1, (int)($background['height'] ?? 1));
         $objs[] = "6 0 obj\n<< /Type /XObject /Subtype /Image /Width {$imgW} /Height {$imgH} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length {$imgLen} >>\nstream\n{$imgData}\nendstream\nendobj\n";
      }

      $pdf = "%PDF-1.4\n";
      $offsets = [0];
      foreach ($objs as $obj) {
         $offsets[] = strlen($pdf);
         $pdf .= $obj;
      }

      $xref_pos = strlen($pdf);
      $pdf .= "xref\n0 " . (count($objs) + 1) . "\n";
      $pdf .= "0000000000 65535 f \n";
      for ($i = 1; $i <= count($objs); $i++) {
         $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
      }

      $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\n";
      $pdf .= "startxref\n$xref_pos\n%%EOF";

      return $pdf;
   }

   private function estimate_pdf_text_width($text, $fontSize) {
      $text = (string)$text;
      $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
      $avgGlyphFactor = 0.52;
      return $length * ((float)$fontSize * $avgGlyphFactor);
   }

   private function parse_name_color_for_pdf($nameColor) {
      $default = [0, 0, 0];
      if (!is_string($nameColor)) return $default;

      $nameColor = trim($nameColor);
      if ($nameColor === '') return $default;

      if (preg_match('/^#?([0-9a-fA-F]{6})$/', $nameColor, $m)) {
         $hex = $m[1];
         return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
         ];
      }

      if (preg_match('/^rgb\s*\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/i', $nameColor, $m)) {
         return [
            min(255, max(0, (int)$m[1])) / 255,
            min(255, max(0, (int)$m[2])) / 255,
            min(255, max(0, (int)$m[3])) / 255,
         ];
      }

      if (preg_match('/^(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})$/', $nameColor, $m)) {
         return [
            min(255, max(0, (int)$m[1])) / 255,
            min(255, max(0, (int)$m[2])) / 255,
            min(255, max(0, (int)$m[3])) / 255,
         ];
      }

      return $default;
   }

   private function get_certificate_background_jpeg($cert_id) {
      $raw = get_post_meta($cert_id, 'imagem_do_certificado', true);
      if (!$raw) return null;

      $bytes = $this->read_background_image_bytes($raw);
      if (!is_string($bytes) || $bytes === '') return null;

      $info = @getimagesizefromstring($bytes);
      if (!is_array($info) || empty($info['mime'])) return null;

      $mime = strtolower((string)$info['mime']);
      if ($mime === 'image/jpeg') {
         return [
            'data' => $bytes,
            'width' => (int)($info[0] ?? 1),
            'height' => (int)($info[1] ?? 1),
         ];
      }

      if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
         return null;
      }

      $img = @imagecreatefromstring($bytes);
      if (!$img) return null;

      ob_start();
      imagejpeg($img, null, 90);
      $jpeg = (string)ob_get_clean();

      $out = [
         'data' => $jpeg,
         'width' => imagesx($img),
         'height' => imagesy($img),
      ];
      imagedestroy($img);

      return $out;
   }

   private function read_background_image_bytes($value) {
      $value = is_string($value) ? trim($value) : $value;
      if ($value === '' || $value === null) return null;

      if (is_numeric($value)) {
         $path = get_attached_file((int)$value);
         if ($path && is_readable($path)) {
            $data = @file_get_contents($path);
            if ($data !== false) return $data;
         }
      }

      if (!is_string($value) || $value === '') return null;

      if (filter_var($value, FILTER_VALIDATE_URL)) {
         $attId = function_exists('attachment_url_to_postid') ? (int) attachment_url_to_postid($value) : 0;
         if ($attId) {
            $path = get_attached_file($attId);
            if ($path && is_readable($path)) {
               $data = @file_get_contents($path);
               if ($data !== false) return $data;
            }
         }

         if (function_exists('wp_remote_get')) {
            $resp = wp_remote_get($value, ['timeout' => 10]);
            if (!is_wp_error($resp) && (int)wp_remote_retrieve_response_code($resp) === 200) {
               $body = wp_remote_retrieve_body($resp);
               if (is_string($body) && $body !== '') return $body;
            }
         }

         return null;
      }

      $paths = [$value, ABSPATH . ltrim($value, '/')];
      foreach ($paths as $path) {
         if ($path && is_readable($path)) {
            $data = @file_get_contents($path);
            if ($data !== false) return $data;
         }
      }

      return null;
   }
   private function normalize_utf8_text($text) {
      $text = is_scalar($text) ? (string)$text : '';

      if ($text === '') return '';

      // Se já for UTF-8 válido, apenas corrige possíveis sequências mojibake.
      if (preg_match('//u', $text)) {
         return $this->fix_common_mojibake($text);
      }

      if (function_exists('mb_convert_encoding')) {
         $converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252,ISO-8859-1');
         if (is_string($converted) && $converted !== '') {
            return $this->fix_common_mojibake($converted);
         }
      }

      if (function_exists('iconv')) {
         foreach (['Windows-1252', 'ISO-8859-1'] as $enc) {
            $converted = @iconv($enc, 'UTF-8//IGNORE', $text);
            if ($converted !== false && $converted !== '') {
               return $this->fix_common_mojibake($converted);
            }
         }
      }

      return $this->fix_common_mojibake($text);
   }


   /* =========================================================
    * CSV parsing
    * ========================================================= */
   private function parse_csv($filepath) {
      $result = [];

      $handle = fopen($filepath, 'r');
      if (!$handle) return $result;

      $firstLine = fgets($handle);
      if ($firstLine === false) {
         fclose($handle);
         return $result;
      }

      // Detecta delimitador
      $delims = [',', ';', "\t"];
      $bestDelim = ',';
      $bestCount = -1;

      foreach ($delims as $d) {
         $c = substr_count($firstLine, $d);
         if ($c > $bestCount) {
            $bestCount = $c;
            $bestDelim = $d;
         }
      }

      rewind($handle);

      $header = fgetcsv($handle, 0, $bestDelim);
      if (!$header) {
         fclose($handle);
         return $result;
      }

      $headerNorm = array_map(function($h){
         $h = $this->to_utf8(trim((string)$h));
         $h = $this->strtolower_safe($h);
         $h = preg_replace('/\s+/', ' ', $h);
         return $h;
      }, $header);

      $hasHeader = $this->looks_like_header($headerNorm);

      if (!$hasHeader) {
         $mapped = $this->map_row($header);
         if ($mapped) $result[] = $mapped;
      }

      while (($row = fgetcsv($handle, 0, $bestDelim)) !== false) {
         if ($this->row_is_empty($row)) continue;

         $mapped = $this->map_row($row, $hasHeader ? $headerNorm : null);
         if ($mapped) $result[] = $mapped;
      }

      fclose($handle);

      // dedup por email dentro do CSV
      $uniq = [];
      $out = [];

      foreach ($result as $r) {
         $email = strtolower(trim($r['email'] ?? ''));
         if (!$email) continue;
         if (isset($uniq[$email])) continue;
         $uniq[$email] = true;
         $out[] = $r;
      }

      return $out;
   }

   private function row_is_empty($row) {
      foreach ((array)$row as $v) {
         if (trim((string)$v) !== '') return false;
      }
      return true;
   }

   private function looks_like_header($headerNorm) {
      $joined = implode(' ', (array)$headerNorm);
      return (strpos($joined, 'email') !== false)
         || (strpos($joined, 'e-mail') !== false)
         || (strpos($joined, 'nome') !== false)
         || (strpos($joined, 'cpf') !== false);
   }

   private function map_row($row, $headerNorm = null) {
      $row = array_map(function($v){
         return trim($this->to_utf8((string)$v));
      }, (array)$row);

      $nome = '';
      $email = '';
      $cpf = '';

      if (is_array($headerNorm)) {
         foreach ($headerNorm as $i => $h) {
            $val = $row[$i] ?? '';
            if ($val === '') continue;

            if (strpos($h, 'email') !== false || strpos($h, 'e-mail') !== false) $email = $val;
            elseif (strpos($h, 'cpf') !== false) $cpf = $val;
            elseif (strpos($h, 'nome') !== false) $nome = $val;
         }

         // fallback por posição
         if (!$nome  && isset($row[0])) $nome  = $row[0];
         if (!$email && isset($row[1])) $email = $row[1];
         if (!$cpf   && isset($row[2])) $cpf   = $row[2] ?? '';
      } else {
         $nome  = $row[0] ?? '';
         $email = $row[1] ?? '';
         $cpf   = $row[2] ?? '';
      }

      $email = strtolower(trim($email));
      if (!$email || !is_email($email)) return null;

      $cpf = preg_replace('/\D+/', '', (string)$cpf);

      return [
         'nome'  => $this->normalize_person_text($nome),
         'email' => $email,
         'cpf'   => $cpf ?: '',
      ];
   }

   private function normalize_person_text($text) {
      $text = is_scalar($text) ? trim((string)$text) : '';
      if ($text === '') return '';

      $text = $this->to_utf8($text);
      $text = $this->fix_common_mojibake($text);

      return sanitize_text_field($text);
   }

   private function fix_common_mojibake($text) {
      $text = (string)$text;
      if ($text === '') return '';

      $replacements = [
         'Ã¡' => 'á', 'Ã ' => 'à', 'Ã¢' => 'â', 'Ã£' => 'ã', 'Ã¤' => 'ä',
         'Ã‰' => 'É', 'Ã©' => 'é', 'Ã¨' => 'è', 'Ãª' => 'ê', 'Ã«' => 'ë',
         'Ã­' => 'í', 'Ã¬' => 'ì', 'Ã®' => 'î', 'Ã¯' => 'ï',
         'Ã“' => 'Ó', 'Ã³' => 'ó', 'Ã²' => 'ò', 'Ã´' => 'ô', 'Ãµ' => 'õ', 'Ã¶' => 'ö',
         'Ãš' => 'Ú', 'Ãº' => 'ú', 'Ã¹' => 'ù', 'Ã»' => 'û', 'Ã¼' => 'ü',
         'Ã‡' => 'Ç', 'Ã§' => 'ç', 'Ã‘' => 'Ñ', 'Ã±' => 'ñ',
         'â€“' => '–', 'â€”' => '—', 'â€˜' => '‘', 'â€™' => '’', 'â€œ' => '“', 'â€' => '”',
      ];

      $text = strtr($text, $replacements);
      $text = str_replace('Â', '', $text);

      return $text;
   }

   private function to_utf8($str) {
      if ($str === '') return $str;
      if (function_exists('seems_utf8') && seems_utf8($str)) return $str;
      if (!function_exists('mb_convert_encoding')) return $str;
      $converted = @mb_convert_encoding($str, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
      return $converted ?: $str;
   }

   private function strtolower_safe($str) {
      if (function_exists('mb_strtolower')) {
         return mb_strtolower((string)$str);
      }

      return strtolower((string)$str);
   }
}

new Certificados_CSV_JSON();
