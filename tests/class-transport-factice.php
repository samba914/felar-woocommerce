<?php
/**
 * Un transport qui répond ce qu'on lui dit, et retient ce qu'on lui a demandé.
 *
 * C'est lui qui rend éprouvables les situations qui coûtent cher en production :
 * une clé révoquée, une limite de débit avec son `Retry-After`, un serveur qui
 * tombe au milieu d'un import. Aucune ne se reproduit en appelant un vrai serveur.
 *
 * @package Felar_Connect
 */

final class Transport_Factice implements Felar_Transport {

	/** @var Felar_Reponse[] Les réponses à servir, dans l'ordre. */
	private $reponses;

	/** @var array Les appels reçus. */
	public $appels = array();

	public function __construct( array $reponses ) {
		$this->reponses = $reponses;
	}

	public function appeler( $methode, $url, array $entetes, $corps, $delai ) {
		$this->appels[] = array(
			'methode' => $methode,
			'url'     => $url,
			'entetes' => $entetes,
			'corps'   => $corps,
			'delai'   => $delai,
		);

		$reponse = array_shift( $this->reponses );
		return $reponse instanceof Felar_Reponse ? $reponse : new Felar_Reponse( 200, array(), '{}' );
	}

	/** Le dernier appel reçu. */
	public function dernier() {
		return empty( $this->appels ) ? null : $this->appels[ count( $this->appels ) - 1 ];
	}
}
