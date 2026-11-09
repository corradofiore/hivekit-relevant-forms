<?php
/**
 * Upload HTTP endpoints (authorize, local upload, complete, download).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Http;

use RelevantForms\Forms\FormRepository;
use RelevantForms\Support\Logger;
use RelevantForms\Uploads\Storage\LocalStorageProvider;
use RelevantForms\Uploads\UploadService;

/**
 * admin-post endpoints for the upload lifecycle (doc §51).
 */
final class UploadController {

	/**
	 * Register endpoints.
	 */
	public static function register(): void {
		add_action( 'admin_post_nopriv_relevant_forms_upload_authorize', [ self::class, 'authorize' ] );
		add_action( 'admin_post_relevant_forms_upload_authorize', [ self::class, 'authorize' ] );
		add_action( 'admin_post_nopriv_relevant_forms_local_upload', [ self::class, 'localUpload' ] );

		/*
		 * Logged-in submissions need this variant: admin-post.php dispatches
		 * admin_post_{action} for authenticated users, and answers 400 when no
		 * handler is registered. Without it, uploads fail for any logged-in
		 * visitor — including the editor's own form preview.
		 */
		add_action( 'admin_post_relevant_forms_local_upload', [ self::class, 'localUpload' ] );
		add_action( 'admin_post_nopriv_relevant_forms_upload_complete', [ self::class, 'complete' ] );
		add_action( 'admin_post_relevant_forms_upload_complete', [ self::class, 'complete' ] );
		add_action( 'admin_post_relevant_forms_download_upload', [ self::class, 'download' ] );
		add_action( 'admin_post_relevant_forms_rescan_upload', [ self::class, 'rescan' ] );
	}

	/**
	 * Authorize an upload and return the authorization as JSON.
	 */
	public static function authorize(): void {
		$raw    = wp_unslash( $_POST );
		$formId = isset( $raw['form_id'] ) ? absint( $raw['form_id'] ) : 0;

		if ( $formId <= 0 || ! isset( $raw['_relevant_upload_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( $raw['_relevant_upload_nonce'] ), 'relevant_upload_' . $formId ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'hivekit-relevant-forms' ) ], 403 );
		}

		$form = ( new FormRepository() )->findPublished( $formId );

		if ( null === $form ) {
			wp_send_json_error( [ 'message' => __( 'Form not found.', 'hivekit-relevant-forms' ) ], 404 );
		}

		$result = ( new UploadService() )->authorize(
			$form,
			sanitize_key( (string) ( $raw['field_id'] ?? '' ) ),
			sanitize_file_name( (string) ( $raw['filename'] ?? '' ) ),
			sanitize_mime_type( (string) ( $raw['content_type'] ?? '' ) ),
			isset( $raw['size'] ) ? absint( $raw['size'] ) : 0,
		);

		if ( isset( $result['error'] ) ) {
			wp_send_json_error( [ 'message' => $result['error'] ], 422 );
		}

		$authorization = $result['authorization'];

		wp_send_json_success(
			[
				'upload_id' => $result['upload_id'],
				'token'     => $result['token'],
				'method'    => $authorization->method,
				'url'       => $authorization->url,
				'headers'   => $authorization->headers,
			]
		);
	}

	/**
	 * Receive a locally uploaded file (multipart) into the private tree.
	 *
	 * Two independent gates: the per-form nonce proves the request came from a
	 * page this site rendered, and the per-upload token authorizes this one
	 * upload ID.
	 */
	public static function localUpload(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below, before the payload is used.
		$raw      = wp_unslash( $_POST );
		$formId   = isset( $raw['form_id'] ) ? absint( $raw['form_id'] ) : 0;
		$nonce    = isset( $raw['_relevant_upload_nonce'] ) ? sanitize_text_field( (string) $raw['_relevant_upload_nonce'] ) : '';
		$uploadId = isset( $raw['upload_id'] ) ? absint( $raw['upload_id'] ) : 0;
		$token    = sanitize_text_field( (string) ( $raw['token'] ?? '' ) );

		if ( $formId <= 0 || '' === $nonce || ! wp_verify_nonce( $nonce, 'relevant_upload_' . $formId ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'hivekit-relevant-forms' ) ], 403 );
		}

		if ( $uploadId <= 0 || '' === $token ) {
			wp_send_json_error( [ 'message' => __( 'Invalid upload request.', 'hivekit-relevant-forms' ) ], 400 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce verified above; $_FILES content is validated by FileValidator.
		if ( ! isset( $_FILES['file'] ) || UPLOAD_ERR_OK !== (int) $_FILES['file']['error'] ) {
			wp_send_json_error( [ 'message' => __( 'No file received.', 'hivekit-relevant-forms' ) ], 400 );
		}

		$result = ( new UploadService() )->storeLocalUpload(
			$uploadId,
			$token,
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- temp path is PHP-controlled; content validated server-side.
			(string) $_FILES['file']['tmp_name'],
			self::uploadedFileMime()
		);

		if ( isset( $result['error'] ) ) {
			wp_send_json_error( [ 'message' => $result['error'] ], 422 );
		}

		wp_send_json_success( [ 'upload_id' => $uploadId ] );
	}

	/**
	 * Server-derived MIME type for the uploaded file.
	 *
	 * The browser-provided type is attacker-controlled, so the type is
	 * resolved from the sanitized file name with wp_check_filetype();
	 * FileValidator then sniffs the bytes for the stored detected_mime.
	 */
	private static function uploadedFileMime(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce verified by the caller; the name is sanitized below.
		$name = isset( $_FILES['file']['name'] ) ? sanitize_file_name( wp_unslash( (string) $_FILES['file']['name'] ) ) : '';
		$type = wp_check_filetype( $name );

		return is_string( $type['type'] ) && '' !== $type['type'] ? $type['type'] : 'application/octet-stream';
	}

	/**
	 * Confirm a direct-to-storage upload finished.
	 *
	 * Same two gates as localUpload(): the per-form nonce and the per-upload
	 * token are both required.
	 */
	public static function complete(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below, before the payload is used.
		$raw      = wp_unslash( $_POST );
		$formId   = isset( $raw['form_id'] ) ? absint( $raw['form_id'] ) : 0;
		$nonce    = isset( $raw['_relevant_upload_nonce'] ) ? sanitize_text_field( (string) $raw['_relevant_upload_nonce'] ) : '';
		$uploadId = isset( $raw['upload_id'] ) ? absint( $raw['upload_id'] ) : 0;
		$token    = sanitize_text_field( (string) ( $raw['token'] ?? '' ) );

		if ( $formId <= 0 || '' === $nonce || ! wp_verify_nonce( $nonce, 'relevant_upload_' . $formId ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'hivekit-relevant-forms' ) ], 403 );
		}

		$result = ( new UploadService() )->completeRemoteUpload( $uploadId, $token );

		if ( isset( $result['error'] ) ) {
			wp_send_json_error( [ 'message' => $result['error'] ], 422 );
		}

		wp_send_json_success( [ 'upload_id' => $uploadId ] );
	}

	/**
	 * Queue a rescan for an upload (admin only).
	 */
	public static function rescan(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'hivekit-relevant-forms' ), '', [ 'response' => 403 ] );
		}

		$raw      = wp_unslash( $_GET );
		$uploadId = isset( $raw['upload_id'] ) ? absint( $raw['upload_id'] ) : 0;

		check_admin_referer( 'relevant_rescan_' . $uploadId );

		$upload = ( new \RelevantForms\Uploads\Domain\UploadRepository() )->find( $uploadId );

		if ( null === $upload ) {
			wp_die( esc_html__( 'Upload not found.', 'hivekit-relevant-forms' ), '', [ 'response' => 404 ] );
		}

		( new UploadService() )->scheduleScan( $uploadId, 5 );

		wp_safe_redirect(
			add_query_arg(
				[ 'rescanned' => 1 ],
				admin_url( 'admin.php?page=relevant-entry&entry_id=' . (int) ( $upload->entryId ?? 0 ) )
			)
		);
		exit;
	}

	/**
	 * Authenticated download: stream local files and managed objects;
	 * custom storage providers still redirect to their authorization URL.
	 */
	public static function download(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this file.', 'hivekit-relevant-forms' ), '', [ 'response' => 403 ] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified via check_admin_referer below.
		$raw      = wp_unslash( $_GET );
		$uploadId = isset( $raw['upload_id'] ) ? absint( $raw['upload_id'] ) : 0;

		check_admin_referer( 'relevant_download_' . $uploadId );

		$result = ( new UploadService() )->authorizeDownload( $uploadId );

		if ( isset( $result['error'] ) ) {
			wp_die( esc_html( $result['error'] ), '', [ 'response' => 403 ] );
		}

		$upload = $result['upload'];

		if ( 'local' === $upload->provider ) {
			$path = ( new LocalStorageProvider() )->absolutePath( new \RelevantForms\Uploads\Contracts\StoredObject( $upload->objectKey ) );

			if ( ! is_file( $path ) ) {
				wp_die( esc_html__( 'Stored object is missing.', 'hivekit-relevant-forms' ), '', [ 'response' => 404 ] );
			}

			nocache_headers();
			header( 'Content-Type: ' . ( '' !== $upload->detectedMime ? $upload->detectedMime : 'application/octet-stream' ) );
			header( 'Content-Disposition: attachment; filename="' . rawurlencode( $upload->originalFilename ) . '"' );
			header( 'Content-Length: ' . (string) filesize( $path ) );
			readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- authenticated private download stream.
			exit;
		}

		if ( 'cloud' === $upload->provider ) {
			$temp = ( new \RelevantForms\Uploads\Storage\CloudStorageProvider() )->copyToTemporaryFile(
				new \RelevantForms\Uploads\Contracts\StoredObject( $upload->objectKey )
			);

			if ( null === $temp || ! is_file( $temp ) ) {
				wp_die( esc_html__( 'This file is temporarily unavailable. Please try again later.', 'hivekit-relevant-forms' ), '', [ 'response' => 502 ] );
			}

			nocache_headers();
			header( 'Content-Type: ' . ( '' !== $upload->detectedMime ? $upload->detectedMime : 'application/octet-stream' ) );
			header( 'Content-Disposition: attachment; filename="' . rawurlencode( $upload->originalFilename ) . '"' );
			header( 'Content-Length: ' . (string) filesize( $temp ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- authenticated private download stream.
			readfile( $temp );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- temp copy of a managed object.
			@unlink( $temp );
			exit;
		}

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- custom storage providers return their own authorization URL.
		wp_redirect( $result['download']->url );
		exit;
	}
}
