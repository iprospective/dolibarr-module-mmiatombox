# MMI Atombox pour Dolibarr

Relie Dolibarr à [AtomBox](https://atombox.iprospective.eu) : le courrier de l'entreprise rangé sur
les objets de Dolibarr.

- **Six axes de tags** déclarés à AtomBox : propositions, commandes, factures, projets, commandes et
  factures fournisseurs. Chaque objet y devient une valeur, avec un libellé lisible
  (« PR2610-0012 — Tiers SA »), tenue à jour par les déclencheurs de Dolibarr.
- **La réponse d'un client se range seule** : Dolibarr écrit l'objet dans le Message-ID de ce qu'il
  envoie ; AtomBox le retrouve dans In-Reply-To / References de la réponse, pour les hôtes déclarés
  de CETTE instance seulement.
- **Un onglet « Échanges »** sur les fiches tiers (tout le courrier avec ses adresses et celles de
  ses contacts), propositions, commandes, factures, projets, commandes et factures fournisseurs.

AtomBox n'appelle jamais Dolibarr : c'est le module qui appelle AtomBox, avec un jeton d'application.

## Installation

1. Côté AtomBox, créer l'application et son jeton (affiché une seule fois) :
   `python -m atombox.applications create --name dolibarr --type dolibarr --domain exemple.fr`
2. Déposer le module dans `htdocs/custom/mmiatombox`, l'activer.
3. Réglages du module : adresse d'AtomBox, jeton ; puis « Déclarer les hôtes » et « Synchroniser les
   objets ».
4. Pour que les envois de Dolibarr soient eux aussi rangés : `MAIL_AUTOCOPY_TO` vers la boîte relevée
   par AtomBox.

Compatible Dolibarr 19 à 24, PHP 7.4 et plus. Numéro de module : 437827.
