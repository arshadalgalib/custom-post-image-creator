<?php
/**
 * Facebook Graph API v22.0 integration.
 *
 * • Connect / disconnect a Facebook Page
 * • Post photo with smart caption to Page
 * • Auto-comment with post link
 * • Rate-limit handling
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPIC_Facebook_API {

    /** @var string Graph API base */
    const GRAPH_URL = 'https://graph.facebook.com/v22.0/';

    public function __construct() {
        add_action( 'wp_ajax_cpic_fb_connect',    array( $this, 'ajax_connect' ) );
        add_action( 'wp_ajax_cpic_fb_disconnect',  array( $this, 'ajax_disconnect' ) );
        add_action( 'wp_ajax_cpic_fb_share',       array( $this, 'ajax_share' ) );
        add_action( 'wp_ajax_cpic_fb_get_pages',   array( $this, 'ajax_get_pages' ) );
        add_action( 'wp_ajax_cpic_fb_select_page', array( $this, 'ajax_select_page' ) );
    }

    /* ─── AJAX: get pages list ────────────────────────── */

    public function ajax_get_pages() {
        cpic()->security->verify_ajax_request();

        $user_token = isset( $_POST['user_token'] ) ? sanitize_text_field( wp_unslash( $_POST['user_token'] ) ) : '';
        if ( empty( $user_token ) ) {
            wp_send_json_error( array( 'message' => __( 'User access token is required.', 'custom-post-image-creator' ) ) );
        }

        // First validate the token by calling /me to distinguish user vs page token.
        $me_url  = self::GRAPH_URL . 'me?' . http_build_query( array( 'fields' => 'id,name', 'access_token' => $user_token ), '', '&', PHP_QUERY_RFC3986 );
        $me_resp = wp_remote_get( $me_url, array( 'timeout' => 15 ) );

        if ( is_wp_error( $me_resp ) ) {
            wp_send_json_error( array( 'message' => $me_resp->get_error_message() ) );
        }

        $me_body = json_decode( wp_remote_retrieve_body( $me_resp ), true );

        if ( isset( $me_body['error'] ) ) {
            $err_code = $me_body['error']['code'] ?? 0;
            $err_msg  = $me_body['error']['message'] ?? '';
            // Error 190 = expired/invalid token.
            if ( 190 === (int) $err_code ) {
                wp_send_json_error( array( 'message' => __( 'Token is expired or invalid. Generate a new User Access Token from the Graph API Explorer with <code>pages_show_list</code> and <code>pages_manage_posts</code> permissions.', 'custom-post-image-creator' ) ) );
            }
            wp_send_json_error( array( 'message' => $err_msg ) );
        }

        // If /me returns a page (type=page), user supplied a Page Access Token.
        if ( isset( $me_body['category'] ) || ( isset( $me_body['id'] ) && ! isset( $me_body['name'] ) ) ) {
            wp_send_json_error( array(
                'message' => sprintf(
                    /* translators: 1: Graph API Explorer URL */
                    __( 'This is a <strong>Page Access Token</strong>, not a User Access Token. You need a <a href="%1$s" target="_blank">User Access Token</a> with <code>pages_show_list</code> permission. Steps: 1) Open the Graph API Explorer. 2) Select your app. 3) Click "Generate Access Token". 4) Grant <code>pages_show_list</code> and <code>pages_manage_posts</code> permissions. 5) Copy the token and paste it above.', 'custom-post-image-creator' ),
                    'https://developers.facebook.com/tools/explorer/?method=GET&path=me%2Faccounts'
                ),
            ) );
        }

        // Token looks like a user token — fetch managed pages.
        $url = self::GRAPH_URL . 'me/accounts?' . http_build_query( array(
            'access_token' => $user_token,
            'fields'       => 'id,name,access_token',
            'limit'        => 100,
        ) );

        $response = wp_remote_get( $url, array( 'timeout' => 30 ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => $response->get_error_message() ) );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            $err_code = $body['error']['code'] ?? 0;
            $err_msg  = $body['error']['message'] ?? '';

            if ( 100 === (int) $err_code && strpos( $err_msg, 'accounts' ) !== false ) {
                wp_send_json_error( array(
                    'message' => sprintf(
                        /* translators: 1: Graph API Explorer URL */
                        __( 'Your token is missing the <code>pages_show_list</code> permission. <a href="%1$s" target="_blank">Generate a new token</a> and make sure to grant <code>pages_show_list</code> and <code>pages_manage_posts</code> permissions.', 'custom-post-image-creator' ),
                        'https://developers.facebook.com/tools/explorer/?method=GET&path=me%2Faccounts'
                    ),
                ) );
            }

            wp_send_json_error( array( 'message' => $err_msg ) );
        }

        wp_send_json_success( array( 'pages' => $body['data'] ?? array() ) );
    }

    /* ─── AJAX: select page ───────────────────────────── */

    public function ajax_select_page() {
        cpic()->security->verify_ajax_request();

        $page_id    = sanitize_text_field( wp_unslash( $_POST['page_id'] ?? '' ) );
        $page_name  = sanitize_text_field( wp_unslash( $_POST['page_name'] ?? '' ) );
        $page_token = sanitize_text_field( wp_unslash( $_POST['page_token'] ?? '' ) );

        if ( empty( $page_id ) || empty( $page_token ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing page details.', 'custom-post-image-creator' ) ) );
        }

        cpic()->settings->set( 'cpic_fb_page_id', $page_id );
        cpic()->settings->set( 'cpic_fb_page_name', $page_name );
        cpic()->settings->set( 'cpic_fb_page_token', $page_token );

        wp_send_json_success( array(
            'message'   => __( 'Facebook Page connected.', 'custom-post-image-creator' ),
            'page_name' => $page_name,
        ) );
    }

    /* ─── AJAX: connect (alias for select_page via OAuth flow) ─ */

    public function ajax_connect() {
        $this->ajax_select_page();
    }

    /* ─── AJAX: disconnect ────────────────────────────── */

    public function ajax_disconnect() {
        cpic()->security->verify_ajax_request();

        cpic()->settings->set( 'cpic_fb_page_id', '' );
        cpic()->settings->set( 'cpic_fb_page_name', '' );
        cpic()->settings->set( 'cpic_fb_page_token', '' );

        wp_send_json_success( array( 'message' => __( 'Disconnected from Facebook.', 'custom-post-image-creator' ) ) );
    }

    /* ─── AJAX: share single post ─────────────────────── */

    public function ajax_share() {
        cpic()->security->verify_ajax_request();

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id || ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid post or permission denied.', 'custom-post-image-creator' ) ), 403 );
        }

        $artifact = CPIC_Canvas_Artifacts::current_for_post( $post_id );
        if ( is_wp_error( $artifact ) ) {
            wp_send_json_error( array( 'message' => $artifact->get_error_message() ) );
        }
        // Keep the canonical PNG: Telegram, Facebook, retries, and downloads reuse this exact file.
        $result = $this->share( $post_id, $artifact['path'] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
    }

    /* ─── Job-aware publishing ───────────────────────── */

    /**
     * Publish a generated photocard to the connected Facebook Page.
     *
     * This is the automation path: it assumes the image already exists on
     * disk and returns the Facebook post id so the job pipeline can add the
     * first comment as its own, persisted stage.
     *
     * @param int    $post_id    WP Post ID.
     * @param string $image_path Absolute path to a generated PNG.
     * @param string $caption    Caption text (fallback to automatic).
     * @return array|WP_Error Array with facebook_post_id / facebook_comment_id.
     */
    public function publish_photo( $post_id, $image_path, $caption = '' ) {
        $page_id    = cpic()->settings->get( 'cpic_fb_page_id' );
        $page_token = cpic()->settings->get( 'cpic_fb_page_token' );

        if ( empty( $page_id ) || empty( $page_token ) ) {
            return new WP_Error( 'fb_not_connected', __( 'Facebook Page is not connected.', 'custom-post-image-creator' ) );
        }

        if ( ! function_exists( 'curl_init' ) || ! class_exists( 'CURLFile' ) ) {
            return new WP_Error( 'fb_curl_missing', __( 'The cURL PHP extension is required for Facebook sharing.', 'custom-post-image-creator' ) );
        }

        if ( empty( $image_path ) || ! file_exists( $image_path ) ) {
            return new WP_Error( 'fb_image_missing', __( 'Photocard image not found.', 'custom-post-image-creator' ) );
        }

        if ( '' === $caption ) {
            $caption = $this->build_caption( $post_id );
        }

        // Write-once guard: never post the same WP post twice, even if the
        // job is re-run (cron retry, manual re-run, legacy queue overlap).
        $existing_fb_id = get_post_meta( $post_id, 'cpic_fb_post_id', true );
        if ( $existing_fb_id ) {
            return array(
                'facebook_post_id'    => $existing_fb_id,
                'facebook_comment_id' => (string) get_post_meta( $post_id, 'cpic_fb_comment_id', true ),
            );
        }

        $lock = $this->acquire_publish_lock( $post_id );
        if ( is_wp_error( $lock ) ) {
            return $lock;
        }

        $posted = $this->upload_photo_to_page( $post_id, $page_id, $page_token, $image_path, $caption );

        if ( is_wp_error( $posted ) ) {
            // A transport failure is ambiguous: Facebook may have created
            // the photo even though no response reached WordPress. Retain
            // the lock in that case so a retry cannot create a duplicate.
            if ( ! in_array( $posted->get_error_code(), array( 'fb_curl_error', 'fb_no_photo_id' ), true ) ) {
                $this->release_publish_lock( $post_id );
            }
            return $posted;
        }

        // Single photo post → the timeline post id is what we persist.
        $facebook_post_id = $posted['post_id'] ?: $posted['photo_id'];

        update_post_meta( $post_id, 'cpic_last_shared_to_facebook', current_time( 'mysql' ) );
        update_post_meta( $post_id, 'cpic_fb_post_id', $facebook_post_id );
        $this->release_publish_lock( $post_id );

        $result = array(
            'facebook_post_id'    => $facebook_post_id,
            'facebook_comment_id' => '',
        );

        return $result;
    }

    /**
     * Create one public Page timeline post with an attached photo.
     *
     * Facebook can store a direct /photos upload only in the Photos tab. To
     * reliably create the visible newspaper-style timeline story, upload the
     * image as unpublished media first, then create ONE /feed post that
     * attaches it and carries the caption. The media record is not a second
     * published post.
     *
     * @param int    $post_id    WordPress post ID.
     * @param string $page_id    Facebook Page ID.
     * @param string $page_token Page access token.
     * @param string $image_path Absolute path to image file.
     * @param string $caption    Photo caption.
     * @return array|WP_Error array( 'photo_id', 'post_id' ) on success.
     */
    private function upload_photo_to_page( $post_id, $page_id, $page_token, $image_path, $caption ) {
        // Preserve the uploaded media ID before creating the feed story. If
        // Facebook rejects the feed request, a later retry reuses this media
        // instead of uploading another copy of the photocard.
        $photo_id = (string) get_post_meta( $post_id, 'cpic_fb_pending_media_id', true );

        if ( ! $photo_id ) {
            $url = self::GRAPH_URL . $page_id . '/photos';

            $ch = curl_init( $url );
            curl_setopt( $ch, CURLOPT_POST, true );
            curl_setopt( $ch, CURLOPT_POSTFIELDS, array(
                'access_token' => $page_token,
                'source'       => new \CURLFile( $image_path, 'image/png', basename( $image_path ) ),
                // This is media only. The following /feed request is the
                // one and only public timeline post.
                'published'    => 0,
            ) );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
            curl_setopt( $ch, CURLOPT_TIMEOUT, 120 );
            curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 30 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
            $raw_response = curl_exec( $ch );
            $http_code    = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $curl_error   = curl_error( $ch );
            curl_close( $ch );

            if ( $curl_error ) {
                CPIC_Logger::log( 'facebook', __( 'cURL error.', 'custom-post-image-creator' ), $curl_error );
                return new WP_Error( 'fb_curl_error', $curl_error );
            }

            $resp_body = json_decode( $raw_response, true );

            if ( 403 === $http_code || ( isset( $resp_body['error']['code'] ) && (int) $resp_body['error']['code'] === 4 ) ) {
                CPIC_Logger::log( 'facebook', __( 'Rate limited.', 'custom-post-image-creator' ), $resp_body );
                return new WP_Error( 'fb_rate_limit', __( 'Facebook rate limit reached. Please wait and try again.', 'custom-post-image-creator' ) );
            }

            if ( isset( $resp_body['error'] ) ) {
                CPIC_Logger::log( 'facebook', __( 'Graph API error.', 'custom-post-image-creator' ), $resp_body['error'] );
                return new WP_Error( 'fb_api_error', $resp_body['error']['message'] ?? __( 'Facebook API error.', 'custom-post-image-creator' ) );
            }

            $photo_id = $resp_body['id'] ?? '';
            if ( empty( $photo_id ) ) {
                return new WP_Error( 'fb_no_photo_id', __( 'Facebook did not return a photo id.', 'custom-post-image-creator' ) );
            }

            update_post_meta( $post_id, 'cpic_fb_pending_media_id', $photo_id );
        }

        $timeline_post_id = $this->create_timeline_post( $page_id, $page_token, $photo_id, $caption );
        if ( is_wp_error( $timeline_post_id ) ) {
            return $timeline_post_id;
        }

        delete_post_meta( $post_id, 'cpic_fb_pending_media_id' );

        CPIC_Logger::log( 'facebook', __( 'Photo attached to public page timeline post.', 'custom-post-image-creator' ), array(
            'page_id'  => $page_id,
            'photo_id' => $photo_id,
            'post_id'  => $timeline_post_id,
        ) );

        return array(
            'photo_id' => $photo_id,
            'post_id'  => $timeline_post_id,
        );
    }

    /** Create the sole public Page feed story for an already-uploaded photo. */
    private function create_timeline_post( $page_id, $page_token, $photo_id, $caption ) {
        $response = wp_remote_post( self::GRAPH_URL . $page_id . '/feed', array(
            'timeout' => 60,
            'body'    => array(
                'access_token'       => $page_token,
                'message'            => $caption,
                'attached_media[0]'  => wp_json_encode( array( 'media_fbid' => (string) $photo_id ) ),
                'published'          => 'true',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            CPIC_Logger::log( 'facebook', __( 'Timeline post request failed.', 'custom-post-image-creator' ), $response->get_error_message() );
            return new WP_Error( 'fb_curl_error', $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['error'] ) ) {
            CPIC_Logger::log( 'facebook', __( 'Timeline post API error.', 'custom-post-image-creator' ), $body['error'] );
            return new WP_Error( 'fb_api_error', $body['error']['message'] ?? __( 'Facebook timeline post error.', 'custom-post-image-creator' ) );
        }

        if ( empty( $body['id'] ) ) {
            return new WP_Error( 'fb_no_photo_id', __( 'Facebook did not return a timeline post id.', 'custom-post-image-creator' ) );
        }

        return (string) $body['id'];
    }

    /**
     * Post the article URL as a comment on the Facebook post (or photo).
     * Safe to call repeatedly – a failed attempt can be retried without
     * re-posting (the caller only retries when comment id is empty).
     *
     * @param string $target_id  Facebook post or photo id.
     * @param int    $post_id    WP Post ID.
     * @param string $page_token Page access token.
     * @return string|WP_Error Comment id on success.
     */
    public function post_comment( $target_id, $post_id, $page_token = '' ) {
        if ( empty( $page_token ) ) {
            $page_token = cpic()->settings->get( 'cpic_fb_page_token' );
        }

        if ( empty( $target_id ) || empty( $page_token ) ) {
            return new WP_Error( 'fb_comment_missing', __( 'Missing post or token for comment.', 'custom-post-image-creator' ) );
        }

        $comment_msg = cpic()->settings->get( 'cpic_comment_message' );
        $permalink   = get_permalink( $post_id );
        $message     = $comment_msg . "\n" . $permalink;

        $url = self::GRAPH_URL . $target_id . '/comments';

        $response = wp_remote_post( $url, array(
            'timeout' => 30,
            'body'    => array(
                'access_token' => $page_token,
                'message'      => $message,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            CPIC_Logger::log( 'facebook', __( 'Comment request failed.', 'custom-post-image-creator' ), $response->get_error_message() );
            return new WP_Error( 'fb_comment_error', $response->get_error_message() );
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        if ( $http_code < 200 || $http_code >= 300 ) {
            $error_body = wp_remote_retrieve_body( $response );
            CPIC_Logger::log( 'facebook', __( 'Comment request returned a non-success HTTP status.', 'custom-post-image-creator' ), array(
                'http_code' => $http_code,
                'body'      => $error_body,
            ) );
            return new WP_Error( 'fb_comment_api_error', __( 'Facebook rejected the article-link comment.', 'custom-post-image-creator' ) );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['error'] ) ) {
            CPIC_Logger::log( 'facebook', __( 'Comment API error.', 'custom-post-image-creator' ), $body['error'] );
            return new WP_Error( 'fb_comment_api_error', $body['error']['message'] ?? __( 'Facebook comment error.', 'custom-post-image-creator' ) );
        }

        if ( empty( $body['id'] ) ) {
            CPIC_Logger::log( 'facebook', __( 'Facebook returned no comment id.', 'custom-post-image-creator' ), $body );
            return new WP_Error( 'fb_comment_no_id', __( 'Facebook did not confirm the article-link comment.', 'custom-post-image-creator' ) );
        }

        return (string) $body['id'];
    }

    /**
     * Add the first article-link comment at most once.
     *
     * This is deliberately separate from photo creation so the job can
     * persist the photo ID before attempting a comment. A lost comment
     * response is treated as ambiguous and left for human verification,
     * never retried blindly into a duplicate comment.
     */
    public function post_first_comment_once( $facebook_post_id, $post_id, $page_token = '' ) {
        $existing_comment_id = get_post_meta( $post_id, 'cpic_fb_comment_id', true );
        if ( $existing_comment_id ) {
            return (string) $existing_comment_id;
        }

        $lock = wp_generate_uuid4();
        if ( ! add_post_meta( $post_id, 'cpic_fb_comment_lock', $lock, true ) ) {
            return new WP_Error(
                'fb_comment_in_progress',
                __( 'The first Facebook comment is already in progress or requires verification after a connection failure.', 'custom-post-image-creator' )
            );
        }

        $comment_id = $this->post_comment( $facebook_post_id, $post_id, $page_token );
        if ( is_wp_error( $comment_id ) || empty( $comment_id ) ) {
            // A Graph API response is definitive, so it is safe to release
            // the reservation. Transport failures and missing IDs are
            // ambiguous and must remain locked to prevent duplicates.
            if ( is_wp_error( $comment_id ) && 'fb_comment_error' !== $comment_id->get_error_code() && 'fb_comment_no_id' !== $comment_id->get_error_code() ) {
                delete_post_meta( $post_id, 'cpic_fb_comment_lock' );
            }
            return is_wp_error( $comment_id )
                ? $comment_id
                : new WP_Error( 'fb_comment_no_id', __( 'Facebook did not return a comment id.', 'custom-post-image-creator' ) );
        }

        update_post_meta( $post_id, 'cpic_fb_comment_id', $comment_id );
        delete_post_meta( $post_id, 'cpic_fb_comment_lock' );
        CPIC_Logger::log( 'facebook', __( 'Article-link comment confirmed.', 'custom-post-image-creator' ), array(
            'post_id'             => $post_id,
            'facebook_post_id'    => $facebook_post_id,
            'facebook_comment_id' => $comment_id,
        ) );
        return (string) $comment_id;
    }

    /* ─── Public share method ─────────────────────────── */

    /**
     * Post a photocard to the connected Facebook Page.
     *
     * @param int    $post_id    WP Post ID.
     * @param string $image_path Optional pre-generated image path.
     * @return array|WP_Error
     */
    public function share( $post_id, $image_path = '', $overrides = array() ) {
        if ( empty( $image_path ) || ! is_file( $image_path ) ) {
            $artifact = CPIC_Canvas_Artifacts::current_for_post( $post_id );
            if ( is_wp_error( $artifact ) ) { return $artifact; }
            $image_path = $artifact['path'];
        }
        $posted = $this->publish_photo( $post_id, $image_path );

        if ( is_wp_error( $posted ) ) {
            return $posted;
        }

        // Keep the comment as a separate, persisted operation. The main job
        // pipeline does this in Stage 4; this branch preserves that behaviour
        // for the manual-share entry point without re-uploading the photo.
        if ( cpic()->settings->get( 'cpic_auto_comment' ) && empty( $posted['facebook_comment_id'] ) ) {
            $comment_id = $this->post_first_comment_once( $posted['facebook_post_id'], $post_id );
            if ( is_wp_error( $comment_id ) ) {
                return $comment_id;
            }
        }

        return array(
            'message'  => __( 'Posted to Facebook.', 'custom-post-image-creator' ),
            'photo_id' => $posted['facebook_post_id'],
        );
    }

    /**
     * Atomically reserve Facebook publishing for a WordPress post.
     *
     * The unique-meta insert is a database-level compare-and-set, unlike a
     * read-then-write meta check which races under cron/manual submissions.
     */
    private function acquire_publish_lock( $post_id ) {
        $token = wp_generate_uuid4();
        if ( add_post_meta( $post_id, 'cpic_fb_publish_lock', $token, true ) ) {
            return $token;
        }

        return new WP_Error(
            'fb_publish_in_progress',
            __( 'Facebook publishing is already in progress or requires verification after a connection failure.', 'custom-post-image-creator' )
        );
    }

    /** Release a publish reservation after a confirmed result. */
    private function release_publish_lock( $post_id ) {
        delete_post_meta( $post_id, 'cpic_fb_publish_lock' );
    }

    /* ─── Smart caption builder ───────────────────────── */

    /**
     * Build the main photo caption.
     *
     * Structure:
     *   [≈50-word summary]
     *   \n\nবিস্তারিত পড়ুন কমেন্টে
     *
     * @param int $post_id Post ID.
     * @return string
     */
    public function build_caption( $post_id ) {
        $summary = cpic()->admin_ui->get_post_summary( $post_id );
        $title   = get_the_title( $post_id );

        $include_headline = false;
        $include_summary  = true;
        $template = cpic()->category_mapper->resolve_template_for_post( $post_id );
        if ( $template && ! empty( $template['config']['caption'] ) && is_array( $template['config']['caption'] ) ) {
            $caption_config   = $template['config']['caption'];
            $include_headline = ! empty( $caption_config['include_headline'] );
            $include_summary  = array_key_exists( 'include_first_paragraph', $caption_config )
                ? ! empty( $caption_config['include_first_paragraph'] )
                : true;
        }

        // Bengali-safe word count (str_word_count only handles ASCII).
        $word_count = count( preg_split( '/\s+/u', trim( $summary ), -1, PREG_SPLIT_NO_EMPTY ) );
        if ( $word_count < 20 ) {
            // Pad with the title if needed.
            $title = get_the_title( $post_id );
            $summary = $title . ' — ' . $summary;
        }

        $summary = trim( strip_tags( $summary ) );

        $parts = array();
        if ( $include_headline && '' !== trim( $title ) ) {
            $parts[] = trim( $title );
        }
        if ( $include_summary && '' !== $summary ) {
            $parts[] = $summary;
        }

        // Keep the existing call-to-action even if both content switches are
        // disabled, so the caption remains a valid, intentional post.
        $caption = implode( "\n\n", $parts );
        $caption = '' !== $caption ? $caption . "\n\n" : '';
        $caption .= 'বিস্তারিত পড়ুন কমেন্টে';

        // Enforce Facebook's 5000-character limit.
        if ( $this->utf8_length( $caption ) > 5000 ) {
            $suffix  = "\n\nবিস্তারিত পড়ুন কমেন্টে";
            $allowed = max( 0, 5000 - $this->utf8_length( $suffix ) - 1 );
            $body    = implode( "\n\n", $parts );
            $caption = $this->utf8_slice( $body, $allowed ) . '…' . $suffix;
        }

        return $caption;
    }

    /** Count Unicode code points without requiring the optional mbstring extension. */
    private function utf8_length( $value ) {
        if ( function_exists( 'mb_strlen' ) ) { return mb_strlen( $value, 'UTF-8' ); }
        if ( preg_match_all( '/./us', (string) $value, $matches ) ) { return count( $matches[0] ); }
        return strlen( (string) $value );
    }

    /** Truncate on grapheme boundaries while respecting a code-point limit. */
    private function utf8_slice( $value, $length ) {
        $length = max( 0, (int) $length );
        if ( preg_match_all( '/\\X/u', (string) $value, $matches ) ) {
            $result = '';
            $used = 0;
            foreach ( $matches[0] as $grapheme ) {
                $points = preg_match_all( '/./us', $grapheme, $point_matches );
                if ( false === $points || $used + $points > $length ) { break; }
                $result .= $grapheme;
                $used += $points;
            }
            return $result;
        }
        if ( function_exists( 'mb_substr' ) ) { return mb_substr( $value, 0, $length, 'UTF-8' ); }
        return substr( (string) $value, 0, $length );
    }

}
