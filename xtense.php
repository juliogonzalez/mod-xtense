<?php
/**
 * @package Xtense 2
 * @author Unibozu
 * @licence GNU
 */

define('IN_SPYOGAME', true);
define('IN_XTENSE', true);

date_default_timezone_set(@date_default_timezone_get());

$currentFolder = getcwd();
if (preg_match('#mod#', getcwd())) chdir('../../');
$_SERVER['SCRIPT_FILENAME'] = str_replace(basename(__FILE__), 'index.php', preg_replace('#\/mod\/(.*)\/#', '/', $_SERVER['SCRIPT_FILENAME']));
include("common.php");
list($root, $active) = $db->sql_fetch_row($db->sql_query("SELECT root, active FROM " . TABLE_MOD . " WHERE action = 'xtense'"));

header("Access-Control-Allow-Origin: * ");
header('Access-Control-Max-Age: 86400', false);    // cache for 1 day
header("Content-Type: text/plain", false);
header("Access-Control-Allow-Methods: POST, GET", false);
header('X-Content-Type-Options: nosniff', false);

require_once("mod/{$root}/includes/config.php");
require_once("mod/{$root}/includes/functions.php");
require_once("mod/{$root}/includes/CallbackHandler.php");
require_once("mod/{$root}/includes/Callback.php");
require_once("mod/{$root}/includes/Io.php");
require_once("mod/{$root}/includes/Check.php");

set_error_handler('error_handler');
$start_time = get_microtime();

$io = new Io();
$time = time() - 60 * 4;
if ($time > mktime(0, 0, 0) && $time < mktime(8, 0, 0)) $timestamp = mktime(0, 0, 0);
if ($time > mktime(8, 0, 0) && $time < mktime(16, 0, 0)) $timestamp = mktime(8, 0, 0);
if ($time > mktime(16, 0, 0) && $time < (mktime(0, 0, 0) + 60 * 60 * 24)) $timestamp = mktime(16, 0, 0);

if (isset($pub_toolbar_version, $pub_toolbar_type, $pub_mod_min_version, $pub_user, $pub_password, $pub_univers) == false) die("hack");

if (version_compare($pub_toolbar_version, TOOLBAR_MIN_VERSION, '<')) {
    $io->set(array(
        'type' => 'wrong version',
        'target' => 'toolbar',
        'version' => TOOLBAR_MIN_VERSION
    ));
    $io->send(0, true);
}

if (version_compare($pub_mod_min_version, PLUGIN_VERSION, '>')) {
    $io->set(array(
        'type' => 'wrong version',
        'target' => 'plugin',
        'version' => PLUGIN_VERSION
    ));
    $io->send(0, true);
}

if ($active != 1) {
    $io->set(array('type' => 'plugin config'));
    $io->send(0, true);
}

if ($server_config['server_active'] == 0) {
    $io->set(array(
        'type' => 'server active',
        'reason' => $server_config['reason']
    ));
    $io->send(0, true);
}

if ($server_config['xtense_allow_connections'] == 0) {
    $io->set(array(
        'type' => 'plugin connections',
    ));
    $io->send(0, true);
}

if (strtolower($server_config['xtense_universe']) != strtolower($pub_univers)) {
    $io->set(array(
        'type' => 'plugin univers',
    ));
    $io->send(0, true);
}

$query = $db->sql_query('SELECT user_id, user_name, user_password, user_active, user_stat_name FROM ' . TABLE_USER . ' WHERE user_name = "' . quote($pub_user) . '"');
if (!$db->sql_numrows($query)) {
    $io->set(array(
        'type' => 'username'
    ));
    $io->send(0, true);
} else {
    $user_data = $db->sql_fetch_assoc($query);

    if ($pub_password != $user_data['user_password']) {
        $io->set(array(
            'type' => 'password'
        ));
        $io->send(0, true);
    }

    if ($user_data['user_active'] == 0) {
        $io->set(array(
            'type' => 'user active'
        ));
        $io->send(0, true);
    }

    $user_data['grant'] = array('system' => 0, 'ranking' => 0, 'empire' => 0, 'messages' => 0);
}

// Verification des droits de l'user
$query = $db->sql_query("SELECT system, ranking, empire, messages FROM " . TABLE_USER_GROUP . " u LEFT JOIN " . TABLE_GROUP . " g ON g.group_id = u.group_id LEFT JOIN " . TABLE_XTENSE_GROUPS . " x ON x.group_id = g.group_id WHERE u.user_id = '" . $user_data['user_id'] . "'");
$user_data['grant'] = $db->sql_fetch_assoc($query);


// Si Xtense demande la verification du serveur, renvoi des droits de l'utilisateur
if (isset($pub_server_check)) {
    $io->set(array(
        'version' => $server_config['version'],
        'servername' => $server_config['servername'],
        'grant' => $user_data['grant']
    ));
    $io->send(1, true);
}

if (isset($pub_type)) {
    $page_type = filter_var($pub_type, FILTER_SANITIZE_STRING);
} else die("hack");

$call = new CallbackHandler();

//nombre de messages
$io->set(array('new_messages' => 0));

// Xtense : Ajout de la version et du type de barre utilisée par l'utilisateur
$db->sql_query("UPDATE " . TABLE_USER . " SET xtense_version='" . $pub_toolbar_version . "', xtense_type='" . $pub_toolbar_type . "' WHERE user_id = " . $user_data['user_id']);
$toolbar_info = $pub_toolbar_type . " V" . $pub_toolbar_version;

switch ($page_type) {
    case 'overview': {//PAGE OVERVIEW
        if (isset($pub_coords, $pub_planet_name, $pub_planet_type, $pub_fields, $pub_temperature_min, $pub_temperature_max, $pub_ressources) == false) die("hack");
        if (!$user_data['grant']['empire']) {
            $io->set(array(
                'type' => 'plugin grant',
                'access' => 'empire'
            ));
            $io->status(0);
        } else {
            $pub_coords = Check::coords($pub_coords);
            $planet_name = filter_var($pub_planet_name, FILTER_SANITIZE_STRING);

            $coords = $pub_coords;
            $planet_type = ((int)$pub_planet_type == TYPE_PLANET ? TYPE_PLANET : TYPE_MOON);
            $fields = (int)$pub_fields;
            $temperature_min = (int)$pub_temperature_min;
            $temperature_max = (int)$pub_temperature_max;
            $ressources = $pub_ressources;
            $ogame_timestamp = $pub_ogame_timestamp;

            $home = home_check($planet_type, $coords);
            if (isset($pub_boostExt)) {
                $boosters = update_boosters($pub_boostExt, $ogame_timestamp); /*Merge des différents boosters*/
                $boosters = booster_encode($boosters); /*Conversion de l'array boosters en string*/
            } else
                $boosters = booster_encodev(0, 0, 0, 0, 0, 0, 0, 0); /* si aucun booster détecté*/

            if ($home[0] == 'full') {
                $io->set(array(
                    'type' => 'home full'
                ));
                $io->status(0);
            } else {
                if ($home[0] == 'update') {
                    $db->sql_query('UPDATE ' . TABLE_USER_BUILDING . ' SET planet_name = "' . $planet_name . '", `fields` = ' . $fields . ', boosters = "' . $boosters . '", temperature_min = ' . $temperature_min . ', temperature_max = ' . $temperature_max . '  WHERE planet_id = ' . $home['id'] . ' AND user_id = ' . $user_data['user_id']);
                } else {
                    $db->sql_query('INSERT INTO ' . TABLE_USER_BUILDING . ' (user_id, planet_id, coordinates, planet_name, fields, boosters, temperature_min, temperature_max) VALUES (' . $user_data['user_id'] . ', ' . $home['id'] . ', "' . $coords . '", "' . $planet_name . '", ' . $fields . ', "' . $boosters . '", ' . $pub_temperature_min . ', ' . $pub_temperature_max . ')');
                }

                $io->set(array(
                    'type' => 'home updated',
                    'page' => 'overview',
                    'planet' => $coords
                ));
            }

            // Appel fonction de callback
            $call->add('overview', array(
                'coords' => explode(':', $coords),
                'planet_type' => $planet_type,
                'planet_name' => $planet_name,
                'fields' => $fields,
                'temperature_min' => $temperature_min,
                'temperature_max' => $temperature_max,
                'ressources' => $ressources
            ));

            add_log('overview', array('coords' => $coords, 'planet_name' => $planet_name, 'toolbar' => $toolbar_info));
        }
    }
        break;

    case 'buildings': //PAGE BATIMENTS
        if (isset($pub_coords, $pub_planet_name, $pub_planet_type) == false) die("hack");

        if (!$user_data['grant']['empire']) {
            $io->set(array(
                'type' => 'plugin grant',
                'access' => 'empire'
            ));
            $io->status(0);
        } else {
            $pub_coords = Check::coords($pub_coords);
            $planet_name = filter_var($pub_planet_name, FILTER_SANITIZE_STRING);

            $coords = $pub_coords;
            $planet_type = ((int)$pub_planet_type == TYPE_PLANET ? TYPE_PLANET : TYPE_MOON);
            $planet_name = $pub_planet_name;

            $home = home_check($planet_type, $coords);

            if ($home[0] == 'full') {
                $io->set(array(
                    'type' => 'home full'
                ));
                $io->status(0);
            } elseif ($home[0] == 'update') {
                $set = '';
                foreach ($database['buildings'] as $code) {
                    if (isset(${'pub_' . $code}))
                        $set .= ', ' . $code . ' = ' . ${'pub_' . $code};//avec la nouvelle version d'Ogame, on n'Ã©crase que si on a vraiment 0
                }

                $db->sql_query('UPDATE ' . TABLE_USER_BUILDING . ' SET planet_name = "' . $planet_name . '"' . $set . ' WHERE planet_id = ' . $home['id'] . ' AND user_id = ' . $user_data['user_id']);

                $io->set(array(
                    'type' => 'home updated',
                    'page' => 'buildings',
                    'planet' => $coords
                ));
            } else {
                $set = '';

                foreach ($database['buildings'] as $code) {
                    $set .= ', ' . (isset(${'pub_' . $code}) ? (int)${'pub_' . $code} : 0);
                }

                $db->sql_query('INSERT INTO ' . TABLE_USER_BUILDING . ' (user_id, planet_id, coordinates, planet_name, ' . implode(',', $database['buildings']) . ') VALUES (' . $user_data['user_id'] . ', ' . $home['id'] . ', "' . $coords . '", "' . $planet_name . '"' . $set . ')');

                $io->set(array(
                    'type' => 'home updated',
                    'page' => 'buildings',
                    'planet' => $coords
                ));
            }

            $buildings = array();
            foreach ($database['buildings'] as $code) {
                if (isset(${'pub_' . $code})) {
                    $buildings[$code] = (int)${'pub_' . $code};
                }
            }

            $call->add('buildings', array(
                'coords' => explode(':', $coords),
                'planet_type' => $planet_type,
                'planet_name' => $planet_name,
                'buildings' => $buildings
            ));

            add_log('buildings', array('coords' => $coords, 'planet_name' => $planet_name, 'toolbar' => $toolbar_info));
        }
        break;

    case 'defense': //PAGE DEFENSE
        if (isset($pub_coords, $pub_planet_name, $pub_planet_type) == false) die("hack");

        if (!$user_data['grant']['empire']) {
            $io->set(array(
                'type' => 'plugin grant',
                'access' => 'empire'
            ));
            $io->status(0);
        } else {
            $pub_coords = Check::coords($pub_coords);
            $planet_name = filter_var($pub_planet_name, FILTER_SANITIZE_STRING);

            $coords = $pub_coords;
            $planet_type = ((int)$pub_planet_type == TYPE_PLANET ? TYPE_PLANET : TYPE_MOON);
            $planet_name = $pub_planet_name;

            $home = home_check($planet_type, $coords);

            if ($home[0] == 'full') {
                $io->set(array(
                    'type' => 'home full'
                ));
                $io->status(0);
            } elseif ($home[0] == 'update') {
                $fields = '';
                $values = '';
                foreach ($database['defense'] as $code) {
                    if (isset(${'pub_' . $code})) {
                        $fields .= ', ' . $code;
                        $values .= ', ' . (int)${'pub_' . $code};
                    }
                }

                $db->sql_query('REPLACE INTO ' . TABLE_USER_DEFENCE . ' (user_id, planet_id' . $fields . ') VALUES (' . $user_data['user_id'] . ', ' . $home['id'] . $values . ')');
                $db->sql_query('UPDATE ' . TABLE_USER_BUILDING . ' SET planet_name = "' . $planet_name . '" WHERE user_id = ' . $user_data['user_id'] . ' AND planet_id = ' . $home['id']);

                $io->set(array(
                    'type' => 'home updated',
                    'page' => 'defense',
                    'planet' => $coords
                ));
            } else {
                $fields = '';
                $set = '';

                foreach ($database['defense'] as $code) {
                    if (isset(${'pub_' . $code})) {
                        $fields .= ', ' . $code;
                        $set .= ', ' . (int)${'pub_' . $code};
                    }
                }

                $db->sql_query('INSERT INTO ' . TABLE_USER_BUILDING . ' (user_id, planet_id, coordinates, planet_name) VALUES (' . $user_data['user_id'] . ', ' . $home['id'] . ', "' . $coords . '", "' . $planet_name . '")');
                $db->sql_query('INSERT INTO ' . TABLE_USER_DEFENCE . ' (user_id, planet_id' . $fields . ') VALUES (' . $user_data['user_id'] . ', ' . $home['id'] . $set . ')');

                $io->set(array(
                    'type' => 'home updated',
                    'page' => 'defense',
                    'planet' => $coords
                ));
            }

            $defenses = array();
            foreach ($database['defense'] as $code) {
                if (isset(${'pub_' . $code})) {
                    $defenses[$code] = (int)${'pub_' . $code};
                }
            }

            $call->add('defense', array(
                'coords' => explode(':', $coords),
                'planet_type' => $planet_type,
                'planet_name' => $planet_name,
                'defense' => $defenses
            ));

            add_log('defense', array('coords' => $coords, 'planet_name' => $planet_name, 'toolbar' => $toolbar_info));
        }
        break;

    case 'researchs': //PAGE RECHERCHE
        if (isset($pub_coords, $pub_planet_name, $pub_planet_type) == false) die("hack");

        $coords = $pub_coords;

        if (!$user_data['grant']['empire']) {
            $io->set(array(
                'type' => 'plugin grant',
                'access' => 'empire'
            ));
            $io->status(0);
        } else {

            if ($db->sql_numrows($db->sql_query('SELECT user_id FROM ' . TABLE_USER_TECHNOLOGY . ' WHERE user_id = ' . $user_data['user_id']))) {
                $set = array();
                foreach ($database['labo'] as $code) {
                    if (isset(${'pub_' . $code})) {
                        $set[] = $code . ' = ' . (int)${'pub_' . $code};
                    }
                }

                if (!empty($set))
                    $db->sql_query('UPDATE ' . TABLE_USER_TECHNOLOGY . ' SET ' . implode(', ', $set) . ' WHERE user_id = ' . $user_data['user_id']);
            } else {
                $fields = '';
                $set = '';

                foreach ($database['labo'] as $code) {
                    if (isset(${'pub_' . $code})) {
                        $fields .= ', ' . $code;
                        $set .= ', "' . (int)${'pub_' . $code} . '"';
                    }
                }

                if (!empty($fields))
                    $db->sql_query('INSERT INTO ' . TABLE_USER_TECHNOLOGY . ' (user_id' . $fields . ') VALUES (' . $user_data['user_id'] . $set . ')');
            }

            $io->set(array(
                'type' => 'home updated',
                'page' => 'labo',
                'planet' => $coords
            ));

            $research = array();
            foreach ($database['labo'] as $code) {
                if (isset(${'pub_' . $code})) {
                    $research[$code] = (int)${'pub_' . $code};
                }
            }

            $call->add('research', array(
                'research' => $research
            ));

            add_log('research', array('toolbar' => $toolbar_info));
        }
        break;

    case 'fleet': //PAGE FLOTTE
        if (isset($pub_coords, $pub_planet_name, $pub_planet_type) == false) die("hack");

        if (!$user_data['grant']['empire']) {
            $io->set(array(
                'type' => 'plugin grant',
                'access' => 'empire'
            ));
            $io->status(0);
        } else {
            $pub_coords = Check::coords($pub_coords);
            $planet_name = filter_var($pub_planet_name, FILTER_SANITIZE_STRING);

            $coords = $pub_coords;
            $planet_type = ((int)$pub_planet_type == TYPE_PLANET ? TYPE_PLANET : TYPE_MOON);
            $planet_name = $pub_planet_name;
            if (isset($pub_SAT)) $ss = $pub_SAT;
            if (!isset($ss)) $ss = "";

            $home = home_check($planet_type, $coords);

            if ($home[0] == 'full') {
                $io->set(array(
                    'type' => 'home full'
                ));
                $io->status(0);
            } elseif ($home[0] == 'update') {
                $db->sql_query('UPDATE ' . TABLE_USER_BUILDING . ' SET planet_name = "' . $planet_name . '" WHERE user_id = ' . $user_data['user_id'] . ' AND planet_id = ' . $home['id']);

                if (isset($pub_SAT)) $db->sql_query('UPDATE ' . TABLE_USER_BUILDING . ' SET planet_name = "' . $planet_name . '", Sat = \'' . $ss . '\' WHERE planet_id = ' . $home['id'] . ' AND user_id = ' . $user_data['user_id']);

                $io->set(array(
                    'type' => 'home updated',
                    'page' => 'fleet',
                    'planet' => $coords
                ));
            } else {
                if (isset($pub_SAT)) $db->sql_query('INSERT INTO ' . TABLE_USER_BUILDING . ' (user_id, planet_id, coordinates, planet_name, Sat) VALUES (' . $user_data['user_id'] . ', ' . $home['id'] . ', "' . $coords . '", "' . $planet_name . '", ' . $ss . ')');

                $io->set(array(
                    'type' => 'home updated',
                    'page' => 'fleet',
                    'planet' => $coords
                ));
            }

            $fleet = array();
            foreach ($database['fleet'] as $code) {
                if (isset(${'pub_' . $code})) {
                    $fleet[$code] = (int)${'pub_' . $code};
                }
            }

            $call->add('fleet', array(
                'coords' => explode(':', $coords),
                'planet_type' => $planet_type,
                'planet_name' => $planet_name,
                'fleet' => $fleet
            ));

            add_log('fleet', array('coords' => $coords, 'planet_name' => $planet_name, 'toolbar' => $toolbar_info));
        }
        break;

    case 'system': //PAGE SYSTEME SOLAIRE
        if (isset($pub_galaxy, $pub_system) == false) die("hack");

        if (!$user_data['grant']['system']) {
            $io->set(array(
                'type' => 'plugin grant',
                'access' => 'system'
            ));
            $io->status(0);
        } else {

            if ($pub_galaxy > $server_config['num_of_galaxies'] || $pub_system > $server_config['num_of_systems']) ;
            {
                $galaxy = (int)$pub_galaxy;
                $system = (int)$pub_system;
                $rows = (isset($pub_row) ? $pub_row : array());
                $data = array();
                $delete = array();
                $update = array();

                $check = $db->sql_query('SELECT row FROM ' . TABLE_UNIVERSE . ' WHERE galaxy = ' . $galaxy . ' AND system = ' . $system . '');
                while ($value = $db->sql_fetch_assoc($check))
                    $update[$value['row']] = true;
            }
            // Recupération des données
            for ($i = 1; $i < 16; $i++) {
                if (isset($rows[$i])) {
                    $line = $rows[$i];
                    // Filtrage des data
                    $line['player_name'] = filter_var($line['player_name'], FILTER_SANITIZE_STRING);
                    $line['planet_name'] = filter_var($line['planet_name'], FILTER_SANITIZE_STRING);
                    $line['ally_tag'] = filter_var($line['ally_tag'], FILTER_SANITIZE_STRING);

                    if (isset($line['debris'])) filter_var($line['debris'], FILTER_SANITIZE_STRING);
                    if (isset($line['status'])) filter_var($line['status'], FILTER_SANITIZE_STRING);

                    $data[$i] = $line;
                } else {
                    $delete[] = $i;
                    $data[$i] = array(
                        'planet_name' => '',
                        'player_name' => '',
                        'status' => '',
                        'ally_tag' => '',
                        'debris' => Array('metal' => 0, 'cristal' => 0),
                        'moon' => 0,
                        'activity' => ''
                    );
                }
            }

            foreach ($data as $row => $v) {
                $statusTemp = (Check::player_status_forbidden($v['status']) ? "" : quote($v['status'])); //On supprime les status qui sont subjectifs
                if (!isset($update[$row]))
                    $db->sql_query('INSERT INTO ' . TABLE_UNIVERSE . ' (galaxy, system, row, name, player, ally, status, last_update, last_update_user_id, moon)
                        VALUES (' . $galaxy . ', ' . $system . ', ' . $row . ', "' . quote($v['planet_name']) . '", "' . quote($v['player_name']) . '", "' . quote($v['ally_tag']) . '", "' . $statusTemp . '", ' . $time . ', ' . $user_data['user_id'] . ', "' . quote($v['moon']) . '")');
                else {
                    $db->sql_query(
                        'UPDATE ' . TABLE_UNIVERSE . ' SET name = "' . quote($v['planet_name']) . '", player = "' . quote($v['player_name']) . '", ally = "' . quote($v['ally_tag']) . '", status = "' . $statusTemp . '", moon = "' . $v['moon'] . '", last_update = ' . $time . ', last_update_user_id = ' . $user_data['user_id']
                        . ' WHERE galaxy = ' . $galaxy . ' AND system = ' . $system . ' AND row = ' . $row
                    );
                }
            }

            if (!empty($delete)) {
                $toDelete = array();
                foreach ($delete as $n) {
                    $toDelete[] = $galaxy . ':' . $system . ':' . $n;
                }

                $db->sql_query('UPDATE ' . TABLE_PARSEDSPY . ' SET active = "0" WHERE coordinates IN ("' . implode('", "', $toDelete) . '")');
            }

            $db->sql_query('UPDATE ' . TABLE_USER . ' SET planet_added_ogs = planet_added_ogs + 15 WHERE user_id = ' . $user_data['user_id']);

            $call->add('system', array(
                'data' => $data,
                'galaxy' => $galaxy,
                'system' => $system
            ));

            $io->set(array(
                'type' => 'system',
                'galaxy' => $galaxy,
                'system' => $system
            ));

            update_statistic('planetimport_ogs', 15);
            add_log('system', array('coords' => $galaxy . ':' . $system, 'toolbar' => $toolbar_info));
        }
        break;

    case 'ranking': //PAGE STATS
        if (isset($pub_type1, $pub_type2, $pub_offset, $pub_n, $pub_time) == false) die("Classement incomplet");

        if (!$user_data['grant']['ranking']) {
            $io->set(array(
                'type' => 'plugin grant',
                'access' => 'ranking'
            ));
            $io->status(0);
        } else {

            if ($pub_type1 != ('player' || 'ally')) die ("type 1 non défini");
            if ($pub_type2 != ('points' || 'fleet' || 'research' || 'economy')) die ("type 2 non défini");
            if (isset($pub_type3)) {
                if (!empty($pub_type3)) {
                    if (!($pub_type3 >= 4 && $pub_type3 <= 7)) die ("type 3 non défini");
                }
            }
            //Vérification Offset
            if ((($pub_offset - 1) % 100) != 0) die("Erreur Offset");

            $type1 = $pub_type1;
            $type2 = $pub_type2;
            $type3 = $pub_type3;
            $time = (int)$pub_time;
            $offset = (int)$pub_offset;
            $n = (array)$pub_n;
            $total = 0;
            $count = count($n);

            if ($type1 == 'player') {
                switch ($type2) {
                    case 'points':
                        $table = TABLE_RANK_PLAYER_POINTS; //Type2 =0
                        break;
                    case 'economy':
                        $table = TABLE_RANK_PLAYER_ECO;//Type2 =1
                        break;
                    case 'research':
                        $table = TABLE_RANK_PLAYER_TECHNOLOGY;//Type2 =2
                        break;
                    case 'fleet':        //Type2 =3
                        switch ($type3) {
                            case '5':
                                $table = TABLE_RANK_PLAYER_MILITARY_BUILT;
                                break;
                            case '6':
                                $table = TABLE_RANK_PLAYER_MILITARY_DESTRUCT;
                                break;
                            case '4':
                                $table = TABLE_RANK_PLAYER_MILITARY_LOOSE;
                                break;
                            case '7':
                                $table = TABLE_RANK_PLAYER_HONOR;
                                break;
                            default:
                                $table = TABLE_RANK_PLAYER_MILITARY;
                                break;
                        }

                        break;
                    default:
                        $table = TABLE_RANK_PLAYER_POINTS;
                        break;
                }
            } else {
                switch ($type2) {
                    case 'points':
                        $table = TABLE_RANK_ALLY_POINTS;
                        break;
                    case 'economy':
                        $table = TABLE_RANK_ALLY_ECO;
                        break;
                    case 'research':
                        $table = TABLE_RANK_ALLY_TECHNOLOGY;
                        break;
                    case 'fleet'://Type2 =3
                        switch ($type3) {
                            case '5':
                                $table = TABLE_RANK_ALLY_MILITARY_BUILT;
                                break;
                            case '6':
                                $table = TABLE_RANK_ALLY_MILITARY_DESTRUCT;
                                break;
                            case '4':
                                $table = TABLE_RANK_ALLY_MILITARY_LOOSE;
                                break;
                            case '7':
                                $table = TABLE_RANK_ALLY_HONOR;
                                break;
                            default:
                                $table = TABLE_RANK_ALLY_MILITARY;
                                break;
                        }
                        break;
                    default:
                        $table = TABLE_RANK_ALLY_POINTS;
                        break;
                }
            }

            $query = array();

            if ($type1 == 'player') {
                foreach ($n as $i => $val) {
                    $data = $n[$i];

                    $data['player_name'] = filter_var($data['player_name'], FILTER_SANITIZE_STRING);
                    $data['ally_tag'] = filter_var($data['ally_tag'], FILTER_SANITIZE_STRING);

                    if (isset($data['points'])) {
                        $data['points'] = filter_var($data['points'], FILTER_SANITIZE_NUMBER_INT);
                    } else die ("Erreur Pas de points pour le joueur !");


                    if ($table == TABLE_RANK_PLAYER_MILITARY) {
                        $query[] = '(' . $timestamp . ', ' . $i . ', "' . quote($data['player_name']) . '", "' . quote($data['ally_tag']) . '", ' . ((int)$data['points']) . ', ' . $user_data['user_id'] . ', ' . ((int)$data['nb_spacecraft']) . ')';
                    } else {
                        $query[] = '(' . $timestamp . ', ' . $i . ', "' . quote($data['player_name']) . '", "' . quote($data['ally_tag']) . '", ' . ((int)$data['points']) . ', ' . $user_data['user_id'] . ')';
                    }
                    $total++;
                    $datas[] = $data;
                }
                if (!empty($query))
                    if ($table == TABLE_RANK_PLAYER_MILITARY) {
                        $db->sql_query('REPLACE INTO ' . $table . ' (datadate, rank, player, ally, points, sender_id, nb_spacecraft) VALUES ' . implode(',', $query));
                    } else {
                        $db->sql_query('REPLACE INTO ' . $table . ' (datadate, rank, player, ally, points, sender_id) VALUES ' . implode(',', $query));
                    }
            } else {
                $fields = 'datadate, rank, ally, points, sender_id, number_member';
                foreach ($n as $i => $val) {
                    $data = $n[$i];
                    $data['ally_tag'] = filter_var($data['ally_tag'], FILTER_SANITIZE_STRING);

                    if (isset($data['points'])) {
                        $data['points'] = filter_var($data['points'], FILTER_SANITIZE_NUMBER_INT);
                    } else die ("Erreur Pas de points pour le joueur !");

                    $query[] = '(' . $timestamp . ', ' . $i . ', "' . $data['ally_tag'] . '", ' . ((int)$data['points']) . ', ' . $user_data['user_id'] . ',' . ((int)$data['members'][0]) . ')';
                    $datas[] = $data;
                    $total++;
                }
                if (!empty($query)) {
                    $db->sql_query('REPLACE INTO ' . $table . ' (' . $fields . ') VALUES ' . implode(',', $query));
                }
            }

            $db->sql_query('UPDATE ' . TABLE_USER . ' SET rank_added_ogs = rank_added_ogs + ' . $total . ' WHERE user_id = ' . $user_data['user_id']);

            $type2 = (($type2 == 'fleet') ? $type2 . $type3 : $type2);

            $call->add('ranking_' . $type1 . '_' . $type2, array(
                'data' => $datas,
                'offset' => $offset,
                'time' => $time
            ));

            $io->set(array(
                'type' => 'ranking',
                'type1' => $type1,
                'type2' => $type2,
                'offset' => $offset
            ));

            update_statistic('rankimport_ogs', 100);
            add_log('ranking', array('type1' => $type1, 'type2' => $type2, 'offset' => $offset, 'time' => $time, 'toolbar' => $toolbar_info));
        }
        break;

    case 'rc': //PAGE RC
    case 'rc_shared':
        if (isset($pub_json) == false) die("hack");
        if(!isset($pub_ogapilnk))
            $pub_ogapilnk = '';

        if (!$user_data['grant']['messages']) {
            $io->set(array(
                'type' => 'plugin grant',
                'access' => 'messages'
            ));
            $io->status(0);
        } else {
            $call->add('rc', array(
                'json' => $pub_json,
                'api' => $pub_ogapilnk
            ));

            $jsonObj = json_decode($pub_json);
            if($jsonObj == null)
                die("hack");

            

            $exist = $db->sql_fetch_row($db->sql_query("SELECT id_rc FROM " . TABLE_PARSEDRC . " WHERE dateRC = '" . $jsonObj->event_timestamp . "'"));
            if (!$exist[0]) {

                switch($jsonObj->result)
                {
                    case 'draw':
                        $winner = 'N';
                        break;
                    case 'attacker':
                        $winner = 'A';
                        break;
                    case 'defender':
                        $winner = 'D';
                        break;
                    default:
                        die($jsonObj->result);
                        break;
                }
                $nbRounds = count($jsonObj->combatRounds)-1;
                $moon = (int)($jsonObj->moon->genesis);
                $coordinates = "{$jsonObj->coordinates->galaxy}:{$jsonObj->coordinates->system}:{$jsonObj->coordinates->position}";

                $db->sql_query("INSERT INTO " . TABLE_PARSEDRC . " (
                        `dateRC`, `coordinates`, `nb_rounds`, `victoire`, `pertes_A`, `pertes_D`, `gain_M`, `gain_C`, `gain_D`, `debris_M`, `debris_C`, `lune`
                    ) VALUES (
                     '{$jsonObj->event_timestamp}',
                     '{$coordinates}',
                      '{$nbRounds}',
                      '{$winner}', 
                      '{$jsonObj->statistic->lostUnitsAttacker}', 
                      '{$jsonObj->statistic->lostUnitsDefender}', 
                      '{$jsonObj->loot->metal}', 
                      '{$jsonObj->loot->crystal}', 
                      '{$jsonObj->loot->deuterium}', 
                      '{$jsonObj->debris->metal}', 
                      '{$jsonObj->debris->crystal}', 
                      '{$moon}'
                    )"
                );
                $id_rc = $db->sql_insertid();

                $attackers = array();
                foreach($jsonObj->attacker as $attacker)
                {
                    $attackers[$attacker->fleetID] = array('coords' => $attacker->ownerCoordinates,
                                                            'planetType' => $attacker->ownerPlanetType,
                                                            'name' => $attacker->ownerName,
                                                            'armor' => $attacker->armorPercentage,
                                                            'weapon' => $attacker->weaponPercentage,
                                                            'shield' => $attacker->shieldPercentage);
                }
                $defenders = array();
                foreach($jsonObj->defender as $defender)
                {
                    $defenders[] = array('coords' => $attacker->ownerCoordinates,
                        'planetType' => $defender->ownerPlanetType,
                        'name' => $defender->ownerName,
                        'armor' => $defender->armorPercentage,
                        'weapon' => $defender->weaponPercentage,
                        'shield' => $defender->shieldPercentage);
                }

                for ($i = 0; $i <= $nbRounds; $i++)
                {
                    $round = $jsonObj->combatRounds[$i];

                    if(!isset($round->statistic))
                        $a_nb = $a_shoot = $a_bcl = $d_nb = $d_shoot = $d_bcl = 0;
                    else {
                        $a_nb = $round->statistic->hitsAttacker;
                        $d_nb = $round->statistic->hitsDefender;
                        $a_shoot = $round->statistic->fullStrengthAttacker;
                        $d_shoot = $round->statistic->fullStrengthDefender;
                        $a_bcl = $round->statistic->absorbedDamageAttacker;
                        $d_bcl = $round->statistic->absorbedDamageDefender;
                    }

                    $db->sql_query("INSERT INTO " . TABLE_PARSEDRCROUND . " (
                            `id_rc`, `numround`, `attaque_tir`, `attaque_puissance`, `defense_bouclier`, `attaque_bouclier`, `defense_tir`, `defense_puissance`
                        ) VALUE (
                            '{$id_rc}', '{$i}', '" . $a_nb . "', '" . $a_shoot . "', '" . $d_bcl . "', '" . $a_bcl . "', '" . $d_nb . "', '" . $d_shoot . "'
                        )"
                    );
                    $id_rcround = $db->sql_insertid();

                    /*'SmallCargo': 202,
         'LargeCargo': 203,
         'LightFighter': 204,
         'HeavyFighter': 205,
         'Cruiser': 206,
         'Battleship': 207,
         'ColonyShip': 208,
         'Recycler': 209,
         'EspionageProbe': 210,
         'Bomber': 211,
         'SolarSatellite': 212,
         'Destroyer': 213,
         'Deathstar': 214,
         'Battlecruiser': 215,

RocketLauncher': 401,
           'LightLaser': 402,
           'HeavyLaser': 403,
           'GaussCannon': 404,
           'IonCannon': 405,
           'PlasmaTurret': 406,
           'SmallShieldDome': 407,
           'LargeShieldDome': 408,
           'AntiBallisticMissiles': 502,
           'InterplanetaryMissiles': 503,*/
                    $shipList = array('202' => 'PT', '203' => 'GT', '204' => 'CLE', '205' => 'CLO', '206' => 'CR', '207' => 'VB', '208' => 'VC', '209' => 'REC',
                        '210' => 'SE', '211' => 'BMD', '212' => 'SAT', '213' => 'DST', '214' => 'EDLM', '215' => 'TRA',
                        '401' => 'LM', '402' => 'LLE', '403' => 'LLO', '404' => 'CG', '405' => 'AI', '406' => 'LP', '407' => 'PB', '408' => 'GB', '502' => 'MIC', '503' => 'MIP');

                    foreach($round->attackerShips as $fleetId => $attackerRound)
                    {
                        $attackerFleet = array_fill_keys($database['fleet'], 0);
                        foreach((array)$attackerRound as $ship => $nbShip)
                            $attackerFleet[$shipList[$ship]]  = $nbShip;
                        // On efface les sat qui attaquent
                        unset($attackerFleet['SAT']);

                        $attacker = $attackers[$fleetId];
                        $fleet = '';
                        foreach(array('PT', 'GT', 'CLE', 'CLO', 'CR', 'VB', 'VC', 'REC', 'SE', 'BMD', 'DST', 'EDLM', 'TRA') as $ship)
                            $fleet .=  ", " . $attackerFleet[$ship];

                        $db->sql_query("INSERT INTO " . TABLE_ROUND_ATTACK . " (`id_rcround`, `player`, `coordinates`, `Armes`, `Bouclier`, `Protection`, 
                        `PT`, `GT`, `CLE`, `CLO`, `CR`, `VB`, `VC`, `REC`, `SE`, `BMD`,  `DST`, `EDLM`, `TRA`) VALUE ('{$id_rcround}', '"
                            . $attacker['name'] . "', '"
                            . $attacker['coords'] . "', '"
                            . $attacker['weapon'] . "', '"
                            . $attacker['shield'] . "', '"
                            . $attacker['armor'] . "'"
                            .  $fleet. ")");


                    }

                    foreach($round->defenderShips as $fleetId => $defenderRound)
                    {
                        $defenderFleet = array_fill_keys(array_merge($database['fleet'], $database['defense']), 0);
                        foreach((array)$defenderRound as $ship => $nbShip)
                            $defenderFleet[$shipList[$ship]]  = $nbShip;

                        $defender = $defenders[0];

                        $columns = array('PT', 'GT', 'CLE', 'CLO', 'CR', 'VB', 'VC', 'REC', 'SE', 'BMD', 'SAT', 'DST', 'EDLM', 'TRA',
                            'LM', 'LLE', 'LLO', 'CG', 'AI', 'LP', 'PB', 'GB');

                        $query = "INSERT INTO " . TABLE_ROUND_DEFENSE . " (`id_rcround`, `player`, `coordinates`, `Armes`, `Bouclier`, `Protection` ";
                        foreach($columns as $column)
                            $query .= ", `{$column}`";
                        $query .= ") VALUE ('{$id_rcround}', '"
                            . $defender['name'] . "', '"
                            . $defender['coords'] . "', '"
                            . $defender['weapon'] . "', '"
                            . $defender['shield'] . "', '"
                            . $defender['armor'] . "'";
                        foreach($columns as $ship)
                            $query .=  ", " . $defenderFleet[$ship];
                        $query .= ")";

                        $db->sql_query($query);
                    }
                }
            }

            $io->set(array(
                'type' => $page_type,
            ));

            add_log($page_type, array('toolbar' => $toolbar_info));
        }
        break;

    case 'ally_list': //PAGE ALLIANCE

        if (isset($pub_tag, $pub_n) == false) die("hack");

        if (!$user_data['grant']['ranking']) {
            $io->set(array(
                'type' => 'plugin grant',
                'access' => 'ranking'
            ));
            $io->status(0);
        } else {
            if (!isset($tag)) break; //Pas d'alliance
            $tag = filter_var($data['$pub_tag'], FILTER_SANITIZE_STRING);


            $list = array();
            $n = (array)$pub_n;

            foreach ($n as $i => $val) {
                $data = $n[$i];

                if (isset($data['player'], $data['points'], $data['rank'], $data['coords']) == false) die("hack");

                $list[] = array(
                    'pseudo' => filter_var($data['player'], FILTER_SANITIZE_STRING),
                    'points' => $data['points'],
                    'coords' => explode(':', $data['coords']),
                    'rang' => $data['rank']
                );
            }

            $call->add('ally_list', array(
                'list' => $list,
                'tag' => $tag
            ));

            $io->set(array(
                'type' => 'ally_list',
                'tag' => $tag
            ));

            add_log('ally_list', array(
                'tag' => $tag,
                'toolbar' => $toolbar_info
            ));
        }
        break;

    case 'trader': //PAGE MARCHAND
        $call->add('trader', array());
        $io->set(array(
            'type' => 'trader'
        ));
        break;

    case 'hostiles': // Hostiles
        $line = $pub_data;
        $line['attacker_name'] = filter_var($line['attacker_name'], FILTER_SANITIZE_STRING);
        $line['origin_attack_name'] = filter_var($line['origin_attack_name'], FILTER_SANITIZE_STRING);
        $line['destination_name'] = filter_var($line['destination_name'], FILTER_SANITIZE_STRING);
        $line['composition'] = filter_var($line['composition'], FILTER_SANITIZE_STRING);

        $hostile = array('id' => $line['id'],
            'id_vague' => $line['id_vague'],
            'player_id' => $line['player_id'],
            'ally_id' => $line['ally_id'],
            'arrival_time' => $line['arrival_time'],
            'arrival_datetime' => $line['arrival_datetime'],
            'destination_name' => $line['destination_name'],
            'attacker' => $line['attacker_name'],
            'origin_planet' => $line['origin_attack_name'],
            'origin_coords' => $line['origin_attack_coords'],
            'cible_planet' => $line['destination_name'],
            'cible_coords' => $line['destination_coords'],
            'composition_flotte' => $line['composition'],
            'clean' => $line['clean'],
            'check' => false
        );

        $call->add('hostiles', $hostile);

        $io->set(array('function' => 'hostiles',
            'type' => 'hostiles'
        ));

        add_log('info', array('toolbar' => $toolbar_info, 'message' => "envoie une flotte hostile de " . $line['attacker_name']));

        break;

    case 'checkhostiles': // Verification des flotttes Hostiles des joueurs de la communauté
        $hostile = array('is_attack' => false,
            'user_attack' => null,
            'check' => true
        );

        $call->add('hostiles', $hostile);

        $io->set(array('type' => 'checkhostiles',
            'check' => $hostile['is_attack'],
            'user' => $hostile['user_attack']
        ));

        add_log('info', array('toolbar' => $toolbar_info, 'message' => "vérifie les flottes hostiles de la communauté"));
        break;

    case 'messages': //PAGE MESSAGES
        if (isset($pub_data) == false) die("hack");

        if (!$user_data['grant']['messages']) {
            $io->set(array(
                'type' => 'plugin grant',
                'access' => 'messages'
            ));
            $io->status(0);
        } else {
            $line = $pub_data;
            switch ($line['type']) {
                case 'msg': //MESSAGE PERSO
                    if (isset($line['coords'], $line['from'], $line['subject'], $line['message']) == false) die("hack");
                    $line['coords'] = Check::coords($line['coords']);
                    $line['from'] = filter_var($line['from'], FILTER_SANITIZE_STRING);
                    $line['message'] = filter_var($line['message'], FILTER_SANITIZE_STRING);
                    $line['subject'] = filter_var($line['subject'], FILTER_SANITIZE_STRING);

                    $msg = array(
                        'coords' => explode(':', $line['coords']),
                        'from' => $line['from'],
                        'subject' => $line['subject'],
                        'message' => $line['message'],
                        'time' => $line['date']
                    );
                    $call->add('msg', $msg);
                    break;

                case 'ally_msg': //MESSAGE ALLIANCE

                    if (isset($line['from'], $line['tag'], $line['message']) == false) die("hack");

                    $line['from'] = filter_var($line['from'], FILTER_SANITIZE_STRING);
                    $line['tag'] = filter_var($line['tag'], FILTER_SANITIZE_STRING);
                    $line['message'] = filter_var($line['message'], FILTER_SANITIZE_STRING);

                    $ally_msg = array(
                        'from' => $line['from'],
                        'tag' => $line['tag'],
                        'message' => $line['message'],
                        'time' => $line['date']
                    );
                    $call->add('ally_msg', $ally_msg);
                    break;

                case 'spy': //RAPPORT ESPIONNAGE
                case 'spy_shared':
                    if (isset($line['coords'], $line['content'], $line['playerName'], $line['planetName'], $line['proba'], $line['activity']) == false) die("hack");

                    $line['coords'] = Check::coords($line['coords']);

                    $line['content'] = filter_var_array($line['content'], FILTER_SANITIZE_STRING);

                    $line['playerName'] = filter_var($line['playerName'], FILTER_SANITIZE_STRING);
                    $line['planetName'] = filter_var($line['planetName'], FILTER_SANITIZE_STRING);
                    $line['proba'] = filter_var($line['proba'], FILTER_SANITIZE_NUMBER_INT);
                    $line['activity'] = filter_var($line['activity'], FILTER_SANITIZE_STRING);

                    $proba = (int)$line['proba'];
                    $proba = $proba > 100 ? 100 : $proba;
                    $activite = (int)$line['activity'];
                    $activite = $activite > 59 ? 59 : $activite;
                    $spy = array(
                        'proba' => $proba,
                        'activite' => $activite,
                        'coords' => explode(':', $line['coords']),
                        'content' => $line['content'],
                        'time' => $line['date'],
                        'player_name' => $line['playerName'],
                        'planet_name' => $line['planetName']
                    );
                    $call->add($line['type'], $spy);

                    $spyDB = array();
                    foreach ($database as $arr) {
                        foreach ($arr as $v) $spyDB[$v] = 1;
                    }

                    $coords = $spy['coords'][0] . ':' . $spy['coords'][1] . ':' . $spy['coords'][2];

                    $moon = ($line['moon'] > 0 ? 1 : 0);
                    $matches = array();
                    $data = array();
                    $values = $fields = '';

                    $fields .= 'planet_name, coordinates, sender_id, proba, activite, dateRE';
                    $values .= '"' . trim($spy['planet_name']) . '", "' . $coords . '", ' . $user_data['user_id'] . ', ' . $spy['proba'] . ', ' . $spy['activite'] . ', ' . $spy['time'] . ' ';

                    foreach ($spy['content'] as $field => $value) {
                        $fields .= ', `' . $field . '`';
                        $values .= ', ' . $value;
                    }

                    $test = $db->sql_numrows($db->sql_query('SELECT id_spy FROM ' . TABLE_PARSEDSPY . ' WHERE coordinates = "' . $coords . '" AND dateRE = ' . $spy['time']));
                    if (!$test) {
                        $db->sql_query('INSERT INTO ' . TABLE_PARSEDSPY . ' ( ' . $fields . ') VALUES (' . $values . ')');
                        $query = $db->sql_query('SELECT last_update' . ($moon ? '_moon' : '') . ' FROM ' . TABLE_UNIVERSE . ' WHERE galaxy = ' . $spy['coords'][0] . ' AND system = ' . $spy['coords'][1] . ' AND row = ' . $spy['coords'][2]);
                        if ($db->sql_numrows($query)) {
                            $assoc = $db->sql_fetch_assoc($query);
                            if ($assoc['last_update' . ($moon ? '_moon' : '')] < $spy['time']) {
                                if ($moon)
                                    $db->sql_query('UPDATE ' . TABLE_UNIVERSE . ' SET moon = "1", phalanx = ' . ($spy['content']['Pha'] > 0 ? $spy['content']['Pha'] : 0) . ', gate = "' . ($spy['content']['PoSa'] > 0 ? 1 : 0) . '", last_update_moon = ' . $line['date'] . ', last_update_user_id = ' . $user_data['user_id'] . ' WHERE galaxy = ' . $spy['coords'][0] . ' AND system = ' . $spy['coords'][1] . ' AND row = ' . $spy['coords'][2]);
                                else//we do nothing if buildings are not in the report
                                    $db->sql_query('UPDATE ' . TABLE_UNIVERSE . ' SET name = "' . $spy['planet_name'] . '", last_update_user_id = ' . $user_data['user_id'] . ' WHERE galaxy = ' . $spy['coords'][0] . ' AND system = ' . $spy['coords'][1] . ' AND row = ' . $spy['coords'][2]);
                            }
                        }
                        $db->sql_query('UPDATE ' . TABLE_USER . ' SET spy_added_ogs = spy_added_ogs + 1 WHERE user_id = ' . $user_data['user_id']);
                        update_statistic('spyimport_ogs', '1');
                        add_log('messages', array('added_spy' => $spy['planet_name'], 'added_spy_coords' => $coords, 'toolbar' => $toolbar_info));
                    }
                    break;

                case 'ennemy_spy': //RAPPORT ESPIONNAGE ENNEMIS
                    if (isset($line['from'], $line['to'], $line['proba'], $line['date']) == false) die("hack");

                    $line['proba'] = filter_var($line['proba'], FILTER_SANITIZE_NUMBER_INT);
                    $line['from'] = Check::coords($line['from']);
                    $line['to'] = Check::coords($line['to']);

                    $query = "SELECT spy_id FROM " . TABLE_PARSEDSPYEN . " WHERE sender_id = '" . $user_data['user_id'] . "' AND dateSpy = '{$line['date']}'";
                    if ($db->sql_numrows($db->sql_query($query)) == 0)
                        $db->sql_query("INSERT INTO " . TABLE_PARSEDSPYEN . " (`dateSpy`, `from`, `to`, `proba`, `sender_id`) VALUES ('" . $line['date'] . "', '" . $line['from'] . "', '" . $line['to'] . "', '" . $line['proba'] . "', '" . $user_data['user_id'] . "')");

                    $ennemy_spy = array(
                        'from' => explode(':', $line['from']),
                        'to' => explode(':', $line['to']),
                        'proba' => (int)$line['proba'],
                        'time' => $line['date']
                    );
                    $call->add('ennemy_spy', $ennemy_spy);
                    add_log('info', array('toolbar' => $toolbar_info, 'message' => "a été espionné avec une probabilité de  " . $line['proba']));
                    break;

                case 'rc_cdr': //RAPPORT RECYCLAGE
                    if (isset($line['nombre'], $line['coords'], $line['M_recovered'], $line['C_recovered'], $line['M_total'], $line['C_total'], $line['date']) == false) die("hack");

                    $line['nombre'] = filter_var($line['nombre'], FILTER_SANITIZE_NUMBER_INT);
                    $line['coords'] = Check::coords($line['coords']);
                    $line['M_recovered'] = filter_var($line['M_recovered'], FILTER_SANITIZE_NUMBER_INT);
                    $line['C_recovered'] = filter_var($line['C_recovered'], FILTER_SANITIZE_NUMBER_INT);
                    $line['M_total'] = filter_var($line['M_total'], FILTER_SANITIZE_NUMBER_INT);
                    $line['C_total'] = filter_var($line['C_total'], FILTER_SANITIZE_NUMBER_INT);

                    $query = "SELECT id_rec FROM " . TABLE_PARSEDREC . " WHERE sender_id = '" . $user_data['user_id'] . "' AND dateRec = '{$line['date']}'";
                    if ($db->sql_numrows($db->sql_query($query)) == 0)
                        $db->sql_query("INSERT INTO " . TABLE_PARSEDREC . " (`dateRec`, `coordinates`, `nbRec`, `M_total`, `C_total`, `M_recovered`, `C_recovered`, `sender_id`) VALUES ('" . $line['date'] . "', '" . $line['coords'] . "', '" . $line['nombre'] . "', '" . $line['M_total'] . "', '" . $line['C_total'] . "', '" . $line['M_recovered'] . "', '" . $line['C_recovered'] . "', '" . $user_data['user_id'] . "')");

                    $rc_cdr = array(
                        'nombre' => (int)$line['nombre'],
                        'coords' => explode(':', $line['coords']),
                        'M_reco' => (int)$line['M_recovered'],
                        'C_reco' => (int)$line['C_recovered'],
                        'M_total' => (int)$line['M_total'],
                        'C_total' => (int)$line['C_total'],
                        'time' => $line['date']
                    );
                    $call->add('rc_cdr', $rc_cdr);
                    break;

                case 'expedition': //RAPPORT EXPEDITION
                case 'expedition_shared':

                    if (isset($line['coords'], $line['content']) == false) die("hack");

                    $line['content'] = filter_var($line['content'], FILTER_SANITIZE_STRING);
                    $line['coords'] = Check::coords($line['coords'], 1); //On ajoute 1 car c'est une expédition

                    $expedition = array(
                        'time' => $line['date'],
                        'coords' => explode(':', $line['coords']),
                        'content' => $line['content']
                    );
                    $call->add($line['type'], $expedition);
                    break;

                case 'trade': // LIVRAISONS AMIES
                    if (isset($line['date'], $line['trader'], $line['trader_planet'], $line['trader_planet_coords'], $line['planet'], $line['planet_coords'], $line['metal'], $line['cristal'], $line['deuterium']) == false) die("hack");

                    $line['trader'] = filter_var($line['trader'], FILTER_SANITIZE_STRING);
                    $line['planet'] = filter_var($line['planet'], FILTER_SANITIZE_STRING);

                    $trade = array(
                        'time' => $line['date'],
                        'trader' => $line['trader'],
                        'trader_planet' => $line['trader_planet'],
                        'trader_planet_coords' => $line['trader_planet_coords'],
                        'planet' => $line['planet'],
                        'planet_coords' => $line['planet_coords'],
                        'metal' => $line['metal'],
                        'cristal' => $line['cristal'],
                        'deuterium' => $line['deuterium']
                    );
                    $call->add('trade', $trade);
                    add_log('info', array('toolbar' => $toolbar_info, 'message' => "envoie une livraison amie provenant de " . $line['trader']));
                    break;

                case 'trade_me': // MES LIVRAISONS

                    if (isset($line['date'], $line['planet_dest'], $line['planet_dest_coords'], $line['trader'], $line['metal'], $line['cristal'], $line['deuterium']) == false) die("hack");
                    $line['trader'] = filter_var($line['trader'], FILTER_SANITIZE_STRING);
                    $line['planet'] = filter_var($line['planet'], FILTER_SANITIZE_STRING);

                    $trade_me = array(
                        'time' => $line['date'],
                        'planet_dest' => $line['planet_dest'],
                        'planet_dest_coords' => $line['planet_dest_coords'],
                        'planet' => $line['planet'],
                        'planet_coords' => $line['planet_coords'],
                        'trader' => $line['trader'],
                        'metal' => $line['metal'],
                        'cristal' => $line['cristal'],
                        'deuterium' => $line['deuterium']
                    );
                    $call->add('trade_me', $trade_me);
                    add_log('info', array('toolbar' => $toolbar_info, 'message' => "envoie une de ses livraison effectuée pour " . $line['trader']));
                    break;
            }

            $io->set(array(
                'type' => (isset($pub_returnAs) && $pub_returnAs == 'spy' ? 'spy' : 'messages')
            ));
        }

        break;

    

    default:
        die('hack ' . $pub_type);
}

$call->apply();

$io->set('execution', str_replace(',', '.', round((get_microtime() - $start_time) * 1000, 2)));
$io->send();
$db->sql_close();

exit();