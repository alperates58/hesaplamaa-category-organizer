<?php
namespace HCO;

if ( ! defined( 'ABSPATH' ) ) exit;

spl_autoload_register( function ( string $class ): void {
    if ( strpos( $class, 'HCO\\' ) !== 0 ) {
        return;
    }

    $relative = substr( $class, 4 );
    $parts    = explode( '\\', $relative );
    $file     = 'class-' . strtolower( str_replace( '_', '-', array_pop( $parts ) ) ) . '.php';
    $dir      = HCO_INCLUDES . implode( DIRECTORY_SEPARATOR, array_map( fn($p) => strtolower($p), $parts ) ) . DIRECTORY_SEPARATOR;

    $path = $dir . $file;
    if ( file_exists( $path ) ) {
        require_once $path;
    }
} );
