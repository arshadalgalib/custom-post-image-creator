<?php
/**
 * Server-side image generation using Imagick.
 *
 * Generates a 1080×1080 (or custom size) photocard layering:
 *   Background → Featured Image → Title → Date → Logo → Caption
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPIC_Image_Generator {

    public function __construct() {
        add_action( 'wp_ajax_cpic_generate_image', array( $this, 'ajax_generate' ) );
    }

    /* ─── AJAX endpoint ───────────────────────────────── */

    public function ajax_generate() {
        cpic()->security->verify_ajax_request();

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id || ! get_post( $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid post.', 'custom-post-image-creator' ) ) );
        }

        // Use template system when possible.
        $tpl = cpic()->category_mapper->resolve_template_for_post( $post_id );
        if ( $tpl && ! empty( $tpl['config'] ) ) {
            $result = $this->generate_from_template( $post_id, $tpl['config'] );
        } else {
            $result = $this->generate( $post_id );
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
    }

    /* ─── Template-driven generation ──────────────────── */

    /**
     * Generate a photocard from a template config.
     *
     * Conceptually: generate( $post_id, $template_config ).
     *
     * @param int   $post_id         Post ID.
     * @param array $template_config Template configuration array.
     * @return array|WP_Error Array with 'url' and 'path' keys, or WP_Error.
     */
    public function generate_from_template( $post_id, $template_config = array() ) {
        $renderer = cpic()->renderer;
        return $renderer->render_for_post( $post_id, $template_config );
    }

    /**
     * Generate a preview photocard from a template config using sample data.
     *
     * @param array $template_config Template configuration array.
     * @param array $data            Optional preview data overrides.
     * @return array|WP_Error
     */
    public function generate_template_preview( $template_config = array(), $data = array() ) {
        $renderer = cpic()->renderer;
        $sample   = wp_parse_args( $data, CPIC_Template_Manager::sample_preview_data() );
        return $renderer->render( $sample, $template_config, 0 );
    }

    /* ─── Public generate method ──────────────────────── */

    /**
     * Generate a photocard image for a post (legacy global-settings path).
     *
     * @param int   $post_id  The post ID.
     * @param array $overrides Optional settings overrides.
     * @return array|WP_Error  Array with 'url' and 'path' keys, or WP_Error.
     */
    public function generate( $post_id, $overrides = array() ) {
        if ( ! class_exists( 'Imagick' ) ) {
            CPIC_Security::log( 'imagick', 'Imagick extension not loaded.' );
            return new WP_Error( 'imagick_missing', __( 'Imagick PHP extension is required.', 'custom-post-image-creator' ) );
        }

        $s = wp_parse_args( $overrides, cpic()->settings->get_all() );

        $canvas_w = (int) $s['cpic_canvas_width'];
        $canvas_h = (int) $s['cpic_canvas_height'];

        try {
            $canvas = new Imagick();
            $canvas->newImage( $canvas_w, $canvas_h, new ImagickPixel( '#1a1a2e' ) );
            $canvas->setImageFormat( 'png' );

            // High-quality rendering settings.
            $canvas->setResolution( 150, 150 );
            $canvas->setImageCompressionQuality( 0 );
            $canvas->setOption( 'png:compression-level', '0' );
            $canvas->setImageDepth( 16 );
            $canvas->setImageBackgroundColor( new ImagickPixel( 'transparent' ) );

            /* ── 1. Background ────────────────────── */
            $bg_url = '';
            // Use saved active background first.
            if ( ! empty( $s['cpic_active_background'] ) ) {
                $bg_url = $s['cpic_active_background'];
            } else {
                $bg_images = $s['cpic_background_images'];
                if ( is_array( $bg_images ) && ! empty( $bg_images ) ) {
                    $bg_url = $bg_images[0];
                }
            }

            if ( $bg_url ) {
                $bg_path = $this->url_to_path( $bg_url );
                if ( $bg_path && file_exists( $bg_path ) ) {
                    $bg = new Imagick( $bg_path );
                    $bg->setResolution( 150, 150 );
                    $bg->resizeImage( $canvas_w, $canvas_h, Imagick::FILTER_LANCZOS, 1 );
                    $canvas->compositeImage( $bg, Imagick::COMPOSITE_OVER, 0, 0 );
                    $bg->clear();
                    $bg->destroy();
                }
            } else {
                // Gradient fallback.
                $gradient = new Imagick();
                $gradient->newPseudoImage( $canvas_w, $canvas_h, 'gradient:#667eea-#764ba2' );
                $canvas->compositeImage( $gradient, Imagick::COMPOSITE_OVER, 0, 0 );
                $gradient->clear();
                $gradient->destroy();
            }

            /* ── 2. Featured Image ────────────────── */
            $feat_url = get_the_post_thumbnail_url( $post_id, 'full' );
            if ( $feat_url ) {
                $feat_path = $this->url_to_path( $feat_url );
                if ( $feat_path && file_exists( $feat_path ) ) {
                    $feat = new Imagick( $feat_path );
                    $feat->setResolution( 150, 150 );
                    $feat->resizeImage(
                        (int) $s['cpic_feat_width'],
                        (int) $s['cpic_feat_height'],
                        Imagick::FILTER_LANCZOS,
                        1
                    );
                    $canvas->compositeImage(
                        $feat,
                        Imagick::COMPOSITE_OVER,
                        (int) $s['cpic_feat_offset_x'],
                        (int) $s['cpic_feat_offset_y']
                    );
                    $feat->clear();
                    $feat->destroy();
                }
            }

            /* ── 3 & 4. Title + Date ───────────────── */
            $title       = get_the_title( $post_id );
            $bangla_date = CPIC_Bangla_Date::format( get_post_timestamp( $post_id ) );

            $text_layer = $this->render_text_overlay_with_python( $title, $bangla_date, $s, $canvas_w, $canvas_h );
            if ( $text_layer && file_exists( $text_layer ) ) {
                $overlay = new Imagick( $text_layer );
                $canvas->compositeImage( $overlay, Imagick::COMPOSITE_OVER, 0, 0 );
                $overlay->clear();
                $overlay->destroy();
                wp_delete_file( $text_layer );
            } else {
                // Fallback renderer when Python/Pango is unavailable.
                $font_file = $this->resolve_font( $s['cpic_title_font'] );
                $date_font = $this->resolve_font( $s['cpic_date_font'] );

                if ( $title ) {
                    $font_size   = (int) $s['cpic_title_size'];
                    $offset_x    = (int) $s['cpic_title_offset_x'];
                    $offset_y    = (int) $s['cpic_title_offset_y'];
                    $title_color = $s['cpic_title_color'];

                    $title_draw = new ImagickDraw();
                    if ( $font_file ) { $title_draw->setFont( $font_file ); }
                    $title_draw->setTextEncoding( 'UTF-8' );
                    $title_draw->setFontSize( $font_size );
                    $title_draw->setFillColor( new ImagickPixel( $title_color ) );
                    $title_draw->setGravity( Imagick::GRAVITY_NORTHWEST );

                    // Dark shadow makes text readable on any background colour.
                    $title_shadow = new ImagickDraw();
                    if ( $font_file ) { $title_shadow->setFont( $font_file ); }
                    $title_shadow->setTextEncoding( 'UTF-8' );
                    $title_shadow->setFontSize( $font_size );
                    $title_shadow->setFillColor( new ImagickPixel( '#000000A6' ) );
                    $title_shadow->setGravity( Imagick::GRAVITY_NORTHWEST );

                    $title_text = $this->word_wrap_text( $title, $font_size, $font_file, $canvas_w - $offset_x - 40 );
                    $canvas->annotateImage( $title_shadow, $offset_x + 2, $offset_y + 2, 0, $title_text );
                    $canvas->annotateImage( $title_draw,   $offset_x,     $offset_y,     0, $title_text );
                }

                if ( $bangla_date ) {
                $d_size  = 28;
                $d_x     = (int) $s['cpic_date_offset_x'];
                $d_y     = (int) $s['cpic_date_offset_y'];
                $d_color = $s['cpic_date_color'];
                $d_bg    = $s['cpic_date_bg_color'];

                // Date background rectangle — estimated safe dimensions.
                $metrics_draw = $this->make_draw( $date_font, $d_size );
                $metrics      = $canvas->queryFontMetrics( $metrics_draw, $bangla_date );
                $pad          = 16;
                $rx           = $d_x;
                $ry           = $d_y - 4;

                $text_w_d = isset( $metrics['textWidth'] )  ? (float) $metrics['textWidth']  : 0;
                $text_h_d = isset( $metrics['textHeight'] ) ? (float) $metrics['textHeight'] : 0;

                if ( $text_w_d < 8 ) {
                    $text_w_d = max( 120, mb_strlen( $bangla_date, 'UTF-8' ) * 18 );
                }
                if ( $text_h_d < 8 ) {
                    $text_h_d = 36;
                }

                $date_draw_bg = new ImagickDraw();
                $date_draw_bg->setFillColor( new ImagickPixel( $d_bg ) );
                $date_draw_bg->rectangle( $rx - $pad, $ry - $pad, $rx + $text_w_d + $pad, $ry + $text_h_d + $pad );
                $canvas->drawImage( $date_draw_bg );

                // Date text with shadow.
                $draw_date = new ImagickDraw();
                if ( $date_font ) { $draw_date->setFont( $date_font ); }
                $draw_date->setTextEncoding( 'UTF-8' );
                $draw_date->setFontSize( $d_size );
                $draw_date->setFillColor( new ImagickPixel( $d_color ) );
                $draw_date->setGravity( Imagick::GRAVITY_NORTHWEST );

                $draw_date_shadow = new ImagickDraw();
                if ( $date_font ) { $draw_date_shadow->setFont( $date_font ); }
                $draw_date_shadow->setTextEncoding( 'UTF-8' );
                $draw_date_shadow->setFontSize( $d_size );
                $draw_date_shadow->setFillColor( new ImagickPixel( '#00000080' ) );
                $draw_date_shadow->setGravity( Imagick::GRAVITY_NORTHWEST );

                $canvas->annotateImage( $draw_date_shadow, $rx + 1, $ry + 1, 0, $bangla_date );
                $canvas->annotateImage( $draw_date, $rx, $ry, 0, $bangla_date );
                }
            }

            /* ── 5. Logo ──────────────────────────── */
            $logo_url = $s['cpic_logo'];
            if ( $logo_url ) {
                $logo_path = $this->url_to_path( $logo_url );
                if ( $logo_path && file_exists( $logo_path ) ) {
                    $logo  = new Imagick( $logo_path );
                    $logo->setResolution( 150, 150 );
                    $l_w   = (int) $s['cpic_logo_width'];
                    $ratio = $logo->getImageHeight() / max( $logo->getImageWidth(), 1 );
                    $l_h   = (int) round( $l_w * $ratio );
                    $logo->resizeImage( $l_w, $l_h, Imagick::FILTER_LANCZOS, 1 );
                    $canvas->compositeImage(
                        $logo,
                        Imagick::COMPOSITE_OVER,
                        (int) $s['cpic_logo_offset_x'],
                        (int) $s['cpic_logo_offset_y']
                    );
                    $logo->clear();
                    $logo->destroy();
                }
            }

            /* ── 6. Save ──────────────────────────── */
            $upload_dir = wp_upload_dir();
            $filename   = 'cpic-' . $post_id . '-' . time() . '.png';
            $filepath   = $upload_dir['basedir'] . '/cpic-temp/' . $filename;
            $fileurl    = $upload_dir['baseurl'] . '/cpic-temp/' . $filename;

            if ( ! file_exists( dirname( $filepath ) ) ) {
                wp_mkdir_p( dirname( $filepath ) );
            }

            $canvas->writeImage( $filepath );
            $canvas->clear();
            $canvas->destroy();

            return array(
                'url'  => $fileurl,
                'path' => $filepath,
            );

        } catch ( \Exception $e ) {
            if ( isset( $canvas ) && $canvas instanceof Imagick ) {
                $canvas->clear();
                $canvas->destroy();
            }
            CPIC_Security::log( 'imagick', $e->getMessage(), array( 'post_id' => $post_id ) );
            return new WP_Error( 'imagick_error', $e->getMessage() );
        }
    }

    /* ─── Helper: text overlay with Python/Pango ──────── */

    /**
     * Render title/date text overlay through Python + PangoCairo.
     *
     * This path provides proper complex-script shaping (Bangla) in fully
     * automatic server-side generation.
     *
     * @param string $title       Post title.
     * @param string $bangla_date Bangla date string.
     * @param array  $settings    Plugin settings.
     * @param int    $canvas_w    Canvas width.
     * @param int    $canvas_h    Canvas height.
     * @return string Empty string on failure, output PNG path on success.
     */
    private function render_text_overlay_with_python( $title, $bangla_date, $settings, $canvas_w, $canvas_h ) {
        if ( ! $title && ! $bangla_date ) {
            return '';
        }

        if ( ! function_exists( 'exec' ) ) {
            return '';
        }

        $python = $this->find_python_binary();
        if ( ! $python ) {
            return '';
        }

        $script = CPIC_PLUGIN_DIR . 'includes/render_text_overlay.py';
        if ( ! file_exists( $script ) ) {
            CPIC_Security::log( 'imagick', 'Python text renderer script missing.', $script );
            return '';
        }

        $upload_dir = wp_upload_dir();
        $tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'cpic-temp/';
        if ( ! file_exists( $tmp_dir ) ) {
            wp_mkdir_p( $tmp_dir );
        }

        $json_path = $tmp_dir . 'cpic-overlay-' . wp_generate_uuid4() . '.json';
        $out_path  = $tmp_dir . 'cpic-overlay-' . wp_generate_uuid4() . '.png';

        $payload = array(
            'canvas_width'       => (int) $canvas_w,
            'canvas_height'      => (int) $canvas_h,
            'title'              => (string) $title,
            'bangla_date'        => (string) $bangla_date,
            'title_font'         => (string) ( $settings['cpic_title_font'] ?? 'Noto Serif Bengali' ),
            'title_font_file'    => (string) $this->resolve_font( $settings['cpic_title_font'] ?? 'Noto Serif Bengali' ),
            'title_size'         => (int) ( $settings['cpic_title_size'] ?? 42 ),
            'title_color'        => (string) ( $settings['cpic_title_color'] ?? '#FFFFFF' ),
            'title_offset_x'     => (int) ( $settings['cpic_title_offset_x'] ?? 40 ),
            'title_offset_y'     => (int) ( $settings['cpic_title_offset_y'] ?? 750 ),
            'date_font'          => (string) ( $settings['cpic_date_font'] ?? 'Hind Siliguri' ),
            'date_font_file'     => (string) $this->resolve_font( $settings['cpic_date_font'] ?? 'Hind Siliguri' ),
            'date_size'          => 28,
            'date_color'         => (string) ( $settings['cpic_date_color'] ?? '#FFFFFF' ),
            'date_bg_color'      => (string) ( $settings['cpic_date_bg_color'] ?? '#E53935' ),
            'date_offset_x'      => (int) ( $settings['cpic_date_offset_x'] ?? 40 ),
            'date_offset_y'      => (int) ( $settings['cpic_date_offset_y'] ?? 950 ),
            'title_shadow_color' => 'rgba(0,0,0,0.65)',
            'date_shadow_color'  => 'rgba(0,0,0,0.5)',
        );

        $json_data = wp_json_encode( $payload );
        if ( ! $json_data || false === file_put_contents( $json_path, $json_data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            return '';
        }

        $cmd = escapeshellarg( $python ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $json_path ) . ' ' . escapeshellarg( $out_path ) . ' 2>&1';
        $out = array();
        $rc  = 1;
        exec( $cmd, $out, $rc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Controlled shell invocation.

        wp_delete_file( $json_path );

        if ( 0 !== (int) $rc || ! file_exists( $out_path ) ) {
            CPIC_Security::log( 'imagick', 'Python/Pango overlay render failed.', array( 'cmd' => $cmd, 'rc' => $rc, 'output' => implode( "\n", $out ) ) );
            if ( file_exists( $out_path ) ) {
                wp_delete_file( $out_path );
            }
            return '';
        }

        return $out_path;
    }

    /**
     * Resolve available Python executable.
     *
     * @return string
     */
    private function find_python_binary() {
        $candidates = array( '/usr/bin/python3', '/usr/local/bin/python3', '/bin/python3', 'python3' );

        foreach ( $candidates as $bin ) {
            if ( '/' === substr( $bin, 0, 1 ) ) {
                if ( is_executable( $bin ) ) {
                    return $bin;
                }
                continue;
            }

            if ( function_exists( 'shell_exec' ) ) {
                $path = trim( (string) shell_exec( 'command -v ' . escapeshellarg( $bin ) . ' 2>/dev/null' ) );
                if ( $path && is_executable( $path ) ) {
                    return $path;
                }
            }
        }

        return '';
    }

    /* ─── Helper: resolve font file path ──────────────── */

    private function resolve_font( $font_name ) {
        $map = array(
            'Noto Serif Bengali' => CPIC_PLUGIN_DIR . 'fonts/NotoSerifBengali-Regular.ttf',
            'Hind Siliguri'      => CPIC_PLUGIN_DIR . 'fonts/HindSiliguri-Regular.ttf',
            'SolaimanLipi'       => CPIC_PLUGIN_DIR . 'fonts/SolaimanLipi.ttf',
            'Siyam Rupali'       => CPIC_PLUGIN_DIR . 'fonts/SiyamRupali.ttf',
        );

        // 1. Exact match in plugin fonts.
        if ( isset( $map[ $font_name ] ) && file_exists( $map[ $font_name ] ) ) {
            return $map[ $font_name ];
        }

        // 2. Any plugin font.
        foreach ( $map as $path ) {
            if ( file_exists( $path ) ) {
                return $path;
            }
        }

        // 3. Any .ttf in plugin fonts dir.
        $glob = glob( CPIC_PLUGIN_DIR . 'fonts/*.ttf' );
        if ( ! empty( $glob ) ) {
            return $glob[0];
        }

        // 4. System Bangla / fallback fonts (Linux).
        $system_fonts = array(
            '/usr/share/fonts/truetype/noto/NotoSerifBengali-Regular.ttf',
            '/usr/share/fonts/truetype/noto/NotoSansBengali-Regular.ttf',
            '/usr/share/fonts/truetype/lohit-bengali/Lohit-Bengali.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        );
        foreach ( $system_fonts as $sf ) {
            if ( file_exists( $sf ) ) {
                return $sf;
            }
        }

        CPIC_Security::log( 'imagick', 'No font file found for: ' . $font_name . '. Title/date will render with Imagick default font.' );
        return null;
    }

    /* ─── Helper: create ImagickDraw for metrics ──────── */

    private function make_draw( $font_file, $size ) {
        $d = new ImagickDraw();
        if ( $font_file ) {
            $d->setFont( $font_file );
        }
        $d->setFontSize( $size );
        $d->setTextAntialias( true );
        $d->setStrokeAntialias( true );
        return $d;
    }

    /* ─── Helper: word-wrap for Imagick ───────────────── */

    private function word_wrap_text( $text, $font_size, $font_file = null, $max_width = 1000 ) {
        $draw = $this->make_draw( $font_file, $font_size );
        $tmp  = new Imagick();
        $tmp->newImage( 1, 1, new ImagickPixel( 'transparent' ) );

        $words   = preg_split( '/\s+/u', $text );
        $lines   = array();
        $current = '';

        foreach ( $words as $word ) {
            $test    = $current ? $current . ' ' . $word : $word;
            $metrics = $tmp->queryFontMetrics( $draw, $test );

            if ( $metrics['textWidth'] > $max_width && $current !== '' ) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $test;
            }
        }
        if ( $current !== '' ) {
            $lines[] = $current;
        }

        $tmp->clear();
        $tmp->destroy();

        return implode( "\n", $lines );
    }

    /* ─── Helper: URL → local file path ───────────────── */

    private function url_to_path( $url ) {
        if ( empty( $url ) ) {
            return null;
        }

        // Strip query strings (e.g. ?ver=…, sizing params) before matching.
        $url = preg_replace( '/\?.*$/', '', $url );

        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];
        $base_dir   = $upload_dir['basedir'];

        if ( strpos( $url, $base_url ) === 0 ) {
            return urldecode( str_replace( $base_url, $base_dir, $url ) );
        }

        // Plugin assets.
        $plugin_url = CPIC_PLUGIN_URL;
        $plugin_dir = CPIC_PLUGIN_DIR;

        if ( strpos( $url, $plugin_url ) === 0 ) {
            return urldecode( str_replace( $plugin_url, $plugin_dir, $url ) );
        }

        return null;
    }

    /**
     * Delete a temp file.
     *
     * @param string $path File path.
     */
    public function cleanup( $path ) {
        if ( $path && file_exists( $path ) ) {
            wp_delete_file( $path );
        }
    }
}
