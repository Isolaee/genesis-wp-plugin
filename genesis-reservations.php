<?php
/**
 * Plugin Name: Genesis Reservations
 * Plugin URI:  https://github.com/eero/genesis-wp-plugin
 * Description: Event reservation system via shortcode. Usage: [reservation event="My Event" time="7:00 PM" place="City Hall" description="Join us!"]
 * Version:     1.0.0
 * Author:      Genesis
 * License:     GPL-2.0-or-later
 * Text Domain: genesis-reservations
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GR_VERSION', '1.0.0' );
define( 'GR_TABLE_NAME', 'gr_reservations' );

// ---------------------------------------------------------------------------
// Activation: create DB table
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, 'gr_activate' );
function gr_activate() {
    global $wpdb;
    $table   = $wpdb->prefix . GR_TABLE_NAME;
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        event_name  VARCHAR(255) NOT NULL DEFAULT '',
        event_time  VARCHAR(100) NOT NULL DEFAULT '',
        event_place VARCHAR(255) NOT NULL DEFAULT '',
        event_desc  TEXT NOT NULL DEFAULT '',
        first_name  VARCHAR(100) NOT NULL,
        last_name   VARCHAR(100) NOT NULL,
        email       VARCHAR(200) NOT NULL,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    update_option( 'gr_db_version', GR_VERSION );
}

// ---------------------------------------------------------------------------
// Enqueue styles
// ---------------------------------------------------------------------------
add_action( 'wp_enqueue_scripts', 'gr_enqueue_assets' );
function gr_enqueue_assets() {
    wp_enqueue_style(
        'genesis-reservations',
        plugin_dir_url( __FILE__ ) . 'assets/style.css',
        [],
        GR_VERSION
    );
}

add_action( 'admin_enqueue_scripts', 'gr_enqueue_admin_assets' );
function gr_enqueue_admin_assets( $hook ) {
    if ( strpos( $hook, 'genesis-reservations' ) === false ) {
        return;
    }
    wp_enqueue_style(
        'genesis-reservations-admin',
        plugin_dir_url( __FILE__ ) . 'assets/style.css',
        [],
        GR_VERSION
    );
}

// ---------------------------------------------------------------------------
// Shortcode: [reservation event="" time="" place="" description=""]
// ---------------------------------------------------------------------------
add_shortcode( 'reservation', 'gr_shortcode' );
function gr_shortcode( $atts ) {
    $atts = shortcode_atts( [
        'event'       => '',
        'time'        => '',
        'place'       => '',
        'description' => '',
    ], $atts, 'reservation' );

    $event_name  = sanitize_text_field( $atts['event'] );
    $event_time  = sanitize_text_field( $atts['time'] );
    $event_place = sanitize_text_field( $atts['place'] );
    $event_desc  = sanitize_textarea_field( $atts['description'] );

    ob_start();

    // ---- Handle form submission ----
    $message = '';
    $error   = '';

    if (
        isset( $_POST['gr_nonce'] ) &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gr_nonce'] ) ), 'gr_reservation_' . $event_name )
    ) {
        $first_name = sanitize_text_field( wp_unslash( $_POST['gr_first_name'] ?? '' ) );
        $last_name  = sanitize_text_field( wp_unslash( $_POST['gr_last_name'] ?? '' ) );
        $email      = sanitize_email( wp_unslash( $_POST['gr_email'] ?? '' ) );

        if ( empty( $first_name ) || empty( $last_name ) || empty( $email ) ) {
            $error = __( 'All fields are required.', 'genesis-reservations' );
        } elseif ( ! is_email( $email ) ) {
            $error = __( 'Please enter a valid email address.', 'genesis-reservations' );
        } else {
            global $wpdb;
            $table = $wpdb->prefix . GR_TABLE_NAME;

            $result = $wpdb->insert(
                $table,
                [
                    'event_name'  => $event_name,
                    'event_time'  => $event_time,
                    'event_place' => $event_place,
                    'event_desc'  => $event_desc,
                    'first_name'  => $first_name,
                    'last_name'   => $last_name,
                    'email'       => $email,
                ],
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );

            if ( $result ) {
                $message = sprintf(
                    /* translators: %1$s first name, %2$s last initial */
                    __( 'Thank you, %1$s %2$s.! Your reservation is confirmed.', 'genesis-reservations' ),
                    esc_html( $first_name ),
                    esc_html( strtoupper( substr( $last_name, 0, 1 ) ) )
                );
            } else {
                $error = __( 'Something went wrong. Please try again.', 'genesis-reservations' );
            }
        }
    }

    $is_admin = current_user_can( 'manage_options' );

    ?>
    <div class="gr-reservation-wrap">

        <?php if ( $event_name || $event_time || $event_place || $event_desc ) : ?>
        <div class="gr-event-info">
            <?php if ( $event_name ) : ?>
                <h2 class="gr-event-title"><?php echo esc_html( $event_name ); ?></h2>
            <?php endif; ?>
            <ul class="gr-event-meta">
                <?php if ( $event_time ) : ?>
                    <li><span class="gr-meta-label"><?php esc_html_e( 'Time:', 'genesis-reservations' ); ?></span> <?php echo esc_html( $event_time ); ?></li>
                <?php endif; ?>
                <?php if ( $event_place ) : ?>
                    <li><span class="gr-meta-label"><?php esc_html_e( 'Place:', 'genesis-reservations' ); ?></span> <?php echo esc_html( $event_place ); ?></li>
                <?php endif; ?>
            </ul>
            <?php if ( $event_desc ) : ?>
                <p class="gr-event-desc"><?php echo esc_html( $event_desc ); ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ( $message ) : ?>
            <div class="gr-notice gr-notice--success"><?php echo esc_html( $message ); ?></div>
        <?php elseif ( $error ) : ?>
            <div class="gr-notice gr-notice--error"><?php echo esc_html( $error ); ?></div>
        <?php endif; ?>

        <?php if ( ! $message ) : ?>
        <form class="gr-form" method="post">
            <?php wp_nonce_field( 'gr_reservation_' . $event_name, 'gr_nonce' ); ?>

            <div class="gr-field">
                <label for="gr_first_name"><?php esc_html_e( 'First Name', 'genesis-reservations' ); ?> <span aria-hidden="true">*</span></label>
                <input type="text" id="gr_first_name" name="gr_first_name"
                    value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_POST['gr_first_name'] ?? '' ) ) ); ?>"
                    required autocomplete="given-name" />
            </div>

            <div class="gr-field">
                <label for="gr_last_name"><?php esc_html_e( 'Last Name', 'genesis-reservations' ); ?> <span aria-hidden="true">*</span></label>
                <input type="text" id="gr_last_name" name="gr_last_name"
                    value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_POST['gr_last_name'] ?? '' ) ) ); ?>"
                    required autocomplete="family-name" />
            </div>

            <div class="gr-field">
                <label for="gr_email"><?php esc_html_e( 'Email Address', 'genesis-reservations' ); ?> <span aria-hidden="true">*</span></label>
                <input type="email" id="gr_email" name="gr_email"
                    value="<?php echo esc_attr( sanitize_email( wp_unslash( $_POST['gr_email'] ?? '' ) ) ); ?>"
                    required autocomplete="email" />
            </div>

            <button type="submit" class="gr-submit"><?php esc_html_e( 'Reserve My Spot', 'genesis-reservations' ); ?></button>
        </form>
        <?php endif; ?>

        <?php
        // ---- Admin view: list all reservations for this event ----
        if ( $is_admin ) :
            global $wpdb;
            $table = $wpdb->prefix . GR_TABLE_NAME;

            // Handle inline edit
            if (
                isset( $_POST['gr_edit_nonce'] ) &&
                wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gr_edit_nonce'] ) ), 'gr_edit_reservation' )
            ) {
                $edit_id         = absint( $_POST['gr_edit_id'] ?? 0 );
                $edit_first_name = sanitize_text_field( wp_unslash( $_POST['gr_edit_first_name'] ?? '' ) );
                $edit_last_name  = sanitize_text_field( wp_unslash( $_POST['gr_edit_last_name'] ?? '' ) );
                $edit_email      = sanitize_email( wp_unslash( $_POST['gr_edit_email'] ?? '' ) );

                if ( $edit_id && $edit_first_name && $edit_last_name && is_email( $edit_email ) ) {
                    $wpdb->update(
                        $table,
                        [
                            'first_name' => $edit_first_name,
                            'last_name'  => $edit_last_name,
                            'email'      => $edit_email,
                        ],
                        [ 'id' => $edit_id ],
                        [ '%s', '%s', '%s' ],
                        [ '%d' ]
                    );
                }
            }

            // Handle delete
            if (
                isset( $_GET['gr_delete'] ) && isset( $_GET['gr_delete_nonce'] ) &&
                wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['gr_delete_nonce'] ) ), 'gr_delete_' . absint( $_GET['gr_delete'] ) )
            ) {
                $wpdb->delete( $table, [ 'id' => absint( $_GET['gr_delete'] ) ], [ '%d' ] );
            }

            $edit_row = isset( $_GET['gr_edit'] ) ? absint( $_GET['gr_edit'] ) : 0;

            $where   = $event_name ? $wpdb->prepare( 'WHERE event_name = %s', $event_name ) : '';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $results = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC" );
        ?>
        <div class="gr-admin-panel">
            <h3 class="gr-admin-title">
                <?php
                printf(
                    /* translators: %s event name */
                    esc_html__( 'Reservations%s', 'genesis-reservations' ),
                    $event_name ? ': ' . esc_html( $event_name ) : ''
                );
                ?>
                <span class="gr-admin-count">(<?php echo count( $results ); ?>)</span>
            </h3>

            <?php if ( $results ) : ?>
            <div class="gr-table-wrap">
                <table class="gr-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?php esc_html_e( 'Name', 'genesis-reservations' ); ?></th>
                            <th><?php esc_html_e( 'Email', 'genesis-reservations' ); ?></th>
                            <th><?php esc_html_e( 'Event', 'genesis-reservations' ); ?></th>
                            <th><?php esc_html_e( 'Time', 'genesis-reservations' ); ?></th>
                            <th><?php esc_html_e( 'Place', 'genesis-reservations' ); ?></th>
                            <th><?php esc_html_e( 'Date', 'genesis-reservations' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'genesis-reservations' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $results as $row ) : ?>
                        <?php if ( $edit_row === (int) $row->id ) : ?>
                        <tr class="gr-edit-row">
                            <td><?php echo esc_html( $row->id ); ?></td>
                            <td colspan="2">
                                <form method="post" class="gr-inline-edit-form">
                                    <?php wp_nonce_field( 'gr_edit_reservation', 'gr_edit_nonce' ); ?>
                                    <input type="hidden" name="gr_edit_id" value="<?php echo esc_attr( $row->id ); ?>" />
                                    <input type="text" name="gr_edit_first_name" value="<?php echo esc_attr( $row->first_name ); ?>" placeholder="<?php esc_attr_e( 'First Name', 'genesis-reservations' ); ?>" required />
                                    <input type="text" name="gr_edit_last_name" value="<?php echo esc_attr( $row->last_name ); ?>" placeholder="<?php esc_attr_e( 'Last Name', 'genesis-reservations' ); ?>" required />
                                    <input type="email" name="gr_edit_email" value="<?php echo esc_attr( $row->email ); ?>" placeholder="<?php esc_attr_e( 'Email', 'genesis-reservations' ); ?>" required />
                                    <button type="submit" class="gr-btn gr-btn--save"><?php esc_html_e( 'Save', 'genesis-reservations' ); ?></button>
                                    <a href="<?php echo esc_url( remove_query_arg( 'gr_edit' ) ); ?>" class="gr-btn gr-btn--cancel"><?php esc_html_e( 'Cancel', 'genesis-reservations' ); ?></a>
                                </form>
                            </td>
                            <td><?php echo esc_html( $row->event_name ); ?></td>
                            <td><?php echo esc_html( $row->event_time ); ?></td>
                            <td><?php echo esc_html( $row->event_place ); ?></td>
                            <td><?php echo esc_html( gmdate( 'Y-m-d H:i', strtotime( $row->created_at ) ) ); ?></td>
                            <td></td>
                        </tr>
                        <?php else : ?>
                        <tr>
                            <td><?php echo esc_html( $row->id ); ?></td>
                            <td><?php echo esc_html( $row->first_name . ' ' . $row->last_name ); ?></td>
                            <td><?php echo esc_html( $row->email ); ?></td>
                            <td><?php echo esc_html( $row->event_name ); ?></td>
                            <td><?php echo esc_html( $row->event_time ); ?></td>
                            <td><?php echo esc_html( $row->event_place ); ?></td>
                            <td><?php echo esc_html( gmdate( 'Y-m-d H:i', strtotime( $row->created_at ) ) ); ?></td>
                            <td class="gr-actions">
                                <a href="<?php echo esc_url( add_query_arg( 'gr_edit', $row->id ) ); ?>" class="gr-btn gr-btn--edit"><?php esc_html_e( 'Edit', 'genesis-reservations' ); ?></a>
                                <a href="<?php echo esc_url( add_query_arg( [ 'gr_delete' => $row->id, 'gr_delete_nonce' => wp_create_nonce( 'gr_delete_' . $row->id ) ] ) ); ?>"
                                   class="gr-btn gr-btn--delete"
                                   onclick="return confirm('<?php esc_attr_e( 'Delete this reservation?', 'genesis-reservations' ); ?>')"
                                ><?php esc_html_e( 'Delete', 'genesis-reservations' ); ?></a>
                            </td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else : ?>
                <p class="gr-no-results"><?php esc_html_e( 'No reservations yet.', 'genesis-reservations' ); ?></p>
            <?php endif; ?>
        </div>
        <?php endif; // is_admin ?>

    </div>
    <?php

    return ob_get_clean();
}

// ---------------------------------------------------------------------------
// Admin menu page (optional overview of all reservations)
// ---------------------------------------------------------------------------
add_action( 'admin_menu', 'gr_admin_menu' );
function gr_admin_menu() {
    add_menu_page(
        __( 'Reservations', 'genesis-reservations' ),
        __( 'Reservations', 'genesis-reservations' ),
        'manage_options',
        'genesis-reservations',
        'gr_admin_page',
        'dashicons-calendar-alt',
        30
    );
}

function gr_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . GR_TABLE_NAME;

    // Handle delete from admin page
    if (
        isset( $_GET['gr_delete'] ) && isset( $_GET['gr_delete_nonce'] ) &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['gr_delete_nonce'] ) ), 'gr_delete_' . absint( $_GET['gr_delete'] ) )
    ) {
        $wpdb->delete( $table, [ 'id' => absint( $_GET['gr_delete'] ) ], [ '%d' ] );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Reservation deleted.', 'genesis-reservations' ) . '</p></div>';
    }

    // Handle inline edit from admin page
    if (
        isset( $_POST['gr_edit_nonce'] ) &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gr_edit_nonce'] ) ), 'gr_edit_reservation' )
    ) {
        $edit_id         = absint( $_POST['gr_edit_id'] ?? 0 );
        $edit_first_name = sanitize_text_field( wp_unslash( $_POST['gr_edit_first_name'] ?? '' ) );
        $edit_last_name  = sanitize_text_field( wp_unslash( $_POST['gr_edit_last_name'] ?? '' ) );
        $edit_email      = sanitize_email( wp_unslash( $_POST['gr_edit_email'] ?? '' ) );

        if ( $edit_id && $edit_first_name && $edit_last_name && is_email( $edit_email ) ) {
            $wpdb->update(
                $table,
                [
                    'first_name' => $edit_first_name,
                    'last_name'  => $edit_last_name,
                    'email'      => $edit_email,
                ],
                [ 'id' => $edit_id ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Reservation updated.', 'genesis-reservations' ) . '</p></div>';
        }
    }

    $edit_row = isset( $_GET['gr_edit'] ) ? absint( $_GET['gr_edit'] ) : 0;
    $results  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    ?>
    <div class="wrap gr-admin-wrap">
        <h1><?php esc_html_e( 'All Reservations', 'genesis-reservations' ); ?></h1>

        <?php if ( $results ) : ?>
        <table class="wp-list-table widefat fixed striped gr-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( '#', 'genesis-reservations' ); ?></th>
                    <th><?php esc_html_e( 'Name', 'genesis-reservations' ); ?></th>
                    <th><?php esc_html_e( 'Email', 'genesis-reservations' ); ?></th>
                    <th><?php esc_html_e( 'Event', 'genesis-reservations' ); ?></th>
                    <th><?php esc_html_e( 'Time', 'genesis-reservations' ); ?></th>
                    <th><?php esc_html_e( 'Place', 'genesis-reservations' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'genesis-reservations' ); ?></th>
                    <th><?php esc_html_e( 'Reserved', 'genesis-reservations' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'genesis-reservations' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $results as $row ) : ?>
                <?php if ( $edit_row === (int) $row->id ) : ?>
                <tr>
                    <td><?php echo esc_html( $row->id ); ?></td>
                    <td colspan="2">
                        <form method="post">
                            <?php wp_nonce_field( 'gr_edit_reservation', 'gr_edit_nonce' ); ?>
                            <input type="hidden" name="gr_edit_id" value="<?php echo esc_attr( $row->id ); ?>" />
                            <input type="text" name="gr_edit_first_name" value="<?php echo esc_attr( $row->first_name ); ?>" required />
                            <input type="text" name="gr_edit_last_name" value="<?php echo esc_attr( $row->last_name ); ?>" required />
                            <input type="email" name="gr_edit_email" value="<?php echo esc_attr( $row->email ); ?>" required />
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'genesis-reservations' ); ?></button>
                            <a href="<?php echo esc_url( remove_query_arg( 'gr_edit' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'genesis-reservations' ); ?></a>
                        </form>
                    </td>
                    <td><?php echo esc_html( $row->event_name ); ?></td>
                    <td><?php echo esc_html( $row->event_time ); ?></td>
                    <td><?php echo esc_html( $row->event_place ); ?></td>
                    <td><?php echo esc_html( $row->event_desc ); ?></td>
                    <td><?php echo esc_html( gmdate( 'Y-m-d H:i', strtotime( $row->created_at ) ) ); ?></td>
                    <td></td>
                </tr>
                <?php else : ?>
                <tr>
                    <td><?php echo esc_html( $row->id ); ?></td>
                    <td><?php echo esc_html( $row->first_name . ' ' . $row->last_name ); ?></td>
                    <td><?php echo esc_html( $row->email ); ?></td>
                    <td><?php echo esc_html( $row->event_name ); ?></td>
                    <td><?php echo esc_html( $row->event_time ); ?></td>
                    <td><?php echo esc_html( $row->event_place ); ?></td>
                    <td><?php echo esc_html( $row->event_desc ); ?></td>
                    <td><?php echo esc_html( gmdate( 'Y-m-d H:i', strtotime( $row->created_at ) ) ); ?></td>
                    <td>
                        <a href="<?php echo esc_url( add_query_arg( 'gr_edit', $row->id ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'genesis-reservations' ); ?></a>
                        <a href="<?php echo esc_url( add_query_arg( [ 'gr_delete' => $row->id, 'gr_delete_nonce' => wp_create_nonce( 'gr_delete_' . $row->id ) ] ) ); ?>"
                           class="button button-small button-link-delete"
                           onclick="return confirm('<?php esc_attr_e( 'Delete this reservation?', 'genesis-reservations' ); ?>')"
                        ><?php esc_html_e( 'Delete', 'genesis-reservations' ); ?></a>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
            <p><?php esc_html_e( 'No reservations found.', 'genesis-reservations' ); ?></p>
        <?php endif; ?>
    </div>
    <?php
}
