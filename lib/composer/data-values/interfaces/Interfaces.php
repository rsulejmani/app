<?php

/**
 * Entry point of the DataValues Interfaces library.
 *
 * @since 0.1
 * @codeCoverageIgnore
 *
 * @license GPL-2.0+
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 */

if ( defined( 'DATAVALUES_INTERFACES_VERSION' ) ) {
	// Do not initialize more than once.
	return 1;
}

define( 'DATAVALUES_INTERFACES_VERSION', '0.2.2' );

if ( defined( 'MEDIAWIKI' ) ) {
	$GLOBALS['wgExtensionCredits']['datavalues'][] = array(
		'path' => __DIR__,
		'name' => 'DataValues Interfaces',
		'version' => DATAVALUES_INTERFACES_VERSION,
		'author' => array(
			'[https://www.mediawiki.org/wiki/User:Jeroen_De_Dauw Jeroen De Dauw]',
		),
		'url' => 'https://github.com/DataValues/Interfaces',
		'description' => 'Defines interfaces for ValueParsers, ValueFormatters and ValueValidators',
		'license-name' => 'GPL-2.0+'
	);
}
