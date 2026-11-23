<?php
/**
 * Credential storage.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes credentials.
 *
 * Constants in wp-config.php win over anything in the database. That is the
 * recommended production setup: it keeps credentials out of DB dumps entirely.
 *
 * The database fallback is encrypted with sodium_crypto_secretbox, keyed with
 * the plugin's own 32-byte key - see "The keys" below - or, where that cannot
 * be written, with one derived from AUTH_KEY/SECURE_AUTH_SALT. Be clear-eyed
 * about what this buys: anyone who can read the filesystem can read the key,
 * so this is not protection against filesystem access. It protects against
 * database-only exposure - SQL injection, a leaked backup, a shared staging
 * dump - which is the realistic threat for a WordPress site.
 */
class Secrets {

	/**
	 * Settings key => wp-config.php constant.
	 */
	private const CONSTANTS = [
		'google_sa_key'     => 'MMOA_GOOGLE_SA_PRIVATE_KEY',
		'google_client_sec' => 'MMOA_GOOGLE_CLIENT_SECRET',
		'google_refresh'    => 'MMOA_GOOGLE_REFRESH_TOKEN',
	];

	private const OPTION = 'mmoa_secrets';

	/**
	 * Which connection's credentials this instance reads and writes.
	 *
	 * Mirrors Settings::$slot exactly, and for the same reason: providers ask
	 * for `google_sa_key` and the slot decides which one that is.
	 */
	public function __construct( private string $slot = '' ) {}

	/**
	 * A view of these credentials scoped to one connection slot.
	 */
	public function for_slot( string $slot ): Secrets {
		return $slot === $this->slot ? $this : new self( $slot );
	}

	/**
	 * Storage key for a credential in this slot.
	 */
	private function storage_key( string $key ): string {
		return '' === $this->slot ? $key : $this->slot . '_' . $key;
	}

	/**
	 * The wp-config.php constant for a credential in this slot, or null.
	 */
	private function constant_for( string $key ): ?string {
		$constant = self::CONSTANTS[ $key ] ?? null;

		// The map above covers the credentials whose constant name does not
		// follow from the key - MMOA_GOOGLE_SA_PRIVATE_KEY for google_sa_key
		// and so on. Everything a provider declares as secret gets the derived
		// name, so a new provider needs no entry here at all.
		if ( null === $constant ) {
			$field = Provider_Registry::all_fields()[ $key ] ?? null;

			if ( null !== $field && $field->secret ) {
				$constant = '' !== $field->constant
					? 'MMOA_' . $field->constant
					: 'MMOA_' . strtoupper( $key );
			}
		}

		if ( null === $constant || '' === $this->slot ) {
			return $constant;
		}

		return 'MMOA_' . strtoupper( $this->slot ) . '_' . substr( $constant, 5 );
	}

	/**
	 * Is this credential pinned by a constant?
	 */
	public function is_constant( string $key ): bool {
		$constant = $this->constant_for( $key );

		return null !== $constant && defined( $constant );
	}

	/**
	 * Read a credential. Returns '' when unset.
	 */
	public function get( string $key ): string {
		$constant = $this->constant_for( $key );

		if ( null !== $constant && defined( $constant ) ) {
			return (string) constant( $constant );
		}

		$stored  = get_option( self::OPTION, [] );
		$storage = $this->storage_key( $key );

		if ( ! is_array( $stored ) || ! isset( $stored[ $storage ] ) ) {
			return '';
		}

		return $this->decrypt( (string) $stored[ $storage ] );
	}

	/**
	 * Is this credential saved, but impossible to read?
	 *
	 * The case it exists for: the site's AUTH_KEY or SECURE_AUTH_SALT changed -
	 * which is what "log everybody out" and "revoke all passwords" do - after
	 * the value was encrypted with a key derived from them. get() then answers
	 * '' exactly as it does for a credential that was never entered, and the
	 * connection looks configured and healthy until the next send fails. This
	 * tells the two apart, so the screen can say so before that send.
	 */
	public function is_unreadable( string $key ): bool {
		if ( $this->is_constant( $key ) ) {
			return false;
		}

		$stored  = get_option( self::OPTION, [] );
		$storage = $this->storage_key( $key );

		if ( ! is_array( $stored ) || '' === (string) ( $stored[ $storage ] ?? '' ) ) {
			return false;
		}

		return '' === $this->decrypt( (string) $stored[ $storage ] );
	}

	/**
	 * Why an unreadable credential cannot be read.
	 *
	 * 'key' - it was sealed with the plugin's own key, and that key is gone:
	 * the file was deleted, or the site moved without it. 'salts' - it was
	 * sealed with the site's salts, and they have changed since.
	 */
	public function unreadable_reason( string $key ): string {
		$stored = get_option( self::OPTION, [] );
		$value  = is_array( $stored ) ? (string) ( $stored[ $this->storage_key( $key ) ] ?? '' ) : '';

		return 0 === strpos( $value, 'v2:' ) ? 'key' : 'salts';
	}

	/**
	 * Write a credential. Passing '' removes it.
	 *
	 * Silently ignores writes to a key that a constant already pins, so the
	 * settings screen can never mislead an admin into thinking a form value
	 * took effect when wp-config.php is overriding it.
	 */
	public function set( string $key, string $value ): void {
		if ( $this->is_constant( $key ) ) {
			return;
		}

		$stored  = get_option( self::OPTION, [] );
		$stored  = is_array( $stored ) ? $stored : [];
		$storage = $this->storage_key( $key );

		if ( '' === $value ) {
			unset( $stored[ $storage ] );
		} else {
			$stored[ $storage ] = $this->encrypt( $value );
		}

		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Remove every stored credential. Used on uninstall and on "disconnect".
	 */
	public function flush(): void {
		delete_option( self::OPTION );
	}

	/**
	 * True when values are genuinely encrypted - with the plugin's own key or,
	 * failing that, the site's salts.
	 */
	public function is_encryption_available(): bool {
		return '' !== self::own_key() || self::salts_available();
	}

	/*
	 * ---------------------------------------------------------------------
	 * The keys
	 * ---------------------------------------------------------------------
	 *
	 * Credentials used to be encrypted only with a key derived from AUTH_KEY
	 * and SECURE_AUTH_SALT (`v1:`). Those are exactly what "log everybody out"
	 * and "revoke all passwords" regenerate, so every saved credential became
	 * unreadable the moment somebody secured the site - and the connection
	 * simply looked unconfigured.
	 *
	 * So the plugin now has a key of its own (`v2:`): 32 random bytes, kept
	 * out of the database - in wp-config.php as MMOA_ENCRYPTION_KEY if the
	 * site defines it, otherwise in a file in wp-content written the first
	 * time it is needed. Rotating the salts no longer touches it. A database
	 * that leaks on its own still leaks nothing readable, since the key is not
	 * in it.
	 *
	 * v1 values are still read, and are re-encrypted with the own key by
	 * migrate(). The salts remain the fallback for a site where the key file
	 * cannot be written.
	 */

	public const KEY_CONSTANT = 'MMOA_ENCRYPTION_KEY';

	private const KEY_FILE = 'mmoa-encryption-key.php';

	/** Set once every v1 value that could be read has been moved to v2. */
	private const MIGRATED_OPTION = 'mmoa_secrets_migrated';

	/** The own key, '' when there is none; null until first asked. */
	private static ?string $own_key = null;

	/** Where the generated key lives. */
	public static function key_file(): string {
		return WP_CONTENT_DIR . '/' . self::KEY_FILE;
	}

	/** Forget the cached key. For tests, and for after the file is replaced. */
	public static function reset_key(): void {
		self::$own_key = null;
	}

	/**
	 * The plugin's own 32-byte key, created on first use; '' if there is none.
	 */
	private static function own_key(): string {
		if ( null !== self::$own_key ) {
			return self::$own_key;
		}

		if ( ! function_exists( 'sodium_crypto_secretbox' ) || ! function_exists( 'random_bytes' ) ) {
			return self::$own_key = '';
		}

		// A constant wins: it is where a site keeps it if the file is not an
		// option, and it survives anything that is done to wp-content. Hashed,
		// so any string of any length makes a key of the right size.
		if ( defined( self::KEY_CONSTANT ) && '' !== (string) constant( self::KEY_CONSTANT ) ) {
			return self::$own_key = hash( 'sha256', 'mmoa|' . (string) constant( self::KEY_CONSTANT ), true );
		}

		$key = self::read_key_file();

		return self::$own_key = '' !== $key ? $key : self::create_key_file();
	}

	private static function read_key_file(): string {
		$path = self::key_file();

		if ( ! is_readable( $path ) ) {
			return '';
		}

		$contents = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! preg_match( '/MMOA_KEY:([A-Za-z0-9+\/=]+)/', $contents, $m ) ) {
			return '';
		}

		$key = base64_decode( $m[1], true );

		return false !== $key && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $key ) ? $key : '';
	}

	/**
	 * Write a new key, unless another request just did.
	 *
	 * Opened with 'x', which fails if the file exists: two requests arriving
	 * together must not each write a key and encrypt with different ones. The
	 * loser reads the winner's instead.
	 *
	 * A PHP file that exits, so a browser that asks for it gets nothing.
	 */
	private static function create_key_file(): string {
		$path   = self::key_file();
		$key    = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$handle = @fopen( $path, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors

		if ( false === $handle ) {
			// Already there, or wp-content is not writable - either way, what
			// is on disk is the answer.
			return self::read_key_file();
		}

		fwrite( // phpcs:ignore WordPress.WP.AlternativeFunctions
			$handle,
			"<?php\n" .
			"// MME-Mail to SMTP: the key its saved passwords are encrypted with.\n" .
			"// Do not delete it, and keep it with your backups: without it the\n" .
			"// passwords cannot be read and every connection must be set up again.\n" .
			"exit;\n" .
			'// MMOA_KEY:' . base64_encode( $key ) . "\n"
		);
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		// Only trusted once it reads back - a full disk can leave a file that
		// exists and holds nothing.
		return self::read_key_file() === $key ? $key : '';
	}

	private static function salts_available(): bool {
		return function_exists( 'sodium_crypto_secretbox' )
			&& function_exists( 'random_bytes' )
			&& defined( 'AUTH_KEY' )
			&& defined( 'SECURE_AUTH_SALT' );
	}

	private static function salt_key(): string {
		return hash( 'sha256', AUTH_KEY . '|mmoa|' . SECURE_AUTH_SALT, true );
	}

	private function encrypt( string $plaintext ): string {
		$own = self::own_key();

		if ( '' !== $own ) {
			return 'v2:' . self::seal( $plaintext, $own );
		}

		if ( self::salts_available() ) {
			return 'v1:' . self::seal( $plaintext, self::salt_key() );
		}

		return 'plain:' . base64_encode( $plaintext );
	}

	private function decrypt( string $stored ): string {
		if ( 0 === strpos( $stored, 'plain:' ) ) {
			return (string) base64_decode( substr( $stored, 6 ), true );
		}

		if ( 0 === strpos( $stored, 'v2:' ) ) {
			$own   = self::own_key();
			$plain = '' === $own ? '' : self::open( substr( $stored, 3 ), $own );

			// A site that sets MMOA_ENCRYPTION_KEY after credentials were
			// already sealed with the generated file must not lose them: the
			// constant wins for everything new, the file still opens the old.
			if ( '' === $plain && defined( self::KEY_CONSTANT ) ) {
				$file  = self::read_key_file();
				$plain = '' === $file || $file === $own ? '' : self::open( substr( $stored, 3 ), $file );
			}

			return $plain;
		}

		// Returns '' if the salts changed since the value was written - treat
		// it as "not configured" rather than fataling. is_unreadable() is what
		// tells the two apart for the screen.
		if ( 0 === strpos( $stored, 'v1:' ) && self::salts_available() ) {
			return self::open( substr( $stored, 3 ), self::salt_key() );
		}

		return '';
	}

	private static function seal( string $plaintext, string $key ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return base64_encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, $key ) );
	}

	private static function open( string $encoded, string $key ): string {
		$raw = base64_decode( $encoded, true );

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$key
		);

		return false === $plain ? '' : $plain;
	}

	/**
	 * Move every credential still on the salts' key to the plugin's own.
	 *
	 * Runs once: the flag is set after a pass. Every slot is in the one option -
	 * the backup connection's, and Pro's alert webhooks - so one pass covers
	 * them all. A v1 value that no longer opens is left exactly as it is; the
	 * salts already changed under it, nothing can recover it, and the screen
	 * reports it as unreadable.
	 *
	 * @return int How many were moved.
	 */
	public static function migrate(): int {
		if ( get_option( self::MIGRATED_OPTION ) || '' === self::own_key() || ! self::salts_available() ) {
			return 0;
		}

		$stored = get_option( self::OPTION, [] );
		$moved  = 0;

		if ( is_array( $stored ) ) {
			foreach ( $stored as $key => $value ) {
				if ( ! is_string( $value ) || 0 !== strpos( $value, 'v1:' ) ) {
					continue;
				}

				$plain = self::open( substr( $value, 3 ), self::salt_key() );

				if ( '' !== $plain ) {
					$stored[ $key ] = 'v2:' . self::seal( $plain, self::own_key() );
					++$moved;
				}
			}

			if ( $moved ) {
				update_option( self::OPTION, $stored, false );
			}
		}

		// Autoloaded: once it is set, asking is free on every request after.
		update_option( self::MIGRATED_OPTION, 1, true );

		return $moved;
	}

	/** Remove the generated key file. Uninstall only. */
	public static function delete_key_file(): void {
		$path = self::key_file();

		if ( is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
		}

		self::$own_key = null;
	}
}
