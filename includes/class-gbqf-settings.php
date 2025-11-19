<?php
namespace GBQF;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings {
    const OPTION_KEY = 'gbqf_enable_metabox_integration';

    public function __construct() {
    }

    /**
     * Check if Meta Box integration is enabled.
     *
     * @return bool
     */
    public static function is_metabox_enabled() {
        return (bool) get_option( self::OPTION_KEY, '1' );
    }
}
