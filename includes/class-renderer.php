<?php
/**
 * Template renderer – renders a photocard from a template config array.
 *
 * The SAME renderer is used for:
 *   1. Template preview (sample data)
 *   2. Manual generation
 *   3. Automatic background generation
 *
 * This guarantees the preview matches production output.
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPIC_Renderer {

    /**
     * Test override for the Pango text path. null = auto-detect.
     *
     * @var bool|null
     */
    public static $pango_override = null;

    /**
     * Test override forcing the ImageMagick pango-delegate path.
     *
     * @var bool|null
     */
    public static $im_pango_override = null;

    /**
     * Last text rendering mode used ('pango', 'pango_im' or 'annotate').
     *
     * @var string
     */
    public static $last_text_mode = 'annotate';

    /** Last-render typography diagnostics (per request).
     * @var array
     */
    public static $last_typography = array();

    /**
     * Gather post data and render.
     *
     * @param int   $post_id Post ID.
     * @param array $config  Template config.
     * @return array|WP_Error { url, path }
     */
    public function render_for_post( $post_id, $config ) {
        if ( ! class_exists( 'Imagick' ) ) {
            return new WP_Error( 'imagick_missing', __( 'Imagick PHP extension is required.', 'custom-post-image-creator' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'invalid_post', __( 'Post not found.', 'custom-post-image-creator' ) );
        }

        $config = CPIC_Template_Manager::normalize_config( $config );

        $date_format = 'bangla';
        if ( isset( $config['elements']['date']['format'] ) ) {
            $date_format = $config['elements']['date']['format'];
        }

        $data = array(
            'title'          => get_the_title( $post_id ),
            'category'       => cpic()->category_mapper->resolve_category_label( $post_id ),
            'date'           => ( 'bangla' === $date_format ) ? CPIC_Bangla_Date::format( get_post_timestamp( $post_id ) ) : get_the_date( '', $post_id ),
            'author'         => get_the_author_meta( 'display_name', (int) $post->post_author ),
            'website'        => get_bloginfo( 'name' ),
            'featured_image' => get_the_post_thumbnail_url( $post_id, 'full' ) ?: '',
        );

        return $this->render( $data, $config, $post_id );
    }

    /**
     * Render a photocard from raw data.
     *
     * @param array $data    { title, category, date, author, website, featured_image }.
     * @param array $config  Template config.
     * @param int   $post_id Optional post id (used in filename).
     * @return array|WP_Error { url, path }
     */
    public function render( $data, $config, $post_id = 0 ) {
        if ( ! class_exists( 'Imagick' ) ) {
            return new WP_Error( 'imagick_missing', __( 'Imagick PHP extension is required.', 'custom-post-image-creator' ) );
        }

        self::$last_typography = array( 'elements' => array() );
        $this->prune_stale_generated_files();
        $config    = CPIC_Template_Manager::normalize_config( $config );
        $canvas_cfg = $config['canvas'];

        $canvas_w = max( 1, (int) $canvas_cfg['width'] );
        $canvas_h = max( 1, (int) $canvas_cfg['height'] );

        try {
            $canvas = new Imagick();
            $canvas->newImage( $canvas_w, $canvas_h, new ImagickPixel( '#1a1a2e' ) );
            $canvas->setImageFormat( 'png' );

            // High-quality rendering settings.
            $canvas->setResolution( 150, 150 );
            $canvas->setImageCompressionQuality( 0 );  // PNG: no compression loss.
            $canvas->setOption( 'png:compression-level', '0' );  // Fastest, no loss.
            $canvas->setOption( 'png:compression-strategy', '1' );
            $canvas->setImageDepth( 16 );  // 16-bit per channel for smooth gradients.
            $canvas->setImageBackgroundColor( new ImagickPixel( 'transparent' ) );

            $this->draw_background( $canvas, $canvas_cfg, $canvas_w, $canvas_h );

            // Merge shapes and elements into one z-ordered list.
            $layers = $this->build_layers( $config, $data );

            foreach ( $layers as $layer ) {
                if ( 'shape' === $layer['type'] ) {
                    $this->draw_shape( $canvas, $layer['config'], $canvas_w, $canvas_h );
                } elseif ( 'image' === $layer['type'] ) {
                    $this->draw_image_element( $canvas, $layer['config'], $layer['source'], $canvas_w, $canvas_h );
                } elseif ( 'text' === $layer['type'] ) {
                    $this->draw_text_element( $canvas, $layer['config'], $layer['text'], $canvas_w, $canvas_h );
                }
            }

            $upload_dir = wp_upload_dir();
            $basedir    = trailingslashit( $upload_dir['basedir'] ) . 'cpic-generated';
            if ( ! file_exists( $basedir ) ) {
                wp_mkdir_p( $basedir );
            }

            $filename = 'cpic-' . ( $post_id ? (int) $post_id : 'preview' ) . '-' . wp_generate_uuid4() . '.png';
            $filepath = trailingslashit( $basedir ) . $filename;
            $fileurl  = trailingslashit( $upload_dir['baseurl'] ) . 'cpic-generated/' . $filename;

            if ( ! $canvas->writeImage( $filepath ) || ! is_file( $filepath ) || ! filesize( $filepath ) ) {
                throw new RuntimeException( __( 'Generated PNG could not be written to the uploads directory.', 'custom-post-image-creator' ) );
            }
            $canvas->clear();
            $canvas->destroy();

            return array(
                'url'  => $fileurl,
                'path' => $filepath,
            );
        } catch ( \Throwable $e ) {
            if ( isset( $canvas ) && $canvas instanceof Imagick ) {
                $canvas->clear();
                $canvas->destroy();
            }
            CPIC_Logger::log( 'imagick', __( 'Render failed.', 'custom-post-image-creator' ), array( 'error' => $e->getMessage() ) );
            return new WP_Error( 'render_error', $e->getMessage() );
        }
    }

    /** Prune only old, unreferenced files under the plugin-owned output folder. */
    private function prune_stale_generated_files() {
        if ( get_transient( 'cpic_generated_prune_lock' ) ) {
            return;
        }
        set_transient( 'cpic_generated_prune_lock', 1, DAY_IN_SECONDS );
        $upload = wp_upload_dir();
        $dir = trailingslashit( $upload['basedir'] ) . 'cpic-generated/';
        if ( ! is_dir( $dir ) ) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'cpic_jobs';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        $referenced = $exists ? $wpdb->get_col( "SELECT image_path FROM {$table} WHERE image_path IS NOT NULL AND image_path != ''" ) : array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $keep = array();
        foreach ( is_array( $referenced ) ? $referenced : array() as $path ) {
            $real = realpath( $path );
            if ( $real ) { $keep[ $real ] = true; }
        }
        $files = glob( $dir . 'cpic-*.png' );
        foreach ( is_array( $files ) ? $files : array() as $file ) {
            $real = realpath( $file );
            if ( $real && empty( $keep[ $real ] ) && filemtime( $real ) < time() - ( 7 * DAY_IN_SECONDS ) ) {
                wp_delete_file( $real );
            }
        }
        $archives = glob( $dir . 'cpic-bulk-*.zip' );
        foreach ( is_array( $archives ) ? $archives : array() as $archive ) {
            if ( is_file( $archive ) && filemtime( $archive ) < time() - DAY_IN_SECONDS ) {
                wp_delete_file( $archive );
            }
        }
    }

    /* ─── Layer building ───────────────────────────────── */

    private function build_layers( $config, $data ) {
        $layers = array();
        $elements = isset( $config['elements'] ) && is_array( $config['elements'] ) ? $config['elements'] : array();
        $shapes   = isset( $config['shapes'] ) && is_array( $config['shapes'] ) ? $config['shapes'] : array();

        foreach ( $shapes as $shape ) {
            if ( ! is_array( $shape ) ) {
                continue;
            }
            $layers[] = array(
                'type'   => 'shape',
                'z'      => isset( $shape['z_index'] ) ? (int) $shape['z_index'] : 0,
                'config' => $shape,
            );
        }

        foreach ( $elements as $key => $el ) {
            if ( ! is_array( $el ) || empty( $el['enabled'] ) ) {
                continue;
            }

            $z = isset( $el['z_index'] ) ? (int) $el['z_index'] : 0;

            if ( 'image' === ( $el['type'] ?? '' ) ) {
                $source = '';

                if ( 'featured_image' === $key ) {
                    $source = $data['featured_image'] ?? '';
                    if ( empty( $source ) ) {
                        // Fallback behaviour.
                        if ( ( $el['fallback'] ?? 'hide' ) === 'template_image' && ! empty( $el['fallback_image'] ) ) {
                            $source = $el['fallback_image'];
                        } else {
                            continue; // Hide element.
                        }
                    }
                } elseif ( 'logo' === $key ) {
                    $source = $el['source'] ?? '';
                    if ( empty( $source ) ) {
                        continue;
                    }
                }

                $layers[] = array(
                    'type'   => 'image',
                    'z'      => $z,
                    'config' => $el,
                    'source' => $source,
                );
            } elseif ( 'text' === ( $el['type'] ?? '' ) ) {
                $el['id'] = (string) $key;
                $text = $this->text_for_element( $key, $el, $data );
                if ( '' === $text ) {
                    continue;
                }
                $layers[] = array(
                    'type'   => 'text',
                    'z'      => $z,
                    'config' => $el,
                    'text'   => $text,
                );
            }
        }

        usort( $layers, function ( $a, $b ) {
            return $a['z'] <=> $b['z'];
        } );

        return $layers;
    }

    private function text_for_element( $key, $el, $data ) {
        switch ( $key ) {
            case 'headline':
                return trim( (string) ( $data['title'] ?? '' ) );
            case 'category':
                return trim( (string) ( $data['category'] ?? '' ) );
            case 'date':
                return trim( (string) ( $data['date'] ?? '' ) );
            case 'author':
                return trim( (string) ( $data['author'] ?? '' ) );
            case 'website_name':
                return trim( (string) ( $data['website'] ?? '' ) );
            default:
                return isset( $el['text'] ) ? trim( (string) $el['text'] ) : '';
        }
    }

    /* ─── Background ───────────────────────────────────── */

    private function draw_background( $canvas, $cfg, $w, $h ) {
        $type = $cfg['background_type'] ?? 'gradient';

        if ( 'image' === $type && ! empty( $cfg['background_image'] ) ) {
            $path = $this->url_to_path( $cfg['background_image'] );
            if ( $path && file_exists( $path ) ) {
                $bg = new Imagick( $path );
                $bg->setResolution( 150, 150 );
                $bg->resizeImage( $w, $h, Imagick::FILTER_LANCZOS, 1 );
                $canvas->compositeImage( $bg, Imagick::COMPOSITE_OVER, 0, 0 );
                $bg->clear();
                $bg->destroy();
                return;
            }
        }

        if ( 'color' === $type && ! empty( $cfg['background_color'] ) ) {
            $draw = new ImagickDraw();
            $draw->setFillColor( new ImagickPixel( $this->sanitize_color( $cfg['background_color'], '#1a1a2e' ) ) );
            $draw->rectangle( 0, 0, $w, $h );
            $canvas->drawImage( $draw );
            return;
        }

        // Default: gradient.
        $gradient = new Imagick();
        $from     = $this->sanitize_color( $cfg['gradient_from'] ?? '', '#667eea' );
        $to       = $this->sanitize_color( $cfg['gradient_to'] ?? '', '#764ba2' );
        $gradient->newPseudoImage( $w, $h, 'gradient:' . $from . '-' . $to );
        $canvas->compositeImage( $gradient, Imagick::COMPOSITE_OVER, 0, 0 );
        $gradient->clear();
        $gradient->destroy();
    }

    /* ─── Shapes ───────────────────────────────────────── */

    private function draw_shape( $canvas, $shape, $w, $h ) {
        $type  = $shape['type'] ?? 'rect';
        $color = $shape['color'] ?? '#000000';
        $op    = isset( $shape['opacity'] ) ? (int) $shape['opacity'] : 100;
        $op    = max( 0, min( 100, $op ) );

        $fill = $this->with_opacity( $color, $op );

        $draw = new ImagickDraw();
        $draw->setFillColor( new ImagickPixel( $fill ) );

        if ( 'line' === $type ) {
            $draw->setStrokeColor( new ImagickPixel( $fill ) );
            $draw->setStrokeWidth( max( 1, (int) ( $shape['width'] ?? 2 ) ) );
            $draw->line(
                (int) ( $shape['x1'] ?? 0 ),
                (int) ( $shape['y1'] ?? 0 ),
                (int) ( $shape['x2'] ?? $w ),
                (int) ( $shape['y2'] ?? $h )
            );
        } elseif ( 'ellipse' === $type ) {
            $draw->ellipse(
                (int) ( $shape['cx'] ?? ( $w / 2 ) ),
                (int) ( $shape['cy'] ?? ( $h / 2 ) ),
                max( 1, (int) ( $shape['rx'] ?? 50 ) ),
                max( 1, (int) ( $shape['ry'] ?? 50 ) ),
                0, 360
            );
        } else {
            $draw->rectangle(
                (int) ( $shape['x'] ?? 0 ),
                (int) ( $shape['y'] ?? 0 ),
                (int) ( $shape['x'] ?? 0 ) + (int) ( $shape['width'] ?? $w ),
                (int) ( $shape['y'] ?? 0 ) + (int) ( $shape['height'] ?? $h )
            );
        }

        $canvas->drawImage( $draw );
    }

    /* ─── Image elements ───────────────────────────────── */

    private function draw_image_element( $canvas, $el, $url, $w, $h ) {
        if ( empty( $url ) ) {
            return;
        }

        $path = $this->url_to_path( $url );
        if ( ! $path || ! file_exists( $path ) ) {
            return;
        }

        $x = (int) ( $el['x'] ?? 0 );
        $y = (int) ( $el['y'] ?? 0 );
        $box_w = max( 1, (int) ( $el['width'] ?? $w ) );
        $box_h = max( 1, (int) ( $el['height'] ?? 0 ) );
        $fit   = $el['fit'] ?? 'cover';

        $img = new Imagick( $path );
        $img->setResolution( 150, 150 );
        $img->setImageDepth( 16 );
        $iw  = $img->getImageWidth();
        $ih  = $img->getImageHeight();
        if ( $iw < 1 || $ih < 1 ) {
            $img->clear();
            $img->destroy();
            return;
        }

        if ( 0 === $box_h ) {
            // Height auto: preserve aspect ratio.
            $box_h = (int) round( $box_w * ( $ih / $iw ) );
        }

        $img->setImageBackgroundColor( new ImagickPixel( 'transparent' ) );
        $img->setImageAlphaChannel( Imagick::ALPHACHANNEL_SET );

        switch ( $fit ) {
            case 'contain':
                $scale = min( $box_w / $iw, $box_h / $ih );
                $dw = max( 1, (int) round( $iw * $scale ) );
                $dh = max( 1, (int) round( $ih * $scale ) );
                $img->resizeImage( $dw, $dh, Imagick::FILTER_LANCZOS, 1 );
                $ox = (int) round( ( $box_w - $dw ) / 2 );
                $oy = (int) round( ( $box_h - $dh ) / 2 );
                break;
            case 'fill':
                $img->resizeImage( $box_w, $box_h, Imagick::FILTER_LANCZOS, 1 );
                $ox = 0;
                $oy = 0;
                break;
            case 'cover':
            default:
                $scale = max( $box_w / $iw, $box_h / $ih );
                $dw = max( 1, (int) ceil( $iw * $scale ) );
                $dh = max( 1, (int) ceil( $ih * $scale ) );
                $img->resizeImage( $dw, $dh, Imagick::FILTER_LANCZOS, 1 );
                $ox = (int) round( ( $box_w - $dw ) / 2 );
                $oy = (int) round( ( $box_h - $dh ) / 2 );
                break;
        }

        // Border radius.
        $radius = (int) ( $el['border_radius'] ?? 0 );
        if ( $radius > 0 ) {
            $img->roundCorners( $radius, $radius );
        }

        // Opacity.
        $opacity = isset( $el['opacity'] ) ? (int) $el['opacity'] : 100;
        if ( $opacity < 100 && $opacity >= 0 ) {
            $img->setImageOpacity( $opacity / 100 );
        }

        $canvas->compositeImage( $img, Imagick::COMPOSITE_OVER, $x + $ox, $y + $oy );
        $img->clear();
        $img->destroy();
    }

    /* ─── Text elements ────────────────────────────────── */

    private function draw_text_element( $canvas, $el, $text, $w, $h ) {
        if ( '' === $text ) {
            return;
        }

        $font_name   = $el['font'] ?? 'Noto Serif Bengali';
        $font_file   = $this->resolve_font( $font_name );
        $font_name   = $this->font_family_for_file( $font_file, $font_name );
        $font_size   = max( 1, (int) ( $el['font_size'] ?? 28 ) );
        $color       = $this->sanitize_color( $el['color'] ?? '', '#FFFFFF' );
        $align       = $el['align'] ?? 'left';
        $x           = max( 0, min( $w - 1, (int) ( $el['x'] ?? 40 ) ) );
        $y           = max( 0, min( $h - 1, (int) ( $el['y'] ?? 40 ) ) );
        $el['x'] = $x;
        $el['y'] = $y;
        $available_height = max( 1, $h - $y );
        $el['max_height'] = isset( $el['max_height'] ) && (int) $el['max_height'] > 0
            ? min( $available_height, (int) $el['max_height'] )
            : $available_height;
        if ( isset( $el['max_width'] ) ) { $el['max_width'] = max( 1, min( (int) $el['max_width'], $w - $x ) ); }
        $bold        = ! empty( $el['bold'] );
        $shadow_color = $this->sanitize_color( $el['shadow_color'] ?? '', '#00000080' );
        $shadow_off  = (int) ( $el['shadow_offset'] ?? 1 );

        $padding_x = (int) ( $el['padding_x'] ?? 0 );
        $padding_y = (int) ( $el['padding_y'] ?? 0 );
        $radius    = (int) ( $el['border_radius'] ?? 0 );

        // Adaptive sizing is opt-in and applies only to the headline.
        // Pango performs its own complex-script-aware sizing when available.
        $requested_size = $font_size;
        $el['_requested_size'] = $requested_size;
        if ( 'headline' === ( $el['id'] ?? '' ) || isset( $el['max_lines'] ) ) {
            if ( ! empty( $el['auto_scale'] ) ) {
                $font_size = $this->fit_headline_font( $text, $el, $font_file, $w, $h );
            }
        }

        // Text engine priority for correct complex-script (Bangla) shaping:
        //   1. Python + PangoCairo (HarfBuzz) — most reliable.
        //   2. ImageMagick's own pango delegate (if built in).
        //   3. annotateImage (works only if ImageMagick has libraqm).
        foreach ( $this->text_engines() as $engine ) {
            if ( 'python' === $engine ) {
                if ( $this->draw_text_element_pango( $canvas, $el, $text, $w, $font_size, $font_name, $color, $shadow_color, $shadow_off ) ) {
                    self::$last_text_mode = 'pango';
                    return;
                }
            } elseif ( $this->draw_text_element_im_pango( $canvas, $el, $text, $w, $font_size, $font_name, $color, $shadow_color, $shadow_off, $bold ) ) {
                self::$last_text_mode = 'pango_im';
                return;
            }
        }
        if ( preg_match( '/[\x{0980}-\x{09FF}]/u', $text ) ) {
            throw new RuntimeException( __( 'Bengali text requires a working Pango/HarfBuzz rendering engine. Install Python PangoCairo or ImageMagick Pango support; refusing to generate potentially broken glyphs.', 'custom-post-image-creator' ) );
        }
        self::$last_text_mode = 'annotate';

        $letter_spacing = (float) ( $el['letter_spacing'] ?? 0 );
        $draw = $this->make_text_draw( $font_file, $font_size, $color, $bold, $letter_spacing );

        // Measure and wrap using the fallback engine's own metrics.
        list( $lines, $max_text_w, $line_h ) = $this->wrap_measure( $text, $font_file, $font_size, $el, $w );
        $line_limit = isset( $el['max_lines'] ) ? max( 1, (int) $el['max_lines'] ) : count( $lines );
        if ( ! empty( $el['max_height'] ) ) {
            $line_limit = min( $line_limit, max( 1, (int) floor( (int) $el['max_height'] / max( 1, $line_h ) ) ) );
        }
        if ( count( $lines ) > $line_limit ) {
            $lines = array_slice( $lines, 0, $line_limit );
            $lines[count( $lines ) - 1] = rtrim( $lines[count( $lines ) - 1] ) . '…';
        }
        $diagnostic = array( 'element' => $el['id'] ?? '', 'font' => $font_name, 'font_file' => $font_file, 'engine' => 'annotate', 'requested_size' => $requested_size, 'final_size' => $font_size, 'line_count' => count( $lines ), 'measured_width' => $max_text_w, 'measured_height' => $line_h * count( $lines ), 'auto_scaled' => $requested_size !== $font_size, 'fallback' => true );
        self::$last_typography['elements'][] = $diagnostic;
        $elements = self::$last_typography['elements'];
        self::$last_typography = $diagnostic;
        self::$last_typography['elements'] = $elements;

        // Background pill (for category / date labels).
        if ( ! empty( $el['background_color'] ) ) {
            $box_w = $max_text_w + $padding_x * 2;
            $box_h = $line_h * count( $lines ) + $padding_y * 2;
            $bg    = new ImagickDraw();
            $bg->setFillColor( new ImagickPixel( $this->sanitize_color( $el['background_color'], '#000000' ) ) );
            if ( $radius > 0 ) {
                $bg->roundRectangle( $x - $padding_x, $y - $padding_y, $x + $box_w, $y + $box_h, $radius, $radius );
            } else {
                $bg->rectangle( $x - $padding_x, $y - $padding_y, $x + $box_w, $y + $box_h );
            }
            $canvas->drawImage( $bg );
        }

        // Alignment offset.
        $align_offset = 0;
        if ( 'center' === $align ) {
            $align_offset = (int) round( ( max( 1, (int) ( $el['max_width'] ?? ( $w - $x ) ) ) - $max_text_w ) / 2 );
        } elseif ( 'right' === $align ) {
            $align_offset = max( 1, (int) ( $el['max_width'] ?? ( $w - $x ) ) ) - $max_text_w;
        }

        $line_y = $y;
        foreach ( $lines as $line ) {
            $line_x = $x + $align_offset;
            if ( $shadow_off > 0 ) {
                $sd = $this->make_text_draw( $font_file, $font_size, $shadow_color, $bold, $letter_spacing );
                $canvas->annotateImage( $sd, $line_x + $shadow_off, $line_y + $shadow_off, 0, $line );
            }
            $canvas->annotateImage( $draw, $line_x, $line_y, 0, $line );
            $line_y += $line_h;
        }
    }

    /**
     * Compute a headline font size that fits within max lines / max width,
     * then produce balanced wrapped lines.
     *
     * @param string $text      Headline text.
     * @param array  $el        Element config.
     * @param string $font_file Font file.
     * @param int    $canvas_w  Canvas width.
     * @return int Final font size.
     */
    private function fit_headline_font( $text, $el, $font_file, $canvas_w, $canvas_h = 0 ) {
        $max_lines = max( 1, min( 30, (int) ( $el['max_lines'] ?? 3 ) ) );
        $x = max( 0, (int) ( $el['x'] ?? 0 ) );
        $max_width = max( 1, min( (int) ( $el['max_width'] ?? ( $canvas_w - $x - 40 ) ), $canvas_w - $x ) );
        $size   = max( 1, (int) ( $el['font_size'] ?? 42 ) );
        $min_sz = max( 1, (int) ( $el['min_font_size'] ?? 20 ) );
        $max_sz = max( $min_sz, (int) ( $el['max_font_size'] ?? $size ) );
        $size = min( $size, $max_sz );
        if ( empty( $el['auto_scale'] ) ) {
            return $size;
        }

        // Search from largest to smallest so the title remains as readable as possible.
        $tmp = new Imagick();
        $tmp->newImage( 1, 1, new ImagickPixel( 'transparent' ) );
        try {
            for ( $candidate = $max_sz; $candidate >= $min_sz; --$candidate ) {
                $draw = $this->make_draw( $font_file, $candidate, (float) ( $el['letter_spacing'] ?? 0 ) );
                $lines = $this->wrap_text_to_width( $tmp, $draw, $text, $max_width );
                $line_height = max( 0.8, min( 3.0, (float) ( $el['line_height'] ?? 1.3 ) ) );
                $height = (int) ceil( $candidate * $line_height * count( $lines ) );
                $max_height = isset( $el['max_height'] ) ? max( 1, (int) $el['max_height'] ) : 0;
                if ( count( $lines ) <= $max_lines && ( ! $max_height || $height <= $max_height ) ) {
                    return $candidate;
                }
            }
            return $min_sz;
        } finally {
            $tmp->clear();
            $tmp->destroy();
        }
    }

    /**
     * Wrap text to a max width and measure lines.
     *
     * @param string $text      Text.
     * @param string $font_file Font file.
     * @param int    $font_size Font size.
     * @param array  $el        Element config.
     * @param int    $canvas_w  Canvas width.
     * @return array [ lines, max_text_w, line_height ]
     */
    private function wrap_measure( $text, $font_file, $font_size, $el, $canvas_w ) {
        $max_width = (int) ( $el['max_width'] ?? ( $canvas_w - 80 ) );
        $max_width = max( 1, min( $max_width, $canvas_w - max( 0, (int) ( $el['x'] ?? 0 ) ) ) );

        $tmp = new Imagick();
        $tmp->newImage( 1, 1, new ImagickPixel( 'transparent' ) );

        $draw   = $this->make_draw( $font_file, $font_size, (float) ( $el['letter_spacing'] ?? 0 ) );
        $wrapped = $this->wrap_text_to_width( $tmp, $draw, $text, $max_width );

        $line_height = (float) ( $el['line_height'] ?? 1.3 );
        $max_text_w  = 0;
        foreach ( $wrapped as $line ) {
            $metrics = $tmp->queryFontMetrics( $draw, $line );
            $line_w  = (float) ( $metrics['textWidth'] ?? 0 );
            if ( $line_w > $max_text_w ) {
                $max_text_w = $line_w;
            }
        }

        $tmp->clear();
        $tmp->destroy();

        return array( $wrapped, (int) $max_text_w, (int) ceil( $font_size * $line_height ) );
    }

    /**
     * Word-aware wrapping using Imagick metrics. Multibyte (Bengali) safe.
     *
     * @param Imagick      $tmp  Temp canvas.
     * @param ImagickDraw  $draw Draw object.
     * @param string       $text Text.
     * @param int          $max_width Max width px.
     * @return array Lines.
     */
    private function wrap_text_to_width( $tmp, $draw, $text, $max_width ) {
        $text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
        $paragraphs = explode( "\n", $text );
        $lines = array();
        $max_width = max( 1, (int) $max_width );
        foreach ( $paragraphs as $paragraph ) {
            $words = preg_split( '/\s+/u', trim( $paragraph ), -1, PREG_SPLIT_NO_EMPTY );
            if ( false === $words || empty( $words ) ) {
                $lines[] = '';
                continue;
            }
            $current = '';
            foreach ( $words as $word ) {
                $candidate = '' === $current ? $word : $current . ' ' . $word;
                $metrics = $tmp->queryFontMetrics( $draw, $candidate );
                if ( (float) ( $metrics['textWidth'] ?? 0 ) <= $max_width ) {
                    $current = $candidate;
                    continue;
                }
                if ( '' !== $current ) {
                    $lines[] = $current;
                    $current = '';
                }
                // Split oversized unbroken words by Unicode codepoint to avoid overflow.
                if ( preg_match_all( '/\\X/u', $word, $match ) ) {
                    $chars = $match[0];
                } else {
                    $chars = str_split( $word );
                }
                foreach ( $chars as $char ) {
                    $part = $current . $char;
                    $metrics = $tmp->queryFontMetrics( $draw, $part );
                    if ( '' !== $current && (float) ( $metrics['textWidth'] ?? 0 ) > $max_width ) {
                        $lines[] = $current;
                        $current = $char;
                    } else {
                        $current = $part;
                    }
                }
            }
            if ( '' !== $current ) {
                $lines[] = $current;
            }
        }
        return empty( $lines ) ? array( '' ) : $lines;
    }

    /* ─── Drawing helpers ──────────────────────────────── */

    private function make_draw( $font_file, $font_size, $letter_spacing = 0 ) {
        $d = new ImagickDraw();
        if ( $font_file ) {
            $d->setFont( $font_file );
        }
        $d->setTextEncoding( 'UTF-8' );
        $d->setFontSize( $font_size );
        if ( method_exists( $d, 'setTextKerning' ) ) { $d->setTextKerning( (float) $letter_spacing ); }
        $d->setTextAntialias( true );
        $d->setStrokeAntialias( true );
        return $d;
    }

    private function make_text_draw( $font_file, $font_size, $color, $bold = false, $letter_spacing = 0 ) {
        $d = new ImagickDraw();
        if ( $font_file ) {
            $d->setFont( $font_file );
        }
        if ( $bold ) {
            $d->setFontWeight( 700 );
        }
        $d->setTextEncoding( 'UTF-8' );
        $d->setFontSize( $font_size );
        if ( method_exists( $d, 'setTextKerning' ) ) { $d->setTextKerning( (float) $letter_spacing ); }
        $d->setFillColor( new ImagickPixel( $color ) );
        $d->setGravity( Imagick::GRAVITY_NORTHWEST );
        $d->setTextAntialias( true );
        $d->setStrokeAntialias( true );
        return $d;
    }

    /* ─── Pango text rendering (complex-script safe) ────────── */

    private $pango_checked    = null;
    private $pango_available  = false;
    private $im_pango_checked = null;
    private $im_pango_available = false;

    /**
     * Ordered list of shaping-capable text engines available on this server.
     *
     * @return string[] 'python' and/or 'im_pango'.
     */
    private function text_engines() {
        if ( false === self::$pango_override ) {
            return array();
        }
        if ( true === self::$im_pango_override ) {
            return $this->im_pango_available() ? array( 'im_pango' ) : array();
        }
        $engines = array();
        if ( $this->pango_renderer_available() ) {
            $engines[] = 'python';
        }
        if ( $this->im_pango_available() ) {
            $engines[] = 'im_pango';
        }
        return $engines;
    }

    /**
     * Whether the Python + PangoCairo text renderer can be used.
     *
     * ImageMagick's annotateImage only shapes Bangla/Arabic correctly when
     * built with libraqm, which is not guaranteed. PangoCairo shapes via
     * HarfBuzz and is the reliable path.
     *
     * @return bool
     */
    private function pango_renderer_available() {
        if ( null === $this->pango_checked ) {
            $this->pango_checked = true;
            $python = $this->find_python_binary();
            $this->pango_available = false;
            if ( function_exists( 'exec' ) && file_exists( CPIC_PLUGIN_DIR . 'includes/render_text_element.py' ) && '' !== $python ) {
                $code = 'import gi; gi.require_version("Pango", "1.0"); gi.require_version("PangoCairo", "1.0"); from gi.repository import Pango, PangoCairo; import cairo';
                $output = array();
                $rc = 1;
                @exec( escapeshellarg( $python ) . ' -c ' . escapeshellarg( $code ) . ' 2>&1', $output, $rc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
                $this->pango_available = 0 === (int) $rc;
            }
        }
        return $this->pango_available;
    }

    /**
     * Find a working ImageMagick CLI binary (magick or convert).
     *
     * @return string Path to binary or empty string.
     */
    private function find_magick_binary() {
        static $cached = null;
        if ( null !== $cached ) {
            return $cached;
        }
        $cached = '';
        if ( ! function_exists( 'exec' ) ) {
            return $cached;
        }
        foreach ( array( 'magick', 'convert' ) as $bin ) {
            $probe = array();
            $rc    = 1;
            @exec( escapeshellarg( $bin ) . ' -version 2>&1', $probe, $rc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
            if ( 0 === (int) $rc && ! empty( $probe ) ) {
                $cached = $bin;
                return $cached;
            }
        }
        return $cached;
    }

    /**
     * Unique temp file base path inside uploads/cpic-temp/.
     *
     * Replaces wp_tempnam(), which is only defined in the admin context and
     * therefore fatals when the renderer runs in the background pipeline.
     *
     * @param string $prefix File name prefix.
     * @return string Path without extension (callers append e.g. ".png").
     */
    private static function temp_path( $prefix ) {
        $upload_dir = wp_upload_dir();
        $tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'cpic-temp/';
        if ( ! is_dir( $tmp_dir ) ) {
            wp_mkdir_p( $tmp_dir );
        }
        return $tmp_dir . $prefix . wp_generate_uuid4();
    }

    /**
     * Whether ImageMagick CLI can render pango text with transparent background.
     *
     * Probes by running `magick -background transparent pango:test`. The PHP
     * Imagick extension's newPseudoImage('pango:') is unreliable for
     * transparent backgrounds on many shared-hosting builds.
     *
     * @return bool
     */
    private function im_pango_available() {
        if ( null === $this->im_pango_checked ) {
            $this->im_pango_checked = true;
            $this->im_pango_available = false;
            $bin = $this->find_magick_binary();
            if ( '' !== $bin && function_exists( 'exec' ) ) {
                $tmp_out = self::temp_path( 'cpic_im_pango_test' ) . '.png';
                $cmd = $bin . ' -background transparent ' . escapeshellarg( 'pango:test' ) . ' ' . escapeshellarg( $tmp_out ) . ' 2>&1'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
                @exec( $cmd, $output, $rc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
                $this->im_pango_available = ( 0 === (int) $rc && file_exists( $tmp_out ) );
                if ( file_exists( $tmp_out ) ) {
                    wp_delete_file( $tmp_out );
                }
            }
        }
        return $this->im_pango_available;
    }

    /**
     * Server capabilities relevant to Bangla text shaping, exposed for
     * diagnostics in the admin UI.
     *
     * @return array
     */
    public static function text_engine_report() {
        $report = array(
            'exec'         => function_exists( 'exec' ),
            'python'       => '',
            'pango_python' => false,
            'im_pango'     => false,
            'fonts_dir'    => file_exists( CPIC_PLUGIN_DIR . 'fonts' ) && is_dir( CPIC_PLUGIN_DIR . 'fonts' ),
        );

        $renderer = new self();
        $report['python'] = $renderer->find_python_binary();
        $report['pango_python'] = $renderer->pango_renderer_available();

        if ( class_exists( 'Imagick' ) ) {
            $bin = $renderer->find_magick_binary();
            if ( '' !== $bin && function_exists( 'exec' ) ) {
                $tmp_out = self::temp_path( 'cpic_im_pango_test' ) . '.png';
                $cmd = $bin . ' -background transparent ' . escapeshellarg( 'pango:test' ) . ' ' . escapeshellarg( $tmp_out ) . ' 2>&1'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
                @exec( $cmd, $output, $rc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
                $report['im_pango'] = ( 0 === (int) $rc && file_exists( $tmp_out ) );
                if ( file_exists( $tmp_out ) ) {
                    wp_delete_file( $tmp_out );
                }
            }
        }

        return $report;
    }

    /**
     * Render a single text element via Python + PangoCairo and composite it.
     *
     * @param Imagick $canvas      Canvas.
     * @param array   $el          Element config.
     * @param string  $text        Text.
     * @param int     $canvas_w    Canvas width (for alignment).
     * @param int     $font_size   Font size.
     * @param string  $font_name   Font family name.
     * @param string  $color       Fill colour.
     * @param string  $shadow_color Shadow colour.
     * @param int     $shadow_off  Shadow offset.
     * @return bool True if rendered via Pango.
     */
    private function draw_text_element_pango( $canvas, $el, $text, $canvas_w, $font_size, $font_name, $color, $shadow_color, $shadow_off ) {
        $max_width = (int) ( $el['max_width'] ?? ( $canvas_w - 80 ) );
        $max_width = max( 1, min( $max_width, $canvas_w - max( 0, (int) ( $el['x'] ?? 0 ) ) ) );

        $upload_dir = wp_upload_dir();
        $tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'cpic-temp/';
        if ( ! file_exists( $tmp_dir ) ) {
            wp_mkdir_p( $tmp_dir );
        }

        $json_path = $tmp_dir . 'cpic-el-' . wp_generate_uuid4() . '.json';
        $out_path  = $tmp_dir . 'cpic-el-' . wp_generate_uuid4() . '.png';

        $payload = array(
            'text'             => (string) $text,
            'font'             => (string) $font_name,
            'font_file'        => (string) $this->resolve_font( $font_name ),
            'size'             => (int) $font_size,
            'requested_size'   => (int) ( $el['_requested_size'] ?? $font_size ),
            'min_font_size'    => (int) ( $el['min_font_size'] ?? $font_size ),
            'max_font_size'    => (int) ( $el['max_font_size'] ?? $font_size ),
            'auto_scale'       => ! empty( $el['auto_scale'] ),
            'max_lines'        => (int) ( $el['max_lines'] ?? 0 ),
            'max_height'       => (int) ( $el['max_height'] ?? 0 ),
            'line_height'      => (float) ( $el['line_height'] ?? 1.3 ),
            'letter_spacing'   => (float) ( $el['letter_spacing'] ?? 0 ),
            'max_width'        => isset( $el['max_width'] ) ? (int) $max_width : 0,
            'configured_width' => isset( $el['max_width'] ),
            'align'            => (string) ( $el['align'] ?? 'left' ),
            'bold'             => ! empty( $el['bold'] ),
            'color'            => (string) $color,
            'shadow_color'     => (string) $shadow_color,
            'shadow_offset'    => (int) $shadow_off,
            'background_color' => (string) ( $el['background_color'] ?? '' ),
            'padding_x'        => (int) ( $el['padding_x'] ?? 0 ),
            'padding_y'        => (int) ( $el['padding_y'] ?? 0 ),
            'border_radius'    => (int) ( $el['border_radius'] ?? 0 ),
        );

        $json_data = wp_json_encode( $payload );
        if ( ! $json_data || false === file_put_contents( $json_path, $json_data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            if ( file_exists( $json_path ) ) { wp_delete_file( $json_path ); }
            return false;
        }

        $this->ensure_fontconfig();
        $python = $this->find_python_binary();
        $script = CPIC_PLUGIN_DIR . 'includes/render_text_element.py';

        $cmd = 'FONTCONFIG_FILE=' . escapeshellarg( trailingslashit( wp_upload_dir()['basedir'] ) . 'cpic-fontconfig/fonts.conf' ) . ' XDG_CACHE_HOME=' . escapeshellarg( trailingslashit( wp_upload_dir()['basedir'] ) . 'cpic-fontconfig/cache' ) . ' ' . escapeshellarg( $python ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $json_path ) . ' ' . escapeshellarg( $out_path ) . ' 2>&1';
        $out = array();
        $rc  = 1;
        exec( $cmd, $out, $rc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Controlled shell invocation.

        wp_delete_file( $json_path );
        foreach ( $out as $line ) {
            if ( 0 === strpos( $line, 'CPIC_DIAGNOSTICS ' ) ) {
                $diagnostics = json_decode( substr( $line, 17 ), true );
                if ( is_array( $diagnostics ) ) {
                    $diagnostics['element'] = $el['id'] ?? '';
                    self::$last_typography['elements'][] = $diagnostics;
                    $elements = self::$last_typography['elements'];
                    self::$last_typography = $diagnostics;
                    self::$last_typography['elements'] = $elements;
                }
            }
        }

        if ( 0 !== (int) $rc || ! file_exists( $out_path ) ) {
            CPIC_Logger::log( 'imagick', __( 'Pango text rendering failed; checking configured fallback engine.', 'custom-post-image-creator' ), array( 'exit_code' => (int) $rc, 'element' => $el['id'] ?? '' ) );
            if ( file_exists( $out_path ) ) {
                wp_delete_file( $out_path );
            }
            return false;
        }

        list( $layer_w, $layer_h ) = $this->png_size( $out_path );
        if ( ! $layer_w || ! $layer_h ) {
            wp_delete_file( $out_path );
            return false;
        }

        $x     = (int) ( $el['x'] ?? 40 );
        $y     = (int) ( $el['y'] ?? 40 );
        $align = $el['align'] ?? 'left';
        if ( empty( $el['max_width'] ) ) {
            if ( 'center' === $align ) {
                $x += (int) round( ( $max_width - $layer_w ) / 2 );
            } elseif ( 'right' === $align ) {
                $x += $max_width - $layer_w;
            }
        }

        try {
            $layer = new Imagick( $out_path );
            $canvas->compositeImage( $layer, Imagick::COMPOSITE_OVER, $x, $y );
            $layer->clear();
            $layer->destroy();
        } catch ( \Throwable $e ) {
            wp_delete_file( $out_path );
            return false;
        }

        wp_delete_file( $out_path );
        return true;
    }

    /**
     * Ensure fontconfig knows about the plugin's bundled fonts so the
     * ImageMagick pango delegate can resolve Bengali font families.
     *
     * @return void
     */
    private function ensure_fontconfig() {
        $tmp_dir    = trailingslashit( wp_upload_dir()['basedir'] ) . 'cpic-fontconfig/';
        $conf_file  = $tmp_dir . 'fonts.conf';
        $fonts_dir  = CPIC_PLUGIN_DIR . 'fonts';

        if ( ! is_dir( $tmp_dir ) ) { wp_mkdir_p( $tmp_dir ); }
        wp_mkdir_p( trailingslashit( $tmp_dir ) . 'cache' );

        $conf = '<?xml version="1.0"?>
<!DOCTYPE fontconfig SYSTEM "urn:fontconfig:fonts.dtd">
<fontconfig>
    <include>/etc/fonts/fonts.conf</include>
    <dir>' . htmlspecialchars( $fonts_dir, ENT_XML1, 'UTF-8' ) . '</dir>
    <cachedir>' . htmlspecialchars( trailingslashit( $tmp_dir ) . 'cache', ENT_XML1, 'UTF-8' ) . '</cachedir>
</fontconfig>';

        $existing = file_exists( $conf_file ) ? file_get_contents( $conf_file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( false === $existing || $existing !== $conf ) {
            $temporary = $tmp_dir . 'fonts-' . wp_generate_uuid4() . '.tmp';
            if ( false !== file_put_contents( $temporary, $conf, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                if ( ! @rename( $temporary, $conf_file ) ) {
                    wp_delete_file( $temporary );
                    if ( ! file_exists( $conf_file ) ) {
                        file_put_contents( $conf_file, $conf ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                    }
                }
            }
        }

        putenv( 'FONTCONFIG_PATH=' . $tmp_dir );
        putenv( 'FONTCONFIG_FILE=' . $conf_file );
    }

    /**
     * Render a text element via ImageMagick's built-in pango delegate.
     *
     * Text is pre-wrapped with the standard metrics, rendered with Pango
     * markup (which shapes Bangla via HarfBuzz when ImageMagick is built
     * with the pango delegate) and composited. Pills are drawn with
     * ImagickDraw so a correct look is preserved.
     *
     * @param Imagick $canvas        Canvas.
     * @param array   $el            Element config.
     * @param string  $text          Text.
     * @param int     $canvas_w      Canvas width.
     * @param int     $font_size     Font size.
     * @param string  $font_name     Font family name.
     * @param string  $color         Fill colour.
     * @param string  $shadow_color  Shadow colour.
     * @param int     $shadow_off    Shadow offset.
     * @param bool    $bold          Bold flag.
     * @return bool True if rendered.
     */
    private function draw_text_element_im_pango( $canvas, $el, $text, $canvas_w, $font_size, $font_name, $color, $shadow_color, $shadow_off, $bold ) {
        $this->ensure_fontconfig();

        $bin = $this->find_magick_binary();
        if ( '' === $bin ) {
            return false;
        }

        $max_width = (int) ( $el['max_width'] ?? ( $canvas_w - 80 ) );
        $max_width = max( 1, min( $max_width, $canvas_w - max( 0, (int) ( $el['x'] ?? 0 ) ) ) );

        $tmp = new Imagick();
        $tmp->newImage( 1, 1, new ImagickPixel( 'transparent' ) );
        $draw    = $this->make_draw( $this->resolve_font( $font_name ), $font_size );
        $wrapped = $this->wrap_text_to_width( $tmp, $draw, $text, $max_width );
        $tmp->clear();
        $tmp->destroy();

        $lines_text = implode( "\n", $wrapped );

        $x     = (int) ( $el['x'] ?? 40 );
        $y     = (int) ( $el['y'] ?? 40 );
        $align = $el['align'] ?? 'left';

        $padding_x = (int) ( $el['padding_x'] ?? 0 );
        $padding_y = (int) ( $el['padding_y'] ?? 0 );
        $radius    = (int) ( $el['border_radius'] ?? 0 );

        // Render the main text via CLI for guaranteed transparent background.
        $layer = $this->cli_pango_render( $bin, $font_name, $font_size, $color, $bold, $lines_text );
        if ( ! $layer ) {
            return false;
        }
        $layer_w = $layer->getImageWidth();
        $layer_h = $layer->getImageHeight();

        // Background pill beneath the text.
        if ( ! empty( $el['background_color'] ) ) {
            $box_w = $layer_w + $padding_x * 2;
            $box_h = $layer_h + $padding_y * 2;
            $bg    = new ImagickDraw();
            $bg->setFillColor( new ImagickPixel( $this->sanitize_color( $el['background_color'], '#000000' ) ) );
            if ( $radius > 0 ) {
                $bg->roundRectangle( $x - $padding_x, $y - $padding_y, $x + $box_w, $y + $box_h, $radius, $radius );
            } else {
                $bg->rectangle( $x - $padding_x, $y - $padding_y, $x + $box_w, $y + $box_h );
            }
            $canvas->drawImage( $bg );
        }

        // Alignment.
        if ( 'center' === $align ) {
            $x += (int) round( ( $max_width - $layer_w ) / 2 );
        } elseif ( 'right' === $align ) {
            $x += $max_width - $layer_w;
        }

        // Shadow (a second pango render offset by shadow_off).
        if ( $shadow_off > 0 ) {
            $sh = $this->cli_pango_render( $bin, $font_name, $font_size, $shadow_color, $bold, $lines_text );
            if ( $sh ) {
                $canvas->compositeImage( $sh, Imagick::COMPOSITE_OVER, $x + $shadow_off, $y + $shadow_off );
                $sh->clear();
                $sh->destroy();
            }
        }

        $canvas->compositeImage( $layer, Imagick::COMPOSITE_OVER, $x, $y );
        $layer->clear();
        $layer->destroy();

        return true;
    }

    /**
     * Render Pango text via ImageMagick CLI to a transparent PNG.
     *
     * Uses `magick -background transparent pango:...` which produces a
     * transparent PNG (unlike the PHP Imagick extension's newPseudoImage
     * which ignores setBackgroundColor on many builds).
     *
     * @param string $bin       Path to magick/convert CLI.
     * @param string $font_name Font family.
     * @param int    $font_size Font size in px.
     * @param string $color     Hex foreground colour.
     * @param bool   $bold      Bold flag.
     * @param string $text      Pre-wrapped text (newlines preserved).
     * @return Imagick|false    Rendered layer or false on failure.
     */
    private function cli_pango_render( $bin, $font_name, $font_size, $color, $bold, $text ) {
        $family = $font_name ? $font_name : 'Noto Serif Bengali';
        $weight = $bold ? 'weight="bold"' : '';
        $family = htmlspecialchars( (string) $family, ENT_QUOTES | ENT_XML1, 'UTF-8' );
        $size   = max( 1, (int) $font_size ) * 1024;
        $color  = $this->sanitize_color( $color, '#FFFFFF' );
        if ( 8 === strlen( ltrim( $color, '#' ) ) ) {
            $color = '#' . substr( ltrim( $color, '#' ), 0, 6 );
        }

        // Pango markup is XML — escape < > and & for safe embedding.
        // ImageMagick's pango: coder processes entities in two passes, so
        // literal & must become &amp;amp; to survive both passes.
        $escaped_text = str_replace( array( '&', '<', '>' ), array( '&amp;amp;', '&lt;', '&gt;' ), $text );
        $markup       = '<span foreground="' . $color . '" font_family="' . $family . '" size="' . $size . '" ' . $weight . '>' . $escaped_text . '</span>';

        $tmp_out = self::temp_path( 'cpic_pango_' ) . '.png';
        $cmd     = $bin . ' -density 150 -background transparent ' . escapeshellarg( 'pango:' . $markup ) . ' ' . escapeshellarg( $tmp_out ) . ' 2>&1'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
        @exec( $cmd, $output, $rc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

        if ( 0 !== (int) $rc || ! file_exists( $tmp_out ) ) {
            if ( file_exists( $tmp_out ) ) {
                wp_delete_file( $tmp_out );
            }
            return false;
        }

        try {
            $layer = new Imagick( $tmp_out );
            $layer->setImageFormat( 'png' );
        } catch ( \Throwable $e ) {
            wp_delete_file( $tmp_out );
            return false;
        }

        wp_delete_file( $tmp_out );

        $w = $layer->getImageWidth();
        $h = $layer->getImageHeight();
        if ( $w <= 0 || $h <= 0 ) {
            $layer->clear();
            $layer->destroy();
            return false;
        }

        return $layer;
    }

    /**
     * Build Pango markup for the ImageMagick pango delegate.
     *
     * @param string $font_name Font family name.
     * @param int    $font_size Font size in px (assumed 72 DPI for Pango).
     * @param string $color     Hex colour.
     * @param bool   $bold      Bold flag.
     * @param string $text      Escaped text.
     * @return string
     */
    private function pango_markup( $font_name, $font_size, $color, $bold, $text ) {
        $family = $font_name ? $font_name : 'Noto Serif Bengali';
        $weight = $bold ? 'font_weight="bold"' : '';
        $size   = max( 1, (int) $font_size ) * 1024;
        $color  = $this->sanitize_color( $color, '#FFFFFF' );
        // Pango markup uses CSS-ish #rrggbb for foreground.
        $color  = preg_replace( '/^#([0-9A-Fa-f]{8})$/', '#$1', $color );
        if ( 8 === strlen( ltrim( $color, '#' ) ) ) {
            $color = '#' . substr( ltrim( $color, '#' ), 0, 6 );
        }
        return '<span foreground="' . $color . '" font_family="' . $family . '" size="' . $size . '" ' . $weight . '>' . htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) . '</span>';
    }

    /**
     * Read PNG width/height from the file header.
     *
     * @param string $path PNG path.
     * @return array [ width, height ] or [ 0, 0 ].
     */
    private function png_size( $path ) {
        $h = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if ( ! $h ) {
            return array( 0, 0 );
        }
        $sig = (string) fread( $h, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        $d   = (string) fread( $h, 24 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        if ( strlen( $sig ) >= 8 && strlen( $d ) >= 16 && 0 === strncmp( $sig, "\x89PNG\r\n\x1a\n", 8 ) ) {
            $size = unpack( 'Nwidth/Nheight', substr( $d, 8, 8 ) );
            return array( (int) $size['width'], (int) $size['height'] );
        }
        return array( 0, 0 );
    }

    /**
     * Resolve an executable Python binary.
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
                $path = trim( (string) shell_exec( 'command -v ' . escapeshellarg( $bin ) . ' 2>/dev/null' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
                if ( $path && is_executable( $path ) ) {
                    return $path;
                }
            }
        }

        return '';
    }

    /**
     * Apply an opacity to a colour, returning an 8-digit hex (#RRGGBBAA).
     * 8-digit hex is universally supported by ImageMagick across versions,
     * unlike the CSS `rgba()` string.
     *
     * @param string $color   Hex, rgba() or named colour.
     * @param int    $opacity 0-100.
     * @return string
     */
    private function with_opacity( $color, $opacity ) {
        $color = $this->sanitize_color( $color, '#000000' );

        $hex = ltrim( $color, '#' );
        if ( 3 === strlen( $hex ) && ctype_xdigit( $hex ) ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if ( 6 === strlen( $hex ) && ctype_xdigit( $hex ) ) {
            $alpha = (int) round( max( 0, min( 100, (int) $opacity ) ) / 100 * 255 );
            return sprintf( '#%s%02X', strtoupper( $hex ), $alpha );
        }
        return $color;
    }

    /**
     * Coerce any colour value into something ImagickPixel accepts.
     *
     * @param string $color    Raw colour value.
     * @param string $fallback Default colour.
     * @return string
     */
    private function sanitize_color( $color, $fallback = '#000000' ) {
        $color = trim( (string) $color );

        if ( '' === $color ) {
            // Sanitise the fallback too, so no raw rgba() can reach ImagickPixel.
            $fallback = trim( (string) $fallback );
            return '' === $fallback ? '#000000' : $this->sanitize_color( $fallback, '#000000' );
        }

        // Hex (#RGB / #RRGGBB / #RRGGBBAA, with or without the # prefix).
        // Checked before named colours so shorthand hex like "fff" is handled.
        $hex = ltrim( $color, '#' );
        if ( ctype_xdigit( $hex ) ) {
            if ( 3 === strlen( $hex ) ) {
                return '#' . strtoupper( $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] );
            }
            if ( 6 === strlen( $hex ) || 8 === strlen( $hex ) ) {
                return '#' . strtoupper( $hex );
            }
        }

        // Named colours (Imagick understands these).
        if ( preg_match( '/^[A-Za-z]+$/', $color ) ) {
            return $color;
        }

        // rgb()/rgba() → #RRGGBBAA.
        if ( preg_match( '/^rgba?\(/i', $color ) && preg_match( '/\(([^)]+)\)/', $color, $m ) ) {
            $parts = array_map( 'trim', explode( ',', $m[1] ) );
            if ( count( $parts ) >= 3 && is_numeric( $parts[0] ) && is_numeric( $parts[1] ) && is_numeric( $parts[2] ) ) {
                $r = max( 0, min( 255, (int) $parts[0] ) );
                $g = max( 0, min( 255, (int) $parts[1] ) );
                $b = max( 0, min( 255, (int) $parts[2] ) );
                $a = isset( $parts[3] ) && is_numeric( $parts[3] ) ? (float) $parts[3] : 1;
                $a = $a > 1 ? min( 255, round( $a ) ) : round( $a * 255 );
                return sprintf( '#%02X%02X%02X%02X', $r, $g, $b, (int) $a );
            }
            return $fallback;
        }

        return $fallback;
    }

    /* ─── Font resolution ──────────────────────────────── */

    private function font_family_for_file( $font_file, $requested ) {
        $families = array(
            'NotoSerifBengali-Regular.ttf' => 'Noto Serif Bengali',
            'NotoSansBengali-Regular.ttf'  => 'Noto Sans Bengali',
            'HindSiliguri-Regular.ttf'     => 'Hind Siliguri',
            'SolaimanLipi.ttf'             => 'SolaimanLipi',
            'SiyamRupali.ttf'              => 'Siyam Rupali',
        );
        $basename = $font_file ? basename( $font_file ) : '';
        return isset( $families[ $basename ] ) ? $families[ $basename ] : ( isset( $families[ basename( $this->resolve_font( $requested ) ) ] ) ? $families[ basename( $this->resolve_font( $requested ) ) ] : 'Noto Serif Bengali' );
    }

    private function resolve_font( $font_name ) {
        $map = array(
            'Noto Serif Bengali' => CPIC_PLUGIN_DIR . 'fonts/NotoSerifBengali-Regular.ttf',
            'Hind Siliguri'      => CPIC_PLUGIN_DIR . 'fonts/HindSiliguri-Regular.ttf',
            'SolaimanLipi'       => CPIC_PLUGIN_DIR . 'fonts/SolaimanLipi.ttf',
            'Siyam Rupali'       => CPIC_PLUGIN_DIR . 'fonts/SiyamRupali.ttf',
            'Noto Sans Bengali'  => CPIC_PLUGIN_DIR . 'fonts/NotoSansBengali-Regular.ttf',
        );

        if ( isset( $map[ $font_name ] ) && file_exists( $map[ $font_name ] ) ) {
            return $map[ $font_name ];
        }

        // Never choose an arbitrary filesystem result: use a stable Bengali fallback.
        $fallback = CPIC_PLUGIN_DIR . 'fonts/NotoSerifBengali-Regular.ttf';
        if ( file_exists( $fallback ) ) {
            CPIC_Logger::log( 'imagick', __( 'Requested font unavailable; using the bundled Noto Serif Bengali fallback.', 'custom-post-image-creator' ), array( 'font' => $font_name, 'fallback' => $fallback ) );
            return $fallback;
        }

        $system_fonts = array(
            '/usr/share/fonts/truetype/noto/NotoSerifBengali-Regular.ttf',
            '/usr/share/fonts/truetype/noto/NotoSansBengali-Regular.ttf',
            '/usr/share/fonts/truetype/lohit-bengali/Lohit-Bengali.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        );
        foreach ( $system_fonts as $sf ) {
            if ( file_exists( $sf ) ) {
                return $sf;
            }
        }

        CPIC_Logger::log( 'imagick', __( 'No font file found.', 'custom-post-image-creator' ), array( 'font' => $font_name ) );
        return null;
    }

    /* ─── URL → path ───────────────────────────────────── */

    private function url_to_path( $url ) {
        if ( empty( $url ) ) {
            return null;
        }

        $url = preg_replace( '/\?.*$/', '', $url );

        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];
        $base_dir   = $upload_dir['basedir'];

        if ( strpos( $url, $base_url ) === 0 ) {
            return urldecode( str_replace( $base_url, $base_dir, $url ) );
        }

        $plugin_url = CPIC_PLUGIN_URL;
        $plugin_dir = CPIC_PLUGIN_DIR;

        if ( strpos( $url, $plugin_url ) === 0 ) {
            return urldecode( str_replace( $plugin_url, $plugin_dir, $url ) );
        }

        return null;
    }

    /**
     * Delete a rendered file.
     *
     * @param string $path File path.
     */
    public function cleanup( $path ) {
        if ( $path && file_exists( $path ) ) {
            wp_delete_file( $path );
        }
    }
}