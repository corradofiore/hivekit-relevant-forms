<?php
/**
 * Stamp the build channel into a staged plugin bootstrap.
 *
 * The release build copies the plugin to a staging directory and then calls
 * this, so the source tree keeps the production identity while an artifact can
 * declare which service it belongs to. Every substitution is verified: if the
 * shape of the bootstrap changes, the build fails instead of quietly emitting
 * a QA artifact that still points at production.
 *
 * Not part of the shipped plugin — bin/ is excluded from the release artifact.
 *
 * Usage:
 *   php bin/stamp-channel.php <bootstrap.php> <channel> <origin> \
 *       <build-version> <source-version>
 */

declare(strict_types=1);

/**
 * Abort the build with a reason.
 *
 * @param string $message Why the stamping failed.
 */
function fail(string $message): never
{
    fwrite(STDERR, $message."\n");
    exit(1);
}

$arguments = $argv;
array_shift($arguments);

if (count($arguments) !== 5) {
    fail('Usage: stamp-channel.php <bootstrap.php> <channel> <origin> <build-version> <source-version>');
}

[$bootstrap, $channel, $origin, $buildVersion, $sourceVersion] = $arguments;

if (! is_file($bootstrap)) {
    fail("Bootstrap not found: {$bootstrap}");
}

$source = (string) file_get_contents($bootstrap);

// 1. The plugin header version, so WordPress (and the plugin list) shows the
//    build for what it is.
$stamped = (string) preg_replace(
    '/^( \* Version:\s+)(\S+)$/m',
    '${1}'.$buildVersion,
    $source,
    1,
    $replacements
);

if ($replacements !== 1) {
    fail('Could not stamp the plugin header version.');
}

// Assert the result, not the intent: '$1' concatenated with a version that
// starts with a digit reads as '$11' and silently drops the version.
if (preg_match('/^ \* Version:\s+'.preg_quote($buildVersion, '/').'$/m', $stamped) !== 1) {
    fail('Stamped header does not read as a version line.');
}

// 2. The channel constants plus the matching version constant, anchored on the
//    version constant itself so the injection cannot land somewhere arbitrary.
$anchor = "define( 'RELEVANT_FORMS_VERSION', '".$sourceVersion."' );";

if (! str_contains($stamped, $anchor)) {
    fail('Could not find the version constant to stamp.');
}

$injection = "define( 'RELEVANT_FORMS_CHANNEL', '".$channel."' );\n"
    ."define( 'RELEVANT_FORMS_CHANNEL_URL', '".$origin."' );\n"
    ."define( 'RELEVANT_FORMS_VERSION', '".$buildVersion."' );";

$stamped = str_replace($anchor, $injection, $stamped);

file_put_contents($bootstrap, $stamped);

fwrite(STDOUT, "Stamped {$channel} channel (version {$buildVersion}) into {$bootstrap}\n");
