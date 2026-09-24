<?php
/**
 * The site's mail connections.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Where a connection's settings live, and what to call it.
 *
 * There is one connection here: the primary. It is still addressed through a
 * slot rather than assumed, for two reasons. A message coming off the retry
 * queue carries the slot it was first sent through, so the slot has to be a
 * real value rather than an implication. And the slot is the seam an add-on
 * extends to introduce a second connection - providers already read their
 * settings through a slot-scoped instance, so nothing inside a provider has to
 * change for that to work.
 */
class Connections {

	public function __construct( private Settings $settings ) {}

	/**
	 * Every connection.
	 *
	 * @return array<int,array{id:string,slot:string,name:string,builtin:bool,provider:string,configured:bool}>
	 */
	public function all(): array {
		return [
			$this->describe( 'primary', Settings::SLOT_PRIMARY, __( 'Primary', 'mme-mail-to-smtp' ), true ),
		];
	}

	/**
	 * The slot a public id addresses, or null when there is no such connection.
	 *
	 * Resolved rather than trusted. An id arriving on a request is used to build
	 * option keys, so anything unrecognised has to come back as null instead of
	 * addressing a slot that does not exist - which would silently create one on
	 * the next save.
	 */
	public function slot_for( string $id ): ?string {
		if ( 'primary' === $id || '' === $id ) {
			return Settings::SLOT_PRIMARY;
		}

		return null;
	}

	public function exists( string $id ): bool {
		return null !== $this->slot_for( $id );
	}

	/**
	 * The display name for a connection.
	 */
	public function name_for( string $id ): string {
		unset( $id );

		return __( 'Primary', 'mme-mail-to-smtp' );
	}

	/**
	 * @return array{id:string,slot:string,name:string,builtin:bool,provider:string,configured:bool}
	 */
	private function describe( string $id, string $slot, string $name, bool $builtin ): array {
		$scoped   = $this->settings->for_slot( $slot );
		$provider = (string) $scoped->get( 'provider' );

		return [
			'id'         => $id,
			'slot'       => $slot,
			'name'       => $name,
			'builtin'    => $builtin,
			'provider'   => $provider,
			'configured' => '' !== $provider,
		];
	}
}
