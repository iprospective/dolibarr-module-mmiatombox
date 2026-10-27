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
		// le TIERS (D189) : ses valeurs portent les adresses du tiers et de ses contacts ; AtomBox range
		// par elles les messages reçus et envoyés (règle d'adresse)
		'societe' => array('axe' => 'tiers', 'table' => 'societe', 'tab' => 'thirdparty', 'triggers' => 'COMPANY'),
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
		if ($element === 'societe') return $this->thirdparties($id);
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

	/** les tiers, avec leurs adresses : la leur et celles de leurs contacts (deux requêtes en tout) */
	private function thirdparties($id = null)
	{
		$f = $id ? ' AND s.rowid = '.((int) $id) : '';
		$adresses = array();
		$r = $this->db->query('SELECT c.fk_soc, c.email FROM '.MAIN_DB_PREFIX.'socpeople as c JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = c.fk_soc'
			." WHERE c.email <> '' AND s.entity IN (".getEntity('societe').')'.$f);
		while ($r && ($o = $this->db->fetch_object($r))) $adresses[$o->fk_soc][] = strtolower(trim($o->email));
		$contextes = $this->contexts($id);
		$out = array();
		$r = $this->db->query('SELECT s.rowid, s.nom, s.name_alias, s.email, s.code_client, s.code_fournisseur FROM '.MAIN_DB_PREFIX.'societe as s'
			.' WHERE s.entity IN ('.getEntity('societe').')'.$f);
		if (!$r) { dol_syslog('AtomBox : '.$this->db->lasterror(), LOG_ERR); return $out; }
		while ($o = $this->db->fetch_object($r)) {
			$a = isset($adresses[$o->rowid]) ? $adresses[$o->rowid] : array();
			if (trim((string) $o->email) !== '') $a[] = strtolower(trim($o->email));
			$a = array_values(array_unique(array_filter($a, function ($x) { return strpos($x, '@') !== false; })));
			$out[] = array('valeur' => (string) $o->rowid, 'libelle' => trim($o->nom.($o->name_alias ? ' ('.$o->name_alias.')' : '')),
				'adresses' => $a, 'ref_externe' => array('type' => 'societe', 'id' => (int) $o->rowid,
					'contexte' => isset($contextes[$o->rowid]) ? $contextes[$o->rowid] : self::emptyContext()));
		}
		return $out;
	}

	private static function emptyContext()
	{
		return array('encours' => 0.0, 'echu' => 0.0, 'devise' => getDolGlobalString('MAIN_MONNAIE', 'EUR'), 'objets' => array(), 'maj' => dol_print_date(dol_now(), 'dayhourrfc', 'gmt'));
	}

	/**
	 * LE CONTEXTE MÉTIER de chaque tiers (F047), poussé avec lui : factures impayées (l'encours, et
	 * l'échu), propositions ouvertes, commandes en cours. Trois requêtes pour tous les tiers, pas une
	 * par tiers. L'encours est le TTC des factures validées non payées — les paiements partiels ne
	 * sont pas déduits (à affiner : llx_paiement_facture).
	 */
	private function contexts($id = null)
	{
		$f = $id ? ' AND o.fk_soc = '.((int) $id) : '';
		$now = $this->db->idate(dol_now());
		$out = array();
		$add = function ($soc, $o) use (&$out) {
			if (!isset($out[$soc])) $out[$soc] = self::emptyContext();
			$out[$soc]['objets'][] = $o;
		};
		$r = $this->db->query('SELECT o.rowid, o.ref, o.fk_soc, o.total_ttc, o.date_lim_reglement < \''.$now.'\' as echue FROM '.MAIN_DB_PREFIX.'facture as o'
			.' WHERE o.fk_statut = 1 AND o.paye = 0 AND o.entity IN ('.getEntity('invoice').')'.$f.' ORDER BY o.date_lim_reglement');
		while ($r && ($o = $this->db->fetch_object($r))) {
			$add($o->fk_soc, array('axe' => 'facture', 'id' => (string) $o->rowid, 'reference' => $o->ref, 'libelle' => 'Facture',
				'montant' => (float) $o->total_ttc, 'statut' => $o->echue ? 'échue' : 'impayée', 'etat' => $o->echue ? 'due' : 'wait'));
			$out[$o->fk_soc]['encours'] += (float) $o->total_ttc;
			if ($o->echue) $out[$o->fk_soc]['echu'] += (float) $o->total_ttc;
		}
		$r = $this->db->query('SELECT o.rowid, o.ref, o.fk_soc, o.total_ttc FROM '.MAIN_DB_PREFIX.'propal as o'
			.' WHERE o.fk_statut = 1 AND o.entity IN ('.getEntity('propal').')'.$f.' ORDER BY o.date_valid DESC');
		while ($r && ($o = $this->db->fetch_object($r))) {
			$add($o->fk_soc, array('axe' => 'propal', 'id' => (string) $o->rowid, 'reference' => $o->ref, 'libelle' => 'Proposition',
				'montant' => (float) $o->total_ttc, 'statut' => 'ouverte', 'etat' => 'wait'));
		}
		$r = $this->db->query('SELECT o.rowid, o.ref, o.fk_soc, o.total_ttc, o.fk_statut FROM '.MAIN_DB_PREFIX.'commande as o'
			.' WHERE o.fk_statut IN (1, 2) AND o.entity IN ('.getEntity('commande').')'.$f.' ORDER BY o.date_valid DESC');
		while ($r && ($o = $this->db->fetch_object($r))) {
			$add($o->fk_soc, array('axe' => 'commande', 'id' => (string) $o->rowid, 'reference' => $o->ref, 'libelle' => 'Commande',
				'montant' => (float) $o->total_ttc, 'statut' => ((int) $o->fk_statut === 2 ? 'en cours' : 'validée'), 'etat' => 'ok'));
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

	/** ce que le module déclare de son instance : les hôtes de ses Message-ID (D187) et son adresse,
	 *  pour les liens vers ses fiches et la création d'un inconnu (D189, F149) */
	public static function instanceSettings()
	{
		global $dolibarr_main_url_root;
		return array('hotes' => self::messageIdHosts(), 'url' => rtrim((string) $dolibarr_main_url_root, '/'));
	}

	/** le compte AtomBox d'un utilisateur Dolibarr : la correspondance déclarée, sinon le même login */
	public static function atomboxLogin($user)
	{
		foreach (preg_split('/\R/', getDolGlobalString('ATOMBOX_LOGINS')) as $l) {
			$p = array_map('trim', explode('=', $l, 2));
			if (count($p) === 2 && $p[0] !== '' && strtolower($p[0]) === strtolower($user->login)) return strtolower($p[1]);
		}
		return strtolower($user->login);
	}

	/**
	 * la session DÉLÉGUÉE (F148) de l'utilisateur, gardée dans sa session Dolibarr et rouverte à
	 * l'expiration ; null (raison dans $this->client->error) si AtomBox la refuse — compte non délégué
	 */
	public function delegatedSession($user, $force = false)
	{
		$k = 'atombox_session_'.((int) getDolGlobalInt('MAIN_MODULE_ATOMBOX'));
		$s = isset($_SESSION[$k]) ? $_SESSION[$k] : null;
		if (!$force && $s && $s['login'] === self::atomboxLogin($user) && strtotime($s['expire_le']) > time() + 300) return $s;
		$r = $this->client->call('POST', '/application/sessions', array('login' => self::atomboxLogin($user)));
		if ($r === null) { unset($_SESSION[$k]); return null; }
		$s = array('jeton' => $r['jeton'], 'login' => $r['compte'], 'expire_le' => $r['expire_le']);
		$_SESSION[$k] = $s;
		return $s;
	}

	/** les hôtes des Message-ID de CETTE instance (CMailFile : dol_getprefix('email')) */
	public static function messageIdHosts()
	{
		return array(dol_getprefix('email'));
	}
}
