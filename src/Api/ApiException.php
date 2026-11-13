<?php
/**
 * Error talking to the RESO server.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Api;

/**
 * Carries the HTTP status (0 for network errors).
 */
final class ApiException extends \RuntimeException {

	/**
	 * Whether retrying later could succeed.
	 */
	public function is_transient(): bool {
		return 0 === $this->getCode() || 429 === $this->getCode() || $this->getCode() >= 500;
	}
}
