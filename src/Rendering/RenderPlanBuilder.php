<?php
/**
 * Builds the render plan shared by the browser renderer, the server fallback
 * and the export pipeline.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Rendering;

use CPIC\Core\Container;
use CPIC\Templates\TemplateSchema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A *render plan* is a fully resolved, side-effect free description of one
 * image: canvas, ordered layers with resolved text and image URLs, and the
 * font list. Both renderers consume the same plan, which is what guarantees
 * preview == export == published image.
 */
final class RenderPlanBuilder {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Build a plan.
	 *
	 * @param array<string,mixed> $args {
	 *     @type array  $document Design document (validated).
	 *     @type string $format   Output format key.
	 *     @type int    $post_id  Post id (0 for previews).
	 *     @type int    $brand_id Brand id override.
	 *     @type array  $overrides Text overrides keyed by element id.
	 *     @type array  $data     Extra context (campaign...).
	 *     @type int    $scale    Pixel ratio (default 1).
	 * }
	 * @return array<string,mixed>|\WP_Error
	 */
	public function build( array $args ) {
		$document = isset( $args['document'] ) && is_array( $args['document'] ) ? $args['document'] : TemplateSchema::blank();
		$format   = isset( $args['format'] ) ? sanitize_key( (string) $args['format'] ) : 'square';
		$post_id  = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;

		$schema = $this->container->get( 'template_schema' );
		$canvas = $schema->canvas_for_format( $document, $format );

		$elements = $schema->elements_for_format( $document, $format );

		// Brand resolution (template-embedded brand, explicit argument, default).
		$brand = $this->resolve_brand( $document, $args );

		$dynamic  = $this->container->get( 'dynamic_content' );
		$context  = $dynamic->context(
			$post_id,
			array(
				'brand'    => array(
					'name'    => isset( $brand['name'] ) ? (string) $brand['name'] : '',
					'website' => isset( $brand['website_url'] ) ? (string) $brand['website_url'] : '',
				),
				'campaign' => isset( $args['data']['campaign'] ) && is_array( $args['data']['campaign'] ) ? $args['data']['campaign'] : array(),
			)
		);

		$overrides = isset( $args['overrides'] ) && is_array( $args['overrides'] ) ? $args['overrides'] : array();

		$layers = array();
		$fonts  = array();

		foreach ( $elements as $element ) {
			$id = isset( $element['id'] ) ? (string) $element['id'] : '';

			if ( '' === $id ) {
				continue;
			}

			if ( isset( $element['visible'] ) && false === $element['visible'] ) {
				continue;
			}

			if ( ! empty( $element['conditions'] ) && is_array( $element['conditions'] ) ) {
				if ( ! $dynamic->conditions_match( $element['conditions'], $post_id ) ) {
					continue;
				}
			}

			$layer = $this->build_layer( $element, $context, $dynamic, $brand, $post_id, $overrides );

			if ( null === $layer ) {
				continue;
			}

			if ( ! empty( $layer['text']['font_family'] ) ) {
				$fonts[ $layer['text']['font_family'] ] = true;
			}

			$layers[] = $layer;
		}

		$fonts_manifest = $this->container->get( 'fonts' );

		$plan = array(
			'version'     => '7.0',
			'format'      => $canvas['format'],
			'width'       => (int) $canvas['width'],
			'height'      => (int) $canvas['height'],
			'scale'       => isset( $args['scale'] ) ? max( 1, min( 3, (int) $args['scale'] ) ) : 1,
			'canvas'      => $this->build_canvas( $document, $canvas, $brand ),
			'layers'      => $layers,
			'fonts'       => array_keys( $fonts ),
			'font_assets' => $this->font_assets( array_keys( $fonts ) ),
			'brand'       => array(
				'id'       => isset( $brand['id'] ) ? (int) $brand['id'] : 0,
				'name'     => isset( $brand['name'] ) ? (string) $brand['name'] : '',
				'palette'  => isset( $brand['background_palette'] ) ? $brand['background_palette'] : array(),
			),
			'post'        => $context['post'],
			'safe_area'   => $canvas['safe'],
			'generated_at' => current_time( 'mysql' ),
			'font_signature' => $fonts_manifest->signature(),
		);

		/**
		 * Filter the render plan before it reaches a renderer.
		 *
		 * @param array<string,mixed> $plan Plan.
		 * @param array<string,mixed> $args Original arguments.
		 */
		return apply_filters( 'cpic/render_plan', $plan, $args );
	}

	/**
	 * Build a single layer.
	 *
	 * @param array<string,mixed> $element   Element.
	 * @param array<string,mixed> $context   Dynamic context.
	 * @param DynamicContent      $dynamic   Dynamic resolver.
	 * @param array<string,mixed> $brand     Brand row.
	 * @param int                 $post_id   Post id.
	 * @param array<string,mixed> $overrides Text overrides.
	 * @return array<string,mixed>|null
	 */
	private function build_layer( array $element, array $context, $dynamic, array $brand, $post_id, array $overrides ) {
		$type = isset( $element['type'] ) ? (string) $element['type'] : 'text';

		$layer = array(
			'id'         => (string) $element['id'],
			'type'       => $type,
			'name'       => isset( $element['name'] ) ? (string) $element['name'] : '',
			'x'          => isset( $element['x'] ) ? (float) $element['x'] : 0,
			'y'          => isset( $element['y'] ) ? (float) $element['y'] : 0,
			'width'      => isset( $element['width'] ) ? (float) $element['width'] : 0,
			'height'     => isset( $element['height'] ) ? (float) $element['height'] : 0,
			'rotation'   => isset( $element['rotation'] ) ? (float) $element['rotation'] : 0,
			'opacity'    => isset( $element['opacity'] ) ? (float) $element['opacity'] : 1,
			'z'          => isset( $element['z'] ) ? (float) $element['z'] : 0,
			'locked'     => ! empty( $element['locked'] ),
		);

		switch ( $type ) {
			case 'text':
				$text = $this->resolve_text( $element, $context, $dynamic, $overrides, $brand );

				if ( '' === trim( (string) $text ) && empty( $element['background'] ) ) {
					// Nothing to draw. Keep the layer out of the plan entirely.
					return null;
				}

				$layer['text'] = array(
					'value'          => (string) $text,
					'font_family'    => isset( $element['font_family'] ) ? (string) $element['font_family'] : 'Noto Sans Bengali',
					'font_size'      => isset( $element['font_size'] ) ? (float) $element['font_size'] : 40,
					'min_font_size'  => isset( $element['min_font_size'] ) ? (float) $element['min_font_size'] : 18,
					'max_font_size'  => isset( $element['max_font_size'] ) ? (float) $element['max_font_size'] : ( isset( $element['font_size'] ) ? (float) $element['font_size'] : 40 ),
					'font_weight'    => isset( $element['font_weight'] ) ? (int) $element['font_weight'] : 400,
					'font_style'     => isset( $element['font_style'] ) ? (string) $element['font_style'] : 'normal',
					'line_height'    => isset( $element['line_height'] ) ? (float) $element['line_height'] : 1.3,
					'letter_spacing' => isset( $element['letter_spacing'] ) ? (float) $element['letter_spacing'] : 0,
					'align'          => isset( $element['align'] ) ? (string) $element['align'] : 'left',
					'vertical_align' => isset( $element['vertical_align'] ) ? (string) $element['vertical_align'] : 'top',
					'color'          => isset( $element['color'] ) ? (string) $element['color'] : '#FFFFFF',
					'background'     => isset( $element['background'] ) ? (string) $element['background'] : '',
					'padding_x'      => isset( $element['padding_x'] ) ? (float) $element['padding_x'] : 0,
					'padding_y'      => isset( $element['padding_y'] ) ? (float) $element['padding_y'] : 0,
					'border_radius'  => isset( $element['border_radius'] ) ? (float) $element['border_radius'] : 0,
					'border_width'   => isset( $element['border_width'] ) ? (float) $element['border_width'] : 0,
					'border_color'   => isset( $element['border_color'] ) ? (string) $element['border_color'] : '',
					'shadow_color'   => isset( $element['shadow_color'] ) ? (string) $element['shadow_color'] : '',
					'shadow_blur'    => isset( $element['shadow_blur'] ) ? (float) $element['shadow_blur'] : 0,
					'shadow_offset_x' => isset( $element['shadow_offset_x'] ) ? (float) $element['shadow_offset_x'] : 0,
					'shadow_offset_y' => isset( $element['shadow_offset_y'] ) ? (float) $element['shadow_offset_y'] : 0,
					'stroke_color'   => isset( $element['stroke_color'] ) ? (string) $element['stroke_color'] : '',
					'stroke_width'   => isset( $element['stroke_width'] ) ? (float) $element['stroke_width'] : 0,
					'max_lines'      => isset( $element['max_lines'] ) ? (int) $element['max_lines'] : 0,
					'auto_scale'     => ! empty( $element['auto_scale'] ),
					'ellipsis'       => ! empty( $element['ellipsis'] ),
					'uppercase'      => ! empty( $element['uppercase'] ),
				);
				break;

			case 'image':
			case 'logo':
			case 'watermark':
			case 'svg':
				$url = $this->resolve_image( $element, $brand, $post_id );
				$layer['image'] = array(
					'url'           => (string) $url,
					'source'        => isset( $element['source'] ) ? (string) $element['source'] : '',
					'fit'           => isset( $element['fit'] ) ? (string) $element['fit'] : 'cover',
					'focus_x'       => isset( $element['focus_x'] ) ? (float) $element['focus_x'] : 0.5,
					'focus_y'       => isset( $element['focus_y'] ) ? (float) $element['focus_y'] : 0.5,
					'border_radius' => isset( $element['border_radius'] ) ? (float) $element['border_radius'] : 0,
					'border_width'  => isset( $element['border_width'] ) ? (float) $element['border_width'] : 0,
					'border_color'  => isset( $element['border_color'] ) ? (string) $element['border_color'] : '',
					'shadow_blur'   => isset( $element['shadow_blur'] ) ? (float) $element['shadow_blur'] : 0,
					'shadow_color'  => isset( $element['shadow_color'] ) ? (string) $element['shadow_color'] : '',
					'fallback'      => isset( $element['fallback'] ) ? (string) $element['fallback'] : 'hide',
					'natural_ratio' => ! empty( $element['natural_ratio'] ),
				);
				if ( '' === $layer['image']['url'] && 'gradient' !== $layer['image']['fallback'] ) {
					return null;
				}
				break;

			case 'shape':
			case 'gradient':
			case 'pattern':
				$layer['shape'] = array(
					'shape'          => isset( $element['shape'] ) ? (string) $element['shape'] : 'rect',
					'fill'           => isset( $element['fill'] ) ? (string) $element['fill'] : '#FFFFFF',
					'gradient_from'  => isset( $element['gradient_from'] ) ? (string) $element['gradient_from'] : '',
					'gradient_to'    => isset( $element['gradient_to'] ) ? (string) $element['gradient_to'] : '',
					'gradient_angle' => isset( $element['gradient_angle'] ) ? (float) $element['gradient_angle'] : 90,
					'stroke_color'   => isset( $element['stroke_color'] ) ? (string) $element['stroke_color'] : '',
					'stroke_width'   => isset( $element['stroke_width'] ) ? (float) $element['stroke_width'] : 0,
					'border_radius'  => isset( $element['border_radius'] ) ? (float) $element['border_radius'] : 0,
				);
				break;

			case 'group':
				// Groups are flattened by the renderer via `children`.
				$layer['children'] = isset( $element['children'] ) && is_array( $element['children'] ) ? $element['children'] : array();
				break;

			default:
				return null;
		}

		return $layer;
	}

	/**
	 * Resolve text for an element.
	 *
	 * @param array<string,mixed> $element   Element.
	 * @param array<string,mixed> $context   Context.
	 * @param DynamicContent      $dynamic   Dynamic resolver.
	 * @param array<string,mixed> $overrides Overrides keyed by element id.
	 * @param array<string,mixed> $brand     Brand.
	 * @return string
	 */
	private function resolve_text( array $element, array $context, $dynamic, array $overrides, array $brand ) {
		$id = isset( $element['id'] ) ? (string) $element['id'] : '';

		if ( isset( $overrides[ $id ] ) && is_string( $overrides[ $id ] ) ) {
			return $overrides[ $id ];
		}

		$source = isset( $element['source'] ) ? (string) $element['source'] : 'static';
		$value  = '';

		switch ( $source ) {
			case 'post_title':
				$value = $dynamic->resolve( 'post.title', $context );
				break;
			case 'post_excerpt':
				$value = $dynamic->resolve( 'post.excerpt', $context );
				break;
			case 'post_author':
				$value = $dynamic->resolve( 'post.author', $context );
				break;
			case 'post_date':
				$format = isset( $element['date_format'] ) ? (string) $element['date_format'] : 'bangla_long';
				if ( 'site' === $format ) {
					$value = $dynamic->resolve( 'post.date', $context );
				} elseif ( 'iso' === $format ) {
					$value = $context['post']['id'] ? (string) get_the_date( 'Y-m-d', (int) $context['post']['id'] ) : '';
				} elseif ( 'custom' === $format && ! empty( $element['date_format_custom'] ) ) {
					$value = $this->container->get( 'dates' )->format_post_date( (int) $context['post']['id'], 'custom' );
				} else {
					$value = $dynamic->resolve( 'post.date_bangla', $context );
				}
				break;
			case 'post_category':
				$value = $dynamic->resolve( 'post.category', $context );
				break;
			case 'site_name':
				$value = $dynamic->resolve( 'site.name', $context );
				break;
			case 'site_url':
				$value = $dynamic->resolve( 'site.url', $context );
				break;
			case 'post_permalink':
				$value = $dynamic->resolve( 'post.permalink', $context );
				break;
			case 'custom_field':
				$key   = isset( $element['dynamic_key'] ) ? (string) $element['dynamic_key'] : '';
				$value = '' !== $key ? $dynamic->resolve( 'meta:' . $key, $context ) : '';
				break;
			case 'dynamic':
				$key   = isset( $element['dynamic_key'] ) ? (string) $element['dynamic_key'] : '';
				$value = $dynamic->render( '{{' . $key . '}}', $context );
				break;
			case 'static':
			default:
				$value = isset( $element['text'] ) ? (string) $element['text'] : '';
				break;
		}

		// Token support inside static text ("{{post.author}}").
		if ( false !== strpos( (string) $value, '{{' ) ) {
			$value = $dynamic->render( (string) $value, $context );
		}

		if ( '' === trim( (string) $value ) && ! empty( $element['fallback'] ) ) {
			$value = (string) $element['fallback'];
		}

		$value = (string) $value;

		if ( ! empty( $element['trim_words'] ) ) {
			$value = $dynamic->truncate_words( $value, (int) $element['trim_words'] );
		}

		if ( ! empty( $element['uppercase'] ) ) {
			$value = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $value, 'UTF-8' ) : strtoupper( $value );
		}

		if ( isset( $element['transform'] ) && 'capitalize' === $element['transform'] ) {
			$value = function_exists( 'mb_convert_case' ) ? mb_convert_case( $value, MB_CASE_TITLE, 'UTF-8' ) : ucwords( $value );
		}

		// Brand text defaults.
		if ( '' === trim( $value ) && ! empty( $brand['footer_text'] ) && 'brand_footer' === $source ) {
			$value = (string) $brand['footer_text'];
		}

		return $value;
	}

	/**
	 * Resolve an image layer URL.
	 *
	 * @param array<string,mixed> $element Element.
	 * @param array<string,mixed> $brand   Brand.
	 * @param int                 $post_id Post id.
	 * @return string
	 */
	private function resolve_image( array $element, array $brand, $post_id ) {
		$source = isset( $element['source'] ) ? (string) $element['source'] : 'featured_image';
		$url    = '';

		switch ( $source ) {
			case 'featured_image':
				$url = $post_id ? (string) get_the_post_thumbnail_url( $post_id, 'full' ) : '';
				break;

			case 'brand_logo':
				$url = isset( $brand['logo_primary'] ) ? (string) $brand['logo_primary'] : '';
				if ( '' === $url && isset( $brand['logo_secondary'] ) ) {
					$url = (string) $brand['logo_secondary'];
				}
				break;

			case 'brand_logo_light':
				$url = isset( $brand['logo_light'] ) ? (string) $brand['logo_light'] : '';
				if ( '' === $url ) {
					$url = isset( $brand['logo_primary'] ) ? (string) $brand['logo_primary'] : '';
				}
				break;

			case 'brand_logo_dark':
				$url = isset( $brand['logo_dark'] ) ? (string) $brand['logo_dark'] : '';
				if ( '' === $url ) {
					$url = isset( $brand['logo_primary'] ) ? (string) $brand['logo_primary'] : '';
				}
				break;

			case 'brand_watermark':
				$url = isset( $brand['watermark_url'] ) ? (string) $brand['watermark_url'] : '';
				break;

			case 'asset':
				$asset_id = isset( $element['asset_id'] ) ? (int) $element['asset_id'] : 0;
				if ( $asset_id ) {
					$asset = $this->container->get( 'assets' )->find( $asset_id );
					if ( $asset ) {
						$url = (string) $asset['url'];
					}
				}
				break;

			case 'custom_url':
				$url = isset( $element['url'] ) ? (string) $element['url'] : '';
				break;

			case 'post_meta_image':
				$key = isset( $element['dynamic_key'] ) ? sanitize_key( (string) $element['dynamic_key'] ) : '';
				if ( '' !== $key && $post_id ) {
					$url = (string) get_post_meta( $post_id, $key, true );
				}
				break;

			case 'none':
				$url = '';
				break;
		}

		if ( '' !== $url ) {
			$url = esc_url_raw( $url );
		}

		/**
		 * Filter a resolved layer image URL.
		 *
		 * @param string              $url     URL.
		 * @param array<string,mixed> $element Element.
		 * @param array<string,mixed> $brand   Brand.
		 */
		return (string) apply_filters( 'cpic/render_image_url', $url, $element, $brand );
	}

	/**
	 * Resolve the brand for a document.
	 *
	 * @param array<string,mixed> $document Document.
	 * @param array<string,mixed> $args     Build arguments.
	 * @return array<string,mixed>
	 */
	public function resolve_brand( array $document, array $args ) {
		$repo = $this->container->get( 'brands' );

		$brand_id = 0;

		if ( ! empty( $args['brand_id'] ) ) {
			$brand_id = (int) $args['brand_id'];
		} elseif ( ! empty( $document['brand']['id'] ) ) {
			$brand_id = (int) $document['brand']['id'];
		}

		$row = $brand_id ? $repo->find( $brand_id ) : null;

		if ( ! $row ) {
			$row = $repo->default_brand();
		}

		if ( ! $row ) {
			return array( 'id' => 0, 'name' => get_bloginfo( 'name' ) );
		}

		$brand = $repo->hydrate( $row );

		// Templates may override individual tokens without duplicating the kit.
		if ( ! empty( $document['brand']['overrides'] ) && is_array( $document['brand']['overrides'] ) ) {
			foreach ( $document['brand']['overrides'] as $key => $value ) {
				if ( isset( $brand[ $key ] ) && is_scalar( $value ) ) {
					$brand[ $key ] = $value;
				}
			}
		}

		$watermark_id = isset( $brand['watermark_asset_id'] ) ? (int) $brand['watermark_asset_id'] : 0;
		if ( $watermark_id ) {
			$asset = $this->container->get( 'assets' )->find( $watermark_id );
			$brand['watermark_url'] = $asset ? (string) $asset['url'] : '';
		}

		return $brand;
	}

	/**
	 * Canvas description for the renderer.
	 *
	 * @param array<string,mixed> $document Document.
	 * @param array<string,mixed> $canvas   Resolved canvas.
	 * @param array<string,mixed> $brand    Brand.
	 * @return array<string,mixed>
	 */
	private function build_canvas( array $document, array $canvas, array $brand ) {
		$src = isset( $document['canvas'] ) && is_array( $document['canvas'] ) ? $document['canvas'] : TemplateSchema::default_canvas();

		$out = array(
			'background_type'  => isset( $src['background_type'] ) ? (string) $src['background_type'] : 'gradient',
			'background_color' => isset( $src['background_color'] ) ? (string) $src['background_color'] : '#0F172A',
			'gradient_from'    => isset( $src['gradient_from'] ) ? (string) $src['gradient_from'] : '#667EEA',
			'gradient_to'      => isset( $src['gradient_to'] ) ? (string) $src['gradient_to'] : '#764BA2',
			'gradient_angle'   => isset( $src['gradient_angle'] ) ? (float) $src['gradient_angle'] : 135,
			'background_image' => isset( $src['background_image'] ) ? (string) $src['background_image'] : '',
			'background_fit'   => isset( $src['background_fit'] ) ? (string) $src['background_fit'] : 'cover',
			'overlay'          => array(
				'enabled' => ! empty( $src['overlay']['enabled'] ),
				'from'    => isset( $src['overlay']['from'] ) ? (string) $src['overlay']['from'] : 'rgba(9,12,24,0.05)',
				'to'      => isset( $src['overlay']['to'] ) ? (string) $src['overlay']['to'] : 'rgba(9,12,24,0.82)',
				'angle'   => isset( $src['overlay']['angle'] ) ? (float) $src['overlay']['angle'] : 90,
			),
			'grid'             => ! empty( $src['grid'] ),
		);

		// Brand colours can drive the canvas when the template opts in.
		if ( ! empty( $src['use_brand_colors'] ) ) {
			$out['gradient_from']    = isset( $brand['primary_color'] ) ? $brand['primary_color'] : $out['gradient_from'];
			$out['gradient_to']      = isset( $brand['secondary_color'] ) ? $brand['secondary_color'] : $out['gradient_to'];
			$out['background_color'] = isset( $brand['primary_color'] ) ? $brand['primary_color'] : $out['background_color'];
		}

		return $out;
	}

	/**
	 * Font files required by the plan (for preloading in the browser).
	 *
	 * @param array<int,string> $families Families.
	 * @return array<int,array<string,mixed>>
	 */
	private function font_assets( array $families ) {
		$manager = $this->container->get( 'fonts' );
		$assets  = array();

		foreach ( $families as $family ) {
			$font = $manager->resolve( $family );
			if ( ! $font ) {
				continue;
			}
			$assets[] = array(
				'family' => $font['family'],
				'weight' => (int) $font['weight'],
				'url'    => (string) $font['url'],
				'format' => isset( $font['format'] ) ? (string) $font['format'] : 'truetype',
			);
		}

		return $assets;
	}

	/**
	 * Serialize a plan for JavaScript.
	 *
	 * @param array<string,mixed> $plan Plan.
	 * @return array<string,mixed>
	 */
	public function for_javascript( array $plan ) {
		foreach ( $plan['layers'] as &$layer ) {
			if ( isset( $layer['image']['url'] ) ) {
				$layer['image']['url'] = esc_url_raw( $layer['image']['url'] );
			}
		}
		unset( $layer );

		if ( isset( $plan['canvas']['background_image'] ) ) {
			$plan['canvas']['background_image'] = esc_url_raw( $plan['canvas']['background_image'] );
		}

		return $plan;
	}
}
