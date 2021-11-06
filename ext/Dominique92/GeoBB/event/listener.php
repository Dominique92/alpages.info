<?php
/**
 *
 * @package GeoBB
 * @copyright (c) 2016 Dominique Cavailhez
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 *
 */
//TODO ALPAGES BEST recherche par département / commune
//TODO TEST ALPAGES post modif mail inscription
//TODO ALPAGES ajouter une information automatique sur les fiches alpages : par exemple les liens pour les départements 04 et 05
//TODO BEST upgrade ALPAGES/CHEM : remplacer forumdesc par [hide]... (et bbcode !)
//TODO AFTER WRI enlever /assets/wri
//TODO CHEM BEST permutations POSTS dans le template modération : déplacer les fichiers la permutation des posts => event/mcp_topic_postrow_post_before.html
//TODO CHEM ne pas afficher les points en doublon (flux wri, prc, c2c)

namespace Dominique92\GeoBB\event;

if (!defined('IN_PHPBB'))
{
	exit;
}

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
	// List of externals
	public function __construct(
		\phpbb\db\driver\driver_interface $db,
		\phpbb\request\request_interface $request,
		\phpbb\template\template $template,
		\phpbb\user $user,
		\phpbb\auth\auth $auth
	) {
		$this->db = $db;
		$this->request = $request;
		$this->template = $template;
		$this->user = $user;
		$this->auth = $auth;
	}

	// List of hooks and related functions
	// We find the calling point by searching in the software of PhpBB 3.x: "event core.<XXX>"
	static public function getSubscribedEvents() {
		// For debug, Varnish will not be caching pages where you are setting a cookie
		if (defined('TRACES_DOM'))
			setcookie('disable-varnish', microtime(true), time()+600, '/');

		return [
			// All
			'core.page_footer' => 'page_footer',

			// Index
			'core.display_forums_modify_row' => 'display_forums_modify_row',
			'core.index_modify_page_title' => 'index_modify_page_title',

			// Viewtopic
			'core.viewtopic_get_post_data' => 'viewtopic_get_post_data',
			'core.viewtopic_modify_post_data' => 'viewtopic_modify_post_data',
			'core.viewtopic_post_row_after' => 'viewtopic_post_row_after',
			'core.viewtopic_post_rowset_data' => 'viewtopic_post_rowset_data',
			'core.viewtopic_modify_post_row' => 'viewtopic_modify_post_row',

			// Posting
			'core.modify_posting_auth' => 'modify_posting_auth',
			'core.modify_posting_parameters' => 'modify_posting_parameters',
			'core.posting_modify_submission_errors' => 'posting_modify_submission_errors',
			'core.submit_post_modify_sql_data' => 'submit_post_modify_sql_data',
			'core.posting_modify_template_vars' => 'posting_modify_template_vars',
			'core.modify_submit_notification_data' => 'modify_submit_notification_data',

			// Resize images
			'core.download_file_send_to_browser_before' => 'download_file_send_to_browser_before',

			// Registration
			'core.ucp_register_welcome_email_before' => 'ucp_register_welcome_email_before',
		];
	}


	/**
		ALL
	*/
	function page_footer() {
//		ob_start();var_dump($this->template);echo'template = '.ob_get_clean(); // VISUALISATION VARIABLES TEMPLATE

		/*//TODO DELETE ?
		// Force le style
		$style_name = request_var ('style', '');
		if ($style_name) {
			$sql = 'SELECT * FROM '.STYLES_TABLE.' WHERE style_name = "'.$style_name.'"';
			$result = $this->db->sql_query ($sql);
			$row = $this->db->sql_fetchrow ($result);
			$this->db->sql_freeresult ($result);
			if ($row)
				$vars['style_id'] =  $row['style_id'];
		}
		*/

		// list of gis.php arguments for chemineur layer selector
		$sql = "
			SELECT category.forum_id AS category_id,
				category.forum_name AS category_name,
				forum.forum_id
			FROM ".FORUMS_TABLE." AS forum
			JOIN ".FORUMS_TABLE." AS category ON category.forum_id = forum.parent_id
			WHERE forum.forum_desc LIKE '%first=%'
			ORDER BY forum.left_id
		";
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
			$cats[$row['category_name']] [] = $row['forum_id'];
		$this->db->sql_freeresult($result);

		if($cats)
			foreach($cats AS $k=>$v)
				$this->template->assign_block_vars('chemcat', [
					'CAT' => $k,
					'ARGS' => implode (',', $v),
				]);

		// Includes language files for this extension
		$ns = explode ('\\', __NAMESPACE__);
		$this->user->add_lang_ext($ns[0].'/'.$ns[1], 'common');

		// Misc template values
		$this->template->assign_vars([
			'EXT_DIR' => 'ext/'.$ns[0].'/'.$ns[1].'/', // Répertoire de l'extension
			'META_ROBOTS' => defined('META_ROBOTS') ? META_ROBOTS : '',
		]);

		// Assign post contents to some templates variables
		$mode = $this-> request->variable('mode', '');
		$msgs = [
			'Conditions d\'utilisation' => 'L_TERMS_OF_USE',
			'Politique de confidentialité' => 'L_PRIVACY_POLICY',
			'Bienvenue '.$this->user->style['style_name'] => 'GEO_PRESENTATION',
			'Aide' => 'GEO_URL_AIDE',
			$mode == 'terms' ? 'Conditions d\'utilisation' : 'Politique de confidentialité' => 'AGREEMENT_TEXT',
		];
		foreach ($msgs AS $k=>$v) {
			$sql = 'SELECT post_text, bbcode_uid, bbcode_bitfield FROM '.POSTS_TABLE.' WHERE post_subject = "'.$k.'" ORDER BY post_id';
			$result = $this->db->sql_query($sql);
			$row = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			if ($row) {
				$this->messages[$k] = generate_text_for_display($row['post_text'],
					$row['bbcode_uid'],
					$row['bbcode_bitfield'],
					OPTION_FLAG_BBCODE,
					true
				);
				$this->template->assign_var (
					$v,
					$this->messages[$k]
				);
			}
		}
	}


	/**
		INDEX.PHP
	*/
	// Add a button to create a topic in front of the list of forums
	function display_forums_modify_row ($vars) {
		$row = $vars['row'];

		if ($this->auth->acl_get('f_post', $row['forum_id']) &&
			$row['forum_type'] == FORUM_POST)
			$row['forum_name'] .= ' &nbsp; '.
				'<a class="button" href="./posting.php?mode=post&f='.$row['forum_id'].'" title="Créer un nouveau sujet '.strtolower($row['forum_name']).'">Créer</a>';

		$vars['row'] = $row;
	}

	function index_modify_page_title ($vars) {
		$this->geobb_activate_map('[all=accueil]');

		// Show the most recents posts on the home page
		$news = request_var ('news', 15); // More news count
		$this->template->assign_var ('PLUS_NOUVELLES', $news * 2);

		$sql = "
			SELECT p.post_id, p.poster_id, p.post_edit_time,
				t.topic_id, topic_title,topic_first_post_id,
				f.forum_id, f.forum_name, f.forum_image,
				u.username,
				IF(post_edit_time > post_time, post_edit_time, post_time) AS post_or_edit_time
			FROM	 ".TOPICS_TABLE." AS t
				JOIN ".FORUMS_TABLE." AS f USING (forum_id)
				JOIN ".POSTS_TABLE." AS p ON (p.post_id = t.topic_last_post_id)
				JOIN ".USERS_TABLE."  AS u ON (p.poster_id = u.user_id)
			WHERE post_visibility = ".ITEM_APPROVED."
			ORDER BY post_or_edit_time DESC
			LIMIT $news
		";
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
			if ($this->auth->acl_get('f_read', $row['forum_id'])) {
				//TODO BEST BUG compte les posts des forums cachés dans le nb max
				$row['post_or_edit_time'] = '<span>'.$this->user->format_date ($row['post_or_edit_time']).'</span>';
				$this->template->assign_block_vars('news', array_change_key_case ($row, CASE_UPPER));
			}
		$this->db->sql_freeresult($result);

	}

	// Docs presentation
	/*//TODO DELETE ??? (utilisé dans doc)
	function index_forum_tree($parent, $num) {
		$last_num = 1;
		$sql = "
			SELECT forum_id, forum_name, forum_type
			FROM ".FORUMS_TABLE."
			WHERE parent_id = $parent
			ORDER BY left_id ASC";
		$result_forum = $this->db->sql_query($sql);
		while ($row_forum = $this->db->sql_fetchrow($result_forum)) {
			$forum_num = $num.$last_num++.'.';
			$this->template->assign_block_vars('forum_tree', [
				'FORUM' => $row_forum['forum_type'],
				'LEVEL' => count (explode ('.', $forum_num)) - 1,
				'NUM' => $forum_num,
				'TITLE' => $row_forum['forum_name'],
				'FORUM_ID' => $row_forum['forum_id'],
				'AUTH' => $this->auth->acl_get('m_edit', $row_forum['forum_id']),
			]);
			if($row_forum['forum_type']) { // C'est un forum (pas une catégotie)
				$sql = "
					SELECT post_id, post_subject, post_text, post_attachment, bbcode_uid, bbcode_bitfield, topic_id
					FROM ".POSTS_TABLE." AS p
						JOIN ".TOPICS_TABLE." AS t USING (topic_id)
					WHERE p.post_id = t.topic_first_post_id AND
						p.forum_id = {$row_forum['forum_id']}
					ORDER BY post_subject";
				$result_post = $this->db->sql_query($sql);
				$sub_num = 1;
				while ($row_post = $this->db->sql_fetchrow($result_post)) {
					preg_match ('/^([0-9\.]+) (.*)$/', $row_post['post_subject'], $titles);
					$post_text = generate_text_for_display ($row_post['post_text'],
						$row_post['bbcode_uid'],
						$row_post['bbcode_bitfield'],
						OPTION_FLAG_BBCODE, true);

					$sql = "
						SELECT *
						FROM ".ATTACHMENTS_TABLE."
						WHERE post_msg_id = {$row_post['post_id']}
						ORDER BY filetime DESC";
					$result_attachement = $this->db->sql_query($sql);
					$attachments = [];
					while ($row_attachement = $this->db->sql_fetchrow($result_attachement))
						$attachments[] = $row_attachement;
					$this->db->sql_freeresult($result_attachement);
					$update_count = array();
					if (count($attachments))
						parse_attachments(0, $post_text, $attachments, $update_count);

					$sql = "SELECT COUNT(*) AS nb_posts FROM ".POSTS_TABLE." WHERE topic_id = {$row_post['topic_id']}";
					$result_count = $this->db->sql_query($sql);
					$row_count = $this->db->sql_fetchrow($result_count);
					$this->db->sql_freeresult($result_count);

					//$post_num = $forum_num.$sub_num++.'.';
					$this->template->assign_block_vars('forum_tree', [
						'LEVEL' => count (explode ('.', $forum_num)),
						'NUM' => $forum_num.$sub_num++.'.',
						'TITLE' => count ($titles) ? $titles[2] : $row_post['post_subject'],
						'TEXT' => $post_text,
						'FORUM_ID' => $row_forum['forum_id'],
						'TOPIC_ID' => $row_post['topic_id'],
						'POST_ID' => $row_post['post_id'],
						'NB_COMMENTS' => $row_count['nb_posts'] - 1,
						'AUTH' => $this->auth->acl_get('m_edit', $row_forum['forum_id']),
					]);
				}
				$this->db->sql_freeresult($result_post);
			}
			$this->index_forum_tree ($row_forum['forum_id'], $forum_num);
		}
		$this->db->sql_freeresult($result_forum);
	}*/

	/**
		VIEWTOPIC.PHP
	*/
	// Appelé avant la requette SQL qui récupère les données des posts
	function viewtopic_get_post_data($vars) {
		$sql = 'SHOW columns FROM '.POSTS_TABLE.' LIKE "geom"';
		$result = $this->db->sql_query($sql);
		if ($this->db->sql_fetchrow($result)) {
			// Insère la conversion du champ geom en format WKT dans la requette SQL
			$sql_ary = $vars['sql_ary'];
			$sql_ary['SELECT'] .=
				', ST_AsGeoJSON(geom) AS geojson'.
				', ST_AsText(ST_Centroid(ST_Envelope(geom))) AS centerwkt'.
				', ST_Area(geom) AS area';
			$vars['sql_ary'] = $sql_ary;
		}
		$this->db->sql_freeresult($result);
	}

	// Called during first pass on post data that reads phpbb-posts SQL data
	function viewtopic_post_rowset_data($vars) {
		// Update the database with the automatic data
		$post_data = $vars['row'];

		// Extraction of the center for all actions
		preg_match_all ('/([0-9\.]+)/', $post_data['centerwkt'], $center);

		$update = []; // Datas to be updated
		foreach ($post_data AS $k=>$v)
			if (!$v)
				switch ($k) {
//TODO ALPAGES Automatiser : Année où la fiche de l'alpage a été renseignée ou actualisée

					case 'geo_surface':
						if ($post_data['area'] && $center[0])
							$update[$k] =
								round ($post_data['area']
									* 1111 // hm par ° delta latitude
									* 1111 * sin ($center[0][1] * M_PI / 180) // hm par ° delta longitude
								);
						break;

					// Calcul de l'altitude avec IGN
					//TODO CHEM Altitude en dehors de la France
					case 'geo_altitude':
						if ($center[0]) {
							global $geo_keys;
							$api = "http://wxs.ign.fr/{$geo_keys['IGN']}/alti/rest/elevation.json?lon={$center[0][0]}&lat={$center[0][1]}&zonly=true";
							preg_match ('/([0-9]+)/', @file_get_contents($api), $altitude);
							if ($altitude)
								$update[$k] = $altitude[1];
						}
						break;

					case 'geo_commune':
						//TODO BUG : calcule geo_commune sur un post d'un forum normal
						//TODO BUG BEST : pas de commune = "~ " (un espace de trop)
						$nominatim = json_decode (@file_get_contents (
							"https://nominatim.openstreetmap.org/reverse?format=json&lon={$center[0][0]}&lat={$center[0][1]}",
							false,
							stream_context_create (array ('http' => array('header' => "User-Agent: StevesCleverAddressScript 3.7.6\r\n")))
						));
						$update[$k] = @$nominatim->address->postcode.' '.@(
							$nominatim->address->town ?:
							$nominatim->address->city ?:
							$nominatim->address->suburb  ?:
							$nominatim->address->village ?:
							$nominatim->address->hamlet ?:
							$nominatim->address->neighbourhood ?:
							$nominatim->address->quarter
						);
						break;

					// Infos refuges.info
					case 'geo_massif':
					case 'geo_reserve':
					case 'geo_ign':
						if ($center[0]) {
							$massif = ''; $reserve = ''; $igns = [];
							$url = "http://www.refuges.info/api/polygones?type_polygon=1,3,12&bbox={$center[0][0]},{$center[0][1]},{$center[0][0]},{$center[0][1]}";
							$wri_export = @file_get_contents($url);
							if ($wri_export) {
								$fs = json_decode($wri_export)->features;
								foreach($fs AS $f)
									switch ($f->properties->type->type) {
										case 'massif':
											$massif = $f->properties->nom;
											break;
										case 'zone réglementée':
											$reserve = $f->properties->nom;
											break;
										case 'carte':
											$ms = explode(' ', str_replace ('-', ' ', $f->properties->nom));
											$nom_carte = str_replace ('-', ' ', str_replace (' - ', ' : ', $f->properties->nom));
											$igns[] = "<a target=\"_BLANK\" href=\"https://ignrando.fr/boutique/catalogsearch/result/?q={$ms[1]}\">$nom_carte</a>";
									}
								if (array_key_exists('geo_massif', $post_data))
									$update['geo_massif'] = $massif;
								if (array_key_exists('geo_reserve', $post_data))
									$update['geo_reserve'] = $reserve;
								if (array_key_exists('geo_ign', $post_data))
									$update['geo_ign'] = implode ('<br/>', $igns);
							}
						}
				}

		//Stores post SQL data for further processing (viewtopic proceeds in 2 steps)
		$this->all_post_data[$vars['row']['post_id']] =  array_merge ($post_data, $update);

		// Clean ~ automatic feilds / replace by -field
		//TODO AFTER CHEM remove when no more ~
		$af = ['geo_surface', 'geo_altitude', 'geo_commune', 'geo_massif', 'geo_reserve', 'geo_ign', 'geo_contains'];
		foreach ($af AS $v)
			if (array_key_exists ($v, $post_data))
				$automatic_fields [] = $v;
		$sql = "SELECT post_id,".implode(',', $automatic_fields).
			" FROM ".POSTS_TABLE.
			" WHERE ".implode(" LIKE '%~' OR ", $automatic_fields)." LIKE '%~'";
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
			foreach ($row AS $k=>$v)
				if (substr($v, -1) == '~') {
					$vr = str_replace("'", "\\'", substr($v, 0, -1));
					$sql = "UPDATE phpbb_posts SET $k = '-$vr' WHERE post_id = ".$row['post_id'];
					$this->db->sql_query($sql);
				}
		$this->db->sql_freeresult($result);

		// Update de la base
		if ($update) {
			// Automatic generated begins by -
			foreach ($update AS $k=>$v)
				$update[$k] = '-'.$update[$k];

			$this->db->sql_query (
				'UPDATE '.POSTS_TABLE.
				' SET '.$this->db->sql_build_array('UPDATE',$update).
				' WHERE post_id = '.$post_data['post_id']
			);

			if(defined('TRACES_DOM') && count($update))
				echo"<pre style='background-color:white;color:black;font-size:14px;'>AUTOMATIC DATA = ".var_export($update,true).'</pre>';
		}
	}

	// Assign template variables for images attachments
	function viewtopic_modify_post_data($vars) {
		$this->attachments = $vars['attachments'];
	}
	function viewtopic_post_row_after($vars) {
		if (isset ($this->attachments[$vars['row']['post_id']]))
			foreach ($this->attachments[$vars['row']['post_id']] as $attachment)
				if (!strncmp ($attachment['mimetype'], 'image', 5)) {
					$attachment['DATE'] = str_replace (' 00:00', '', $this->user->format_date($attachment['filetime']));
					$attachment['TEXT_SIZE'] = strlen ($vars['row']['post_text']) * count($vars['attachments']);
					$attachment['POSTER'] = $attachment['poster_id']; //TODO rechercher via SQL le vrai nom de l'auteur
					$this->template->assign_block_vars('postrow.image', array_change_key_case ($attachment, CASE_UPPER));
				}
	}

	// Appelé lors de la deuxième passe sur les données des posts qui prépare dans $post_row les données à afficher sur le post du template
	function viewtopic_modify_post_row($vars) {
		$post_id = $vars['row']['post_id'];
		$this->template->assign_vars ([
			'TOPIC_FIRST_POST_ID' => $vars['topic_data']['topic_first_post_id'],
			'TOPIC_AUTH_EDIT' =>
				$this->auth->acl_get('m_edit', $vars['row']['forum_id']) ||
				$vars['topic_data']['topic_poster'] == $this->user->data['user_id'],
		]);
		$this->geobb_activate_map($vars['topic_data']['forum_desc']);

		// Assign the geo values to the template
		if (isset ($this->all_post_data[$post_id])) {
			$post_data = $this->all_post_data[$post_id]; // Récupère les données SQL du post

			if ($post_data['post_id'] == $vars['topic_data']['topic_first_post_id']) {
				$this->topic_fields('specific_fields', $post_data, $vars['topic_data']['forum_desc']);

				// Assign geo_ vars to template for these used out of topic_fields
				foreach ($post_data AS $k=>$v)
					if (strstr ($k, 'geo') && is_string ($v))
						$this->template->assign_var (strtoupper ($k), $v);
			}
		}
	}


	/**
		POSTING.PHP
	*/
	function modify_posting_auth($vars) {
		// Popule le sélecteur de forum
return;		//TODO CHEM OBSOLETE ????? Voir dans chem !
		preg_match ('/\view=[a-z]+/i', $vars['post_data']['forum_desc'], $view);
		if (count ($view)) {
			$sql = "SELECT forum_id, forum_name, parent_id, forum_type, forum_flags, forum_options, left_id, right_id, forum_desc
				FROM ".FORUMS_TABLE."
				WHERE forum_desc LIKE '%{$view[0]}%'
				ORDER BY left_id ASC";
			$result = $this->db->sql_query($sql);
			while ($row = $this->db->sql_fetchrow($result))
				$forum_list[] = '<option value="' . $row['forum_id'] . '"' .($row['forum_id'] == $vars['forum_id'] ? ' selected="selected"' : ''). '>' . $row['forum_name'] . '</option>';
			$this->db->sql_freeresult($result);
			if (isset ($forum_list))
				$this->template->assign_var ('S_FORUM_SELECT', implode ('', $forum_list));

			// Assign the new forum for creation
			$vars['forum_id'] = request_var('to_forum_id', $vars['forum_id']);

			// Move it
			if ($vars['mode'] == 'edit' && // S'il existe déjà !
				$vars['forum_id'] != $vars['forum_id'])
				move_topics([$vars['post_id']], $vars['forum_id']);
		}
	}

	// Appelé au début pour ajouter des parametres de recherche sql
	function modify_posting_parameters($vars) {
		// Création topic avec le nom d'image
		$forum_image = $this->request->variable('type', '');
		$sql = 'SELECT forum_id FROM '.FORUMS_TABLE.' WHERE forum_image LIKE "%/'.$forum_image.'.%"';
		$result = $this->db->sql_query ($sql);
		$row = $this->db->sql_fetchrow ($result);
		$this->db->sql_freeresult ($result);
		if ($row) // Force le forum
			$vars['forum_id'] = $row['forum_id'];
	}

	// Allows entering a POST with empty text
	function posting_modify_submission_errors($vars) {
		$error = $vars['error'];

		foreach ($error AS $k=>$v)
			if ($v == $this->user->lang['TOO_FEW_CHARS'])
				unset ($error[$k]);

		$vars['error'] = $error;
	}

	// Called when display post page
	function posting_modify_template_vars($vars) {
		$page_data = $vars['page_data'];
		$post_data = $vars['post_data'];

		// Get translation of SQL space data
		if (isset ($post_data['geom'])) {
			$sql = 'SELECT ST_AsGeoJSON(geom) AS geojson'.
				' FROM '.POSTS_TABLE.
				' WHERE post_id = '.$post_data['post_id'];
			$result = $this->db->sql_query($sql);
			$row = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			$page_data['GEOJSON'] = $post_data['geojson'] = $row['geojson'];
		}

		// Unhide geojson field
		$this->request->enable_super_globals();
		$page_data['GEOJSON_TYPE'] = isset($_GET['geom']) ? 'text' : 'hidden';
		$this->request->disable_super_globals();

		// Create a log file with the existing data if there is none
		$this->save_post_data($post_data, $vars['message_parser']->attachment_data, $post_data, true);

		// To prevent an empty title invalidate the full page and input.
		if (!$post_data['post_subject'])
			$page_data['DRAFT_SUBJECT'] = $this->post_name ?: 'Nom';

		$page_data['EDIT_REASON'] = 'Modération'; // For display in news
		$page_data['TOPIC_ID'] = @$post_data['topic_id'];
		$page_data['POST_ID'] = @$post_data['post_id'];
		$page_data['TOPIC_FIRST_POST_ID'] = $post_data['topic_first_post_id'] ?: 0;

		$this->topic_fields('specific_fields', $post_data, $post_data['forum_desc'], true);
		$this->geobb_activate_map($post_data['forum_desc'], @$post_data['post_id'] == $post_data['topic_first_post_id']);

		// HORRIBLE phpbb hack to accept geom values //TODO-ARCHI : check if done by PhpBB (supposed 3.2)
		$file_name = "phpbb/db/driver/driver.php";
		$file_tag = "\n\t\tif (is_null(\$var))";
		$file_patch = "\n\t\tif (strpos(\$var, 'GeomFrom') !== false)\n\t\t\treturn \$var;";
		$file_content = file_get_contents ($file_name);
		if (strpos($file_content, '{'.$file_tag))
			file_put_contents ($file_name, str_replace ('{'.$file_tag, '{'.$file_patch.$file_tag, $file_content));

		$vars['page_data'] = $page_data;
	}

	// Call when validating the data to be saved
	function submit_post_modify_sql_data($vars) {
		$sql_data = $vars['sql_data'];

		// Treat specific data
		$this->request->enable_super_globals(); // Allow access to $_POST & $_SERVER
		foreach ($_POST AS $k=>$v)
			if (!strncmp ($k, 'geo', 3)) {
				// Retrieves the values of the geometry, includes them in the phpbb_posts table
				if ($k == 'geom' && $v)
					$v = "ST_GeomFromGeoJSON('$v')";
					//TODO TEST est-ce qu'il optimise les linestring en multilinestring (ne devrait pas)

				// Retrieves the values of the questionnaire, includes them in the phpbb_posts table
				$sql_data[POSTS_TABLE]['sql'][$k] = utf8_normalize_nfc($v) ?: null; // null allows the deletion of the field
			}
		$this->request->disable_super_globals();

		$vars['sql_data'] = $sql_data; // return data
		$this->modifs = $sql_data[POSTS_TABLE]['sql']; // Save change
		$this->modifs['geojson'] = str_replace (['ST_GeomFromGeoJSON(\'','\')'], '', $this->modifs['geom']);
	}

	// Call after the post validation
	function modify_submit_notification_data($vars) {
		$this->save_post_data($vars['data_ary'], $vars['data_ary']['attachment_data'], $this->modifs);
	}

	// Save changes
	function save_post_data($post_data, $attachment_data, $geo_data, $create_if_null = false) {
		if (isset ($post_data['post_id'])) {
			$this->request->enable_super_globals();
			$to_save = [
				$this->user->data['username'].' '.date('r').' '.$_SERVER['REMOTE_ADDR'],
				$_SERVER['REQUEST_URI'],
				'forum '.$post_data['forum_id'].' = '.$post_data['forum_name'],
				'topic '.$post_data['topic_id'].' = '.$post_data['topic_title'],
				'post_subject = '.$geo_data['post_subject'],
				'post_text = '.$post_data['post_text'].$post_data['message'],
				'geojson = '.@$geo_data['geojson'],
			];
			foreach ($geo_data AS $k=>$v)
				if ($v && !strncmp ($k, 'geo_', 4))
					$to_save [] = "$k = $v";

			// Save attachment_data
			$attach = [];
			if ($attachment_data)
				foreach ($attachment_data AS $att)
					$attach[] = $att['attach_id'].' : '.$att['real_filename'];
			if (isset ($attach))
				$to_save[] = 'attachments = '.implode (', ', $attach);

			//TODO protéger l'accès à ces fichiers
			//TODO sav avec les balises !
			$file_name = 'LOG/'.$post_data['post_id'].'.txt';
			if (!$create_if_null || !file_exists($file_name))
				file_put_contents ($file_name, implode ("\n", $to_save)."\n\n", FILE_APPEND);

			$this->request->disable_super_globals();
		}
	}


	/**
		COMMON FUNCTIONS
	*/
	function geobb_activate_map($forum_desc, $first_post = true) {
		global $geo_keys; // Private / defined in config.php

		preg_match ('/\[view=([a-z]+)(\:|\])/i', html_entity_decode ($forum_desc), $view);
		// Misc template values
		$this->template->assign_vars([
			'BODY_CLASS' => @$view[1],
//TODO DELETE	STYLE_NAME' => $this->user->style['style_name'],
		]);

		preg_match ('/\[(first|all)=([a-z]+)(\:|\])/i', html_entity_decode ($forum_desc), $regle);
		switch (@$regle[1]) {
			case 'first': // Rule for the first post only
				if (!$first_post)
					break;

			case 'all': // Rule for all posts
				$this->template->assign_vars([
					'GEO_MAP_TYPE' => str_replace(
						['point','ligne','line','surface',],
						['Point','LineString','LineString','Polygon',],
						@$regle[2]),
					'GEO_KEYS' => json_encode($geo_keys),
				]);
				if ($geo_keys)
					$this->template->assign_vars (array_change_key_case ($geo_keys, CASE_UPPER));
		}
	}

	// Form management
	function topic_fields($block_name, $post_data, $forum_desc, $posting = false) {
		// Get special columns list
		$sql = 'SHOW columns FROM '.POSTS_TABLE.' LIKE "geo%"';
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
			$special_columns[$row['Field']] = $row['Type'];
		$this->db->sql_freeresult($result);

		// Get form fields from the relative post (one post with the title === form=title or the same title than the post discribing the specific fieds)
		preg_match ('/form=([^\&\>\]]+)/u', str_replace('"', '', html_entity_decode($forum_desc)), $form);
		$def_forms = $this->get_post_data($form[1]);

		foreach ($def_forms AS $k=>$line) { // Get form definition lines
			$field = explode ('|', preg_replace ('/[[:cntrl:]]|<br>/', '', $line.'|||||')); // Get form definition fields
			$title = explode (' ', $field[1], 2); // Extract the title number if any
			$sql_type = 'varchar(255)';
			$block[$k] = [ // Init default field template block
				'FIELD_TAG' => 'p',
				'FIELD_TITLE' => $field[1],
				'FIELD_TYPE' => $field[2],
				'PLACEHOLDER' => $field[3], // Displayed on empty fields
				'POSTAMBULE' => $field[4], // Displayed after the value
				'COMMENT' => $field[5],
					// Displayed on posting
					// confidentiel (not displayed on viewtopic if not moderator)
					// automatique (not displayed on posting)
			];

			// Numbered title
			if (preg_match ('/[0-9]+\./', $title[0])) {
				preg_match_all ('/[0-9]+\.?/', $title[0], $title_num);
				$block[$k]['FIELD_TITLE_NUM'] = $title[0];
				$block[$k]['FIELD_TAG'] = 'h'.(count ($title_num[0]) + 1);
			}

			// SQL field
			if (preg_match ('/^([a-z0-9_]+)$/', $field[0])) {
				$block[$k]['TAG'] = 'input';
				$block[$k]['NAME'] = $sql_id = 'geo_'.$field[0];
				$sql_data = $posting && $post_data[$sql_id][0] == '-' //TODO BEST only do that if automatic data
					? '' // Don't edit automatic values
					: trim ($post_data[$sql_id], '-~ \t\n\r\0\x0B'); // Also remove - before automatic data //TODO AFRET CHEM remove ~ when no more databases with ~
				$sql_data = str_replace (['<a ','</a>'], ['<pre><a ','</a></pre>'], $sql_data);
				$block[$k]['VALUE'] = $sql_data;

				// sql_id|titre|choix,choix|invite|postambule|commentaire saisie
				$options = explode (',', ','.$field[2]); // Add a first option empty
				if (count($options) > 2) {
					$block[$k]['TAG'] = 'select';
					foreach ($options AS $o)
						$block[$k]['INNER'] .=
							"<option value=\"$o\"".
							($sql_data == $o ? ' selected="selected"' : '').
							">$o</option>\n";
					// Verify SQL type
					$length = 0;
					foreach ($options AS $o)
						$length = max ($length, strlen ($o) + 1);
					$sql_type = "varchar($length)";
				}

				// sql_id|titre|type|invite|postambule|commentaire
				else switch ($field[2]) {
					case 'liste':
						$rows = $this->get_post_data($field[3], "\n");
						$block[$k]['TAG'] = 'select';
						$block[$k]['POSTAMBULE'] = '';
						foreach ($rows AS $r) {
							$rowss = explode (' ', $r, 2);
							$block[$k]['INNER'] .=
								"<option value=\"{$rowss[0]}\"".
								($sql_data == $rowss[0] ? ' selected="selected"' : '').
								">{$rowss[0]}</option>\n";
							if ($sql_data == $rowss[0])
								$block[$k]['POSTAMBULE'] = '<br/>'.$field[4].': '.$rowss[1];
						}
						break;

					case 'proches':
						if ($posting) {
							// Posting : Search surfaces closest to a point
							if ($post_data['post_id']) {
								$sql = "SELECT s.topic_id, t.topic_title,
										ST_Distance(s.geom,p.geom) AS distance
										FROM ".POSTS_TABLE." AS s
											JOIN ".POSTS_TABLE." AS p ON (p.post_id = {$post_data['post_id']})
											JOIN ".TOPICS_TABLE." AS t ON (t.topic_id = s.topic_id)
										WHERE ST_Area(s.geom) > 0 AND
											ST_Distance(s.geom,p.geom) < 0.1
										ORDER BY distance ASC
										LIMIT 10";
								$result = $this->db->sql_query($sql);
								$block[$k]['INNER'] = '<option'.($sql_data?'':' selected="selected"').'></option>';
								while ($row = $this->db->sql_fetchrow($result))
									$block[$k]['INNER'] .=
										'<option value="'.$row['topic_id'].'"'.
										($row['topic_id'] == $sql_data ? ' selected="selected"' : '').'>'.
										$row['topic_title'].
										'</option>';
								$this->db->sql_freeresult($result);

								$block[$k]['TAG'] = 'select';
								$block[$k]['STYLE'] = 'display:none'; // Hide at posting //TODO TEST sert à quoi ?
							} else {
								$block[$k]['TAG'] = 'span';
								$block[$k]['COMMENT'] = '(enregistrez ce point pour accéder à la fonction)';
							}
						}

						// Viewtopic : Get the contains name & url
						elseif ($sql_data) {
							$sql = 'SELECT topic_id,topic_title FROM '.TOPICS_TABLE.' WHERE topic_id = '.$sql_data;
							$result = $this->db->sql_query($sql);
							$row = $this->db->sql_fetchrow($result);
							$this->db->sql_freeresult($result);
							$block[$k]['VALUE'] = '<a href="viewtopic.php?t='.$row['topic_id'].'">'.$row['topic_title'].'</a>';
						}
//TODO BEST (mais pb base ALPAGES)						$sql_type = 'int(10)'; // === topic_id
						break;

					// List topics attached to this one
					//TODO BUG ASPIR : reste les titres couverture réseau, points d'eau, logement de fonction
					case 'attaches':
						$block[$k]['VALUE'] = '';
						if ($post_data['topic_id']) {
							$sql = "SELECT * FROM ".POSTS_TABLE."
										JOIN ".FORUMS_TABLE." USING (forum_id)"./* Just to sort forum_image related */"
									WHERE forum_image LIKE '%/{$field[3]}.%' AND
										$sql_id LIKE '{$post_data['topic_id']}%'";
							$result = $this->db->sql_query($sql);
							while ($row = $this->db->sql_fetchrow($result))
								$attachments[$k][] = $row;
							$this->db->sql_freeresult($result);
						}

						$sql = 'SELECT forum_name,forum_id FROM '.FORUMS_TABLE.' WHERE forum_image LIKE "%'.$field[3].'%"';
						$result = $this->db->sql_query($sql);
						$row = $this->db->sql_fetchrow($result);
						$this->db->sql_freeresult($result);

						//TODO BEST ARCHI put in an alpage template
						$block[$k]['COMMENT'] = 'Pour ajouter un '.strtolower($row['forum_name']).
							', ';
						$block[$k]['COMMENT'] .= $post_data['post_id']
							? 'choisissez ou créez le <a target="_BLANK" href="viewforum.php?&f='.$row['forum_id'].
							'">ICI</a> et modifiez le champ "Alpage d’appartenance"'
							: 'enregistrez d\'abord cet alpage.';

						$block[$k]['TYPE'] = 'hidden'; // Hides the input field
						$sql_type = 'int(10)'; // topic_id
						break;

					case 'long':
						$block[$k]['TAG'] = 'textarea';
						$block[$k]['INNER'] = $sql_data;
						$sql_type = 'text';
						break;
					case 'date':
						$block[$k]['TYPE'] = 'date';
						$sql_type = 'date';
						break;
					case '0':
						$block[$k]['TYPE'] = 'number';
						$sql_type = 'int(5)';
						break;
					default:
						$block[$k]['SIZE'] = '40';
						$block[$k]['CLASS'] = 'inputbox autowidth';
				}

				// Correct SQL table structure
				if ($sql_type != $special_columns[$sql_id]) {
					// Change the table structure
					$sql = 'ALTER TABLE '.POSTS_TABLE.
						(array_key_exists ($sql_id, $special_columns) ? ' CHANGE '.$sql_id.' ' : ' ADD ').
						$sql_id.' '.$sql_type;
					$this->db->sql_query($sql);

					// Pass 1 : Flag title having values on related fields
					if ($sql_data || $attachments[$k])
						for ($stn = $title_num[0]; $stn; array_pop ($stn))
							$block_value[implode('',$stn)] = true;
				}
			}
		}

		// Assign template blocks
		if ($block)
			foreach ($block AS $k=>$b) {
				// Pass 2 : Flag titles of blocks having values on related fields
				$b['BLOCK_HAVING_VALUE'] = $block_value[$b['FIELD_TITLE_NUM']];

				// Create att="value" template fields
				foreach ($b AS $kb=>$vb)
					if ($vb)
						$b['ATT_'.$kb] = strtolower($kb).'="'.str_replace('"','\\\"', $vb).'"';

				$this->template->assign_block_vars($block_name, $b);

				if ($attachments[$k])
					foreach ($attachments[$k] AS $a) {
						$this->template->assign_block_vars(
							$block_name.'.attachments',
							array_change_key_case ($a, CASE_UPPER)
						);
						if (count (explode ('.', $block_name)) == 1)
							$this->topic_fields ($block_name.'.attachments.detail', $a, $a['forum_desc']);
					}
			}
	}

	function get_post_data($post_title, $add_first_line = '') {
		$sql = 'SELECT post_text,bbcode_uid,bbcode_bitfield FROM '.POSTS_TABLE.
			' WHERE LOWER(post_subject) LIKE "'.strtolower(str_replace ("'", "\'", $post_title)).'"'.
			' ORDER BY post_id';
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row 
			? explode ("\n", $add_first_line.generate_text_for_display(
				$row['post_text'],
				$row['bbcode_uid'],
				$row['bbcode_bitfield'],
				0)
			)
			: [];
	}


	/**
		RESIZE IMAGES
	*/
	// Insère des miniatures des liens.jpg insérés dans les messages
	/*//TODO DELETE ??
	function viewtopic_modify_post_row_2($vars) {
		global $db;
		$post_row = $vars['post_row'];
		preg_match_all('/href="(http[^"]*\.(jpe?g|png))"[^>]*>([^<]*\.(jpe?g|png))<\/a>/i', $post_row['MESSAGE'], $imgs); // Récupère les urls d'images

		foreach ($imgs[1] AS $k=>$href) {
			$sql_rch = "SELECT * FROM ".ATTACHMENTS_TABLE." WHERE real_filename = '".addslashes($href)."'";
			$result = $this->db->sql_query_limit($sql_rch, 1);
			$r = $this->db->sql_fetchrow($result);
			if(!$r) { // L'image n'est pas dans la base
				$sql_ary = array(
					'physical_filename'	=> $href,
					'attach_comment'	=> $href,
					'real_filename'		=> $href,
					'extension'			=> 'jpg',
					'mimetype'			=> 'image/jpeg',
					'filesize'			=> 0,
					'filetime'			=> time(),
					'thumbnail'			=> 0,
					'is_orphan'			=> 0,
					'in_message'		=> 0,
					'post_msg_id'		=> $vars['row']['post_id'],
					'topic_id'			=> $vars['row']['topic_id'],
					'poster_id'			=> $vars['poster_id'],
				);
				$db->sql_query('INSERT INTO ' . ATTACHMENTS_TABLE . ' ' . $db->sql_build_array('INSERT', $sql_ary));
				$result = $this->db->sql_query_limit($sql_rch, 1);
				$r = $this->db->sql_fetchrow($result);
			}

			$post_row['MESSAGE'] = str_replace (
				$href.'">'.$imgs[3][$k].'<',
				$href.'"><img title="'.$href.'" alt="'.$href.'" style="border:5px solid #F3E358" src="download/file.php?id='.$r['attach_id'].'&s=200&'.time().'"><',
				$post_row['MESSAGE']
			);
		}
		$vars['post_row'] = $post_row;
	}*/

	function download_file_send_to_browser_before($vars) {
		$attachment = $vars['attachment'];
		if (!is_dir ('../cache/geobb/'))
			mkdir ('../cache/geobb/');

		// Images externes
		$purl = parse_url ($attachment['real_filename']);
		if (isset ($purl['host'])) { // le fichier est distant
			$local = '../cache/geobb/'.str_replace ('/', '-', $purl['path']);
			if (!file_exists ($local) || !filesize ($local)) {
				// Recuperation du contenu
				$url_cache = file_get_contents ($attachment['real_filename']);

				if (ord ($url_cache) == 0xFF) // Si c'est une image jpeg
					file_put_contents ($local, $url_cache); // Ecrit le fichier
				else { // Message d'erreur sinon
					$nbcligne = 40;
					$cs = [];
					if (!$url_cache)
						$err_msg = $user->lang('FILE_GET_CONTENTS_ERROR', $attachment['real_filename']);
					foreach (explode ("\n", strip_tags ($err_msg)) AS $v)
						if ($v)
							$cs = array_merge ($cs, str_split (strip_tags ($v), $nbcligne));
					$im = imagecreate  ($nbcligne * 7 + 10, 12 * count ($cs) + 8);
					ImageColorAllocate ($im, 0, 0, 200);
					foreach ($cs AS $k => $v)
						ImageString ($im, 3, 5, 3 + 12 * $k, $v, ImageColorAllocate ($im, 255, 255, 255));
					imagejpeg ($im, $local);
					ImageDestroy ($im);
				}
			}
			$attachment['physical_filename'] = $local;
		}
		else if (is_file('../'.$attachment['real_filename'])) // Fichier relatif à la racine du site
			$attachment['physical_filename'] = '../'.$attachment['real_filename']; // script = download/file.php

		if ($exif = @exif_read_data ('../files/'.$attachment['physical_filename'])) {
			$fls = explode ('/', $exif['FocalLength']);
			if (count ($fls) == 2)
				$info[] = round($fls[0]/$fls[1]).'mm';

			$aps = explode ('/', $exif['FNumber']);
			if (count ($aps) == 2)
				$info[] = 'f/'.round($aps[0]/$aps[1], 1).'';

			$exs = explode ('/', $exif['ExposureTime']);
			if (count ($exs) == 2)
				$info[] = '1/'.round($exs[1]/$exs[0]).'s';

			if ($exif['ISOSpeedRatings'])
				$info[] = $exif['ISOSpeedRatings'].'ASA';

			if ($exif['Model']) {
				if ($exif['Make'] &&
					strpos ($exif['Model'], $exif['Make']) === false)
					$info[] = $exif['Make'];
				$info[] = $exif['Model'];
			}

			$this->db->sql_query (implode (' ', [
				'UPDATE '.ATTACHMENTS_TABLE,
				'SET exif = "'.implode (' ', $info ?: ['-']).'",',
					'filetime = '.(strtotime($exif['DateTimeOriginal']) ?: $exif['FileDateTime'] ?: $attachment['filetime']),
				'WHERE attach_id = '.$attachment['attach_id']
			]));
		}

		// Reduction de la taille de l'image
		if ($max_size = request_var('size', 0)) {
			$img_size = @getimagesize ('../files/'.$attachment['physical_filename']);
			$isx = $img_size[0]; $isy = $img_size[1];
			$reduction = max ($isx / $max_size, $isy / $max_size);
			if ($reduction > 1) { // Il faut reduire l'image
				$pn = pathinfo ($attachment['physical_filename']);
				$temporaire = '../cache/geobb/'.$pn['basename'].'.'.$max_size.$pn['extension'];

				// Si le fichier temporaire n'existe pas, il faut le creer
				if (!is_file ($temporaire)) {
					$mimetype = explode('/',$attachment['mimetype']);

					// Get source image
					$imgcreate = 'imagecreatefrom'.$mimetype[1]; // imagecreatefromjpeg / imagecreatefrompng / imagecreatefromgif
					$image_src = $imgcreate ('../files/'.$attachment['physical_filename']);

					// Detect orientation
					$angle = [
						3 => 180,
						6 => -90,
						8 =>  90,
					];
					$a = $angle[$exif['Orientation']];
					if ($a)
						$image_src = imagerotate ($image_src, $a, 0);
					if (abs ($a) == 90) {
						$tmp = $isx;
						$isx = $isy;
						$isy = $tmp;
					}

					// Build destination image
					$image_dest = imagecreatetruecolor ($isx / $reduction, $isy / $reduction);
					imagecopyresampled ($image_dest, $image_src, 0,0, 0,0, $isx / $reduction, $isy / $reduction, $isx, $isy);

					// Convert image
					$imgconv = 'image'.$mimetype[1]; // imagejpeg / imagepng / imagegif
					$imgconv ($image_dest, $temporaire);

					// Cleanup
					imagedestroy ($image_dest);
					imagedestroy ($image_src);
				}
				$attachment['physical_filename'] = $temporaire;
			}
		}

		$vars['attachment'] = $attachment;
	}

	/**
		MODIFY INSCRIPTION MAIL
	*/
	function ucp_register_welcome_email_before($vars) {
		$vars['message'] = implode ("\n", $this->get_post_data('Mail inscription'));
	}

}
