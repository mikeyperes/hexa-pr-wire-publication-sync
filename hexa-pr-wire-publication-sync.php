<?php
/**
 * Plugin Name: Hexa PR Wire Publication Sync
 * Description: Secure async control plane for Hexa PR Wire publication imports powered by Echo RSS.
 * Version: 1.0.2
 * Author: Hexa Web Systems
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Hexa_PR_Wire_Publication_Sync {
	const OPTION_KEY      = 'hexa_pr_wire_publication_sync_settings';
	const JOB_INDEX_KEY   = 'hexa_pr_wire_publication_sync_job_index';
	const JOB_PREFIX      = 'hexa_pr_wire_publication_sync_job_';
	const CRON_HOOK       = 'hexa_pr_wire_publication_sync_execute_job';
	const REST_NAMESPACE  = 'hexa-pr-wire/v1';
	const MAX_JOB_LOGS    = 200;
	const JOB_RETENTION   = 604800;
	const RULE_FEED_INDEX = 0;
	const RULE_LAST_RUN   = 3;
	const RULE_POST_TYPE  = 6;
	const RULE_IDENTITY   = 37;

	public static function init() {
		self::maybe_bootstrap_settings();
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_execute_job' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_admin_actions' ) );
	}

	public static function activate() {
		$settings = self::get_settings();

		if ( empty( $settings['secret_token'] ) ) {
			$settings['secret_token'] = self::generate_secret();
		}

		if ( empty( $settings['allowed_host'] ) ) {
			$settings['allowed_host'] = 'hexaprwire.com';
		}

		update_option( self::OPTION_KEY, $settings, false );

		if ( false === get_option( self::JOB_INDEX_KEY, false ) ) {
			add_option( self::JOB_INDEX_KEY, array(), '', false );
		}
	}

	public static function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/jobs',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( __CLASS__, 'rest_create_job' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/jobs/(?P<job_id>[a-zA-Z0-9\-]+)/run',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( __CLASS__, 'rest_run_job' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/jobs/(?P<job_id>[a-zA-Z0-9\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_get_job' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/jobs/(?P<job_id>[a-zA-Z0-9\-]+)/stream',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_stream_job' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function register_admin_page() {
		add_options_page(
			'Hexa PR Sync',
			'Hexa PR Sync',
			'manage_options',
			'hexa-pr-wire-publication-sync',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function handle_admin_actions() {
		if ( empty( $_POST['hexa_pr_wire_publication_sync_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'hexa_pr_wire_publication_sync_settings' );

		$action   = sanitize_text_field( wp_unslash( $_POST['hexa_pr_wire_publication_sync_action'] ) );
		$settings = self::get_settings();

		if ( 'save_settings' === $action ) {
			$settings['allowed_host']    = sanitize_text_field( wp_unslash( $_POST['allowed_host'] ?? '' ) );
			$settings['default_rule_id'] = sanitize_text_field( wp_unslash( $_POST['default_rule_id'] ?? '' ) );
			update_option( self::OPTION_KEY, $settings, false );
		}

		if ( 'regenerate_token' === $action ) {
			$settings['secret_token'] = self::generate_secret();
			update_option( self::OPTION_KEY, $settings, false );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'hexa-pr-wire-publication-sync',
					'updated' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	public static function render_admin_page() {
		$settings       = self::get_settings();
		$detected_rules = self::discover_rules();
		$default_rule   = self::get_default_rule();
		$token          = $settings['secret_token'];
		$base_url       = trailingslashit( rest_url( self::REST_NAMESPACE . '/jobs' ) );
		$status_base    = trailingslashit( rest_url( self::REST_NAMESPACE . '/jobs/<job_id>' ) );
		$stream_base    = trailingslashit( rest_url( self::REST_NAMESPACE . '/jobs/<job_id>/stream' ) );
		$rule_id        = isset( $default_rule['id'] ) ? (string) $default_rule['id'] : '';
		$publication    = isset( $default_rule['publication_slug'] ) ? $default_rule['publication_slug'] : '';
		$feed_url       = isset( $default_rule['feed_url'] ) ? $default_rule['feed_url'] : '';
		$refetch_url    = add_query_arg(
			array(
				'token'  => $token,
				'action' => 'refetch',
			),
			rest_url( self::REST_NAMESPACE . '/jobs' )
		);
		$update_url     = add_query_arg(
			array(
				'token'       => $token,
				'action'      => 'update',
				'feed_action' => 'reprocess-all',
				'slugs'       => 'example-slug',
			),
			rest_url( self::REST_NAMESPACE . '/jobs' )
		);
		$delete_url     = add_query_arg(
			array(
				'token'  => $token,
				'action' => 'delete',
				'slugs'  => 'example-slug',
			),
			rest_url( self::REST_NAMESPACE . '/jobs' )
		);
		?>
		<div class="wrap">
			<h1>Hexa PR Wire Publication Sync</h1>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Settings updated.</p></div>
			<?php endif; ?>

			<p>This plugin wraps Echo RSS with a publication-safe control plane: secret trigger URLs, async jobs, JSON status, live streaming logs, and targeted delete/update helpers for imported Hexa PR Wire posts.</p>

			<h2>Detected Echo Rule</h2>
			<table class="widefat striped" style="max-width: 1200px;">
				<tbody>
					<tr><th style="width: 220px;">Default Rule ID</th><td><?php echo esc_html( $rule_id ?: 'Not detected' ); ?></td></tr>
					<tr><th>Detected Publication Slug</th><td><?php echo esc_html( $publication ?: 'Not detected' ); ?></td></tr>
					<tr><th>Detected Feed URL</th><td><code><?php echo esc_html( $feed_url ?: 'Not detected' ); ?></code></td></tr>
					<tr><th>Allowed Upstream Host</th><td><code><?php echo esc_html( $settings['allowed_host'] ); ?></code></td></tr>
					<tr><th>Echo Log File</th><td><code><?php echo esc_html( self::get_echo_log_path() ); ?></code></td></tr>
				</tbody>
			</table>

			<h2>Settings</h2>
			<form method="post" style="max-width: 900px;">
				<?php wp_nonce_field( 'hexa_pr_wire_publication_sync_settings' ); ?>
				<input type="hidden" name="hexa_pr_wire_publication_sync_action" value="save_settings" />
				<table class="form-table">
					<tr>
						<th scope="row"><label for="secret_token">Secret Token</label></th>
						<td>
							<input type="text" id="secret_token" class="regular-text code" readonly value="<?php echo esc_attr( $token ); ?>" />
							<p class="description">This token authorizes public trigger, status, and stream URLs.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="allowed_host">Allowed Upstream Host</label></th>
						<td>
							<input type="text" id="allowed_host" name="allowed_host" class="regular-text code" value="<?php echo esc_attr( $settings['allowed_host'] ); ?>" />
							<p class="description">Only feed URLs from this host are accepted by the public API.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="default_rule_id">Default Echo Rule ID Override</label></th>
						<td>
							<input type="text" id="default_rule_id" name="default_rule_id" class="small-text code" value="<?php echo esc_attr( $settings['default_rule_id'] ); ?>" />
							<p class="description">Leave blank to auto-detect the active Hexa PR Wire press-release rule.</p>
						</td>
					</tr>
				</table>
				<p>
					<button type="submit" class="button button-primary">Save Settings</button>
				</p>
			</form>

			<form method="post" style="margin-bottom: 32px;">
				<?php wp_nonce_field( 'hexa_pr_wire_publication_sync_settings' ); ?>
				<input type="hidden" name="hexa_pr_wire_publication_sync_action" value="regenerate_token" />
				<p><button type="submit" class="button">Regenerate Secret Token</button></p>
			</form>

			<h2>Discovered Rules</h2>
			<table class="widefat striped" style="max-width: 1200px;">
				<thead>
					<tr>
						<th>Rule ID</th>
						<th>Active</th>
						<th>Post Type</th>
						<th>Publication</th>
						<th>Feed URL</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $detected_rules ) ) : ?>
					<tr><td colspan="5">No matching Hexa PR Wire rules were detected.</td></tr>
				<?php else : ?>
					<?php foreach ( $detected_rules as $rule ) : ?>
						<tr>
							<td><?php echo esc_html( $rule['id'] ); ?></td>
							<td><?php echo ! empty( $rule['active'] ) ? 'Yes' : 'No'; ?></td>
							<td><?php echo esc_html( $rule['post_type'] ); ?></td>
							<td><?php echo esc_html( $rule['publication_slug'] ); ?></td>
							<td><code><?php echo esc_html( $rule['feed_url'] ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<h2>Public Control URLs</h2>
			<p>Channel 1 is the job trigger + JSON status API. Channel 2 is the live event stream for progress and Echo log output.</p>
			<table class="widefat striped" style="max-width: 1200px;">
				<tbody>
					<tr><th style="width: 220px;">Create Job Endpoint</th><td><code><?php echo esc_html( untrailingslashit( rest_url( self::REST_NAMESPACE . '/jobs' ) ) ); ?></code></td></tr>
					<tr><th>Status Endpoint Pattern</th><td><code><?php echo esc_html( untrailingslashit( $status_base ) ); ?></code></td></tr>
					<tr><th>Stream Endpoint Pattern</th><td><code><?php echo esc_html( untrailingslashit( $stream_base ) ); ?></code></td></tr>
					<tr><th>Immediate Refetch URL</th><td><code><?php echo esc_html( $refetch_url ); ?></code></td></tr>
					<tr><th>Targeted Update URL</th><td><code><?php echo esc_html( $update_url ); ?></code></td></tr>
					<tr><th>Targeted Delete URL</th><td><code><?php echo esc_html( $delete_url ); ?></code></td></tr>
				</tbody>
			</table>

			<h2>How It Works</h2>
			<ol>
				<li>The trigger endpoint creates a job, returns JSON immediately, and dispatches a non-blocking loopback request to execute it.</li>
				<li>The execution layer wraps Echo RSS rule execution instead of replacing it. It temporarily overrides the rule feed URL when needed, runs Echo, then restores the original feed URL.</li>
				<li>The status endpoint returns structured JSON with job state, discovered feed items, new URLs, updated URLs, delete results, missing targets, and error details.</li>
				<li>The stream endpoint emits server-sent events from both this plugin and the live Echo log file so a browser or external tool can watch progress while the job runs.</li>
			</ol>

			<h2>Payload Conventions</h2>
			<ul>
				<li><code>action=refetch</code> runs the default Hexa PR Wire feed for this publication and imports new posts immediately.</li>
				<li><code>action=update</code> runs Echo with <code>update_existing=1</code> already preserved from the existing rule. By default, targeted updates use <code>feed_action=reprocess-all</code> so older posts can be refreshed too.</li>
				<li><code>action=delete</code> accepts local post IDs, original slugs, or original source URLs and removes matching imported posts from this publication.</li>
				<li><code>post_ids</code> are local publication post IDs. <code>slugs</code> refer to upstream/original Hexa PR Wire slugs.</li>
				<li><code>dry_run=1</code> performs the audit path without mutating posts. Use this to preview rollout behavior on other publications.</li>
			</ul>

			<h2>Example JSON Calls</h2>
			<textarea readonly rows="14" style="width: 100%; max-width: 1200px;" class="large-text code"><?php echo esc_textarea( wp_json_encode( array(
				'refetch' => array(
					'url'         => untrailingslashit( rest_url( self::REST_NAMESPACE . '/jobs' ) ),
					'query'       => array(
						'token'  => $token,
						'action' => 'refetch',
					),
				),
				'update'  => array(
					'url'         => untrailingslashit( rest_url( self::REST_NAMESPACE . '/jobs' ) ),
					'query'       => array(
						'token'       => $token,
						'action'      => 'update',
						'feed_action' => 'reprocess-all',
						'slugs'       => array( 'example-slug-1', 'example-slug-2' ),
					),
				),
				'delete'  => array(
					'url'         => untrailingslashit( rest_url( self::REST_NAMESPACE . '/jobs' ) ),
					'query'       => array(
						'token'       => $token,
						'action'      => 'delete',
						'source_urls' => array( 'https://hexaprwire.com/example-slug/' ),
						'hard_delete' => false,
					),
				),
			), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></textarea>

			<h2>Live Tester</h2>
			<p>Use this to test the publication-side API directly from wp-admin before rolling it to the other outlets.</p>
			<div style="max-width: 1200px; display: grid; gap: 16px;">
				<div style="background: #fff; border: 1px solid #ccd0d4; padding: 16px;">
					<table class="form-table">
						<tr>
							<th scope="row"><label for="hpr-action">Action</label></th>
							<td>
								<select id="hpr-action">
									<option value="refetch">Refetch</option>
									<option value="update">Update</option>
									<option value="delete">Delete</option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="hpr-feed-action">Feed Action</label></th>
							<td>
								<select id="hpr-feed-action">
									<option value="">Default</option>
									<option value="reprocess-all">reprocess-all</option>
									<option value="reprocess-old">reprocess-old</option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="hpr-feed-url">Feed URL Override</label></th>
							<td><input type="text" id="hpr-feed-url" class="regular-text code" value="<?php echo esc_attr( $feed_url ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="hpr-slugs">Slugs</label></th>
							<td><textarea id="hpr-slugs" rows="4" class="large-text code" placeholder="one slug per line"></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="hpr-source-urls">Source URLs</label></th>
							<td><textarea id="hpr-source-urls" rows="4" class="large-text code" placeholder="one URL per line"></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="hpr-post-ids">Local Post IDs</label></th>
							<td><textarea id="hpr-post-ids" rows="3" class="large-text code" placeholder="one ID per line"></textarea></td>
						</tr>
						<tr>
							<th scope="row">Flags</th>
							<td>
								<label><input type="checkbox" id="hpr-dry-run" checked /> Dry run</label><br />
								<label><input type="checkbox" id="hpr-hard-delete" /> Hard delete</label>
							</td>
						</tr>
					</table>
					<p>
						<button type="button" class="button button-primary" id="hpr-start-job">Start Job</button>
					</p>
				</div>

				<div style="background: #fff; border: 1px solid #ccd0d4; padding: 16px;">
					<h3 style="margin-top: 0;">Job Response</h3>
					<pre id="hpr-job-response" style="white-space: pre-wrap; max-height: 240px; overflow: auto;"></pre>
				</div>

				<div style="background: #111827; color: #e5e7eb; border: 1px solid #1f2937; padding: 16px;">
					<h3 style="margin-top: 0; color: #fff;">Live Stream</h3>
					<pre id="hpr-job-stream" style="white-space: pre-wrap; max-height: 420px; overflow: auto; color: #d1fae5;"></pre>
				</div>
			</div>
		</div>
		<script>
		(function () {
			const token = <?php echo wp_json_encode( $token ); ?>;
			const createUrl = <?php echo wp_json_encode( untrailingslashit( rest_url( self::REST_NAMESPACE . '/jobs' ) ) ); ?>;
			const responseBox = document.getElementById('hpr-job-response');
			const streamBox = document.getElementById('hpr-job-stream');
			const startButton = document.getElementById('hpr-start-job');
			let source = null;

			function linesToArray(value) {
				return value
					.split(/\r?\n/)
					.map((line) => line.trim())
					.filter(Boolean);
			}

			function appendStream(line) {
				streamBox.textContent += line + "\n";
				streamBox.scrollTop = streamBox.scrollHeight;
			}

			function renderJson(target, payload) {
				target.textContent = JSON.stringify(payload, null, 2);
			}

			startButton.addEventListener('click', async function () {
				if (source) {
					source.close();
					source = null;
				}

				streamBox.textContent = '';
				responseBox.textContent = 'Starting job...';

				const params = new URLSearchParams();
				params.set('token', token);
				params.set('action', document.getElementById('hpr-action').value);

				const feedAction = document.getElementById('hpr-feed-action').value;
				const feedUrl = document.getElementById('hpr-feed-url').value.trim();
				const slugs = linesToArray(document.getElementById('hpr-slugs').value);
				const sourceUrls = linesToArray(document.getElementById('hpr-source-urls').value);
				const postIds = linesToArray(document.getElementById('hpr-post-ids').value);

				if (feedAction) {
					params.set('feed_action', feedAction);
				}

				if (feedUrl) {
					params.set('feed_url', feedUrl);
				}

				if (slugs.length) {
					params.set('slugs', slugs.join('\n'));
				}

				if (sourceUrls.length) {
					params.set('source_urls', sourceUrls.join('\n'));
				}

				if (postIds.length) {
					params.set('post_ids', postIds.join('\n'));
				}

				if (document.getElementById('hpr-dry-run').checked) {
					params.set('dry_run', '1');
				}

				if (document.getElementById('hpr-hard-delete').checked) {
					params.set('hard_delete', '1');
				}

				try {
					const response = await fetch(createUrl + '?' + params.toString(), {
						credentials: 'same-origin',
					});
					const payload = await response.json();
					renderJson(responseBox, payload);

					if (!payload.stream_url) {
						appendStream('No stream URL returned.');
						return;
					}

					source = new EventSource(payload.stream_url, { withCredentials: true });
					source.addEventListener('log', function (event) {
						appendStream('[LOG] ' + event.data);
					});
					source.addEventListener('echo-log', function (event) {
						appendStream('[ECHO] ' + event.data);
					});
					source.addEventListener('status', function (event) {
						appendStream('[STATUS] ' + event.data);
					});
					source.addEventListener('summary', async function () {
						appendStream('[DONE] Summary received.');
						source.close();
						source = null;
						if (payload.status_url) {
							const statusResponse = await fetch(payload.status_url, { credentials: 'same-origin' });
							const statusPayload = await statusResponse.json();
							renderJson(responseBox, statusPayload);
						}
					});
					source.onerror = function () {
						appendStream('[STREAM] Connection closed or interrupted.');
					};
				} catch (error) {
					renderJson(responseBox, {
						error: true,
						message: error.message,
					});
				}
			});
		}());
		</script>
		<?php
	}

	public static function rest_create_job( WP_REST_Request $request ) {
		if ( ! self::authorize_request( $request ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Unauthorized.',
				),
				403
			);
		}

		self::cleanup_old_jobs();

		$params = self::normalize_request_params( $request );
		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$rule = self::get_rule_by_request( $params );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		$job_id        = wp_generate_uuid4();
		$echo_log_path = self::get_echo_log_path();
		$job           = array(
			'id'               => $job_id,
			'state'            => 'queued',
			'action'           => $params['action'],
			'feed_action'      => $params['feed_action'],
			'dry_run'          => $params['dry_run'],
			'hard_delete'      => $params['hard_delete'],
			'created_at'       => gmdate( 'c' ),
			'started_at'       => '',
			'finished_at'      => '',
			'rule_id'          => $rule['id'],
			'rule'             => $rule,
			'params'           => $params,
			'logs'             => array(),
			'errors'           => array(),
			'result'           => array(),
			'echo_log_path'    => $echo_log_path,
			'echo_log_start'   => file_exists( $echo_log_path ) ? (int) filesize( $echo_log_path ) : 0,
			'status_message'   => 'Job queued.',
			'stream_heartbeat' => 0,
		);

		self::append_job_log( $job, 'Job created.' );
		self::append_job_log( $job, 'Resolved Echo rule #' . $rule['id'] . ' using feed ' . $rule['feed_url'] . '.' );

		self::save_job( $job );
		self::index_job( $job_id );

		wp_schedule_single_event( time() + 1, self::CRON_HOOK, array( $job_id ) );
		self::dispatch_job_loopback( $job_id );

		return new WP_REST_Response(
			array(
				'success'    => true,
				'accepted'   => true,
				'job_id'     => $job_id,
				'state'      => 'queued',
				'status_url' => self::build_status_url( $job_id, true ),
				'stream_url' => self::build_stream_url( $job_id, true ),
				'run_url'    => self::build_run_url( $job_id, true ),
				'rule'       => $rule,
				'message'    => 'Job queued and dispatched.',
			),
			202
		);
	}

	public static function rest_run_job( WP_REST_Request $request ) {
		if ( ! self::authorize_request( $request ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Unauthorized.',
				),
				403
			);
		}

		$job_id = sanitize_text_field( $request['job_id'] );
		$job    = self::get_job( $job_id );

		if ( empty( $job ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Job not found.',
				),
				404
			);
		}

		self::execute_job( $job_id );

		return new WP_REST_Response( self::prepare_job_response( self::get_job( $job_id ) ) );
	}

	public static function rest_get_job( WP_REST_Request $request ) {
		if ( ! self::authorize_request( $request ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Unauthorized.',
				),
				403
			);
		}

		$job = self::get_job( sanitize_text_field( $request['job_id'] ) );
		if ( empty( $job ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Job not found.',
				),
				404
			);
		}

		return new WP_REST_Response( self::prepare_job_response( $job ) );
	}

	public static function rest_stream_job( WP_REST_Request $request ) {
		if ( ! self::authorize_request( $request ) ) {
			status_header( 403 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Unauthorized.';
			exit;
		}

		$job_id = sanitize_text_field( $request['job_id'] );
		$job    = self::get_job( $job_id );

		if ( empty( $job ) ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Job not found.';
			exit;
		}

		ignore_user_abort( true );
		@set_time_limit( 0 );
		@ini_set( 'zlib.output_compression', '0' );
		@ini_set( 'output_buffering', 'off' );

		while ( ob_get_level() > 0 ) {
			@ob_end_flush();
		}

		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'X-Accel-Buffering: no' );

		$start_time      = time();
		$sent_job_logs   = 0;
		$echo_log_offset = isset( $job['echo_log_start'] ) ? (int) $job['echo_log_start'] : 0;

		self::emit_sse( 'status', 'Stream attached to job ' . $job_id . '.' );

		do {
			$job = self::get_job( $job_id );
			if ( empty( $job ) ) {
				self::emit_sse( 'summary', 'Job disappeared.' );
				break;
			}

			$logs = isset( $job['logs'] ) && is_array( $job['logs'] ) ? $job['logs'] : array();
			for ( $i = $sent_job_logs; $i < count( $logs ); $i++ ) {
				$entry = $logs[ $i ];
				self::emit_sse( 'log', '[' . $entry['time'] . '] ' . $entry['message'] );
			}
			$sent_job_logs = count( $logs );

			$echo_lines = self::read_echo_log_lines( $job, $echo_log_offset );
			foreach ( $echo_lines['lines'] as $line ) {
				self::emit_sse( 'echo-log', $line );
			}
			$echo_log_offset = $echo_lines['offset'];

			self::emit_sse( 'status', $job['state'] . ': ' . $job['status_message'] );

			if ( in_array( $job['state'], array( 'completed', 'failed' ), true ) ) {
				self::emit_sse( 'summary', wp_json_encode( self::prepare_job_response( $job ) ) );
				break;
			}

			echo ": heartbeat\n\n";
			@flush();
			sleep( 1 );
		} while ( ( time() - $start_time ) < 120 );

		exit;
	}

	public static function cron_execute_job( $job_id ) {
		self::execute_job( $job_id );
	}

	private static function execute_job( $job_id ) {
		$job = self::get_job( $job_id );
		if ( empty( $job ) ) {
			return;
		}

		if ( in_array( $job['state'], array( 'running', 'completed', 'failed' ), true ) ) {
			return;
		}

		$job['state']          = 'running';
		$job['started_at']     = gmdate( 'c' );
		$job['status_message'] = 'Job is running.';
		self::append_job_log( $job, 'Execution started.' );
		self::save_job( $job );

		try {
			switch ( $job['action'] ) {
				case 'delete':
					$job = self::execute_delete_job( $job );
					break;
				case 'update':
				case 'refetch':
				default:
					$job = self::execute_sync_job( $job );
					break;
			}

			if ( 'failed' !== $job['state'] ) {
				$job['state']          = 'completed';
				$job['finished_at']    = gmdate( 'c' );
				$job['status_message'] = 'Job completed successfully.';
				self::append_job_log( $job, 'Execution finished successfully.' );
			}
		} catch ( Throwable $throwable ) {
			$job['state']          = 'failed';
			$job['finished_at']    = gmdate( 'c' );
			$job['status_message'] = 'Job failed.';
			$job['errors'][]       = array(
				'time'    => gmdate( 'c' ),
				'message' => $throwable->getMessage(),
			);
			self::append_job_log( $job, 'Execution failed: ' . $throwable->getMessage(), 'error' );
			$job['result']['echo_log_tail'] = self::get_echo_log_tail( $job );
		}

		self::save_job( $job );
	}

	private static function execute_sync_job( array $job ) {
		$rule = $job['rule'];

		self::append_job_log( $job, 'Loading imported post snapshot for rule #' . $rule['id'] . '.' );
		$before_map                    = self::get_imported_post_map( $rule['id'] );
		$job['result']['before_count'] = count( $before_map['source_urls'] );
		self::save_job( $job );

		$effective_feed_url = self::build_effective_feed_url( $rule['feed_url'], $job );
		$job['result']['original_rule_feed_url'] = $rule['feed_url'];
		$job['result']['effective_feed_url']     = $effective_feed_url;
		self::append_job_log( $job, 'Effective feed URL: ' . $effective_feed_url );

		$feed_items = self::fetch_feed_items( $effective_feed_url );
		$job['result']['feed_items_discovered'] = count( $feed_items );
		$job['result']['feed_source_urls']      = wp_list_pluck( $feed_items, 'source_url' );
		$job['result']['last_url_processed']    = ! empty( $feed_items ) ? end( $feed_items )['source_url'] : '';
		self::append_job_log( $job, 'Feed fetched successfully with ' . count( $feed_items ) . ' items.' );

		$targets                           = self::resolve_targets( $job, $before_map );
		$job['result']['targets']          = $targets;
		$job['result']['target_feed_hits'] = self::filter_feed_items_by_targets( $feed_items, $targets );
		self::save_job( $job );

		if ( ! empty( $job['dry_run'] ) ) {
			$job['result'] = array_merge( $job['result'], self::build_dry_run_sync_result( $before_map, $feed_items, $targets ) );
			$job['status_message'] = 'Dry run complete.';
			self::append_job_log( $job, 'Dry run complete. No posts were modified.' );
			return $job;
		}

		self::append_job_log( $job, 'Running Echo rule #' . $rule['id'] . '.' );
		self::run_echo_rule_with_feed_override( $job, $effective_feed_url );
		self::append_job_log( $job, 'Echo rule execution finished. Rebuilding imported post snapshot.' );

		$after_map                    = self::get_imported_post_map( $rule['id'] );
		$job['result']['after_count'] = count( $after_map['source_urls'] );
		$job['result']                = array_merge(
			$job['result'],
			self::build_sync_result( $before_map, $after_map, $feed_items, $targets )
		);
		$job['result']['echo_log_tail'] = self::get_echo_log_tail( $job );
		$job['status_message']          = 'Sync finished.';

		self::append_job_log(
			$job,
			sprintf(
				'Sync summary: %d new, %d updated, %d unchanged.',
				count( $job['result']['new_source_urls'] ),
				count( $job['result']['updated_source_urls'] ),
				count( $job['result']['unchanged_source_urls'] )
			)
		);

		return $job;
	}

	private static function execute_delete_job( array $job ) {
		$rule      = $job['rule'];
		$post_map  = self::get_imported_post_map( $rule['id'] );
		$targets   = self::resolve_targets( $job, $post_map );
		$matches   = self::filter_post_map_by_targets( $post_map, $targets );
		$deleted   = array();
		$failed    = array();
		$job['result']['targets'] = $targets;

		if ( empty( $matches ) ) {
			$job['result']['matched_posts'] = array();
			$job['status_message']          = 'No matching imported posts were found.';
			self::append_job_log( $job, 'No matching imported posts were found for delete request.' );
			return $job;
		}

		$job['result']['matched_posts'] = array_values( $matches );
		self::append_job_log( $job, 'Matched ' . count( $matches ) . ' imported posts for delete processing.' );

		foreach ( $matches as $match ) {
			$job['result']['last_url_processed'] = $match['source_url'];
			if ( ! empty( $job['dry_run'] ) ) {
				self::append_job_log( $job, 'Dry run delete hit: post #' . $match['post_id'] . ' (' . $match['source_url'] . ')' );
				$deleted[] = $match;
				continue;
			}

			$result = ! empty( $job['hard_delete'] ) ? wp_delete_post( $match['post_id'], true ) : wp_trash_post( $match['post_id'] );
			if ( $result ) {
				self::append_job_log( $job, 'Deleted post #' . $match['post_id'] . ' for source ' . $match['source_url'] . '.' );
				$deleted[] = $match;
			} else {
				self::append_job_log( $job, 'Failed deleting post #' . $match['post_id'] . ' for source ' . $match['source_url'] . '.', 'error' );
				$failed[] = $match;
			}
		}

		$job['result']['deleted_count']     = count( $deleted );
		$job['result']['failed_delete_cnt'] = count( $failed );
		$job['result']['deleted_source_urls'] = array_values(
			array_map(
				static function ( $row ) {
					return $row['source_url'];
				},
				$deleted
			)
		);
		$job['result']['deleted_live_urls'] = array_values(
			array_map(
				static function ( $row ) {
					return $row['live_url'];
				},
				$deleted
			)
		);
		$job['result']['failed_deletes'] = array_values( $failed );
		$job['status_message']           = ! empty( $job['dry_run'] ) ? 'Delete dry run complete.' : 'Delete processing complete.';

		return $job;
	}

	private static function run_echo_rule_with_feed_override( array &$job, $effective_feed_url ) {
		if ( ! function_exists( 'echo_run_rule' ) ) {
			throw new RuntimeException( 'Echo RSS function echo_run_rule() is not available.' );
		}

		$rule_id     = (int) $job['rule']['id'];
		$rules       = get_option( 'echo_rules_list', array() );
		$original    = isset( $rules[ $rule_id ][ self::RULE_FEED_INDEX ] ) ? $rules[ $rule_id ][ self::RULE_FEED_INDEX ] : '';

		if ( '' === $original ) {
			throw new RuntimeException( 'Original Echo rule feed URL could not be read.' );
		}

		$restore = static function () use ( $rule_id, $original ) {
			$rules = get_option( 'echo_rules_list', array() );
			if ( isset( $rules[ $rule_id ] ) ) {
				$rules[ $rule_id ][ self::RULE_FEED_INDEX ] = $original;
				update_option( 'echo_rules_list', $rules, false );
				wp_cache_delete( 'echo_rules_list', 'options' );
			}
		};

		register_shutdown_function( $restore );

		$rules[ $rule_id ][ self::RULE_FEED_INDEX ] = $effective_feed_url;
		update_option( 'echo_rules_list', $rules, false );
		wp_cache_delete( 'echo_rules_list', 'options' );

		try {
			$result = echo_run_rule( $rule_id, 0 );
		} finally {
			$restore();
		}

		if ( 'fail' === $result ) {
			throw new RuntimeException( 'Echo RSS returned fail for rule #' . $rule_id . '.' );
		}
	}

	private static function normalize_request_params( WP_REST_Request $request ) {
		$params = array_merge(
			(array) $request->get_params(),
			(array) $request->get_json_params()
		);

		$action = sanitize_text_field( (string) ( $params['action'] ?? 'refetch' ) );
		if ( ! in_array( $action, array( 'refetch', 'update', 'delete' ), true ) ) {
			return new WP_Error( 'invalid_action', 'Invalid action requested.', array( 'status' => 400 ) );
		}

		$feed_action = sanitize_text_field( (string) ( $params['feed_action'] ?? '' ) );
		if ( $feed_action && ! in_array( $feed_action, array( 'reprocess-all', 'reprocess-old' ), true ) ) {
			return new WP_Error( 'invalid_feed_action', 'Invalid feed_action requested.', array( 'status' => 400 ) );
		}

		$slugs       = self::normalize_list_param( $params['slugs'] ?? array() );
		$source_urls = self::normalize_list_param( $params['source_urls'] ?? array() );
		$post_ids    = array_map( 'intval', self::normalize_list_param( $params['post_ids'] ?? array() ) );

		if ( 'update' === $action && empty( $feed_action ) && ( ! empty( $slugs ) || ! empty( $source_urls ) || ! empty( array_filter( $post_ids ) ) ) ) {
			$feed_action = 'reprocess-all';
		}

		return array(
			'action'       => $action,
			'feed_action'  => $feed_action,
			'feed_url'     => esc_url_raw( (string) ( $params['feed_url'] ?? '' ) ),
			'rule_id'      => sanitize_text_field( (string) ( $params['rule_id'] ?? '' ) ),
			'slugs'        => $slugs,
			'source_urls'  => $source_urls,
			'post_ids'     => array_values( array_filter( $post_ids ) ),
			'dry_run'      => self::to_bool( $params['dry_run'] ?? false ),
			'hard_delete'  => self::to_bool( $params['hard_delete'] ?? false ),
		);
	}

	private static function resolve_targets( array $job, array $post_map ) {
		$params              = $job['params'];
		$target_slugs        = $params['slugs'];
		$target_source_urls  = array_map( array( __CLASS__, 'normalize_url' ), $params['source_urls'] );
		$target_local_ids    = array_values( array_filter( array_map( 'intval', $params['post_ids'] ) ) );
		$resolved_local_ids  = array();

		foreach ( $target_local_ids as $post_id ) {
			if ( empty( $post_map[ 'post_ids' ][ $post_id ] ) ) {
				continue;
			}

			$resolved = $post_map['post_ids'][ $post_id ];
			$resolved_local_ids[] = $post_id;

			if ( ! empty( $resolved['source_slug'] ) ) {
				$target_slugs[] = $resolved['source_slug'];
			}

			if ( ! empty( $resolved['source_url'] ) ) {
				$target_source_urls[] = $resolved['source_url'];
			}
		}

		return array(
			'source_slugs'    => array_values( array_unique( array_filter( $target_slugs ) ) ),
			'source_urls'     => array_values( array_unique( array_filter( $target_source_urls ) ) ),
			'local_post_ids'  => array_values( array_unique( array_filter( $resolved_local_ids ) ) ),
			'request_post_ids'=> $target_local_ids,
		);
	}

	private static function filter_feed_items_by_targets( array $feed_items, array $targets ) {
		if ( empty( $targets['source_slugs'] ) && empty( $targets['source_urls'] ) ) {
			return array();
		}

		$matched = array();
		foreach ( $feed_items as $item ) {
			if (
				( ! empty( $targets['source_urls'] ) && in_array( $item['source_url'], $targets['source_urls'], true ) ) ||
				( ! empty( $targets['source_slugs'] ) && in_array( $item['source_slug'], $targets['source_slugs'], true ) )
			) {
				$matched[] = $item;
			}
		}

		return $matched;
	}

	private static function filter_post_map_by_targets( array $post_map, array $targets ) {
		$matches = array();

		foreach ( $post_map['source_urls'] as $source_url => $row ) {
			if (
				( ! empty( $targets['source_urls'] ) && in_array( $source_url, $targets['source_urls'], true ) ) ||
				( ! empty( $targets['source_slugs'] ) && in_array( $row['source_slug'], $targets['source_slugs'], true ) ) ||
				( ! empty( $targets['local_post_ids'] ) && in_array( $row['post_id'], $targets['local_post_ids'], true ) )
			) {
				$matches[] = $row;
			}
		}

		return $matches;
	}

	private static function build_sync_result( array $before_map, array $after_map, array $feed_items, array $targets ) {
		$new_source_urls      = array();
		$new_live_urls        = array();
		$updated_source_urls  = array();
		$updated_live_urls    = array();
		$unchanged_source_urls = array();

		foreach ( $after_map['source_urls'] as $source_url => $after_row ) {
			if ( empty( $before_map['source_urls'][ $source_url ] ) ) {
				$new_source_urls[] = $source_url;
				$new_live_urls[]   = $after_row['live_url'];
				continue;
			}

			$before_row = $before_map['source_urls'][ $source_url ];
			if ( $before_row['content_hash'] !== $after_row['content_hash'] || $before_row['modified_gmt'] !== $after_row['modified_gmt'] ) {
				$updated_source_urls[] = $source_url;
				$updated_live_urls[]   = $after_row['live_url'];
			} else {
				$unchanged_source_urls[] = $source_url;
			}
		}

		$feed_source_urls = wp_list_pluck( $feed_items, 'source_url' );
		$missing_targets  = array();

		foreach ( $targets['source_urls'] as $source_url ) {
			if ( ! in_array( $source_url, $feed_source_urls, true ) && empty( $after_map['source_urls'][ $source_url ] ) ) {
				$missing_targets[] = $source_url;
			}
		}

		foreach ( $targets['source_slugs'] as $slug ) {
			$found = false;
			foreach ( $feed_items as $item ) {
				if ( $item['source_slug'] === $slug ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$missing_targets[] = $slug;
			}
		}

		return array(
			'new_source_urls'       => array_values( array_unique( $new_source_urls ) ),
			'new_live_urls'         => array_values( array_unique( $new_live_urls ) ),
			'updated_source_urls'   => array_values( array_unique( $updated_source_urls ) ),
			'updated_live_urls'     => array_values( array_unique( $updated_live_urls ) ),
			'unchanged_source_urls' => array_values( array_unique( $unchanged_source_urls ) ),
			'missing_targets'       => array_values( array_unique( array_filter( $missing_targets ) ) ),
			'feed_urls_discovered'  => $feed_source_urls,
			'up_to_date'            => empty( $new_source_urls ) && empty( $updated_source_urls ),
		);
	}

	private static function build_dry_run_sync_result( array $before_map, array $feed_items, array $targets ) {
		$new_source_urls = array();
		$missing_targets = array();
		$feed_source_urls = wp_list_pluck( $feed_items, 'source_url' );

		foreach ( $feed_items as $item ) {
			if ( empty( $before_map['source_urls'][ $item['source_url'] ] ) ) {
				$new_source_urls[] = $item['source_url'];
			}
		}

		foreach ( $targets['source_urls'] as $source_url ) {
			if ( ! in_array( $source_url, $feed_source_urls, true ) && empty( $before_map['source_urls'][ $source_url ] ) ) {
				$missing_targets[] = $source_url;
			}
		}

		foreach ( $targets['source_slugs'] as $slug ) {
			$found = false;
			foreach ( $feed_items as $item ) {
				if ( $item['source_slug'] === $slug ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$missing_targets[] = $slug;
			}
		}

		return array(
			'new_source_urls'       => array_values( array_unique( $new_source_urls ) ),
			'new_live_urls'         => array(),
			'updated_source_urls'   => array(),
			'updated_live_urls'     => array(),
			'unchanged_source_urls' => array_values( array_unique( array_intersect( $feed_source_urls, array_keys( $before_map['source_urls'] ) ) ) ),
			'missing_targets'       => array_values( array_unique( array_filter( $missing_targets ) ) ),
			'feed_urls_discovered'  => $feed_source_urls,
			'up_to_date'            => empty( $new_source_urls ),
		);
	}

	private static function fetch_feed_items( $feed_url ) {
		$response = wp_remote_get(
			$feed_url,
			array(
				'timeout'    => 60,
				'redirection'=> 5,
				'headers'    => array(
					'Accept' => 'application/rss+xml, application/xml, text/xml;q=0.9',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'Feed request failed: ' . $response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			throw new RuntimeException( 'Feed request returned HTTP ' . $status_code . '.' );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === trim( $body ) ) {
			throw new RuntimeException( 'Feed response was empty.' );
		}

		if ( ! function_exists( 'simplexml_load_string' ) ) {
			throw new RuntimeException( 'SimpleXML is not available on this server.' );
		}

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOCDATA );
		if ( false === $xml ) {
			$messages = array();
			foreach ( libxml_get_errors() as $error ) {
				$messages[] = trim( $error->message );
			}
			libxml_clear_errors();
			throw new RuntimeException( 'Feed XML could not be parsed: ' . implode( '; ', $messages ) );
		}

		$items = array();
		if ( empty( $xml->channel->item ) ) {
			return $items;
		}

		foreach ( $xml->channel->item as $item ) {
			$source_url = self::normalize_url( (string) $item->post_url );
			if ( empty( $source_url ) ) {
				$source_url = self::normalize_url( (string) $item->link );
			}

			$source_slug = sanitize_title( (string) $item->post_slug );
			if ( empty( $source_slug ) && ! empty( $source_url ) ) {
				$source_slug = sanitize_title( basename( wp_parse_url( $source_url, PHP_URL_PATH ) ) );
			}

			$items[] = array(
				'title'       => wp_strip_all_tags( (string) $item->title ),
				'source_url'  => $source_url,
				'source_slug' => $source_slug,
				'pub_date'    => (string) $item->pubDate,
			);
		}

		return $items;
	}

	private static function get_imported_post_map( $rule_id ) {
		$post_ids = get_posts(
			array(
				'post_type'              => 'press-release',
				'post_status'            => array( 'publish', 'draft', 'pending', 'trash' ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'   => 'echo_parent_rule',
						'value' => (string) $rule_id,
					),
				),
			)
		);

		$map = array(
			'source_urls' => array(),
			'post_ids'    => array(),
		);

		foreach ( $post_ids as $post_id ) {
			$source_url = self::normalize_url( (string) get_post_meta( $post_id, 'original_post_url', true ) );
			if ( empty( $source_url ) ) {
				$source_url = self::normalize_url( (string) get_post_meta( $post_id, 'echo_post_full_url', true ) );
			}

			$source_slug = sanitize_title( (string) get_post_meta( $post_id, 'original_post_slug', true ) );
			if ( empty( $source_slug ) && ! empty( $source_url ) ) {
				$source_slug = sanitize_title( basename( wp_parse_url( $source_url, PHP_URL_PATH ) ) );
			}

			$row = array(
				'post_id'       => (int) $post_id,
				'post_title'    => get_the_title( $post_id ),
				'live_url'      => get_permalink( $post_id ),
				'source_url'    => $source_url,
				'source_slug'   => $source_slug,
				'modified_gmt'  => (string) get_post_field( 'post_modified_gmt', $post_id ),
				'content_hash'  => md5(
					(string) get_post_field( 'post_title', $post_id ) . "\n" .
					(string) get_post_field( 'post_content', $post_id ) . "\n" .
					(string) get_post_field( 'post_excerpt', $post_id )
				),
			);

			if ( ! empty( $source_url ) ) {
				$map['source_urls'][ $source_url ] = $row;
			}

			$map['post_ids'][ (int) $post_id ] = $row;
		}

		return $map;
	}

	private static function build_effective_feed_url( $feed_url, array $job ) {
		$override = ! empty( $job['params']['feed_url'] ) ? $job['params']['feed_url'] : $feed_url;
		$validated = self::validate_feed_url( $override );
		if ( is_wp_error( $validated ) ) {
			throw new RuntimeException( $validated->get_error_message() );
		}

		$url_parts = wp_parse_url( $override );
		$query     = array();

		if ( ! empty( $url_parts['query'] ) ) {
			parse_str( $url_parts['query'], $query );
		}

		if ( ! empty( $job['feed_action'] ) ) {
			$query['action'] = $job['feed_action'];
		} else {
			unset( $query['action'] );
		}

		$query['v'] = (string) time();

		$rebuilt = $url_parts['scheme'] . '://' . $url_parts['host'];
		if ( ! empty( $url_parts['port'] ) ) {
			$rebuilt .= ':' . $url_parts['port'];
		}
		$rebuilt .= $url_parts['path'] ?? '';
		$rebuilt  = add_query_arg( $query, $rebuilt );

		return $rebuilt;
	}

	private static function validate_feed_url( $feed_url ) {
		$settings = self::get_settings();
		$parsed   = wp_parse_url( $feed_url );

		if ( empty( $parsed['host'] ) ) {
			return new WP_Error( 'invalid_feed_url', 'The provided feed URL is invalid.', array( 'status' => 400 ) );
		}

		if ( strtolower( $parsed['host'] ) !== strtolower( $settings['allowed_host'] ) ) {
			return new WP_Error( 'invalid_feed_host', 'The provided feed host is not allowed.', array( 'status' => 400 ) );
		}

		return true;
	}

	private static function discover_rules() {
		$rules     = get_option( 'echo_rules_list', array() );
		$detected  = array();

		if ( ! is_array( $rules ) ) {
			return $detected;
		}

		foreach ( $rules as $rule_id => $rule ) {
			if ( empty( $rule[ self::RULE_FEED_INDEX ] ) ) {
				continue;
			}

			$feed_url = (string) $rule[ self::RULE_FEED_INDEX ];
			if ( false === strpos( $feed_url, 'feed=rss_publication' ) ) {
				continue;
			}

			$publication_slug = '';
			$query = wp_parse_url( $feed_url, PHP_URL_QUERY );
			if ( is_string( $query ) ) {
				parse_str( $query, $query_args );
				$publication_slug = isset( $query_args['publication'] ) ? sanitize_text_field( (string) $query_args['publication'] ) : '';
			}

			$detected[] = array(
				'id'               => (int) $rule_id,
				'active'           => ! empty( $rule[2] ) && '1' === (string) $rule[2],
				'post_type'        => isset( $rule[ self::RULE_POST_TYPE ] ) ? (string) $rule[ self::RULE_POST_TYPE ] : '',
				'feed_url'         => $feed_url,
				'publication_slug' => $publication_slug,
				'identity'         => isset( $rule[ self::RULE_IDENTITY ] ) ? (string) $rule[ self::RULE_IDENTITY ] : '',
			);
		}

		return $detected;
	}

	private static function get_default_rule() {
		$settings = self::get_settings();
		$rules    = self::discover_rules();

		if ( ! empty( $settings['default_rule_id'] ) ) {
			foreach ( $rules as $rule ) {
				if ( (string) $rule['id'] === (string) $settings['default_rule_id'] ) {
					return $rule;
				}
			}
		}

		foreach ( $rules as $rule ) {
			if ( ! empty( $rule['active'] ) && 'press-release' === $rule['post_type'] ) {
				return $rule;
			}
		}

		return ! empty( $rules[0] ) ? $rules[0] : array();
	}

	private static function get_rule_by_request( array $params ) {
		$rule = self::get_default_rule();
		if ( ! empty( $params['rule_id'] ) ) {
			foreach ( self::discover_rules() as $candidate ) {
				if ( (string) $candidate['id'] === (string) $params['rule_id'] ) {
					$rule = $candidate;
					break;
				}
			}
		}

		if ( empty( $rule ) ) {
			return new WP_Error( 'missing_rule', 'No matching Echo press-release rule could be detected.', array( 'status' => 500 ) );
		}

		return $rule;
	}

	private static function authorize_request( WP_REST_Request $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$settings = self::get_settings();
		$token    = (string) $request->get_param( 'token' );

		if ( empty( $token ) ) {
			$header_token = $request->get_header( 'x_hexa_pr_token' );
			$token        = is_string( $header_token ) ? $header_token : '';
		}

		if ( empty( $settings['secret_token'] ) || empty( $token ) ) {
			return false;
		}

		return hash_equals( (string) $settings['secret_token'], (string) $token );
	}

	private static function build_status_url( $job_id, $with_token = false ) {
		$url = rest_url( self::REST_NAMESPACE . '/jobs/' . rawurlencode( $job_id ) );
		return $with_token ? add_query_arg( 'token', self::get_settings()['secret_token'], $url ) : $url;
	}

	private static function build_stream_url( $job_id, $with_token = false ) {
		$url = rest_url( self::REST_NAMESPACE . '/jobs/' . rawurlencode( $job_id ) . '/stream' );
		return $with_token ? add_query_arg( 'token', self::get_settings()['secret_token'], $url ) : $url;
	}

	private static function build_run_url( $job_id, $with_token = false ) {
		$url = rest_url( self::REST_NAMESPACE . '/jobs/' . rawurlencode( $job_id ) . '/run' );
		return $with_token ? add_query_arg( 'token', self::get_settings()['secret_token'], $url ) : $url;
	}

	private static function dispatch_job_loopback( $job_id ) {
		$url = self::build_run_url( $job_id, true );

		wp_remote_post(
			$url,
			array(
				'timeout'  => 0.01,
				'blocking' => false,
				'headers'  => array(
					'Cache-Control' => 'no-cache',
				),
			)
		);
	}

	private static function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );

		return wp_parse_args(
			is_array( $settings ) ? $settings : array(),
			array(
				'secret_token'    => '',
				'allowed_host'    => 'hexaprwire.com',
				'default_rule_id' => '',
			)
		);
	}

	private static function maybe_bootstrap_settings() {
		$settings = get_option( self::OPTION_KEY, null );
		$settings = is_array( $settings ) ? $settings : array();
		$changed  = false;

		if ( empty( $settings['secret_token'] ) ) {
			$settings['secret_token'] = self::generate_secret();
			$changed                  = true;
		}

		if ( empty( $settings['allowed_host'] ) ) {
			$settings['allowed_host'] = 'hexaprwire.com';
			$changed                  = true;
		}

		if ( ! array_key_exists( 'default_rule_id', $settings ) ) {
			$settings['default_rule_id'] = '';
			$changed                     = true;
		}

		if ( $changed || null === get_option( self::OPTION_KEY, null ) ) {
			update_option( self::OPTION_KEY, $settings, false );
		}

		if ( false === get_option( self::JOB_INDEX_KEY, false ) ) {
			add_option( self::JOB_INDEX_KEY, array(), '', false );
		}
	}

	private static function save_job( array $job ) {
		update_option( self::JOB_PREFIX . $job['id'], $job, false );
	}

	private static function get_job( $job_id ) {
		wp_cache_delete( self::JOB_PREFIX . $job_id, 'options' );
		$job = get_option( self::JOB_PREFIX . $job_id, array() );
		return is_array( $job ) ? $job : array();
	}

	private static function index_job( $job_id ) {
		$index   = get_option( self::JOB_INDEX_KEY, array() );
		$index   = is_array( $index ) ? $index : array();
		$index[] = $job_id;
		$index   = array_values( array_unique( $index ) );
		update_option( self::JOB_INDEX_KEY, $index, false );
	}

	private static function cleanup_old_jobs() {
		$index = get_option( self::JOB_INDEX_KEY, array() );
		if ( ! is_array( $index ) ) {
			$index = array();
		}

		$kept = array();
		foreach ( $index as $job_id ) {
			$job = self::get_job( $job_id );
			if ( empty( $job['created_at'] ) ) {
				delete_option( self::JOB_PREFIX . $job_id );
				continue;
			}

			$created = strtotime( $job['created_at'] );
			if ( false === $created || ( time() - $created ) > self::JOB_RETENTION ) {
				delete_option( self::JOB_PREFIX . $job_id );
				continue;
			}

			$kept[] = $job_id;
		}

		update_option( self::JOB_INDEX_KEY, $kept, false );
	}

	private static function prepare_job_response( array $job ) {
		return array(
			'success'    => true,
			'job_id'     => $job['id'],
			'state'      => $job['state'],
			'action'     => $job['action'],
			'feed_action'=> $job['feed_action'],
			'dry_run'    => ! empty( $job['dry_run'] ),
			'hard_delete'=> ! empty( $job['hard_delete'] ),
			'created_at' => $job['created_at'],
			'started_at' => $job['started_at'],
			'finished_at'=> $job['finished_at'],
			'status'     => $job['status_message'],
			'rule'       => $job['rule'],
			'errors'     => $job['errors'],
			'logs'       => $job['logs'],
			'result'     => $job['result'],
		);
	}

	private static function append_job_log( array &$job, $message, $level = 'info' ) {
		if ( ! isset( $job['logs'] ) || ! is_array( $job['logs'] ) ) {
			$job['logs'] = array();
		}

		$job['logs'][] = array(
			'time'    => gmdate( 'H:i:s' ),
			'level'   => $level,
			'message' => $message,
		);

		if ( count( $job['logs'] ) > self::MAX_JOB_LOGS ) {
			$job['logs'] = array_slice( $job['logs'], -1 * self::MAX_JOB_LOGS );
		}

		$job['status_message'] = $message;
	}

	private static function get_echo_log_path() {
		return trailingslashit( get_temp_dir() ) . 'echo_info.log';
	}

	private static function read_echo_log_lines( array $job, $offset ) {
		$path = ! empty( $job['echo_log_path'] ) ? $job['echo_log_path'] : self::get_echo_log_path();
		if ( ! file_exists( $path ) ) {
			return array(
				'offset' => $offset,
				'lines'  => array(),
			);
		}

		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return array(
				'offset' => $offset,
				'lines'  => array(),
			);
		}

		fseek( $handle, $offset );
		$chunk  = stream_get_contents( $handle );
		$offset = ftell( $handle );
		fclose( $handle );

		if ( false === $chunk || '' === $chunk ) {
			return array(
				'offset' => $offset,
				'lines'  => array(),
			);
		}

		$lines = preg_split( '/\r\n|\r|\n/', trim( str_replace( '<br/>', '', $chunk ) ) );
		$lines = array_values( array_filter( array_map( 'trim', $lines ) ) );

		return array(
			'offset' => $offset,
			'lines'  => $lines,
		);
	}

	private static function get_echo_log_tail( array $job ) {
		$read = self::read_echo_log_lines( $job, isset( $job['echo_log_start'] ) ? (int) $job['echo_log_start'] : 0 );
		return $read['lines'];
	}

	private static function emit_sse( $event, $data ) {
		echo 'event: ' . $event . "\n";
		$payload = is_string( $data ) ? $data : wp_json_encode( $data );
		foreach ( preg_split( "/\r\n|\r|\n/", $payload ) as $line ) {
			echo 'data: ' . $line . "\n";
		}
		echo "\n";
		@flush();
	}

	private static function normalize_list_param( $value ) {
		if ( is_array( $value ) ) {
			$items = $value;
		} else {
			$items = preg_split( '/[\r\n,]+/', (string) $value );
		}

		$items = array_map(
			static function ( $item ) {
				return trim( (string) $item );
			},
			$items
		);

		return array_values( array_filter( $items ) );
	}

	private static function normalize_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		$url   = esc_url_raw( $url );
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $url;
		}

		$normalized = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$normalized .= ':' . $parts['port'];
		}

		$path = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) . '/' : '/';
		$normalized .= '/' === $path ? '/' : $path;

		if ( ! empty( $parts['query'] ) ) {
			$normalized .= '?' . $parts['query'];
		}

		if ( ! empty( $parts['fragment'] ) ) {
			$normalized .= '#' . $parts['fragment'];
		}

		return $normalized;
	}

	private static function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( (string) $value, array( '1', 'true', 'yes', 'on' ), true );
	}

	private static function generate_secret() {
		return wp_generate_password( 64, false, false );
	}
}

register_activation_hook( __FILE__, array( 'Hexa_PR_Wire_Publication_Sync', 'activate' ) );
Hexa_PR_Wire_Publication_Sync::init();
