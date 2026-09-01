<?php
/**
 * e-Gov Law Monitor - Permissions
 */

/**
 * Grant internal API capability to the API user.
 */
function egov_law_monitor_setup_api_user() {

    $user = get_user_by( 'login', 'law_watch_api' );

    if ( ! $user ) {
        return;
    }

    $user->add_cap( 'law_watch_internal_api' );
}