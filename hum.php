<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Plugin Name: Hum
 * Plugin URI: https://github.com/willnorris/wordpress-hum
 * Description: Personal URL shortener for WordPress
 * Author: Will Norris
 * Author URI: https://willnorris.com/
 * Version: 1.3.5
 * License: MIT
 * License URI: http://opensource.org/licenses/MIT
 * Text Domain: hum
 */

class Hum {

	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'init', array( $this, 'rewrite_rules' ), 15 );
		add_action( 'init', array( $this, 'register_editor_script' ) );

		register_activation_hook( __FILE__, array( $this, 'flush_rewrite_rules' ) );
		register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
	}

	/**
	 * Initialize the plugin, registering WordPress hooks.
	 */
	public function init() {
		load_plugin_textdomain( 'hum', null, basename( __DIR__ ) );

		// if you have hum installed, then you probably actually care about short
		// links, so we'll add it to the admin menu bar.
		add_action( 'admin_bar_menu', 'wp_admin_bar_shortlink_menu', 90 );

		add_action( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'parse_request', array( $this, 'parse_request' ) );
		add_filter( 'hum_redirect', array( $this, 'redirect_request' ), 10, 3 );
		add_filter( 'hum_redirect_i', array( $this, 'redirect_request_i' ), 10, 2 );
		add_filter( 'hum_process_redirect', array( $this, 'process_redirect' ), 10, 2 );
		add_filter( 'pre_option_hum_shortlink_base', array( $this, 'config_shortlink_base' ) );
		add_filter( 'pre_get_shortlink', array( $this, 'get_shortlink' ), 10, 4 );
		add_filter( 'template_redirect', array( $this, 'legacy_redirect' ) );
		add_filter( 'hum_legacy_id', array( $this, 'legacy_ftl_id' ), 10, 2 );
		add_action( 'atom_entry', array( $this, 'shortlink_atom_entry' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_script' ) );

		// Admin Settings
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_filter( 'manage_edit-post_columns', array( $this, 'add_post_column' ), 10, 1 );
		add_filter( 'manage_edit-page_columns', array( $this, 'add_post_column' ), 10, 1 );
		add_action( 'manage_posts_custom_column', array( $this, 'add_posts_custom_column' ), 10, 2 );
		add_action( 'manage_pages_custom_column', array( $this, 'add_posts_custom_column' ), 10, 2 );
	}

	/**
	 * Register editor script.
	 */
	public function register_editor_script() {
		// Load dependencies and version info.
		$asset_info = include plugin_dir_path( __FILE__ ) . 'build/index.asset.php';

		wp_register_script(
			'hum-editor-script',
			plugins_url( 'build/index.js', __FILE__ ),
			$asset_info['dependencies'],
			$asset_info['version']
		);
	}

	/**
	 * Enqueue editor script.
	 */
	public function enqueue_block_editor_script() {
		wp_enqueue_script( 'hum-editor-script' );

		wp_localize_script(
			'hum-editor-script',
			'humEditorObject',
			array(
				'shortlink'             => wp_get_shortlink(),
				'inputLabel'            => __( 'Shortlink', 'hum' ),
				'copyButtonLabel'       => __( 'Copy link', 'hum' ),
				'copyButtonCopiedLabel' => __( 'Copied!', 'hum' ),
			)
		);
	}

	/**
	 * Accept hum query variables.
	 */
	public function query_vars( $vars ) {
		$vars[] = 'hum';
		return $vars;
	}

	/**
	 * Parse request for shortlink. This is the main entry point for handling
	 * short URLs.
	 *
	 * @uses apply_filters() Calls 'hum_redirect' filter
	 * @uses apply_filters() Calls 'hum_process_redirect' filter
	 *
	 * @param WP $wp the WordPress environment for the request
	 */
	public function parse_request( $wp ) {
		if ( array_key_exists( 'hum', $wp->query_vars ) ) {
			$hum_path = $wp->query_vars['hum'];
			if ( strpos( $hum_path, '/' ) !== false ) {
				list($type, $id) = explode( '/', $hum_path, 2 );
			} else {
				$type = $hum_path;
				$id   = null;
			}

			$url = apply_filters( 'hum_redirect', null, $type, $id );

			// hum hasn't handled the request yet, so try again but strip common
			// punctuation that might appear after a URL in written text: . , )
			if ( ! $url ) {
				$clean_id = preg_replace( '/[\.,\)]+$/', '', $id );
				if ( $id !== $clean_id ) {
					$url = apply_filters( 'hum_redirect', null, $type, $clean_id );
				}
			}

			if ( $url ) {
				do_action( 'hum_process_redirect', $url, $id );
			}

			// hum didn't handle request, so issue 404.
			// manually setting query vars like this feels very fragile, but
			// $wp_query->set_404() doesn't do what we need here.
			$wp->query_vars['error'] = '404';
		}
	}

	/**
	 * Process the redirect.
	 *
	 * @param string $url the permalink of the post
	 * @param string $id the requested post ID
	 */
	public function process_redirect( $url, $id ) {
		wp_redirect( $url, 301 );
		exit;
	}

	/**
	 * Get the short URL types that are handled locally by WordPress.
	 *
	 * @uses apply_filters() Calls 'hum_local_types' with array of local types
	 *
	 * @return array local types
	 */
	public function local_types() {
		$local_types = array( 'b', 't', 'a', 'p' );
		return apply_filters( 'hum_local_types', $local_types );
	}

	/**
	 * Get the short URL types that shoud be redirected (types can be the same as local types).
	 *
	 * @uses apply_filters() Calls 'hum_redirect_types' with array of redirect types
	 *
	 * @return array redirect types
	 */
	public function redirect_types() {
		$redirect_types = array( 'i' );
		return apply_filters( 'hum_redirect_types', $redirect_types );
	}

	/**
	 * Attempt to handle redirect for the current shortlink.
	 *
	 * This redirects shortlinks that are for content hosted directly within
	 * WordPress. The 'id' portion of these URLs is expected to be the
	 * sexagesimal post ID.
	 *
	 * This also allows for simple redirect rules for shortlink prefixes. Users
	 * can provide a filter to perform simple URL redirect for a given type
	 * prefix. For example, to redirect all /w/ shortlinks to your personal
	 * PBworks wiki, you could use:
	 *
	 *     add_filter('hum_redirect_base_w', fn() => "http://willnorris.pbworks.com/");
	 *
	 * @uses apply_filters() Calls 'hum_redirect_{$type}' action
	 * @uses apply_filters() Calls 'hum_redirect_base_{$type}' filter on redirect base URL
	 *
	 * @param string $url the short URL
	 * @param string $type the content-type prefix
	 * @param string $id the requested post ID
	 */
	public function redirect_request( $url, $type, $id ) {
		// locally hosted content
		$local_types = $this->local_types();
		if ( in_array( $type, $local_types, true ) ) {
			$p = sxg_to_num( $id );
			if ( $p ) {
				$url = get_permalink( $p );
			}
		}

		// simple redirects for entire base type
		if ( ! $url ) {
			$url = apply_filters( "hum_redirect_base_{$type}", false );
			if ( $url ) {
				$url = trailingslashit( $url ) . $id;
			}
		}

		$url = apply_filters( "hum_redirect_{$type}", $url, $id );
		return $url;
	}

	/**
	 * Handles /i/ URLs that have ISBN or ASIN subpaths by redirecting to Amazon.
	 *
	 * @uses apply_filters() Calls 'hum_redirect_i_{$subtype}' action
	 * @uses apply_filters() Calls 'amazon_domain' filter
	 * @uses apply_filters() Calls 'amazon_affiliate_id' filter
	 *
	 * @param string $url the short URL
	 * @param string $path subpath of URL (after /i/)
	 */
	public function redirect_request_i( $url, $path ) {
		list( $subtype, $id ) = explode( '/', $path, 2 );

		if ( $subtype ) {
			switch ( $subtype ) {
				case 'a':
				case 'asin':
				case 'i':
				case 'isbn':
					$amazon_domain = apply_filters( 'amazon_domain', 'www.amazon.com' );
					$amazon_id     = apply_filters( 'amazon_affiliate_id', false );
					if ( $amazon_id ) {
						// valid partner shortlink, checked by
						// https://partnernet.amazon.de/gp/associates/network/tools/link-checker/main.html
						$url = 'http://' . $amazon_domain . '/dp/product/' . $id . '?tag=' . $amazon_id;
					} else {
						$url = 'http://' . $amazon_domain . '/dp/product/' . $id;
					}
					break;
			}
			$url = apply_filters( "hum_redirect_i_{$subtype}", $url, $id );
		}
		return $url;
	}

	/**
	 * Add rewrite rules for hum shortlinks.
	 */
	public function rewrite_rules() {
		$local_types    = $this->local_types();
		$redirect_types = $this->redirect_types();

		$types = array_merge( $local_types, $redirect_types );
		$types = implode( '', array_unique( $types ) );

		add_rewrite_rule( "([{$types}](\/.*)?$)", 'index.php?hum=$matches[1]', 'top' );
	}

	/**
	 * Add rewrite rules for hum shortlinks.
	 */
	public function flush_rewrite_rules() {
		$this->rewrite_rules();
		flush_rewrite_rules();
	}

	/**
	 * Get the base URL for hum shortlinks. Defaults to the WordPress home url.
	 * Users can define HUM_SHORTLINK_BASE or provide a filter to use a custom
	 * domain for shortlinks.
	 *
	 * @uses apply_filters() Calls 'hum_shortlink_base' filter on base URL.
	 *
	 * @return string
	 */
	public function shortlink_base() {
		$base = get_option( 'hum_shortlink_base' );
		if ( empty( $base ) ) {
			$base = home_url();
		}
		return apply_filters( 'hum_shortlink_base', $base );
	}

	/**
	 * Allow the constant named 'HUM_SHORTLINK_BASE' to override the base URL for shortlinks.
	 *
	 * @param string $url The short URL.
	 */
	public function config_shortlink_base( $url = '' ) {
		if ( defined( 'HUM_SHORTLINK_BASE' ) ) {
			return untrailingslashit( HUM_SHORTLINK_BASE );
		}
		return $url;
	}

	/**
	 * Get the shortlink for a post, page, attachment, or blog.
	 *
	 * @param int    $id          A post or site ID. Default is 0, which means the current post or site.
	 * @param string $context     Whether the ID is a 'site' ID, 'post' ID, or 'media' ID. If 'post',
	 *                            the post_type of the post is consulted. If 'query', the current query is consulted
	 *                            to determine the ID and context. Default 'post'.
	 * @param bool   $allow_slugs Whether to allow post slugs in the shortlink. It is up to the plugin how
	 *                            and whether to honor this. Default true.
	 * @return string
	 */
	public function get_shortlink( $link, $id, $context, $allow_slugs ) {
		$post_id = 0;
		if ( 'query' === $context && is_singular() ) {
			$post_id = get_queried_object_id();
			$post    = get_post( $post_id );
		} elseif ( 'post' === $context ) {
			$post = get_post( $id );
			if ( ! empty( $post->ID ) ) {
				$post_id = $post->ID;
			}
		}

		if ( ! empty( $post_id ) ) {
			$type   = $this->type_prefix( $post_id );
			$sxg_id = num_to_sxg( $post_id );
			$link   = trailingslashit( $this->shortlink_base() ) . $type . '/' . $sxg_id;
		}

		return $link;
	}

	/**
	 * Get the content-type prefix for the specified post.
	 *
	 * @see http://ttk.me/w/Whistle#design
	 * @uses apply_filters() Calls 'hum_type_prefix' on the content type prefix.
	 *
	 * @param int|object $post A post
	 * @return string The content type prefix for the post.
	 */
	public function type_prefix( $post ) {
		$prefix = 'b';

		$post_type = get_post_type( $post );

		if ( 'attachment' === $post_type ) {
			// check if $post is a WP_Post or an ID
			if ( is_numeric( $post ) ) {
				$post_id = $post;
			} else {
				$post_id = $post->ID;
			}

			$mime_type  = get_post_mime_type( $post_id );
			$media_type = preg_replace( '/(\/[a-zA-Z]+)/i', '', $mime_type );

			switch ( $media_type ) {
				case 'audio':
				case 'video':
					$prefix = 'a';
					break;
				case 'image':
					$prefix = 'p';
					break;
			}

			// @todo add support for slides
		} else {
			$post_format = get_post_format( $post );
			switch ( $post_format ) {
				case 'aside':
				case 'status':
				case 'link':
					$prefix = 't';
					break;
				case 'audio':
				case 'video':
					$prefix = 'a';
					break;
				case 'photo':
				case 'gallery':
				case 'image':
					$prefix = 'p';
					break;
			}
		}

		return apply_filters( 'hum_type_prefix', $prefix, $post );
	}

	/**
	 * Support redirects from legacy short URL schemes. This allows users to migrate from other
	 * shortlink generaters, but still have hum support the old URLs.
	 *
	 * @uses do_action() Calls 'hum_legacy_id' with the post ID and shortlink path.
	 */
	public function legacy_redirect() {
		if ( is_404() ) {
			global $wp;
			$post_id = apply_filters( 'hum_legacy_id', 0, $wp->request );
			if ( $post_id ) {
				$url = get_permalink( $post_id );
				if ( $url ) {
					$url = apply_filters( 'hum_legacy_redirect', $url );
					wp_redirect( $url, 301 );
					exit;
				}
			}
		}
	}

	/**
	 * Handle shortlinks generated by Friendly Twitter Links, which take the form
	 * /{id}, where {id} can be the base10 or base32 post ID.
	 *
	 * @param int $id post ID to filter on.
	 * @param string $path URL path (without preceding slash) of the request.
	 *
	 * @return string ID of post to redirect to.
	 */
	public function legacy_ftl_id( $id, $path ) {
		if ( is_numeric( $path ) ) {
			$post = get_post( $path );
		} else {
			$post_id = base_convert( preg_replace( '/[^0-9a-fA-F]/', '', $path ), 32, 10 );
			$post    = get_post( $post_id );
		}

		if ( $post ) {
			$id = $post->ID;
		}

		return $id;
	}


	// Admin Settings

	/**
	 * Register admin settings for Hum.
	 */
	public function admin_init() {
		register_setting( 'general', 'hum_shortlink_base' );
	}

	/**
	 * Add admin settings fields for Hum.
	 */
	public function admin_menu() {
		add_settings_field( 'hum_shortlink_base', __( 'Shortlink Base (URL)', 'hum' ), array( $this, 'admin_shortlink_base' ), 'general' );
	}

	/**
	 * Admin UI for setting the shortlink base URL.
	 */
	public function admin_shortlink_base() {
		?>
		<input name="hum_shortlink_base" type="text" id="hum_shortlink_base"
				value="<?php form_option( 'hum_shortlink_base' ); ?>"
				<?php disabled( defined( 'HUM_SHORTLINK_BASE' ) ); ?>
				class="regular-text code<?php if ( defined( 'HUM_SHORTLINK_BASE' ) ) { echo ' disabled'; } // phpcs:ignore Squiz.ControlStructures.ControlSignature.NewlineAfterOpenBrace ?>" />
		<p class="description">
			<?php _e( 'If you have a custom domain you want to use for shortlinks, enter the address here.', 'hum' ); ?>
		</p>

		<script type="text/javascript">
			// move adjacent to other URL properties
			jQuery('input#hum_shortlink_base').parents('tr').insertAfter( jQuery('input#home').parents('tr') );
		</script>
		<?php
	}

	/**
	 * Add shortlink <link /> to Atom-Entry.
	 */
	public function shortlink_atom_entry() {
		$shortlink = wp_get_shortlink();
		if ( $shortlink ) {
			echo "\t\t" . '<link rel="shortlink" href="' . esc_attr( $shortlink ) . '" />' . PHP_EOL;
		}
	}

	/**
	 * Show shortlink column.
	 *
	 * @param array $columns The list of columns.
	 */
	public function add_post_column( $columns ) {
		$reorderes_columns = array();
		foreach ( $columns as $key => $value ) {
			if ( 'date' === $key ) {
				$reorderes_columns['shortlink'] = esc_html__( 'Shortlink', 'hum' );
			}
			$reorderes_columns[ $key ] = $value;
		}

		return $reorderes_columns;
	}

	/**
	 * Generate shortlink column.
	 *
	 * @param string $column_name The culumn name.
	 * @param string $post_id The post id.
	 */
	public function add_posts_custom_column( $column_name, $post_id ) {
		if ( 'shortlink' === $column_name ) {
			printf( '<small>%s</small>', wp_get_shortlink( $post_id ) );
		}
	}
}

new Hum();


// New Base 60 - see http://ttk.me/w/NewBase60
//
// slightly modified from Cassis Project (http://cassisproject.com/)
// Copyright 2010 Tantek Çelik, used with permission under CC0 license (http://git.io/tZ8fjw)
//
// @codingStandardsIgnoreStart
if ( ! function_exists( 'num_to_sxg' ) ) :
	/**
	 * Convert base-10 number to sexagesimal.
	 */
	function num_to_sxg($n) {
		$s = "";
		$m = "0123456789ABCDEFGHJKLMNPQRSTUVWXYZ_abcdefghijkmnopqrstuvwxyz";
		if ($n===null || $n===0) { return 0; }
		while ($n>0) {
			$d = $n % 60;
			$s = $m[$d] . $s;
			$n = ($n-$d)/60;
		}
		return $s;
	}
endif;


if ( ! function_exists( 'sxg_to_num' ) ) :
	/**
	 * Convert sexagesimal to base-10 number.
	 */
	function sxg_to_num( $s ) {
		$n = 0;
		$j = strlen($s);
		for ($i=0;$i<$j;$i++) { // iterate from first to last char of $s
			$c = ord($s[$i]); //	put current ASCII of char into $c
			if ($c>=48 && $c<=57) { $c=$c-48; }
			else if ($c>=65 && $c<=72) { $c-=55; }
			else if ($c==73 || $c==108) { $c=1; } // typo capital I, lowercase l to 1
			else if ($c>=74 && $c<=78) { $c-=56; }
			else if ($c==79) { $c=0; } // error correct typo capital O to 0
			else if ($c>=80 && $c<=90) { $c-=57; }
			else if ($c==95) { $c=34; } // underscore
			else if ($c>=97 && $c<=107) { $c-=62; }
			else if ($c>=109 && $c<=122) { $c-=63; }
			else { $c = 0; } // treat all other noise as 0
			$n = 60*$n + $c;
		}
		return $n;
	}
endif;
// @codingStandardsIgnoreEnd
