<?php
/**
 * WordPress API stubs for the plugin test-suite.
 *
 * Only the WordPress surface the plugin actually calls is implemented. Hook
 * behaviour, options, post meta, posts and users are stored in real arrays so
 * services can be exercised end to end.
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', ( getenv( 'CPIC_TEST_TMP' ) ? rtrim( (string) getenv( 'CPIC_TEST_TMP' ), '/' ) : '/tmp/cpic-wp' ) . '/wp/' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}
if ( ! defined( 'MONTH_IN_SECONDS' ) ) {
	define( 'MONTH_IN_SECONDS', 2592000 );
}
if ( ! defined( 'YEAR_IN_SECONDS' ) ) {
	define( 'YEAR_IN_SECONDS', 31536000 );
}
if ( ! defined( 'MB_IN_BYTES' ) ) {
	define( 'MB_IN_BYTES', 1024 * 1024 );
}
if ( ! defined( 'PHP_INT_MAX' ) ) {
	define( 'PHP_INT_MAX', 9223372036854775807 );
}
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'test-auth-key-0123456789' );
}
if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
	define( 'SECURE_AUTH_KEY', 'test-secure-auth-key-0123456789' );
}
if ( ! defined( 'NONCE_SALT' ) ) {
	define( 'NONCE_SALT', 'test-nonce-salt-0123456789' );
}
if ( ! defined( 'DB_NAME' ) ) {
	define( 'DB_NAME', 'cpic_test' );
}
if ( ! defined( 'CPIC_PLUGIN_DIR' ) ) {
	define( 'CPIC_PLUGIN_DIR', rtrim( getenv( 'CPIC_ROOT' ) ? getenv( 'CPIC_ROOT' ) : '/plugin', '/' ) . '/' );
}
if ( ! defined( 'CPIC_PLUGIN_URL' ) ) {
	define( 'CPIC_PLUGIN_URL', 'https://example.test/wp-content/plugins/custom-post-image-creator/' );
}
if ( ! defined( 'CPIC_VERSION' ) ) {
	define( 'CPIC_VERSION', '7.0.0' );
}
if ( ! defined( 'CPIC_DB_VERSION' ) ) {
	define( 'CPIC_DB_VERSION', '7.0.0' );
}
if ( ! defined( 'CPIC_MIN_PHP' ) ) {
	define( 'CPIC_MIN_PHP', '7.4' );
}
if ( ! defined( 'CPIC_MIN_WP' ) ) {
	define( 'CPIC_MIN_WP', '5.8' );
}
if ( ! defined( 'CPIC_REST_NAMESPACE' ) ) {
	define( 'CPIC_REST_NAMESPACE', 'cpic/v1' );
}

/**
 * Test runtime state.
 */
final class CPIC_Test_State {

	/**
	 * Registered hooks.
	 *
	 * @var array<string,array<string,array<int,array{0:callable,1:int}>>>
	 */
	public static $hooks = array();

	/**
	 * Options.
	 *
	 * @var array<string,mixed>
	 */
	public static $options = array();

	/**
	 * Transients.
	 *
	 * @var array<string,mixed>
	 */
	public static $transients = array();

	/**
	 * Posts.
	 *
	 * @var array<int,WP_Post>
	 */
	public static $posts = array();

	/**
	 * Post meta.
	 *
	 * @var array<int,array<string,array<int,mixed>>>
	 */
	public static $post_meta = array();

	/**
	 * Terms by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static $terms = array();

	/**
	 * Term relationships (post id => term ids).
	 *
	 * @var array<int,array<int,int>>
	 */
	public static $post_terms = array();

	/**
	 * Thumbnails (post id => attachment id).
	 *
	 * @var array<int,int>
	 */
	public static $thumbnails = array();

	/**
	 * Attachment URLs.
	 *
	 * @var array<int,string>
	 */
	public static $attachment_urls = array();

	/**
	 * Attachment files.
	 *
	 * @var array<int,string>
	 */
	public static $attachment_files = array();

	/**
	 * Current user id.
	 *
	 * @var int
	 */
	public static $user_id = 1;

	/**
	 * User capabilities.
	 *
	 * @var array<string,bool>
	 */
	public static $capabilities = array();

	/**
	 * Uploads directory.
	 *
	 * @var string
	 */
	public static $uploads_dir = '';

	/**
	 * Scheduled events.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public static $scheduled = array();

	/**
	 * Actions registered for shutdown.
	 *
	 * @var array<int,array{0:string,1:array}>
	 */
	public static $shutdown = array();

	/**
	 * REST routes registered.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public static $rest_routes = array();

	/**
	 * Reset all state between tests.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$hooks       = array();
		self::$options     = array();
		self::$transients  = array();
		self::$posts       = array();
		self::$post_meta   = array();
		self::$terms       = array();
		self::$post_terms  = array();
		self::$thumbnails  = array();
		self::$scheduled   = array();
		self::$rest_routes = array();
		self::$shutdown    = array();
		self::$capabilities = array();
		self::$uploaded    = array();
		self::$enqueued    = array();
		self::$localized   = array();
		self::$redirects   = array();
		self::$menu        = array();
		self::$meta_boxes  = array();
	}

	/**
	 * Uploaded files during the request.
	 *
	 * @var array
	 */
	public static $uploaded = array();

	/**
	 * Enqueued assets.
	 *
	 * @var array<string,mixed>
	 */
	public static $enqueued = array();

	/**
	 * Localised script data.
	 *
	 * @var array<string,mixed>
	 */
	public static $localized = array();

	/**
	 * Redirects requested.
	 *
	 * @var array<int,string>
	 */
	public static $redirects = array();

	/**
	 * Registered admin menu entries.
	 *
	 * @var array<string,mixed>
	 */
	public static $menu = array();

	/**
	 * Registered meta boxes.
	 *
	 * @var array<string,mixed>
	 */
	public static $meta_boxes = array();
}

/**
 * Sent JSON payloads (for AJAX tests).
 *
 * @var array<int,array<string,mixed>>|null
 */
$GLOBALS['cpic_test_json'] = array();

/* ─── Hooks ──────────────────────────────────────────────────────── */

/**
 * Add a filter.
 *
 * @param string   $tag      Tag.
 * @param callable $callback Callback.
 * @param int      $priority Priority.
 * @param int      $args     Args.
 * @return bool
 */
function add_filter( $tag, $callback, $priority = 10, $args = 1 ) {
	CPIC_Test_State::$hooks[ $tag ][ $priority ][] = array( $callback, $args );
	return true;
}

/**
 * Add an action.
 *
 * @param string   $tag      Tag.
 * @param callable $callback Callback.
 * @param int      $priority Priority.
 * @param int      $args     Args.
 * @return bool
 */
function add_action( $tag, $callback, $priority = 10, $args = 1 ) {
	return add_filter( $tag, $callback, $priority, $args );
}

/**
 * Remove all callbacks for a tag.
 *
 * @param string $tag Tag.
 * @param mixed  $callback Callback.
 * @param int    $priority Priority.
 * @return bool
 */
function remove_all_filters( $tag, $priority = false ) {
	unset( CPIC_Test_State::$hooks[ $tag ] );
	return true;
}

/**
 * Remove one callback.
 *
 * @param string $tag Tag.
 * @param mixed  $callback Callback.
 * @param int    $priority Priority.
 * @return bool
 */
function remove_filter( $tag, $callback, $priority = 10 ) {
	if ( empty( CPIC_Test_State::$hooks[ $tag ][ $priority ] ) ) {
		return false;
	}
	foreach ( CPIC_Test_State::$hooks[ $tag ][ $priority ] as $index => $entry ) {
		if ( $entry[0] === $callback ) {
			unset( CPIC_Test_State::$hooks[ $tag ][ $priority ][ $index ] );
			return true;
		}
	}
	return false;
}

/**
 * Whether a hook has callbacks.
 *
 * @param string $tag Tag.
 * @return bool
 */
function has_action( $tag ) {
	return ! empty( CPIC_Test_State::$hooks[ $tag ] );
}

/**
 * Apply filters.
 *
 * @param string $tag   Tag.
 * @param mixed  $value Value.
 * @param mixed  ...$args Extra args.
 * @return mixed
 */
function apply_filters( $tag, $value, ...$args ) {
	if ( empty( CPIC_Test_State::$hooks[ $tag ] ) ) {
		return $value;
	}

	$callbacks = CPIC_Test_State::$hooks[ $tag ];
	ksort( $callbacks );

	foreach ( $callbacks as $priority => $entries ) {
		foreach ( $entries as $entry ) {
			$params = array_merge( array( $value ), $args );
			$value  = call_user_func_array( $entry[0], array_slice( $params, 0, max( 1, (int) $entry[1] ) ) );
		}
	}

	return $value;
}

/**
 * Fire an action.
 *
 * @param string $tag Tag.
 * @param mixed  ...$args Args.
 * @return void
 */
function do_action( $tag, ...$args ) {
	if ( empty( CPIC_Test_State::$hooks[ $tag ] ) ) {
		return;
	}

	$callbacks = CPIC_Test_State::$hooks[ $tag ];
	ksort( $callbacks );

	foreach ( $callbacks as $priority => $entries ) {
		foreach ( $entries as $entry ) {
			call_user_func_array( $entry[0], array_slice( $args, 0, max( 1, (int) $entry[1] ) ) );
		}
	}
}

/**
 * Count hook callbacks for a tag.
 *
 * @param string $tag Tag.
 * @return int
 */
function cpic_test_hook_count( $tag ) {
	$count = 0;
	if ( ! empty( CPIC_Test_State::$hooks[ $tag ] ) ) {
		foreach ( CPIC_Test_State::$hooks[ $tag ] as $entries ) {
			$count += count( $entries );
		}
	}
	return $count;
}

/* ─── Options ───────────────────────────────────────────────────── */

/**
 * Get an option.
 *
 * @param string $name    Name.
 * @param mixed  $default Default.
 * @return mixed
 */
function get_option( $name, $default = false ) {
	return array_key_exists( $name, CPIC_Test_State::$options ) ? CPIC_Test_State::$options[ $name ] : $default;
}

/**
 * Update an option.
 *
 * @param string $name  Name.
 * @param mixed  $value Value.
 * @param mixed  $autoload Autoload.
 * @return bool
 */
function update_option( $name, $value, $autoload = null ) {
	CPIC_Test_State::$options[ $name ] = $value;
	return true;
}

/**
 * Add an option (only when missing).
 *
 * @param string $name  Name.
 * @param mixed  $value Value.
 * @return bool
 */
function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $name, CPIC_Test_State::$options ) ) {
		return false;
	}
	CPIC_Test_State::$options[ $name ] = $value;
	return true;
}

/**
 * Delete an option.
 *
 * @param string $name Name.
 * @return bool
 */
function delete_option( $name ) {
	unset( CPIC_Test_State::$options[ $name ] );
	return true;
}

/* ─── Transients ────────────────────────────────────────────────── */

/**
 * Get a transient.
 *
 * @param string $name Name.
 * @return mixed
 */
function get_transient( $name ) {
	return array_key_exists( $name, CPIC_Test_State::$transients ) ? CPIC_Test_State::$transients[ $name ] : false;
}

/**
 * Set a transient.
 *
 * @param string $name  Name.
 * @param mixed  $value Value.
 * @param int    $ttl   TTL.
 * @return bool
 */
function set_transient( $name, $value, $ttl = 0 ) {
	CPIC_Test_State::$transients[ $name ] = $value;
	return true;
}

/**
 * Delete a transient.
 *
 * @param string $name Name.
 * @return bool
 */
function delete_transient( $name ) {
	unset( CPIC_Test_State::$transients[ $name ] );
	return true;
}

/* ─── Users / capabilities ──────────────────────────────────────── */

/**
 * Current user id.
 *
 * @return int
 */
function get_current_user_id() {
	return (int) CPIC_Test_State::$user_id;
}

/**
 * Capability check.
 *
 * @param string $cap Capability.
 * @return bool
 */
function current_user_can( $cap ) {
	$cap = (string) $cap;

	if ( isset( CPIC_Test_State::$capabilities[ $cap ] ) ) {
		return (bool) CPIC_Test_State::$capabilities[ $cap ];
	}

	// The test user is a capability-mapped administrator: every `cpic_*`
	// capability granted by Capabilities::CAPS resolves through its mapped
	// WordPress capability.
	if ( 0 === strpos( $cap, 'cpic_' ) && class_exists( '\\CPIC\\Security\\Capabilities' ) ) {
		$map = \CPIC\Security\Capabilities::CAPS;

		if ( isset( $map[ $cap ] ) ) {
			return current_user_can( $map[ $cap ] );
		}
	}

	return in_array( $cap, array( 'manage_options', 'read', 'edit_posts', 'publish_posts', 'edit_others_posts', 'upload_files', 'edit_pages' ), true );
}

/**
 * Roles stub storage.
 *
 * @var array<string,array<string,bool>>
 */
$GLOBALS['cpic_test_roles'] = array();

/**
 * Get a role.
 *
 * @param string $name Role.
 * @return object|null
 */
function get_role( $name ) {
	if ( ! isset( $GLOBALS['cpic_test_roles'][ $name ] ) ) {
		return null;
	}

	return new class( $name ) {
		/**
		 * Role name.
		 *
		 * @var string
		 */
		public $name;

		/**
		 * Constructor.
		 *
		 * @param string $name Name.
		 */
		public function __construct( $name ) {
			$this->name = $name;
		}

		/**
		 * Live capability map, as WP_Role exposes it.
		 *
		 * @return array<string,bool>
		 */
		public function __get( $property ) {
			if ( 'capabilities' === $property ) {
				return isset( $GLOBALS['cpic_test_roles'][ $this->name ] ) ? (array) $GLOBALS['cpic_test_roles'][ $this->name ] : array();
			}

			return null;
		}

		/**
		 * Add a capability.
		 *
		 * @param string $cap Capability.
		 * @return void
		 */
		public function add_cap( $cap ) {
			$GLOBALS['cpic_test_roles'][ $this->name ][ $cap ] = true;
		}

		/**
		 * Remove a capability.
		 *
		 * @param string $cap Capability.
		 * @return void
		 */
		public function remove_cap( $cap ) {
			unset( $GLOBALS['cpic_test_roles'][ $this->name ][ $cap ] );
		}
	};
}

/* ─── Posts / meta / terms ──────────────────────────────────────── */

/**
 * Get a post.
 *
 * @param int|WP_Post $post Post.
 * @return WP_Post|null
 */
function get_post( $post = null ) {
	if ( $post instanceof WP_Post ) {
		return $post;
	}
	$id = (int) $post;
	return isset( CPIC_Test_State::$posts[ $id ] ) ? CPIC_Test_State::$posts[ $id ] : null;
}

/**
 * Build a WP_Post-like object.
 *
 * @param array<string,mixed> $args Args.
 * @return WP_Post
 */
function cpic_test_make_post( array $args = array() ) {
	$post = new WP_Post();

	$defaults = array(
		'ID'                => 0,
		'post_author'       => 1,
		'post_date'         => current_time( 'mysql' ),
		'post_date_gmt'     => gmdate( 'Y-m-d H:i:s' ),
		'post_content'      => '',
		'post_title'        => '',
		'post_excerpt'      => '',
		'post_status'       => 'publish',
		'post_type'         => 'post',
		'post_modified'     => current_time( 'mysql' ),
		'post_modified_gmt' => gmdate( 'Y-m-d H:i:s' ),
	);

	foreach ( $defaults as $key => $default ) {
		$post->$key = array_key_exists( $key, $args ) ? $args[ $key ] : $default;
	}

	return $post;
}

/**
 * Get post meta.
 *
 * @param int    $post_id Post id.
 * @param string $key     Key.
 * @param bool   $single  Single.
 * @return mixed
 */
function get_post_meta( $post_id, $key = '', $single = false ) {
	$post_id = (int) $post_id;

	if ( '' === $key ) {
		$out = array();
		if ( isset( CPIC_Test_State::$post_meta[ $post_id ] ) ) {
			foreach ( CPIC_Test_State::$post_meta[ $post_id ] as $meta_key => $values ) {
				$out[ $meta_key ] = $values;
			}
		}
		return $out;
	}

	$values = isset( CPIC_Test_State::$post_meta[ $post_id ][ $key ] ) ? CPIC_Test_State::$post_meta[ $post_id ][ $key ] : array();

	if ( $single ) {
		return $values ? $values[0] : '';
	}

	return $values;
}

/**
 * Update post meta.
 *
 * @param int    $post_id Post id.
 * @param string $key     Key.
 * @param mixed  $value   Value.
 * @return bool
 */
function update_post_meta( $post_id, $key, $value ) {
	CPIC_Test_State::$post_meta[ (int) $post_id ][ $key ] = array( $value );
	return true;
}

/**
 * Add post meta.
 *
 * @param int    $post_id Post id.
 * @param string $key     Key.
 * @param mixed  $value   Value.
 * @return int
 */
function add_post_meta( $post_id, $key, $value, $unique = false ) {
	CPIC_Test_State::$post_meta[ (int) $post_id ][ $key ][] = $value;
	return count( CPIC_Test_State::$post_meta[ (int) $post_id ][ $key ] );
}

/**
 * Delete post meta.
 *
 * @param int    $post_id Post id.
 * @param string $key     Key.
 * @return bool
 */
function delete_post_meta( $post_id, $key, $value = '' ) {
	unset( CPIC_Test_State::$post_meta[ (int) $post_id ][ $key ] );
	return true;
}

/**
 * Delete meta by key (global).
 *
 * @param string $key Key.
 * @return void
 */
function delete_post_meta_by_key( $key ) {
	foreach ( CPIC_Test_State::$post_meta as $post_id => $meta ) {
		unset( CPIC_Test_State::$post_meta[ $post_id ][ $key ] );
	}
}

/**
 * Post title.
 *
 * @param int $post_id Post id.
 * @return string
 */
function get_the_title( $post_id = 0 ) {
	$post = get_post( $post_id );
	return $post ? (string) $post->post_title : '';
}

/**
 * Post permalink.
 *
 * @param int $post_id Post id.
 * @return string
 */
function get_permalink( $post_id = 0 ) {
	return 'https://example.test/?p=' . (int) $post_id;
}

/**
 * Edit link.
 *
 * @param int    $post_id Post id.
 * @param string $context Context.
 * @return string
 */
function get_edit_post_link( $post_id = 0, $context = 'display' ) {
	return 'https://example.test/wp-admin/post.php?post=' . (int) $post_id . '&action=edit';
}

/**
 * Post thumbnail id.
 *
 * @param int $post_id Post id.
 * @return int
 */
function get_post_thumbnail_id( $post_id = 0 ) {
	return isset( CPIC_Test_State::$thumbnails[ (int) $post_id ] ) ? (int) CPIC_Test_State::$thumbnails[ (int) $post_id ] : 0;
}

/**
 * Post thumbnail URL.
 *
 * @param int          $post_id Post id.
 * @param string|array $size    Size.
 * @return string
 */
function get_the_post_thumbnail_url( $post_id = 0, $size = 'post-thumbnail' ) {
	$thumb = get_post_thumbnail_id( $post_id );
	return $thumb && isset( CPIC_Test_State::$attachment_urls[ $thumb ] ) ? CPIC_Test_State::$attachment_urls[ $thumb ] : '';
}

/**
 * Post timestamp.
 *
 * @param int|WP_Post $post Post.
 * @return int
 */
function get_post_timestamp( $post = null ) {
	$post = get_post( $post );
	return $post ? (int) strtotime( $post->post_date ) : 0;
}

/**
 * Post categories.
 *
 * @param int $post_id Post id.
 * @return array<int,int>
 */
function wp_get_post_categories( $post_id = 0 ) {
	return isset( CPIC_Test_State::$post_terms[ (int) $post_id ] ) ? CPIC_Test_State::$post_terms[ (int) $post_id ] : array();
}

/**
 * Category objects.
 *
 * @param int $post_id Post id.
 * @return array<int,object>
 */
function get_the_category( $post_id = 0 ) {
	$ids  = wp_get_post_categories( $post_id );
	$out  = array();

	foreach ( $ids as $id ) {
		if ( isset( CPIC_Test_State::$terms[ $id ] ) ) {
			$out[] = (object) CPIC_Test_State::$terms[ $id ];
		}
	}

	return $out;
}

/**
 * Author display name.
 *
 * @param string $field Field.
 * @param int    $user_id User id.
 * @return string
 */
function get_the_author_meta( $field, $user_id = 0 ) {
	return 'Test Author';
}

/**
 * Post type.
 *
 * @param int $post_id Post id.
 * @return string|false
 */
function get_post_type( $post_id = 0 ) {
	$post = get_post( $post_id );
	return $post ? $post->post_type : false;
}

/**
 * Taxonomies for a post type.
 *
 * @param string $post_type Post type.
 * @return array<int,string>
 */
function get_object_taxonomies( $post_type ) {
	return array( 'category', 'post_tag' );
}

/**
 * Terms for a post.
 *
 * @param int    $post_id Post id.
 * @param string $taxonomy Taxonomy.
 * @return array<int,object>|false
 */
function get_the_terms( $post_id, $taxonomy ) {
	if ( 'category' === $taxonomy ) {
		return get_the_category( $post_id );
	}
	return array();
}

/**
 * Pluck a field from objects.
 *
 * @param array  $list  List.
 * @param string $field Field.
 * @return array
 */
function wp_list_pluck( $list, $field ) {
	$out = array();
	foreach ( (array) $list as $item ) {
		$item = (array) $item;
		$out[] = isset( $item[ $field ] ) ? $item[ $field ] : null;
	}
	return $out;
}

/* ─── Formatting ────────────────────────────────────────────────── */

/**
 * Current MySQL time (site local).
 *
 * @param string $type Type.
 * @param bool   $gmt  GMT.
 * @return string
 */
function current_time( $type = 'mysql', $gmt = 0 ) {
	if ( 'timestamp' === $type || 'U' === $type ) {
		return time();
	}
	return gmdate( 'Y-m-d H:i:s', time() + ( (int) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ) );
}

/**
 * Convert a MySQL date using a PHP format.
 *
 * @param string $format Format.
 * @param string $date   Date.
 * @return string
 */
function mysql2date( $format, $date ) {
	$timestamp = strtotime( (string) $date );
	return $timestamp ? date( $format, $timestamp ) : '';
}

/**
 * Get the date of a post.
 *
 * @param string $format Format.
 * @param int    $post_id Post id.
 * @return string
 */
function get_the_date( $format = '', $post_id = 0 ) {
	$post = get_post( $post_id );
	$timestamp = $post ? strtotime( $post->post_date ) : time();
	return gmdate( '' === $format ? 'F j, Y' : $format, $timestamp );
}

/**
 * @param string $string String.
 * @return string
 */
function esc_html( $string ) {
	return htmlspecialchars( (string) $string, ENT_QUOTES, 'UTF-8' );
}

/**
 * @param string $string String.
 * @return string
 */
function esc_attr( $string ) {
	return htmlspecialchars( (string) $string, ENT_QUOTES, 'UTF-8' );
}

/**
 * @param string $url URL.
 * @return string
 */
function esc_url( $url ) {
	return (string) $url;
}

/**
 * @param string $url URL.
 * @return string
 */
function esc_url_raw( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	$scheme = parse_url( $url, PHP_URL_SCHEME );
	if ( ! in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) ) {
		return '';
	}
	return $url;
}

/**
 * @param string $text Text.
 * @return string
 */
function sanitize_text_field( $text ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) );
}

/**
 * @param string $text Text.
 * @return string
 */
function sanitize_textarea_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

/**
 * @param string $key Key.
 * @return string
 */
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

/**
 * @param string $title Title.
 * @return string
 */
function sanitize_title( $title ) {
	$title = strtolower( trim( (string) $title ) );
	$title = preg_replace( '/[^a-z0-9\x{0980}-\x{09FF}]+/u', '-', $title );
	return trim( (string) $title, '-' );
}

/**
 * @param string $color Color.
 * @return string|false
 */
function sanitize_hex_color( $color ) {
	$color = trim( (string) $color );
	if ( '' === $color ) {
		return '';
	}
	if ( preg_match( '|^#([A-Fa-f0-9]{3}){1,2}$|', $color ) ) {
		return $color;
	}
	return '';
}

/**
 * @param string $email Email.
 * @return string
 */
function sanitize_email( $email ) {
	return filter_var( (string) $email, FILTER_SANITIZE_EMAIL );
}

/**
 * @param string $email Email.
 * @return bool
 */
function is_email( $email ) {
	return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
}

/**
 * @param string $data Data.
 * @return string
 */
function wp_unslash( $data ) {
	return $data;
}

/**
 * @param mixed $data Data.
 * @return string
 */
function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, $depth );
}

/**
 * @param mixed $data Data.
 * @return string
 */
function wp_kses_post( $data ) {
	return (string) $data;
}

/**
 * @param string $text Text.
 * @return string
 */
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}

/**
 * Parse args.
 *
 * @param array $args     Args.
 * @param array $defaults Defaults.
 * @return array
 */
function wp_parse_args( $args, $defaults = array() ) {
	if ( is_object( $args ) ) {
		$args = get_object_vars( $args );
	}
	if ( ! is_array( $args ) ) {
		$args = array();
	}
	return array_merge( $defaults, $args );
}

/**
 * @param int $min Min.
 * @param int $max Max.
 * @return int
 */
function wp_rand( $min = 0, $max = 0 ) {
	if ( $max > 0 ) {
		return random_int( (int) $min, (int) $max );
	}
	return random_int( 0, 999999 );
}

/* ─── Site ──────────────────────────────────────────────────────── */

/**
 * @param string $option Option.
 * @return string
 */
function get_bloginfo( $option = '' ) {
	$values = array(
		'name'        => 'Test Site',
		'description' => 'Test tagline',
		'version'     => '6.8',
		'url'         => 'https://example.test',
	);
	return isset( $values[ $option ] ) ? $values[ $option ] : '';
}

/**
 * @param string $path Path.
 * @return string
 */
function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

/**
 * @param string $path Path.
 * @return string
 */
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

/**
 * Add a query argument.
 *
 * @param string $url URL.
 * @param string $key Key.
 * @param string $value Value.
 * @return string
 */
function add_query_arg( $key, $value = '', $url = '' ) {
	if ( is_array( $key ) ) {
		$args = $key;
		$url  = $value ? $value : '';
	} else {
		$args = array( $key => $value );
	}
	$glue = false === strpos( (string) $url, '?' ) ? '?' : '&';
	return $url . $glue . http_build_query( $args );
}

/**
 * Upload directory.
 *
 * @param string $time Time.
 * @param bool   $create Create.
 * @return array<string,string>
 */
function wp_upload_dir( $time = null, $create = true ) {
	$base = CPIC_Test_State::$uploads_dir ? CPIC_Test_State::$uploads_dir : sys_get_temp_dir() . '/cpic-uploads';

	if ( $create && ! is_dir( $base ) ) {
		@mkdir( $base, 0777, true );
	}

	return array(
		'path'    => $base,
		'url'     => 'https://example.test/wp-content/uploads',
		'subdir'  => '',
		'basedir' => $base,
		'baseurl' => 'https://example.test/wp-content/uploads',
		'error'   => false,
	);
}

/**
 * Create a directory.
 *
 * @param string $path Path.
 * @return bool
 */
function wp_mkdir_p( $path ) {
	if ( is_dir( $path ) ) {
		return true;
	}
	return @mkdir( $path, 0777, true );
}

/**
 * Attachment URL to post id.
 *
 * @param string $url URL.
 * @return int
 */
function attachment_url_to_postid( $url ) {
	$id = array_search( $url, CPIC_Test_State::$attachment_urls, true );
	return false === $id ? 0 : (int) $id;
}

/**
 * Attachment file.
 *
 * @param int $attachment_id Id.
 * @return string|false
 */
function get_attached_file( $attachment_id ) {
	return isset( CPIC_Test_State::$attachment_files[ (int) $attachment_id ] ) ? CPIC_Test_State::$attachment_files[ (int) $attachment_id ] : false;
}

/**
 * Register an activation hook.
 *
 * @param string   $file     File.
 * @param callable $callback Callback.
 * @return void
 */
function register_activation_hook( $file, $callback ) {
	CPIC_Test_State::$hooks['__activate'][10][] = array( $callback, 1 );
}

/**
 * Register a deactivation hook.
 *
 * @param string   $file     File.
 * @param callable $callback Callback.
 * @return void
 */
function register_deactivation_hook( $file, $callback ) {
	CPIC_Test_State::$hooks['__deactivate'][10][] = array( $callback, 1 );
}

/**
 * Load a text domain.
 *
 * @param string $domain Domain.
 * @param string $path   Path.
 * @param string $locale Locale.
 * @return bool
 */
function load_plugin_textdomain( $domain, $deprecated = false, $path = '' ) {
	return true;
}

/**
 * @param string $text   Text.
 * @param string $domain Domain.
 * @return string
 */
function __( $text, $domain = 'default' ) {
	return $text;
}

/**
 * @param string $text   Text.
 * @param string $domain Domain.
 * @return string
 */
function esc_html__( $text, $domain = 'default' ) {
	return $text;
}

/**
 * @param string $text   Text.
 * @param string $domain Domain.
 * @return string
 */
function esc_attr__( $text, $domain = 'default' ) {
	return $text;
}

/**
 * @param string $text   Text.
 * @param string $domain Domain.
 * @return void
 */
function _e( $text, $domain = 'default' ) {
	echo $text; // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * @param string $text   Text.
 * @param string $domain Domain.
 * @return void
 */
function esc_html_e( $text, $domain = 'default' ) {
	echo esc_html( $text ); // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * @param string $text   Text.
 * @param string $domain Domain.
 * @param int    $number Number.
 * @return string
 */
function _n( $single, $plural, $number, $domain = 'default' ) {
	return 1 === (int) $number ? $single : $plural;
}

/**
 * @param string $text   Text.
 * @param string $domain Domain.
 * @return string
 */
function _x( $text, $context, $domain = 'default' ) {
	return $text;
}

/* ─── Nonces ────────────────────────────────────────────────────── */

/**
 * Create a nonce.
 *
 * @param string $action Action.
 * @return string
 */
function wp_create_nonce( $action = -1 ) {
	return substr( md5( $action . AUTH_KEY ), 0, 10 );
}

/**
 * Verify a nonce.
 *
 * @param string $nonce  Nonce.
 * @param string $action Action.
 * @return int|false
 */
function wp_verify_nonce( $nonce, $action = -1 ) {
	return hash_equals( wp_create_nonce( $action ), (string) $nonce ) ? 1 : false;
}

/**
 * Check an AJAX referer.
 *
 * @param string $action Action.
 * @param string $field  Field.
 * @param bool   $die    Die.
 * @return int|false
 */
function check_ajax_referer( $action = -1, $field = false, $die = true ) {
	$nonce = isset( $_REQUEST[ $field ] ) ? $_REQUEST[ $field ] : '';
	return wp_verify_nonce( $nonce, $action );
}

/* ─── REST ──────────────────────────────────────────────────────── */

/**
 * Register a REST route.
 *
 * @param string $namespace Namespace.
 * @param string $route    Route.
 * @param array  $args     Args.
 * @return bool
 */
function register_rest_route( $namespace, $route, $args = array() ) {
	// WordPress normalises to "/namespace/route".
	$full = '/' . trim( (string) $namespace, '/' ) . '/' . trim( (string) $route, '/' );

	CPIC_Test_State::$rest_routes[ $full ] = $args;

	return true;
}

/**
 * REST URL.
 *
 * @param string $path Path.
 * @return string
 */
function rest_url( $path = '' ) {
	return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
}

/* ─── Cron ──────────────────────────────────────────────────────── */

/**
 * Next scheduled event.
 *
 * @param string $hook Hook.
 * @return int|false
 */
function wp_next_scheduled( $hook, $args = array() ) {
	return isset( CPIC_Test_State::$scheduled[ $hook ] ) ? CPIC_Test_State::$scheduled[ $hook ]['timestamp'] : false;
}

/**
 * Schedule a recurring event.
 *
 * @param int    $timestamp Timestamp.
 * @param string $recurrence Recurrence.
 * @param string $hook Hook.
 * @return bool
 */
function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array(), $wp_error = false ) {
	CPIC_Test_State::$scheduled[ $hook ] = array(
		'timestamp'  => (int) $timestamp,
		'recurrence' => $recurrence,
		'args'       => $args,
	);
	return true;
}

/**
 * Schedule a single event.
 *
 * @param int    $timestamp Timestamp.
 * @param string $hook Hook.
 * @param array  $args Args.
 * @return bool
 */
function wp_schedule_single_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
	CPIC_Test_State::$scheduled[ $hook . ':' . md5( (string) wp_json_encode( $args ) ) ] = array(
		'timestamp' => (int) $timestamp,
		'hook'      => $hook,
		'args'      => $args,
	);
	return true;
}

/**
 * Unschedule an event.
 *
 * @param int    $timestamp Timestamp.
 * @param string $hook Hook.
 * @return bool
 */
function wp_unschedule_event( $timestamp, $hook, $args = array() ) {
	unset( CPIC_Test_State::$scheduled[ $hook ] );
	return true;
}

/**
 * Clear all events for a hook.
 *
 * @param string $hook Hook.
 * @return int
 */
function wp_clear_scheduled_hook( $hook, $args = array() ) {
	$count = 0;
	foreach ( array_keys( CPIC_Test_State::$scheduled ) as $key ) {
		if ( $key === $hook || 0 === strpos( $key, $hook . ':' ) ) {
			unset( CPIC_Test_State::$scheduled[ $key ] );
			$count++;
		}
	}
	return $count;
}

/**
 * Whether WP-Cron is disabled.
 *
 * @return bool
 */
function wp_doing_cron() {
	return defined( 'DOING_CRON' ) && DOING_CRON;
}

/* ─── Misc ──────────────────────────────────────────────────────── */

/**
 * @param bool $bool Bool.
 * @return bool
 */
function is_admin() {
	return true;
}

/**
 * Flush rewrites.
 *
 * @param bool $hard Hard.
 * @return void
 */
function flush_rewrite_rules( $hard = true ) {
	// Tests can simulate a host where this step explodes.
	if ( ! empty( $GLOBALS['cpic_test_fail_rewrite_flush'] ) ) {
		throw new \RuntimeException( 'simulated flush_rewrite_rules() failure' );
	}
}

/**
 * Error factory.
 *
 * @param string $code    Code.
 * @param string $message Message.
 * @param array  $data    Data.
 * @return WP_Error
 */
function cpic_test_error( $code, $message, $data = array() ) {
	return new WP_Error( $code, $message, $data );
}

/**
 * Whether a value is an error.
 *
 * @param mixed $thing Thing.
 * @return bool
 */
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * JSON success helper (captured, never exits in tests).
 *
 * @param mixed $data Data.
 * @return void
 */
function wp_send_json_success( $data = null, $status = 200 ) {
	$GLOBALS['cpic_test_json'][] = array(
		'success' => true,
		'data'    => $data,
		'status'  => $status,
	);
}

/**
 * JSON error helper.
 *
 * @param mixed $data Data.
 * @return void
 */
function wp_send_json_error( $data = null, $status = 200 ) {
	$GLOBALS['cpic_test_json'][] = array(
		'success' => false,
		'data'    => $data,
		'status'  => $status,
	);
}

/**
 * Die handler.
 *
 * @param string $message Message.
 * @return void
 */
function wp_die( $message = '' ) {
	throw new RuntimeException( 'wp_die: ' . ( is_string( $message ) ? $message : 'error' ) );
}

/**
 * Remote request stub.
 *
 * @param string $url  URL.
 * @param array  $args Args.
 * @return array|WP_Error
 */
function wp_remote_request( $url, $args = array() ) {
	return apply_filters( 'cpic_test_http_response', array(), $url, $args );
}

/**
 * POST stub.
 *
 * @param string $url  URL.
 * @param array  $args Args.
 * @return array|WP_Error
 */
function wp_remote_post( $url, $args = array() ) {
	return wp_remote_request( $url, array_merge( (array) $args, array( 'method' => 'POST' ) ) );
}

/**
 * GET stub.
 *
 * @param string $url  URL.
 * @param array  $args Args.
 * @return array|WP_Error
 */
function wp_remote_get( $url, $args = array() ) {
	return wp_remote_request( $url, array_merge( (array) $args, array( 'method' => 'GET' ) ) );
}

/**
 * Response code.
 *
 * @param array $response Response.
 * @return int
 */
function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}

/**
 * Response body.
 *
 * @param array $response Response.
 * @return string
 */
function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? (string) $response['body'] : '';
}

/* ─── Media library ────────────────────────────────────────────── */

function wp_insert_attachment( $args, $file = false, $parent = 0 ) {
	$id = 1000 + count( CPIC_Test_State::$attachment_urls );
	CPIC_Test_State::$attachment_files[ $id ] = (string) $file;
	return $id;
}

function wp_generate_attachment_metadata( $attachment_id, $file ) {
	return array( 'file' => basename( (string) $file ) );
}

function wp_update_attachment_metadata( $attachment_id, $data ) {
	return true;
}

function strip_shortcodes( $content ) {
	return preg_replace( '/\[[^\]]+\]/', '', (string) $content );
}

class WP_Query {
	public $posts = array();
	public $found_posts = 0;
	public $max_num_pages = 0;
	private $pointer = 0;

	public function __construct( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'paged'          => 1,
			)
		);

		$filtered = array();

		foreach ( CPIC_Test_State::$posts as $post ) {
			if ( isset( $args['post_type'] ) && $args['post_type'] !== $post->post_type ) {
				continue;
			}
			if ( isset( $args['post_status'] ) && $args['post_status'] !== $post->post_status ) {
				continue;
			}
			$filtered[] = $post;
		}

		usort(
			$filtered,
			static function ( $a, $b ) {
				return strcmp( $b->post_date, $a->post_date );
			}
		);

		$this->found_posts   = count( $filtered );
		$this->max_num_pages = (int) ceil( $this->found_posts / max( 1, (int) $args['posts_per_page'] ) );
		$this->posts         = array_slice( $filtered, ( max( 1, (int) $args['paged'] ) - 1 ) * (int) $args['posts_per_page'], (int) $args['posts_per_page'] );
	}

	public function have_posts() {
		return $this->pointer < count( $this->posts );
	}

	public function the_post() {
		$this->pointer++;
	}

	public function reset_postdata() {
		$this->pointer = 0;
	}
}

function get_the_ID() {
	$posts = CPIC_Test_State::$posts;
	if ( ! $posts ) {
		return 0;
	}
	$keys = array_keys( $posts );
	return (int) $keys[0];
}

function wp_reset_postdata() {
}

function get_the_author() {
	return 'Test Author';
}

/**
 * dbDelta implementation for the SQLite harness.
 *
 * @param string|array $queries Queries.
 * @param bool         $execute Execute.
 * @return array
 */
function dbDelta( $queries = '', $execute = true ) {
	global $wpdb;

	$statements = is_array( $queries ) ? $queries : preg_split( '/;\s*\n/', (string) $queries );
	$results    = array();

	foreach ( (array) $statements as $statement ) {
		$statement = trim( (string) $statement );
		if ( '' === $statement ) {
			continue;
		}
		$results[] = $wpdb->query( $statement );
	}

	if ( method_exists( $wpdb, 'create_pending_indexes' ) ) {
		$wpdb->create_pending_indexes();
	}

	return $results;
}

/**
 * WP_Error implementation.
 */
class WP_Error {

	/** @var array<string,array<int,string>> */
	public $errors = array();

	/** @var array<string,mixed> */
	public $error_data = array();

	public function __construct( $code = '', $message = '', $data = '' ) {
		if ( '' !== $code ) {
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}
	}

	public function get_error_code() {
		$codes = array_keys( $this->errors );
		return $codes ? $codes[0] : '';
	}

	public function get_error_message( $code = '' ) {
		if ( '' === $code ) {
			$code = $this->get_error_code();
		}
		return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
	}

	public function get_error_data( $code = '' ) {
		if ( '' === $code ) {
			$code = $this->get_error_code();
		}
		return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
	}
}

/**
 * Minimal WP_Post value object.
 */
class WP_Post {

	/** @var int */
	public $ID = 0;

	/** @var int */
	public $post_author = 0;

	/** @var string */
	public $post_date = '';

	/** @var string */
	public $post_date_gmt = '';

	/** @var string */
	public $post_content = '';

	/** @var string */
	public $post_title = '';

	/** @var string */
	public $post_excerpt = '';

	/** @var string */
	public $post_status = 'publish';

	/** @var string */
	public $post_type = 'post';

	/** @var string */
	public $post_modified = '';

	/** @var string */
	public $post_modified_gmt = '';
}

/* ─── Admin / editor / helpers ─────────────────────────────────── */

/**
 * Prepare the fake WordPress install tree (ABSPATH).
 *
 * @return void
 */
function cpic_test_prepare_wp_tree() {
	$base = rtrim( ABSPATH, '/' );

	$dirs = array(
		$base,
		$base . '/wp-admin',
		$base . '/wp-admin/includes',
		$base . '/wp-content',
		$base . '/wp-content/uploads',
		$base . '/wp-content/plugins',
		$base . '/wp-includes',
	);

	foreach ( $dirs as $dir ) {
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
	}

	$upgrade = $base . '/wp-admin/includes/upgrade.php';

	if ( ! file_exists( $upgrade ) ) {
		file_put_contents( $upgrade, "<?php\n// dbDelta() is provided by the test suite's \$wpdb shim.\n" );
	}
}

function trailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' ) . '/';
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

function absint( $value ) {
	return abs( (int) $value );
}

function size_format( $bytes, $decimals = 0 ) {
	$bytes = (float) $bytes;
	$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
	$i     = 0;

	while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
		$bytes /= 1024;
		$i++;
	}

	return sprintf( '%s %s', round( $bytes, (int) $decimals ), $units[ $i ] );
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals );
}

function human_time_diff( $from, $to = 0 ) {
	$to    = $to ? (int) $to : time();
	$diff  = abs( $to - (int) $from );
	$units = array( 'year' => YEAR_IN_SECONDS, 'month' => MONTH_IN_SECONDS, 'week' => WEEK_IN_SECONDS, 'day' => DAY_IN_SECONDS, 'hour' => HOUR_IN_SECONDS, 'minute' => MINUTE_IN_SECONDS );

	foreach ( $units as $label => $seconds ) {
		if ( $diff >= $seconds ) {
			return sprintf( '%d %s(s)', (int) floor( $diff / $seconds ), $label );
		}
	}

	return max( 1, $diff ) . ' second(s)';
}

function is_user_logged_in() {
	return CPIC_Test_State::$user_id > 0;
}

function get_current_screen() {
	return null;
}

function wp_check_filetype( $filename, $mimes = null ) {
	$ext = strtolower( (string) pathinfo( (string) $filename, PATHINFO_EXTENSION ) );

	$map = array(
		'png'  => 'image/png',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'webp' => 'image/webp',
		'gif'  => 'image/gif',
		'svg'  => 'image/svg+xml',
		'json' => 'application/json',
	);

	return array(
		'ext'  => isset( $map[ $ext ] ) ? $ext : false,
		'type' => isset( $map[ $ext ] ) ? $map[ $ext ] : false,
	);
}

function wp_doing_ajax() {
	return false;
}

function wp_get_attachment_image_url( $attachment_id, $size = 'thumbnail' ) {
	$id = (int) $attachment_id;
	return isset( CPIC_Test_State::$attachment_urls[ $id ] ) ? (string) CPIC_Test_State::$attachment_urls[ $id ] : false;
}

function wp_enqueue_media( $args = array() ) {
	CPIC_Test_State::$enqueued['media'] = true;
}

function wp_enqueue_script( $handle, $src = '', $deps = array(), $version = false, $footer = false ) {
	CPIC_Test_State::$enqueued['scripts'][ (string) $handle ] = (string) $src;
	return true;
}

function wp_enqueue_style( $handle, $src = '', $deps = array(), $version = false, $media = 'all' ) {
	CPIC_Test_State::$enqueued['styles'][ (string) $handle ] = (string) $src;
	return true;
}

function wp_localize_script( $handle, $object_name, $l10n ) {
	CPIC_Test_State::$localized[ (string) $object_name ] = $l10n;
	return true;
}

function wp_nonce_url( $actionurl, $action = -1, $name = '_wpnonce' ) {
	return $actionurl . ( false === strpos( (string) $actionurl, '?' ) ? '?' : '&' ) . $name . '=' . wp_create_nonce( $action );
}

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true, $field_id = '' ) {
	$field = sprintf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $name ), esc_attr( wp_create_nonce( $action ) ) );

	if ( $display ) {
		echo $field; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	return $field;
}

function wp_safe_redirect( $location, $status = 302 ) {
	CPIC_Test_State::$redirects[] = (string) $location;
	return true;
}

function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
	CPIC_Test_State::$menu[ (string) $menu_slug ] = array( 'title' => $page_title, 'cap' => $capability );
	return (string) $menu_slug;
}

function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '' ) {
	CPIC_Test_State::$menu[ (string) $menu_slug ] = array( 'title' => $page_title, 'cap' => $capability, 'parent' => $parent_slug );
	return (string) $menu_slug;
}

function add_meta_box( $id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default' ) {
	CPIC_Test_State::$meta_boxes[ (string) $id ] = array( 'screen' => $screen, 'context' => $context );
}

function media_handle_sideload( $file_array, $post_id = 0, $desc = null, $post_data = array() ) {
	$file = isset( $file_array['tmp_name'] ) ? (string) $file_array['tmp_name'] : '';

	if ( '' === $file || ! file_exists( $file ) ) {
		return new WP_Error( 'cpic_no_tmp', 'The uploaded file could not be read.' );
	}

	$dest = trailingslashit( CPIC_Test_State::$uploads_dir ? CPIC_Test_State::$uploads_dir : sys_get_temp_dir() ) . basename( $file );
	copy( $file, $dest );

	$id = 1000 + count( CPIC_Test_State::$attachment_urls );
	CPIC_Test_State::$attachment_files[ $id ] = $dest;
	CPIC_Test_State::$attachment_urls[ $id ]  = 'https://example.test/wp-content/uploads/' . basename( $dest );

	return $id;
}

function get_terms( $args = array(), $deprecated = '' ) {
	$out = array();

	foreach ( CPIC_Test_State::$terms as $term ) {
		$out[] = (object) $term;
	}

	return $out;
}

function get_term( $term, $taxonomy = '' ) {
	$id = is_object( $term ) ? (int) $term->term_id : (int) $term;
	return isset( CPIC_Test_State::$terms[ $id ] ) ? (object) CPIC_Test_State::$terms[ $id ] : null;
}

function term_exists( $term, $taxonomy = '', $parent = null ) {
	foreach ( CPIC_Test_State::$terms as $id => $row ) {
		if ( (string) $row['name'] === (string) $term || (string) $row['slug'] === (string) $term ) {
			return array( 'term_id' => $id, 'term_taxonomy_id' => $id );
		}
	}
	return null;
}

function wp_insert_term( $term, $taxonomy, $args = array() ) {
	$id = max( array_keys( CPIC_Test_State::$terms ) ?: array( 0 ) ) + 1;
	CPIC_Test_State::$terms[ $id ] = array(
		'term_id'  => $id,
		'name'     => (string) $term,
		'slug'     => sanitize_title( (string) $term ),
		'taxonomy' => (string) $taxonomy,
	);
	return array( 'term_id' => $id, 'term_taxonomy_id' => $id );
}

function wp_set_post_terms( $post_id, $terms, $taxonomy = 'post_tag', $append = false ) {
	$ids = array();

	foreach ( (array) $terms as $term ) {
		$existing = term_exists( $term, $taxonomy );
		$ids[]    = $existing ? (int) $existing['term_id'] : (int) wp_insert_term( $term, $taxonomy )['term_id'];
	}

	CPIC_Test_State::$post_terms[ (int) $post_id ] = $ids;

	return $ids;
}


/**
 * REST response stub.
 */
class WP_REST_Request {

	/**
	 * Parameters.
	 *
	 * @var array<string,mixed>
	 */
	private $params = array();

	/**
	 * File parameters.
	 *
	 * @var array<string,mixed>
	 */
	private $files = array();

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $params Parameters.
	 * @param array<string,mixed> $files  Files.
	 */
	public function __construct( array $params = array(), array $files = array() ) {
		$this->params = $params;
		$this->files  = $files;
	}

	/**
	 * Get a parameter.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public function get_param( $key ) {
		return array_key_exists( $key, $this->params ) ? $this->params[ $key ] : null;
	}

	/**
	 * All parameters.
	 *
	 * @return array<string,mixed>
	 */
	public function get_params() {
		return $this->params;
	}

	/**
	 * File parameters.
	 *
	 * @return array<string,mixed>
	 */
	public function get_file_params() {
		return $this->files;
	}

	/**
	 * Route (stub).
	 *
	 * @return string
	 */
	public function get_route() {
		return '/cpic/v1/test';
	}
}

/**
 * REST response stub.
 */
class WP_REST_Response {

	/**
	 * Data.
	 *
	 * @var mixed
	 */
	public $data;

	/**
	 * Status.
	 *
	 * @var int
	 */
	public $status;

	/**
	 * Headers.
	 *
	 * @var array<string,string>
	 */
	public $headers = array();

	/**
	 * Constructor.
	 *
	 * @param mixed $data   Data.
	 * @param int   $status Status.
	 */
	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = (int) $status;
	}

	/**
	 * Get data.
	 *
	 * @return mixed
	 */
	public function get_data() {
		return $this->data;
	}

	/**
	 * Get status.
	 *
	 * @return int
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * Set a header.
	 *
	 * @param string $key   Key.
	 * @param string $value Value.
	 * @return void
	 */
	public function header( $key, $value ) {
		$this->headers[ (string) $key ] = (string) $value;
	}
}

/**
 * Echo an escaped attribute.
 *
 * @param string $text   Text.
 * @param string $domain Domain.
 * @return void
 */
function esc_attr_e( $text, $domain = 'default' ) {
	echo esc_attr( $text ); // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * Escape for a JS string literal.
 *
 * @param string $text Text.
 * @return string
 */
function esc_js( $text ) {
	return str_replace( array( "\\", "'", "\r", "\n", '</' ), array( '\\\\', "\\'", '', '\\n', '<\\/' ), (string) $text );
}

/**
 * Escape textarea content.
 *
 * @param string $text Text.
 * @return string
 */
function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Render a `checked` attribute.
 *
 * @param bool $checked  Whether checked.
 * @param mixed $current  Current value.
 * @param bool $display  Whether to echo.
 * @return string
 */
function checked( $checked, $current = true, $display = true ) {
	$result = ( (string) $checked === (string) $current || ( $checked && $current ) ) ? " checked='checked'" : '';

	if ( $display ) {
		echo $result; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	return $result;
}

/**
 * Render a `selected` attribute.
 *
 * @param mixed $selected Selected.
 * @param mixed $current  Current.
 * @param bool  $display  Whether to echo.
 * @return string
 */
function selected( $selected, $current = true, $display = true ) {
	$result = ( (string) $selected === (string) $current ) ? " selected='selected'" : '';

	if ( $display ) {
		echo $result; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	return $result;
}

/**
 * Render a `disabled` attribute.
 *
 * @param bool $disabled Whether disabled.
 * @param bool $current  Current.
 * @param bool $display  Whether to echo.
 * @return string
 */
function disabled( $disabled, $current = true, $display = true ) {
	$result = $disabled === $current || ( $disabled && $current ) ? " disabled='disabled'" : '';

	if ( $display ) {
		echo $result; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	return $result;
}

/**
 * Trim text to a word count.
 *
 * @param string $text  Text.
 * @param int    $count Words.
 * @param string $more  Suffix.
 * @return string
 */
function wp_trim_words( $text = '', $count = 55, $more = null ) {
	$words = preg_split( '/\s+/u', trim( wp_strip_all_tags( (string) $text ) ), (int) $count + 1 );

	if ( count( $words ) <= (int) $count ) {
		return implode( ' ', $words );
	}

	array_pop( $words );

	return implode( ' ', $words ) . ( null === $more ? '&hellip;' : $more );
}

/**
 * Pagination links for list tables.
 *
 * @param array $args Args.
 * @return string
 */
function paginate_links( $args = array() ) {
	$total   = isset( $args['total'] ) ? (int) $args['total'] : 1;
	$current = isset( $args['current'] ) ? (int) $args['current'] : 1;
	$out     = array();

	for ( $page = 1; $page <= $total; $page++ ) {
		$url   = str_replace( '%#%', (string) $page, isset( $args['base'] ) ? (string) $args['base'] : '' );
		$out[] = $page === $current
			? '<span class="current">' . $page . '</span>'
			: '<a href="' . esc_url( $url ) . '">' . $page . '</a>';
	}

	return implode( ' ', $out );
}

/**
 * Fetch posts (thin wrapper over the WP_Query stub).
 *
 * @param array $args Query args.
 * @return array<int,WP_Post>
 */
function get_posts( $args = array() ) {
	$query = new WP_Query( $args );

	return $query->posts;
}

/**
 * Global roles object (subset of WP_Roles used by the plugin).
 *
 * @return object
 */
function wp_roles() {
	return new class() {
		/**
		 * Role map: slug => { name, capabilities }.
		 *
		 * @var array<string,array<string,mixed>>
		 */
		public $roles = array();

		/**
		 * Constructor: mirror the stub role store.
		 */
		public function __construct() {
			foreach ( (array) $GLOBALS['cpic_test_roles'] as $slug => $caps ) {
				$this->roles[ $slug ] = array(
					'name'         => ucfirst( (string) $slug ),
					'capabilities' => (array) $caps,
				);
			}
		}

		/**
		 * Get a role.
		 *
		 * @param string $name Role.
		 * @return object|null
		 */
		public function get_role( $name ) {
			return isset( $this->roles[ $name ] ) ? get_role( (string) $name ) : null;
		}
	};
}

/* ─── Plugin path helpers (mirror wp-includes/plugin.php) ───────── */

/**
 * Filesystem path of the directory that contains a plugin file.
 *
 * @param string $file Plugin file.
 * @return string Trailing-slashed path.
 */
function plugin_dir_path( $file ) {
	return trailingslashit( dirname( (string) $file ) );
}

/**
 * URL of the directory that contains a plugin file.
 *
 * @param string $file Plugin file.
 * @return string Trailing-slashed URL.
 */
function plugin_dir_url( $file ) {
	return trailingslashit( 'http://example.test/wp-content/plugins/' . basename( dirname( (string) $file ) ) );
}

/**
 * Plugin basename, e.g. `dir/plugin.php`.
 *
 * @param string $file Plugin file.
 * @return string
 */
function plugin_basename( $file ) {
	$file = str_replace( '\\', '/', (string) $file );
	return basename( dirname( $file ) ) . '/' . basename( $file );
}
