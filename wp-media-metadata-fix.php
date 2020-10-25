<?php
/**
 * Plugin Name:    WP Media Metadata Fix
 * Version:        1.0
 * Plugin URI:     https://wordpress.org/plugins/wp-media-metadata-fix/
 * Description:    Fixes the metadata of the images in the Media library.
 * Author:         Malik Naik
 * Author URI:     http://maliknaik.me/
 * License:        GNU General Public License v2 or later
 * Text Domain:    wp-media-metadata-fix
 */

error_reporting( E_ALL );

add_action( 'plugins_loaded', 'wpmmf_init' );
add_action( 'wp_loaded', 'wpmmf_init' );

function wpmmf_init() {
    add_filter( 'manage_upload_columns', 'wpmmf_media_metadata_column_name' );
    add_action( 'manage_media_custom_column', 'wpmmf_metadata_column', 10, 2 );
    add_action( 'wp_loaded', 'wpmmf_generate_metadata' );
    add_action( 'add_meta_boxes', 'wpmmf_metadata_fix_box' );
}

function wpmmf_metadata_fix_box() {
    add_meta_box(
        'wpmmf_metabox',
        __('Metadata'),
        'wpmmf_render_metabox',
        'attachment',
        'side'
    );
}

function wpmmf_render_metabox( $post ) {
    wpmmf_metadata_column( 'wpmmf_metadata', $post->ID );
}

function wpmmf_media_metadata_column_name( $columns ) {
    $columns['wpmmf_metadata'] = 'Metadata';

    return $columns;
}

function wpmmf_generate_metadata() {
    $attachment_id = NULL;

    if ( isset( $_GET['wpmmf_attachment_id'] ) ) {
        $attachment_id = $_GET['wpmmf_attachment_id'];
    }

    if ( isset( $_GET['wpmmf_nonce'] ) ) {
        $wpmmf_nonce = $_GET['wpmmf_nonce'];
    }

    if ( !empty( $attachment_id ) && !empty( $wpmmf_nonce ) ) {
        if ( wp_verify_nonce( $wpmmf_nonce, 'wpmmf_attachment_id' ) ) {

            $attached_file = get_post_meta( $attachment_id, '_wp_attached_file' );

            if ( array_key_exists( 'wpmmf_file', $_GET ) && count( $attached_file ) < 1 || $attached_file[0] === NULL ) {
                $meta_value = wpmmf_get_file_path( $attachment_id );
                add_post_meta( $attachment_id, '_wp_attached_file', $meta_value, true );
            }


            if ( !function_exists( 'wp_generate_attachment_metadata' ) || !function_exists( 'wp_update_attachment_metadata' ) ) {
                include( ABSPATH . 'wp-admin/includes/image.php' );
            }
            $metadata = wp_generate_attachment_metadata( $attachment_id, get_attached_file( $attachment_id ) );
            wp_update_attachment_metadata( $attachment_id, $metadata );
            error_log( "Fixed metadata for the attachment id $attachment_id" );
        }
    }

    $_SERVER['REQUEST_URI'] = remove_query_arg( array( 'wpmmf_attachment_id', 'wpmmf_nonce', 'wpmmf_file' ), $_SERVER['REQUEST_URI'] );
}

function wpmmf_get_file_path( $post_id ) {
    $image_url = get_the_guid( $post_id );
    $parsed_url = parse_url( $image_url );

    // echo wp_basename($image_url)['subdir'];
    $subdir = ltrim(wp_get_upload_dir()['subdir'], '/');
    $file_name = wp_basename($image_url);

    $file_path = path_join( $subdir, $file_name );

    return $file_path;
}

function wpmmf_get_missing_file_btn( $post_id ) {
    $output = '';
    $attached_file = get_post_meta( $post_id, '_wp_attached_file' );

    if ( count( $attached_file ) < 1 || $attached_file[0] === NULL ) {

        // Get the mime type of the post.
        $mime_type = get_post_mime_type( $post_id );

        if ( false !== strpos( $mime_type, 'image' ) ) {

            // Get allowed mime types.
            $allowed_mime_types = get_allowed_mime_types();

            if ( in_array( $mime_type, $allowed_mime_types ) ) {
                wpmmf_get_fix_button( $post_id, $output, 'Image meta key missing in the database.', 'Fix Image', true );
                return $output;
            }
        }
    } else {
        return false;
    }

}
function wpmmf_metadata_column( $column_name, $post_id ) {

    set_error_handler(function( $severity, $message, $file, $line ) {
        throw new ErrorException( $message, $severity, $severity, $file, $line );
    });

    try{

        $output = '';

        if ( 'wpmmf_metadata' !== $column_name || !wp_attachment_is_image( $post_id ) ) {

            if ( false !== ($btn = wpmmf_get_missing_file_btn( $post_id ) ) ) {
                echo $btn;
            }

            // $attached_file = get_post_meta( $post_id, '_wp_attached_file' );

            // if ( count( $attached_file ) < 1) {

            //     // Get the mime type of the post.
            //     $mime_type = get_post_mime_type( $post_id );

            //     if ( false !== strpos( $mime_type, 'image' ) ) {

            //         // Get allowed mime types.
            //         $allowed_mime_types = get_allowed_mime_types();

            //         if ( in_array( $mime_type, $allowed_mime_types ) ) {
            //             $image_url = get_the_guid( $post_id );
            //             $parsed_url = parse_url( $image_url );

            //             // echo wp_basename($image_url)['subdir'];
            //             $subdir = ltrim(wp_get_upload_dir()['subdir'], '/');
            //             $file_name = wp_basename($image_url);

            //             $file_path = path_join( $subdir, $file_name );
            //             // echo $parsed_url['path'];
            //             // echo 'allowed image';
            //             wpmmf_get_fix_button( $post_id, $output, 'Image meta key missing in the database.', 'Fix Image', true );

            //             echo $output;
            //         }
            //     }
            // }

            restore_error_handler();
            return;
        }


        $image_url = wp_get_attachment_image_src( $post_id, 'original' );

        if ( false === $image_url ) {
            wpmmf_get_fix_button( $post_id, $output, '<b>_wp_attached_file</b> meta key missing in the database' );
            restore_error_handler();

            echo $output;
            return;
        }

        $image_url = $image_url[0];

        if (!file_exists( get_attached_file( $post_id ) )) {
            $output .= 'File missing.';
            restore_error_handler();

            echo $output;
            return;
        }
        wpmmf_get_image_meta( $post_id, $output );
        restore_error_handler();

        echo $output;
        return;
    } catch( Exception $e ) {
        echo $e->getMessage();
    }
}

function wpmmf_get_image_meta( $post_id, &$output ) {

    // Get the image meta data.
    $image_metadata = get_post_meta( $post_id );

    if ( is_array( $image_metadata ) ) {

        // Check if the meta key '_wp_attached_file' exists and then get the file.
        if ( array_key_exists( '_wp_attached_file', $image_metadata ) &&
             count( $image_metadata['_wp_attached_file']) > 0 ) {
            $output .= sprintf( "<b>File:</b> %s<br/>", $image_metadata['_wp_attached_file'][0] );
        } else {
            $output .= sprintf("File name missing<br/>");
        }

        // Check if the meta key '_wp_attachment_metadata' exists then print the meta data of the image.
        if ( array_key_exists( '_wp_attachment_metadata', $image_metadata ) &&
             count( $image_metadata['_wp_attachment_metadata'] ) > 0 &&
             is_serialized( $image_metadata['_wp_attachment_metadata'][0] ) ) {

            // Get the unserialized metadata.
            $unserialized_metadata = maybe_unserialize( $image_metadata['_wp_attachment_metadata'][0] );

            if ( is_array( $unserialized_metadata ) ) {

                // Get width of the image.
                if ( array_key_exists( 'width', $unserialized_metadata ) ) {
                    $output .= sprintf("<b>Width</b>: %s px<br/>", $unserialized_metadata['width']);
                } else {
                    $output .= sprintf("<b>Width</b>: Missing</br>");
                }

                // Get height of the image.
                if ( array_key_exists( 'height', $unserialized_metadata ) ) {
                    $output .= sprintf("<b>Height</b>: %s px<br/>", $unserialized_metadata['height']);
                } else {
                    $output .= sprintf("<b>Height</b>: Missing</br>");
                }
            }

        } else {
            wpmmf_get_fix_button( $post_id, $output, 'Image metadata is missing in the database', 'Fix metadata' );
        }
    }
}

function wpmmf_get_fix_button( $post_id, &$output, $msg, $button_string, $insert_file = false ) {
    $output .= sprintf("<p>%s</p>", $msg);

    $paged = isset( $_GET['paged'] ) ? '&paged=' . $_GET['paged'] : "";
    $page = isset( $_GET['page'] ) ? '&page=' . $_GET['page'] : "";
    $post = isset( $_GET['post'] ) ? '&post=' . $_GET['post'] : "";
    $action = isset( $_GET['action'] ) ? '&action=' . $_GET['action'] : "";
    $item = isset( $_GET['item'] ) ? '&item=' . $_GET['item'] : "";
    $wpmmf_id = sprintf( "&wpmmf_attachment_id=%s", $post_id );
    $insert_file_qs = $insert_file ? "&wpmmf_file=1" : "";

    $url_path = sprintf( "?%s%s%s%s%s%s%s", $page, $item, $paged, $post, $action, $wpmmf_id, $insert_file_qs );
    $nonce_url = wp_nonce_url( $url_path, 'wpmmf_attachment_id', 'wpmmf_nonce' );
    $output .= sprintf("<a href='%s' class='button button-primary button-small'>%s</a>", $nonce_url, __( $button_string, 'wp-media-metadata-fix' ) );
}
// SELECT * FROM `wp_postmeta` WHERE `meta_key` like '_wp_attachment_metadata';
// SELECT * FROM `wp_postmeta` WHERE `meta_key` like '_wp_attach%';