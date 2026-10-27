<?php
/* Copyright (C) 2026 Mathieu Moulin iProspective
 *
 * This program is free software: you can redistribute it and/or modify it under the terms of the
 * GNU General Public License as published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

/**
 * \file    core/modules/modAtomBox.class.php
 * \ingroup atombox
 * \brief   AtomBox — le courrier de l'entreprise relié aux objets de Dolibarr.
 *
 * Ce que fait le module (décision D187 du CDC d'AtomBox) :
 *  - il DÉCLARE ses six axes à AtomBox (propositions, commandes, factures, projets, commandes et
 *    factures fournisseurs) et en POUSSE les valeurs avec leur libellé (« PR2610-0012 — Tiers ») ;
 *  - il donne à AtomBox les hôtes de ses Message-ID : la réponse d'un client à un envoi Dolibarr
 *    arrive ainsi étiquetée sur l'objet, sans geste humain ;
 *  - il ajoute un onglet « Échanges » aux fiches de ces objets, et à la fiche tiers.
 * AtomBox n'appelle jamais Dolibarr : c'est le module qui appelle AtomBox.
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modAtomBox extends DolibarrModules
{
	public function __construct($db)
	{
		$this->db = $db;
		$this->numero = 437827;   // plage iProspective 437800-437850
		$this->rights_class = 'atombox';
		$this->family = 'crm';
		$this->module_position = '91';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "AtomBox : les échanges de courrier sur les fiches tiers, propositions, commandes, factures et projets";
		$this->descriptionlong = "Relie Dolibarr à AtomBox : les objets deviennent des tags, les réponses des clients s'y rangent seules, et chaque fiche montre ses échanges.";
		$this->editor_name = 'Mathieu Moulin iProspective MMI';
		$this->editor_url = 'https://iprospective.fr';
		$this->version = '0.1.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-envelope-open-text';
		$this->module_parts = array('triggers' => 1);
		$this->config_page_url = array('setup.php@atombox');
		$this->depends = array();
		$this->langfiles = array('atombox@atombox');
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(19, 0);

		// la configuration SURVIT à une désactivation (dernier champ à 0) : la perdre obligerait à
		// recréer un jeton côté AtomBox
		$this->const = array(
			0 => array('ATOMBOX_URL', 'chaine', '', "Adresse d'AtomBox (https://…)", 0, 'current', 0),
			1 => array('ATOMBOX_TOKEN', 'chaine', '', "Jeton d'application AtomBox", 0, 'current', 0),
			2 => array('ATOMBOX_LOGINS', 'texte', '', "Correspondance des comptes : une ligne « login Dolibarr = login AtomBox »", 0, 'current', 0),
		);

		$cond = '$user->hasRight("atombox", "read")';
		$this->tabs = array();
		foreach (array('thirdparty', 'propal', 'order', 'invoice', 'project', 'supplier_order', 'supplier_invoice') as $type) {
			$this->tabs[] = array('data' => $type.':+atombox:AtomBoxTab:atombox@atombox:'.$cond.':/atombox/tab.php?type='.$type.'&id=__ID__');
		}

		$this->rights = array();
		$this->rights[0][0] = $this->numero.'01';
		$this->rights[0][1] = 'Voir les échanges AtomBox';
		$this->rights[0][3] = 1;
		$this->rights[0][4] = 'read';

		// « Courrier » : le webmail d'AtomBox dans la page de Dolibarr (F148)
		$this->menu = array();
		$this->menu[] = array('fk_menu' => '', 'type' => 'top', 'titre' => 'AtomBoxMail', 'prefix' => img_picto('', 'fa-envelope-open-text', 'class="pictofixedwidth"'),
			'mainmenu' => 'atombox', 'leftmenu' => '', 'url' => '/atombox/courrier.php', 'langs' => 'atombox@atombox', 'position' => 1100,
			'enabled' => 'isModEnabled("atombox")', 'perms' => '$user->hasRight("atombox", "read")', 'target' => '', 'user' => 2);
	}

	public function init($options = '')
	{
		return $this->_init(array(), $options);
	}

	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
