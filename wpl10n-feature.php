<?php
/**
 * Plugin Name: WP L10n Feature
 */

/**
 * The main plugin class.
 */
class WPL10n_Feature {

	/**
	 * An array of domains and their translations.
	 *
	 * @var array
	 */
	private $translations = array();

	/**
	 * Class constructor.
	 */
	public function __construct() {
		add_filter( 'override_load_textdomain', array( $this, 'override_load_textdomain' ), 10, 4);
		add_filter( 'gettext', array( $this, 'override_gettext' ), 10, 3 );
		add_filter( 'gettext_with_context', array( $this, 'override_gettext_with_context' ), 10, 4 );
		// TODO: Handle using the $plural expression.
		add_filter( 'ngettext', array( $this, 'override_ngettext' ), 10, 5 );
	}

	/**
	 * Get translations from a JSON file.
	 * This function does the following:
	 *     - If the file doesn't exist, create it from the .mo file.
	 *     - If the .mo file is newer than the .json file, regenerate the .json file.
	 *     - If the .json file exists, return the translations.
	 *     - If the .json file cannot be created, return false.
	 *
	 * @param string $domain Text domain. Unique identifier for retrieving translated strings.
	 * @param string $mofile Path to the MO file.
	 * @param string $locale The locale of the file.
	 *
	 * @return array|bool Array of translations or false if the JSON file cannot be created.
	 */
	public function get_translations_from_file( $domain, $mofile, $locale ) {

		// TODO: If the file exists, check the date. If the date is older than the .mo file, regenerate the .json file.
		$json_path = str_replace( '.mo', '.json', $mofile );

		// Just init an empty array to avoid errors down the line.
		$translations = array();
		if ( ! file_exists( $json_path ) ) {
			global $wp_filesystem;
			if ( ! $wp_filesystem ) {
				require_once ABSPATH . '/wp-admin/includes/file.php';
				WP_Filesystem();
			}

			$mo = new \Mo();
			$mo->import_from_file( $mofile );

			// Get the Plural Forms header from the MO file.
			$plural = 'nplurals=2; plural=n != 1;';
			if ( isset( $mo->headers['Plural-Forms'] ) ) {
				$plural = $mo->headers['Plural-Forms'];
			}

			// Build the JSON file structure.
			$json_content = array(
				'translation-revision-date' => filemtime( $mofile ),
				'generator'                 => 'WordPress',
				'domain'                    => 'messages',
				'locale_data'               => array(
					'messages'              => array(
						'' => array(
							'domain'       => 'messages',
							'plural-forms' => $plural,
							'lang'         => $locale,
						),
					),
				)
			);

			// Add translations to the JSON file.
			foreach ( $mo->entries as $key => $entry ) {
				$json_content['locale_data']['messages'][ $key ] = $entry->translations;
			}

			// Try to write the JSON file. If it fails, return false to fallback to the .mo file.
			// @TODO: Remove the JSON_PRETTY_PRINT flag to generate minified JSON files.
			if ( ! $wp_filesystem->put_contents( $json_path, json_encode( $json_content, JSON_PRETTY_PRINT ) ) ) {
				return false;
			}

			return $json_content['locale_data']['messages'][ $key ];
		}

		// Read the JSON file and save translations in the $translations variable.
		$json_content = json_decode( file_get_contents( $json_path ), true );

		// If the .mo file is newer than the .json file, delete the .json file
		// so it can be re-generated on the next pageload.
		if ( filemtime( $mofile ) > (int) $json_content['translation-revision-date'] ) {
			$wp_filesystem->delete( $json_path );
			return false;
		}
		return $json_content['locale_data']['messages'];
	}

	/**
	 * Hooks in the `override_load_textdomain` filter to load translations from a JSON file.
	 *
	 * @param bool   $override Whether to override the textdomain load. Default false.
	 * @param string $domain   Text domain. Unique identifier for retrieving translated strings.
	 * @param string $mofile   Path to the MO file.
	 * @param string $locale   The locale of the file.
	 *
	 * @return bool True if the translations were loaded from the JSON file, false otherwise.
	 */
	public function override_load_textdomain( $override, $domain, $mofile, $locale ) {
		/**
		 * Filters MO file path for loading translations for a specific text domain.
		 *
		 * @since 2.9.0
		 *
		 * @param string $mofile Path to the MO file.
		 * @param string $domain Text domain. Unique identifier for retrieving translated strings.
		 */
		$mofile = apply_filters( 'load_textdomain_mofile', $mofile, $domain );

		/**
		 * Fires before the MO translation file is loaded.
		 *
		 * @since 2.9.0
		 *
		 * @param string $domain Text domain. Unique identifier for retrieving translated strings.
		 * @param string $mofile Path to the .mo file.
		 */
		do_action( 'load_textdomain', $domain, $mofile );

		$translations = $this->get_translations_from_file( $domain, $mofile, $locale );

		if ( ! $translations ) {
			return false;
		}

		$this->translations[ $domain ] = isset( $this->translations[ $domain ] )
			? $this->translations[ $domain ]
			: array();

		$this->translations[ $domain ] = array_merge(
			$this->translations[ $domain ],
			$this->get_translations_from_file( $domain, $mofile, $locale )
		);

		return true;
	}

	/**
	 * Hooks in the `gettext` filter to load translations from a JSON file.
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Text to translate.
	 * @param string $domain      Text domain. Unique identifier for retrieving translated strings.
	 *
	 * @return string Translated text.
	 */
	public function override_gettext( $translation, $text, $domain ) {
		if ( ! isset( $this->translations[ $domain ] ) ) {
			return $translation;
		}

		if ( ! empty( $this->translations[ $domain ][ $text ] ) ) {
			return $this->translations[ $domain ][ $text ][0];
		}

		return $translation;
	}

	/**
	 * Hooks in the `gettext_with_context` filter to load translations from a JSON file.
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Text to translate.
	 * @param string $context     Context information for the translators.
	 * @param string $domain      Text domain. Unique identifier for retrieving translated strings.
	 *
	 * @return string Translated text.
	 */
	public function override_gettext_with_context( $translation, $text, $context, $domain ) {
		if ( ! isset( $this->translations[ $domain ] ) ) {
			return $translation;
		}

		if ( ! empty( $this->translations[ $domain ][ "$context$text" ] ) ) {
			return $this->translations[ $domain ][ "$context$text" ][0];
		}

		return $translation;
	}

	/**
	 * Hooks in the `ngettext` filter to load translations from a JSON file.
	 *
	 * @param string $translation Translated text.
	 * @param string $single      The text to be translated.
	 * @param string $plural      The plural form of the text to be translated.
	 * @param int    $number      The number to use to find the translation for the
	 * 						      respective plural form.
	 * @param string $domain      Text domain. Unique identifier for retrieving translated strings.
	 *
	 * @return string Translated text.
	 */
	public function override_ngettext( $translation, $single, $plural, $number, $domain ) {
		if ( ! isset( $this->translations[ $domain ] ) ) {
			return $translation;
		}

		if ( ! empty( $this->translations[ $domain ][ $single ] ) ) {
			return $this->translations[ $domain ][ $single ][ $number == 1 ? 0 : 1 ];
		}

		return $translation;
	}
}

new WPL10n_Feature();
