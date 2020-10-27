<?php
/**
 * Plugin Name:    WP Media Metadata Fix
 * Plugin URI:     https://wordpress.org/plugins/wp-media-metadata-fix/
 * Description:    Fixes the metadata of the images in the Media library.
 * Version:        1.0
 * Author:         Malik Naik
 * Author URI:     http://maliknaik.me/
 * License:        GNU General Public License v3
 * License URI:    https://www.gnu.org/licenses/gpl-3.0.en.html
 * Text Domain:    wp-media-metadata-fix
 * Domain Path:    /languages
 */

// Make sure we don't expose any info if called directly
defined('ABSPATH') || exit;

class WPMediaMetadataFix {

    /**
     * Instantiate the object.
     */
    public function __construct() {
        add_action( 'plugins_loaded', [ $this, 'initialize' ] );
        add_action( 'wp_loaded', [ $this, 'initialize' ] );
    }

    /**
     * Initialize the plugin.
     */
    public function initialize() {
        add_filter( 'manage_upload_columns', [ $this, 'addMetadataColumn' ] );
        add_action( 'manage_media_custom_column', [ $this, 'renderMetadata' ], 10, 2 );
        add_action( 'wp_loaded', [ $this, 'fixMetadata' ] );
        add_action( 'add_meta_boxes', [ $this, 'addMetadataMetaBox' ] );
    }

    /**
     * Adds a metadata column to the media library listing.
     *
     * @param array $columns
     *
     * @return array
     */
    public function addMetadataColumn( $columns ) {
        $columns['wpmmf_metadata'] = 'Metadata';

        return $columns;
    }

    /**
     * Renders the metadata.
     *
     * @param string $column_name
     * @param int    $post_id
     *
     * @return void
     */
    public function renderMetadata( $column_name, $post_id ) {

        // Set the error handler to catch warnings.
        set_error_handler(function( $severity, $message, $file, $line ) {
            throw new \ErrorException( $message, $severity, $severity, $file, $line );
        });

        try{
            $output = '';

            if ( 'wpmmf_metadata' !== $column_name || !wp_attachment_is_image( $post_id ) ) {

                if ( false !== ($btn = $this->getMissingFileBtn( $post_id ) ) ) {
                    // Render fix image button.
                    _e( $btn, 'wp-media-metadata-fix' );
                }

                // Restore the error handler.
                restore_error_handler();
                return;
            }

            // Get the image url from post id.
            $image_url = wp_get_attachment_image_src( $post_id, 'original' );

            if ( false === $image_url ) {
                $this->renderFixButton( $post_id, $output, '<b>_wp_attached_file</b> meta key missing in the database' );
                restore_error_handler();

                // Render fix image meta key button.
                _e( $output, 'wp-media-metadata-fix' );
                return;
            }

            // Get the image url.
            $image_url = $image_url[0];

            if (!file_exists( get_attached_file( $post_id ) )) {
                $output .= 'Original File missing.';
                restore_error_handler();

                // Render the missing file message.
                _e( $output, 'wp-media-metadata-fix' );
                return;
            }

            // Get the image metadata into the $output.
            $this->getImageMetadata( $post_id, $output );

            // Restore the error handler.
            restore_error_handler();

            // Render the image metadata.
            _e( $output, 'wp-media-metadata-fix' );
            return;

        } catch( \Exception $e ) {
            // Render the exception.
            _e( $e->getMessage(), 'wp-media-metadata-fix' );
        }
    }

    /**
     * Fixes the metadata of the attachment.
     *
     * @return void
     */
    public function fixMetadata() {
        $attachment_id = NULL;

        if ( isset( $_GET['wpmmf_attachment_id'] ) ) {
            // Get the attachement id from the query string.
            $filtered_attachment_id = filter_input(INPUT_GET, 'wpmmf_attachment_id', FILTER_VALIDATE_INT); // $_GET['wpmmf_attachment_id'];

            if ( $filtered_attachment_id !== false ) {
                $attachment_id = $filtered_attachment_id;
            }
        }

        if ( isset( $_GET['wpmmf_nonce'] ) ) {
            // Get the nonce from the query string.
            $wpmmf_nonce = $_GET['wpmmf_nonce'];
        }

        if ( !empty( $attachment_id ) && !empty( $wpmmf_nonce ) ) {
            if ( wp_verify_nonce( $wpmmf_nonce, 'wpmmf_attachment_id' ) ) {

                // Get the post meta with the meta key `_wp_attached_file`.
                $attached_file = get_post_meta( $attachment_id, '_wp_attached_file' );

                if ( array_key_exists( 'wpmmf_file', $_GET ) && count( $attached_file ) < 1 || $attached_file[0] === NULL ) {
                    // Get the file path from the attachment/post id.
                    $meta_value = $this->getFilePathFromPostID( $attachment_id );

                    // Insert a new post meta with meta key `_wp_attached_file`.
                    add_post_meta( $attachment_id, '_wp_attached_file', $meta_value, true );
                }


                if ( !function_exists( 'wp_generate_attachment_metadata' ) || !function_exists( 'wp_update_attachment_metadata' ) ) {
                    include( ABSPATH . 'wp-admin/includes/image.php' );
                }

                // Generate the attachment metadata.
                $metadata = wp_generate_attachment_metadata( $attachment_id, get_attached_file( $attachment_id ) );

                // Update the attachment metadata.
                wp_update_attachment_metadata( $attachment_id, $metadata );

                // Log the fixed attachment id.
                error_log( "Fixed metadata for the attachment id $attachment_id" );
            }
        }

        // Remove some query string from the URI.
        $_SERVER['REQUEST_URI'] = remove_query_arg( array( 'wpmmf_attachment_id', 'wpmmf_nonce', 'wpmmf_file' ), $_SERVER['REQUEST_URI'] );
    }


    /**
     * Adds the meta box to the attachment page to the right.
     *
     * @return void
     */
    public function addMetadataMetaBox() {
        add_meta_box( 'wpmmf_metabox', __( 'Metadata' ), [ $this, 'renderMetabox' ], 'attachment', 'side' );
    }

    /**
     * Renders the metadata in the meta box.
     *
     * @param object $post
     *
     * @return void
     */
    public function renderMetabox( $post ) {
        $this->renderMetadata( 'wpmmf_metadata', $post->ID );
    }

    /**
     * Returns the relative path from the post id of the attachment.
     *
     * @param int $post_id
     *
     * @return string
     */
    public function getFilePathFromPostID( $post_id ) {
        // Get the image url.
        $image_url = get_the_guid( $post_id );

        // Parse the url.
        $parsed_url = parse_url( $image_url );

        // Get the subdirectory of the image.
        $subdir = ltrim(wp_get_upload_dir()['subdir'], '/');

        // Get the filename of the image.
        $file_name = wp_basename($image_url);

        // Join the subdirectory and the filename.
        $file_path = path_join( $subdir, $file_name );

        return $file_path;
    }

    /**
     * Returns the fix image button.
     *
     * @param int $post_id
     *
     * @return bool|string
     */
    public function getMissingFileBtn( $post_id ) {
        $output = '';
        $attached_file = get_post_meta( $post_id, '_wp_attached_file' );

        if ( count( $attached_file ) < 1 || $attached_file[0] === NULL ) {

            // Get the mime type of the post.
            $mime_type = get_post_mime_type( $post_id );

            if ( false !== strpos( $mime_type, 'image' ) ) {

                // Get allowed mime types.
                $allowed_mime_types = get_allowed_mime_types();

                if ( in_array( $mime_type, $allowed_mime_types ) ) {
                    $this->renderFixButton( $post_id, $output, 'Image meta key missing in the database.', 'Fix Image', true );
                    return $output;
                }
            }
        } else {
            return false;
        }

    }

    /**
     * Initializes the html string for the image metadata.
     *
     * @param int    $post_id
     * @param string &$output
     *
     * @return void
     */
    public function getImageMetadata( $post_id, &$output ) {

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
                $this->renderFixButton( $post_id, $output, 'Image metadata is missing in the database', 'Fix metadata' );
            }
        }
    }

    /**
     * Renders the button to fix the image metadata.
     *
     * @param  int       $post_id
     * @param  string    &$output
     * @param  string    $msg
     * @param  string    $button_string
     * @param  bool      $insert_file
     *
     * @return bool|string
     */
    public function renderFixButton( $post_id, &$output, $msg, $button_string, $insert_file = false ) {

        $output .= sprintf("<p>%s</p>", $msg);

        $paged = isset( $_GET['paged'] ) ? '&paged=' . sanitize_text_field( $_GET['paged'] ) : "";
        $page = isset( $_GET['page'] ) ? '&page=' . sanitize_text_field( $_GET['page'] ) : "";
        $post = isset( $_GET['post'] ) ? '&post=' . sanitize_text_field( $_GET['post'] ) : "";
        $action = isset( $_GET['action'] ) ? '&action=' . sanitize_text_field( $_GET['action'] ) : "";
        $item = isset( $_GET['item'] ) ? '&item=' . sanitize_text_field( $_GET['item'] ) : "";
        $wpmmf_id = sprintf( "&wpmmf_attachment_id=%s", $post_id );
        $insert_file_qs = $insert_file ? "&wpmmf_file=1" : "";

        $url_path = sprintf( "?%s%s%s%s%s%s%s", $page, $item, $paged, $post, $action, $wpmmf_id, $insert_file_qs );
        $nonce_url = wp_nonce_url( $url_path, 'wpmmf_attachment_id', 'wpmmf_nonce' );
        $output .= sprintf("<a href='%s' class='button button-primary button-small'>%s</a>", esc_url( $nonce_url ), __( $button_string, 'wp-media-metadata-fix' ) );
    }

}

$wpmmf = new WPMediaMetadataFix();
