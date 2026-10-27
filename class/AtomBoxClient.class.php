<?php
/* Copyright (C) 2026 Mathieu Moulin iProspective — GPL v3 ou ultérieure */

/**
 * Le client de l'API d'AtomBox : un jeton d'application au porteur, du JSON dans les deux sens.
 *
 * Pas getURLContent() : elle refuse par défaut les adresses locales (AtomBox et Dolibarr peuvent
 * tourner sur la même machine) et ne connaît pas PATCH.
 */
class AtomBoxClient
{
	/** @var string */ public $url;
	/** @var string */ public $error = '';
	/** @var int */ public $lastcode = 0;
	/** @var string */ private $token;
	/** @var int */ private $timeout;

	public function __construct($url = null, $token = null, $timeout = 5)
	{
		$this->url = rtrim((string) ($url === null ? getDolGlobalString('ATOMBOX_URL') : $url), '/');
		$this->token = (string) ($token === null ? getDolGlobalString('ATOMBOX_TOKEN') : $token);
		$this->timeout = (int) $timeout;
	}

	public function isConfigured()
	{
		return $this->url !== '' && $this->token !== '';
	}

	/** les OCTETS d'une ressource (une pièce jointe) ; null en cas d'échec */
	public function raw($path)
	{
		$this->error = '';
		if (!$this->isConfigured()) { $this->error = 'AtomBox non configuré'; return null; }
		$c = curl_init($this->url.'/api/v1'.$path);
		curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => array('Authorization: Bearer '.$this->token),
			CURLOPT_CONNECTTIMEOUT => $this->timeout, CURLOPT_TIMEOUT => 60));
		$out = curl_exec($c); $this->lastcode = (int) curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
		if ($out === false || $this->lastcode >= 400) { $this->error = 'HTTP '.$this->lastcode; return null; }
		return $out;
	}

	/**
	 * @return array|null la réponse décodée, ou null (raison dans $this->error)
	 */
	public function call($method, $path, $body = null, $query = array())
	{
		$this->error = ''; $this->lastcode = 0;
		if (!$this->isConfigured()) { $this->error = 'AtomBox non configuré'; return null; }
		$u = $this->url.'/api/v1'.$path.($query ? '?'.http_build_query($query) : '');
		$c = curl_init($u);
		$headers = array('Accept: application/json', 'Authorization: Bearer '.$this->token);
		if ($body !== null) { $headers[] = 'Content-Type: application/json'; curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($body)); }
		curl_setopt_array($c, array(CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
			CURLOPT_CONNECTTIMEOUT => $this->timeout, CURLOPT_TIMEOUT => $this->timeout * 2));
		$out = curl_exec($c);
		$this->lastcode = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
		if ($out === false) { $this->error = curl_error($c); curl_close($c); dol_syslog('AtomBox '.$method.' '.$path.' : '.$this->error, LOG_WARNING); return null; }
		curl_close($c);
		if ($this->lastcode >= 400) {
			$this->error = 'HTTP '.$this->lastcode.' '.substr((string) $out, 0, 200);
			dol_syslog('AtomBox '.$method.' '.$path.' : '.$this->error, LOG_WARNING);
			return null;
		}
		$j = json_decode((string) $out, true);
		return is_array($j) ? $j : array();
	}
}
