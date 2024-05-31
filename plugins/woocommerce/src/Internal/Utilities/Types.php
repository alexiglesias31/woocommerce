<?php

namespace Automattic\WooCommerce\Internal\Utilities;

use InvalidArgumentException;

/**
 * Utilities to help ensure type safety.
 */
class Types {
	public const UNABLE_TO_ENSURE_INSTANCE_IS_OF_EXPECTED_TYPE = 'unable-to-ensure-instance-is-of-expected-type';

	/**
	 * Checks if $thing is an instance of $desired_type.
	 *
	 * If the check succeeds, $thing will be returned without further modification. If the check fails, then either
	 * an exception will be thrown or, if an $on_failure callback was supplied, it will be invoked to either generate
	 * an appropriate return value or to throw a more specific exception.
	 *
	 * Please note that the failure handler will be passed two arguments:
	 *
	 *     $on_failure( $object, $desired_type )
	 *
	 * @since 9.1.0
	 * @throws InvalidArgumentException If $object does not match $desired_type, and an $on_failure callback was not supplied.
	 *
	 * @param mixed     $thing        The value or reference to be assessed.
	 * @param string    $desired_type What we expect the return type to be, if it is not a WP_Error.
	 * @param ?callable $on_failure   If provided, and if evaluation fails, this will be invoked to generate a return value.
	 *
	 * @return mixed
	 */
	public static function ensure_instance_of( $thing, string $desired_type, callable $on_failure = null ) {
		// If everything looks good, return early.
		if ( $thing instanceof $desired_type ) {
			return $thing;
		}

		// Summarize the error for use in logging and in case we have to throw an exception.
		$summary = sprintf(
			/* translators: %1$s: name of the expected type */
			__( 'Unable to ensure that an object was of expected type %1$s. This is not necessarily a problem within WooCommerce, and may indicate there is a problem with code from another plugin that has hooked into one or more WooCommerce filters (please refer to the backtrace).', 'woocommerce' ),
			$desired_type
		);

		// Otherwise, let's log the problem so the site operator has a record of where things went wrong.
		$logger = wc_get_logger();

		if ( $logger ) {
			$logger->error(
				$summary,
				array(
					'source'    => 'wc-type-check-utility',
					'backtrace' => true,
				)
			);
		}

		// Invoke the $on_failure handler, if specified.
		if ( null !== $on_failure ) {
			return $on_failure( $thing, $desired_type );
		}

		throw new InvalidArgumentException(
			esc_html( $summary ),
			esc_html( self::UNABLE_TO_ENSURE_INSTANCE_IS_OF_EXPECTED_TYPE )
		);
	}
}
