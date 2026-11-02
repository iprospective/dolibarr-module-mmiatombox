<?php
/* Copyright (C) 2026 Mathieu Moulin iProspective — GPL v3 ou ultérieure */
/**
 * Mettre en route le module SANS l'interface : l'activer, poser l'adresse et le jeton, déclarer les
 * hôtes, synchroniser. Le jeton est lu dans l'ENVIRONNEMENT (ATOMBOX_TOKEN), jamais en argument —
 * il ne doit apparaître ni dans la liste des processus ni dans un historique.
 *
 *   ATOMBOX_TOKEN=… php scripts/setup-cli.php /chemin/htdocs https://atombox.exemple.fr [login_admin]
 */
if (PHP_SAPI !== 'cli') { echo "en ligne de commande seulement\n"; exit(1); }
$htdocs = rtrim($argv[1] ?? '', '/'); $url = $argv[2] ?? ''; $login = $argv[3] ?? 'admin';
if (!is_file($htdocs.'/master.inc.php') || !$url) { fwrite(STDERR, "usage : ATOMBOX_TOKEN=… php setup-cli.php HTDOCS URL [admin]\n"); exit(2); }
define('NOSESSION', 1); define('NOCSRFCHECK', 1);
require $htdocs.'/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
global $db, $conf, $user, $langs;
if ($user->fetch(0, $login) <= 0) { fwrite(STDERR, "administrateur « $login » introuvable\n"); exit(1); }
$user->loadRights();

// DÉSACTIVER PUIS RÉACTIVER, comme on le ferait dans l'interface : un module déjà actif ne reprend
// ni ses menus ni ses crochets nouveaux. La configuration survit (constantes gardées à la désactivation) ;
// les DROITS, eux, sont retirés à tous : rendus ci-dessous à l'administrateur seul — les autres sont à rendre.
if (isModEnabled('mmiatombox')) { unActivateModule('modMMIAtombox', 0); $conf->setValues($db); }   // sans recharger, activateModule croit le module encore actif et ne fait rien
$r = activateModule('modMMIAtombox');
if (!empty($r['errors'])) { fwrite(STDERR, 'activation : '.implode(' ; ', $r['errors'])."\n"); exit(1); }
echo "-- module (ré)activé\n";
$r = $db->query('SELECT count(*) as n FROM '.MAIN_DB_PREFIX."menu WHERE module = 'mmiatombox'");
echo "-- menu « Courrier » : ".($r ? (int) $db->fetch_object($r)->n : 0)." entrée(s) ; crochets : ".getDolGlobalString('MAIN_MODULE_ATOMBOX_HOOKS')."\n";
// le droit de lire les échanges, donné à l'administrateur
$user->addrights(43782701); $user->loadRights('', 1);

$token = (string) getenv('ATOMBOX_TOKEN');
dolibarr_set_const($db, 'ATOMBOX_URL', rtrim($url, '/'), 'chaine', 0, '', $conf->entity);
if ($token !== '') dolibarr_set_const($db, 'ATOMBOX_TOKEN', $token, 'chaine', 0, '', $conf->entity);
// la correspondance des comptes (F148), facultative : ATOMBOX_LOGINS="admin = mathieu"
if (getenv('ATOMBOX_LOGINS') !== false) dolibarr_set_const($db, 'ATOMBOX_LOGINS', (string) getenv('ATOMBOX_LOGINS'), 'texte', 0, '', $conf->entity);
echo "-- adresse ".rtrim($url, '/').($token !== '' ? ", jeton enregistré" : ", jeton inchangé")."\n";

require_once dol_buildpath('/mmiatombox/class/AtomBoxSync.class.php');
$client = new AtomBoxClient(rtrim($url, '/'), $token !== '' ? $token : getDolGlobalString('ATOMBOX_TOKEN'));
$sync = new AtomBoxSync($db, $client);
$moi = $client->call('GET', '/application');
if ($moi === null) { fwrite(STDERR, "AtomBox injoignable : {$client->error}\n"); exit(1); }
echo "-- connecté à AtomBox : application « {$moi['nom']} », axes ".implode(', ', array_column($moi['axes'], 'id'))."\n";
$h = $client->call('PATCH', '/application', AtomBoxSync::instanceSettings());
echo "-- instance déclarée : ".($h === null ? 'ÉCHEC '.$client->error : 'hôtes '.implode(', ', $h['parametres']['hotes']).', adresse '.$h['parametres']['url'])."\n";
foreach ($sync->syncAll() as $el => $res) {
	echo "-- $el : ".(is_array($res) ? "{$res[0]} créé(s), {$res[1]} modifié(s)" : "ÉCHEC $res")."\n";
}
