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
require_once dol_buildpath('/mmiatombox/class/AtomBoxSync.class.php');

$langs->loadLangs(array('companies', 'mmiatombox@mmiatombox'));
if (!$user->hasRight('mmiatombox', 'read')) accessforbidden();

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

/* F151 : RANGER UNE PIÈCE JOINTE dans les documents de l'objet — avec les droits de l'utilisateur
   dans Dolibarr (AtomBox n'écrit jamais dans Dolibarr : c'est Dolibarr qui va chercher la pièce) */
$DROITS = array('thirdparty' => array('societe', 'creer'), 'propal' => array('propal', 'creer'), 'order' => array('commande', 'creer'),
	'invoice' => array('facture', 'creer'), 'project' => array('projet', 'creer'),
	'supplier_order' => array('fournisseur', 'commande', 'creer'), 'supplier_invoice' => array('fournisseur', 'facture', 'creer'));
$peutRanger = call_user_func_array(array($user, 'hasRight'), $DROITS[$type]);
if (GETPOST('action', 'aZ09') === 'adddoc' && $peutRanger && $msg !== '') {
	require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
	$pj = GETPOST('pj', 'alphanohtml');
	$d = $client->call('GET', '/messages/'.rawurlencode($msg));
	$piece = null;
	foreach ((array) ($d['pieces_jointes'] ?? array()) as $p) { if ($p['pj_id'] === $pj) $piece = $p; }
	$octets = $piece ? $client->raw('/messages/'.rawurlencode($msg).'/pieces-jointes/'.rawurlencode($pj)) : null;
	$dir = getMultidirOutput($object, '', 1);
	if ($octets === null || !$dir || strpos($dir, 'error-') === 0) {
		setEventMessages($langs->trans('AtomBoxDocFailed', dol_escape_htmltag($client->error ?: $dir)), null, 'errors');
	} else {
		dol_mkdir($dir);
		$nom = dol_sanitizeFileName($piece['nom'] ?: 'piece');
		if (file_exists($dir.'/'.$nom)) $nom = dol_print_date(dol_now(), '%Y%m%d-%H%M%S').'-'.$nom;   // jamais écraser un document
		if (file_put_contents($dir.'/'.$nom, $octets) === false) setEventMessages($langs->trans('AtomBoxDocFailed', $dir), null, 'errors');
		else {
			dolChmod($dir.'/'.$nom);
			addFileIntoDatabaseIndex($dir, $nom, '', 'uploaded', 0, $object);
			setEventMessages($langs->trans('AtomBoxDocAdded', $nom), null);
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?type='.urlencode($type).'&id='.$object->id.'&msg='.urlencode($msg)); exit;
}
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
print dol_get_fiche_head($head, 'mmiatombox', $langs->trans('AtomBoxTab'), -1, $picto);
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
		$pieces = (array) ($detail['pieces_jointes'] ?? array());
		if ($pieces) {
			print '<tr><td>'.$langs->trans('AtomBoxAttachments').'</td><td>';
			foreach ($pieces as $p) {
				print '<div>'.img_picto('', 'fa-paperclip', 'class="pictofixedwidth"').dol_escape_htmltag($p['nom']).' <span class="opacitymedium">('.dol_print_size((int) $p['octets']).')</span>';
				if ($peutRanger) {
					print ' <form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline"><input type="hidden" name="token" value="'.newToken().'">'
						.'<input type="hidden" name="action" value="adddoc"><input type="hidden" name="type" value="'.dol_escape_htmltag($type).'"><input type="hidden" name="id" value="'.$object->id.'">'
						.'<input type="hidden" name="msg" value="'.dol_escape_htmltag($msg).'"><input type="hidden" name="pj" value="'.dol_escape_htmltag($p['pj_id']).'">'
						.'<button class="butActionSmall">'.img_picto('', 'fa-file-import', 'class="pictofixedwidth"').$langs->trans('AtomBoxAddToDocuments').'</button></form>';
				}
				print '</div>';
			}
			print '</td></tr>';
		}
		print '</table>';
		print '<div class="tabsAction"><a class="butAction" href="'.dol_buildpath('/mmiatombox/courrier.php', 1).'?msg='.urlencode($msg).'">'.img_picto('', 'fa-reply', 'class="pictofixedwidth"').$langs->trans('AtomBoxReplyInMail').'</a></div>';
	}
	print '<div class="div-table-responsive"><table class="tagtable liste centpercent">';
	print '<tr class="liste_titre"><th></th><th>'.$langs->trans('Date').'</th><th>'.$langs->trans('AtomBoxFromTo').'</th><th>'.$langs->trans('AtomBoxSubject').'</th><th>'.$langs->trans('AtomBoxMailbox').'</th><th>'.$langs->trans('AtomBoxTags').'</th><th></th></tr>';
	// F152 : « Ouvrir dans Courrier » — le webmail intégré, sur ce message, pour y répondre
	$courrier = function ($id) { return dol_buildpath('/mmiatombox/courrier.php', 1).'?msg='.urlencode($id); };
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
