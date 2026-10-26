<?php
/* Copyright (C) 2026 Mathieu Moulin iProspective — GPL v3 ou ultérieure */

require_once __DIR__.'/AtomBoxClient.class.php';

/**
 * Ce que Dolibarr pousse vers AtomBox : les valeurs des six axes (D187). La VALEUR est l'id de
 * l'objet (stable), le LIBELLÉ ce qu'on lit (« PR2610-0012 — Tiers SA »). Un objet supprimé
 * devient inactif : il sort des complétions, les messages déjà étiquetés le gardent.
 */
class AtomBoxSync
{
	/**
	 * élément Dolibarr → axe d'AtomBox, table, onglet, et les codes de déclencheur
	 * (relevés dans Dolibarr 24 : call_trigger des six classes)
	 */
	public static $TYPES = array(
		'propal' => array('axe' => 'propal', 'table' => 'propal', 'tab' => 'propal', 'triggers' => 'PROPAL'),
		'commande' => array('axe' => 'commande', 'table' => 'commande', 'tab' => 'order', 'triggers' => 'ORDER'),
		'facture' => array('axe' => 'facture', 'table' => 'facture', 'tab' => 'invoice', 'triggers' => 'BILL'),
		'project' => array('axe' => 'projet', 'table' => 'projet', 'tab' => 'project', 'triggers' => 'PROJECT'),
		'order_supplier' => array('axe' => 'commande_fournisseur', 'table' => 'commande_fournisseur', 'tab' => 'supplier_order', 'triggers' => 'ORDER_SUPPLIER'),
		'invoice_supplier' => array('axe' => 'facture_fournisseur', 'table' => 'facture_fourn', 'tab' => 'supplier_invoice', 'triggers' => 'BILL_SUPPLIER'),
	);

	/** @var DoliDB */ private $db;
	/** @var AtomBoxClient */ public $client;

	public function __construct($db, $client = null)
	{
		$this->db = $db;
		$this->client = $client ? $client : new AtomBoxClient();
	}

	public static function typeOfTab($tab)
	{
		foreach (self::$TYPES as $el => $t) { if ($t['tab'] === $tab) return $el; }
		return null;
	}

	/** le libellé d'une ligne : référence, puis le titre (projet) ou le tiers */
	private static function label($ref, $extra, $tiers)
	{
		$l = trim((string) $ref);
		if (trim((string) $extra) !== '') $l .= ' — '.trim((string) $extra);
		if (trim((string) $tiers) !== '') $l .= ' — '.trim((string) $tiers);
		return $l;
	}

	/** les valeurs d'un type, lues en base (une requête, pas un fetch par objet) */
	public function values($element, $id = null)
	{
		$t = self::$TYPES[$element];
		$extra = $element === 'project' ? 'o.title' : ($element === 'invoice_supplier' ? 'o.ref_supplier' : "''");
		$sql = 'SELECT o.rowid, o.ref, '.$extra.' as extra, s.nom FROM '.MAIN_DB_PREFIX.$t['table'].' as o'
			.' LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = o.fk_soc'
			.' WHERE o.entity IN ('.getEntity($t['table']).')'.($id ? ' AND o.rowid = '.((int) $id) : '');
		$res = $this->db->query($sql);
		$out = array();
		if (!$res) { dol_syslog('AtomBox : '.$this->db->lasterror(), LOG_ERR); return $out; }
		while ($o = $this->db->fetch_object($res)) {
			$out[] = array('valeur' => (string) $o->rowid, 'libelle' => self::label($o->ref, $o->extra, $o->nom),
				'ref_externe' => array('type' => $element, 'id' => (int) $o->rowid));
		}
		return $out;
	}

	/** pousse un lot ; rend [créés, modifiés] ou null */
	public function push($element, $values)
	{
		$n = array(0, 0);
		foreach (array_chunk($values, 500) as $lot) {
			$r = $this->client->call('PUT', '/axes/'.self::$TYPES[$element]['axe'].'/valeurs', array('valeurs' => $lot));
			if ($r === null) return null;
			$n[0] += (int) $r['crees']; $n[1] += (int) $r['modifies'];
		}
		return $n;
	}

	/** tout synchroniser : rend [élément => [créés, modifiés] | message d'erreur] */
	public function syncAll()
	{
		$out = array();
		foreach (array_keys(self::$TYPES) as $el) {
			$r = $this->push($el, $this->values($el));
			$out[$el] = $r === null ? $this->client->error : $r;
		}
		return $out;
	}

	/** un objet, depuis un déclencheur ; $inactive pour une suppression */
	public function syncOne($element, $id, $inactive = false)
	{
		if ($inactive) return $this->push($element, array(array('valeur' => (string) $id, 'libelle' => '#'.$id, 'actif' => false)));
		$v = $this->values($element, $id);
		return $v ? $this->push($element, $v) : array(0, 0);
	}

	/** les hôtes des Message-ID de CETTE instance (CMailFile : dol_getprefix('email')) */
	public static function messageIdHosts()
	{
		return array(dol_getprefix('email'));
	}
}
