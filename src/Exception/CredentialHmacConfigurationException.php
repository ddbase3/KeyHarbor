<?php declare(strict_types=1);

namespace KeyHarbor\Exception;

use RuntimeException;

/**
 * Reports an unavailable or invalid KeyHarbor HMAC master key.
 */
final class CredentialHmacConfigurationException extends RuntimeException {
}
