<?php
/**
 * @package      MEDIA_REMOTE_FTP_PLUGIN
 * @copyright    Copyright (C) 2024-2025, media remote ftp plugin
 * @link         https://mehdifani.com
 * @since        1.0.0
 */

/**
 * Plugin Name: remote to download server
 * Description: upload wordpress media to download server and load them to your website.
 * Version: 1.0.1
 * Author: Mehdi fani
 */

defined( 'ABSPATH' ) || exit;

/**
 * Define Constants
 */
define( 'PP_FTP_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'PP_FTP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PP_FTP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );


if ( ! class_exists( 'PetporsFtpManager' ) ) {
	class PetporsFtpManager {

		protected $base_url = 'https://example.com/uploads';
		protected $ftp_conn = null;

		// FTP Constants Data
		protected $ftp_server = 'YOUR_FTP_SERVER_ADDRESS'; // IP ADDRESS OR DOMAIN
		protected $ftp_user = 'YOUR_FTP_USERNAME';
		protected $ftp_pass = 'YOUR_FTP_PASSWORD';

		private $all_files_transferred = false;

		public function __construct() {
			add_filter( 'wp_get_attachment_url', [ $this, 'replace_attachment_url' ] );
			add_filter( 'wp_calculate_image_srcset', [ $this, 'replace_image_srcset' ], 10, 5 );
			add_action( 'admin_init', [ $this, 'admin_init_hooks' ] );
		}

		public function __destruct() {
			if ( $this->ftp_conn ) {
				ftp_close( $this->ftp_conn );
				error_log( 'FTP connection closed' );
			}
		}

		public function admin_init_hooks() {
			add_filter( 'upload_dir', [ $this, 'change_upload_dir' ] );
			add_filter( 'wp_handle_upload', [ $this, 'handle_new_upload' ] );
			add_action( 'delete_attachment', [ $this, 'delete_image_and_sizes_from_ftp' ] );
		}

		/**
		 * @param $sources
		 * This method use to replace default srcset url to download host url.
		 *
		 * @return mixed
		 */
		public function replace_image_srcset( $sources ) {
			foreach ( $sources as &$source ) {
				$source['url'] = str_replace(
					wp_upload_dir()['baseurl'],
					$this->base_url,
					$source['url']
				);
			}

			return $sources;
		}

		/**
		 * @param $url
		 * ُThis method use to replace WP default media url to download host url.
		 *
		 * @return array|string|string[]
		 */
		public function replace_attachment_url( $url ) {
			$remote_base_url = $this->base_url;
			$local_base_url  = wp_upload_dir()['baseurl'];

			return str_replace( $local_base_url, $remote_base_url, $url );
		}

		/**
		 * @return false|resource
		 * This method use for connect to ftp server.
		 */
		private function connect_to_ftp_server() {
			if ( $this->ftp_conn ) {
				error_log( 'ftp has open.' );

				return $this->ftp_conn;
			}

			$ftp_conn = ftp_connect( $this->ftp_server );
			if ( ! $ftp_conn ) {
				error_log( 'Cannot connect to FTP server.' );

				return false;
			} else {
				error_log( 'connect to FTP server.' );
			}

			$login = ftp_login( $ftp_conn, $this->ftp_user, $this->ftp_pass );
			if ( ! $login ) {
				ftp_close( $ftp_conn );
				error_log( 'FTP login failed.' );

				return false;
			}

			ftp_pasv( $ftp_conn, true );

			$this->ftp_conn = $ftp_conn;

			return $this->ftp_conn;
		}

		/**
		 * @param $dirs
		 * This method use to change WP upload base url to ftp subdomain link.
		 * This is important for use WP this baseurl to show images from ftp host.
		 *
		 * @return mixed
		 */
		public function change_upload_dir( $dirs ) {
			$dirs['baseurl'] = $this->base_url;

			return $dirs;
		}

		/**
		 * @param $upload
		 * This method handle upload file data in WP.
		 * Check upload base directory and create it's to FTP server
		 *
		 * @return mixed
		 */
		public function handle_new_upload( $upload ) {

			$local_file = $upload['file'];
			$local_url  = $upload['url'];

			$remote_file = str_replace( wp_upload_dir()['basedir'], '', $local_file );

			$upload_base_url = str_replace( $remote_file, '', $local_url );
			$new_url         = str_replace( $upload_base_url, $this->base_url, $local_url );

			$upload['url'] = $new_url;

			$remote_dir = dirname( $remote_file );
			$dirs       = explode( '/', ltrim( $remote_dir, '/' ) );

			$this->ftp_conn = $this->connect_to_ftp_server();

			// Check FTP directory exists, if not exists then create directories.
			$path = '';
			foreach ( $dirs as $dir ) {
				$path .= '/' . $dir;
				if ( ! @ftp_chdir( $this->ftp_conn, $path ) ) {
					ftp_mkdir( $this->ftp_conn, $path );
				}
			}

			// Upload Main File To FTP Server.
			ftp_put( $this->ftp_conn, $remote_file, $local_file, FTP_BINARY );

			add_action( 'wp_generate_attachment_metadata', [ $this, 'manage_attachment_metadata' ], 10, 2 );

			add_filter( 'wp_calculate_image_srcset', function ( $sources ) {
				error_log( 'sources: ' . print_r( $sources, true ) );
			} );

			return $upload;
		}

		/**
		 * @param $metadata
		 * @param $attachment_id
		 * This Method is An Intermediate method To Merge 2 Method For One Action Hook (wp_generate_attachment_metadata).
		 * Method 1: Use To Save Image Sizes To FTP Server.
		 * Method 2: Use To Remove Images From WP Server (uploads folder).
		 *
		 * @return mixed
		 */
		public function manage_attachment_metadata( $metadata, $attachment_id ) {
			// Save image sizes to FTP server.
			$this->generate_image_sizes_for_thumbnail( $metadata, $attachment_id );

			// Then remove images from WP host (uploads folder).
			$this->delete_local_images( $metadata, $attachment_id );

			return $metadata;
		}

		/**
		 * @param $metadata
		 * @param $attachment_id
		 * When Upload Media With All Image Sizes On FTP Server, Then Check Upload Successfully For Any One.
		 * Then Remove Media From WP Local Server.
		 *
		 * @return mixed
		 */
		public function delete_local_images( $metadata, $attachment_id ) {
			$upload_dir = wp_upload_dir();
			$local_file = $upload_dir['basedir'] . '/' . get_post_meta( $attachment_id, '_wp_attached_file', true );

			if ( ! $this->all_files_transferred ) {
				error_log( "Not all files were transferred. Skipping deletion for ID: $attachment_id" );

				return $metadata;
			}

			if ( file_exists( $local_file ) ) {
				unlink( $local_file );
				error_log( "Local file deleted: $local_file" );
			}

			if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				$original_dir = dirname( $local_file );

				foreach ( $metadata['sizes'] as $size => $size_data ) {
					if ( isset( $size_data['file'] ) ) {
						$size_file = $original_dir . '/' . $size_data['file'];
						if ( file_exists( $size_file ) ) {
							unlink( $size_file );
							error_log( "Image size '$size' deleted: $size_file" );
						}
					}
				}
			}

			return $metadata;
		}

		/**
		 * @param $post_ID
		 * This Method Fire When Delete An Attachment From WP Library.
		 * When Delete Any Attachment In WP Then Remove From FTP Server.
		 *
		 * @return void
		 */
		public function delete_image_and_sizes_from_ftp( $post_ID ) {
			$file           = get_post_meta( $post_ID, '_wp_attached_file', true );
			$image_metadata = get_post_meta( $post_ID, '_wp_attachment_metadata', true );

			if ( ! $file ) {
				error_log( "No file metadata found for attachment ID: $post_ID" );

				return;
			}

			$ftp_conn = $this->connect_to_ftp_server();

			$remote_main_file = str_replace( wp_upload_dir()['basedir'], '', wp_upload_dir()['basedir'] . '/' . $file );
			$check_list       = ftp_nlist( $ftp_conn, dirname( $remote_main_file ) );

			if ( $check_list && in_array( $remote_main_file, $check_list ) ) {
				if ( ftp_delete( $ftp_conn, $remote_main_file ) ) {
					error_log( "Main file deleted from CDN: $remote_main_file" );
				} else {
					error_log( "Failed to delete main file from CDN: $remote_main_file" );
				}
			} else {
				error_log( 'main file not exist' );
			}

			if ( isset( $image_metadata['sizes'] ) ) {
				foreach ( $image_metadata['sizes'] as $size_name => $size_data ) {
					$size_file        = $size_data['file'];
					$remote_thumbnail = str_replace( wp_upload_dir()['basedir'], '', wp_upload_dir()['basedir'] . '/' . dirname( $file ) . '/' . $size_file );

					$image_list = ftp_nlist( $ftp_conn, dirname( $remote_thumbnail ) );
					if ( $image_list && in_array( $remote_thumbnail, $image_list ) ) {
						if ( ftp_delete( $ftp_conn, $remote_thumbnail ) ) {
							error_log( "Thumbnail size deleted from CDN: $remote_thumbnail" );
						} else {
							error_log( "Failed to delete thumbnail size from CDN: $remote_thumbnail" );
						}
					}
				}
			}

		}

		/**
		 * @param $metadata
		 * @param $attachment_id
		 * Generate all theme image sizes to download host, when image upload on WP.
		 * In this method check all image sizes successfully upload to download host.
		 *
		 * @return mixed
		 */
		public function generate_image_sizes_for_thumbnail( $metadata, $attachment_id ) {
			$file = get_post_meta( $attachment_id, '_wp_attached_file', true );

			if ( ! $file || ! isset( $metadata['sizes'] ) ) {
				error_log( 'No file or image sizes found for attachment ID: ' . $attachment_id );

				return $metadata;
			}

			// Connect To FTP
			$ftp_conn = $this->ftp_conn;

			// local variable to check all file successfully transferred.
			$all_files_transferred = true;

			// Upload Directory.
			$upload_dir_basedir = wp_upload_dir()['basedir'];

			// Generate WP and Theme image sizes on FTP server.
			foreach ( $metadata['sizes'] as $size ) {
				$size_file = $size['file'];

				if ( $size_file ) {
					$local_thumbnail  = $upload_dir_basedir . '/' . dirname( $file ) . '/' . $size_file;
					$remote_thumbnail = str_replace( $upload_dir_basedir, '', $local_thumbnail );


					if ( ! ftp_put( $ftp_conn, $remote_thumbnail, $local_thumbnail, FTP_BINARY ) ) {
						error_log( "Failed to transfer $size_file to remote server" );
						$all_files_transferred = false;
					} else {
						error_log( "Transferred $size_file to remote server" );
					}
				}
			}

			$this->all_files_transferred = $all_files_transferred;

			return $metadata;
		}
	}

	$petpors_ftp = new PetporsFtpManager();
}