<?php
/**
 * Built-in starter template library.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Templates;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ships twelve real, editable starter designs (not placeholders): each one is
 * generated from the same design-document schema the Studio edits.
 */
final class StarterLibrary {

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
	 * Collections shipped with the plugin.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function collections() {
		return array(
			'news-media'       => array( 'label' => __( 'News Media', 'custom-post-image-creator' ), 'category' => 'news' ),
			'newspaper'        => array( 'label' => __( 'Newspaper', 'custom-post-image-creator' ), 'category' => 'news' ),
			'magazine'         => array( 'label' => __( 'Magazine', 'custom-post-image-creator' ), 'category' => 'editorial' ),
			'corporate'        => array( 'label' => __( 'Corporate', 'custom-post-image-creator' ), 'category' => 'business' ),
			'education'        => array( 'label' => __( 'Education', 'custom-post-image-creator' ), 'category' => 'education' ),
			'islamic'          => array( 'label' => __( 'Islamic Content', 'custom-post-image-creator' ), 'category' => 'religion' ),
			'political'        => array( 'label' => __( 'Political Campaign', 'custom-post-image-creator' ), 'category' => 'politics' ),
			'event'            => array( 'label' => __( 'Event Promotion', 'custom-post-image-creator' ), 'category' => 'events' ),
			'announcement'     => array( 'label' => __( 'Announcement', 'custom-post-image-creator' ), 'category' => 'general' ),
			'breaking'         => array( 'label' => __( 'Breaking News', 'custom-post-image-creator' ), 'category' => 'news' ),
			'quotes'           => array( 'label' => __( 'Quotes', 'custom-post-image-creator' ), 'category' => 'editorial' ),
			'research'         => array( 'label' => __( 'Research & Publications', 'custom-post-image-creator' ), 'category' => 'education' ),
		);
	}

	/**
	 * Seed starter templates when the site has no starter templates yet.
	 *
	 * @return int Number of templates created.
	 */
	public function maybe_seed() {
		$manager = $this->container->get( 'template_manager' );

		$already = $manager->repo()->count( array( 'collection' => 'news-media' ) );
		if ( $already > 0 ) {
			return 0;
		}

		$created = 0;

		foreach ( $this->designs() as $slug => $design ) {
			$existing = $manager->repo()->find_by_slug( $slug );
			if ( $existing ) {
				continue;
			}

			$result = $manager->create(
				array(
					'name'        => $design['name'],
					'slug'        => $slug,
					'document'    => $design['document'],
					'description' => $design['description'],
					'collection'  => $design['collection'],
					'category'    => $design['category'],
					'tags'        => $design['tags'],
					'is_active'   => 1,
				)
			);

			if ( ! is_wp_error( $result ) ) {
				$created++;
			}
		}

		return $created;
	}

	/**
	 * Starter design definitions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function designs() {
		$schema = $this->container->get( 'template_schema' );

		$designs = array();

		/* 1. News Media — image-led square card */
		$doc = TemplateSchema::blank( 'square' );
		$doc['canvas']['background_type'] = 'color';
		$doc['canvas']['background_color'] = '#0B1220';
		$doc = $this->style_headline( $doc, array( 'font_family' => 'Noto Sans Bengali', 'font_size' => 58, 'color' => '#FFFFFF', 'max_lines' => 4 ) );
		$doc = $this->add_shape( $doc, 'top_rule', array( 'y' => 0, 'height' => 10, 'width' => 1080, 'fill' => '#E11D48', 'x' => 0, 'z' => 50 ) );
		$doc = $this->set_element( $doc, 'featured_image', array( 'height' => 620, 'fit' => 'cover' ) );
		$doc = $this->set_element( $doc, 'headline', array( 'y' => 690, 'height' => 300 ) );
		$doc = $this->set_element( $doc, 'date', array( 'y' => 1000 ) );
		$designs['news-media-headline'] = array(
			'name'        => __( 'News Media — Headline Card', 'custom-post-image-creator' ),
			'description' => __( 'Image-first news card with a red top rule, category chip and Bengali headline.', 'custom-post-image-creator' ),
			'collection'  => 'news-media',
			'category'    => 'news',
			'tags'        => array( 'news', 'headline', 'square' ),
			'document'    => $schema->validate( $doc )['document'],
		);

		/* 2. Newspaper — masthead style */
		$doc = TemplateSchema::blank( 'square' );
		$doc['canvas']['background_type'] = 'color';
		$doc['canvas']['background_color'] = '#F8F5EF';
		$doc = $this->set_element( $doc, 'headline', array( 'font_family' => 'Noto Serif Bengali', 'font_size' => 66, 'color' => '#111827', 'y' => 300, 'max_lines' => 5, 'shadow_color' => '', 'shadow_blur' => 0 ) );
		$doc = $this->set_element( $doc, 'category', array( 'background' => '#111827', 'color' => '#F8F5EF', 'y' => 230, 'font_size' => 24 ) );
		$doc = $this->set_element( $doc, 'date', array( 'background' => '', 'color' => '#374151', 'y' => 960, 'font_size' => 24 ) );
		$doc = $this->set_element( $doc, 'featured_image', array( 'y' => 690, 'height' => 260, 'width' => 952, 'x' => 64 ) );
		$doc = $this->set_element( $doc, 'logo', array( 'x' => 64, 'y' => 80, 'width' => 220 ) );
		$doc = $this->set_element( $doc, 'accent_bar', array( 'y' => 660, 'width' => 952, 'fill' => '#111827', 'height' => 3 ) );
		$designs['newspaper-front'] = array(
			'name'        => __( 'Newspaper — Front Page', 'custom-post-image-creator' ),
			'description' => __( 'Serif masthead layout with a rule, headline block and inset photo.', 'custom-post-image-creator' ),
			'collection'  => 'newspaper',
			'category'    => 'news',
			'tags'        => array( 'newspaper', 'serif', 'light' ),
			'document'    => $schema->validate( $doc )['document'],
		);

		/* 3. Magazine — portrait editorial */
		$doc = TemplateSchema::blank( 'portrait' );
		$doc['canvas']['background_type'] = 'gradient';
		$doc['canvas']['gradient_from'] = '#1E1B4B';
		$doc['canvas']['gradient_to'] = '#7C2D12';
		$doc = $this->set_element( $doc, 'headline', array( 'font_family' => 'Noto Serif Bengali', 'font_size' => 76, 'y' => 760, 'height' => 380, 'max_lines' => 5, 'align' => 'left' ) );
		$doc = $this->set_element( $doc, 'featured_image', array( 'height' => 800 ) );
		$doc = $this->set_element( $doc, 'category', array( 'background' => '#F59E0B', 'color' => '#111827', 'y' => 700, 'uppercase' => true ) );
		$doc = $this->set_element( $doc, 'accent_bar', array( 'y' => 730, 'fill' => '#F59E0B', 'width' => 360 ) );
		$designs['magazine-editorial'] = array(
			'name'        => __( 'Magazine — Editorial Spread', 'custom-post-image-creator' ),
			'description' => __( '4:5 portrait cover with a full-bleed image and stacked serif headline.', 'custom-post-image-creator' ),
			'collection'  => 'magazine',
			'category'    => 'editorial',
			'tags'        => array( 'magazine', 'portrait', 'editorial' ),
			'document'    => $schema->validate( $doc )['document'],
		);

		/* 4. Corporate — clean gradient with logo */
		$doc = TemplateSchema::blank( 'landscape' );
		$doc['canvas']['background_type'] = 'gradient';
		$doc['canvas']['gradient_from'] = '#0EA5E9';
		$doc['canvas']['gradient_to'] = '#1E3A8A';
		$doc = $this->set_element( $doc, 'headline', array( 'font_family' => 'Hind Siliguri', 'font_size' => 54, 'y' => 220, 'height' => 240, 'max_lines' => 3, 'align' => 'left' ) );
		$doc = $this->set_element( $doc, 'featured_image', array( 'width' => 420, 'height' => 420, 'x' => 700, 'y' => 130, 'fit' => 'cover', 'border_radius' => 24 ) );
		$doc = $this->set_element( $doc, 'category', array( 'background' => '#F8FAFC', 'color' => '#1E3A8A', 'y' => 150 ) );
		$doc = $this->set_element( $doc, 'date', array( 'y' => 560, 'color' => '#E0F2FE', 'font_size' => 22 ) );
		$doc = $this->set_element( $doc, 'logo', array( 'x' => 56, 'y' => 480, 'width' => 150 ) );
		$designs['corporate-announcement'] = array(
			'name'        => __( 'Corporate — Announcement', 'custom-post-image-creator' ),
			'description' => __( '16:9 business card with a blue gradient, inset photo and logo lockup.', 'custom-post-image-creator' ),
			'collection'  => 'corporate',
			'category'    => 'business',
			'tags'        => array( 'corporate', 'landscape', 'gradient' ),
			'document'    => $schema->validate( $doc )['document'],
		);

		/* 5. Education */
		$doc = TemplateSchema::blank( 'square' );
		$doc['canvas']['background_type'] = 'color';
		$doc['canvas']['background_color'] = '#F0FDF4';
		$doc = $this->set_element( $doc, 'headline', array( 'font_family' => 'Noto Sans Bengali', 'font_size' => 54, 'color' => '#064E3B', 'y' => 620, 'max_lines' => 5, 'shadow_blur' => 0, 'shadow_color' => '' ) );
		$doc = $this->set_element( $doc, 'category', array( 'background' => '#047857', 'color' => '#ECFDF5', 'y' => 550 ) );
		$doc = $this->set_element( $doc, 'date', array( 'background' => '', 'color' => '#065F46', 'y' => 970 ) );
		$doc = $this->set_element( $doc, 'featured_image', array( 'height' => 500, 'border_radius' => 0 ) );
		$doc = $this->set_element( $doc, 'accent_bar', array( 'fill' => '#047857', 'y' => 1000, 'width' => 200 ) );
		$designs['education-notice'] = array(
			'name'        => __( 'Education — Notice', 'custom-post-image-creator' ),
			'description' => __( 'Friendly green notice card for schools, universities and courses.', 'custom-post-image-creator' ),
			'collection'  => 'education',
			'category'    => 'education',
			'tags'        => array( 'education', 'notice', 'light' ),
			'document'    => $schema->validate( $doc )['document'],
		);

		/* 6. Islamic content */
		$doc = TemplateSchema::blank( 'portrait' );
		$doc['canvas']['background_type'] = 'gradient';
		$doc['canvas']['gradient_from'] = '#064E3B';
		$doc['canvas']['gradient_to'] = '#022C22';
		$doc = $this->set_element( $doc, 'headline', array( 'font_family' => 'Noto Serif Bengali', 'font_size' => 68, 'y' => 520, 'height' => 640, 'max_lines' => 6, 'align' => 'center', 'x' => 90, 'width' => 900 ) );
		$doc = $this->set_element( $doc, 'featured_image', array( 'height' => 420, 'fit' => 'cover' ) );
		$doc = $this->set_element( $doc, 'category', array( 'background' => '#D4AF37', 'color' => '#052E16', 'y' => 460, 'x' => 440, 'width' => 200, 'align' => 'center' ) );
		$doc = $this->set_element( $doc, 'date', array( 'color' => '#D4AF37', 'x' => 340, 'width' => 400, 'align' => 'center', 'y' => 1250 ) );
		$doc = $this->set_element( $doc, 'accent_bar', array( 'fill' => '#D4AF37', 'x' => 440, 'width' => 200, 'y' => 440, 'height' => 4 ) );
		$designs['islamic-quote'] = array(
			'name'        => __( 'Islamic — Reflection Card', 'custom-post-image-creator' ),
			'description' => __( 'Centred serif layout with gold accents on deep green, ideal for reflections.', 'custom-post-image-creator' ),
			'collection'  => 'islamic',
			'category'    => 'religion',
			'tags'        => array( 'islamic', 'quote', 'portrait' ),
			'document'    => $schema->validate( $doc )['document'],
		);

		/* 7. Political campaign */
		$doc = TemplateSchema::blank( 'square' );
		$doc['canvas']['background_type'] = 'gradient';
		$doc['canvas']['gradient_from'] = '#7F1D1D';
		$doc['canvas']['gradient_to'] = '#1F2937';
		$doc = $this->set_element( $doc, 'headline', array( 'font_family' => 'Noto Sans Bengali', 'font_size' => 62, 'y' => 640, 'height' => 340, 'max_lines' => 4, 'font_weight' => 700 ) );
		$doc = $this->set_element( $doc, 'category', array( 'background' => '#FBBF24', 'color' => '#111827', 'y' => 570 ) );
		$doc = $this->set_element( $doc, 'featured_image', array( 'height' => 540 ) );
		$doc = $this->set_element( $doc, 'logo', array( 'x' => 64, 'y' => 64, 'width' => 130 ) );
		$doc = $this->set_element( $doc, 'date', array( 'background' => '', 'color' => '#FDE68A', 'y' => 990 ) );
		$designs['political-campaign'] = array(
			'name'        => __( 'Political — Campaign Card', 'custom-post-image-creator' ),
			'description' => __( 'High-contrast campaign card with gold chip and heavy headline weight.', 'custom-post-image-creator' ),
			'collection'  => 'political',
			'category'    => 'politics',
			'tags'        => array( 'political', 'campaign', 'bold' ),
			'document'    => $schema->validate( $doc )['document'],
		);

		/* 8. Event promotion */
		$doc = TemplateSchema::blank( 'story' );
		$doc['canvas']['background_type'] = 'gradient';
		$doc['canvas']['gradient_from'] = '#4338CA';
		$doc['canvas']['gradient_to'] = '#DB2777';
		$doc = $this->set_element( $doc, 'headline', array( 'font_family' => 'Noto Sans Bengali', 'font_size' => 86, 'y' => 900, 'height' => 520, 'max_lines' => 5, 'align' => 'center', 'x' => 90, 'width' => 900 ) );
		$doc = $this->set_element( $doc, 'featured_image', array( 'height' => 820, 'y' => 260, 'width' => 960, 'x' => 60, 'border_radius' => 40 ) );
		$doc = $this->set_element( $doc, 'category', array( 'background' => '#FFFFFF', 'color' => '#4338CA', 'y' => 800, 'x' => 390, 'width' => 300, 'align' => 'center', 'letter_spacing' => 2 ) );
		$doc = $this->set_element( $doc, 'date', array( 'color' => '#FFFFFF', 'x' => 290, 'width' => 500, 'align' => 'center', 'y' => 1460, 'font_size' => 30 ) );
		$designs['event-story'] = array(
			'name'        => __( 'Event — Story Promo', 'custom-post-image-creator' ),
			'description' => __( '9:16 story with rounded image card, centred headline and date line.', 'custom-post-image-creator' ),
			'collection'  => 'event',
			'category'    => 'events',
			'tags'        => array( 'event', 'story', 'promo' ),
			'document'    => $schema->validate( $doc )['document'],
		);

		/* 9. Announcement */
		$doc = TemplateSchema::blank( 'square' );
		$doc['canvas']['background_type'] = 'color';
		$doc['canvas']['background_color'] = '#FFFFFF';
		$doc = $this->set_element( $doc, 'headline', array( 'font_family' => 'Hind Siliguri', 'font_size' => 60, 'color' => '#0F172A', 'y' => 340, 'height' => 420, 'max_lines' => 5, 'shadow_blur' => 0, 'shadow_color' => '' ) );
		$doc = $this->set_element( $doc, 'category', array( 'background' => '#0F172A', 'color' => '#FFFFFF', 'y' => 270, 'letter_spacing' => 1 ) );
		$doc = $this->set_element( $doc, 'date', array( 'background' => '', 'color' => '#475569', 'y' => 840 ) );
		$doc = $this->set_element( $doc, 'featured_image', array( 'visible' => false ) );
		$doc = $this->set_element( $doc, 'accent_bar', array( 'fill' => '#0EA5E9', 'y' => 800, 'width' => 120, 'height' => 10 ) );
		$doc = $this->set_element( $doc, 'logo', array( 'x' => 64, 'y' => 900, 'width' => 140 ) );
		$designs['announcement-clean'] = array(
			'name'        => __( 'Announcement — Clean Type', 'custom-post-image-creator' ),
			'description' => __( 'Typography-only white card for statements and official notices.', 'custom-post-image-creator' ),
			'collection'  => 'announcement',
			'category'    => 'general',
			'tags'        => array( 'announcement', 'minimal', 'light' ),
			'document'    => $schema->validate( $doc )['document'],
		);

		/* 10. Breaking news */
		$doc = TemplateSchema::blank( 'landscape' );
		$doc['canvas']['background_type'] = 'color';
		$doc['canvas']['background_color'] = '#B91C1C';
		$doc = $this->set_element( $doc, 'headline', array( 'font_family' => 'Noto Sans Bengali', 'font_size' => 66, 'color' => '#FFFFFF', 'y' => 220, 'height' => 340, 'max_lines' => 3, 'x' => 56, 'width' => 700 ) );
		$doc = $this->set_element( $doc, 'featured_image', array( 'width' => 380, 'height' => 380, 'x' => 780, 'y' => 150, 'border_radius' => 16 ) );
		$doc = $this->set_element( $doc, 'category', array( 'text' => 'ব্রেকিং', 'background' => '#FEF2F2', 'color' => '#B91C1C', 'y' => 120, 'font_size' => 26, 'letter_spacing' => 2 ) );
		$doc = $this->set_element( $doc, 'date', array( 'background' => '', 'color' => '#FEE2E2', 'y' => 580, 'x' => 56, 'font_size' => 22 ) );
		$doc = $this->set_element( $doc, 'accent_bar', array( 'fill' => '#FBBF24', 'y' => 590, 'width' => 160, 'height' => 8 ) );
		$designs['breaking-news'] = array(
			'name'        => __( 'Breaking News — Alert', 'custom-post-image-creator' ),
			'description' => __( 'Red alert landscape card with a breaking-news chip and inset photo.', 'custom-post-image-creator' ),
			'collection'  => 'breaking',
			'category'    => 'news',
			'tags'        => array( 'breaking', 'urgent', 'landscape' ),
			'document'    => $schema->validate( $doc )['document'],
		);

		/* 11. Quotes */
		$doc = TemplateSchema::blank( 'square' );
		$doc['canvas']['background_type'] = 'gradient';
		$doc['canvas']['gradient_from'] = '#111827';
		$doc['canvas']['gradient_to'] = '#374151';
		$doc = $this->set_element( $doc, 'featured_image', array( 'visible' => false ) );
		$doc = $this->set_element( $doc, 'headline', array( 'font_family' => 'Noto Serif Bengali', 'font_size' => 68, 'y' => 280, 'height' => 520, 'x' => 110, 'width' => 860, 'align' => 'center', 'max_lines' => 6, 'line_height' => 1.5 ) );
		$doc = $this->set_element( $doc, 'category', array( 'background' => '', 'color' => '#9CA3AF', 'y' => 900, 'x' => 240, 'width' => 600, 'align' => 'center', 'uppercase' => true, 'letter_spacing' => 3, 'font_size' => 22 ) );
		$doc = $this->set_element( $doc, 'date', array( 'background' => '', 'color' => '#6B7280', 'x' => 340, 'width' => 400, 'align' => 'center', 'y' => 960, 'font_size' => 20 ) );
		$doc = $this->set_element( $doc, 'accent_bar', array( 'fill' => '#F59E0B', 'x' => 490, 'width' => 100, 'y' => 220, 'height' => 6 ) );
		$designs['quote-card'] = array(
			'name'        => __( 'Quotes — Centred Reflection', 'custom-post-image-creator' ),
			'description' => __( 'Typographic quote card with generous line height and a gold rule.', 'custom-post-image-creator' ),
			'collection'  => 'quotes',
			'category'    => 'editorial',
			'tags'        => array( 'quote', 'serif', 'dark' ),
			'document'    => $schema->validate( $doc )['document'],
		);

		/* 12. Research */
		$doc = TemplateSchema::blank( 'portrait' );
		$doc['canvas']['background_type'] = 'color';
		$doc['canvas']['background_color'] = '#0C4A6E';
		$doc = $this->set_element( $doc, 'headline', array( 'font_family' => 'Noto Sans Bengali', 'font_size' => 62, 'color' => '#F0F9FF', 'y' => 420, 'height' => 420, 'max_lines' => 5 ) );
		$doc = $this->set_element( $doc, 'featured_image', array( 'height' => 360, 'fit' => 'cover' ) );
		$doc = $this->set_element( $doc, 'category', array( 'background' => '#38BDF8', 'color' => '#082F49', 'y' => 350, 'text' => 'গবেষণা' ) );
		$doc = $this->set_element( $doc, 'date', array( 'background' => '', 'color' => '#7DD3FC', 'y' => 1180 ) );
		$doc = $this->set_element( $doc, 'logo', array( 'x' => 64, 'y' => 900, 'width' => 160 ) );
		$doc = $this->set_element( $doc, 'accent_bar', array( 'fill' => '#38BDF8', 'y' => 390, 'width' => 240 ) );
		$designs['research-publication'] = array(
			'name'        => __( 'Research — Publication', 'custom-post-image-creator' ),
			'description' => __( 'Portrait research card with a sky-blue accent and institutional logo space.', 'custom-post-image-creator' ),
			'collection'  => 'research',
			'category'    => 'education',
			'tags'        => array( 'research', 'publication', 'portrait' ),
			'document'    => $schema->validate( $doc )['document'],
		);

		/**
		 * Filter the starter template library (add-ons may append designs).
		 *
		 * @param array<string,array<string,mixed>> $designs Designs keyed by slug.
		 */
		return apply_filters( 'cpic/starter_library', $designs );
	}

	/**
	 * Apply headline styling helpers.
	 *
	 * @param array<string,mixed> $doc   Document.
	 * @param array<string,mixed> $props Element props.
	 * @return array<string,mixed>
	 */
	private function style_headline( array $doc, array $props ) {
		return $this->set_element( $doc, 'headline', $props );
	}

	/**
	 * Set properties on an element, creating it from defaults if missing.
	 *
	 * @param array<string,mixed> $doc   Document.
	 * @param string              $id    Element id.
	 * @param array<string,mixed> $props Props.
	 * @return array<string,mixed>
	 */
	private function set_element( array $doc, $id, array $props ) {
		if ( ! isset( $doc['elements'][ $id ] ) ) {
			$doc['elements'][ $id ] = TemplateSchema::default_text();
			$doc['elements'][ $id ]['id'] = $id;
		}

		$doc['elements'][ $id ] = array_merge( $doc['elements'][ $id ], $props );

		return $doc;
	}

	/**
	 * Add a shape element.
	 *
	 * @param array<string,mixed> $doc   Document.
	 * @param string              $id    Element id.
	 * @param array<string,mixed> $props Props.
	 * @return array<string,mixed>
	 */
	private function add_shape( array $doc, $id, array $props ) {
		$shape = TemplateSchema::default_shape();
		$shape['id'] = $id;

		$doc['elements'][ $id ] = array_merge( $shape, $props );

		return $doc;
	}
}
