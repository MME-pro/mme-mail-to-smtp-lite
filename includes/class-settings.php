<?php
/**
 * Settings storage and schema.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Non-secret settings, with wp-config.php constants taking precedence.
 *
 * Credentials do not live here - see Secrets.
 */
class Settings {

	public const OPTION = 'mmoa_settings';

	public const PROVIDER_NONE         = '';
	public const PROVIDER_GRAPH        = 'graph';
	public const PROVIDER_GMAIL_SA     = 'gmail_sa';
	public const PROVIDER_GMAIL_OAUTH  = 'gmail_oauth';

	/**
	 * The two built-in connection slots.
	 *
	 * Any other string is an additional connection, stored under its own
	 * prefix. Nothing here is limited to two - the prefix mechanism was always
	 * general, and additional connections use it unchanged.
	 */
	public const SLOT_PRIMARY = '';
	public const SLOT_BACKUP  = 'backup';

	/**
	 * Keys that describe one connection, and so exist once per slot.
	 *
	 * Setting key => [ default, constant, sanitizer ]. For the backup slot the
	 * storage key and the constant both gain a prefix, so
	 * `ms_tenant_id` / MMOA_MS_TENANT_ID becomes
	 * `backup_ms_tenant_id` / MMOA_BACKUP_MS_TENANT_ID.
	 */
	/**
	 * Credentials written by an authorization flow rather than typed.
	 *
	 * Nobody fills in a refresh token, so none of these is a declared
	 * field and nothing in the field registry knows they exist. Listing
	 * them here is what lets a disconnect clear them: the previous one
	 * walked the declared fields and left every refresh token behind.
	 */
	private const FLOW_SECRETS = [ 'google_refresh', 'ms_refresh', 'msoauth_refresh' ];

	private const CONNECTION_SCHEMA = [
		'provider'          => [ self::PROVIDER_NONE, 'MMOA_PROVIDER', 'provider' ],

		// The From address belongs to the connection, not to the site. Two
		// connections normally authenticate as different mailboxes, and every
		// provider here either refuses or silently rewrites a From address the
		// authenticated identity is not allowed to use - so one site-wide value
		// meant a backup on a second provider could only work by coincidence.
		//
		// The primary slot stores these under the bare key, which is where the
		// site-wide value already lived, so moving them here leaves the primary
		// connection reading exactly what it read before.
		'from_email'        => [ '', 'MMOA_FROM_EMAIL', 'email' ],
		'from_name'         => [ '', 'MMOA_FROM_NAME', 'text' ],
		'force_from'        => [ true, null, 'bool' ],

		'ms_tenant_id'      => [ '', 'MMOA_MS_TENANT_ID', 'text' ],
		'ms_client_id'      => [ '', 'MMOA_MS_CLIENT_ID', 'text' ],
		'ms_sender'         => [ '', 'MMOA_MS_SENDER', 'email' ],
		'ms_secret_expires' => [ 0, null, 'int' ],
		'ms_policy_ack'     => [ false, null, 'bool' ],

		'google_sa_email'   => [ '', 'MMOA_GOOGLE_SA_CLIENT_EMAIL', 'text' ],
		'google_sender'     => [ '', 'MMOA_GOOGLE_SENDER', 'email' ],
		'google_client_id'  => [ '', 'MMOA_GOOGLE_CLIENT_ID', 'text' ],

		// Whether a connection uses the hosted setup service or an OAuth client
		// this site registered itself. Stored per connection, because one site
		// can reasonably do both: a brokered Gmail account for convenience and
		// a self-registered client for the mailbox that matters.
		//
		// The default is deliberately the self-registered path. A stored value
		// only ever appears here because someone chose one-click, so an upgrade
		// cannot silently move an existing connection onto a service it was
		// never told about.
		'google_setup_mode' => [ 'own_client', null, 'text' ],
		'google_account'    => [ '', null, 'text' ],

		'ms_setup_mode'     => [ 'own_signin', null, 'text' ],

		// Written by the sign-in rather than typed, exactly like
		// google_account above. It is here rather than declared as a field
		// because it is not one: the connection screen already shows which
		// mailbox signed in, and a second read-only copy of it on the form
		// asks the reader to work out whether the two can disagree.
		'msoauth_account'   => [ '', null, 'text' ],
		'ms_account'        => [ '', null, 'text' ],
	];

	/**
	 * Keys that belong to the site, not to a connection.
	 *
	 * These stay readable through a slot-scoped instance - a provider asking
	 * for `from_email` gets the site's one answer no matter which slot it is
	 * sending from, which is what makes providers slot-agnostic.
	 */
	private const GLOBAL_SCHEMA = [

		'log_enabled'     => [ true, null, 'bool' ],
		'log_retention'   => [ 30, null, 'int' ],
		'alert_threshold' => [ 3, null, 'int' ],
		'alert_email'     => [ '', 'MMOA_ALERT_EMAIL', 'email' ],

		'queue_enabled'   => [ true, null, 'bool' ],

		// How long a message may sit in the retry queue before it is
		// discarded. It is a privacy setting as much as a housekeeping one:
		// a queued row holds the entire message, so this is the longest
		// anybody's correspondence can remain in the database unsent.
		'queue_retention' => [ 7, null, 'int' ],

		// Additional connections beyond primary and backup: [ id => name ].
		// Only the names live here; every credential a connection holds is
		// stored under its own slot prefix by the same mechanism the backup
		// already uses.
		'connections'     => [ [], null, 'list' ],

		'routing_enabled' => [ false, null, 'bool' ],
		'routing_rules'   => [ [], null, 'list' ],
	];

	/**
	 * Shared across instances on purpose.
	 *
	 * Every slot reads and writes the one option row, so a per-instance cache
	 * would let a write through the primary view leave a backup view holding
	 * stale values for the rest of the request. There is exactly one copy of
	 * this data, so there is exactly one cache of it.
	 */
	private static ?array $cache = null;

	public function __construct( private Secrets $secrets, private string $slot = self::SLOT_PRIMARY ) {}

	public function secrets(): Secrets {
		return $this->secrets;
	}

	public function slot(): string {
		return $this->slot;
	}

	/**
	 * A view of these settings scoped to one connection slot.
	 *
	 * Providers are handed one of these and never learn which slot they are.
	 * That is the point: Graph reading `ms_tenant_id` resolves to the primary
	 * or the backup credential purely by which view it was constructed with,
	 * so the backup connection needed no provider changes at all.
	 */
	public function for_slot( string $slot ): Settings {
		if ( $slot === $this->slot ) {
			return $this;
		}

		return new self( $this->secrets->for_slot( $slot ), $slot );
	}

	/**
	 * Storage key for a setting in this slot.
	 */
	private function storage_key( string $key ): string {
		// Everything that is not explicitly site-wide belongs to a connection,
		// including the fields providers declare for themselves. Testing the
		// other way round - "is it a known connection key" - would silently
		// leave provider fields unprefixed, and the backup connection would
		// then write its credentials straight over the primary's.
		if ( self::SLOT_PRIMARY === $this->slot || isset( self::GLOBAL_SCHEMA[ $key ] ) ) {
			return $key;
		}

		return $this->slot . '_' . $key;
	}

	/**
	 * @return array{0:mixed,1:?string,2:string}|null
	 */
	private function schema( string $key ): ?array {
		$entry = self::CONNECTION_SCHEMA[ $key ] ?? self::GLOBAL_SCHEMA[ $key ] ?? null;

		// Anything a provider declares but this class has never heard of is a
		// connection setting too. Without this, adding a provider would mean
		// editing the schema here as well as writing the class, and the two
		// would drift - a field the provider reads but Settings refuses to
		// store fails silently and looks like a save bug.
		if ( null === $entry ) {
			$entry = $this->provider_field_schema( $key );
		}

		if ( null === $entry ) {
			return null;
		}

		// Only connection keys move between slots, and only they take a
		// prefixed constant.
		if ( self::SLOT_PRIMARY !== $this->slot && ! isset( self::GLOBAL_SCHEMA[ $key ] ) && null !== $entry[1] ) {
			$entry[1] = 'MMOA_' . strtoupper( $this->slot ) . '_' . substr( $entry[1], 5 );
		}

		return $entry;
	}

	/**
	 * Derive a schema entry from a provider's declared field.
	 *
	 * Credentials are excluded on purpose: a field marked secret belongs to
	 * Secrets, which encrypts it. Falling through to here would store an API
	 * key in the clear in the options table, so the two stores are kept
	 * strictly disjoint rather than merely conventionally so.
	 *
	 * @return array{0:mixed,1:?string,2:string}|null
	 */
	private function provider_field_schema( string $key ): ?array {
		$field = Provider_Registry::all_fields()[ $key ] ?? null;

		if ( null === $field || $field->secret ) {
			return null;
		}

		$types = [
			Field::EMAIL    => 'email',
			Field::NUMBER   => 'int',
			Field::CHECKBOX => 'bool',
		];

		return [
			$field->default,
			'' !== $field->constant ? 'MMOA_' . $field->constant : 'MMOA_' . strtoupper( $key ),
			$types[ $field->type ] ?? 'text',
		];
	}

	/**
	 * @return mixed
	 */
	public function get( string $key ) {
		$schema = $this->schema( $key );

		if ( null === $schema ) {
			return null;
		}

		[ $default, $constant, $type ] = $schema;

		if ( $constant && defined( $constant ) ) {
			return $this->sanitize_value( constant( $constant ), $type );
		}

		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, [] );
			self::$cache = is_array( $stored ) ? $stored : [];
		}

		$storage = $this->storage_key( $key );

		return array_key_exists( $storage, self::$cache )
			? $this->sanitize_value( self::$cache[ $storage ], $type )
			: $default;
	}

	/**
	 * Is this setting pinned by a wp-config.php constant?
	 */
	public function is_constant( string $key ): bool {
		$constant = $this->schema( $key )[1] ?? null;

		return $constant && defined( $constant );
	}

	/**
	 * Merge and persist. Keys pinned by constants are skipped.
	 *
	 * @param array<string,mixed> $values Raw, unsanitized input.
	 */
	public function update( array $values ): void {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		foreach ( $values as $key => $value ) {
			$schema = $this->schema( $key );

			if ( null === $schema || $this->is_constant( $key ) ) {
				continue;
			}

			$stored[ $this->storage_key( $key ) ] = $this->sanitize_value( $value, $schema[2] );
		}

		update_option( self::OPTION, $stored, true );
		self::$cache = $stored;
	}

	/**
	 * Return one connection to the state it had before anyone touched it.
	 *
	 * Deleting rather than blanking. A key left behind holding an empty
	 * string is not the same as a key that was never set: the first reads
	 * back as "" and the second reads back as whatever the schema declares,
	 * so blanking would leave a disconnected connection with force_from off
	 * and its setup mode empty rather than at the defaults a fresh one gets.
	 *
	 * Everything goes, the From address included. Disconnecting is not
	 * "sign out and keep my details" - it is the control someone uses when
	 * they are handing a site on, or when a connection is being rebuilt
	 * against a different mailbox, and leaving the previous address sitting
	 * in the form is how mail ends up going out as the wrong person.
	 *
	 * A value pinned by a constant in wp-config.php survives, because it was
	 * never in the database to delete. That is the correct outcome and the
	 * only one available.
	 */
	/**
	 * Whether a key has a value of its own, rather than falling back.
	 *
	 * get() cannot answer this: an absent key and a key holding its default
	 * read back identically, which is usually the point. A migration that
	 * needs to pin current behaviour before a default moves underneath it
	 * is the case where the difference matters, because writing the value
	 * to a connection that had chosen something else would be the bug it is
	 * trying to prevent.
	 */
	public function is_stored( string $key ): bool {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, [] );
			self::$cache = is_array( $stored ) ? $stored : [];
		}

		return array_key_exists( $this->storage_key( $key ), self::$cache );
	}

	public function reset_connection(): void {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		foreach ( self::connection_keys() as $key ) {
			unset( $stored[ $this->storage_key( $key ) ] );
		}

		update_option( self::OPTION, $stored, true );
		self::$cache = $stored;

		// Secrets live in their own option and their own encryption, so they
		// are cleared through Secrets rather than unset here. Setting a
		// credential to the empty string removes the row outright.
		foreach ( self::secret_keys() as $key ) {
			$this->secrets()->set( $key, '' );
		}
	}

	/**
	 * Every setting that belongs to a connection rather than to the site.
	 *
	 * @return array<int,string>
	 */
	private static function connection_keys(): array {
		$keys = array_keys( self::CONNECTION_SCHEMA );

		// Provider fields are connection settings too, and the registry is
		// the only thing that knows them all - a provider registered by
		// another plugin declares keys this class has never heard of, and a
		// disconnect has to clear those as well.
		foreach ( Provider_Registry::all_fields() as $key => $field ) {
			if ( ! $field->secret && ! isset( self::GLOBAL_SCHEMA[ $key ] ) ) {
				$keys[] = $key;
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Every credential a connection can be holding.
	 *
	 * @return array<int,string>
	 */
	private static function secret_keys(): array {
		$keys = self::FLOW_SECRETS;

		foreach ( Provider_Registry::all_fields() as $key => $field ) {
			if ( $field->secret ) {
				$keys[] = $key;
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Should the plugin take over wp_mail()?
	 *
	 * False means we stay out of the way and core sends normally - which is
	 * what we want on a fresh install, and locally where Mailpit is catching
	 * mail. Never intercept a send we cannot actually complete.
	 */
	public function is_active(): bool {
		return self::PROVIDER_NONE !== $this->get( 'provider' );
	}

	/**
	 * Is a backup connection configured?
	 */
	public function has_backup(): bool {
		return self::PROVIDER_NONE !== $this->for_slot( self::SLOT_BACKUP )->get( 'provider' );
	}

	/**
	 * Drop the shared read cache.
	 *
	 * Needed by the test suite, which rewrites settings out from under a live
	 * instance, and harmless in production.
	 */
	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * @return array<string,string> Provider slug => human label.
	 */
	public static function provider_labels(): array {
		return Provider_Registry::labels();
	}

	/**
	 * Sanitize a nested array of scalars.
	 *
	 * Keys are restricted to the characters a structured payload actually needs,
	 * so a crafted key cannot become anything surprising when this is read back
	 * and iterated. Depth is capped for the same reason a recursive walk over
	 * untrusted input always should be.
	 *
	 * @param array<mixed> $value Raw array.
	 * @return array<mixed>
	 */
	private function sanitize_list( array $value, int $depth = 0 ): array {
		if ( $depth > 6 ) {
			return [];
		}

		$out = [];

		foreach ( $value as $key => $item ) {
			$key = is_int( $key ) ? $key : preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $key );

			if ( '' === $key && ! is_int( $key ) ) {
				continue;
			}

			if ( is_array( $item ) ) {
				$out[ $key ] = $this->sanitize_list( $item, $depth + 1 );
			} elseif ( is_bool( $item ) ) {
				$out[ $key ] = $item;
			} elseif ( is_int( $item ) || is_float( $item ) ) {
				$out[ $key ] = $item;
			} else {
				$out[ $key ] = sanitize_text_field( (string) $item );
			}
		}

		return $out;
	}

	/**
	 * A list of addresses, kept as one comma-separated string.
	 *
	 * Accepts either an array - which is what the admin app sends, one entry
	 * per chip - or a string a human typed or a wp-config.php constant holds,
	 * separated by commas, semicolons or newlines. Whichever arrives, what
	 * comes back out is the same shape, so nothing downstream has to care.
	 *
	 * Anything that is not an address is dropped rather than stored and left
	 * to fail on the Monday morning it was needed. Duplicates are dropped
	 * case-insensitively, because the same person entered twice means the
	 * report arrives twice.
	 *
	 * @param mixed $value Raw value.
	 */
	private function sanitize_emails( $value ): string {
		$parts = is_array( $value )
			? $value
			: (array) preg_split( '~[,;\r\n\t ]+~', (string) $value );

		$out = [];

		foreach ( $parts as $part ) {
			$email = sanitize_email( trim( (string) $part ) );

			if ( '' === $email || ! is_email( $email ) ) {
				continue;
			}

			$out[ strtolower( $email ) ] = $email;
		}

		return implode( ', ', $out );
	}

	/**
	 * @param mixed $value Raw value.
	 * @return mixed Sanitized value.
	 */
	private function sanitize_value( $value, string $type ) {
		switch ( $type ) {
			case 'bool':
				return (bool) $value;
			case 'int':
				return (int) $value;
			case 'email':
				return sanitize_email( (string) $value );
			case 'emails':
				return $this->sanitize_emails( $value );
			case 'list':
				// Nested arrays - the connection list and the routing rules.
				// Sanitized recursively rather than trusted, because these
				// arrive from the admin app as JSON and end up in an option
				// that other code reads back as structured data.
				return is_array( $value ) ? $this->sanitize_list( $value ) : [];
			case 'provider':
				return array_key_exists( (string) $value, self::provider_labels() )
					? (string) $value
					: self::PROVIDER_NONE;
			default:
				return sanitize_text_field( (string) $value );
		}
	}
}
