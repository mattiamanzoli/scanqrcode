<?php
/*
Plugin Name: WP QR → Seatable Bridge
Description: Pagina "sender" (scanner QR su telefono) che invia l'ID ad una pagina "receiver" (desktop) in ascolto. La receiver reindirizza al link pubblico del record Seatable. Include impostazioni, REST API e shortcodes.
Version: 1.1.0
Author: ChatGPT + Mattia
License: GPLv2 or later
*/

if (!defined('ABSPATH')) { exit; }

class WP_QR_Seatable_Bridge {
    const OPT_KEY = 'qrseat_options';
    const NS = 'qrseat/v1';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::ensure_directories(); // Crea le cartelle se non esistono
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }
    
    // Funzione per creare le directory in public/
    private static function ensure_directories() {
        $plugin_dir = plugin_dir_path(__FILE__);
        if (!is_dir($plugin_dir . 'public/css')) {
            mkdir($plugin_dir . 'public/css', 0755, true);
        }
        if (!is_dir($plugin_dir . 'public/js')) {
            mkdir($plugin_dir . 'public/js', 0755, true);
        }
    }

    public function init() {
        // Defaults
        add_option(self::OPT_KEY, array(
            'base_template' => 'https://example.com/records/{id}',
            'target'        => '_self',
            'polling_ms'    => 1200,
            'keep_seconds'  => 900
        ));

        // Admin
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));

        // REST
        add_action('rest_api_init', array($this, 'register_routes'));

        // Shortcodes
        add_shortcode('qr_sender', array($this, 'shortcode_sender'));
        add_shortcode('qr_receiver', array($this, 'shortcode_receiver'));

        // Assets
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_assets'));
    }

    /* ===================== Admin ===================== */
    public function admin_menu() {
        add_options_page(
            'QR → Seatable Bridge',
            'QR → Seatable Bridge',
            'manage_options',
            'qrseat-bridge',
            array($this, 'settings_page')
        );
    }

    public function register_settings() {
        register_setting(self::OPT_KEY, self::OPT_KEY, array($this, 'validate_options'));

        add_settings_section('qrseat_main', 'Impostazioni generali', '__return_false', self::OPT_KEY);

        add_settings_field('base_template', 'Base URL (con {id})', array($this, 'field_base_template'), self::OPT_KEY, 'qrseat_main');
        add_settings_field('target',        'Target redirect', array($this, 'field_target'), self::OPT_KEY, 'qrseat_main');
        add_settings_field('polling_ms',    'Intervallo polling (ms)', array($this, 'field_polling_ms'), self::OPT_KEY, 'qrseat_main');
        add_settings_field('keep_seconds',  'TTL messaggi (secondi)', array($this, 'field_keep_seconds'), self::OPT_KEY, 'qrseat_main');
    }

    public function validate_options($in) {
        $out = array();
        $out['base_template'] = isset($in['base_template']) ? sanitize_text_field($in['base_template']) : 'https://example.com/records/{id}';
        $t = isset($in['target']) ? sanitize_text_field($in['target']) : '_self';
        $out['target'] = in_array($t, array('_self','_blank'), true) ? $t : '_self';
        $out['polling_ms'] = max(400, intval($in['polling_ms'] ?? 1200));
        $out['keep_seconds'] = max(60, intval($in['keep_seconds'] ?? 900));
        return $out;
    }

    public function field_base_template() {
        $o = get_option(self::OPT_KEY);
        printf('<input type="text" name="%s[base_template]" value="%s" size="60" />', esc_attr(self::OPT_KEY), esc_attr($o['base_template'] ?? ''));
        echo '<p class="description">Usa <code>{id}</code> come segnaposto. Es: <code>https://seatable.tuo-dominio/view/row/{id}</code> oppure <code>{id}</code> se il QR contiene già il link finale.</p>';
    }

    public function field_target() {
        $o = get_option(self::OPT_KEY);
        $val = $o['target'] ?? '_self';
        echo '<select name="'.esc_attr(self::OPT_KEY).'[target]">';
        printf('<option value="_self" %s>Stessa scheda (_self)</option>', selected($val, '_self', false));
        printf('<option value="_blank" %s>Nuova scheda (_blank)</option>', selected($val, '_blank', false));
        echo '</select>';
    }

    public function field_polling_ms() {
        $o = get_option(self::OPT_KEY);
        $val = intval($o['polling_ms'] ?? 1200);
        printf('<input type="number" min="400" step="100" name="%s[polling_ms]" value="%d" />', esc_attr(self::OPT_KEY), $val);
        echo '<p class="description">Intervallo di polling della pagina receiver (desktop). 800–1500ms è un buon compromesso.</p>';
    }

    public function field_keep_seconds() {
        $o = get_option(self::OPT_KEY);
        $val = intval($o['keep_seconds'] ?? 900);
        printf('<input type="number" min="60" step="30" name="%s[keep_seconds]" value="%d" />', esc_attr(self::OPT_KEY), $val);
        echo '<p class="description">Tempo di conservazione dell’ultimo messaggio per sessione.</p>';
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>QR → Seatable Bridge</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPT_KEY);
                do_settings_sections(self::OPT_KEY);
                submit_button();
                ?>
            </form>
            <hr/>
            <h2>Shortcodes</h2>
            <p><code>[qr_receiver]</code> — pagina in ascolto (desktop). Accetta attributi opzionali:<br/>
            <code>base</code>, <code>target</code>, <code>polling</code>, <code>autogen</code>. Esempio:<br/>
            <code>[qr_receiver base="https://seatable.tuo/view/row/{id}" target="_self" polling="1200" autogen="1"]</code></p>
            <p><code>[qr_sender]</code> — pagina di scansione (telefono). Accetta attributi opzionali:<br/>
            <code>mode</code> (= <code>param</code> | <code>raw</code>), <code>redirect</code> (0/1), <code>base</code> (per redirect sul telefono, se attivo). Esempio:<br/>
            <code>[qr_sender mode="param" redirect="0"]</code></p>
            <p><strong>Flusso pairing consigliato:</strong> apri la pagina receiver su desktop. Se non c’è il parametro <code>?session=</code>, la pagina lo genera e lo aggiunge all’URL. **Scansiona il QR Code di pairing** mostrato dal Receiver per aprire la pagina Sender sul telefono con la sessione pre-caricata. Quando scannerizzi un QR, la receiver riceve l’ID e reindirizza al link pubblico Seatable.</p>
        </div>
        <?php
    }

    /* ===================== REST API ===================== */

    public function register_routes() {
        register_rest_route(self::NS, '/send', array(
            'methods'  => 'POST',
            'callback' => array($this, 'route_send'),
            'permission_callback' => '__return_true',
            'args' => array(
                'session' => array('required' => true, 'type' => 'string'),
                'id'      => array('required' => true, 'type' => 'string'),
            ),
        ));

        register_rest_route(self::NS, '/next', array(
            'methods'  => 'GET',
            'callback' => array($this, 'route_next'),
            'permission_callback' => '__return_true',
            'args' => array(
                'session' => array('required' => true, 'type' => 'string'),
                'since'   => array('required' => false, 'type' => 'integer', 'default' => 0),
            ),
        ));
    }

    private function normalize_session($s) {
        $s = sanitize_text_field($s);
        $s = preg_replace('/[^A-Za-z0-9_\-]/', '', $s);
        return substr($s, 0, 64);
    }

    private function get_key($session) {
        return 'qrseat_' . $session;
    }

    public function route_send($req) {
        $session = $this->normalize_session($req->get_param('session'));
        $id_raw  = $req->get_param('id');
        if (!$session || $id_raw === null) {
            return new WP_REST_Response(array('ok'=>false,'error'=>'bad_request'), 400);
        }

        $o = get_option(self::OPT_KEY);
        $ttl = max(60, intval($o['keep_seconds'] ?? 900));

        $key = $this->get_key($session);
        $state = get_transient($key);
        if (!is_array($state)) { $state = array('ver'=>0); }

        $state['ver']  = intval($state['ver']) + 1;
        $state['id']   = sanitize_text_field($id_raw);
        $state['time'] = time();

        set_transient($key, $state, $ttl);

        return new WP_REST_Response(array('ok'=>true, 'ver'=>$state['ver']), 200);
    }

    public function route_next($req) {
        $session = $this->normalize_session($req->get_param('session'));
        $since = intval($req->get_param('since'));
        if (!$session) {
            return new WP_REST_Response(array('ok'=>false,'error'=>'bad_request'), 400);
        }
        $key = $this->get_key($session);
        $state = get_transient($key);
        if (!is_array($state)) {
            return new WP_REST_Response(array('ok'=>true, 'ver'=>0), 200);
        }
        if (intval($state['ver']) > $since) {
            return new WP_REST_Response(array('ok'=>true, 'ver'=>intval($state['ver']), 'id'=>$state['id'], 'time'=>$state['time']), 200);
        }
        return new WP_REST_Response(array('ok'=>true, 'ver'=>intval($state['ver'])), 200);
    }

    /* ===================== Shortcodes & Assets ===================== */

    public function maybe_enqueue_assets() {
        if (is_admin() || !is_singular()) return;
        global $post;
        if (!$post) return;
        $content = $post->post_content ?? '';

        $has_sender = has_shortcode($content, 'qr_sender');
        $has_receiver = has_shortcode($content, 'qr_receiver');
        
        if ($has_sender || $has_receiver) {
            wp_enqueue_style('qrseat-style', plugin_dir_url(__FILE__) . 'public/css/qrseat.css', array(), '1.1.0');
        }

        if ($has_sender) {
            // Vue + vue-qrcode-reader UMD da CDN
            wp_enqueue_script('vue-global', 'https://unpkg.com/vue@3/dist/vue.global.prod.js', array(), null, true);
            wp_enqueue_script('vue-qrcode-reader-umd', 'https://unpkg.com/vue-qrcode-reader/dist/vue-qrcode-reader.umd.js', array('vue-global'), null, true);

            wp_enqueue_script('qrseat-sender', plugin_dir_url(__FILE__) . 'public/js/sender-umd.js', array('vue-global','vue-qrcode-reader-umd'), '1.1.0', true);
            wp_localize_script('qrseat-sender', 'QRSEAT_REST', array(
                'base' => esc_url_raw( rest_url( self::NS ) )
            ));
        }

        if ($has_receiver) {
            // Nuova dipendenza per la generazione di QR Code (CDN)
            wp_enqueue_script('qrcodejs', 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js', array(), '1.0.0', true);
            
            wp_enqueue_script('qrseat-receiver', plugin_dir_url(__FILE__) . 'public/js/receiver.js', array('qrcodejs'), '1.1.0', true);
            wp_localize_script('qrseat-receiver', 'QRSEAT_REST', array(
                'base' => esc_url_raw( rest_url( self::NS ) )
            ));
        }
    }
    
    public function shortcode_sender($atts=array()) {
        $defaults = array(
            'mode'     => 'param', // "param" (estrae ?id=...) o "raw" (tutto il contenuto)
            'redirect' => '0',     // se 1, reindirizza anche il telefono al link base
            'base'     => ''       // se redirect=1 e base è vuoto usa l'impostazione globale
        );
        $a = shortcode_atts($defaults, $atts, 'qr_sender');

        $o = get_option(self::OPT_KEY);
        $base_template = $o['base_template'] ?? 'https://example.com/records/{id}';
        
        $mode = in_array($a['mode'], array('param','raw'), true) ? $a['mode'] : 'param';
        $redirect = $a['redirect'] === '1' ? '1' : '0';
        $sender_base = $a['base'] !== '' ? $a['base'] : $base_template;

        ob_start();
        ?>
        <div class="qrseat-card">
          <h3>QR Sender (Telefono)</h3>
          <div id="qrseat-sender"
               data-rest="<?php echo esc_attr( rest_url(self::NS) ); ?>"
               data-mode="<?php echo esc_attr($mode); ?>"
               data-redirect="<?php echo esc_attr($redirect); ?>"
               data-base="<?php echo esc_attr($sender_base); ?>"
               >
            </div>
          <p class="qrseat-note">Assicurati che questa pagina sia sotto HTTPS per permettere l'accesso alla fotocamera. Dopo la scansione, l'ID sarà inviato al desktop.</p>
        </div>
        <?php
        return ob_get_clean();
    }

    public function shortcode_receiver($atts=array()) {
        $o = get_option(self::OPT_KEY);
        $defaults = array(
            'base'    => $o['base_template'] ?? 'https://example.com/records/{id}',
            'target'  => $o['target'] ?? '_self',
            'polling' => strval( intval($o['polling_ms'] ?? 1200) ),
            'autogen' => '1' // se non c'è ?session= genera una sessione
        );
        $a = shortcode_atts($defaults, $atts, 'qr_receiver');

        $base = sanitize_text_field($a['base']);
        $target = in_array($a['target'], array('_self','_blank'), true) ? $a['target'] : '_self';
        $polling = max(400, intval($a['polling']));

        ob_start();
        ?>
        <div class="qrseat-card">
          <h3>QR Receiver (Desktop in ascolto)</h3>
          <div id="qrseat-receiver"
               data-rest="<?php echo esc_attr( rest_url(self::NS) ); ?>"
               data-base="<?php echo esc_attr($base); ?>"
               data-target="<?php echo esc_attr($target); ?>"
               data-polling="<?php echo esc_attr($polling); ?>"
               data-autogen="<?php echo esc_attr($a['autogen'] === '0' ? '0':'1'); ?>"
               >
            </div>
          <div class="qrseat-help">
            <p>1. **Sessione Attiva:** <code><span id="qrseat-session-label">—</span></code> <button class="qrseat-copy-btn" data-target="qrseat-session-label">Copia Sessione</button></p>
            
            <div id="qrseat-pairing-qr">
                <h4>Scansiona per il Pairing (Telefono)</h4>
                <p class="qrseat-note">Usa la fotocamera del tuo telefono per aprire la pagina Sender con la sessione pre-caricata.</p>
                <div id="qrseat-qrcode-target"></div>
                <p><a href="#" id="qrseat-sender-url" target="_blank" rel="noopener">Link Sender (fallback)</a> <button class="qrseat-copy-btn" data-target="qrseat-sender-url" data-raw-text="1">Copia URL</button></p>
            </div>
            
            <p>2. **Stato della Connessione:** <span id="qrseat-status-indicator">Inizializzazione...</span></p>
            
            <h4>Log e Debug Live</h4>
            <div id="qrseat-log">Nessun evento registrato.</div>
          </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

add_action('plugins_loaded', array('WP_QR_Seatable_Bridge','instance'));
