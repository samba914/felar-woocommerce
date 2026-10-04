<?php
/**
 * Le transport HTTP, derrière une interface.
 *
 * Non par goût de l'abstraction : c'est ce qui rend le client éprouvable. Les
 * situations qui coûtent cher en production — une clé refusée, un `429` avec son
 * `Retry-After`, une coupure au milieu d'un import — ne se reproduisent pas en
 * appelant un vrai serveur. Avec un transport de substitution, chacune devient un
 * cas de test de trois lignes.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

interface Felar_Transport {

	/**
	 * Exécute un appel.
	 *
	 * @param string      $methode 'GET' ou 'POST'.
	 * @param string      $url     L'adresse complète.
	 * @param array       $entetes En-têtes, `nom => valeur`.
	 * @param string|null $corps   Le corps JSON, ou null.
	 * @param int         $delai   Le délai d'attente, en secondes.
	 * @return Felar_Reponse
	 */
	public function appeler( $methode, $url, array $entetes, $corps, $delai );
}
