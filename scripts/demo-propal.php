<?php
/* Copyright (C) 2026 Mathieu Moulin iProspective — GPL v3 ou ultérieure */
/**
 * LA DÉMONSTRATION, premier temps : un tiers, une proposition validée, envoyée par courrier COMME
 * Dolibarr l'envoie (CMailFile, trackid « pro<id> » dans le Message-ID). Idempotent sur le tiers ;
 * chaque appel crée une nouvelle proposition.
 *
 *   php scripts/demo-propal.php HTDOCS EXPEDITEUR CLIENT [login_admin]
 *   php scripts/demo-propal.php /home/x/htdocs mathieu@exemple.fr test@exemple.fr
 * Rend sur la sortie : l'id, la référence et le Message-ID de l'envoi.
 */
if (PHP_SAPI !== 'cli') { echo "en ligne de commande seulement\n"; exit(1); }
list(, $htdocs, $from, $to) = array_pad($argv, 4, ''); $login = $argv[4] ?? 'admin';
if (!is_file(rtrim($htdocs, '/').'/master.inc.php') || strpos($from, '@') === false || strpos($to, '@') === false) {
	fwrite(STDERR, "usage : php demo-propal.php HTDOCS EXPEDITEUR CLIENT [admin]\n"); exit(2);
}
define('NOSESSION', 1); define('NOCSRFCHECK', 1);
require rtrim($htdocs, '/').'/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
global $db, $conf, $user, $langs, $mysoc;
$user->fetch(0, $login); $user->loadRights();
$langs->setDefaultLang('fr_FR');

// le tiers, retrouvé par son adresse
$soc = new Societe($db);
$r = $db->query('SELECT rowid FROM '.MAIN_DB_PREFIX."societe WHERE email = '".$db->escape($to)."' LIMIT 1");
if ($r && ($o = $db->fetch_object($r))) { $soc->fetch($o->rowid); }
else {
	$soc->name = 'Tiers Démo AtomBox'; $soc->email = $to; $soc->client = 1; $soc->code_client = -1;
	if ($soc->create($user) <= 0) { fwrite(STDERR, 'tiers : '.$soc->error."\n"); exit(1); }
}
// la proposition : une ligne libre, validée — c'est la validation qui lui donne sa référence
$p = new Propal($db);
$p->socid = $soc->id; $p->datep = dol_now(); $p->duree_validite = 30; $p->cond_reglement_id = 1; $p->mode_reglement_id = 0;
if ($p->create($user) <= 0) { fwrite(STDERR, 'proposition : '.$p->error."\n"); exit(1); }
$p->addline('Installation et paramétrage d\'AtomBox', 1200, 1, 20);
$p->fetch($p->id);
if ($p->valid($user) <= 0) { fwrite(STDERR, 'validation : '.$p->error."\n"); exit(1); }
$p->fetch($p->id);

// l'envoi, tel que Dolibarr le fait depuis la fiche (trackid « pro<id> »)
$sujet = 'Proposition '.$p->ref.' — '.$mysoc->name;
$corps = "Bonjour,\n\nVeuillez trouver notre proposition ".$p->ref." d'un montant de ".price($p->total_ttc)." € TTC.\n\nCordialement,\n";
$m = new CMailFile($sujet, $to, $from, $corps, array(), array(), array(), '', '', 0, 0, '', '', 'pro'.$p->id);
if (!$m->sendfile()) { fwrite(STDERR, 'envoi : '.$m->error."\n"); exit(1); }
echo "propal_id={$p->id}\nref={$p->ref}\nmessage_id={$m->msgid}\n";
