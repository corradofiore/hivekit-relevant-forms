<?php
/**
 * S3Client SigV4 tests, validated against the official AWS test vectors.
 *
 * Vector source: awslabs/aws-sig-v4-test-suite (get-vanilla).
 * Credentials: AKIDEXAMPLE / wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY
 * (the suite signs with a + in the secret).
 *
 * @package SentinelForms
 */

declare(strict_types=1);

use SentinelForms\Uploads\Storage\S3Client;

const SF_TEST_CREQ = "GET\n/\n\nhost:example.amazonaws.com\nx-amz-date:20150830T123600Z\n\nhost;x-amz-date\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";

const SF_TEST_SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

/**
 * Test client instance.
 */
function sf_test_client(): S3Client {
	return new S3Client(
		'https://example.amazonaws.com',
		'us-east-1',
		'AKIDEXAMPLE',
		SF_TEST_SECRET,
		'examplebucket',
	);
}

it(
	'builds the canonical request exactly as the official vector',
	function () {
		$client = sf_test_client();

		$creq = $client->canonicalRequest(
			'GET',
			'/',
			[],
			[
				'host'       => 'example.amazonaws.com',
				'x-amz-date' => '20150830T123600Z',
			],
		);

		expect( $creq )->toBe( SF_TEST_CREQ );
	}
);

it(
	'hashes the canonical request to the official value',
	function () {
		expect( hash( 'sha256', SF_TEST_CREQ ) )
			->toBe( 'bb579772317eb040ac9ed261061d46c1f17a8133879d6129b6e1c25292927e63' );

		$stringToSign = S3Client::stringToSign(
			'20150830T123600Z',
			'20150830',
			'us-east-1',
			'service',
			SF_TEST_CREQ,
		);

		expect( $stringToSign )
			->toContain( '20150830/us-east-1/service/aws4_request' );
		expect( $stringToSign )->toEndWith( 'bb579772317eb040ac9ed261061d46c1f17a8133879d6129b6e1c25292927e63' );
	}
);

it(
	'produces the official signature for get-vanilla',
	function () {
		$signature = S3Client::sign(
			SF_TEST_SECRET,
			'20150830T123600Z',
			'20150830',
			'us-east-1',
			'service',
			SF_TEST_CREQ,
		);

		expect( $signature )
			->toBe( '5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31' );
	}
);

it(
	'builds well-formed presigned PUT URLs with a constrained content type',
	function () {
		$client = sf_test_client();
		$url    = $client->presignedUrl( 'PUT', 'docs/hello.pdf', 900, [ 'Content-Type' => 'application/pdf' ] );

		$parts = wp_parse_url( $url );
		expect( $parts )->not->toBeFalse();

		parse_str( (string) ( $parts['query'] ?? '' ), $query );

		expect( $parts['scheme'] )->toBe( 'https' );
		expect( $parts['host'] )->toBe( 'example.amazonaws.com' );
		expect( $parts['path'] )->toBe( '/examplebucket/docs/hello.pdf' );
		expect( $query['X-Amz-Algorithm'] )->toBe( 'AWS4-HMAC-SHA256' );
		expect( $query['X-Amz-Expires'] )->toBe( '900' );
		expect( $query['X-Amz-Credential'] )->toContain( 'AKIDEXAMPLE' );
		expect( $query['X-Amz-Credential'] )->toContain( '/us-east-1/s3/aws4_request' );
		expect( $query['X-Amz-SignedHeaders'] )->toContain( 'content-type' );
		expect( $query['X-Amz-SignedHeaders'] )->toContain( 'host' );
		expect( $query['X-Amz-Signature'] )->toMatch( '/^[a-f0-9]{64}$/' );
	}
);

it(
	'encodes object keys without double-encoding slashes',
	function () {
		$client = sf_test_client();
		$url    = $client->presignedUrl( 'GET', 'folder/report final.pdf' );

		$parts = wp_parse_url( $url );
		expect( $parts['path'] )->toBe( '/examplebucket/folder/report%20final.pdf' );
	}
);
