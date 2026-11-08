<?php
/**
 * Facebook OAuth - popup-based authorization flow.
 *
 * CSRF state uses a transient (not wp nonce directly) because Facebook
 * truncates the state parameter at underscores.
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPIC_Facebook_OAuth {

    const GRAPH_URL = 'https://graph.facebook.com/v22.0/';
    const SCOPES    = 'pages_show_list,pages_read_engagement,pages_read_user_content,pages_manage_posts,pages_manage_engagement';

    public function __construct() {
        add_action( 'admin_post_cpic_fb_oauth_start', array( $this, 'handle_start' ) );
        add_action( 'admin_post_cpic_fb_oauth_callback', array( $this, 'handle_callback' ) );
        add_action( 'admin_post_cpic_fb_oauth_select', array( $this, 'handle_select_page' ) );
        add_action( 'wp_ajax_cpic_fb_oauth_status', array( $this, 'ajax_status' ) );
    }

    /* --- Helpers -------------------------------------------------- */

    private static function transient_key( $token ) {
        return 'cpic_fb_oauth_' . md5( $token );
    }

    private static function create_state_token() {
        $token = wp_generate_password( 12, false );
        $nonce = wp_create_nonce( 'cpic_fb_oauth' );
        set_transient( self::transient_key( $token ), $nonce, HOUR_IN_SECONDS );
        return $token;
    }

    private static function verify_and_consume_state( $token ) {
        $key = self::transient_key( $token );
        $nonce = get_transient( $key );
        delete_transient( $key );
        if ( ! $nonce ) {
            return false;
        }
        return wp_verify_nonce( $nonce, 'cpic_fb_oauth' );
    }

    /* --- OAuth Start --------------------------------------------- */

    public function handle_start() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'custom-post-image-creator' ) );
        }

        $app_id = cpic()->settings->get( 'cpic_fb_app_id' );
        if ( empty( $app_id ) ) {
            wp_die( esc_html__( 'Please enter your Facebook App ID in the Dashboard first.', 'custom-post-image-creator' ) );
        }

        $redirect_uri = add_query_arg( 'action', 'cpic_fb_oauth_callback', admin_url( 'admin-post.php' ) );
        $state        = self::create_state_token();

        $oauth_url = 'https://www.facebook.com/v22.0/dialog/oauth?'
            . http_build_query( array(
                'client_id'     => $app_id,
                'redirect_uri'  => $redirect_uri,
                'response_type' => 'code',
                'scope'         => self::SCOPES,
                'state'         => $state,
            ) );

        header( 'Content-Type: text/html; charset=utf-8' );
        ?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Connecting to Facebook...</title></head>
<body style="font-family:-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f0f0f1">
<div style="text-align:center">
<div style="width:40px;height:40px;border:4px solid #ddd;border-top-color:#2271b1;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 16px"></div>
<style>@keyframes spin{to{transform:rotate(360deg)}}</style>
<p style="color:#333">Redirecting to Facebook...</p>
</div>
<script>window.location.href=<?php echo wp_json_encode( $oauth_url ); ?>;</script>
</body>
</html>
<?php
        exit;
    }

    /* --- OAuth Callback ------------------------------------------ */

    public function handle_callback() {
        // Start output buffering so nothing leaks before our HTML.
        if ( ! ob_start() ) {
            // ob_start failed — output directly.
        }

        // Output a minimal safety page first. If WordPress/PHP crashes later,
        // the browser at least sees this instead of a blank page.
        $safety_sent = false;
        $safety_html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Facebook OAuth</title>'
            . '<style>body{font-family:-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f0f0f1}'
            . '.box{background:#fff;border-radius:12px;padding:40px;text-align:center;max-width:440px;width:90%;box-shadow:0 4px 20px rgba(0,0,0,.1)}'
            . '.err .icon{font-size:48px;color:#dc3545;margin-bottom:16px}'
            . '.ok .icon{font-size:48px;color:#28a745;margin-bottom:16px}'
            . '.msg{font-size:15px;color:#555;margin:0 0 24px;line-height:1.5}'
            . '.btn{background:#2271b1;color:#fff;border:none;padding:10px 28px;border-radius:6px;font-size:14px;cursor:pointer}'
            . '</style></head><body>';

        try {
            nocache_headers();

            if ( ! current_user_can( 'manage_options' ) ) {
                $this->show( false, 'Permission denied.' );
                return;
            }

            $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

            if ( empty( $state ) || ! self::verify_and_consume_state( $state ) ) {
                $this->show( false, 'Security verification failed. Please try again.' );
                return;
            }

            if ( isset( $_GET['error'] ) ) {
                $error = sanitize_text_field( wp_unslash( $_GET['error_description'] ?? $_GET['error'] ) );
                $this->show( false, $error );
                return;
            }

            $code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
            if ( empty( $code ) ) {
                $this->show( false, 'No authorization code received from Facebook.' );
                return;
            }

            $user_token = $this->exchange_code_for_token( $code );
            if ( is_wp_error( $user_token ) ) {
                $this->show( false, $user_token->get_error_message() );
                return;
            }

            $pages = $this->get_pages_for_token( $user_token );
            if ( is_wp_error( $pages ) ) {
                $this->show( false, $pages->get_error_message() );
                return;
            }
            if ( empty( $pages ) ) {
                $this->show( false, 'No Facebook Pages found. Make sure you are an admin of at least one Page and granted the required permissions.' );
                return;
            }

            if ( 1 === count( $pages ) ) {
                $page = $pages[0];
                $this->store_page_credentials( $page['id'], $page['name'], $page['access_token'] );
                $this->show( true, $page['name'] );
                return;
            }

            $this->render_page_selection( $pages );

        } catch ( \Throwable $e ) {
            $this->show( false, 'Unexpected error: ' . $e->getMessage() );
        }
    }

    /* --- Simple result output ------------------------------------ */

    private function show( $success, $message ) {
        ob_end_clean();
        $cls = $success ? 'ok' : 'err';
        $icon = $success ? '&#10004;' : '&#10008;';
        $title = $success ? 'Facebook Connected!' : 'Connection Failed';
        $connected = $success ? 'true' : 'false';
        ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Facebook Connection</title>
<style>
body{font-family:-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f0f0f1}
.box{background:#fff;border-radius:12px;padding:40px;text-align:center;max-width:440px;width:90%;box-shadow:0 4px 20px rgba(0,0,0,.1)}
.icon{font-size:48px;margin-bottom:16px}
.ok .icon{color:#28a745}
.err .icon{color:#dc3545}
.title{font-size:20px;font-weight:600;margin:0 0 10px}
.msg{font-size:15px;color:#555;margin:0 0 24px;line-height:1.5;word-break:break-word}
.btn{background:#2271b1;color:#fff;border:none;padding:10px 28px;border-radius:6px;font-size:14px;cursor:pointer}
.btn:hover{background:#135e96}
</style>
</head>
<body>
<div class="box <?php echo $cls; ?>">
<div class="icon"><?php echo $icon; ?></div>
<p class="title"><?php echo esc_html( $title ); ?></p>
<p class="msg"><?php echo esc_html( $message ); ?></p>
<button class="btn" onclick="window.close();">Close Window</button>
</div>
<script>
if (window.opener) {
    window.opener.postMessage({ cpic_fb_connected: <?php echo $connected; ?> }, '*');
}
</script>
</body>
</html>
<?php
        exit;
    }

    /* --- Page Selection ------------------------------------------ */

    public function handle_select_page() {
        try {
            if ( ! current_user_can( 'manage_options' ) ) {
                $this->show( false, 'Permission denied.' );
                return;
            }
            if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cpic_fb_oauth_select' ) ) {
                $this->show( false, 'Security verification failed.' );
                return;
            }

            $page_id    = isset( $_POST['page_id'] ) ? sanitize_text_field( wp_unslash( $_POST['page_id'] ) ) : '';
            $page_token = isset( $_POST['page_token'] ) ? sanitize_text_field( wp_unslash( $_POST['page_token'] ) ) : '';
            $page_name  = isset( $_POST['page_name'] ) ? sanitize_text_field( wp_unslash( $_POST['page_name'] ) ) : '';

            if ( empty( $page_id ) || empty( $page_token ) ) {
                $this->show( false, 'Missing page selection.' );
                return;
            }

            $this->store_page_credentials( $page_id, $page_name, $page_token );
            $this->show( true, $page_name );
        } catch ( \Throwable $e ) {
            $this->show( false, $e->getMessage() );
        }
    }

    /* --- AJAX: Connection Status ---------------------------------- */

    public function ajax_status() {
        cpic()->security->verify_ajax_request();

        $page_name = cpic()->settings->get( 'cpic_fb_page_name' );
        $page_id   = cpic()->settings->get( 'cpic_fb_page_id' );

        wp_send_json_success( array(
            'connected' => ! empty( $page_name ) && ! empty( $page_id ),
            'page_name' => $page_name,
            'page_id'   => $page_id,
        ) );
    }

    /* --- Token Exchange ------------------------------------------ */

    private function exchange_code_for_token( $code ) {
        $app_id     = cpic()->settings->get( 'cpic_fb_app_id' );
        $app_secret = cpic()->settings->get( 'cpic_fb_app_secret' );

        if ( empty( $app_id ) || empty( $app_secret ) ) {
            return new WP_Error( 'fb_missing', 'Facebook App ID and App Secret are required.' );
        }

        $response = wp_remote_get( self::GRAPH_URL . 'oauth/access_token?' . http_build_query( array(
            'client_id'     => $app_id,
            'client_secret' => $app_secret,
            'redirect_uri'  => add_query_arg( 'action', 'cpic_fb_oauth_callback', admin_url( 'admin-post.php' ) ),
            'code'          => $code,
        ) ), array( 'timeout' => 30 ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'fb_req', $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            return new WP_Error( 'fb_token', $body['error']['message'] ?? 'Token exchange failed.' );
        }

        return $body['access_token'] ?? '';
    }

    private function get_pages_for_token( $user_token ) {
        $response = wp_remote_get( self::GRAPH_URL . 'me/accounts?' . http_build_query( array(
            'access_token' => $user_token,
            'fields'       => 'id,name,access_token',
            'limit'        => 100,
        ) ), array( 'timeout' => 30 ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'fb_pages', $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            return new WP_Error( 'fb_pages', $body['error']['message'] ?? 'Failed to fetch pages.' );
        }

        return $body['data'] ?? array();
    }

    private function store_page_credentials( $page_id, $page_name, $page_token ) {
        cpic()->settings->set( 'cpic_fb_page_id', $page_id );
        cpic()->settings->set( 'cpic_fb_page_name', $page_name );
        cpic()->settings->set( 'cpic_fb_page_token', $page_token );
    }

    /* --- Render: Page Selection ----------------------------------- */

    private function render_page_selection( $pages ) {
        ob_end_clean();
        ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Select Facebook Page</title>
<style>
body{font-family:-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f0f0f1}
.box{background:#fff;border-radius:12px;padding:32px;max-width:480px;width:90%;box-shadow:0 4px 20px rgba(0,0,0,.1)}
.title{font-size:18px;font-weight:600;margin:0 0 6px;text-align:center}
.sub{font-size:13px;color:#666;margin:0 0 20px;text-align:center}
.pages{list-style:none;padding:0;margin:0 0 20px}
.page{display:flex;align-items:center;gap:12px;padding:12px 16px;border:2px solid #e0e0e0;border-radius:8px;margin-bottom:8px;cursor:pointer;transition:all .15s}
.page:hover{border-color:#2271b1;background:#f0f7ff}
.page.sel{border-color:#2271b1;background:#e8f0fe}
.page-radio{margin:0}
.page-name{font-weight:500;font-size:14px}
.page-id{font-size:12px;color:#888}
.btn-row{text-align:center}
.btn{background:#2271b1;color:#fff;border:none;padding:10px 32px;border-radius:6px;font-size:14px;cursor:pointer;font-weight:500}
.btn:hover{background:#135e96}
.btn:disabled{background:#ccc;cursor:not-allowed}
</style>
</head>
<body>
<div class="box">
<p class="title">Choose a Facebook Page</p>
<p class="sub">Select which page to connect for auto-publishing.</p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=cpic_fb_oauth_select' ) ); ?>">
    <?php wp_nonce_field( 'cpic_fb_oauth_select' ); ?>
    <ul class="pages">
    <?php foreach ( $pages as $page ) : ?>
        <li class="page" data-token="<?php echo esc_attr( $page['access_token'] ); ?>" data-name="<?php echo esc_attr( $page['name'] ); ?>">
            <input type="radio" name="page_id" value="<?php echo esc_attr( $page['id'] ); ?>" class="page-radio">
            <div>
                <div class="page-name"><?php echo esc_html( $page['name'] ); ?></div>
                <div class="page-id">ID: <?php echo esc_html( $page['id'] ); ?></div>
            </div>
        </li>
    <?php endforeach; ?>
    </ul>
    <input type="hidden" name="page_token" id="sel-token" value="">
    <input type="hidden" name="page_name" id="sel-name" value="">
    <div class="btn-row">
        <button type="submit" class="btn" id="btn-confirm" disabled>Connect This Page</button>
    </div>
</form>
</div>
<script>
(function(){
    var items = document.querySelectorAll('.page');
    var ti = document.getElementById('sel-token');
    var ni = document.getElementById('sel-name');
    var btn = document.getElementById('btn-confirm');
    items.forEach(function(it){
        it.addEventListener('click', function(){
            items.forEach(function(i){ i.classList.remove('sel'); });
            it.classList.add('sel');
            it.querySelector('input[type=radio]').checked = true;
            ti.value = it.getAttribute('data-token');
            ni.value = it.getAttribute('data-name');
            btn.disabled = false;
        });
    });
})();
</script>
</body>
</html>
<?php
        exit;
    }
}
