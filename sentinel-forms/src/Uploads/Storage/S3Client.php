<?php
/**
 * Minimal AWS Signature Version 4 S3 client.
 *
 * Self-contained SigV4 implementation (no SDK dependency) so the plugin
 * stays small enough for wordpress.org distribution while supporting any
 * S3-compatible endpoint: Hetzner Object Storage, Backblaze B2, AWS S3,
 * GCS interoperability mode.
 *
 * @package SentinelForms
 */

declare(strict_types=1);

namespace SentinelForms\Uploads\Storage;

/**
 * Signs requests and builds presigned URLs for an S3-compatible endpoint.
 */
final class S3Client {

	/**
	 * AWS-compatible region.
	 *
	 * @var string
	 */
	private string $region;

	/**
	 * Access key id.
	 *
	 * @var string
	 */
	private string $access_key;

	/**
	 * Secret access key.
	 *
	 * @var string
	 */
	private string $secret_key;

	/**
	 * Endpoint host (no scheme).
	 *
	 * @var string
	 */
	private string $host;

	/**
	 * Endpoint scheme (https).
	 *
	 * @var string
	 */
	private string $scheme;

	/**
	 * Bucket name.
	 *
	 * @var string
	 */
	private string $bucket;

	/**
	 * Constructor.
	 *
	 * @param string $endpoint  Full endpoint URL, e.g. https://s3.us-west-004.backblazeb2.com.
	 * @param string $region    Region, e.g. us-west-004, fsn1, us-east-1.
	 * @param string $accessKey Access key id.
	 * @param string $secretKey Secret access key.
	 * @param string $bucket    Bucket name.
	 */
	public function __construct(
		string $endpoint,
		string $region,
		string $accessKey,
		string $secretKey,
		string $bucket,
	) {
		$parts = wp_parse_url( $endpoint );

		$this->region     = $region;
		$this->access_key = $accessKey;
		$this->secret_key = $secretKey;
		$this->bucket     = $bucket;
		$this->scheme     = $parts['scheme'] ?? 'https';
		$this->host       = (string) ( $parts['host'] ?? '' );
	}

	/**
	 * Build a presigned URL (query-string authentication).
	 *
	 * @param string              $method      PUT or GET.
	 * @param string              $objectKey   Object key (no leading slash).
	 * @param int                 $ttlSeconds  Link lifetime.
	 * @param array<string,string> $headers    Extra headers to sign (e.g. Content-Type).
	 */
	public function presignedUrl( string $method, string $objectKey, int $ttlSeconds = 900, array $headers = [] ): string {
		$dateTime = gmdate( 'Ymd\THis\Z' );
		$date     = gmdate( 'Ymd' );

		$headers = array_merge(
			[
				'host'                 => $this->host,
				'x-amz-content-sha256' => 'UNSIGNED-PAYLOAD',
			],
			array_change_key_case( $headers, CASE_LOWER ),
		);

		$query = [
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => $this->access_key . '/' . $this->credentialScope( $date ),
			'X-Amz-Date'          => $dateTime,
			'X-Amz-Expires'       => (string) max( 1, $ttlSeconds ),
			'X-Amz-SignedHeaders' => $this->signedHeaders( $headers ),
		];

		$canonicalRequest = $this->canonicalRequest( $method, $this->uriPath( $objectKey ), $query, $headers );
		$signature        = $this->signature( $canonicalRequest, $dateTime, $date );

		$query['X-Amz-Signature'] = $signature;

		return $this->scheme . '://' . $this->host . $this->uriPath( $objectKey ) . '?' . $this->buildQuery( $query );
	}

	/**
	 * Perform a signed server-side request (HEAD/DELETE) via wp_remote_request.
	 *
	 * @param string              $method    HEAD or DELETE.
	 * @param string              $objectKey Object key.
	 * @param array<string,string> $headers   Extra headers.
	 * @return array<string, mixed>|null Response array or null on failure.
	 */
	public function request( string $method, string $objectKey, array $headers = [] ): ?array {
		$dateTime = gmdate( 'Ymd\THis\Z' );
		$date     = gmdate( 'Ymd' );
		$payload  = hash( 'sha256', '' );

		$headers = array_merge(
			[
				'host'                 => $this->host,
				'x-amz-content-sha256' => $payload,
				'x-amz-date'           => $dateTime,
			],
			array_change_key_case( $headers, CASE_LOWER ),
		);

		$canonicalRequest = $this->canonicalRequest( $method, $this->uriPath( $objectKey ), [], $headers );
		$signature        = $this->signature( $canonicalRequest, $dateTime, $date );

		$headers['authorization'] = sprintf(
			'AWS4-HMAC-SHA256 Credential=%s, SignedHeaders=%s, Signature=%s',
			$this->access_key . '/' . $this->credentialScope( $date ),
			$this->signedHeaders( $headers ),
			$signature
		);

		unset( $headers['host'] ); // wp_remote_request sets Host itself.

		$response = wp_remote_request(
			$this->scheme . '://' . $this->host . $this->uriPath( $objectKey ),
			[
				'method'  => $method,
				'headers' => $headers,
				'timeout' => 15,
			]
		);

		return is_array( $response ) ? $response : null;
	}

	/**
	 * Bucket name.
	 */
	public function bucket(): string {
		return $this->bucket;
	}

	/**
	 * Credential scope: date/region/service/aws4_request.
	 */
	private function credentialScope( string $date ): string {
		return $date . '/' . $this->region . '/s3/aws4_request';
	}

	/**
	 * URI-encode the bucket+key path.
	 */
	private function uriPath( string $objectKey ): string {
		$key = str_replace( '%2F', '/', rawurlencode( $objectKey ) );

		return '/' . $this->bucket . '/' . $key;
	}

	/**
	 * Canonical request string.
	 *
	 * @param string              $method  HTTP verb.
	 * @param string              $path    Canonical URI.
	 * @param array<string,string> $query   Query params (already in canonical order when needed).
	 * @param array<string,string> $headers Lowercased headers.
	 */
	public function canonicalRequest( string $method, string $path, array $query, array $headers ): string {
		ksort( $headers );

		$canonicalHeaders = '';
		foreach ( $headers as $name => $value ) {
			$canonicalHeaders .= $name . ':' . trim( (string) $value ) . "\n";
		}

		$signed = $this->signedHeaders( $headers );

		return implode(
			"\n",
			[
				strtoupper( $method ),
				$path,
				$this->buildQuery( $query ),
				$canonicalHeaders,
				$signed,
				'UNSIGNED-PAYLOAD' === ( $headers['x-amz-content-sha256'] ?? '' )
					? 'UNSIGNED-PAYLOAD'
					: (string) ( $headers['x-amz-content-sha256'] ?? hash( 'sha256', '' ) ),
			]
		);
	}

	/**
	 * Sorted, lowercased signed-header list.
	 *
	 * @param array<string,string> $headers Lowercased headers.
	 */
	private function signedHeaders( array $headers ): string {
		unset( $headers['authorization'] );
		$names = array_keys( $headers );
		sort( $names );

		return implode( ';', $names );
	}

	/**
	 * Compute the signature for a canonical request.
	 */
	private function signature( string $canonicalRequest, string $dateTime, string $date ): string {
		return self::sign(
			$this->secret_key,
			$dateTime,
			$date,
			$this->region,
			's3',
			$canonicalRequest,
		);
	}

	/**
	 * Build the SigV4 string to sign (exposed for test vectors).
	 *
	 * @param string $dateTime        ISO8601 basic timestamp.
	 * @param string $date            Ymd date.
	 * @param string $region          Region.
	 * @param string $service         Service name.
	 * @param string $canonicalRequest Canonical request string.
	 */
	public static function stringToSign( string $dateTime, string $date, string $region, string $service, string $canonicalRequest ): string {
		return implode(
			"\n",
			[
				'AWS4-HMAC-SHA256',
				$dateTime,
				$date . '/' . $region . '/' . $service . '/aws4_request',
				hash( 'sha256', $canonicalRequest ),
			]
		);
	}

	/**
	 * Derive the signing key and sign a string-to-sign (exposed for tests).
	 *
	 * @param string $secretKey    Secret access key.
	 * @param string $dateTime     ISO8601 basic timestamp.
	 * @param string $date         Ymd date.
	 * @param string $region       Region.
	 * @param string $service      Service name.
	 * @param string $canonicalRequest Canonical request string.
	 */
	public static function sign( string $secretKey, string $dateTime, string $date, string $region, string $service, string $canonicalRequest ): string {
		$stringToSign = self::stringToSign( $dateTime, $date, $region, $service, $canonicalRequest );

		$kDate    = hash_hmac( 'sha256', $date, 'AWS4' . $secretKey, true );
		$kRegion  = hash_hmac( 'sha256', $region, $kDate, true );
		$kService = hash_hmac( 'sha256', $service, $kRegion, true );
		$kSigning = hash_hmac( 'sha256', 'aws4_request', $kService, true );

		return hash_hmac( 'sha256', $stringToSign, $kSigning );
	}

	/**
	 * Build a canonical query string: sorted, RFC3986-encoded.
	 *
	 * @param array<string,string> $query Query parameters.
	 */
	private function buildQuery( array $query ): string {
		uksort(
			$query,
			static function ( string $a, string $b ): int {
				return strcmp( rawurlencode( $a ), rawurlencode( $b ) );
			}
		);

		$parts = [];
		foreach ( $query as $name => $value ) {
			$parts[] = rawurlencode( $name ) . '=' . rawurlencode( (string) $value );
		}

		return implode( '&', $parts );
	}
}
