<?php

/**
 * Persistence and request service for logged-in users starting Square Checkout.
 * Payment links are not evidence of a paid subscription and never grant access.
 */
class Egov_Law_Monitor_Checkout_Repository {
	private $wpdb;

	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/** Start a per-user transaction and lock the stable WordPress user row. */
	public function begin_user_lock( $user_id ) {
		$users = $this->wpdb->users;
		$attempts = $this->wpdb->prefix . 'egov_law_checkout_attempts';
		$status = $this->wpdb->get_row( $this->wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $users ) );
		$attempt_status = $this->wpdb->get_row( $this->wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $attempts ) );
		if (
			! is_object( $status )
			|| 'INNODB' !== strtoupper( (string) $status->Engine )
			|| ! is_object( $attempt_status )
			|| 'INNODB' !== strtoupper( (string) $attempt_status->Engine )
		) {
			return new WP_Error( 'checkout_database_unavailable', '申込情報を保存できません。' );
		}

		$this->wpdb->last_error = '';
		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'checkout_database_unavailable', '申込情報を保存できません。' );
		}
		$this->wpdb->last_error = '';
		$locked_user = $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT ID FROM {$users} WHERE ID = %d FOR UPDATE", $user_id )
		);
		if ( '' !== (string) $this->wpdb->last_error || (int) $locked_user !== (int) $user_id ) {
			$this->rollback();
			return new WP_Error( 'checkout_database_unavailable', '申込情報を保存できません。' );
		}
		return true;
	}

	public function find_open_attempt( $user_id, $environment, $merchant_id ) {
		$table = $this->wpdb->prefix . 'egov_law_checkout_attempts';
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND environment = %s AND merchant_id = %s AND status IN ('creating', 'pending', 'reconciliation_required') ORDER BY id DESC FOR UPDATE",
				$user_id,
				$environment,
				$merchant_id
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'checkout_database_unavailable', '申込情報を読み取れません。' );
		}
		if ( count( $rows ) > 1 ) {
			return new WP_Error( 'checkout_attempts_inconsistent', '申込情報を確認できません。' );
		}
		return isset( $rows[0] ) ? $rows[0] : null;
	}

	public function insert_creating_attempt( $user_id, $scope, $attempt_uuid, $idempotency_key ) {
		$table = $this->wpdb->prefix . 'egov_law_checkout_attempts';
		$now = current_time( 'mysql', true );
		$this->wpdb->last_error = '';
		$inserted = $this->wpdb->insert(
			$table,
			array(
				'attempt_uuid'                    => $attempt_uuid,
				'user_id'                         => $user_id,
				'environment'                     => $scope['environment'],
				'merchant_id'                     => $scope['merchant_id'],
				'square_location_id'               => $scope['location_id'],
				'plan_code'                       => 'standard',
				'catalog_item_variation_id'         => $scope['catalog_item_variation_id'],
				'subscription_plan_variation_id'    => $scope['subscription_plan_variation_id'],
				'status'                          => 'creating',
				'idempotency_key'                 => $idempotency_key,
				'created_at'                      => $now,
				'updated_at'                      => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( 1 !== $inserted || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'checkout_database_unavailable', '申込情報を保存できません。' );
		}
		return array(
			'id'                              => (int) $this->wpdb->insert_id,
			'attempt_uuid'                    => $attempt_uuid,
			'idempotency_key'                 => $idempotency_key,
			'status'                          => 'creating',
			'environment'                     => $scope['environment'],
			'merchant_id'                     => $scope['merchant_id'],
			'square_location_id'               => $scope['location_id'],
			'plan_code'                       => 'standard',
			'catalog_item_variation_id'         => $scope['catalog_item_variation_id'],
			'subscription_plan_variation_id'    => $scope['subscription_plan_variation_id'],
		);
	}

	public function save_pending_link( $attempt_id, $link ) {
		$table = $this->wpdb->prefix . 'egov_law_checkout_attempts';
		$this->wpdb->last_error = '';
		$updated = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table} SET status = 'pending', square_payment_link_id = %s, square_order_id = %s, payment_url = %s, last_error_code = '', updated_at = %s WHERE id = %d AND status IN ('creating', 'failed', 'pending')",
			$link['payment_link_id'],
			$link['order_id'],
			$link['url'],
			current_time( 'mysql', true ),
			$attempt_id
			)
		);
		if ( false === $updated || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'checkout_database_unavailable', '申込情報を更新できません。' );
		}
		$row = $this->get_attempt( $attempt_id );
		if ( is_wp_error( $row ) || ! is_array( $row ) || 'pending' !== $row['status'] || $row['square_payment_link_id'] !== $link['payment_link_id'] || $row['square_order_id'] !== $link['order_id'] || $row['payment_url'] !== $link['url'] ) {
			return new WP_Error( 'checkout_database_unavailable', '申込情報を更新できません。' );
		}
		return true;
	}

	public function record_api_error( $attempt_id, $error_code, $permanent ) {
		$table = $this->wpdb->prefix . 'egov_law_checkout_attempts';
		$safe_code = substr( sanitize_key( (string) $error_code ), 0, 100 );
		if ( '' === $safe_code ) {
			$safe_code = 'square_request_failed';
		}
		$this->wpdb->last_error = '';
		if ( $permanent ) {
			$updated = $this->wpdb->query(
				$this->wpdb->prepare(
					"UPDATE {$table} SET status = 'failed', last_error_code = %s, updated_at = %s WHERE id = %d AND status = 'creating'",
					$safe_code,
					current_time( 'mysql', true ),
					$attempt_id
				)
			);
		} else {
			$updated = $this->wpdb->update(
				$table,
				array( 'last_error_code' => $safe_code, 'updated_at' => current_time( 'mysql', true ) ),
				array( 'id' => $attempt_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}
		return false !== $updated && '' === (string) $this->wpdb->last_error;
	}

	public function mark_reconciliation_required( $attempt_id ) {
		$table = $this->wpdb->prefix . 'egov_law_checkout_attempts';
		$this->wpdb->last_error = '';
		$updated = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table} SET status = 'reconciliation_required', last_error_code = 'square_payment_link_not_found', updated_at = %s WHERE id = %d AND status IN ('creating', 'pending', 'reconciliation_required')",
			current_time( 'mysql', true ),
			$attempt_id
			)
		);
		return false !== $updated && '' === (string) $this->wpdb->last_error;
	}

	public function get_attempt( $attempt_id ) {
		$table = $this->wpdb->prefix . 'egov_law_checkout_attempts';
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $attempt_id ), ARRAY_A );
		if ( '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'checkout_database_unavailable', '申込情報を読み取れません。' );
		}
		return is_array( $row ) ? $row : null;
	}

	public function commit() {
		$this->wpdb->last_error = '';
		$result = $this->wpdb->query( 'COMMIT' );
		return false !== $result && '' === (string) $this->wpdb->last_error;
	}

	public function rollback() {
		$this->wpdb->query( 'ROLLBACK' );
	}
}

/** Create or safely reuse the current user's Standard checkout link. */
class Egov_Law_Monitor_Square_Checkout_Service {
	private $repository;
	private $client;

	public function __construct( Egov_Law_Monitor_Checkout_Repository $repository, Egov_Law_Monitor_Square_Api_Client $client ) {
		$this->repository = $repository;
		$this->client = $client;
	}

	public function create_or_reuse_for_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return new WP_Error( 'checkout_unavailable', '決済リンクを準備できません。' );
		}
		$scope = $this->client->get_checkout_scope();
		if ( is_wp_error( $scope ) ) {
			return new WP_Error( 'checkout_configuration_unavailable', '決済リンクを準備できません。' );
		}

		$locked = $this->repository->begin_user_lock( $user_id );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}

		$entitlement = egov_law_monitor_get_effective_entitlement( $user_id );
		if ( is_wp_error( $entitlement ) || ! is_array( $entitlement ) || ! isset( $entitlement['effective_plan'] ) || ! in_array( $entitlement['effective_plan'], array( 'free', 'standard' ), true ) ) {
			$this->repository->rollback();
			return new WP_Error( 'checkout_entitlement_unavailable', '契約状態を確認できないため、申込を開始できません。' );
		}
		if ( 'standard' === $entitlement['effective_plan'] ) {
			$this->repository->rollback();
			return new WP_Error( 'checkout_standard_active', 'Standardプランはすでに有効です。' );
		}

		$attempt = $this->repository->find_open_attempt( $user_id, $scope['environment'], $scope['merchant_id'] );
		if ( is_wp_error( $attempt ) ) {
			$this->repository->rollback();
			return $attempt;
		}
		$is_retry = null !== $attempt;
		if ( null === $attempt ) {
			$attempt = $this->repository->insert_creating_attempt( $user_id, $scope, wp_generate_uuid4(), wp_generate_uuid4() );
			if ( is_wp_error( $attempt ) ) {
				$this->repository->rollback();
				return $attempt;
			}
		}
		if ( ! $this->attempt_matches_scope( $attempt, $scope ) ) {
			$this->repository->rollback();
			return new WP_Error( 'checkout_attempt_scope_mismatch', '申込情報を確認できません。' );
		}
		if ( 'reconciliation_required' === $attempt['status'] ) {
			$this->repository->rollback();
			return new WP_Error( 'checkout_reconciliation_required', '決済状況を確認中です。しばらくしてからお試しください。' );
		}
		if ( ! $this->repository->commit() ) {
			$this->repository->rollback();
			return new WP_Error( 'checkout_database_unavailable', '申込情報を保存できません。' );
		}

		if ( 'pending' === $attempt['status'] ) {
			return $this->verify_and_reuse( $attempt );
		}
		return $this->create_for_attempt( $user_id, $attempt, $is_retry );
	}

	private function verify_and_reuse( $attempt ) {
		if ( empty( $attempt['square_payment_link_id'] ) || empty( $attempt['square_order_id'] ) || empty( $attempt['payment_url'] ) ) {
			return new WP_Error( 'checkout_link_verification_unavailable', '既存の決済リンクを確認できません。' );
		}
		$link = $this->client->retrieve_payment_link( $attempt['square_payment_link_id'] );
		if ( is_wp_error( $link ) ) {
			if ( 'square_payment_link_not_found' === $link->get_error_code() ) {
				if ( ! $this->repository->mark_reconciliation_required( (int) $attempt['id'] ) ) {
					return new WP_Error( 'checkout_database_unavailable', '申込情報を更新できません。' );
				}
				return new WP_Error( 'checkout_reconciliation_required', '決済状況を確認中です。しばらくしてからお試しください。' );
			}
			$this->repository->record_api_error( (int) $attempt['id'], $link->get_error_code(), false );
			return new WP_Error( 'checkout_link_verification_unavailable', '既存の決済リンクを確認できません。時間をおいて再度お試しください。' );
		}
		if ( $attempt['square_payment_link_id'] !== $link['payment_link_id'] || $attempt['square_order_id'] !== $link['order_id'] || $attempt['payment_url'] !== $link['url'] ) {
			return new WP_Error( 'checkout_link_verification_unavailable', '既存の決済リンクを確認できません。時間をおいて再度お試しください。' );
		}
		return array( 'status' => 'pending', 'url' => $link['url'], 'reused' => true );
	}

	private function create_for_attempt( $user_id, $attempt, $is_retry ) {
		$link = $this->client->create_payment_link( $attempt['attempt_uuid'], $attempt['idempotency_key'] );
		if ( is_wp_error( $link ) ) {
			$this->repository->record_api_error( (int) $attempt['id'], $link->get_error_code(), $this->is_definite_api_failure( $link ) );
			return new WP_Error( 'checkout_link_unavailable', '決済リンクを作成できませんでした。時間をおいて再度お試しください。' );
		}

		if ( $is_retry ) {
			$verified = $this->client->retrieve_payment_link( $link['payment_link_id'] );
			if ( is_wp_error( $verified ) ) {
				if ( 'square_payment_link_not_found' === $verified->get_error_code() ) {
					if ( ! $this->repository->mark_reconciliation_required( (int) $attempt['id'] ) ) {
						return new WP_Error( 'checkout_database_unavailable', '申込情報を更新できません。' );
					}
					return new WP_Error( 'checkout_reconciliation_required', '決済状況を確認中です。しばらくしてからお試しください。' );
				}
				$this->repository->record_api_error( (int) $attempt['id'], $verified->get_error_code(), false );
				return new WP_Error( 'checkout_link_verification_unavailable', '決済リンクを確認できませんでした。時間をおいて再度お試しください。' );
			}
			if ( $link !== $verified ) {
				return new WP_Error( 'checkout_link_verification_unavailable', '決済リンクを確認できませんでした。時間をおいて再度お試しください。' );
			}
			$link = $verified;
		}

		$saved = $this->repository->save_pending_link( (int) $attempt['id'], $link );
		if ( is_wp_error( $saved ) ) {
			return new WP_Error( 'checkout_database_unavailable', '決済リンクを保存できませんでした。時間をおいて再度お試しください。' );
		}
		return array( 'status' => 'pending', 'url' => $link['url'], 'reused' => false );
	}

	private function is_definite_api_failure( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		if ( in_array( $error->get_error_code(), array( 'square_http_request_failed', 'square_response_invalid' ), true ) ) {
			return false;
		}
		$data = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['http_status'] ) ? (int) $data['http_status'] : 0;
		return $status >= 400 && $status < 500 && ! in_array( $status, array( 408, 409, 425, 429 ), true );
	}

	private function attempt_matches_scope( $attempt, $scope ) {
		$expected = array(
			'environment'                   => $scope['environment'],
			'merchant_id'                   => $scope['merchant_id'],
			'square_location_id'             => $scope['location_id'],
			'plan_code'                      => 'standard',
			'catalog_item_variation_id'        => $scope['catalog_item_variation_id'],
			'subscription_plan_variation_id'   => $scope['subscription_plan_variation_id'],
		);
		foreach ( $expected as $key => $value ) {
			if ( ! isset( $attempt[ $key ] ) || ! is_string( $attempt[ $key ] ) || $attempt[ $key ] !== $value ) {
				return false;
			}
		}
		return true;
	}
}

/** Build the configured checkout service; filters allow isolated tests. */
function egov_law_monitor_square_checkout_service() {
	global $wpdb;
	$override = apply_filters( 'egov_law_monitor_square_checkout_service_override', null, $wpdb );
	if ( is_wp_error( $override ) || $override instanceof Egov_Law_Monitor_Square_Checkout_Service ) {
		return $override;
	}
	$client = Egov_Law_Monitor_Square_Api_Client::from_wordpress_config();
	if ( is_wp_error( $client ) ) {
		return $client;
	}
	$service = new Egov_Law_Monitor_Square_Checkout_Service( new Egov_Law_Monitor_Checkout_Repository( $wpdb ), $client );
	return apply_filters( 'egov_law_monitor_square_checkout_service', $service );
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'egov-law-monitor/v1',
			'/subscription-checkout',
			array(
				'methods'  => WP_REST_Server::CREATABLE,
				'permission_callback' => static function ( $request ) {
					if ( ! is_user_logged_in() ) {
						return new WP_Error( 'checkout_login_required', 'ログインが必要です。', array( 'status' => 401 ) );
					}
					$nonce = $request->get_header( 'X-WP-Nonce' );
					if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
						return new WP_Error( 'checkout_nonce_invalid', 'リクエストを確認できません。ページを再読み込みしてください。', array( 'status' => 403 ) );
					}
					return true;
				},
				'callback' => static function () {
					$service = egov_law_monitor_square_checkout_service();
					if ( is_wp_error( $service ) ) {
						return new WP_Error( 'checkout_unavailable', '決済リンクを準備できません。時間をおいて再度お試しください。', array( 'status' => 503 ) );
					}
					$result = $service->create_or_reuse_for_user( get_current_user_id() );
					if ( is_wp_error( $result ) ) {
						$code = $result->get_error_code();
						if ( 'checkout_standard_active' === $code ) {
							return new WP_Error( 'checkout_standard_active', 'Standardプランはすでに有効です。', array( 'status' => 409 ) );
						}
						if ( 'checkout_reconciliation_required' === $code ) {
							return new WP_Error( 'checkout_status_pending', '決済状況を確認中です。しばらくしてからお試しください。', array( 'status' => 503 ) );
						}
						$status = 'checkout_link_unavailable' === $code ? 502 : 503;
						return new WP_Error( 'checkout_unavailable', '決済リンクを準備できません。時間をおいて再度お試しください。', array( 'status' => $status ) );
					}
					return rest_ensure_response( array( 'status' => 'pending', 'checkout_url' => $result['url'] ) );
				},
			)
		);
	}
);
