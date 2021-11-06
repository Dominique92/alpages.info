<?php
/**
*
* This file is part of the french language pack for the GeoBB Forum Software package.
*
* @copyright (c) 2015 Dominique Cavailhez
* @license GNU General Public License, version 2 (GPL-2.0)
*
*/

/**
* DO NOT CHANGE
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = array();
}

// DEVELOPERS PLEASE NOTE
//
// All language files should use UTF-8 as their encoding and the files must not contain a BOM.
//
// Placeholders can now contain order information, e.g. instead of
// 'Page %s of %s' you can (and should) write 'Page %1$s of %2$s', this allows
// translators to re-order the output of data while ensuring it remains correct
//
// You do not need this where single placeholders are used, e.g. 'Message %d' is fine
// equally where a string contains only two placeholders which are used to wrap text
// in a url you again do not need to specify an order e.g., 'Click %sHERE%s' is fine
//
// Some characters you may want to copy&paste:
// ’ « » “ ” …
//

$lang = array_merge($lang, array(
//TODO ARCHI reprendre tous les textes dans GeoBB
	// Fil d'ariane
	'ACP_SHORT' => 'Administration',
	'MCP_SHORT' => 'Modération',

	// News
	'CREATION' => 'Création de',
	'MODIFICATION' => 'Modification de',
	'COMMENT' => 'Commentaire sur',

	// Posting
	'SUBJECT' => 'Nom',

	// Help
	'FAQ' => 'Aide',
	'FAQ_EXPLAIN' => 'Documentation des fonctions du site',

	// Footer
	'FINAL_INTEGRATION_BY' =>
		'&copy; Dominique Cavailhez 2016 : <a href="https://github.com/Dominique92/GeoBB">GeoBB</a> &amp; '.
		'<a href="https://github.com/Dominique92/MyOl">MyOl</a> '.
		'based on <a href="https://www.phpbb.com/">phpBB</a>',
));
