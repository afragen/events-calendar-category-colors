<?php

namespace Fragen\Category_Colors;

use DateTime,
	Tribe__Events__Main;

/**
 * Class Frontend
 *
 * @package Fragen\Category_Colors
 */
class Frontend {
	protected $teccc     = null;
	protected $options   = array();
	protected $cache_key = null;

	protected $legendTargetHook   = 'tribe_events_after_header';
	protected $legendFilterHasRun = false;
	protected $legendExtraView    = array();

	public function __construct( Main $teccc ) {
		$this->teccc     = $teccc;
		$this->options   = Admin::fetch_options( $teccc );
		$this->cache_key = 'teccc_' . $this->options_hash();

		require_once TECCC_INCLUDES . '/templatetags.php';

		add_action( 'init', array( $this, 'add_colored_categories' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'add_scripts_styles' ), PHP_INT_MAX - 100 );
	}

	/**
	 * Create stylesheet and show legend.
	 */
	public function add_colored_categories() {
		$this->generate_css();
		add_action( $this->legendTargetHook, array( $this, 'show_legend' ) );
	}

	/**
	 * Enqueue stylesheets and scripts as appropriate.
	 */
	public function add_scripts_styles() {
		$min            = defined( 'WP_DEBUG' ) && WP_DEBUG ? null : '.min';
		$stylesheet_url = WP_CONTENT_URL . "/uploads/{$this->cache_key}{$min}.css";
		wp_register_style( 'teccc_stylesheet', $stylesheet_url, false, Main::$version );

		// Let's test to see if any event-related post types were requested
		$event_types     = array( 'tribe_events', 'tribe_organizer', 'tribe_venue' );
		$requested_types = (array) get_query_var( 'post_type' );
		$found_types     = array_intersect( $event_types, $requested_types );

		if ( ! empty( $found_types ) || $this->has_tribe_shortcodes() ) {
			wp_enqueue_style( 'teccc_stylesheet' );
		}

		// If the color widgets setting is enabled we also need to enqueue styles
		// This also enqueues styles all the time
		if ( isset( $this->options['color_widgets'] ) && '1' === $this->options['color_widgets'] ) {
			wp_enqueue_style( 'teccc_stylesheet' );
		}

		// Optionally add legend superpowers
		if ( isset( $this->options['legend_superpowers'] ) &&
			'1' === $this->options['legend_superpowers'] &&
			! wp_is_mobile()
		) {
			wp_enqueue_script( 'legend_superpowers', TECCC_RESOURCES . '/legend-superpowers.js', array( 'jquery' ), Main::$version, true );
		}
	}

	/**
	 * Find tribe shortcodes in post/page contents.
	 *
	 * @return bool
	 */
	private function has_tribe_shortcodes() {
		$tribe_shortcodes = array(
			'tribe_events',
			'tribe_event_inline',
			'tribe_mini_calendar',
			'tribe_this_week',
			'tribe_events_list',
			'tribe_featured_venue',
		);

		$current_post         = get_post( get_the_ID() );
		$current_post_content = $current_post instanceof \WP_Post ? $current_post->post_content : '';

		$found_shortcodes = array_filter(
			$tribe_shortcodes, function( $e ) use ( $current_post_content ) {
				return has_shortcode( $current_post_content, $e );
			}
		);

		return ! empty( $found_shortcodes );
	}


	/**
	 * By generating a unique hash of the plugin options and other relevant settings
	 * if these change so will the stylesheet URL, forcing the browser to grab an
	 * updated copy.
	 *
	 * @return string
	 */
	protected function options_hash() {
		// Current options are the basis of the current config
		$config = (array) $this->options;

		// Terms are relevant but need to be flattened out
		foreach ( $config as $key => $value ) {
			if ( isset( $key ) && is_array( $value ) ) {
				$config[ $key ] = implode( '|', array_keys( $value ) );
			}
		}

		// We also need to be cognizant of the mobile breakpoint
		$config['breakpoint'] = tribe_get_mobile_breakpoint();

		return hash( 'md5', implode( '|', $config ) );
	}

	/**
	 * Create CSS for stylesheet, standard and minified.
	 *
	 * @link https://gist.github.com/manastungare/2625128
	 *
	 * @return mixed|string
	 */
	protected function generate_css() {
		// Look out for refresh requests.
		$refresh_css = array_key_exists( 'refresh_css', $_GET ) ? true : false;
		$debug_css   = array_key_exists( 'debug_css', $_GET ) ? true : false;
		$current     = get_transient( $this->cache_key );

		// Return if fresh CSS hasn't been requested.
		if ( $current && ( ! $refresh_css && ! $debug_css ) ) {
			return false;
		}

		// Else generate the CSS afresh.
		$css     = $this->teccc->view(
			'category.css', array(
				'options' => $this->options,
				'teccc'   => $this->teccc,
			), false
		);
		$css_min = $this->minify_css( $css );

		$css_path = WP_CONTENT_DIR . '/uploads/';
		file_put_contents( $css_path . "{$this->cache_key}.css", $css );
		file_put_contents( $css_path . "{$this->cache_key}.min.css", $css_min );

		// Store in transient
		set_transient( $this->cache_key, true, 4 * WEEK_IN_SECONDS );

		return true;
	}

	/**
	 * Minify CSS.
	 *
	 * Removes comments, spaces after colons, spaces around braces, and whitespace.
	 *
	 * @param string $css
	 * @return string $css Minified CSS.
	 */
	private function minify_css( $css ) {
		/**
		 * 1. Remove comments.
		 * 2. Remove space after colons.
		 * 3. Remove space before and after braces.
		 * 4. Remove whitespace.
		 */
		$css = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css );
		$css = str_replace( ': ', ':', $css );
		$css = preg_replace( '/\s?({|})\s?/', '$1', $css );
		$css = str_replace(
			array(
				"\r\n",
				"\r",
				"\n",
				"\t",
				'  ',
				'   ',
				'    ',
			), '', $css
		);

		return $css;
	}

	/**
	 * Displays legend.
	 *
	 * @param string $existingContent
	 *
	 * @return bool
	 */
	public function show_legend( $existingContent = '' ) {
		$tribe         = Tribe__Events__Main::instance();
		$eventDisplays = array( 'month' );
		$eventDisplays = array_merge( $eventDisplays, $this->legendExtraView );
		$tribe_view    = get_query_var( 'eventDisplay' );
		if ( isset( $tribe->displaying ) && get_query_var( 'eventDisplay' ) !== $tribe->displaying ) {
			$tribe_view = $tribe->displaying;
		}
		if ( ( 'tribe_events' === get_query_var( 'post_type' ) ) && ! in_array( $tribe_view, $eventDisplays, true ) ) {
			return false;
		}
		if ( ! ( isset( $this->options['add_legend'] ) && '1' === $this->options['add_legend'] ) ) {
			return false;
		}

		$content = $this->teccc->view(
			'legend', array(
				'options' => $this->options,
				'teccc'   => Main::instance(),
				'tec'     => Tribe__Events__Main::instance(),
			), false
		);

		$this->legendFilterHasRun = true;

		/**
		 * Filter legend html to return modified version.
		 * Useful for appending text to legend.
		 *
		 * @return string $content
		 */
		echo $existingContent . apply_filters( 'teccc_legend_html', $content );
	}


	/**
	 * Move legend to different position.
	 *
	 * @param $tribeViewFilter
	 *
	 * @return bool
	 */
	public function reposition_legend( $tribeViewFilter ) {
		// If the legend has already run they are probably doing something wrong
		if ( $this->legendFilterHasRun ) {
			_doing_it_wrong(
				__CLASS__ . '::' . __METHOD__,
				'You are attempting to reposition the legend after it has already been rendered.', '1.6.4'
			);
		}

		// Change the target filter (even if they are _doing_it_wrong, in case they have a special use case)
		$this->legendTargetHook = $tribeViewFilter;

		// Indicate if they were doing it wrong (or not)
		return ( ! $this->legendFilterHasRun );
	}


	/**
	 * Remove default legend.
	 *
	 * @return bool
	 */
	public function remove_default_legend() {
		// If the legend has already run they are probably doing something wrong
		if ( $this->legendFilterHasRun ) {
			_doing_it_wrong(
				__CLASS__ . '::' . __METHOD__,
				'You are attempting to remove the default legend after it has already been rendered.', '1.6.4'
			);
		}

		// Remove the hook regardless of whether they are _doing_it_wrong or not (in case of creative usage)
		$this->legendTargetHook = null;

		// Indicate if they were doing it wrong (or not)
		return ( ! $this->legendFilterHasRun );
	}

	/**
	 * Add legend to additional views.
	 *
	 * @param $view
	 */
	public function add_legend_view( $view ) {
		// takes 'upcoming', 'day', 'week', 'photo' as parameters
		$this->legendExtraView[] = $view;
	}

}
