<?php
/* Copyright (C) 2026 Mathieu Moulin iProspective — GPL v3 ou ultérieure */

/**
 * L'ONGLET « ÉCHANGES » : le courrier d'un objet, lu dans AtomBox.
 *  - un objet (proposition, commande, facture, projet, commande ou facture fournisseur) : les
 *    messages étiquetés sur lui — ceux qu'on a envoyés depuis Dolibarr, et les réponses ;
 *  - un tiers : tout le courrier avec ses adresses (la sienne et celles de ses contacts).
 * Le corps d'un message s'ouvre sur place (&msg=…).
 */
$res = 0;
if (!$res && file_exists('../main.inc.php')) $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res) die('Include of main fails');
require_once dol_buildpath('/atombox/class/AtomBoxSync.class.php');

$langs->loadLangs(array('companies', 'atombox@atombox'));
if (!$user->hasRight('atombox', 'read')) accessforbidden();

$type = GETPOST('type', 'aZ09');
$id = (int) GETPOST('id', 'int');   // GETPOSTINT n'existe pas en 19
$msg = GETPOST('msg', 'alphanohtml');

// type d'onglet → classe, fichier, fonction d'en-tête
$TABS = array(
	'thirdparty' => array('Societe', '/societe/class/societe.class.php', '/core/lib/company.lib.php', 'societe_prepare_head', 'company'),
	'propal' => array('Propal', '/comm/propal/class/propal.class.php', '/core/lib/propal.lib.php', 'propal_prepare_head', 'propal'),
	'order' => array('Commande', '/commande/class/commande.class.php', '/core/lib/order.lib.php', 'commande_prepare_head', 'order'),
	'invoice' => array('Facture', '/compta/facture/class/facture.class.php', '/core/lib/invoice.lib.php', 'facture_prepare_head', 'bill'),
	'project' => array('Project', '/projet/class/project.class.php', '/core/lib/project.lib.php', 'project_prepare_head', 'project'),
	'supplier_order' => array('CommandeFournisseur', '/fourn/class/fournisseur.commande.class.php', '/core/lib/fourn.lib.php', 'ordersupplier_prepare_head', 'order'),
	'supplier_invoice' => array('FactureFournisseur', '/fourn/class/fournisseur.facture.class.php', '/core/lib/fourn.lib.php', 'facturefourn_prepare_head', 'supplier_invoice'),
);
if (!isset($TABS[$type])) accessforbidden();
list($classe, $fichier, $lib, $entete, $picto) = $TABS[$type];
require_once DOL_DOCUMENT_ROOT.$fichier;
require_once DOL_DOCUMENT_ROOT.$lib;
$object = new $classe($db);
if ($object->fetch($id) <= 0) accessforbidden();
if ($type === 'thirdparty') restrictedArea($user, 'societe', $id, '&societe');

$client = new AtomBoxClient();
$messages = null; $detail = null;
if ($client->isConfigured()) {
	if ($type === 'thirdparty' && GETPOST('par', 'aZ09') !== 'adresses') {
		// la fiche tiers passe par son TAG (D189) : ce que la règle d'adresse a rangé, envois compris
		$adresses = array();
		$q = $client->call('GET', '/messages', null, array('dossier' => 'tiers:'.$object->id, 'filtre' => 'tous'));
	} elseif ($type === 'thirdparty') {
		// repli : par les adresses, sans dépendre de la synchronisation (&par=adresses)
		$adresses = array();
		if (isValidEmail($object->email)) $adresses[] = strtolower($object->email);
		$r = $db->query('SELECT email FROM '.MAIN_DB_PREFIX."socpeople WHERE fk_soc = ".((int) $object->id)." AND email <> ''");
		while ($r && ($o = $db->fetch_object($r))) { if (isValidEmail($o->email)) $adresses[] = strtolower($o->email); }
		$adresses = array_values(array_unique($adresses));
		$q = $adresses ? $client->call('GET', '/messages', null, array('dossier' => 'tous', 'filtre' => 'tous', 'adresses' => implode(',', $adresses))) : array('messages' => array());
	} else {
		$axe = AtomBoxSync::$TYPES[AtomBoxSync::typeOfTab($type)]['axe'];
		$q = $client->call('GET', '/messages', null, array('dossier' => $axe.':'.$object->id, 'kind' => 'virtuel', 'filtre' => 'tous'));
	}
	$messages = $q === null ? null : $q['messages'];
	if ($msg !== '') $detail = $client->call('GET', '/messages/'.rawurlencode($msg));
}

llxHeader('', $langs->trans('AtomBoxTab'));
$head = $entete($object);
print dol_get_fiche_head($head, 'atombox', $langs->trans('AtomBoxTab'), -1, $picto);
dol_banner_tab($object, 'id', '', 0, 'rowid', $type === 'thirdparty' ? 'nom' : 'ref', '', '&type='.$type);
print '<div class="fichecenter"><div class="underbanner clearboth"></div>';

if (!$client->isConfigured()) {
	print '<div class="warning">'.$langs->trans('AtomBoxNotConfigured').'</div>';
} elseif ($messages === null) {
	print '<div class="error">'.$langs->trans('AtomBoxUnreachable', dol_escape_htmltag($client->error)).'</div>';
} else {
	if ($type === 'thirdparty' && $adresses) print '<div class="opacitymedium">'.$langs->trans('AtomBoxThirdpartyHelp', dol_escape_htmltag(implode(', ', $adresses))).'</div><br>';
	if (is_array($detail)) {
		print '<table class="border centpercent tableforfield">';
		print '<tr><td class="titlefield">'.$langs->trans('AtomBoxSubject').'</td><td><b>'.dol_escape_htmltag($detail['sujet']).'</b></td></tr>';
		print '<tr><td>'.$langs->trans('AtomBoxFrom').'</td><td>'.dol_escape_htmltag(trim($detail['from_nom'].' <'.$detail['from_adresse'].'>')).'</td></tr>';
		print '<tr><td>'.$langs->trans('Date').'</td><td>'.dol_print_date(strtotime($detail['date_recue']), 'dayhour').'</td></tr>';
		print '<tr><td colspan="2"><pre style="white-space:pre-wrap">'.dol_escape_htmltag((string) ($detail['corps'] ?? $detail['snippet'] ?? '')).'</pre></td></tr>';
		print '</table>';
		print '<div class="tabsAction"><a class="butAction" href="'.dol_buildpath('/atombox/courrier.php', 1).'?msg='.urlencode($msg).'">'.img_picto('', 'fa-reply', 'class="pictofixedwidth"').$langs->trans('AtomBoxReplyInMail').'</a></div>';
	}
	print '<div class="div-table-responsive"><table class="tagtable liste centpercent">';
	print '<tr class="liste_titre"><th></th><th>'.$langs->trans('Date').'</th><th>'.$langs->trans('AtomBoxFromTo').'</th><th>'.$langs->trans('AtomBoxSubject').'</th><th>'.$langs->trans('AtomBoxMailbox').'</th><th>'.$langs->trans('AtomBoxTags').'</th><th></th></tr>';
	// F152 : « Ouvrir dans Courrier » — le webmail intégré, sur ce message, pour y répondre
	$courrier = function ($id) { return dol_buildpath('/atombox/courrier.php', 1).'?msg='.urlencode($id); };
	if (!$messages) print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">'.$langs->trans('AtomBoxNoMessage').'</span></td></tr>';
	foreach ($messages as $m) {
		$sortant = ($m['sens'] ?? 'in') === 'out';
		// la liste ne sert pas les destinataires (le détail, si) : un envoi se lit par son expéditeur, flèche montante
		$qui = trim(($m['from_nom'] ?: '').' <'.$m['from_adresse'].'>');
		$tags = implode(' ', array_map(function ($t) { return '<span class="badge badge-secondary" title="'.dol_escape_htmltag($t['axe'].'='.$t['val']).'">'.dol_escape_htmltag($t['libelle'] ?? $t['val']).'</span>'; }, (array) $m['tags']));
		$lien = $_SERVER['PHP_SELF'].'?type='.urlencode($type).'&id='.$object->id.'&msg='.urlencode($m['id']);
		print '<tr class="oddeven'.($m['id'] === $msg ? ' highlight' : '').'">';
		print '<td>'.($sortant ? '↑' : '↓').'</td>';
		print '<td class="nowraponall">'.dol_print_date(strtotime($m['date_recue']), 'dayhour').'</td>';
		print '<td class="tdoverflowmax250">'.dol_escape_htmltag($qui).'</td>';
		print '<td><a href="'.$lien.'">'.dol_escape_htmltag($m['sujet'] ?: '('.$langs->trans('AtomBoxNoSubject').')').'</a></td>';
		print '<td class="opacitymedium">'.dol_escape_htmltag($m['boite']).'</td>';
		print '<td>'.$tags.'</td>';
		print '<td class="right nowraponall"><a class="butActionSmall" href="'.$courrier($m['id']).'" title="'.dol_escape_htmltag($langs->trans('AtomBoxOpenInMail')).'">'.img_picto('', 'fa-reply', 'class="pictofixedwidth"').$langs->trans('AtomBoxOpenInMail').'</a></td></tr>';
	}
	print '</table></div>';
}
print '</div>';
print dol_get_fiche_end();
llxFooter();
$db->close();
