<?php
/* Copyright (C) 2026 Mathieu Moulin iProspective — GPL v3 ou ultérieure */

/**
 * « COURRIER » : le webmail d'AtomBox dans la page de Dolibarr (F148, D108).
 *
 * Le module ouvre pour l'utilisateur une session DÉLÉGUÉE (le compte AtomBox que l'administrateur
 * a délégué à cette application) et la passe au webmail par le FRAGMENT de l'adresse : le
 * fragment ne part jamais au serveur, et le webmail l'efface aussitôt de l'historique. AtomBox
 * n'accepte d'être affiché ici que si cette instance lui est déclarée (frame-ancestors).
 * &msg=<id> ouvre un message précis (F152).
 */
$res = 0;
if (!$res && file_exists('../main.inc.php')) $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res) die('Include of main fails');
require_once dol_buildpath('/mmiatombox/class/AtomBoxSync.class.php');

$langs->loadLangs(array('mmiatombox@mmiatombox'));
if (!$user->hasRight('mmiatombox', 'read')) accessforbidden();

$sync = new AtomBoxSync($db);
$session = $sync->client->isConfigured() ? $sync->delegatedSession($user, GETPOST('renouveler', 'int') ? true : false) : null;
$msg = GETPOST('msg', 'alphanohtml');

llxHeader('', $langs->trans('AtomBoxMail'), '', '', 0, 0, '', '', '', 'mod-atombox page-courrier');
if (!$sync->client->isConfigured()) {
	print '<div class="warning">'.$langs->trans('AtomBoxNotConfigured').'</div>';
} elseif ($session === null) {
	print '<div class="error">'.$langs->trans('AtomBoxNoDelegation', dol_escape_htmltag(AtomBoxSync::atomboxLogin($user)), dol_escape_htmltag($sync->client->error)).'</div>';
} else {
	$f = 'session='.rawurlencode($session['jeton']).'&compte='.rawurlencode($session['login']).'&integre=1'.($msg !== '' ? '&msg='.rawurlencode($msg) : '');
	print '<iframe id="atombox" title="AtomBox" src="'.dol_escape_htmltag($sync->client->url.'/#'.$f).'"'
		.' style="width:100%; height:calc(100vh - 110px); border:0; display:block" allow="clipboard-write"></iframe>';
}
llxFooter();
$db->close();
