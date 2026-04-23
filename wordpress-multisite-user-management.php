<?php
/**
 * Plugin Name: WordPress Multisite User Management
 * Plugin URI:  https://github.com/gruchet/wordpress-multisite-user-management
 * Description: Assign a role across a selection of sites for any network user from the Network Admin dashboard.
 * Version:     1.0.0
 * Network:     true
 * Author:      gruchet
 * License:     GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// AJAX user search (used by the autocomplete on the admin page)
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

add_action( 'network_admin_notices', 'wsmum_admin_notice' );
function wsmum_admin_notice() {
	if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'wsmum-user-site-roles' ) {
		return;
	}
	if ( isset( $_GET['wsmum_updated'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>'
			. esc_html__( 'User site roles updated.', 'wsmum' )
			. '</p></div>';
	}
	if ( isset( $_GET['wsmum_error'] ) ) {
		echo '<div class="notice notice-error is-dismissible"><p>'
			. esc_html__( 'Invalid request. Please try again.', 'wsmum' )
			. '</p></div>';
	}
}

/**
 * Handle the form submission.
 */
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
	if ( ! $target_user || empty( $role ) ) {
		wp_safe_redirect( add_query_arg( 'wsmum_error', 1, wsmum_page_url() ) );
		exit;
	}

	// Validate the role against wp_roles — roles are network-wide in WP multisite.
	$valid_roles = array_keys( wp_roles()->roles );
	if ( ! in_array( $role, $valid_roles, true ) ) {
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

	wp_safe_redirect( add_query_arg( 'wsmum_updated', 1, wsmum_page_url( $user_id ) ) );
	exit;
}

function wsmum_page_url( $user_id = 0 ) {
	$args = [ 'page' => 'wsmum-user-site-roles' ];
	if ( $user_id ) {
		$args['wsmum_uid'] = $user_id;
	}
	return network_admin_url( 'admin.php?' . http_build_query( $args ) );
}

/**
 * Render the admin page.
 */
function wsmum_render_page() {
	if ( ! current_user_can( 'manage_network_users' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'wsmum' ) );
	}

	// If a user is pre-selected via query var (e.g. after redirect), honour it.
	$selected_user_id = isset( $_GET['wsmum_uid'] ) ? absint( $_GET['wsmum_uid'] ) : 0;

	$all_sites = get_sites( [ 'number' => 0 ] );
	$all_roles = wp_roles()->roles;

	// Determine currently assigned role per site for the selected user.
	$current_roles = [];
	if ( $selected_user_id ) {
		foreach ( $all_sites as $site ) {
			if ( is_user_member_of_blog( $selected_user_id, $site->blog_id ) ) {
				$u = new WP_User( $selected_user_id, '', $site->blog_id );
				$current_roles[ $site->blog_id ] = reset( $u->roles ) ?: '';
			}
		}
	}

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'User Site Roles', 'wsmum' ); ?></h1>

		<!-- Step 1 – pick a user via autocomplete -->
		<form method="get" id="wsmum-user-form" action="<?php echo esc_url( network_admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="wsmum-user-site-roles">
			<input type="hidden" name="wsmum_uid" id="wsmum_uid" value="<?php echo esc_attr( $selected_user_id ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wsmum_user_search"><?php esc_html_e( 'Search user', 'wsmum' ); ?></label></th>
					<td>
						<div style="position:relative;display:inline-block;">
							<input type="text" id="wsmum_user_search" autocomplete="off"
								class="regular-text"
								placeholder="<?php esc_attr_e( 'Type a name, login or email…', 'wsmum' ); ?>"
								value="<?php
									if ( $selected_user_id ) {
										$u = get_userdata( $selected_user_id );
										echo $u ? esc_attr( $u->display_name . ' (' . $u->user_login . ')' ) : '';
									}
								?>">
							<ul id="wsmum-suggestions" style="
								display:none;position:absolute;top:100%;left:0;
								z-index:9999;background:#fff;border:1px solid #c3c4c7;
								box-shadow:0 2px 6px rgba(0,0,0,.15);margin:0;padding:0;
								min-width:100%;list-style:none;max-height:240px;overflow-y:auto;
							"></ul>
						</div>
						<p class="description" style="margin-top:4px;">
							<?php esc_html_e( 'Select a suggestion to load the user.', 'wsmum' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</form>

		<style>
		#wsmum-suggestions li {
			padding: 6px 12px;
			cursor: pointer;
			white-space: nowrap;
		}
		#wsmum-suggestions li:hover,
		#wsmum-suggestions li.wsmum-active {
			background: #2271b1;
			color: #fff;
		}
		</style>

		<script>
		(function() {
			var searchInput = document.getElementById('wsmum_user_search');
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
					.then(function(data) {
						if (!data.success) return;
						renderList(data.data);
					});
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
		})();
		</script>

		<?php if ( $selected_user_id && get_userdata( $selected_user_id ) ) :
			$target = get_userdata( $selected_user_id );
		?>
		<hr>
		<h2><?php
			/* translators: %s: display name + login */
			printf(
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

			<table class="widefat striped" style="max-width:700px;margin-top:12px;">
				<thead>
					<tr>
						<th style="width:32px;"><input type="checkbox" id="wsmum_check_all" title="<?php esc_attr_e( 'Toggle all', 'wsmum' ); ?>"></th>
						<th><?php esc_html_e( 'Site', 'wsmum' ); ?></th>
						<th><?php esc_html_e( 'Current role', 'wsmum' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $all_sites as $site ) :
					$blog_id     = $site->blog_id;
					$site_name   = get_blog_option( $blog_id, 'blogname' ) ?: $site->domain . $site->path;
					$cur_role    = $current_roles[ $blog_id ] ?? '';
					$cur_label   = $cur_role && isset( $all_roles[ $cur_role ] )
						? translate_user_role( $all_roles[ $cur_role ]['name'] )
						: __( '(none)', 'wsmum' );
				?>
					<tr>
						<td><input type="checkbox" name="wsmum_sites[]" value="<?php echo esc_attr( $blog_id ); ?>"
							<?php checked( (bool) $cur_role ); ?> class="wsmum-site-cb"></td>
						<td>
							<strong><?php echo esc_html( $site_name ); ?></strong><br>
							<span class="description"><?php echo esc_html( $site->domain . $site->path ); ?></span>
						</td>
						<td><?php echo esc_html( $cur_label ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:16px;"><?php submit_button( __( 'Apply role to selected sites', 'wsmum' ), 'primary', '', false ); ?></p>
		</form>

		<script>
		(function() {
			var checkAll = document.getElementById('wsmum_check_all');
			var cbs = document.querySelectorAll('.wsmum-site-cb');
			if (!checkAll) return;
			checkAll.addEventListener('change', function() {
				cbs.forEach(function(cb) { cb.checked = checkAll.checked; });
			});
		})();
		</script>

		<?php endif; ?>
	</div>
	<?php
}
