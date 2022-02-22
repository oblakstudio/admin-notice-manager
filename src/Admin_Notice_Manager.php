<?php
/**
 * Admin_Notice_Manager class file
 *
 * @package Admin Notice Manager
 */

namespace Oblak;

/**
 * Admin notice manager is used to manage admin notices.
 */
class Admin_Notice_Manager {


    /**
     * Module version
     *
     * @var string
     */
    public $version = '1.0.2';

    /**
     * Class singleton instance
     *
     * @var Admin_Notice_Manager
     */
    private static $instance = null;

    /**
     * Admin notices
     *
     * @var array
     */
    private $notices = array();

    /**
     * Default notice types.
     *
     * If a registered notice type is not in this array, we expect a hex color.
     *
     * @var array
     */
    private $notice_types = array(
        'success',
        'error',
        'warning',
        'info',
    );

    /**
     * Retrieve the singleton instance
     *
     * @return Admin_Notice_Manager
     */
    public static function get_instance() {
        return ( is_null( self::$instance ) ) ? self::$instance = new Admin_Notice_Manager() : self::$instance;
    }

    /**
     * Prevent cloning
     */
    private function __clone() {
        _doing_it_wrong( __FUNCTION__, 'Cheatin&#8217; huh?', esc_html( $this->version ) );
    }

    /**
     * Prevent unserializing
     */
    public function __wakeup() {
        _doing_it_wrong( __FUNCTION__, 'Cannot unserialize singleton', esc_html( $this->version ) );
    }

    /**
     * Check if we're running from action scheduler
     */
    private function is_running_from_action_scheduler() {
        //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return isset( $_REQUEST['action'] ) && 'as_async_request_queue_runner' === $_REQUEST['action'];
    }

    /**
     * Class constructor
     */
    private function __construct() {
        $this->notices = get_option( 'admin_notice_manager_notices', array() );

        if ( ! $this->is_running_from_action_scheduler() ) {
            add_action( 'shutdown', array( $this, 'store_notices' ) );
        }

        add_action( 'admin_print_styles', array( $this, 'add_notices' ) );
        add_action( 'wp_loaded', array( $this, 'hide_notices' ) );
    }

    /**
     * Stores notices to database
     */
    public function store_notices() {
        update_option( 'admin_notice_manager_notices', $this->get_notices() );
    }

    /**
     * Retrieves the stored notices
     *
     * @return array             Array of notices
     */
    public function get_notices() {
        return $this->notices;
    }

    /**
     * Removes all the notices.
     */
    public function remove_all_notices() {
        $this->notices = array();
    }

    /**
     * Checks if a notice is registered
     *
     * @param  string $name Notice name.
     * @return boolean      True if the notice is registered, false otherwise.
     */
    public function has_notice( $name ) {
        return in_array( $name, array_keys( $this->notices ), true );
    }

    /**
     * Remove a notice from being displayed.
     *
     * @param string $name Notice name.
     * @param bool   $force_save  Force saving inside this method instead of at the 'shutdown'.
     */
    public function remove_notice( $name, $force_save = false ) {
        unset( $this->notices[ $name ] );
        delete_option( 'admin_notice_manager_notice_' . $name );

        if ( $force_save ) {
            // Adding early save to prevent more race conditions with notices.
            $this->store_notices();
        }
    }

    /**
     * Adds a notice to the notice manager.
     *
     * @param  string $name       Notice name.
     * @param  array  $args       Notice arguments.
     * @param  bool   $force_save Force the notice to be added if it's already registered.
     * @return bool               True if the notice was added, false otherwise.
     */
    public function add_notice( $name, $args, $force_save = false ) {
        if ( $this->has_notice( $name ) && ! $force_save ) {
            return false;
        }

        $defaults = array(
            'type'        => 'info',
            'caps'        => 'manage_options',
            'message'     => '',
            'screen_ids'  => array(),
            'post_ids'    => array(),
            'dismissible' => true,
            'persistent'  => true,
            'dismiss_all' => false,
        );

        $args = wp_parse_args( $args, $defaults );

        // If type is not a hex color, we expect it to be a registered notice type.
        if ( ! in_array( $args['type'], $this->notice_types, true ) && ! preg_match( '/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $args['type'] ) ) {
            return false;
        }

        $this->notices[ $name ] = $args;

        if ( $force_save ) {
            $this->store_notices();
        }

        return true;
    }

    /**
     * Hides (dismisses) a notice.
     */
    public function hide_notices() {

        if ( ! isset( $_GET['amn-dismiss-all'] ) && ! isset( $_GET['amn-dismiss'] ) ) { //phpcs:disable WordPress.Security.NonceVerification.Recommended
            return;
        }

        if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_amn_notice_nonce'] ?? '' ) ), 'amn_hide_notice_nonce' ) ) {
            return;
        }

        $dismiss_for_all = isset( $_GET['amn-dismiss-all'] ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $hide_notice = sanitize_text_field( wp_unslash( $_GET['amn-dismiss-all'] ?? $_GET['amn-dismiss'] ) );

        $this->remove_notice( $hide_notice );

        if ( $dismiss_for_all ) {
            update_option( "amn_hide_{$hide_notice}_notice", true );
        } else {
            update_user_meta( get_current_user_id(), "amn_hide_{$hide_notice}_notice", true );
        }

        do_action( "amn_hide_{$hide_notice}_notice" );
    }

    /**
     * Adds notices to dashboard
     */
    public function add_notices() {
        $notices = $this->get_notices();

        if ( empty( $notices ) ) {
            return;
        }

        $screen    = get_current_screen();
        $screen_id = $screen ? $screen->id : '';

        foreach ( $notices as $name => $args ) {
            if ( ! empty( $args['screen_ids'] ) && ! in_array( $screen_id, $args['screen_ids'], true ) ) {
                continue;
            }

            if ( ! empty( $args['post_ids'] ) && ! in_array( get_the_ID(), $args['post_ids'], true ) ) {
                continue;
            }

            if ( ! current_user_can( $args['caps'] ) ) {
                continue;
            }

            $notice_dismissed = $args['dismiss_all']
                ? get_option( "amn_hide_{$name}_notice", false )
                : get_user_meta( get_current_user_id(), "amn_hide_{$name}_notice", true );

            if ( $notice_dismissed ) {
                continue;
            }

            $this->render_notice( $name, $args );

            if ( ! $args['persistent'] ) {
                $this->remove_notice( $name );
            }
        }
    }

    /**
     * Renders a notice to the screen.
     *
     * @param  string $name Notice name.
     * @param  array  $args Notice arguments.
     */
    private function render_notice( $name, $args ) {
        $action  = '';
        $style   = '';
        $classes = array(
            'notice',
        );

        if ( in_array( $args['type'], $this->notice_types, true ) ) {
            $classes[] = 'notice-' . $args['type'];
        } else {
            $style = "border-left-color: {$args['type']} !important;";
        }

        if ( $args['dismiss_all'] ) {
            $classes[] = 'is-dismissible';
            $action    = 'amn-dismiss-all';
        }

        if ( $args['dismissible'] ) {
            $classes[] = 'is-dismissible';
            $action    = 'amn-dismiss';
        }

        $classes = apply_filters( 'admin_notice_manager_notice_classes', $classes, $name, $args );
        $classes = array_map( 'sanitize_html_class', $classes );
        $classes = array_unique( $classes );
        $classes = implode( ' ', $classes );

        $message = apply_filters( 'admin_notice_manager_notice_message', $this->get_message( $args ), $name, $args );

        if ( '' !== $action ) {
            $dismiss_url = esc_url( wp_nonce_url( add_query_arg( $action, $name ), 'amn_hide_notice_nonce', '_amn_notice_nonce' ) );
            $message    .= sprintf(
                '<a href="%s" class="notice-dismiss" style="text-decoration: none"></a>',
                $dismiss_url,
            );
        }

        add_action(
            'admin_notices',
            function() use ( $name, $classes, $style, $message ) {
                printf(
                    '<div id="%s" class="%s" style="%s">%s</div>',
                    esc_attr( 'notice-' . $name ),
                    esc_attr( $classes ),
                    esc_attr( $style ),
                    wp_kses_post( $message ),
                );
            },
            10
        );

        if ( ! $args['persistent'] ) {
            $this->remove_notice( $name, true );
        }

    }

    /**
     * Get notice message
     *
     * Will check if the message is a callable function or a file path.
     *
     * @param  array $args Notice arguments.
     * @return string      Notice message.
     */
    private function get_message( $args ) {
        if ( is_callable( $args['message'] ) ) {
            return call_user_func( $args['message'] );
        }

        if ( file_exists( $args['message'] ) ) {
            ob_start();
            require $args['message'];
            return ob_get_clean();
        }

        return $args['message'];
    }
}
