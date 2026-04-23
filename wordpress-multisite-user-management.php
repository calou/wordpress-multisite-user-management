<?php
/**
 * Plugin Name: WordPress Multisite User Management
 * Plugin URI:  https://github.com/gruchet/wordpress-multisite-user-management
 * Description: Assign roles to users and manage role capabilities across network sites from the Network Admin dashboard.
 * Version:     1.1.0
 * Network:     true
 * Author:      gruchet
 * License:     GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// AJAX user search (autocomplete)
// ---------------------------------------------------------------------------

add_action( 'wp_ajax_wsmum_search_users', 'wsmum_ajax_search_users' );
function wsmum_ajax_search_users() {
	if ( ! current_user_can( 'manage_network_users' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}

	check_ajax_referer( 'wsmum_search_users' );

	$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
	if ( strlen( $term ) < 2 ) {
		wp_send_json_success( [] );
	}

	$users = get_users( [
		'blog_id'        => 0,
		'search'         => '*' . $term . '*',
		'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
		'number'         => 10,
		'orderby'        => 'display_name',
	] );

	$results = array_map( function( $u ) {
		return [
			'id'    => $u->ID,
			'label' => $u->display_name . ' (' . $u->user_login . ')',
		];
	}, $users );

	wp_send_json_success( $results );
}

// ---------------------------------------------------------------------------
// Menu
// ---------------------------------------------------------------------------

add_action( 'network_admin_menu', 'wsmum_add_menu' );
function wsmum_add_menu() {
	add_menu_page(
		__( 'User Site Roles', 'wsmum' ),
		__( 'User Site Roles', 'wsmum' ),
		'manage_network_users',
		'wsmum-user-site-roles',
		'wsmum_render_page',
		'dashicons-groups',
		70
	);
}

// ---------------------------------------------------------------------------
// Admin notices
// ---------------------------------------------------------------------------

add_action( 'network_admin_notices', 'wsmum_admin_notice' );
function wsmum_admin_notice() {
	if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'wsmum-user-site-roles' ) {
		return;
	}

	$messages = [
		'wsmum_updated'      => [ 'success', __( 'User site roles updated.', 'wsmum' ) ],
		'wsmum_caps_updated' => [ 'success', __( 'Role capabilities updated.', 'wsmum' ) ],
		'wsmum_error'        => [ 'error',   __( 'Invalid request. Please try again.', 'wsmum' ) ],
	];

	foreach ( $messages as $key => [ $type, $text ] ) {
		if ( isset( $_GET[ $key ] ) ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $type ),
				esc_html( $text )
			);
		}
	}
}

// ---------------------------------------------------------------------------
// POST handler – assign role to user across sites
// ---------------------------------------------------------------------------

add_action( 'admin_post_wsmum_assign_roles', 'wsmum_handle_assignment' );
function wsmum_handle_assignment() {
	if ( ! current_user_can( 'manage_network_users' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'wsmum' ) );
	}

	check_admin_referer( 'wsmum_assign_roles' );

	$user_id = isset( $_POST['wsmum_user_id'] ) ? absint( $_POST['wsmum_user_id'] ) : 0;
	$role    = isset( $_POST['wsmum_role'] ) ? sanitize_key( $_POST['wsmum_role'] ) : '';
	$sites   = isset( $_POST['wsmum_sites'] ) && is_array( $_POST['wsmum_sites'] )
		? array_map( 'absint', $_POST['wsmum_sites'] )
		: [];

	$target_user = get_userdata( $user_id );
	$valid_roles = array_keys( wp_roles()->roles );

	if ( ! $target_user || empty( $role ) || ! in_array( $role, $valid_roles, true ) ) {
		wp_safe_redirect( add_query_arg( 'wsmum_error', 1, wsmum_page_url() ) );
		exit;
	}

	foreach ( $sites as $blog_id ) {
		if ( ! get_site( $blog_id ) ) {
			continue;
		}
		if ( ! is_user_member_of_blog( $user_id, $blog_id ) ) {
			add_user_to_blog( $blog_id, $user_id, $role );
		} else {
			$user_obj = new WP_User( $user_id, '', $blog_id );
			$user_obj->set_role( $role );
		}
	}

	wp_safe_redirect( add_query_arg( 'wsmum_updated', 1, wsmum_page_url( [ 'wsmum_uid' => $user_id ] ) ) );
	exit;
}

// ---------------------------------------------------------------------------
// POST handler – update role capabilities across sites
// ---------------------------------------------------------------------------

add_action( 'admin_post_wsmum_update_caps', 'wsmum_handle_capabilities' );
function wsmum_handle_capabilities() {
	if ( ! current_user_can( 'manage_network_users' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'wsmum' ) );
	}

	check_admin_referer( 'wsmum_update_caps' );

	$role_key = isset( $_POST['wsmum_cap_role'] ) ? sanitize_key( $_POST['wsmum_cap_role'] ) : '';
	$sites    = isset( $_POST['wsmum_sites'] ) && is_array( $_POST['wsmum_sites'] )
		? array_map( 'absint', $_POST['wsmum_sites'] )
		: [];
	$new_caps = isset( $_POST['wsmum_caps'] ) && is_array( $_POST['wsmum_caps'] )
		? array_map( 'sanitize_key', $_POST['wsmum_caps'] )
		: [];

	$valid_roles = array_keys( wp_roles()->roles );
	if ( empty( $role_key ) || ! in_array( $role_key, $valid_roles, true ) ) {
		wp_safe_redirect( add_query_arg( 'wsmum_error', 1, wsmum_page_url( [ 'wsmum_tab' => 'capabilities' ] ) ) );
		exit;
	}

	// Build the capabilities array: every submitted cap is granted (true).
	$caps_map = [];
	foreach ( $new_caps as $cap ) {
		if ( $cap !== '' ) {
			$caps_map[ $cap ] = true;
		}
	}

	foreach ( $sites as $blog_id ) {
		if ( ! get_site( $blog_id ) ) {
			continue;
		}
		switch_to_blog( $blog_id );
		$all_roles_option = get_option( 'user_roles' );
		if ( isset( $all_roles_option[ $role_key ] ) ) {
			$all_roles_option[ $role_key ]['capabilities'] = $caps_map;
			update_option( 'user_roles', $all_roles_option );
		}
		restore_current_blog();
	}

	wp_safe_redirect( add_query_arg(
		'wsmum_caps_updated', 1,
		wsmum_page_url( [ 'wsmum_tab' => 'capabilities', 'wsmum_cap_role' => $role_key ] )
	) );
	exit;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function wsmum_page_url( array $extra = [] ) {
	$args = array_merge( [ 'page' => 'wsmum-user-site-roles' ], $extra );
	return network_admin_url( 'admin.php?' . http_build_query( $args ) );
}

/**
 * Returns a sorted list of all capability keys known across all registered roles.
 */
function wsmum_all_caps() {
	$caps = [];
	foreach ( wp_roles()->roles as $role_data ) {
		foreach ( array_keys( $role_data['capabilities'] ) as $cap ) {
			$caps[ $cap ] = true;
		}
	}
	ksort( $caps );
	return array_keys( $caps );
}

// ---------------------------------------------------------------------------
// Page renderer
// ---------------------------------------------------------------------------

function wsmum_render_page() {
	if ( ! current_user_can( 'manage_network_users' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'wsmum' ) );
	}

	$active_tab       = ( isset( $_GET['wsmum_tab'] ) && $_GET['wsmum_tab'] === 'capabilities' ) ? 'capabilities' : 'roles';
	$selected_user_id = isset( $_GET['wsmum_uid'] ) ? absint( $_GET['wsmum_uid'] ) : 0;
	$selected_role    = isset( $_GET['wsmum_cap_role'] ) ? sanitize_key( $_GET['wsmum_cap_role'] ) : '';

	$all_sites     = get_sites( [ 'number' => 0 ] );
	$all_roles     = wp_roles()->roles;
	$all_caps_list = wsmum_all_caps();

	// Per-site current role for the selected user (tab 1).
	$current_user_roles = [];
	if ( $selected_user_id ) {
		foreach ( $all_sites as $site ) {
			if ( is_user_member_of_blog( $selected_user_id, $site->blog_id ) ) {
				$u = new WP_User( $selected_user_id, '', $site->blog_id );
				$current_user_roles[ $site->blog_id ] = reset( $u->roles ) ?: '';
			}
		}
	}

	// Capabilities currently granted for the selected role (from main site / wp_roles global).
	$role_current_caps = [];
	if ( $selected_role && isset( $all_roles[ $selected_role ] ) ) {
		foreach ( $all_roles[ $selected_role ]['capabilities'] as $cap => $granted ) {
			if ( $granted ) {
				$role_current_caps[ $cap ] = true;
			}
		}
	}

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'User Site Roles', 'wsmum' ); ?></h1>

		<nav class="nav-tab-wrapper" style="margin-bottom:20px;">
			<a href="<?php echo esc_url( wsmum_page_url( $selected_user_id ? [ 'wsmum_uid' => $selected_user_id ] : [] ) ); ?>"
				class="nav-tab <?php echo $active_tab === 'roles' ? 'nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Assign User Roles', 'wsmum' ); ?>
			</a>
			<a href="<?php echo esc_url( wsmum_page_url( array_filter( [ 'wsmum_tab' => 'capabilities', 'wsmum_cap_role' => $selected_role ] ) ) ); ?>"
				class="nav-tab <?php echo $active_tab === 'capabilities' ? 'nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Role Capabilities', 'wsmum' ); ?>
			</a>
		</nav>

		<?php if ( $active_tab === 'roles' ) : ?>

		<!-- ================================================================
		     TAB 1 – Assign a role to a user across sites
		     ================================================================ -->

		<!-- User autocomplete -->
		<form method="get" id="wsmum-user-form" action="<?php echo esc_url( network_admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="wsmum-user-site-roles">
			<input type="hidden" name="wsmum_uid" id="wsmum_uid" value="<?php echo esc_attr( $selected_user_id ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wsmum_user_search"><?php esc_html_e( 'Search user', 'wsmum' ); ?></label></th>
					<td>
						<div style="position:relative;display:inline-block;">
							<input type="text" id="wsmum_user_search" autocomplete="off" class="regular-text"
								placeholder="<?php esc_attr_e( 'Type a name, login or email…', 'wsmum' ); ?>"
								value="<?php
									if ( $selected_user_id ) {
										$u = get_userdata( $selected_user_id );
										echo $u ? esc_attr( $u->display_name . ' (' . $u->user_login . ')' ) : '';
									}
								?>">
							<ul id="wsmum-suggestions"></ul>
						</div>
						<p class="description" style="margin-top:4px;">
							<?php esc_html_e( 'Select a suggestion to load the user.', 'wsmum' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</form>

		<?php if ( $selected_user_id && get_userdata( $selected_user_id ) ) :
			$target = get_userdata( $selected_user_id );
		?>
		<hr>
		<h2><?php
			printf(
				/* translators: %s: display name + login */
				esc_html__( 'Assign roles for %s', 'wsmum' ),
				esc_html( $target->display_name . ' (' . $target->user_login . ')' )
			);
		?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wsmum_assign_roles' ); ?>
			<input type="hidden" name="action" value="wsmum_assign_roles">
			<input type="hidden" name="wsmum_user_id" value="<?php echo esc_attr( $selected_user_id ); ?>">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wsmum_role"><?php esc_html_e( 'Role to assign', 'wsmum' ); ?></label></th>
					<td>
						<select name="wsmum_role" id="wsmum_role">
							<?php foreach ( $all_roles as $role_key => $role_data ) : ?>
								<option value="<?php echo esc_attr( $role_key ); ?>">
									<?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Sites', 'wsmum' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Check the sites you want to assign the role above. Unchecked sites are left unchanged.', 'wsmum' ); ?></p>

			<?php wsmum_render_sites_table( $all_sites, $all_roles, $current_user_roles, 'wsmum-roles-site-cb' ); ?>

			<p style="margin-top:16px;"><?php submit_button( __( 'Apply role to selected sites', 'wsmum' ), 'primary', '', false ); ?></p>
		</form>
		<?php endif; // selected user ?>

		<?php else : ?>

		<!-- ================================================================
		     TAB 2 – Manage role capabilities across sites
		     ================================================================ -->

		<!-- Role selector -->
		<form method="get" action="<?php echo esc_url( network_admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="wsmum-user-site-roles">
			<input type="hidden" name="wsmum_tab" value="capabilities">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wsmum_cap_role"><?php esc_html_e( 'Select role', 'wsmum' ); ?></label></th>
					<td>
						<select name="wsmum_cap_role" id="wsmum_cap_role">
							<option value=""><?php esc_html_e( '— choose a role —', 'wsmum' ); ?></option>
							<?php foreach ( $all_roles as $role_key => $role_data ) : ?>
								<option value="<?php echo esc_attr( $role_key ); ?>"
									<?php selected( $selected_role, $role_key ); ?>>
									<?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php submit_button( __( 'Load role', 'wsmum' ), 'secondary', '', false ); ?>
					</td>
				</tr>
			</table>
		</form>

		<?php if ( $selected_role && isset( $all_roles[ $selected_role ] ) ) : ?>
		<hr>
		<h2><?php
			printf(
				/* translators: %s: role name */
				esc_html__( 'Capabilities for role: %s', 'wsmum' ),
				esc_html( translate_user_role( $all_roles[ $selected_role ]['name'] ) )
			);
		?></h2>
		<p class="description"><?php esc_html_e( 'The checked capabilities reflect the current definition on the main site. Adjust them and select the sites you want to push this capability set to.', 'wsmum' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wsmum_update_caps' ); ?>
			<input type="hidden" name="action" value="wsmum_update_caps">
			<input type="hidden" name="wsmum_cap_role" value="<?php echo esc_attr( $selected_role ); ?>">

			<h3><?php esc_html_e( 'Capabilities', 'wsmum' ); ?></h3>

			<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
				<label style="font-weight:600;">
					<input type="checkbox" id="wsmum_caps_check_all">
					<?php esc_html_e( 'Toggle all', 'wsmum' ); ?>
				</label>
				<input type="text" id="wsmum_cap_filter" class="regular-text" placeholder="<?php esc_attr_e( 'Filter capabilities…', 'wsmum' ); ?>" style="max-width:240px;">
			</div>

			<div id="wsmum-caps-grid" style="
				display:grid;
				grid-template-columns:repeat(auto-fill,minmax(260px,1fr));
				gap:4px 16px;
				background:#fff;border:1px solid #c3c4c7;border-radius:3px;
				padding:12px 16px;max-height:340px;overflow-y:auto;margin-bottom:20px;
			">
				<?php foreach ( $all_caps_list as $cap ) : ?>
					<label class="wsmum-cap-label" style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:2px 0;">
						<input type="checkbox" name="wsmum_caps[]" value="<?php echo esc_attr( $cap ); ?>"
							class="wsmum-cap-cb"
							<?php checked( isset( $role_current_caps[ $cap ] ) ); ?>>
						<code style="font-size:12px;"><?php echo esc_html( $cap ); ?></code>
					</label>
				<?php endforeach; ?>
			</div>

			<h3><?php esc_html_e( 'Sites', 'wsmum' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Select the sites where this capability set should be applied to the role.', 'wsmum' ); ?></p>

			<?php wsmum_render_sites_table( $all_sites, [], [], 'wsmum-caps-site-cb' ); ?>

			<p style="margin-top:16px;"><?php submit_button( __( 'Apply capabilities to selected sites', 'wsmum' ), 'primary', '', false ); ?></p>
		</form>

		<script>
		(function() {
			// Toggle all caps
			var capsCheckAll = document.getElementById('wsmum_caps_check_all');
			var capsCbs = document.querySelectorAll('.wsmum-cap-cb');
			if (capsCheckAll) {
				capsCheckAll.addEventListener('change', function() {
					capsCbs.forEach(function(cb) {
						if (cb.closest('.wsmum-cap-label').style.display !== 'none') {
							cb.checked = capsCheckAll.checked;
						}
					});
				});
			}

			// Filter caps
			var capFilter = document.getElementById('wsmum_cap_filter');
			if (capFilter) {
				capFilter.addEventListener('input', function() {
					var q = this.value.toLowerCase();
					document.querySelectorAll('.wsmum-cap-label').forEach(function(label) {
						var cap = label.querySelector('code').textContent.toLowerCase();
						label.style.display = cap.includes(q) ? '' : 'none';
					});
				});
			}

			// Toggle all sites
			var siteCheckAll = document.getElementById('wsmum_caps_check_all_sites');
			var siteCbs = document.querySelectorAll('.wsmum-caps-site-cb');
			if (siteCheckAll) {
				siteCheckAll.addEventListener('change', function() {
					siteCbs.forEach(function(cb) { cb.checked = siteCheckAll.checked; });
				});
			}
		})();
		</script>

		<?php endif; // selected role ?>

		<?php endif; // tab ?>

	</div>

	<style>
	#wsmum-suggestions {
		display:none;position:absolute;top:100%;left:0;
		z-index:9999;background:#fff;border:1px solid #c3c4c7;
		box-shadow:0 2px 6px rgba(0,0,0,.15);margin:0;padding:0;
		min-width:100%;list-style:none;max-height:240px;overflow-y:auto;
	}
	#wsmum-suggestions li { padding:6px 12px;cursor:pointer;white-space:nowrap; }
	#wsmum-suggestions li:hover,
	#wsmum-suggestions li.wsmum-active { background:#2271b1;color:#fff; }
	</style>

	<script>
	(function() {
		// ---- User autocomplete ----
		var searchInput = document.getElementById('wsmum_user_search');
		if (!searchInput) return;
		var hiddenInput = document.getElementById('wsmum_uid');
		var list        = document.getElementById('wsmum-suggestions');
		var form        = document.getElementById('wsmum-user-form');
		var ajaxUrl     = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		var nonce       = <?php echo wp_json_encode( wp_create_nonce( 'wsmum_search_users' ) ); ?>;
		var activeIndex = -1;
		var timer;

		searchInput.addEventListener('input', function() {
			clearTimeout(timer);
			var term = this.value.trim();
			if (term.length < 2) { closeList(); return; }
			timer = setTimeout(function() { fetchUsers(term); }, 250);
		});

		searchInput.addEventListener('keydown', function(e) {
			var items = list.querySelectorAll('li');
			if (!items.length) return;
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				activeIndex = Math.min(activeIndex + 1, items.length - 1);
				highlight(items);
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				activeIndex = Math.max(activeIndex - 1, 0);
				highlight(items);
			} else if (e.key === 'Enter') {
				e.preventDefault();
				if (activeIndex >= 0) items[activeIndex].click();
			} else if (e.key === 'Escape') {
				closeList();
			}
		});

		document.addEventListener('click', function(e) {
			if (e.target !== searchInput) closeList();
		});

		function fetchUsers(term) {
			var url = ajaxUrl + '?action=wsmum_search_users&_ajax_nonce=' + encodeURIComponent(nonce)
				+ '&term=' + encodeURIComponent(term);
			fetch(url, { credentials: 'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(data) { if (data.success) renderList(data.data); });
		}

		function renderList(users) {
			list.innerHTML = '';
			activeIndex = -1;
			if (!users.length) { closeList(); return; }
			users.forEach(function(u) {
				var li = document.createElement('li');
				li.textContent = u.label;
				li.dataset.id  = u.id;
				li.addEventListener('click', function() {
					searchInput.value = u.label;
					hiddenInput.value = u.id;
					closeList();
					form.submit();
				});
				list.appendChild(li);
			});
			list.style.display = 'block';
		}

		function highlight(items) {
			items.forEach(function(li, i) {
				li.classList.toggle('wsmum-active', i === activeIndex);
			});
			if (activeIndex >= 0) items[activeIndex].scrollIntoView({ block: 'nearest' });
		}

		function closeList() {
			list.style.display = 'none';
			list.innerHTML = '';
			activeIndex = -1;
		}

		// ---- Toggle-all for roles-tab site table ----
		var checkAll = document.getElementById('wsmum_roles_check_all_sites');
		var roleSiteCbs = document.querySelectorAll('.wsmum-roles-site-cb');
		if (checkAll) {
			checkAll.addEventListener('change', function() {
				roleSiteCbs.forEach(function(cb) { cb.checked = checkAll.checked; });
			});
		}
	})();
	</script>
	<?php
}

// ---------------------------------------------------------------------------
// Shared: site-selection table
// ---------------------------------------------------------------------------

/**
 * @param WP_Site[] $all_sites
 * @param array     $all_roles      Keyed by role slug; pass [] on caps tab.
 * @param array     $current_roles  blog_id → current role slug for the user; pass [] on caps tab.
 * @param string    $cb_class       CSS class applied to each site checkbox.
 */
function wsmum_render_sites_table( $all_sites, $all_roles, $current_roles, $cb_class ) {
	$toggle_id  = 'wsmum_' . ( $cb_class === 'wsmum-roles-site-cb' ? 'roles' : 'caps' ) . '_check_all_sites';
	$show_roles = ! empty( $all_roles );
	?>
	<table class="widefat striped" style="max-width:700px;margin-top:12px;">
		<thead>
			<tr>
				<th style="width:32px;">
					<input type="checkbox" id="<?php echo esc_attr( $toggle_id ); ?>"
						title="<?php esc_attr_e( 'Toggle all', 'wsmum' ); ?>">
				</th>
				<th><?php esc_html_e( 'Site', 'wsmum' ); ?></th>
				<?php if ( $show_roles ) : ?>
				<th><?php esc_html_e( 'Current role', 'wsmum' ); ?></th>
				<?php endif; ?>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $all_sites as $site ) :
			$blog_id   = $site->blog_id;
			$site_name = get_blog_option( $blog_id, 'blogname' ) ?: $site->domain . $site->path;
			$cur_role  = $current_roles[ $blog_id ] ?? '';
			$cur_label = $show_roles
				? ( $cur_role && isset( $all_roles[ $cur_role ] )
					? translate_user_role( $all_roles[ $cur_role ]['name'] )
					: __( '(none)', 'wsmum' ) )
				: '';
		?>
			<tr>
				<td>
					<input type="checkbox" name="wsmum_sites[]" value="<?php echo esc_attr( $blog_id ); ?>"
						class="<?php echo esc_attr( $cb_class ); ?>"
						<?php if ( $show_roles ) checked( (bool) $cur_role ); ?>>
				</td>
				<td>
					<strong><?php echo esc_html( $site_name ); ?></strong><br>
					<span class="description"><?php echo esc_html( $site->domain . $site->path ); ?></span>
				</td>
				<?php if ( $show_roles ) : ?>
				<td><?php echo esc_html( $cur_label ); ?></td>
				<?php endif; ?>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}
