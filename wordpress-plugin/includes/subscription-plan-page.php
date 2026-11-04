<?php

/** Render the current logged-in user's effective plan for the account shortcode. */
function egov_law_monitor_subscription_plan_shortcode( $attributes = array(), $content = null, $tag = '' ) {
	if ( ! is_user_logged_in() ) {
		return '<p class="egov-subscription-plan-message">ログインするとご利用プランを確認できます。</p>';
	}

	$user_id    = get_current_user_id();
	$entitlement = egov_law_monitor_get_effective_entitlement( $user_id );
	if ( is_wp_error( $entitlement ) || ! is_array( $entitlement ) ) {
		return '<p class="egov-subscription-plan-message" role="status">現在のプラン情報を確認できません。時間をおいて再度ご確認ください。</p>';
	}

	$plan = $entitlement['effective_plan'] ?? '';
	if ( 'standard' === $plan ) {
		return '<section class="egov-subscription-plan-card"><h2>ご利用中のプラン</h2><p class="egov-subscription-plan-name">Standard</p><p>Standardプランをご利用中です。</p></section>';
	}
	if ( 'free' !== $plan ) {
		return '<p class="egov-subscription-plan-message" role="status">現在のプラン情報を確認できません。時間をおいて再度ご確認ください。</p>';
	}

	$service = egov_law_monitor_square_checkout_service();
	if ( is_wp_error( $service ) || ! $service instanceof Egov_Law_Monitor_Square_Checkout_Service ) {
		return egov_law_monitor_subscription_plan_offer( false, '申込状況を確認できないため、現在お申し込みいただけません。時間をおいて再度ご確認ください。' );
	}

	$attempt_status = $service->get_attempt_status_for_user( $user_id );
	if ( is_wp_error( $attempt_status ) ) {
		return egov_law_monitor_subscription_plan_offer( false, '申込状況を確認できないため、現在お申し込みいただけません。時間をおいて再度ご確認ください。' );
	}
	if ( 'reconciliation_required' === $attempt_status ) {
		return egov_law_monitor_subscription_plan_offer( false, '決済状況を確認中です。確認が完了するまで、新たなお申し込みはできません。' );
	}
	if ( null !== $attempt_status && ! in_array( $attempt_status, array( 'creating', 'pending' ), true ) ) {
		return egov_law_monitor_subscription_plan_offer( false, '申込状況を確認できないため、現在お申し込みいただけません。時間をおいて再度ご確認ください。' );
	}

	return egov_law_monitor_subscription_plan_offer( true, '' );
}

/** Build the Free-plan offer without exposing stored checkout details. */
function egov_law_monitor_subscription_plan_offer( $can_checkout, $message ) {
	ob_start();
	?>
	<section class="egov-subscription-plan-card">
		<h2>ご利用中のプラン</h2>
		<p class="egov-subscription-plan-name">Free</p>
		<div class="egov-subscription-plan-offer">
			<h3>Standardプラン</h3>
			<p class="egov-subscription-plan-price">月額500円（税込）</p>
			<ul>
				<li>法令ウォッチ：キーワード10件</li>
				<li>AI要約：月100回</li>
			</ul>
			<?php if ( $can_checkout ) : ?>
				<button type="button" class="egov-subscription-checkout-button">Standardプランに申し込む</button>
				<p class="egov-subscription-checkout-status" role="status" aria-live="polite"></p>
			<?php else : ?>
				<p class="egov-subscription-plan-message" role="status" aria-live="polite"><?php echo esc_html( $message ); ?></p>
			<?php endif; ?>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}

add_shortcode( 'egov_law_subscription_plan', 'egov_law_monitor_subscription_plan_shortcode' );

add_action(
	'wp_enqueue_scripts',
	static function () {
		$plugin_file = dirname( __DIR__ ) . '/egov-law-monitor.php';
		$base_path   = plugin_dir_path( $plugin_file );
		$base_url    = plugin_dir_url( $plugin_file );
		$script_path = $base_path . 'assets/js/subscription-plan.js';
		$style_path  = $base_path . 'assets/css/subscription-plan.css';

		wp_enqueue_style( 'egov-subscription-plan', $base_url . 'assets/css/subscription-plan.css', array(), filemtime( $style_path ) );
		wp_enqueue_script( 'egov-subscription-plan', $base_url . 'assets/js/subscription-plan.js', array(), filemtime( $script_path ), true );
		wp_localize_script(
			'egov-subscription-plan',
			'egovSubscriptionPlan',
			array(
				'restUrl'   => rest_url( 'egov-law-monitor/v1/subscription-checkout' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
);
