<?php
/**
* Extractions de données géographiques
*
* @copyright (c) Dominique Cavailhez 2015
* @license GNU General Public License, version 2 (GPL-2.0)
*
*/

define('IN_PHPBB', true);
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : '../../../';
$phpEx = substr(strrchr(__FILE__, '.'), 1);
include($phpbb_root_path . 'common.' . $phpEx);

// Start session management
$user->session_begin();
$auth->acl($user->data);
$user->setup();

$forums = request_var ('forums', ''); // List of forum to include "1,2,3"
$priority = request_var ('priority', 0); // topic_id à affichage prioritaire
$exclude = request_var ('exclude', 0); // topic_id to exclude
$limit = request_var ('limit', 250); // Nombre de points maximum

$bboxs = explode (',', $bbox = request_var ('bbox', '-180,-90,180,90'));
$bbox_sql =
	$bboxs[0].' '.$bboxs[1].','.
	$bboxs[2].' '.$bboxs[1].','.
	$bboxs[2].' '.$bboxs[3].','.
	$bboxs[0].' '.$bboxs[3].','.
	$bboxs[0].' '.$bboxs[1];

$diagBbox = hypot ($bboxs[2] - $bboxs[0], $bboxs[3] - $bboxs[1]); // Hypothènuse de la bbox

// Recherche des points dans la bbox
$sql_array = [
	'SELECT' => [
		'post_subject',
		'post_id',
		't.topic_id',
		'f.forum_id',
		'forum_image',
		'forum_desc',
		'ST_AsGeoJSON(geom) AS geojson',
	],
	'FROM' => [POSTS_TABLE => 'p'],
	'LEFT_JOIN' => [[
		'FROM' => [TOPICS_TABLE => 't'],
		'ON' => 't.topic_id = p.topic_id',
	],[
		'FROM' => [FORUMS_TABLE => 'f'],
		'ON' => 'f.forum_id = p.forum_id',
	]],
	'WHERE' => [
		$forums ? "f.forum_id IN ($forums)" : 'TRUE',
		't.topic_id != '.$exclude,
		'geom IS NOT NULL',
		"Intersects (GeomFromText ('POLYGON (($bbox_sql))',4326),geom)",
		'post_visibility = '.ITEM_APPROVED,
		'OR' => [
			't.topic_first_post_id = p.post_id',
			'forum_desc LIKE "%[all=%"', //TODO DELETE ?
		],
	],
	'ORDER_BY' => "CASE WHEN f.forum_id = $priority THEN 0 ELSE left_id END",
];

// Build query
if (is_array ($sql_array ['SELECT']))
	$sql_array ['SELECT'] = implode (',', $sql_array ['SELECT']);

if (is_array ($sql_array ['WHERE'])) {
	foreach ($sql_array ['WHERE'] AS $k=>&$w)
		if (is_array ($w))
			$w = '('.implode (" $k ", $w).')';
	$sql_array ['WHERE'] = implode (' AND ', $sql_array ['WHERE']);
}
$sql = $db->sql_build_query('SELECT', $sql_array);
$result = $db->sql_query_limit($sql, $limit);

// Ajoute l'adresse complète aux images d'icones
$sp = explode ('/', getenv('REQUEST_SCHEME'));
$ri = explode ('/ext/', getenv('REQUEST_URI'));
$bu = $sp[0].'://'.getenv('SERVER_NAME').$ri[0].'/';

$features = $signatures = [];
$ampl = 0x100; // Max color delta
while ($row = $db->sql_fetchrow($result)) {
	// Color calculation
	preg_match_all('/[0-9\.]+/',$row['geojson'],$coords);
	$x[0] = $coords[0][0] * 30000 % $ampl; // From lon
	$x[1] = $coords[0][1] * 30000 % $ampl; // From lat
	$x[2] = $ampl - ($x[0] + $x[1]) / 2;
	$color = '#';
	for ($c = 0; $c < 3; $c++) // Chacune des 3 couleurs primaires
		$color .= substr (dechex (0x100 + $x[$c]), -2); // 0x100 for left hex 0 / the 2 last hex int chars

	preg_match('/([a-z_]+)\.png/', @$row['forum_image'], $match);
	$properties = [
		'name' => $row['post_subject'],
		'id' => $row['topic_id'],
		'type_id' => $row['forum_id'],
		'post_id' => $row['post_id'],
		'type' => $match ? $match[1] : '',
		'icon' => $row['forum_image'] ? $bu.$row['forum_image'] : null,
		'color' => $color,
	];

	// Spécific color defined in forum desc
	//TODO DELETE / REVOIR ???
	preg_match('/\[color=([a-z]+)\]/i', html_entity_decode ($row['forum_desc']), $colors);
	if (count ($colors))
		$properties['color'] = $colors[1];

	// Disjoin points having the same coordinate
	$geophp = json_decode ($row['geojson']);
	if ($geophp->type == 'Point') {
		while (in_array (signature ($geophp->coordinates), $signatures))
			$geophp->coordinates[0] += 0.00001;
		$signatures[] = signature ($geophp->coordinates);
	}

	$features[] = [
		'id' => $row['post_id'],
		'type' => 'Feature',
		'geometry' => $geophp, // On ajoute le tout à la liste à afficher sous la forme d'un "Feature" (Sous forme d'objet PHP)
		'properties' => $properties,
	];
}
$db->sql_freeresult($result);

function signature ($coord) {
	return round ($coord[0], 5).'_'.round ($coord[1], 5);
}


// Formatage du header
$secondes_de_cache = 10;
$ts = gmdate("D, d M Y H:i:s", time() + $secondes_de_cache) . " GMT";
header("Content-disposition: filename=geobb.json");
header("Content-Type: application/json; UTF-8");
header("Content-Transfer-Encoding: binary");
header("Pragma: cache");
header("Expires: $ts");
header("Access-Control-Allow-Origin: *");
header("Cache-Control: max-age=$secondes_de_cache");

// On transforme l'objet PHP en code geoJson
echo json_encode ([
	'type' => 'FeatureCollection',
	'timestamp' => date('c'),
	'features' => $features
]);
