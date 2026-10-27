<?php
/* Copyright (C) 2026 Mathieu Moulin iProspective — GPL v3 ou ultérieure */

/**
 * Les crochets du module. F153 : sur la fiche d'un tiers, une ligne « Échanges » — le nombre de
 * messages rangés sur lui dans AtomBox (D189) et les trois derniers, lien vers l'onglet. AtomBox lent
 * ou injoignable : la fiche s'affiche quand même (2 s au plus), la ligne le dit.
 */
class ActionsAtomBox
{
	/** @var array */ public $results = array();
	/** @var string */ public $resprints = '';
	/** @var DoliDB */ public $db;

	public function __construct($db) { $this->db = $db; }

	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;
		if (!in_array('thirdpartycard', explode(':', (string) $parameters['context']), true)) return 0;
		if (in_array($action, array('create', 'edit'), true) || empty($object->id) || !$user->hasRight('atombox', 'read')) return 0;
		require_once dol_buildpath('/atombox/class/AtomBoxSync.class.php');
		$langs->load('atombox@atombox');
		$client = new AtomBoxClient(null, null, 2);
		if (!$client->isConfigured()) return 0;
		$r = $client->call('GET', '/messages', null, array('dossier' => 'tiers:'.$object->id, 'filtre' => 'tous', 'tri' => 'date_desc'));
		$onglet = dol_buildpath('/atombox/tab.php', 1).'?type=thirdparty&id='.$object->id;
		print '<tr><td>'.img_picto('', 'fa-envelope-open-text', 'class="pictofixedwidth"').$langs->trans('AtomBoxTab').'</td><td>';
		if ($r === null) {
			print '<span class="opacitymedium">'.$langs->trans('AtomBoxUnreachable', dol_escape_htmltag($client->error)).'</span>';
		} else {
			$m = (array) $r['messages'];
			print '<a href="'.$onglet.'"><b>'.count($m).'</b> '.$langs->trans('AtomBoxExchanges').'</a>';
			foreach (array_slice($m, 0, 3) as $x) {
				$lien = $onglet.'&msg='.urlencode($x['id']);
				print '<div class="small">'.((($x['sens'] ?? 'in') === 'out') ? '↑' : '↓').' '.dol_print_date(strtotime($x['date_recue']), 'day')
					.' — <a href="'.$lien.'">'.dol_escape_htmltag(dol_trunc($x['sujet'] ?: '('.$langs->trans('AtomBoxNoSubject').')', 60)).'</a></div>';
			}
		}
		print '</td></tr>';
		return 0;
	}
}
