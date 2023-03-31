<?php
/**
 * Plugin Name: WP L10n Feature
 */

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
 */
function wpl10n_get_translations( $domain, $mofile, $locale ) {

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
		if ( ! $wp_filesystem->put_contents( $json_path, json_encode( $json_content, JSON_PRETTY_PRINT ) ) ) {
			return false;
		}

		return $json_content['locale_data']['messages'][ $key ];
	}

	// Read the JSON file and save translations in the $translations variable.
	$json_content = json_decode( file_get_contents( $json_path ), true );
	return $json_content['locale_data']['messages'];
}

add_filter( 'override_load_textdomain', function( $override, $domain, $mofile, $locale ) {
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

	$translations = wpl10n_get_translations( $domain, $mofile, $locale );

	// Replace the translations with the ones from the JSON file for simple strings.
	add_filter( "gettext_{$domain}", function( $translation, $text, $domain ) use ( $translations ) {
		if ( ! empty( $translations[ $text ] ) ) {
			return $translations[ $text ][0];
		}
	}, 10, 3 );

	// Replace the translations with the ones from the JSON file for strings with context.
	add_filter( "gettext_with_context_{$domain}", function( $translation, $text, $context, $domain ) use ( $translations ) {
		if ( ! empty( $translations[ "$context$text" ] ) ) {
			return $translations[ $text ][0];
		}
	}, 10, 4 );


	// Replace the translations with the ones from the JSON file for strings with plurals.
	// TODO: Handle using the $plural expression.
	add_filter( "ngettext_{$domain}", function( $translation, $single, $plural, $number, $domain ) use ( $translations ) {
		if ( ! empty( $translations[ $single ] ) ) {
			return $translations[ $single ][ $number == 1 ? 0 : 1 ];
		}
	}, 10, 5 );

	return true;
}, 10, 4);

