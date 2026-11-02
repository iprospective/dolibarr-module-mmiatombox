<?php
/* Copyright (C) 2026 Mathieu Moulin iProspective — GPL v3 ou ultérieure */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Tenir les valeurs à jour : un objet créé, modifié ou validé pousse son libellé ; un objet supprimé
 * devient inactif. AtomBox injoignable ne bloque JAMAIS Dolibarr : l'échec se journalise, l'objet
 * s'enregistre, et « Synchroniser » rattrape plus tard.
 */
class InterfaceMMIAtomboxTriggers extends DolibarrTriggers
{
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'crm';
		$this->description = 'AtomBox : pousse les objets (propositions, commandes, factures, projets…) comme valeurs de tags';
		$this->version = '0.1.0';
		$this->picto = 'fa-envelope-open-text';
	}

	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('mmiatombox')) return 0;
		require_once dol_buildpath('/mmiatombox/class/AtomBoxSync.class.php');
		// UN PAIEMENT change l'encours du tiers (F047) : c'est le tiers qu'on repousse
		if (in_array($action, array('BILL_PAYED', 'BILL_UNPAYED', 'BILL_CANCEL'), true)) {
			if (!empty($object->socid)) { $s2 = new AtomBoxSync($this->db, new AtomBoxClient(null, null, 2)); if ($s2->client->isConfigured()) $s2->syncOne('societe', $object->socid); }
			return 0;
		}
		// UN CONTACT change les adresses de SON tiers (D189) : c'est le tiers qu'on repousse
		if (in_array($action, array('CONTACT_CREATE', 'CONTACT_MODIFY', 'CONTACT_DELETE'), true)) {
			if (empty($object->socid)) return 0;
			$sync = new AtomBoxSync($this->db, new AtomBoxClient(null, null, 2));
			if ($sync->client->isConfigured() && $sync->syncOne('societe', $object->socid) === null)
				dol_syslog('AtomBox : '.$action.' → tiers '.$object->socid.' non poussé ('.$sync->client->error.')', LOG_WARNING);
			return 0;
		}
		foreach (AtomBoxSync::$TYPES as $element => $t) {
			$p = $t['triggers'].'_';
			if (strpos($action, $p) !== 0) continue;
			$suite = substr($action, strlen($p));
			if (!in_array($suite, array('CREATE', 'MODIFY', 'VALIDATE', 'UNVALIDATE', 'DELETE'), true)) return 0;
			// ORDER_ ne doit pas attraper ORDER_SUPPLIER_ : le code d'élément tranche
			if (!empty($object->element) && $object->element !== $element) continue;
			$sync = new AtomBoxSync($this->db, new AtomBoxClient(null, null, 2));
			if (!$sync->client->isConfigured()) return 0;
			$r = $sync->syncOne($element, $object->id, $suite === 'DELETE');
			if ($r === null) dol_syslog('AtomBox : '.$action.' '.$object->id.' non poussé ('.$sync->client->error.')', LOG_WARNING);
			// une proposition, une commande, une facture qui bouge change le CONTEXTE de son tiers (F047)
			if (in_array($element, array('propal', 'commande', 'facture'), true) && !empty($object->socid)) $sync->syncOne('societe', $object->socid);
			return 0;
		}
		return 0;
	}
}
