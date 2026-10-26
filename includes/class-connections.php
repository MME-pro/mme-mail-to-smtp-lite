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
 * Free has one connection of its own: the primary. An add-on can declare more
 * through the `mmoa_connections` filter - Pro's backup is one - and each is
 * addressed by its slot. The primary is still addressed through a slot rather
 * than assumed, for two reasons. A message coming off the retry
 * queue carries the slot it was first sent through, so the slot has to be a
 * real value rather than an implication. And the slot is the seam an add-on
 * extends to introduce a second connection - providers already read their
 * settings through a slot-scoped instance, so nothing inside a provider has to
 * change for that to work.
 */
class Connections {

	public function __construct( private Settings $settings ) {}

	/**
	 * Every connection, the primary first.
	 *
	 * @return array<int,array{id:string,slot:string,name:string,builtin:bool,provider:string,configured:bool,role:string,description:string}>
	 */
	public function all(): array {
		$all = [ $this->describe( 'primary', Settings::SLOT_PRIMARY, __( 'Primary', 'mme-mail-to-smtp' ), true, 'primary', '' ) ];

		foreach ( $this->extra() as $c ) {
			$all[] = $this->describe( $c['id'], $c['id'], $c['name'], false, $c['role'], $c['description'] );
		}

		return $all;
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

		return isset( $this->extra()[ $id ] ) ? $id : null;
	}

	public function exists( string $id ): bool {
		return null !== $this->slot_for( $id );
	}

	/**
	 * The display name for a connection.
	 */
	public function name_for( string $id ): string {
		return $this->extra()[ $id ]['name'] ?? __( 'Primary', 'mme-mail-to-smtp' );
	}

	/**
	 * Connections an add-on declares, by id, after checking each one.
	 *
	 * The id becomes an option-key prefix, so it is held to a strict shape and
	 * may not collide with the primary or with a global setting - a slot named
	 * after one would read and write that setting as if it were its own.
	 *
	 * The filter callback must not read settings: describe() does, and a
	 * callback that did would run inside its own answer.
	 *
	 * @return array<string,array{id:string,name:string,role:string,description:string}>
	 */
	private function extra(): array {
		/**
		 * Filters the connections an add-on adds beside the primary.
		 *
		 * @param array<int,array{id:string,name:string,role?:string,description?:string}> $connections
		 */
		$declared = apply_filters( 'mmoa_connections', [] );
		$out      = [];

		foreach ( is_array( $declared ) ? $declared : [] as $c ) {
			$id = is_array( $c ) ? (string) ( $c['id'] ?? '' ) : '';

			if (
				! preg_match( '/^[a-z][a-z0-9_]{1,31}$/', $id )
				|| 'primary' === $id
				|| isset( $out[ $id ] )
				|| Settings::is_global( $id )
			) {
				continue;
			}

			$out[ $id ] = [
				'id'          => $id,
				'name'        => (string) ( $c['name'] ?? $id ),
				'role'        => (string) ( $c['role'] ?? '' ),
				'description' => (string) ( $c['description'] ?? '' ),
			];
		}

		return $out;
	}

	/**
	 * @return array{id:string,slot:string,name:string,builtin:bool,provider:string,configured:bool,role:string,description:string}
	 */
	private function describe( string $id, string $slot, string $name, bool $builtin, string $role, string $description ): array {
		$scoped   = $this->settings->for_slot( $slot );
		$provider = (string) $scoped->get( 'provider' );

		return [
			'id'          => $id,
			'slot'        => $slot,
			'name'        => $name,
			'builtin'     => $builtin,
			'provider'    => $provider,
			'configured'  => '' !== $provider,
			'role'        => $role,
			'description' => $description,
		];
	}
}
