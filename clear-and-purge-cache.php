<?php
/**
 * Plugin Name:       Clear and Purge Cache
 * Plugin URI:        https://monetiscope.com
 * Description:       Professional-grade WordPress caching, file minification, and database maintenance engine built to scale site speeds. Unlocks premium features entirely for free.
 * Version:           1.0.0
 * Author:            Monetiscope
 * Author URI:        https://monetiscope.com
 * Text Domain:       clear-and-purge-cache
 * Domain Path:       /languages
 * Requires at least: 5.0
 * Tested up to:      7.0
 * License:           GPLv2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Clear_And_Purge_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Include required classes
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cache-engine.php';

class Clear_And_Purge_Cache {

	/**
	 * Main class instance.
	 */
	private static $instance = null;

	/**
	 * Cache Engine Instance.
	 */
	public $engine;

	/**
	 * Singleton pattern.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor bootstrapper.
	 */
	private function __construct() {
		$this->engine = new CPC_Cache_Engine();
		$this->engine->init();

		// Enqueue scripts and styles in Admin Dashboard
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		// Enqueue scripts for Admin Bar actions on the Front-end
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		// Register settings page in sidebar menu
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );

		// Hook into Admin Bar menu
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_menu' ), 100 );

		// Activation & Deactivation routines
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
	}

	/**
	 * Activation hook logic.
	 */
	public function activate() {
		$default = CPC_Cache_Engine::get_default_settings();
		add_option( CPC_Cache_Engine::OPTION_KEY, $default );

		// Make sure cache folder is writable
		wp_mkdir_p( WP_CONTENT_DIR . '/cache/clear-and-purge-cache' );
	}

	/**
	 * Deactivation hook logic.
	 */
	public function deactivate() {
		// Clean static cached pages
		$cache_dir = WP_CONTENT_DIR . '/cache/clear-and-purge-cache';
		if ( file_exists( $cache_dir ) ) {
			$files = glob( $cache_dir . '/*.html' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					@unlink( $file );
				}
			}
			@rmdir( $cache_dir );
		}

		// Restore .htaccess Gzip/Cache rules
		$htaccess_file = ABSPATH . '.htaccess';
		if ( file_exists( $htaccess_file ) && is_writable( $htaccess_file ) ) {
			$content = file_get_contents( $htaccess_file );
			$content = preg_replace( '/# BEGIN ClearAndPurgeCache.*# END ClearAndPurgeCache/s', '', $content );
			@file_put_contents( $htaccess_file, trim( $content ) );
		}
	}

	/**
	 * Enqueue admin stylesheet and scripts.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'settings_page_clear-and-purge-cache' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'cpc-dashboard-style',
			plugin_dir_url( __FILE__ ) . 'admin/css/dashboard-style.css',
			array(),
			'1.0.0'
		);

		wp_enqueue_script(
			'cpc-admin-actions',
			plugin_dir_url( __FILE__ ) . 'admin/js/admin-actions.js',
			array( 'jquery' ),
			'1.0.0',
			true
		);

		// Localize parameters for secure AJAX
		wp_localize_script( 'cpc-admin-actions', 'cpc_vars', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'cpc_admin_nonce' ),
			'is_admin' => 1
		) );
	}

	/**
	 * Enqueue minimal AJAX admin actions on frontend (for Admin Bar buttons).
	 */
	public function enqueue_frontend_assets() {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_script(
			'cpc-admin-actions-frontend',
			plugin_dir_url( __FILE__ ) . 'admin/js/admin-actions.js',
			array( 'jquery' ),
			'1.0.0',
			true
		);

		// Localize parameters for secure AJAX
		wp_localize_script( 'cpc-admin-actions-frontend', 'cpc_vars', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'cpc_admin_nonce' ),
			'is_admin' => 0,
			'post_id'  => is_singular() ? get_the_ID() : 0,
			'page_url' => esc_url_raw( ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] )
		) );
	}

	/**
	 * Register the administrative configuration page.
	 */
	public function register_settings_page() {
		add_options_page(
			esc_html__( 'Clear and Purge Cache', 'clear-and-purge-cache' ),
			esc_html__( 'Clear Cache', 'clear-and-purge-cache' ),
			'manage_options',
			'clear-and-purge-cache',
			array( $this, 'render_admin_dashboard' )
		);
	}

	/**
	 * Hook into standard admin bar menu.
	 */
	public function add_admin_bar_menu( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Top level node
		$wp_admin_bar->add_node( array(
			'id'    => 'cpc-admin-bar-menu',
			'title' => '<span class="ab-icon dashicons dashicons-performanceCPC" style="margin-top:2px;"></span>' . esc_html__( 'Purge Cache', 'clear-and-purge-cache' ),
			'href'  => admin_url( 'options-general.php?page=clear-and-purge-cache' ),
			'meta'  => array( 'class' => 'cpc-admin-bar-menu-root' )
		) );

		// Custom inline style for admin bar icon
		add_action( 'wp_before_admin_bar_render', function() {
			echo '<style>
				#wpadminbar .quicklinks li#wp-admin-bar-cpc-admin-bar-menu .ab-icon:before {
					content: "\f226";
					color: #f76809;
					top: 2px;
				}
				#wpadminbar .quicklinks li#wp-admin-bar-cpc-admin-bar-menu:hover .ab-icon:before {
					color: #fff;
				}
			</style>';
		} );

		// Dropdown node 1: Clear all cache
		$wp_admin_bar->add_node( array(
			'parent' => 'cpc-admin-bar-menu',
			'id'     => 'cpc-clear-all-node',
			'title'  => esc_html__( 'Clear all cache', 'clear-and-purge-cache' ),
			'href'   => '#',
			'meta'   => array( 'onclick' => 'CPC_Trigger_Clear_All(); return false;' )
		) );

		// Dropdown node 2: Clear cache of current page (Visible strictly on front-end)
		if ( ! is_admin() && is_singular() ) {
			$post = get_post();
			if ( $post ) {
				$wp_admin_bar->add_node( array(
					'parent' => 'cpc-admin-bar-menu',
					'id'     => 'cpc-clear-page-node',
					'title'  => esc_html__( 'Clear cache of this page', 'clear-and-purge-cache' ),
					'href'   => '#',
					'meta'   => array( 'onclick' => 'CPC_Trigger_Clear_Page(' . $post->ID . '); return false;' )
				) );
			}
		}

		// Dropdown node 3: Clear cache and Minified css/js
		$wp_admin_bar->add_node( array(
			'parent' => 'cpc-admin-bar-menu',
			'id'     => 'cpc-clear-minified-node',
			'title'  => esc_html__( 'Clear cache and Minified css/js', 'clear-and-purge-cache' ),
			'href'   => '#',
			'meta'   => array( 'onclick' => 'CPC_Trigger_Clear_Minified(); return false;' )
		) );
	}

	/**
	 * Render the full settings page panel structure (Part 2 and Part 3).
	 */
	public function render_admin_dashboard() {
		$settings = CPC_Cache_Engine::get_settings();
		?>
		<div class="wrap cpc-dashboard-wrap">
			<div class="cpc-header-banner">
				<div class="cpc-logo-container">
					<img src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'assets/icon-128x128.png' ); ?>" alt="Clear and Purge Cache" class="cpc-logo-img">
					<div class="cpc-title-sub">
						<h1><?php esc_html_e( 'Clear and Purge Cache', 'clear-and-purge-cache' ); ?></h1>
						<p><?php printf( esc_html__( 'Version %1$s | Developed by %2$s', 'clear-and-purge-cache' ), '1.0.0', '<a href="https://monetiscope.com" target="_blank" rel="noopener noreferrer">Monetiscope</a>' ); ?></p>
					</div>
				</div>
				<div class="cpc-header-actions">
					<button class="button button-primary cpc-quick-clear-btn" onclick="CPC_Trigger_Clear_All()"><?php esc_html_e( 'Purge All Cache Now', 'clear-and-purge-cache' ); ?></button>
				</div>
			</div>

			<div class="cpc-two-column-layout">
				<!-- Left Column: Main Configuration panel -->
				<main class="cpc-main-column">
					<!-- Administrative Tab Navigation Header -->
					<h2 class="nav-tab-wrapper cpc-tab-wrapper">
						<a href="#tab-settings" class="nav-tab nav-tab-active" data-tab="settings"><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'Settings', 'clear-and-purge-cache' ); ?></a>
						<a href="#tab-clear" class="nav-tab" data-tab="clear"><span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Clear Cache', 'clear-and-purge-cache' ); ?></a>
						<a href="#tab-images" class="nav-tab" data-tab="images"><span class="dashicons dashicons-format-image"></span> <?php esc_html_e( 'Image Optimization', 'clear-and-purge-cache' ); ?></a>
						<a href="#tab-exclude" class="nav-tab" data-tab="exclude"><span class="dashicons dashicons-dismiss"></span> <?php esc_html_e( 'Exclude', 'clear-and-purge-cache' ); ?></a>
						<a href="#tab-db" class="nav-tab" data-tab="db"><span class="dashicons dashicons-database"></span> <?php esc_html_e( 'DB Optimization', 'clear-and-purge-cache' ); ?></a>
					</h2>

					<form id="cpc-settings-form" method="post">
						<!-- Tab 1: Settings Panel -->
						<div id="cpc-tab-settings" class="cpc-tab-content cpc-tab-active">
							<div class="cpc-card">
								<h3><span class="dashicons dashicons-admin-plugins"></span> <?php esc_html_e( 'Caching Engine Engine Controls', 'clear-and-purge-cache' ); ?></h3>
								<table class="form-table cpc-form-table">
									<tr>
										<th><label><?php esc_html_e( 'Cache System', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<label class="cpc-switch">
												<input type="checkbox" name="cache_enabled" value="1" <?php checked( $settings['cache_enabled'], 1 ); ?>>
												<span class="cpc-slider"></span>
											</label>
											<p class="description"><?php esc_html_e( 'Globally enable or disable page buffering static compilation.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Widget Cache', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<label class="cpc-switch">
												<input type="checkbox" name="widget_cache" value="1" <?php checked( $settings['widget_cache'], 1 ); ?>>
												<span class="cpc-slider"></span>
											</label>
											<p class="description"><?php esc_html_e( 'Save cached variants of dashboard and theme widgets to minimize redundant SQL queries.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Preload Caches', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<label class="cpc-switch">
												<input type="checkbox" name="preload_cache" value="1" <?php checked( $settings['preload_cache'], 1 ); ?>>
												<span class="cpc-slider"></span>
											</label>
											<p class="description"><?php esc_html_e( 'Automate background crawler queues to pre-build page caches.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Logged-in Users Exception', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<label class="cpc-switch">
												<input type="checkbox" name="logged_in_users" value="1" <?php checked( $settings['logged_in_users'], 1 ); ?>>
												<span class="cpc-slider"></span>
											</label>
											<p class="description"><?php esc_html_e( 'Exclude serving cached static copies to authenticated administrators or authors.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Mobile Cache Exclusion', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<label class="cpc-switch">
												<input type="checkbox" name="mobile_cache" value="1" <?php checked( $settings['mobile_cache'], 1 ); ?>>
												<span class="cpc-slider"></span>
											</label>
											<p class="description"><?php esc_html_e( 'Prevent serving standard desktop cached static files to mobile browser requests.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Mobile Theme optimization', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<label class="cpc-switch">
												<input type="checkbox" name="mobile_theme" value="1" <?php checked( $settings['mobile_theme'], 1 ); ?>>
												<span class="cpc-slider"></span>
											</label>
											<p class="description"><?php esc_html_e( 'Dedicated caching profile segment optimized specifically for mobile templates.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Publish / Update Purge', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<label class="cpc-switch">
												<input type="checkbox" name="clear_on_update" value="1" <?php checked( $settings['clear_on_update'], 1 ); ?>>
												<span class="cpc-slider"></span>
											</label>
											<p class="description"><?php esc_html_e( 'Instantly clear individual static cache files whenever page, post or custom nodes are created or updated.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
								</table>
							</div>

							<div class="cpc-card">
								<h3><span class="dashicons dashicons-code-standards"></span> <?php esc_html_e( 'Minification & Asset Processing Engine', 'clear-and-purge-cache' ); ?></h3>
								<table class="form-table cpc-form-table">
									<tr>
										<th><label><?php esc_html_e( 'Minify HTML', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<label class="cpc-switch">
												<input type="checkbox" name="minify_html" value="1" <?php checked( $settings['minify_html'], 1 ); ?>>
												<span class="cpc-slider"></span>
											</label>
											<span class="cpc-inline-control">
												<input type="checkbox" name="minify_html_plus" value="1" <?php checked( $settings['minify_html_plus'], 1 ); ?>> <strong><?php esc_html_e( 'HTML Plus (Aggressive)', 'clear-and-purge-cache' ); ?></strong>
											</span>
											<p class="description"><?php esc_html_e( 'Compress HTML responses. Aggressive mode strips all historic comments and deep spaces.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Minify CSS', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<label class="cpc-switch">
												<input type="checkbox" name="minify_css" value="1" <?php checked( $settings['minify_css'], 1 ); ?>>
												<span class="cpc-slider"></span>
											</label>
											<span class="cpc-inline-control">
												<input type="checkbox" name="minify_css_plus" value="1" <?php checked( $settings['minify_css_plus'], 1 ); ?>> <strong><?php esc_html_e( 'CSS Plus', 'clear-and-purge-cache' ); ?></strong>
											</span>
											<p class="description"><?php esc_html_e( 'Strip comments and compress parameters inside stylesheet enqueues.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Combine CSS Stylesheets', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<label class="cpc-switch">
												<input type="checkbox" name="combine_css" value="1" <?php checked( $settings['combine_css'], 1 ); ?>>
												<span class="cpc-slider"></span>
											</label>
											<p class="description"><?php esc_html_e( 'Concatenate all core structural style sheets into single compiled build nodes to reduce HTTP overhead.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Minify JavaScript', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<label class="cpc-switch">
												<input type="checkbox" name="minify_js" value="1" <?php checked( $settings['minify_js'], 1 ); ?>>
												<span class="cpc-slider"></span>
											</label>
											<span class="cpc-inline-control">
												<input type="checkbox" name="combine_js_header" value="1" <?php checked( $settings['combine_js_header'], 1 ); ?>> <strong><?php esc_html_e( 'Combine Header Scripts', 'clear-and-purge-cache' ); ?></strong>
											</span>
											<span class="cpc-inline-control">
												<input type="checkbox" name="combine_js_footer" value="1" <?php checked( $settings['combine_js_footer'], 1 ); ?>> <strong><?php esc_html_e( 'Combine Footer Scripts Plus', 'clear-and-purge-cache' ); ?></strong>
											</span>
											<p class="description"><?php esc_html_e( 'Compress JS script assets. Optionally compile enqueues and defer execution lines to body margins.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
								</table>
							</div>

							<div class="cpc-card">
								<h3><span class="dashicons dashicons-dashboard"></span> <?php esc_html_e( 'Browser Cache & Compression Protocols', 'clear-and-purge-cache' ); ?></h3>
								<table class="form-table cpc-form-table">
									<tr>
										<th><label><?php esc_html_e( 'Gzip Compression', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<label class="cpc-switch">
												<input type="checkbox" name="gzip_compression" value="1" <?php checked( $settings['gzip_compression'], 1 ); ?>>
												<span class="cpc-slider"></span>
											</label>
											<p class="description"><?php esc_html_e( 'Inject highly optimized mod_deflate rules directly into active .htaccess variables.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Browser Caching Headers', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<label class="cpc-switch">
												<input type="checkbox" name="browser_caching" value="1" <?php checked( $settings['browser_caching'], 1 ); ?>>
												<span class="cpc-slider"></span>
											</label>
											<p class="description"><?php esc_html_e( 'Force downstream browser cache control tags (Cache-Control, Expires parameters) for site media assets.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
								</table>
							</div>

							<div class="cpc-card">
								<h3><span class="dashicons dashicons-clock"></span> <?php esc_html_e( 'Speed Optimization Tweaks', 'clear-and-purge-cache' ); ?></h3>
								<table class="form-table cpc-form-table">
									<tr>
										<th><label><?php esc_html_e( 'Disable Core Emojis', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<label class="cpc-switch">
												<input type="checkbox" name="disable_emojis" value="1" <?php checked( $settings['disable_emojis'], 1 ); ?>>
												<span class="cpc-slider"></span>
											</label>
											<p class="description"><?php esc_html_e( 'Safely dequeue inline emojis scripts, styles, and extra metadata structures to clear code markup.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Render Blocking JavaScript', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<label class="cpc-switch">
												<input type="checkbox" name="render_blocking_js" value="1" <?php checked( $settings['render_blocking_js'], 1 ); ?>>
												<span class="cpc-slider"></span>
											</label>
											<p class="description"><?php esc_html_e( 'Add "defer" or "async" targets to critical non-essential script resources.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Google Fonts Async Load', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<label class="cpc-switch">
												<input type="checkbox" name="async_google_fonts" value="1" <?php checked( $settings['async_google_fonts'], 1 ); ?>>
												<span class="cpc-slider"></span>
											</label>
											<p class="description"><?php esc_html_e( 'Handle asynchronous, render-friendly external font loading routines.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Lazy Load Imagery', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<label class="cpc-switch">
												<input type="checkbox" name="lazy_load" value="1" <?php checked( $settings['lazy_load'], 1 ); ?>>
												<span class="cpc-slider"></span>
											</label>
											<p class="description"><?php esc_html_e( 'Intercept imagery and frame targets, loading them strictly when entering the viewport.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Delay JS Execution', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<label class="cpc-switch">
												<input type="checkbox" name="delay_js" value="1" <?php checked( $settings['delay_js'], 1 ); ?>>
												<span class="cpc-slider"></span>
											</label>
											<p class="description"><?php esc_html_e( 'Hold non-essential scripts from compiling until first human interaction indicators trigger.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
								</table>
							</div>

							<div class="cpc-form-actions">
								<button type="submit" class="button button-primary button-large cpc-btn-save-settings"><?php esc_html_e( 'Save Settings Configuration', 'clear-and-purge-cache' ); ?></button>
							</div>
						</div>

						<!-- Tab 2: Clear Cache & Proxy Rules -->
						<div id="cpc-tab-clear" class="cpc-tab-content">
							<div class="cpc-stats-row">
								<div class="cpc-stat-card">
									<h4><?php esc_html_e( 'Desktop Cache', 'clear-and-purge-cache' ); ?></h4>
									<div class="cpc-stat-value" id="cpc-stat-desktop-size">
										<?php
										$cache_dir = WP_CONTENT_DIR . '/cache/clear-and-purge-cache';
										$desktop_files = glob( $cache_dir . '/desktop-*.html' );
										echo is_array( $desktop_files ) ? count( $desktop_files ) : 0;
										?>
										<span><?php esc_html_e( 'files compiled', 'clear-and-purge-cache' ); ?></span>
									</div>
								</div>
								<div class="cpc-stat-card">
									<h4><?php esc_html_e( 'Mobile Cache', 'clear-and-purge-cache' ); ?></h4>
									<div class="cpc-stat-value" id="cpc-stat-mobile-size">
										<?php
										$mobile_files = glob( $cache_dir . '/mobile-*.html' );
										echo is_array( $mobile_files ) ? count( $mobile_files ) : 0;
										?>
										<span><?php esc_html_e( 'files compiled', 'clear-and-purge-cache' ); ?></span>
									</div>
								</div>
								<div class="cpc-stat-card">
									<h4><?php esc_html_e( 'Minified CSS', 'clear-and-purge-cache' ); ?></h4>
									<div class="cpc-stat-value" id="cpc-stat-css-size">
										<?php
										$css_files = glob( $cache_dir . '/min/*.css' );
										echo is_array( $css_files ) ? count( $css_files ) : 0;
										?>
										<span><?php esc_html_e( 'stylesheets minified', 'clear-and-purge-cache' ); ?></span>
									</div>
								</div>
								<div class="cpc-stat-card">
									<h4><?php esc_html_e( 'Minified JS', 'clear-and-purge-cache' ); ?></h4>
									<div class="cpc-stat-value" id="cpc-stat-js-size">
										<?php
										$js_files = glob( $cache_dir . '/min/*.js' );
										echo is_array( $js_files ) ? count( $js_files ) : 0;
										?>
										<span><?php esc_html_e( 'script builds', 'clear-and-purge-cache' ); ?></span>
									</div>
								</div>
							</div>

							<div class="cpc-card">
								<h3><?php esc_html_e( 'Manual Purging Actions', 'clear-and-purge-cache' ); ?></h3>
								<p><?php esc_html_e( 'Manually force caching systems to invalidate current static outputs. Choose between purging pages or complete stylesheet minifications.', 'clear-and-purge-cache' ); ?></p>
								<div class="cpc-purge-btn-group">
									<button type="button" class="button button-large cpc-btn-action-primary" onclick="CPC_Trigger_Clear_All()"><?php esc_html_e( 'Clear All Cache', 'clear-and-purge-cache' ); ?></button>
									<button type="button" class="button button-large cpc-btn-action-secondary" onclick="CPC_Trigger_Clear_Minified()"><?php esc_html_e( 'Clear Cache and Minified CSS/JS', 'clear-and-purge-cache' ); ?></button>
								</div>
							</div>

							<div class="cpc-card">
								<h3><?php esc_html_e( 'Dynamic Cache Timeout & Exclusions', 'clear-and-purge-cache' ); ?></h3>
								<div class="cpc-flex-row">
									<div class="cpc-flex-col">
										<label><strong><?php esc_html_e( 'Global Cache Timeout (Seconds)', 'clear-and-purge-cache' ); ?></strong></label>
										<input type="number" name="cache_timeout" value="<?php echo esc_attr( $settings['cache_timeout'] ); ?>" class="regular-text">
										<p class="description"><?php esc_html_e( 'Define duration before the engine invalidates and re-preloads compiled pages.', 'clear-and-purge-cache' ); ?></p>
									</div>
								</div>
							</div>

							<div class="cpc-card">
								<h3><span class="dashicons dashicons-networking"></span> <?php esc_html_e( 'Reverse Proxy Integration (Varnish)', 'clear-and-purge-cache' ); ?></h3>
								<table class="form-table cpc-form-table">
									<tr>
										<th><label><?php esc_html_e( 'Enable Varnish Proxy Cache Purge', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<label class="cpc-switch">
												<input type="checkbox" name="varnish_proxy" value="1" <?php checked( $settings['varnish_proxy'], 1 ); ?>>
												<span class="cpc-slider"></span>
											</label>
											<p class="description"><?php esc_html_e( 'Activating this checkbox structure allows the plugin to instantly dispatch HTTP PURGE hooks directly onto local Varnish Cache configurations when posts are edited.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
								</table>
							</div>

							<div class="cpc-form-actions">
								<button type="submit" class="button button-primary button-large cpc-btn-save-settings"><?php esc_html_e( 'Save Cache Configuration Rules', 'clear-and-purge-cache' ); ?></button>
							</div>
						</div>

						<!-- Tab 3: Image Optimization Console -->
						<div id="cpc-tab-images" class="cpc-tab-content">
							<div class="cpc-flex-layout-row">
								<div class="cpc-card cpc-image-console-card">
									<h3><span class="dashicons dashicons-admin-appearance"></span> <?php esc_html_e( 'Image Optimization Metrics', 'clear-and-purge-cache' ); ?></h3>
									<div class="cpc-image-optim-row">
										<!-- Circle chart metrics graphics -->
										<div class="cpc-circle-chart-container">
											<svg viewBox="0 0 36 36" class="cpc-circular-chart">
												<path class="cpc-circle-bg"
													d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
												/>
												<path class="cpc-circle-succeed"
													id="cpc-circle-succeed-path"
													stroke-dasharray="<?php echo esc_attr( $settings['image_succeed_pct'] ); ?>, 100"
													d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
												/>
											</svg>
											<div class="cpc-percentage-inner">
												<span class="cpc-pct-value" id="cpc-image-succeed-text"><?php echo esc_html( $settings['image_succeed_pct'] ); ?>%</span>
												<span class="cpc-pct-lbl"><?php esc_html_e( 'Optimized', 'clear-and-purge-cache' ); ?></span>
											</div>
										</div>

										<div class="cpc-image-stats-meta">
											<div class="cpc-meta-item">
												<span class="cpc-color-indicator success"></span>
												<strong><?php esc_html_e( 'Succeed Status:', 'clear-and-purge-cache' ); ?></strong>
												<span class="cpc-meta-val" id="cpc-meta-succeed"><?php echo esc_html( $settings['image_succeed_pct'] ); ?>%</span>
											</div>
											<div class="cpc-meta-item">
												<span class="cpc-color-indicator pending"></span>
												<strong><?php esc_html_e( 'Pending Queue:', 'clear-and-purge-cache' ); ?></strong>
												<span class="cpc-meta-val" id="cpc-meta-pending"><?php echo esc_html( $settings['image_pending_count'] ); ?> <?php esc_html_e( 'assets', 'clear-and-purge-cache' ); ?></span>
											</div>
											<div class="cpc-meta-item">
												<span class="cpc-color-indicator errors"></span>
												<strong><?php esc_html_e( 'Optimization Errors:', 'clear-and-purge-cache' ); ?></strong>
												<span class="cpc-meta-val" id="cpc-meta-errors"><?php echo esc_html( $settings['image_error_count'] ); ?> <?php esc_html_e( 'failed', 'clear-and-purge-cache' ); ?></span>
											</div>
										</div>
									</div>

									<div class="cpc-telemetry-indicator">
										<div class="cpc-telemetry-box">
											<span class="dashicons dashicons-shield"></span>
											<div class="cpc-telemetry-box-text">
												<h4><?php esc_html_e( 'Total Disk Space Recovered', 'clear-and-purge-cache' ); ?></h4>
												<p id="cpc-recovered-size-display"><?php echo esc_html( size_format( $settings['image_data_recovered'], 2 ) ); ?></p>
											</div>
										</div>
									</div>

									<div class="cpc-optim-console-actions">
										<button type="button" class="button button-primary button-large cpc-btn-optimize-images" onclick="CPC_Trigger_Optimize_Images()"><?php esc_html_e( 'Optimize All Images Now', 'clear-and-purge-cache' ); ?></button>
									</div>
								</div>
							</div>
						</div>

						<!-- Tab 4: Exclude Caches & Minifiers -->
						<div id="cpc-tab-exclude" class="cpc-tab-content">
							<div class="cpc-card">
								<h3><span class="dashicons dashicons-no"></span> <?php esc_html_e( 'Exclude Caching Targets', 'clear-and-purge-cache' ); ?></h3>
								<p><?php esc_html_e( 'Management arrays to shield specified environments, URIs, user agents, cookies, and resources from being handled by the performance engine.', 'clear-and-purge-cache' ); ?></p>

								<table class="form-table cpc-form-table">
									<tr>
										<th><label><?php esc_html_e( 'Exclude Pages & URIs', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<textarea name="exclude_pages" rows="4" class="large-text code"><?php echo esc_textarea( $settings['exclude_pages'] ); ?></textarea>
											<p class="description"><?php esc_html_e( 'One per line. Matches URIs to block. Supports wildcards (e.g. wp-admin/*). Default parameters protect login pages.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Exclude User-Agents', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<textarea name="exclude_user_agents" rows="4" class="large-text code"><?php echo esc_textarea( $settings['exclude_user_agents'] ); ?></textarea>
											<p class="description"><?php esc_html_e( 'One per line. Blocks static cache serving for identified bot/crawler user agents (e.g. facebookexternalhit).', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Exclude Cookies', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<textarea name="exclude_cookies" rows="4" class="large-text code"><?php echo esc_textarea( $settings['exclude_cookies'] ); ?></textarea>
											<p class="description"><?php esc_html_e( 'One per line. Do not cache pages if these cookies are detected inside client browsers.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Exclude CSS Files', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<textarea name="exclude_css" rows="3" class="large-text code"><?php echo esc_textarea( $settings['exclude_css'] ); ?></textarea>
											<p class="description"><?php esc_html_e( 'Stylesheet paths or handle identifiers to exclude from automated combining/minifications.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Exclude JavaScript Scripts', 'clear-and-purge-cache' ); ?></label></th>
										<td>
											<textarea name="exclude_js" rows="3" class="large-text code"><?php echo esc_textarea( $settings['exclude_js'] ); ?></textarea>
											<p class="description"><?php esc_html_e( 'Script handles or inline tags to exclude from script delay or concatenation algorithms.', 'clear-and-purge-cache' ); ?></p>
										</td>
									</tr>
								</table>
							</div>

							<div class="cpc-form-actions">
								<button type="submit" class="button button-primary button-large cpc-btn-save-settings"><?php esc_html_e( 'Save Exclusion Rules', 'clear-and-purge-cache' ); ?></button>
							</div>
						</div>

						<!-- Tab 5: DB Database Optimization Suite -->
						<div id="cpc-tab-db" class="cpc-tab-content">
							<div class="cpc-card">
								<h3><span class="dashicons dashicons-database"></span> <?php esc_html_e( 'Automated Database Maintenance Engine', 'clear-and-purge-cache' ); ?></h3>
								<p><?php esc_html_e( 'Run a complete administrative deep scrub of your WordPress database. Wipes unused clutter, transients, dead revisions, and spans to minimize table overhead.', 'clear-and-purge-cache' ); ?></p>

								<div class="cpc-db-clean-checkboxes">
									<div class="cpc-db-row active">
										<span class="dashicons dashicons-yes-alt cpc-db-active-icon"></span>
										<div class="cpc-db-meta-text">
											<strong><?php esc_html_e( 'All Data Optimization Engine', 'clear-and-purge-cache' ); ?></strong>
											<p class="description"><?php esc_html_e( 'Performs optimization tables queries on all database arrays to clear blank lines.', 'clear-and-purge-cache' ); ?></p>
										</div>
									</div>

									<div class="cpc-db-row active">
										<span class="dashicons dashicons-yes-alt cpc-db-active-icon"></span>
										<div class="cpc-db-meta-text">
											<strong><?php esc_html_e( 'Post Revisions Cleanup', 'clear-and-purge-cache' ); ?></strong>
											<p class="description"><?php esc_html_e( 'Permanently cleans historical draft revisions. Wipes out post structural drafts.', 'clear-and-purge-cache' ); ?></p>
										</div>
									</div>

									<div class="cpc-db-row active">
										<span class="dashicons dashicons-yes-alt cpc-db-active-icon"></span>
										<div class="cpc-db-meta-text">
											<strong><?php esc_html_e( 'Trashed Content Purge', 'clear-and-purge-cache' ); ?></strong>
											<p class="description"><?php esc_html_e( 'Purges deleted posts, pages, and custom post formats from trash folders.', 'clear-and-purge-cache' ); ?></p>
										</div>
									</div>

									<div class="cpc-db-row active">
										<span class="dashicons dashicons-yes-alt cpc-db-active-icon"></span>
										<div class="cpc-db-meta-text">
											<strong><?php esc_html_e( 'Trashed & Spam Comments', 'clear-and-purge-cache' ); ?></strong>
											<p class="description"><?php esc_html_e( 'Scrapes user comments containing spam or historical deleted parameters.', 'clear-and-purge-cache' ); ?></p>
										</div>
									</div>

									<div class="cpc-db-row active">
										<span class="dashicons dashicons-yes-alt cpc-db-active-icon"></span>
										<div class="cpc-db-meta-text">
											<strong><?php esc_html_e( 'Trackbacks and Pingbacks', 'clear-and-purge-cache' ); ?></strong>
											<p class="description"><?php esc_html_e( 'Deletes core blog linkback parameters and related notification rows.', 'clear-and-purge-cache' ); ?></p>
										</div>
									</div>

									<div class="cpc-db-row active">
										<span class="dashicons dashicons-yes-alt cpc-db-active-icon"></span>
										<div class="cpc-db-meta-text">
											<strong><?php esc_html_e( 'Orphaned Metadata', 'clear-and-purge-cache' ); ?></strong>
											<p class="description"><?php esc_html_e( 'Deletes metadata records that do not link back to any posts, comments, or users.', 'clear-and-purge-cache' ); ?></p>
										</div>
									</div>

									<div class="cpc-db-row active">
										<span class="dashicons dashicons-yes-alt cpc-db-active-icon"></span>
										<div class="cpc-db-meta-text">
											<strong><?php esc_html_e( 'Orphaned Term Relationships', 'clear-and-purge-cache' ); ?></strong>
											<p class="description"><?php esc_html_e( 'Cleanses relationship index associations pointing to empty attributes.', 'clear-and-purge-cache' ); ?></p>
										</div>
									</div>

									<div class="cpc-db-row active">
										<span class="dashicons dashicons-yes-alt cpc-db-active-icon"></span>
										<div class="cpc-db-meta-text">
											<strong><?php esc_html_e( 'Expired Transient Options', 'clear-and-purge-cache' ); ?></strong>
											<p class="description"><?php esc_html_e( 'Scrapes dead temporary database session states and transactional cache hashes.', 'clear-and-purge-cache' ); ?></p>
										</div>
									</div>
								</div>

								<div class="cpc-db-actions-console">
									<button type="button" class="button button-primary button-large cpc-btn-optimize-db" onclick="CPC_Trigger_Optimize_DB()"><?php esc_html_e( 'Optimize Database Tables Now', 'clear-and-purge-cache' ); ?></button>
									<span class="cpc-db-loader-indicator" style="display:none;"><span class="spinner is-active"></span> <?php esc_html_e( 'Executing Optimization Sequence...', 'clear-and-purge-cache' ); ?></span>
								</div>

								<!-- DB optimization results box -->
								<div class="cpc-db-results-box" style="display:none;">
									<h4><?php esc_html_e( 'Database Scrub Completed!', 'clear-and-purge-cache' ); ?></h4>
									<ul id="cpc-db-results-list"></ul>
								</div>
							</div>
						</div>
					</form>
				</main>

				<!-- Right Column: Responsive Promotional Sidebar (Part 3) -->
				<aside class="cpc-sidebar-column">
					<!-- Lead Generation Banner Integration -->
					<div class="cpc-sidebar-card cpc-promo-card">
						<a href="https://monetiscope.com/contact" target="_blank" rel="noopener noreferrer" class="cpc-promo-link">
							<img src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'admin/images/monetiscope-promo-sidebar.png' ); ?>" alt="Monetiscope Lead Generation Banner" class="cpc-promo-banner-img">
						</a>
						<div class="cpc-promo-badge"><?php esc_html_e( 'Premium Ad Partner', 'clear-and-purge-cache' ); ?></div>
					</div>

					<!-- Social Follow Section -->
					<div class="cpc-sidebar-card cpc-social-card">
						<h3><?php esc_html_e( 'Connect with Us', 'clear-and-purge-cache' ); ?></h3>
						<p><?php esc_html_e( 'Follow us on LinkedIn for Optimization Tips & Updates', 'clear-and-purge-cache' ); ?></p>
						<a href="https://www.linkedin.com/company/monetiscope" target="_blank" rel="noopener noreferrer" class="cpc-linkedin-button">
							<!-- Inline SVG LinkedIn logo -->
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
								<path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.779-1.75-1.75s.784-1.75 1.75-1.75 1.75.779 1.75 1.75-.784 1.75-1.75 1.75zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/>
							</svg>
							<span><?php esc_html_e( 'Follow Monetiscope', 'clear-and-purge-cache' ); ?></span>
						</a>
					</div>
				</aside>
			</div>
		</div>
		<?php
	}
}

// Bootstrap single instance
Clear_And_Purge_Cache::get_instance();
