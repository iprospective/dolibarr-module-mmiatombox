<?php
/* Copyright (C) 2026 Mathieu Moulin iProspective — GPL v3 ou ultérieure */

$res = 0;
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once dol_buildpath('/atombox/class/AtomBoxSync.class.php');

$langs->loadLangs(array('admin', 'atombox@atombox'));
if (!$user->admin) accessforbidden();
$action = GETPOST('action', 'aZ09');

// le jeton CSRF des formulaires est vérifié par main.inc.php
if ($action === 'save') {
	dolibarr_set_const($db, 'ATOMBOX_URL', rtrim(GETPOST('ATOMBOX_URL', 'alphanohtml'), '/'), 'chaine', 0, '', $conf->entity);
	$jeton = GETPOST('ATOMBOX_TOKEN', 'none');
	// le jeton ne se réaffiche jamais : un champ vide le laisse tel quel
	if ($jeton !== '') dolibarr_set_const($db, 'ATOMBOX_TOKEN', trim($jeton), 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('SetupSaved'), null);
	header('Location: '.$_SERVER['PHP_SELF']); exit;
}

$sync = new AtomBoxSync($db);
$retour = null;
if ($action === 'test') {
	$retour = $sync->client->call('GET', '/application');
	if ($retour === null) setEventMessages($langs->trans('AtomBoxUnreachable', $sync->client->error), null, 'errors');
}
if ($action === 'hosts') {
	$r = $sync->client->call('PATCH', '/application', array('hotes' => AtomBoxSync::messageIdHosts()));
	if ($r === null) setEventMessages($sync->client->error, null, 'errors');
	else setEventMessages($langs->trans('AtomBoxHostsDeclared', implode(', ', $r['parametres']['hotes'])), null);
}
if ($action === 'sync') {
	foreach ($sync->syncAll() as $el => $r) {
		if (is_array($r)) setEventMessages($langs->trans('AtomBoxSynced', $langs->trans('AtomBoxType_'.$el), $r[0], $r[1]), null);
		else setEventMessages($langs->trans('AtomBoxType_'.$el).' : '.$r, null, 'errors');
	}
}

llxHeader('', $langs->trans('AtomBoxSetup'));
print load_fiche_titre($langs->trans('AtomBoxSetup'), '<a href="'.DOL_URL_ROOT.'/admin/modules.php">'.$langs->trans('BackToModuleList').'</a>', 'title_setup');
print '<span class="opacitymedium">'.$langs->trans('AtomBoxSetupHelp').'</span><br><br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('AtomBoxURL').'</td><td><input class="minwidth400" name="ATOMBOX_URL" value="'.dol_escape_htmltag(getDolGlobalString('ATOMBOX_URL')).'" placeholder="https://atombox.exemple.fr"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('AtomBoxToken').'</td><td><input class="minwidth400" type="password" name="ATOMBOX_TOKEN" value="" autocomplete="new-password" placeholder="'.(getDolGlobalString('ATOMBOX_TOKEN') ? $langs->trans('AtomBoxTokenKept') : 'abxapp_…').'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('AtomBoxHosts').'</td><td><code>'.dol_escape_htmltag(implode(', ', AtomBoxSync::messageIdHosts())).'</code></td></tr>';
print '</table><div class="center"><input type="submit" class="button" value="'.$langs->trans('Save').'"></div></form><br>';

print '<div class="tabsAction">';
foreach (array('test' => 'AtomBoxTest', 'hosts' => 'AtomBoxDeclareHosts', 'sync' => 'AtomBoxSyncAll') as $a => $l) {
	print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action='.$a.'&token='.newToken().'">'.$langs->trans($l).'</a>';
}
print '</div>';

if (is_array($retour)) {
	print '<table class="noborder centpercent"><tr class="liste_titre"><td colspan="2">'.$langs->trans('AtomBoxConnected').'</td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans('Name').'</td><td>'.dol_escape_htmltag($retour['nom'].' ('.$retour['type'].')').'</td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans('AtomBoxScope').'</td><td>'.dol_escape_htmltag(json_encode($retour['portee'], JSON_UNESCAPED_UNICODE)).'</td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans('AtomBoxAxes').'</td><td>'.dol_escape_htmltag(implode(', ', array_map(function ($a) { return $a['libelle']; }, $retour['axes']))).'</td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans('AtomBoxHosts').'</td><td>'.dol_escape_htmltag(implode(', ', (array) ($retour['parametres']['hotes'] ?? array()))).'</td></tr>';
	print '</table>';
}
llxFooter();
$db->close();
