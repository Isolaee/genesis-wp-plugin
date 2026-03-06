<?php
/**
 * Plugin Name: Genesis Reservations
 * Plugin URI:  https://github.com/eero/genesis-wp-plugin
 * Description: Event reservation system via shortcode. Usage: [reservation event="My Event" time="7:00 PM" place="City Hall" description="Join us!"]
 * Version:     1.1.0
 * Author:      Eero Isola
 * License:     GPL-2.0-or-later
 * Text Domain: genesis-reservations
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GR_VERSION', '1.1.0' );
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

        $texts = gr_texts();

        if ( empty( $first_name ) || empty( $last_name ) || empty( $email ) ) {
            $error = $texts['msg_required'];
        } elseif ( ! is_email( $email ) ) {
            $error = $texts['msg_invalid_email'];
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
                $message = str_replace(
                    [ '{first_name}', '{last_initial}' ],
                    [ esc_html( $first_name ), esc_html( strtoupper( substr( $last_name, 0, 1 ) ) ) ],
                    $texts['msg_success']
                );
            } else {
                $error = $texts['msg_error'];
            }
        }
    }

    $texts    = gr_texts();
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
                    <li><span class="gr-meta-label"><?php echo esc_html( $texts['label_time'] ); ?></span> <?php echo esc_html( $event_time ); ?></li>
                <?php endif; ?>
                <?php if ( $event_place ) : ?>
                    <li><span class="gr-meta-label"><?php echo esc_html( $texts['label_place'] ); ?></span> <?php echo esc_html( $event_place ); ?></li>
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

        <?php
        // ---- Fetch attendees (always, before form) ----
        global $wpdb;
        $table = $wpdb->prefix . GR_TABLE_NAME;

        // Admin-only: handle inline edit
        if ( $is_admin ) :
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

            // Admin-only: handle delete
            if (
                isset( $_GET['gr_delete'] ) && isset( $_GET['gr_delete_nonce'] ) &&
                wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['gr_delete_nonce'] ) ), 'gr_delete_' . absint( $_GET['gr_delete'] ) )
            ) {
                $wpdb->delete( $table, [ 'id' => absint( $_GET['gr_delete'] ) ], [ '%d' ] );
            }
        endif;

        $edit_row = $is_admin && isset( $_GET['gr_edit'] ) ? absint( $_GET['gr_edit'] ) : 0;

        $where   = $event_name ? $wpdb->prepare( 'WHERE event_name = %s', $event_name ) : '';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC" );
        $count   = count( $results );
        ?>

        <?php if ( ! $message ) : ?>
        <form class="gr-form" method="post">
            <?php wp_nonce_field( 'gr_reservation_' . $event_name, 'gr_nonce' ); ?>

            <div class="gr-field">
                <label for="gr_first_name"><?php echo esc_html( $texts['label_first_name'] ); ?> <span aria-hidden="true">*</span></label>
                <input type="text" id="gr_first_name" name="gr_first_name"
                    value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_POST['gr_first_name'] ?? '' ) ) ); ?>"
                    required autocomplete="given-name" />
            </div>

            <div class="gr-field">
                <label for="gr_last_name"><?php echo esc_html( $texts['label_last_name'] ); ?> <span aria-hidden="true">*</span></label>
                <input type="text" id="gr_last_name" name="gr_last_name"
                    value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_POST['gr_last_name'] ?? '' ) ) ); ?>"
                    required autocomplete="family-name" />
            </div>

            <div class="gr-field">
                <label for="gr_email"><?php echo esc_html( $texts['label_email'] ); ?> <span aria-hidden="true">*</span></label>
                <input type="email" id="gr_email" name="gr_email"
                    value="<?php echo esc_attr( sanitize_email( wp_unslash( $_POST['gr_email'] ?? '' ) ) ); ?>"
                    required autocomplete="email" />
            </div>

            <button type="submit" class="gr-submit"><?php echo esc_html( $texts['btn_submit'] ); ?></button>
        </form>
        <?php endif; ?>

        <div class="gr-attendees">
            <h3 class="gr-attendees-title">
                <?php
                if ( $is_admin ) {
                    printf(
                        esc_html__( 'Reservations%s', 'genesis-reservations' ),
                        $event_name ? ': ' . esc_html( $event_name ) : ''
                    );
                } else {
                    esc_html_e( 'Attending', 'genesis-reservations' );
                }
                ?>
                <span class="gr-admin-count">(<?php echo $count; ?>)</span>
            </h3>

            <?php if ( $results ) : ?>
            <div class="gr-table-wrap">
                <table class="gr-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?php esc_html_e( 'Name', 'genesis-reservations' ); ?></th>
                            <?php if ( $is_admin ) : ?>
                            <th><?php esc_html_e( 'First Name', 'genesis-reservations' ); ?></th>
                            <th><?php esc_html_e( 'Last Name', 'genesis-reservations' ); ?></th>
                            <th><?php esc_html_e( 'Email', 'genesis-reservations' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'genesis-reservations' ); ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $results as $i => $row ) : ?>
                        <?php if ( $is_admin && $edit_row === (int) $row->id ) : ?>
                        <tr class="gr-edit-row">
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo esc_html( $row->first_name . ' ' . strtoupper( substr( $row->last_name, 0, 1 ) ) . '.' ); ?></td>
                            <td colspan="3">
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
                            <td></td>
                        </tr>
                        <?php else : ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo esc_html( $row->first_name . ' ' . strtoupper( substr( $row->last_name, 0, 1 ) ) . '.' ); ?></td>
                            <?php if ( $is_admin ) : ?>
                            <td><?php echo esc_html( $row->first_name ); ?></td>
                            <td><?php echo esc_html( $row->last_name ); ?></td>
                            <td><?php echo esc_html( $row->email ); ?></td>
                            <td class="gr-actions">
                                <a href="<?php echo esc_url( add_query_arg( 'gr_edit', $row->id ) ); ?>" class="gr-btn gr-btn--edit"><?php esc_html_e( 'Edit', 'genesis-reservations' ); ?></a>
                                <a href="<?php echo esc_url( add_query_arg( [ 'gr_delete' => $row->id, 'gr_delete_nonce' => wp_create_nonce( 'gr_delete_' . $row->id ) ] ) ); ?>"
                                   class="gr-btn gr-btn--delete"
                                   onclick="return confirm('<?php esc_attr_e( 'Delete this reservation?', 'genesis-reservations' ); ?>')"
                                ><?php esc_html_e( 'Delete', 'genesis-reservations' ); ?></a>
                            </td>
                            <?php endif; ?>
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

    </div>
    <?php

    return ob_get_clean();
}

// ---------------------------------------------------------------------------
// Text customization helpers
// ---------------------------------------------------------------------------
function gr_texts() {
    $defaults = [
        'label_first_name'    => 'First Name',
        'label_last_name'     => 'Last Name',
        'label_email'         => 'Email Address',
        'label_time'          => 'Time:',
        'label_place'         => 'Place:',
        'btn_submit'          => 'Reserve My Spot',
        'msg_success'         => 'Thank you, {first_name} {last_initial}.! Your reservation is confirmed.',
        'msg_required'        => 'All fields are required.',
        'msg_invalid_email'   => 'Please enter a valid email address.',
        'msg_error'           => 'Something went wrong. Please try again.',
    ];
    $saved = get_option( 'gr_texts', [] );
    return wp_parse_args( $saved, $defaults );
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
    add_submenu_page(
        'genesis-reservations',
        __( 'Text Settings', 'genesis-reservations' ),
        __( 'Text Settings', 'genesis-reservations' ),
        'manage_options',
        'genesis-reservations-texts',
        'gr_texts_page'
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

// ---------------------------------------------------------------------------
// Text Settings page
// ---------------------------------------------------------------------------
function gr_texts_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if (
        isset( $_POST['gr_texts_nonce'] ) &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gr_texts_nonce'] ) ), 'gr_save_texts' )
    ) {
        $fields = [
            'label_first_name', 'label_last_name', 'label_email',
            'label_time', 'label_place', 'btn_submit',
            'msg_success', 'msg_required', 'msg_invalid_email', 'msg_error',
        ];
        $saved = [];
        foreach ( $fields as $key ) {
            $saved[ $key ] = sanitize_text_field( wp_unslash( $_POST[ 'gr_' . $key ] ?? '' ) );
        }
        update_option( 'gr_texts', $saved );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'genesis-reservations' ) . '</p></div>';
    }

    $t = gr_texts();

    $fields = [
        'label_first_name'  => 'Form label — First Name',
        'label_last_name'   => 'Form label — Last Name',
        'label_email'       => 'Form label — Email',
        'label_time'        => 'Event meta label — Time',
        'label_place'       => 'Event meta label — Place',
        'btn_submit'        => 'Submit button text',
        'msg_success'       => 'Success message (use {first_name} and {last_initial})',
        'msg_required'      => 'Error — fields required',
        'msg_invalid_email' => 'Error — invalid email',
        'msg_error'         => 'Error — database failure',
    ];
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Reservation Text Settings', 'genesis-reservations' ); ?></h1>
        <p><?php esc_html_e( 'Customize all user-facing text displayed by the [reservation] shortcode.', 'genesis-reservations' ); ?></p>

        <form method="post">
            <?php wp_nonce_field( 'gr_save_texts', 'gr_texts_nonce' ); ?>
            <table class="form-table" role="presentation">
                <?php foreach ( $fields as $key => $label ) : ?>
                <tr>
                    <th scope="row">
                        <label for="gr_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
                    </th>
                    <td>
                        <input
                            type="text"
                            id="gr_<?php echo esc_attr( $key ); ?>"
                            name="gr_<?php echo esc_attr( $key ); ?>"
                            value="<?php echo esc_attr( $t[ $key ] ); ?>"
                            class="regular-text"
                        />
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php submit_button( __( 'Save Text Settings', 'genesis-reservations' ) ); ?>
        </form>
    </div>
    <?php
}
